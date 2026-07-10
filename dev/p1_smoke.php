<?php
// P1 acceptance: epub.js reader page + book file stream + PDF placeholder.
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Book;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;

$user = User::firstOrCreate(
    ['email' => 'smoke@test.com'],
    ['name' => 'Smoke Test', 'password' => bcrypt('password123')]
);
Session::start();
Auth::login($user);
Session::save();
$sid = Session::getId();

function req($uri)
{
    global $sid;
    $r = Illuminate\Http\Request::create($uri, 'GET');
    $r->cookies->set(config('session.cookie'), $sid);
    $r->setLaravelSession(Session::driver());
    app()->instance('request', $r);
    return app()->make(Illuminate\Contracts\Http\Kernel::class)->handle($r);
}

// --- Build a minimal valid EPUB for testing (must land in the 'local' disk root) ---
$epubRel = 'books/'.$user->id.'/p1_test.epub';
$epubPath = Storage::disk('local')->path($epubRel);
@mkdir(dirname($epubPath), 0755, true);
$zip = new ZipArchive();
$zip->open($epubPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('mimetype', 'application/epub+zip');
$zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
$zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
$zip->addFromString('OEBPS/content.opf', '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="id"><metadata><dc:identifier id="id" xmlns:dc="http://purl.org/dc/elements/1.1/">urn:uuid:p1</dc:identifier><dc:title>P1 Test</dc:title><dc:language>zh</dc:language></metadata><manifest><item id="c1" href="chapter1.xhtml" media-type="application/xhtml+xml"/></manifest><spine><itemref idref="c1"/></spine></package>');
$zip->addFromString('OEBPS/chapter1.xhtml', '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><body><h1>第一章</h1><p>这是伴读工具 P1 阅读器的测试正文。</p></body></html>');
$zip->close();

$epubBook = Book::create([
    'user_id' => $user->id, 'title' => 'P1 测试书', 'author' => 'Tester',
    'format' => 'epub', 'path' => 'books/'.$user->id.'/p1_test.epub', 'size' => filesize($epubPath),
]);

// 1) Render reader page for EPUB
$resp = req('/read/'.$epubBook->id);
$body = $resp->getContent();
echo "READ_EPUB_STATUS: ".$resp->getStatusCode()."\n";
echo "HAS_VIEWER: ".(str_contains($body, 'x-ref="viewer"') ? 'YES' : 'NO')."\n";
echo "HAS_COMPANION: ".(str_contains($body, 'CompanionReader.start') ? 'YES' : 'NO')."\n";
echo "HAS_WIRE_IGNORE: ".(str_contains($body, 'wire:ignore') ? 'YES' : 'NO')."\n";

// 2) Stream book file
$resp = req('/book/'.$epubBook->id.'/file');
echo "FILE_STATUS: ".$resp->getStatusCode()."\n";
$ct = $resp->headers->get('content-type');
echo "FILE_CONTENT_TYPE: ".$ct."\n";
echo "FILE_OK: ".(($resp->getStatusCode() === 200 && str_contains($ct, 'epub')) ? 'YES' : 'NO')."\n";

// 3) PDF placeholder
$pdfBook = Book::create([
    'user_id' => $user->id, 'title' => 'PDF 占位书', 'author' => 'Tester',
    'format' => 'pdf', 'path' => 'books/'.$user->id.'/fake.pdf', 'size' => 10,
]);
$resp = req('/read/'.$pdfBook->id);
$body = $resp->getContent();
echo "READ_PDF_STATUS: ".$resp->getStatusCode()."\n";
echo "PDF_PLACEHOLDER: ".(str_contains($body, '二期') ? 'YES' : 'NO')."\n";

// Cleanup
$epubBook->delete();
$pdfBook->delete();
@unlink($epubPath);

echo "P1_RESULT: ".
    (str_contains($body, '二期') && $resp->getStatusCode() === 200
        && str_contains($body === '' ? '' : $body, '二期')
        ? 'PASS' : 'CHECK')."\n";
