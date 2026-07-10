<?php
// Empirical probe: figure out what the CloudBase gateway actually expects
// (auth header + path), by sending fake-token requests and reading the 401 body.
$ca = __DIR__.'/storage/certs/cacert.pem';
$host = 'https://hy3-d8gfx6nztf84ee6cf.api.tcloudbasegateway.com';
$base = $host.'/v1/ai/cloudbase';

function hit($url, $headers, $ca) {
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) { $h[] = "$k: $v"; }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'hy3-preview',
            'max_tokens' => 16,
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO => $ca,
        CURLOPT_HEADER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'curl_err' => $err];
}

echo "=== A: path /v1/ai/cloudbase/v1/messages, NO auth ===\n";
$r = hit($base.'/v1/messages', ['content-type' => 'application/json'], $ca);
echo "code={$r['code']} err={$r['curl_err']}\nbody={$r['body']}\n\n";

echo "=== B: path /v1/ai/cloudbase/v1/messages, x-api-key: FAKE ===\n";
$r = hit($base.'/v1/messages', ['content-type' => 'application/json', 'x-api-key' => 'FAKE', 'anthropic-version' => '2023-06-01'], $ca);
echo "code={$r['code']} err={$r['curl_err']}\nbody={$r['body']}\n\n";

echo "=== C: path /v1/ai/cloudbase/v1/messages, Authorization: Bearer FAKE ===\n";
$r = hit($base.'/v1/messages', ['content-type' => 'application/json', 'Authorization' => 'Bearer FAKE', 'anthropic-version' => '2023-06-01'], $ca);
echo "code={$r['code']} err={$r['curl_err']}\nbody={$r['body']}\n\n";

echo "=== D: path /v1/ai/cloudbase (NO /v1/messages), x-api-key: FAKE ===\n";
$r = hit($base, ['content-type' => 'application/json', 'x-api-key' => 'FAKE', 'anthropic-version' => '2023-06-01'], $ca);
echo "code={$r['code']} err={$r['curl_err']}\nbody={$r['body']}\n\n";

echo "=== E: path /v1/ai/cloudbase (NO /v1/messages), Authorization: Bearer FAKE ===\n";
$r = hit($base, ['content-type' => 'application/json', 'Authorization' => 'Bearer FAKE', 'anthropic-version' => '2023-06-01'], $ca);
echo "code={$r['code']} err={$r['curl_err']}\nbody={$r['body']}\n\n";
