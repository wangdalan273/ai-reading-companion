<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-zinc-800 dark:text-zinc-100 leading-tight">
                🕸️ <span class="gradient-text">知识库</span>
            </h2>
            <a href="{{ route('dashboard') }}"
               class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 dark:border-zinc-700 dark:text-zinc-200 hover:border-primary-400 hover:text-primary-600">
                ← 书架
            </a>
        </div>
    </x-slot>

    <div class="py-6" x-data="{ tab: '{{ in_array($activeTab, ['graph','rag','highlights','notes']) ? $activeTab : 'graph' }}' }">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- 模块内 Tab：图谱 / 记忆检索 / 划线笔记 -->
            <div class="mb-5 inline-flex rounded-xl border border-zinc-200 bg-white p-1 dark:border-zinc-800 dark:bg-zinc-900">
                <button type="button" @click="tab='graph'; setTimeout(()=>window.__kgResize&&window.__kgResize(),60)"
                    :class="tab==='graph' ? 'rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white' : 'rounded-lg px-4 py-2 text-sm text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800'">
                    🕸 图谱
                </button>
                <button type="button" @click="tab='notes'"
                    :class="tab==='notes' ? 'rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white' : 'rounded-lg px-4 py-2 text-sm text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800'">
                    📝 文本笔记
                </button>
                <button type="button" @click="tab='rag'"
                    :class="tab==='rag' ? 'rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white' : 'rounded-lg px-4 py-2 text-sm text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800'">
                    🔌 来源 / 索引
                </button>
                <button type="button" @click="tab='highlights'"
                    :class="tab==='highlights' ? 'rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white' : 'rounded-lg px-4 py-2 text-sm text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800'">
                    🖍 划线笔记
                </button>
            </div>

            <!-- 图谱 -->
            <div x-show="tab==='graph'">
                @include('partials.kb-graph', ['hasCache' => $hasCache, 'stats' => $graphStats])
            </div>

            <!-- 文本笔记 -->
            <div x-show="tab==='notes'" x-cloak>
                @include('partials.kb-notes', ['notes' => $notes])
            </div>

            <!-- 记忆检索 -->
            <div x-show="tab==='rag'" x-cloak>
                @include('partials.kb-rag', [
                    'stats' => $ragStats,
                    'vault_path' => $vault_path,
                    'note_folder' => $note_folder,
                    'prompts' => $prompts,
                ])
            </div>

            <!-- 划线笔记 -->
            <div x-show="tab==='highlights'" x-cloak>
                @include('partials.kb-highlights', [
                    'annotations' => $annotations,
                    'books' => $books,
                ])
            </div>
        </div>
    </div>
</x-app-layout>
