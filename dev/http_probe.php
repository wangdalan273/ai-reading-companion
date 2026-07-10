<?php
// Decisive test: run the actual CompanionController::ask() with the real config
// (in-process, no network) and capture the raw SSE body. If we see
// `data: "..."` frames, the controller + service are 100% fine and the bug is
// purely the over-the-wire / browser layer.

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\CompanionController;
use App\Models\AiConfig;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$cfg = AiConfig::whereNotNull('api_key')->first();
$user = User::find($cfg->user_id);
Auth::login($user);

$request = Request::create('/api/companion/ask', 'POST', [
    'message' => '用一句话介绍中医',
    'context' => '',
    'book_id' => null,
    'mode' => '',
]);

$ctrl = new CompanionController();
$response = $ctrl->ask($request);

ob_start();
$response->sendContent();
$out = ob_get_clean();

echo "=== RAW SSE BODY (first 600 chars) ===\n";
echo mb_substr($out, 0, 600);
echo "\n=== frame count ===\n";
echo "data: frames = " . (substr_count($out, 'data: '));
echo "\n[DONE] present = " . (strpos($out, '[DONE]') !== false ? 'yes' : 'no');
