<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-zinc-800 dark:text-zinc-100 leading-tight">
            <span class="gradient-text">🧰 功能中心</span>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-6xl px-4">
            {{-- 头部：书名 + 返回阅读 --}}
            <section class="mb-5 rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-3 p-5">
                    <div>
                        <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">
                            本书的 AI 工具箱 · 《{{ $book->title }}》
                        </h1>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            所有功能都围绕这本书；其中「本书分析」是 4 个子功能的集合，结果可汇入全局「知识库」。
                        </p>
                    </div>
                    <a href="{{ route('read', $book) }}"
                       class="shrink-0 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">← 返回阅读</a>
                </div>

                {{-- 数据流动线：让"协同"看得见 --}}
                <div class="flex flex-wrap items-center gap-2 border-t border-zinc-100 px-5 py-3 text-[12px] text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    <span class="rounded-full border border-zinc-200 bg-white px-2.5 py-1 dark:border-zinc-700 dark:bg-zinc-800">① 划线 / 选中</span>
                    <span class="text-zinc-400">→</span>
                    <span class="rounded-full border border-zinc-200 bg-white px-2.5 py-1 dark:border-zinc-700 dark:bg-zinc-800">② 问 AI / 图谱 / 测验</span>
                    <span class="text-zinc-400">→</span>
                    <span class="rounded-full border border-zinc-200 bg-white px-2.5 py-1 dark:border-zinc-700 dark:bg-zinc-800">③ 闪卡 / 知识库</span>
                    <span class="ml-1 text-zinc-400">串成你的第二大脑</span>
                </div>
            </section>

            {{-- 层级标识：明确「本书子功能」↔「跨书大功能」 --}}
            <div class="mb-4 flex flex-wrap items-center gap-2 text-xs">
                <span class="rounded-full bg-primary-100 px-3 py-1 font-medium text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">📚 本书子功能</span>
                <span class="text-zinc-400">↔ 联动</span>
                <span class="rounded-full bg-zinc-100 px-3 py-1 font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">🌐 跨书大功能</span>
            </div>

            {{-- 分组一：本书分析（4 个子功能卡片） --}}
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-400">📚 本书分析 · 4 个子功能</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <a href="{{ route('book.analyze', [$book, 'tab' => 'graph']) }}"
                   class="group relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-start justify-between">
                        <div class="text-2xl">🕸</div>
                        @include('partials.book-analyze.status-badge', ['state' => $status['concept_graph']])
                    </div>
                    <h3 class="mt-2 text-sm font-semibold text-zinc-800 dark:text-zinc-100">概念图谱</h3>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">抽取概念与关系，力导向交互图。</p>
                    <span class="mt-2 inline-flex items-center text-xs font-medium text-primary-600 transition group-hover:translate-x-0.5 dark:text-primary-400">打开 →</span>
                </a>

                <a href="{{ route('book.analyze', [$book, 'tab' => 'characters']) }}"
                   class="group relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-start justify-between">
                        <div class="text-2xl">👥</div>
                        @include('partials.book-analyze.status-badge', ['state' => $status['character_graph']])
                    </div>
                    <h3 class="mt-2 text-sm font-semibold text-zinc-800 dark:text-zinc-100">人物关系</h3>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">人物、阵营、关系与时间线。</p>
                    <span class="mt-2 inline-flex items-center text-xs font-medium text-primary-600 transition group-hover:translate-x-0.5 dark:text-primary-400">打开 →</span>
                </a>

                <a href="{{ route('book.analyze', [$book, 'tab' => 'argument']) }}"
                   class="group relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-start justify-between">
                        <div class="text-2xl">⚖</div>
                        @include('partials.book-analyze.status-badge', ['state' => $status['argument_map']])
                    </div>
                    <h3 class="mt-2 text-sm font-semibold text-zinc-800 dark:text-zinc-100">论证地图</h3>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">主张、证据、反驳与批判性质询。</p>
                    <span class="mt-2 inline-flex items-center text-xs font-medium text-primary-600 transition group-hover:translate-x-0.5 dark:text-primary-400">打开 →</span>
                </a>

                <a href="{{ route('book.analyze', [$book, 'tab' => 'mindmap']) }}"
                   class="group relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-start justify-between">
                        <div class="text-2xl">📊</div>
                        @include('partials.book-analyze.status-badge', ['state' => $status['mindmap']])
                    </div>
                    <h3 class="mt-2 text-sm font-semibold text-zinc-800 dark:text-zinc-100">思维导图</h3>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">逐章总结生成的全书结构脑图。</p>
                    <span class="mt-2 inline-flex items-center text-xs font-medium text-primary-600 transition group-hover:translate-x-0.5 dark:text-primary-400">打开 →</span>
                </a>
            </div>

            {{-- 分组二：主动学习 --}}
            <h2 class="mb-3 mt-6 text-sm font-semibold uppercase tracking-wide text-zinc-400">🎯 主动学习</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <a href="{{ route('read', $book) }}"
                   class="group rounded-2xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-2xl">📝</div>
                    <div class="mt-2 font-medium text-zinc-800 dark:text-zinc-100">出测验</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">选中文字或全书抽题自测</div>
                </a>
                <a href="{{ route('read', $book) }}"
                   class="group rounded-2xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-2xl">🧭</div>
                    <div class="mt-2 font-medium text-zinc-800 dark:text-zinc-100">苏格拉底模式</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">AI 只问不答，逼你思考</div>
                </a>
                <a href="{{ route('flashcards') }}"
                   class="group rounded-2xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-2xl">🃏</div>
                    <div class="mt-2 font-medium text-zinc-800 dark:text-zinc-100">闪卡复习</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">间隔重复，记牢金句</div>
                </a>
            </div>

            {{-- 分组三：我的痕迹 · 联动全局大功能 --}}
            <h2 class="mb-3 mt-6 text-sm font-semibold uppercase tracking-wide text-zinc-400">🧠 我的痕迹 · 联动跨书大功能</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <a href="{{ route('highlights') }}"
                   class="group rounded-2xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-2">
                        <div class="text-2xl">🖍</div>
                        <span class="rounded-full bg-primary-100 px-2 py-0.5 text-[11px] text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">{{ $status['highlights'] }} 条</span>
                    </div>
                    <div class="mt-2 font-medium text-zinc-800 dark:text-zinc-100">划线集中查看</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">所有划过的句子，点开回原文</div>
                </a>
                <a href="{{ route('knowledge-base', ['tab' => 'graph']) }}"
                   class="group rounded-2xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-2">
                        <div class="text-2xl">🕸</div>
                        @if($kgCount > 0)
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">已生成</span>
                        @endif
                    </div>
                    <div class="mt-2 font-medium text-zinc-800 dark:text-zinc-100">跨书知识库</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">书 + 笔记连成网，跨源检索</div>
                </a>
                <a href="{{ route('companion') }}"
                   class="group rounded-2xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-2">
                        <div class="text-2xl">💬</div>
                        <span class="rounded-full bg-primary-100 px-2 py-0.5 text-[11px] text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">跨书问答</span>
                    </div>
                    <div class="mt-2 font-medium text-zinc-800 dark:text-zinc-100">伴读</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">自定义人格，跨书/跨笔记对话</div>
                </a>
            </div>

            {{-- 协同说明 --}}
            <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 text-sm shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="font-semibold text-zinc-700 dark:text-zinc-200">💡 它们是怎么协同工作的？</div>
                <ul class="mt-2 space-y-1.5 text-zinc-600 dark:text-zinc-300">
                    <li>· <b>划线</b>后可在浮条直接「问 AI / 翻成闪卡」，划线汇聚到「划线集中查看」，点开即跳回原文位置。</li>
                    <li>· 读累了让 AI 生成 <b>概念图谱 / 人物 / 论证 / 脑图</b>（统一在「本书分析」里），它们都基于同一本书，互相印证理解。</li>
                    <li>· <b>测验 / 苏格拉底</b>基于你选中的句子出题，反向检验你是否真读懂。</li>
                    <li>· 把本书连同你的 Obsidian 笔记一起进 <b>知识库</b>（跨书大功能），图谱自动发现跨书关联，检索时也带原文引用——这就是你的"第二大脑"。</li>
                    <li>· 在 <b>伴读</b> 里用不同人格跨书/跨笔记提问，遇到高质量回答可以一键加入知识库，让知识库持续生长。</li>
                </ul>
            </section>
        </div>
    </div>
</x-app-layout>
