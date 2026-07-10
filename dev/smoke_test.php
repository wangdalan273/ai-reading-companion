<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

$user = \App\Models\User::firstOrCreate(
    ['email' => 'smoke@test.com'],
    ['name' => 'Smoke Test', 'password' => bcrypt('password123')]
);

Session::start();
Auth::login($user);
Session::save();
$sid = Session::getId();

$request = Illuminate\Http\Request::create('/dashboard', 'GET');
$request->cookies->set(config('session.cookie'), $sid);
$request->setLaravelSession(Session::driver());
$app->instance('request', $request);

try {
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    echo "STATUS: ".$response->getStatusCode()."\n";
    $body = $response->getContent();
    echo "HAS_SHELF: ".(str_contains($body, '书架') ? 'YES' : 'NO')."\n";
    echo "HAS_FLUX_CARD: ".(str_contains($body, 'flux-card') || str_contains($body, 'data-flux') ? 'YES' : 'NO')."\n";
    echo "HAS_UPLOAD_FORM: ".(str_contains($body, 'wire:model="upload"') ? 'YES' : 'NO')."\n";
    echo "HAS_APPEARANCE: ".(str_contains($body, 'applyAppearance') ? 'YES' : 'NO')."\n";
    echo "HAS_LIVEWIRE: ".(str_contains($body, 'wire:initial-data') || str_contains($body, 'wire:submit') ? 'YES' : 'NO')."\n";
} catch (\Throwable $e) {
    echo "EXCEPTION: ".$e->getMessage()."\n";
    echo "FILE: ".$e->getFile().":".$e->getLine()."\n";
    echo "TRACE_HEAD:\n".$e->getTraceAsString()."\n";
}

// Data-layer sanity: create a Book, read it back, then clean up.
use App\Models\Book;
$before = Book::where('user_id', $user->id)->count();
$book = Book::create([
    'user_id' => $user->id,
    'title' => '冒烟测试书',
    'author' => 'Tester',
    'format' => 'pdf',
    'path' => 'books/'.$user->id.'/smoke.pdf',
    'size' => 1234,
]);
$after = Book::where('user_id', $user->id)->count();
$readBack = Book::find($book->id);
echo "DB_INSERT_OK: ".($after === $before + 1 && $readBack && $readBack->title === '冒烟测试书' ? 'YES' : 'NO')."\n";
$book->delete();
$clean = Book::where('user_id', $user->id)->count();
echo "DB_CLEAN_OK: ".($clean === $before ? 'YES' : 'NO')."\n";
