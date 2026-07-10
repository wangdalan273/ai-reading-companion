<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-zinc-800 dark:text-zinc-100 leading-tight">
            <span class="gradient-text">📚 本书分析</span>
        </h2>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 lg:px-6">
        <div class="flex flex-col gap-4 lg:flex-row">
            <!-- 左：子功能导航（明确「本书分析」为父，4 个模块为子） -->
            <aside class="lg:w-56 lg:shrink-0">
                <div class="sticky top-20 rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="px-2 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">本书分析 · 子功能</div>
                    <nav class="mt-1 space-y-1">
                        <a href="{{ route('book.analyze', [$book, 'tab' => 'graph']) }}"
                           class="flex items-center justify-between gap-2 rounded-xl px-3 py-2 text-sm font-medium transition {{ $activeTab === 'graph' ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
                            <span>🕸 概念图谱</span>
                            @include('partials.book-analyze.status-badge', ['state' => $status['concept_graph']])
                        </a>
                        <a href="{{ route('book.analyze', [$book, 'tab' => 'characters']) }}"
                           class="flex items-center justify-between gap-2 rounded-xl px-3 py-2 text-sm font-medium transition {{ $activeTab === 'characters' ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
                            <span>👥 人物关系</span>
                            @include('partials.book-analyze.status-badge', ['state' => $status['character_graph']])
                        </a>
                        <a href="{{ route('book.analyze', [$book, 'tab' => 'argument']) }}"
                           class="flex items-center justify-between gap-2 rounded-xl px-3 py-2 text-sm font-medium transition {{ $activeTab === 'argument' ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
                            <span>⚖ 论证地图</span>
                            @include('partials.book-analyze.status-badge', ['state' => $status['argument_map']])
                        </a>
                        <a href="{{ route('book.analyze', [$book, 'tab' => 'mindmap']) }}"
                           class="flex items-center justify-between gap-2 rounded-xl px-3 py-2 text-sm font-medium transition {{ $activeTab === 'mindmap' ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
                            <span>📊 思维导图</span>
                            @include('partials.book-analyze.status-badge', ['state' => $status['mindmap']])
                        </a>
                    </nav>
                    <p class="mt-3 px-2 text-[11px] leading-relaxed text-zinc-400">
                        四个模块都基于同一本书，共享上下文、互相印证理解。生成后可一键进入知识库，串成跨书的「第二大脑」。
                    </p>
                    <a href="{{ route('knowledge-base', ['tab' => 'graph']) }}"
                       class="mt-3 block rounded-xl bg-zinc-100 px-3 py-2 text-center text-xs font-medium text-zinc-600 transition hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">🕸 跨书知识库 →</a>
                </div>
            </aside>

            <!-- 右：当前子功能内容 -->
            <div class="min-w-0 flex-1">
                @include('partials.book-analyze.' . $activeTab)
            </div>
        </div>
    </div>
</x-app-layout>
