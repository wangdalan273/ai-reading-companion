<?php
// Verify the exact request our app now sends (full Laravel Http stack + CA bundle).
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$ca = base_path('storage/certs/cacert.pem');
$url = 'https://hy3-d8gfx6nztf84ee6cf.api.tcloudbasegateway.com/v1/ai/cloudbase/v1/messages';

// Mirror LlmService::buildRequest for the anthropic branch (both headers now).
$resp = Http::withHeaders([
        'x-api-key' => 'FAKE',
        'Authorization' => 'Bearer FAKE',
        'anthropic-version' => '2023-06-01',
        'content-type' => 'application/json',
    ])
    ->withOptions(['verify' => $ca])
    ->timeout(20)
    ->post($url, [
        'model' => 'hy3-preview',
        'max_tokens' => 16,
        'messages' => [['role' => 'user', 'content' => 'hi']],
    ]);

echo "status=".$resp->status()."\n";
echo "body=".$resp->body()."\n";
echo "interpretation: INVALID_CREDENTIALS => Bearer header accepted & path/CA OK (real token will pass)\n";
echo "                MISSING_CREDENTIALS => Bearer NOT sent (regression!)\n";
