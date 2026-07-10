<?php

use App\Models\Book;
use Livewire\Volt\Component;

new class extends Component
{
    public Book $book;
};

?>

<div class="space-y-3" data-book-title="{{ $book->title }}">
    <!-- Top toolbar: 目录 / 工具 / 翻页 / 朗读 -->
    <div class="flex items-center justify-between gap-3 rounded-2xl border border-zinc-200 bg-white/80 px-3 py-2 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/80">
        <div class="flex min-w-0 flex-1 items-center gap-2">
            <p class="hidden truncate text-xs text-zinc-500 dark:text-zinc-400 sm:block">{{ $book->author ?? '未知作者' }}</p>
            <h1 class="truncate text-sm font-semibold text-zinc-800 dark:text-zinc-100" title="{{ $book->title }}">
                {{ $book->title }}
            </h1>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <!-- 翻页：放在顶部，避免底部按钮遮挡文字 / 影响复制 -->
            <div class="flex items-center gap-1 rounded-lg border border-zinc-200 bg-zinc-50 p-0.5 dark:border-zinc-700 dark:bg-zinc-800/50">
                <button type="button" onclick="window.CompanionReader.prev()"
                        class="rounded-md px-2.5 py-1 text-xs font-medium text-zinc-600 hover:bg-zinc-200 dark:text-zinc-300 dark:hover:bg-zinc-700">← 上一页</button>
                <button type="button" onclick="window.CompanionReader.next()"
                        class="rounded-md px-2.5 py-1 text-xs font-medium text-zinc-600 hover:bg-zinc-200 dark:text-zinc-300 dark:hover:bg-zinc-700">下一页 →</button>
            </div>

            @if ($book->format === 'epub')
                <button id="tts-toggle" type="button" onclick="window.CompanionReader.ttsToggle()"
                    class="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    ▶ 朗读
                </button>
            @endif

            <!-- 工具下拉：把零散文具入口收拢，避免顶部拥挤遮挡 -->
            <div x-data="floatingDropdown()">
                <button type="button" x-ref="btn" @click.stop="toggle($refs.btn)"
                    class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    🧰 工具 <span class="text-[10px] text-zinc-400">▼</span>
                </button>
                <template x-teleport="body">
                    <div x-show="open" x-cloak @click.outside="open = false"
                         :style="pos"
                         class="fixed z-[200] mt-1 max-h-[70vh] w-48 overflow-y-auto rounded-xl border border-zinc-200 bg-white py-1 shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
                         x-transition.origin.top.right>
                        <a href="{{ route('book.tools', $book) }}" class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800">本书工具箱</a>
                        <a href="{{ route('book.analyze', [$book, 'tab' => 'mindmap']) }}" class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800">📊 思维导图</a>
                        <a href="{{ route('book.analyze', [$book, 'tab' => 'graph']) }}" class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800">🕸 概念图谱</a>
                        <a href="{{ route('book.analyze', [$book, 'tab' => 'characters']) }}" class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800">👥 人物关系</a>
                        <a href="{{ route('book.analyze', [$book, 'tab' => 'argument']) }}" class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800">⚖ 论证地图</a>
                        <a href="{{ route('settings.ai') }}" class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800">⚙️ AI 设置</a>
                    </div>
                </template>
            </div>

            <a href="{{ route('dashboard') }}"
               class="hidden rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 sm:inline-flex">
                ← 书架
            </a>
        </div>
    </div>

    @if ($book->format === 'pdf')
        {{-- PDF 内联查看：用浏览器原生 PDF 查看器（iframe），划线/伴读暂不支持 --}}
        <flux:card class="luxury-glass p-0 overflow-hidden">
            <div class="flex items-center justify-between gap-3 border-b border-zinc-200 px-4 py-2.5 dark:border-zinc-800">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    📄 PDF 内联预览（浏览器原生查看器）。PDF 的精准划线与 AI 伴读将在后续版本支持。
                </p>
                <a href="{{ route('book.file', $book) }}" target="_blank"
                   class="shrink-0 rounded-full border border-zinc-300 px-3 py-1 text-xs font-medium text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    ↗ 新标签打开
                </a>
            </div>
            <iframe src="{{ route('book.file', $book) }}"
                    class="w-full h-[78vh] bg-zinc-100 dark:bg-zinc-800"
                    title="{{ $book->title }} · PDF 预览"></iframe>
        </flux:card>
    @else
        <!-- epub.js viewer. wire:ignore protects epub.js's iframes from Livewire diffing.
             data-reader-url triggers the self-initialising reader engine in reader.js -->
        <flux:card class="luxury-glass p-0 overflow-hidden">
            <div x-ref="viewer"
                 wire:ignore
                 data-reader-url="{{ route('book.file', $book) }}"
                 data-book-id="{{ $book->id }}"
                 class="relative h-[68vh] w-full bg-white dark:bg-zinc-100 overflow-hidden lg:h-[calc(100vh-12rem)]">
            </div>
        </flux:card>

        <p class="text-center text-xs text-zinc-400">
            在书中<span class="text-primary-600">选中文字</span>可「划线 / 问 AI / 翻译 / 闪卡 / 分享」；<span class="text-primary-600">左右滑动</span>或点击页面<span class="text-primary-600">左右边缘</span>翻页；点「朗读」可听书。划线自动保存，阅读时长自动记录。
        </p>
    @endif
</div>
