<?php
// P4 integrated acceptance: the whole product chain, in-process.
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\CompanionController;
use App\Models\Annotation;
use App\Models\Book;
use App\Models\Chat;
use App\Models\User;
use App\Services\ExportService;
use App\Services\LlmService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;

$results = [];
function check($name, $cond) { global $results; $results[$name] = $cond ? 'PASS' : 'FAIL'; }

$user = User::firstOrCreate(
    ['email' => 'smoke@test.com'],
    ['name' => 'Smoke Test', 'password' => bcrypt('password123')]
);

function reqAuth($uri) {
    global $sid;
    $r = Illuminate\Http\Request::create($uri, 'GET');
    $r->cookies->set(config('session.cookie'), $sid);
    $r->setLaravelSession(Session::driver());
    app()->instance('request', $r);
    return app()->make(Illuminate\Contracts\Http\Kernel::class)->handle($r);
}
function reqGuest($uri) {
    $r = Illuminate\Http\Request::create($uri, 'GET');
    app()->instance('request', $r);
    return app()->make(Illuminate\Contracts\Http\Kernel::class)->handle($r);
}

// --- S1: Auth gate (guest, checked BEFORE login so the in-process guard is fresh) ---
$resp = reqGuest('/dashboard');
check('S1_guest_dashboard_redirects', $resp->getStatusCode() === 302);

// Login for the rest of the flow.
Session::start();
Auth::login($user);
Session::save();
$sid = Session::getId();

// --- S2: Authed bookshelf ---
$resp = reqAuth('/dashboard');
$body = $resp->getContent();
check('S2_dashboard_200', $resp->getStatusCode() === 200);
check('S2_has_upload_form', str_contains($body, 'wire:model="upload"'));

// --- S3: Upload a book (simulate the Livewire save outcome) ---
$epubRel = 'books/'.$user->id.'/p4_test.epub';
$epubPath = Storage::disk('local')->path($epubRel);
@mkdir(dirname($epubPath), 0755, true);
$zip = new ZipArchive();
$zip->open($epubPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('mimetype', 'application/epub+zip');
$zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
$zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
$zip->addFromString('OEBPS/content.opf', '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="id"><metadata><dc:identifier id="id" xmlns:dc="http://purl.org/dc/elements/1.1/">urn:uuid:p4</dc:identifier><dc:title>P4</dc:title><dc:language>zh</dc:language></metadata><manifest><item id="c1" href="chapter1.xhtml" media-type="application/xhtml+xml"/></manifest><spine><itemref idref="c1"/></spine></package>');
$zip->addFromString('OEBPS/chapter1.xhtml', '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><body><h1>第一章</h1><p>这是 P4 全链路验收的测试正文。</p></body></html>');
$zip->close();

$book = Book::create([
    'user_id' => $user->id, 'title' => 'P4 全链路书', 'author' => 'Tester',
    'format' => 'epub', 'path' => $epubRel, 'size' => filesize($epubPath),
]);
check('S3_book_created', $book->exists());

// bookshelf now lists it
$resp = reqAuth('/dashboard');
check('S3_shelf_lists_book', str_contains($resp->getContent(), 'P4 全链路书'));

// --- S4: Open reader ---
$resp = reqAuth('/read/'.$book->id);
$body = $resp->getContent();
check('S4_reader_200', $resp->getStatusCode() === 200);
check('S4_has_viewer', str_contains($body, 'x-ref="viewer"'));
check('S4_has_chat', str_contains($body, 'companionChat('));

// Export buttons live on the bookshelf cards (not the reader), so verify there.
$dashBody = reqAuth('/dashboard')->getContent();
check('S4_has_export_btn', str_contains($dashBody, '导出 MD') && str_contains($dashBody, '推 Obsidian'));

// --- S5: Ask AI (SSE) ---
$quote = '这是一句被划线的原文。';
Annotation::create(['book_id' => $book->id, 'user_id' => $user->id, 'loc' => 1, 'quote' => $quote, 'tag' => '心境', 'note' => '我的批注']);
$before = Chat::where('user_id', $user->id)->count();
$ctrl = new CompanionController();
$request = Illuminate\Http\Request::create('/api/companion/ask', 'POST', ['message' => '这句什么意思', 'context' => $quote, 'book_id' => $book->id]);
$request->setUserResolver(fn () => $user);
$resp = $ctrl->ask($request);
$GLOBALS['cap'] = '';
ob_start(function ($b) { $GLOBALS['cap'] .= $b; return ''; });
$resp->sendContent();
ob_end_clean();
$after = Chat::where('user_id', $user->id)->count();
check('S5_ask_200', $resp->getStatusCode() === 200);
check('S5_chat_saved', $after === $before + 2);

// --- S6: Export Markdown ---
$resp = reqAuth('/book/'.$book->id.'/export/markdown');
$md = $resp->getContent();
check('S6_md_200', $resp->getStatusCode() === 200);
check('S6_md_has_quote', str_contains($md, $quote));
check('S6_md_has_ai', str_contains($md, 'AI 解读'));

// --- S7: Push to Obsidian (temp vault) ---
$vault = storage_path('app/obsidian_test_vault');
@mkdir($vault, 0755, true);
config(['companion.obsidian_vault_path' => $vault]);
$svc = new ExportService();
$pr = $svc->pushToObsidian($book);
check('S7_push_ok', $pr['ok'] && is_file($pr['path']) && str_contains(file_get_contents($pr['path']), $quote));

// --- Cleanup ---
Annotation::where('book_id', $book->id)->delete();
Chat::where('book_id', $book->id)->delete();
$book->delete();
array_map('unlink', glob($vault.'/*'));
@rmdir($vault);

// --- Report ---
echo "================ P4 整链验收 ================\n";
foreach ($results as $k => $v) {
    echo str_pad($k, 28, ' ').": ".$v."\n";
}
$allPass = ! in_array('FAIL', $results, true);
echo "-------------------------------------------\n";
echo "OVERALL: ".($allPass ? 'PASS ✅ 全链路通畅' : 'FAIL ❌ 见上')."\n";
