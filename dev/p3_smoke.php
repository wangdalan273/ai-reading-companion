<?php
// P3 acceptance: Markdown export + Obsidian push + dashboard buttons.
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Annotation;
use App\Models\Book;
use App\Models\Chat;
use App\Models\User;
use App\Services\ExportService;
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

// Seed a book with a highlight + its AI explanation.
$book = Book::create([
    'user_id' => $user->id, 'title' => 'P3 导出测试书', 'author' => 'Tester',
    'format' => 'epub', 'path' => 'books/'.$user->id.'/p3.epub', 'size' => 10,
]);
$quote = '这是一句被划线的原文。';
Annotation::create([
    'book_id' => $book->id, 'user_id' => $user->id, 'loc' => 1,
    'quote' => $quote, 'tag' => '心境', 'note' => '我的批注',
]);
Chat::create([
    'user_id' => $user->id, 'book_id' => $book->id, 'role' => 'assistant',
    'content' => 'AI 解释测试内容', 'context' => $quote,
]);

// 1) toMarkdown content
$svc = new ExportService();
$md = $svc->toMarkdown($book);
echo "MD_FRONTMATTER: ".(str_contains($md, 'title:') && str_contains($md, 'source:') ? 'YES' : 'NO')."\n";
echo "MD_HAS_QUOTE: ".(str_contains($md, $quote) ? 'YES' : 'NO')."\n";
echo "MD_HAS_NOTE: ".(str_contains($md, '我的批注') ? 'YES' : 'NO')."\n";
echo "MD_HAS_AI: ".(str_contains($md, 'AI 解释测试内容') ? 'YES' : 'NO')."\n";

// 2) Markdown download route
$resp = req('/book/'.$book->id.'/export/markdown');
$body = $resp->getContent();
echo "DL_STATUS: ".$resp->getStatusCode()."\n";
echo "DL_CONTENT_TYPE: ".$resp->headers->get('content-type')."\n";
echo "DL_ATTACHMENT: ".(str_contains($resp->headers->get('content-disposition') ?? '', 'attachment') ? 'YES' : 'NO')."\n";
echo "DL_BODY_OK: ".(str_contains($body, $quote) ? 'YES' : 'NO')."\n";

// 3) Obsidian push to a temp vault
$vault = storage_path('app/obsidian_test_vault');
@mkdir($vault, 0755, true);
config(['companion.obsidian_vault_path' => $vault]);
$result = $svc->pushToObsidian($book);
$pushedFile = $vault.'/'.preg_replace('/[\\\\\\/:*?"<>|]/', '_', $book->title).'-伴读.md';
echo "PUSH_OK: ".($result['ok'] ? 'YES' : 'NO')."\n";
echo "PUSH_FILE_EXISTS: ".(isset($result['path']) && is_file($result['path']) ? 'YES' : 'NO')."\n";
echo "PUSH_FILE_HAS_QUOTE: ".(isset($result['path']) && is_file($result['path']) && str_contains(file_get_contents($result['path']), $quote) ? 'YES' : 'NO')."\n";

// 3b) Push without vault path configured -> graceful failure
config(['companion.obsidian_vault_path' => null]);
$result2 = $svc->pushToObsidian($book);
echo "PUSH_NO_CONFIG_GRACEFUL: ".(! $result2['ok'] && isset($result2['msg']) ? 'YES' : 'NO')."\n";

// 4) Dashboard shows export buttons
$resp = req('/dashboard');
$dbody = $resp->getContent();
echo "DASH_HAS_MD_BTN: ".(str_contains($dbody, '导出 MD') ? 'YES' : 'NO')."\n";
echo "DASH_HAS_OBSIDIAN_BTN: ".(str_contains($dbody, '推 Obsidian') ? 'YES' : 'NO')."\n";

// Aggregate verdict BEFORE cleanup (so pushed file still exists for the check).
$pass = str_contains($md, $quote)
    && str_contains($md, 'AI 解释测试内容')
    && $resp->getStatusCode() === 200
    && str_contains($body, $quote)
    && ($result['ok'] ?? false)
    && isset($result['path'])
    && is_file($result['path'])
    && ! $result2['ok']
    && str_contains($dbody, '导出 MD')
    && str_contains($dbody, '推 Obsidian');

// Cleanup
$book->delete();
Annotation::where('book_id', $book->id)->delete();
Chat::where('book_id', $book->id)->delete();
array_map('unlink', glob($vault.'/*'));
@rmdir($vault);

echo "P3_RESULT: ".($pass ? 'PASS' : 'CHECK')."\n";
