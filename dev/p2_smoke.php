<?php
// P2 acceptance: SSE ask endpoint + mock streaming + chat persistence + reader chat panel.
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\CompanionController;
use App\Models\Book;
use App\Models\Chat;
use App\Models\User;
use App\Services\LlmService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

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

// Build a minimal EPUB so we have a book to open.
$epubRel = 'books/'.$user->id.'/p2_test.epub';
$epubPath = Storage::disk('local')->path($epubRel);
@mkdir(dirname($epubPath), 0755, true);
$zip = new ZipArchive();
$zip->open($epubPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('mimetype', 'application/epub+zip');
$zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
$zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
$zip->addFromString('OEBPS/content.opf', '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="id"><metadata><dc:identifier id="id" xmlns:dc="http://purl.org/dc/elements/1.1/">urn:uuid:p2</dc:identifier><dc:title>P2</dc:title><dc:language>zh</dc:language></metadata><manifest><item id="c1" href="chapter1.xhtml" media-type="application/xhtml+xml"/></manifest><spine><itemref idref="c1"/></spine></package>');
$zip->addFromString('OEBPS/chapter1.xhtml', '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><body><h1>第一章</h1><p>这是伴读工具 P2 问答的测试正文。</p></body></html>');
$zip->close();

$book = Book::create([
    'user_id' => $user->id, 'title' => 'P2 测试书', 'author' => 'Tester',
    'format' => 'epub', 'path' => $epubRel, 'size' => filesize($epubPath),
]);

// 1) LlmService mock streaming
$svc = new LlmService();
$tokens = [];
foreach ($svc->stream('你好', '选中的句子') as $t) {
    $tokens[] = $t;
}
$full = implode('', $tokens);
echo "MOCK_STREAM_OK: ".(mb_strlen($full) > 10 && str_contains($full, '选中的句子') ? 'YES' : 'NO')."\n";

// 2) Controller SSE + persistence (call directly to bypass CSRF/middleware)
$before = Chat::where('user_id', $user->id)->count();
$ctrl = new CompanionController();
$request = Request::create('/api/companion/ask', 'POST', [
    'message' => '测试问题',
    'context' => '原文X',
    'book_id' => $book->id,
]);
$request->setUserResolver(fn () => $user);
$resp = $ctrl->ask($request);

// Capture the streamed SSE frames. The controller calls ob_flush()/flush()
// during streaming, so we use an output buffer with a callback to collect them.
$GLOBALS['captured'] = '';
ob_start(function ($buf) {
    $GLOBALS['captured'] .= $buf;
    return '';
});
$resp->sendContent();
ob_end_clean();
$out = $GLOBALS['captured'];

$collected = '';
foreach (explode("\n\n", $out) as $frame) {
    foreach (explode("\n", $frame) as $line) {
        if (str_starts_with($line, 'data: ')) {
            $p = trim(substr($line, 6));
            if ($p === '[DONE]' || $p === '"[DONE]"') continue;
            try { $collected .= json_decode($p); } catch (\Throwable $e) { /* skip */ }
        }
    }
}
$after = Chat::where('user_id', $user->id)->count();

echo "SSE_STATUS: ".$resp->getStatusCode()."\n";
echo "SSE_COLLECTED_LEN: ".mb_strlen($collected)."\n";
echo "SSE_DONE_OK: ".(str_contains($out, '[DONE]') ? 'YES' : 'NO')."\n";
echo "CHAT_SAVED_OK: ".($after === $before + 2 ? 'YES' : 'NO')."\n";

// 3) Reader page contains the companion chat panel
$resp = req('/read/'.$book->id);
$body = $resp->getContent();
echo "READ_HAS_CHAT: ".(str_contains($body, 'companionChat(') && str_contains($body, '伴读对话') ? 'YES' : 'NO')."\n";

// Cleanup
$book->delete();
@unlink($epubPath);

echo "P2_RESULT: ".
    (str_contains($full, '选中的句子') && str_contains($out, '[DONE]') && $after === $before + 2
        && str_contains($body, 'companionChat(') ? 'PASS' : 'CHECK')."\n";
