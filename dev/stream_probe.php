<?php
// In-process real-streaming probe: reuse the user's DB-saved AI config to see
// exactly what LlmService::stream() yields. Ground-truths the "AI 没反应" bug.

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AiConfig;
use App\Models\User;
use App\Services\LlmService;
use Illuminate\Support\Facades\Auth;

$cfg = AiConfig::whereNotNull('api_key')->first();
if (! $cfg) {
    echo "NO_AI_CONFIG\n";
    exit;
}
$user = User::find($cfg->user_id);
echo "user_id=", $user->id, "\n";
echo "format=", $cfg->format, " model=", $cfg->model, " base_url=", $cfg->base_url, "\n";
echo "key_len=", mb_strlen($cfg->api_key), "\n";

Auth::login($user);
$svc = new LlmService();

echo "--- stream start ---\n";
$full = '';
try {
    foreach ($svc->stream('用一句话介绍中医', '', '') as $tok) {
        echo "TOKEN[".mb_strlen($tok ?? '')."]=".(mb_substr($tok ?? '', 0, 30))."\n";
        $full .= $tok;
        if (mb_strlen($full) > 400) break;
    }
} catch (\Throwable $e) {
    echo "EXCEPTION=".$e->getMessage()."\n";
}
echo "--- stream end, full_len=".mb_strlen($full)." ---\n";
echo "FULL_PREVIEW=".mb_substr($full, 0, 200)."\n";
