<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Local PP-OCRv6 OCR client.
 *
 * The OCR engine runs as a local FastAPI service (PP-OCRv6, already deployed by
 * the user on this machine). All processing stays on-device — text never leaves
 * the machine. This service is the text fallback for scanned PDFs that have no
 * embedded text layer: we render each page to an image and ask the local engine
 * to read it.
 *
 * Designed to fail soft: if the local service is unreachable or times out, it
 * returns null instead of throwing, so the upload / analysis flow never breaks.
 */
class OcrService
{
    protected string $baseUrl;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('companion.ocr_url', 'http://127.0.0.1:8765'), '/');
        $this->timeout = (int) config('companion.ocr_timeout', 60);
    }

    /**
     * OCR a single image file (path on disk) and return its recognized text.
     * Returns null on any failure (service down, timeout, bad response).
     */
    public function image(string $imagePath): ?string
    {
        if (! is_file($imagePath)) {
            return null;
        }

        try {
            $resp = Http::timeout($this->timeout)
                ->attach('file', file_get_contents($imagePath), basename($imagePath))
                ->post($this->baseUrl.'/ocr');

            if (! $resp->successful()) {
                return null;
            }

            $json = $resp->json();

            return $json['text'] ?? $json['result'] ?? ($json['data']['text'] ?? null);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether the local OCR engine appears to be reachable right now.
     */
    public function available(): bool
    {
        try {
            return Http::timeout(3)->get($this->baseUrl.'/healthz')->successful()
                || Http::timeout(3)->get($this->baseUrl.'/')->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
