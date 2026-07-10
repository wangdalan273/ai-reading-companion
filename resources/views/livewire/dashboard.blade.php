<?php

use App\Models\Book;
use App\Models\ReadingLog;
use App\Services\ExportService;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public $books;

    public $upload = null;
    public $title = '';
    public $author = '';

    public function mount(): void
    {
        $this->loadBooks();
    }

    public function loadBooks(): void
    {
        $books = Book::where('user_id', auth()->id())->latest()->get();
        $readingMinutes = ReadingLog::where('user_id', auth()->id())
            ->whereIn('book_id', $books->pluck('id'))
            ->selectRaw('book_id, ROUND(SUM(seconds) / 60) as minutes')
            ->groupBy('book_id')
            ->pluck('minutes', 'book_id');
        $books->each(fn ($book) => $book->reading_minutes = $readingMinutes->get($book->id, 0));
        $this->books = $books;
    }

    public function save(): void
    {
        $this->validate([
            'upload' => 'required|file|mimes:pdf,epub|max:500000',
            'title' => 'nullable|string|max:255',
            'author' => 'nullable|string|max:255',
        ]);

        /** @var TemporaryUploadedFile $file */
        $file = $this->upload;
        $format = strtolower($file->getClientOriginalExtension());
        $storedPath = $file->store('books/'.auth()->id(), 'local');

        $book = Book::create([
            'user_id' => auth()->id(),
            'title' => $this->title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'author' => $this->author ?: null,
            'format' => $format,
            'path' => $storedPath,
            'size' => $file->getSize(),
        ]);

        // Phase 0/1: 上传即抽取章节文本（失败不阻断上传，可在脑图页重试）。
        try {
            $svc = app(\App\Services\BookTextService::class);
            $svc->extract($book);
            $svc->extractCover($book);
        } catch (\Throwable $e) {
            // 抽取失败不影响导入；后续在脑图页点「生成」时会再试。
        }

        $this->reset(['upload', 'title', 'author']);
        $this->loadBooks();
        $this->dispatch('book-saved');

        session()->flash('status', '已添加《'.$book->title.'》');
    }

    public function openBook(Book $book): void
    {
        abort_unless($book->user_id === auth()->id(), 403);

        $this->redirect(route('read', $book));
    }

    public function deleteBook(Book $book): void
    {
        abort_unless($book->user_id === auth()->id(), 403);

        if (Storage::disk('local')->exists($book->path)) {
            Storage::disk('local')->delete($book->path);
        }

        $book->delete();
        $this->loadBooks();
    }

    public function pushObsidian(Book $book): void
    {
        abort_unless($book->user_id === auth()->id(), 403);

        $result = app(ExportService::class)->pushToObsidian($book);

        if ($result['ok']) {
            session()->flash('status', '已推送到 Obsidian：'.basename($result['path']));
        } else {
            session()->flash('status', '推送失败：'.($result['msg'] ?? '未知错误'));
        }
    }
};
?>

<div x-data="{
        view: localStorage.getItem('bs-view') || 'card',
        showImport: false,
        setView(v){ this.view = v; localStorage.setItem('bs-view', v); },
        importOpen(){ this.showImport = true; },
        importClose(){ this.showImport = false; },
    }"
    x-on:book-saved.window="importClose()"
    class="space-y-6">

    @if (session('status'))
        <flux:callout variant="success" class="mb-1">{{ session('status') }}</flux:callout>
    @endif

    <!-- 概览 + 操作区 -->
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-50 text-xl text-primary-600 dark:bg-primary-900/30">📚</div>
            <div>
                <div class="text-lg font-bold leading-none text-zinc-800 dark:text-zinc-100">{{ $books->count() }} <span class="text-sm font-normal text-zinc-500">本藏书</span></div>
                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">已读 {{ $books->sum('reading_minutes') }} 分钟 · 导入 PDF / EPUB，选中句子即可问 AI、划线、导出。</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            @if (! $books->isEmpty())
                <!-- 视图切换（偏好持久化） -->
                <div class="flex rounded-xl border border-zinc-200 bg-white p-0.5 dark:border-zinc-800 dark:bg-zinc-900">
                    <button type="button" @click="setView('card')"
                        :class="view === 'card' ? 'bg-primary-600 text-white shadow-sm' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400'"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition" title="卡片视图">
                        <span class="mr-1">▦</span>卡片
                    </button>
                    <button type="button" @click="setView('list')"
                        :class="view === 'list' ? 'bg-primary-600 text-white shadow-sm' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400'"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition" title="列表视图">
                        <span class="mr-1">☰</span>列表
                    </button>
                </div>
            @endif
            <button type="button" @click="importOpen()"
                class="inline-flex items-center gap-1.5 rounded-xl bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 active:scale-95">
                <span class="text-base leading-none">＋</span> 导入新书
            </button>
        </div>
    </div>

    @if ($books->isEmpty())
        <!-- 空状态 -->
        <section class="flex flex-col items-center justify-center rounded-3xl border border-dashed border-zinc-300 bg-white/60 py-20 text-center dark:border-zinc-700 dark:bg-zinc-900/40">
            <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary-50 text-3xl dark:bg-primary-900/30">📚</div>
            <p class="text-zinc-600 dark:text-zinc-300">书架还是空的</p>
            <p class="mt-1 text-sm text-zinc-400">导入第一本 PDF / EPUB，开始边读边问 AI 的伴读体验。</p>
            <button type="button" @click="importOpen()" class="mt-5 inline-flex items-center gap-1.5 rounded-xl bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700">
                <span class="text-base leading-none">＋</span> 导入新书
            </button>
        </section>
    @else
        <!-- 卡片视图 -->
        <section x-show="view === 'card'" x-cloak>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                @foreach ($books as $book)
                    <article class="group relative flex flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
                        <!-- 封面：固定高度，避免无封面占位巨字显得「图片太大」 -->
                        <div class="relative h-44 bg-zinc-100 dark:bg-zinc-800">
                            @if ($book->coverUrl())
                                <img src="{{ $book->coverUrl() }}" alt="{{ $book->title }}" loading="lazy"
                                    class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                            @else
                                <div class="flex h-full w-full items-center justify-center bg-gradient-to-br {{ $book->coverGradient() }}">
                                    <span class="select-none text-4xl font-black text-white/90 drop-shadow">{{ mb_substr($book->title, 0, 1, 'UTF-8') }}</span>
                                </div>
                            @endif
                            <span class="absolute left-2 top-2 rounded-md bg-black/35 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white backdrop-blur">{{ $book->format }}</span>

                            <!-- ⋯ 菜单（右上常驻，所有次级功能可达） -->
                            <div x-data="{ menu: false }" class="absolute right-2 top-2">
                                <button type="button" @click="menu = !menu" @click.outside="menu = false"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg bg-black/30 text-white backdrop-blur transition hover:bg-black/50" aria-label="更多操作">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>
                                </button>
                                <div x-show="menu" x-cloak x-transition.origin.top.right
                                    class="absolute right-0 z-50 mt-1 w-44 rounded-xl border border-zinc-200 bg-white py-1 shadow-xl dark:border-zinc-700 dark:bg-zinc-800">
                                    <a href="{{ route('book.tools', $book) }}" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700/60">🧰 本书工具</a>
                                    <a href="{{ route('book.analyze', [$book, 'tab' => 'mindmap']) }}" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700/60">📊 思维导图</a>
                                    <a href="{{ route('book.export.markdown', $book) }}" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700/60">📤 导出 Markdown</a>
                                    <a href="{{ route('book.export.conversation', $book) }}" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700/60">💬 对话导出</a>
                                    <button wire:click="pushObsidian({{ $book->id }})" @click="menu = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700/60">📝 推送到 Obsidian</button>
                                    <div class="my-1 border-t border-zinc-100 dark:border-zinc-700"></div>
                                    <button wire:click="deleteBook({{ $book->id }})" wire:confirm="确定删除《{{ $book->title }}》？" @click="menu = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600 transition hover:bg-red-50 dark:hover:bg-red-900/30">🗑 删除本书</button>
                                </div>
                            </div>
                        </div>

                        <!-- 信息与操作：主操作「打开阅读」常显，功能全部可达 -->
                        <div class="flex flex-1 flex-col p-3">
                            <h3 class="line-clamp-2 text-sm font-semibold text-zinc-800 dark:text-zinc-100" title="{{ $book->title }}">{{ $book->title }}</h3>
                            <p class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $book->author ?? '未知作者' }}</p>
                            <p class="mt-1 text-[10px] text-zinc-400 dark:text-zinc-500">⏱ {{ $book->reading_minutes }} 分钟</p>
                            <button wire:click="openBook({{ $book->id }})"
                                class="mt-2.5 w-full rounded-lg bg-primary-600 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 active:scale-95">
                                打开阅读
                            </button>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <!-- 列表视图 -->
        <section x-show="view === 'list'" x-cloak class="space-y-2">
            @foreach ($books as $book)
                <div class="group flex items-center gap-3 rounded-2xl border border-zinc-200 bg-white p-2.5 shadow-sm transition hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                    <button wire:click="openBook({{ $book->id }})" class="relative h-16 w-12 shrink-0 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                        @if ($book->coverUrl())
                            <img src="{{ $book->coverUrl() }}" alt="{{ $book->title }}" loading="lazy" class="h-full w-full object-cover">
                        @else
                            <div class="flex h-full w-full items-center justify-center bg-gradient-to-br {{ $book->coverGradient() }}">
                                <span class="text-lg font-bold text-white/90">{{ mb_substr($book->title, 0, 1, 'UTF-8') }}</span>
                            </div>
                        @endif
                    </button>
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ $book->title }}</h3>
                        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $book->author ?? '未知作者' }} · <span class="uppercase">{{ $book->format }}</span> · ⏱ {{ $book->reading_minutes }} 分钟</p>
                    </div>
                    <button wire:click="openBook({{ $book->id }})" class="shrink-0 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-primary-700">打开</button>
                    <div x-data="{ menu: false }" class="relative shrink-0">
                        <button type="button" @click="menu = !menu" @click.outside="menu = false"
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800" aria-label="更多操作">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>
                        </button>
                        <div x-show="menu" x-cloak x-transition.origin.top.right
                            class="absolute right-0 z-50 mt-1 w-44 rounded-xl border border-zinc-200 bg-white py-1 shadow-xl dark:border-zinc-700 dark:bg-zinc-800">
                            <a href="{{ route('book.tools', $book) }}" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700/60">🧰 本书工具</a>
                            <a href="{{ route('book.analyze', [$book, 'tab' => 'mindmap']) }}" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700/60">📊 思维导图</a>
                            <a href="{{ route('book.export.markdown', $book) }}" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700/60">📤 导出 Markdown</a>
                            <a href="{{ route('book.export.conversation', $book) }}" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700/60">💬 对话导出</a>
                            <button wire:click="pushObsidian({{ $book->id }})" @click="menu = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700/60">📝 推送到 Obsidian</button>
                            <div class="my-1 border-t border-zinc-100 dark:border-zinc-700"></div>
                            <button wire:click="deleteBook({{ $book->id }})" wire:confirm="确定删除《{{ $book->title }}》？" @click="menu = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600 transition hover:bg-red-50 dark:hover:bg-red-900/30">🗑 删除本书</button>
                        </div>
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    <!-- 导入弹窗 -->
    <div x-show="showImport" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="showImport" x-transition.opacity class="absolute inset-0 bg-zinc-900/50 backdrop-blur-sm" @click="importClose()"></div>
        <div x-show="showImport" x-transition
            class="relative w-full max-w-lg rounded-2xl border border-zinc-200 bg-white p-6 shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-lg font-bold text-zinc-800 dark:text-zinc-100">📥 导入新书</h3>
                <button type="button" @click="importClose()" class="flex h-8 w-8 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800">✕</button>
            </div>
            <form wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <flux:input wire:model="title" label="书名（可选）" placeholder="留空则使用文件名" />
                    </div>
                    <div>
                        <flux:input wire:model="author" label="作者（可选）" placeholder="作者 / 出版方" />
                    </div>
                </div>
                <div x-data="{ tooBig: false }">
                    <label class="block">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">选择文件</span>
                        <input type="file" wire:model="upload" accept=".pdf,.epub"
                            @change="const f = $el.files[0]; tooBig = !!(f && f.size > 120 * 1024 * 1024); if (tooBig) $el.value = '';"
                            class="mt-1 block w-full text-sm text-zinc-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-primary-600 file:text-white hover:file:bg-primary-700 file:cursor-pointer file:transition" />
                    </label>
                    <p x-show="tooBig" x-cloak class="mt-1 text-xs text-red-500">文件超过 120MB，请压缩后再上传。</p>
                    @error('upload') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" @click="importClose()" class="rounded-lg border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">取消</button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove>添加图书</span>
                        <span wire:loading>上传中…</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </div>
</div>
