<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 min-w-0">
            <a href="{{ route('dashboard') }}"
               class="shrink-0 rounded-lg p-1.5 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
               title="返回书架">←</a>
            <h2 class="truncate text-base font-semibold text-zinc-800 dark:text-zinc-100" title="{{ $book->title }}">
                {{ $book->title }}
            </h2>
        </div>
    </x-slot>

        <div class="pt-4 lg:pt-0 px-4 lg:px-6"
         x-data="{ toc: [], tocOpen: false, activeHref: '' }"
         @companion:toc.window="toc = $event.detail"
         @companion:relocated.window="activeHref = $event.detail">

        <div class="max-w-[1600px] mx-auto sm:px-4 lg:px-6">
            <div class="lg:grid lg:grid-cols-[280px_minmax(0,1fr)_360px] lg:gap-5">

                <!-- ===== TOC (desktop static column) ===== -->
                <aside class="hidden lg:flex flex-col rounded-2xl border border-zinc-200 bg-white/80 dark:border-zinc-800 dark:bg-zinc-900/80 backdrop-blur h-[calc(100vh-8rem)] min-h-0 overflow-hidden">
                    <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-800 font-semibold text-zinc-800 dark:text-zinc-100">📑 目录</div>
                    <nav class="flex-1 overflow-y-auto p-2 space-y-0.5 text-sm">
                        <template x-for="item in toc" :key="item.href">
                            <button type="button" @click="CompanionReader.go(item.href); activeHref = item.href"
                                :class="activeHref === item.href
                                    ? 'block w-full text-left truncate rounded-lg px-3 py-1.5 bg-primary-100 text-primary-700 font-medium dark:bg-primary-900/40 dark:text-primary-300'
                                    : 'block w-full text-left truncate rounded-lg px-3 py-1.5 text-zinc-600 hover:bg-primary-50 hover:text-primary-700 dark:text-zinc-300 dark:hover:bg-primary-900/30'"
                                :title="item.label" x-text="item.label"></button>
                        </template>
                        <p x-show="toc.length === 0" class="px-3 py-6 text-center text-xs text-zinc-400">目录加载中…</p>
                    </nav>
                </aside>

                <!-- ===== Reading column ===== -->
                <div class="min-w-0 min-h-0">
                    <!-- Mobile: only directory button + reader -->
                    <div class="flex items-center gap-2 mb-3 lg:hidden">
                        <button @click="tocOpen = true"
                            class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm font-medium text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">📑 目录</button>
                        <span class="text-xs text-zinc-400">选中文字可问 AI · 左右边缘翻页</span>
                    </div>

                    <livewire:reader :book="$book" />
                </div>

                <!-- ===== AI 共读 column (desktop) / bottom drawer (mobile) ===== -->
                <div x-data="companionChat({ bookId: {{ $book->id }} })" class="contents">
                    <!-- Mobile FAB -->
                    <button x-show="!open" x-cloak @click="open = true"
                        class="safe-b-lg lg:hidden fixed bottom-5 right-5 z-50 rounded-full bg-primary-600 px-4 py-3 text-sm font-medium text-white shadow-xl hover:bg-primary-700 transition">
                        问 AI
                    </button>

                    <aside class="safe-b fixed inset-x-0 bottom-0 z-40 flex max-h-[82vh] flex-col rounded-t-2xl border-t border-zinc-200 bg-white/95 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95 shadow-2xl transition-transform duration-300 lg:static lg:inset-auto lg:z-auto lg:max-h-[calc(100vh-8rem)] lg:min-h-0 lg:rounded-2xl lg:border lg:translate-y-0 lg:h-[calc(100vh-8rem)]"
                        :class="open ? 'translate-y-0' : 'translate-y-full lg:translate-y-0'">

                        <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-lg">💬</span>
                                <!-- 多对话切换器 -->
                                <div class="relative" @click.outside="convMenu = false">
                                    <button type="button" @click="convMenu = !convMenu"
                                        class="flex items-center gap-1 max-w-[150px] truncate rounded-lg px-2 py-1 text-sm font-semibold text-zinc-800 hover:bg-zinc-100 dark:text-zinc-100 dark:hover:bg-zinc-800"
                                        :title="convTitle()">
                                        <span class="truncate" x-text="convTitle()"></span>
                                        <span class="text-[10px] text-zinc-400">▼</span>
                                    </button>
                                    <div x-show="convMenu" x-cloak
                                        class="absolute left-0 z-50 mt-1 w-64 rounded-xl border border-zinc-200 bg-white p-1 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                                        <template x-for="c in conversations" :key="c.id">
                                            <div class="group flex items-center gap-1 rounded-lg px-2 py-1.5 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                                :class="c.id === currentConv ? 'bg-primary-50 dark:bg-primary-900/30' : ''">
                                                <button type="button" @click="selectConv(c.id)"
                                                    class="flex-1 min-w-0 truncate text-left text-sm"
                                                    :class="c.id === currentConv ? 'font-medium text-primary-700 dark:text-primary-300' : 'text-zinc-700 dark:text-zinc-200'"
                                                    :title="c.preview || c.title" x-text="c.title"></button>
                                                <button type="button" @click.stop="renameConv(c.id)" class="opacity-0 group-hover:opacity-100 px-1 text-[11px] text-zinc-400 hover:text-primary-600" title="重命名">✎</button>
                                                <button type="button" @click.stop="deleteConv(c.id)" class="opacity-0 group-hover:opacity-100 px-1 text-[11px] text-zinc-400 hover:text-rose-600" title="删除">🗑</button>
                                            </div>
                                        </template>
                                        <button type="button" @click="newConv()"
                                            class="mt-1 w-full rounded-lg border border-dashed border-zinc-300 px-2 py-1.5 text-sm text-zinc-500 hover:border-primary-400 hover:text-primary-600 dark:border-zinc-600">
                                            ＋ 新建对话
                                        </button>
                                    </div>
                                </div>
                                <span x-show="messages.length" class="text-[11px] text-zinc-400 dark:text-zinc-500" x-text="messages.length + ' 条'"></span>
                            </div>

                            <div class="flex items-center gap-2">
                                <!-- 工具菜单：收拢所有不常用入口，避免顶部拥挤遮挡 -->
                                <div class="relative" @click.outside="toolMenu = false">
                                    <button type="button" @click="toolMenu = !toolMenu"
                                        class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                                        title="更多工具">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                                    </button>
                                    <div x-show="toolMenu" x-cloak
                                         class="absolute right-0 z-[100] mt-1 max-h-[70vh] w-48 overflow-y-auto rounded-xl border border-zinc-200 bg-white py-1 shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
                                         x-transition.origin.top.right>
                                        <a href="{{ route('book.tools', $book) }}" class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800">🧰 工作台</a>
                                        <a href="{{ route('book.analyze', [$book, 'tab' => 'graph']) }}" class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800">🕸 概念图谱</a>
                                        <a href="{{ route('book.analyze', [$book, 'tab' => 'characters']) }}" class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800">👥 人物关系</a>
                                        <a href="{{ route('book.analyze', [$book, 'tab' => 'argument']) }}" class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800">⚖ 论证地图</a>
                                        <a href="{{ route('book.export.conversation', $book) }}" class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800">📤 对话导出</a>
                                        <button type="button" @click="openQuiz(); toolMenu = false" class="block w-full px-4 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800">📝 出测验</button>
                                        <a href="{{ route('settings.ai') }}" class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800">⚙️ AI 设置</a>
                                    </div>
                                </div>
                                <button class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 lg:hidden" @click="open = false">收起</button>
                            </div>
                        </div>

                        <div x-ref="messages" class="flex-1 space-y-4 overflow-y-auto p-4">
                            <!-- 空态提示 -->
                            <div x-show="messages.length === 0" class="flex flex-col items-center justify-center h-full text-center text-sm text-zinc-400 dark:text-zinc-500">
                                <div class="text-3xl mb-3">💬</div>
                                <p>选中书里的句子点「问 AI」</p>
                                <p>或在下方直接提问，我会陪你读</p>
                            </div>

                            <!-- 对话消息列表：统一汇聚，每轮问答连贯呈现 -->
                            <template x-for="(m, i) in messages" :key="'msg-'+i">
                                <div class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                                    <!-- 头像 -->
                                    <div x-show="m.role === 'assistant'" class="shrink-0 mr-2 mt-1">
                                        <div class="w-7 h-7 rounded-full bg-primary-100 dark:bg-primary-900/40 flex items-center justify-center text-xs">🤖</div>
                                    </div>

                                    <div class="group relative max-w-[85%] min-w-0">
                                        <!-- 气泡 -->
                                        <div :class="m.role === 'user'
                                            ? 'rounded-2xl rounded-tr-md bg-primary-600 px-3.5 py-2.5 text-sm text-white shadow-sm'
                                            : 'rounded-2xl rounded-tl-md bg-zinc-100 px-3.5 py-2.5 text-sm text-zinc-800 shadow-sm dark:bg-zinc-800 dark:text-zinc-100'">
                                            <!-- 消息内容（自动换行 + 防溢出） -->
                                            <div class="whitespace-pre-wrap break-words leading-relaxed" x-text="m.content"></div>
                                            <!-- 选中原文引用（仅当引用与消息内容不同时才显示，避免重复） -->
                                            <template x-if="m.context && m.context.trim() && m.context !== m.content">
                                                <div class="mt-2 border-l-2 border-primary-400 bg-primary-50/50 dark:bg-primary-900/20 rounded-r px-2.5 py-1.5 text-[11px] leading-snug opacity-80 dark:opacity-70" x-text="'📖 原文：' + m.context"></div>
                                            </template>
                                        </div>
                                        <!-- 复制按钮 -->
                                        <button type="button" @click="copy(m.content || '', i)"
                                            :title="copiedIndex === i ? '已复制' : '复制内容'"
                                            class="absolute -bottom-2 opacity-0 transition-opacity duration-200 group-hover:opacity-100 focus:opacity-100 text-[11px] leading-none rounded-full border border-zinc-200 bg-white px-2 py-0.5 text-zinc-500 shadow-sm hover:text-primary-600 hover:border-primary-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                            :class="m.role === 'user' ? 'right-0 translate-x-1' : 'left-0 -translate-x-1'">
                                            <span x-show="copiedIndex !== i">📋</span>
                                            <span x-show="copiedIndex === i" class="text-green-600">✓</span>
                                        </button>
                                    </div>

                                    <!-- 用户头像 -->
                                    <div x-show="m.role === 'user'" class="shrink-0 ml-2 mt-1">
                                        <div class="w-7 h-7 rounded-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center text-xs">😊</div>
                                    </div>
                                </div>
                            </template>

                            <!-- 流式思考中指示 -->
                            <div x-show="streaming && messages.length && messages[messages.length-1].role === 'assistant' && !messages[messages.length-1].content"
                                class="flex justify-start">
                                <div class="shrink-0 mr-2 mt-1 w-7 h-7 rounded-full bg-primary-100 flex items-center justify-center text-xs">🤖</div>
                                <div class="inline-flex items-center gap-1.5 rounded-2xl rounded-tl-md bg-zinc-100 px-4 py-2.5 text-sm text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400 shadow-sm">
                                    <span class="animate-pulse">AI 思考中</span>
                                    <span class="inline-flex gap-0.5">
                                        <span class="animate-bounce" style="animation-delay: 0ms">·</span>
                                        <span class="animate-bounce" style="animation-delay: 150ms">·</span>
                                        <span class="animate-bounce" style="animation-delay: 300ms">·</span>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- N9 魔鬼代言人 + P15 苏格拉底：模式开关（互斥） -->
                        <div class="flex items-center gap-2 flex-wrap px-4 py-1.5 border-t border-zinc-100 dark:border-zinc-800/50">
                            <button type="button" @click="toggleDevil()"
                                :class="devil ? 'rounded-full bg-rose-600 px-3 py-1 text-xs font-medium text-white shadow-sm' : 'rounded-full border border-zinc-300 px-3 py-1 text-xs text-zinc-500 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                                title="开启后，AI 会专门挑刺、挑战你的理解，帮你把想法打磨得更严谨">
                                🎯 魔鬼代言人：<span x-text="devil ? '开' : '关'"></span>
                            </button>
                            <button type="button" @click="toggleSocratic()"
                                :class="socratic ? 'rounded-full bg-indigo-600 px-3 py-1 text-xs font-medium text-white shadow-sm' : 'rounded-full border border-zinc-300 px-3 py-1 text-xs text-zinc-500 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                                title="开启后，AI 只提问引导你思考，绝不直接给答案">
                                🧭 苏格拉底：<span x-text="socratic ? '开' : '关'"></span>
                            </button>
                            <span x-show="devil" class="text-[11px] text-rose-500 animate-pulse">已开启 — 接下来我会专门挑刺</span>
                            <span x-show="socratic" class="text-[11px] text-indigo-500 animate-pulse">已开启 — 接下来我只问不答</span>
                        </div>

                        <form @submit.prevent="send()" class="flex gap-2 border-t border-zinc-200 p-3 dark:border-zinc-800 bg-white/50 dark:bg-zinc-900/30">
                            <input x-ref="input" x-model="input" type="text"
                                placeholder="问点什么…（可先选中书中句子）"
                                class="flex-1 rounded-full border border-zinc-300 bg-transparent px-4 py-2.5 text-sm text-zinc-800 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-zinc-700 dark:text-zinc-100 dark:focus:ring-primary-800" />
                            <button type="submit" :disabled="streaming"
                                class="shrink-0 rounded-full bg-primary-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed">
                                <span x-show="!streaming">发送</span>
                                <span x-show="streaming" class="inline-flex items-center gap-1">
                                    <svg class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                    思考中
                                </span>
                            </button>
                        </form>
                    </aside>

                    <!-- P15 自动测验 modal -->
                    <div x-show="quizOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                        <div class="absolute inset-0 bg-black/50" @click="closeQuiz()"></div>
                        <div class="relative w-full max-w-2xl max-h-[88vh] overflow-y-auto rounded-2xl bg-white shadow-2xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800">
                            <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-3 dark:border-zinc-800">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="text-lg">📝</span>
                                    <span class="font-semibold text-sm">自测题</span>
                                    <span x-show="quizChapterTitle" class="text-[11px] text-zinc-400 truncate" x-text="'· '+quizChapterTitle"></span>
                                </div>
                                <button type="button" @click="closeQuiz()" class="text-zinc-500 hover:text-zinc-700">✕</button>
                            </div>

                            <div class="p-5 space-y-4">
                                <!-- 来源选择 -->
                                <div x-show="!quizQuestions.length && !quizGenerating" class="flex items-center gap-4 text-sm">
                                    <span class="text-zinc-500">出题范围：</span>
                                    <label class="flex items-center gap-1 cursor-pointer"><input type="radio" value="selection" x-model="quizSource" class="accent-primary-600"> 选中文字</label>
                                    <label class="flex items-center gap-1 cursor-pointer"><input type="radio" value="book" x-model="quizSource" class="accent-primary-600"> 全书抽取</label>
                                </div>

                                <p x-show="quizMsg" class="text-xs text-rose-500" x-text="quizMsg"></p>

                                <!-- 生成中 -->
                                <div x-show="quizGenerating" class="flex items-center gap-2 text-sm text-zinc-500">
                                    <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                    正在出题…
                                </div>

                                <!-- 题目 -->
                                <template x-for="q in quizQuestions" :key="q.id">
                                    <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100" x-text="(q.idx+1)+'. '+q.stem"></p>
                                        <div class="mt-2 space-y-1">
                                            <template x-for="(opt, oi) in q.options" :key="oi">
                                                <button type="button" @click="chooseAnswer(q.id, oi)"
                                                    :class="(quizAnswers[q.id]===oi ? (quizSubmitted ? (oi===q.answer ? 'border-green-500 bg-green-50 dark:bg-green-900/20' : 'border-rose-500 bg-rose-50 dark:bg-rose-900/20') : 'border-primary-500 bg-primary-50 dark:bg-primary-900/20') : (quizSubmitted && oi===q.answer ? 'border-green-500 bg-green-50 dark:bg-green-900/20' : 'border-zinc-200 dark:border-zinc-700')) + ' w-full text-left rounded-lg border px-3 py-1.5 text-sm transition'"
                                                    :disabled="quizSubmitted">
                                                    <span x-text="String.fromCharCode(65+oi)+'. '+opt"></span>
                                                </button>
                                            </template>
                                        </div>
                                        <div x-show="quizSubmitted" class="mt-2 text-[12px] leading-snug" :class="(quizResults[q.idx] && quizResults[q.idx].correct) ? 'text-green-600' : 'text-rose-500'">
                                            <span x-show="quizResults[q.idx] && quizResults[q.idx].correct">✓ 答对了。</span>
                                            <span x-show="!(quizResults[q.idx] && quizResults[q.idx].correct)">✗ 正确答案：<span x-text="String.fromCharCode(65 + (quizResults[q.idx] ? quizResults[q.idx].answer : -1))"></span></span>
                                            <p class="mt-1 text-zinc-500 dark:text-zinc-400" x-text="q.reason"></p>
                                        </div>
                                    </div>
                                </template>

                                <!-- 操作区 -->
                                <div class="flex items-center gap-3 pt-1 flex-wrap">
                                    <button x-show="!quizQuestions.length && !quizGenerating" @click="generateQuiz()" type="button" class="rounded-full bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700">生成测验</button>
                                    <button x-show="quizQuestions.length && !quizSubmitted" @click="submitQuiz()" type="button" class="rounded-full bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700">提交答案</button>
                                    <button x-show="quizQuestions.length" @click="exportQuiz()" type="button" class="rounded-full border border-zinc-300 px-4 py-2 text-sm text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800" title="导出为 Obsidian 友好的 Markdown（[[双链]]+frontmatter）">📤 导出 / 写入 Obsidian</button>
                                    <button x-show="quizSubmitted" @click="openQuiz()" type="button" class="rounded-full border border-zinc-300 px-4 py-2 text-sm text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">再做一次</button>
                                    <span x-show="quizSubmitted" class="text-sm font-semibold text-primary-700 dark:text-primary-300" x-text="'得分 '+quizScore+' / '+quizQuestions.length"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Mobile TOC drawer (slides from left) -->
        <div x-show="tocOpen" x-cloak class="lg:hidden fixed inset-0 z-50">
            <div class="absolute inset-0 bg-black/40" @click="tocOpen = false"></div>
            <aside class="absolute inset-y-0 left-0 w-[80%] max-w-sm bg-white dark:bg-zinc-900 shadow-2xl flex flex-col safe-b">
                <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-800">
                    <span class="font-semibold text-zinc-800 dark:text-zinc-100">📑 目录</span>
                    <button @click="tocOpen = false" class="text-zinc-500">✕</button>
                </div>
                <nav class="flex-1 overflow-y-auto p-2 space-y-0.5 text-sm">
                    <template x-for="item in toc" :key="item.href">
                        <button type="button" @click="CompanionReader.go(item.href); activeHref = item.href; tocOpen = false"
                            :class="activeHref === item.href
                                ? 'block w-full text-left truncate rounded-lg px-3 py-2 bg-primary-100 text-primary-700 font-medium dark:bg-primary-900/40 dark:text-primary-300'
                                : 'block w-full text-left truncate rounded-lg px-3 py-2 text-zinc-600 hover:bg-primary-50 hover:text-primary-700 dark:text-zinc-300 dark:hover:bg-primary-900/30'"
                            :title="item.label" x-text="item.label"></button>
                    </template>
                    <p x-show="toc.length === 0" class="px-3 py-6 text-center text-xs text-zinc-400">目录加载中…</p>
                </nav>
            </aside>
        </div>
    </div>
</x-app-layout>
