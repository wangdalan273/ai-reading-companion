<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Chapter;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Extracts the readable text of a book and splits it into chapters, so the
 * downstream AI features (P12 summary + mind-map, N3 concept graph, N7 characters,
 * P13 cross-book RAG) all have structured source text to work on.
 *
 * - EPUB: pure-PHP zip read of the OPF spine order -> strip HTML -> per-spine-item
 *   chapter. No external dependency, works everywhere.
 * - PDF: probe for `pdftotext` (poppler) to pull the text layer; if absent or
 *   empty, mark has_text_layer=false. A scanned PDF can then be recovered via the
 *   local OCR service (ocrPdf) when Imagick is available.
 *
 * First-principles: before any AI can "read" a book, we must reliably turn the
 * file into plain text + chapter boundaries. That text is the raw material for
 * every later feature, so this service is intentionally dependency-light and
 * fail-soft.
 */
class BookTextService
{
    /**
     * Extract chapters for a book (idempotent: skips if already extracted).
     * @return void
     */
    public function extract(Book $book): void
    {
        if ($book->chapters()->exists()) {
            $book->update(['text_extracted_at' => now()]);

            return;
        }

        $path = Storage::disk('local')->path($book->path);

        $chapters = $book->format === 'epub'
            ? $this->extractEpub($path)
            : $this->extractPdf($book, $path);

        $this->persist($book, $chapters);
        $book->update(['text_extracted_at' => now()]);
    }

    /**
     * Best-effort cover extraction for EPUB (PDF has no embedded cover we can
     * cheaply read). Result is stored as a file under public/covers and the
     * book's cover_path is set; every failure path is silently ignored.
     */
    public function extractCover(Book $book): void
    {
        if ($book->format !== 'epub' || $book->cover_path) {
            return;
        }

        $path = Storage::disk('local')->path($book->path);
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return;
        }

        try {
            $container = $zip->getFromName('META-INF/container.xml');
            $opfPath = $this->opfPathFromContainer($container);
            if (! $opfPath) {
                return;
            }
            $opfDir = dirname($opfPath);
            $opf = $zip->getFromName($opfPath);
            if (! $opf) {
                return;
            }

            $coverHref = $this->coverHrefFromOpf($opf);
            if (! $coverHref) {
                return;
            }

            $entry = ltrim(($opfDir !== '.' && $opfDir !== '' ? $opfDir.'/' : '').$coverHref, '/');
            $img = $zip->getFromName($entry);
            if ($img === false) {
                return;
            }

            $ext = strtolower(pathinfo($coverHref, PATHINFO_EXTENSION)) ?: 'jpg';
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                return;
            }

            $dir = public_path('covers');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filename = 'book-'.$book->id.'.'.$ext;
            file_put_contents($dir.'/'.$filename, $img);
            $book->update(['cover_path' => $filename]);
        } finally {
            $zip->close();
        }
    }

    /**
     * Scanned-PDF recovery path: rasterize pages with Imagick, OCR each via the
     * local engine, then split into page-based chapters. Returns [] if Imagick or
     * the OCR service is unavailable (so callers can surface a friendly message).
     *
     * @return array<int,array{title:string,text:string}>
     */
    public function ocrPdf(Book $book, string $path): array
    {
        if (! class_exists(\Imagick::class)) {
            return [];
        }

        $ocr = app(OcrService::class);
        if (! $ocr->available()) {
            return [];
        }

        try {
            $im = new \Imagick($path);
            $im->setImageFormat('png');
        } catch (\Throwable $e) {
            return [];
        }

        $texts = [];
        foreach ($im as $page) {
            $tmp = tempnam(sys_get_temp_dir(), 'ocr').'.png';
            try {
                $page->writeImage($tmp);
                $t = $ocr->image($tmp);
                if ($t) {
                    $texts[] = $t;
                }
            } finally {
                @unlink($tmp);
            }
        }
        $im->clear();

        $full = implode("\n\n", $texts);
        $book->update(['has_text_layer' => false, 'ocr_text' => $full]);

        return $this->splitLongText($full, '页');
    }

    /**
     * @param  array<int,array{title:string,text:string}>  $chapters
     */
    protected function persist(Book $book, array $chapters): void
    {
        $idx = 1;
        foreach ($chapters as $ch) {
            if (trim($ch['text']) === '') {
                continue;
            }
            Chapter::create([
                'user_id' => $book->user_id,
                'book_id' => $book->id,
                'idx' => $idx++,
                'title' => $ch['title'],
                'source_text' => $ch['text'],
            ]);
        }
    }

    /**
     * @return array<int,array{title:string,text:string}>
     */
    protected function extractEpub(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return [];
        }

        $container = $zip->getFromName('META-INF/container.xml');
        $opfPath = $this->opfPathFromContainer($container);
        if (! $opfPath) {
            $zip->close();

            return [];
        }

        $opfDir = dirname($opfPath);
        $opf = $zip->getFromName($opfPath);
        [$spine, $manifest] = $this->parseOpf($opf);

        $chapters = [];
        $n = 1;
        foreach ($spine as $href) {
            $entry = ltrim(($opfDir !== '.' && $opfDir !== '' ? $opfDir.'/' : '').$href, '/');
            $content = $zip->getFromName($entry);
            if ($content === false) {
                continue;
            }
            $text = $this->htmlToText($content);
            $title = $this->chapterTitle($content) ?: ('第 '.$n.' 章');
            $chapters[] = ['title' => $title, 'text' => $text];
            $n++;
        }
        $zip->close();

        return $chapters;
    }

    protected function opfPathFromContainer(?string $container): ?string
    {
        if (! $container) {
            return null;
        }
        if (preg_match('/full-path="([^"]+)"/', $container, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array{0:array<int,string>,1:array<string,string} [spineHrefs, manifestHrefById]
     */
    protected function parseOpf(?string $opf): array
    {
        $spine = [];
        $manifest = [];
        if (! $opf) {
            return [$spine, $manifest];
        }

        if (preg_match_all('/<item\b[^>]*\bid="([^"]+)"[^>]*\bhref="([^"]+)"[^>]*>/', $opf, $items, PREG_SET_ORDER)) {
            foreach ($items as $it) {
                $manifest[$it[1]] = $it[2];
            }
        }
        if (empty($manifest) && preg_match_all('/<item\b[^>]*\bhref="([^"]+)"[^>]*\bid="([^"]+)"[^>]*>/', $opf, $items2, PREG_SET_ORDER)) {
            foreach ($items2 as $it) {
                $manifest[$it[2]] = $it[1];
            }
        }

        if (preg_match_all('/<itemref\b[^>]*\bidref="([^"]+)"[^>]*>/', $opf, $refs, PREG_SET_ORDER)) {
            foreach ($refs as $r) {
                if (isset($manifest[$r[1]])) {
                    $spine[] = $manifest[$r[1]];
                }
            }
        }

        return [$spine, $manifest];
    }

    /**
     * Resolve the cover image href from an OPF document, trying in order:
     * 1) <meta name="cover" content="ID"/> -> manifest[id]
     * 2) a manifest <item> with properties="cover-image"
     * 3) any manifest <item> whose href looks like a cover image
     */
    protected function coverHrefFromOpf(string $opf): ?string
    {
        if (preg_match('/<meta\b[^>]*\bname="cover"[^>]*\bcontent="([^"]+)"/', $opf, $m)) {
            $id = $m[1];
            if (preg_match('/<item\b[^>]*\bid="'.preg_quote($id, '/').'"[^>]*\bhref="([^"]+)"/', $opf, $it)) {
                return $it[1];
            }
        }

        if (preg_match('/<item\b[^>]*\bproperties="[^"]*cover-image[^"]*"[^>]*\bhref="([^"]+)"/', $opf, $m)) {
            return $m[1];
        }

        if (preg_match('/<item\b[^>]*\bhref="([^"]*(?:cover|Cover)[^"]*\.(?:jpg|jpeg|png|gif|webp))"/', $opf, $m)) {
            return $m[1];
        }

        return null;
    }

    protected function htmlToText(string $html): string
    {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\\1>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<(br|p|div|h[1-6]|li|tr)\b[^>]*>/i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    protected function chapterTitle(string $html): ?string
    {
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $t = trim(strip_tags($m[1]));

            return $t !== '' ? $t : null;
        }
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $t = trim(strip_tags($m[1]));

            return $t !== '' ? $t : null;
        }

        return null;
    }

    /**
     * @return array<int,array{title:string,text:string}>
     */
    protected function extractPdf(Book $book, string $path): array
    {
        $text = $this->pdfTextLayer($path);

        if ($text === null || trim($text) === '') {
            $book->update(['has_text_layer' => false]);

            return [];
        }

        $book->update(['has_text_layer' => true, 'ocr_text' => $text]);

        return $this->splitLongText($text, '部分');
    }

    protected function pdfTextLayer(string $path): ?string
    {
        $bin = $this->findPdfToText();
        if (! $bin) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pdf').'.txt';
        $cmd = sprintf('%s -layout %s %s 2>/dev/null', escapeshellarg($bin), escapeshellarg($path), escapeshellarg($tmp));
        exec($cmd, $out, $rc);
        if (is_file($tmp)) {
            $text = file_get_contents($tmp);
            @unlink($tmp);

            return $text ?: null;
        }

        return null;
    }

    protected function findPdfToText(): ?string
    {
        $check = PHP_OS_FAMILY === 'Windows' ? 'where pdftotext' : 'command -v pdftotext';
        $out = @shell_exec($check.' 2>&1');
        if (empty($out) || stripos($out, 'not found') !== false || stripos($out, '找不到') !== false) {
            return null;
        }

        return 'pdftotext';
    }

    /**
     * Split a long plain-text blob into pseudo-chapters of ~3000 chars.
     * @return array<int,array{title:string,text:string}>
     */
    protected function splitLongText(string $text, string $label): array
    {
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $len = mb_strlen($text, 'UTF-8');
        if ($len <= 3000) {
            return [['title' => '全文', 'text' => $text]];
        }
        $parts = (int) ceil($len / 3000);
        $chunks = [];
        for ($i = 0; $i < $parts; $i++) {
            $start = intdiv($i * $len, $parts);
            $end = intdiv(($i + 1) * $len, $parts);
            $chunk = mb_substr($text, $start, $end - $start, 'UTF-8');
            $chunks[] = ['title' => '第 '.($i + 1).' '.$label, 'text' => trim($chunk)];
        }

        return $chunks;
    }
}
