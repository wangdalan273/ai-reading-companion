<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <!-- 主题防闪：首屏绘制前应用已保存的主题（浅色/护眼/深色） -->
        <script>
            (function () {
                try {
                    var t = localStorage.getItem('companion.theme') || 'light';
                    var h = document.documentElement;
                    h.setAttribute('data-theme', t);
                    if (t === 'dark') { h.classList.add('dark'); } else { h.classList.remove('dark'); }
                } catch (e) {}
            })();
        </script>

        <title>{{ config('app.name', 'AI 伴读') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- 伴读对话 Alpine 组件：必须在 Alpine.start() 之前注册。
             livewire.js 是普通脚本（非 defer），在 body 解析时立即启动 Alpine；
             而 app.js 是 ES module（defer），运行更晚。若把本组件放在 module 里，
             alpine:init 监听会错过时机，导致 companionChat 未注册、AI 面板整体失效。
             此处用普通脚本同步注册，并挂 window.companionChat 全局兜底。 -->
        <script>
        (function () {
            function companionChatData(cfg) {
                return {
                    bookId: (cfg && cfg.bookId) || null,
                    messages: [],
                    input: '',
                    context: '',
                    streaming: false,
                    devil: false,
                    socratic: false,
                    open: false,
                    showFloat: false,
                    floatPos: { x: 0, y: 0 },
                    selectedText: '',
                    copiedIndex: null,

                    // 多对话状态
                    conversations: [],
                    currentConv: null,
                    convMenu: false,

                    // 阅读页顶部「更多工具」下拉（read.blade.php 引用，必须在此声明，
                    // 否则 Alpine 初始化 x-show/@click 引用 toolMenu 时抛 ReferenceError，
                    // 会拖垮整个 companionChat 组件、AI 面板全瘫）
                    toolMenu: false,

                    // P15 测验状态
                    quizOpen: false,
                    quizSource: 'selection',
                    quizGenerating: false,
                    quizQuestions: [],
                    quizId: null,
                    quizChapterTitle: '',
                    quizAnswers: {},
                    quizSubmitted: false,
                    quizScore: 0,
                    quizResults: [],
                    quizMsg: '',

                    init() {
                        window.addEventListener('companion:ask-selection', (e) => {
                            this.askSelection(e.detail || '');
                        });
                        window.addEventListener('companion:translate-selection', (e) => {
                            this.translate(e.detail || '');
                        });
                        // 进入阅读页：载入本书对话列表，并恢复上次选中的对话
                        if (this.bookId) {
                            const saved = parseInt(localStorage.getItem('companion.conv.' + this.bookId) || '0');
                            this.currentConv = saved || null;
                            this.loadConversations();
                        } else {
                            this.loadHistory();
                        }
                    },

                    // 场景 A：选中正文 → 点「问 AI」→ 面板弹出 + 自动聚焦 + AI 立即流式解读
                    askSelection(text) {
                        if (!text || this.streaming) return;
                        this.selectedText = text;
                        this.context = text;
                        this.showFloat = false;
                        this.open = true;
                        this.input = '';
                        const self = this;
                        this.$nextTick(() => {
                            const el = self.$refs.input;
                            if (el) el.focus();
                            // 直接把选中的句子作为问题发出去，后端会结合原文通俗解读
                            self._stream(text, text);
                        });
                    },

                    captureSelection() {
                        this.showFloat = false;
                        this.open = true;
                        this.$nextTick(() => { const el = this.$refs.input; if (el) el.focus(); });
                    },

                    async send() {
                        const text = this.input.trim();
                        if (!text || this.streaming) return;
                        const ctx = this.context;
                        this.input = '';
                        await this._stream(text, ctx);
                    },

                    toggleDevil() { this.devil = !this.devil; if (this.devil) this.socratic = false; },
                    toggleSocratic() { this.socratic = !this.socratic; if (this.socratic) this.devil = false; },

                    // ---- P15 自动测验 ----
                    openQuiz() {
                        this.quizOpen = true;
                        this.quizSubmitted = false;
                        this.quizQuestions = [];
                        this.quizResults = [];
                        this.quizAnswers = {};
                        this.quizMsg = '';
                    },
                    closeQuiz() { this.quizOpen = false; },

                    async generateQuiz() {
                        if (this.quizGenerating) return;
                        let text = '';
                        if (this.quizSource === 'selection') {
                            // 选区在 EPUB iframe 内，window.getSelection() 读不到；
                            // 优先读 CompanionReader 记录的最近选区，兜底父窗口选区。
                            text = ((window.CompanionReader && window.CompanionReader.lastSelection) || '').trim()
                                || (window.getSelection() ? window.getSelection().toString().trim() : '');
                            if (!text) {
                                this.quizMsg = '请先在书里选中一段文字（选中后会弹出工具条），再点「生成」；或切到「全书」出题。';
                                return;
                            }
                        }
                        this.quizGenerating = true;
                        this.quizMsg = '';
                        this.quizQuestions = [];
                        try {
                            const resp = await fetch('/book/' + this.bookId + '/quiz/generate', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                },
                                body: JSON.stringify({ book_id: this.bookId, source_type: this.quizSource, text: text }),
                            });
                            const data = await resp.json();
                            if (data.ok) {
                                this.quizQuestions = data.questions.map((q, i) => ({ ...q, idx: i }));
                                this.quizId = data.quiz_id;
                                this.quizChapterTitle = data.chapter_title || '';
                            } else {
                                this.quizMsg = data.msg || '生成失败，请重试。';
                            }
                        } catch (e) {
                            this.quizMsg = '网络错误，请重试。';
                        } finally {
                            this.quizGenerating = false;
                        }
                    },
                    chooseAnswer(qId, optIdx) {
                        if (this.quizSubmitted) return;
                        this.quizAnswers[qId] = optIdx;
                    },
                    async submitQuiz() {
                        if (this.quizSubmitted || !this.quizId) return;
                        const unanswered = this.quizQuestions.filter((q) => this.quizAnswers[q.id] === undefined);
                        if (unanswered.length) {
                            this.quizMsg = '还有 ' + unanswered.length + ' 题没选，答完再提交～';
                            return;
                        }
                        try {
                            const resp = await fetch('/quiz/' + this.quizId + '/submit', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                },
                                body: JSON.stringify({ answers: this.quizAnswers }),
                            });
                            const data = await resp.json();
                            if (data.ok) {
                                this.quizResults = data.results;
                                this.quizScore = data.score;
                                this.quizSubmitted = true;
                                this.quizMsg = '';
                            } else {
                                this.quizMsg = data.msg || '提交失败。';
                            }
                        } catch (e) {
                            this.quizMsg = '网络错误，请重试。';
                        }
                    },
                    exportQuiz() {
                        if (!this.quizId) return;
                        const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                        const url = '/book/' + this.bookId + '/quiz/' + this.quizId + '/export';
                        fetch(url, { headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } })
                            .then((r) => r.json().catch(() => null))
                            .then((data) => {
                                if (data && data.ok && data.pushed) {
                                    this.quizMsg = '已写入 Obsidian vault：' + data.path;
                                } else {
                                    // 降级：触发浏览器下载 .md
                                    const a = document.createElement('a');
                                    a.href = url;
                                    a.click();
                                }
                            })
                            .catch(() => {
                                const a = document.createElement('a');
                                a.href = url;
                                a.click();
                            });
                    },

                    // 拉取当前对话的历史消息，让重新打开书 / 切换对话都能看到过往记录
                    async loadHistory() {
                        if (!this.bookId) return;
                        try {
                            const url = '/api/companion/history?book_id=' + this.bookId
                                + (this.currentConv ? '&conversation_id=' + this.currentConv : '');
                            const resp = await fetch(url, {
                                headers: {
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                },
                            });
                            if (!resp.ok) return;
                            const data = await resp.json();
                            if (data.ok && Array.isArray(data.messages)) {
                                this.messages = data.messages.map(m => ({
                                    role: m.role,
                                    content: this.clean(m.content),
                                    context: m.context || '',
                                }));
                                this.$nextTick(() => this.scrollToBottom());
                            }
                        } catch (e) {}
                    },

                    // ---- 多对话 ----
                    csrf() {
                        return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                    },
                    persistConv() {
                        if (this.bookId) localStorage.setItem('companion.conv.' + this.bookId, this.currentConv || '');
                    },
                    convTitle() {
                        const c = this.conversations.find(x => x.id === this.currentConv);
                        return c ? c.title : '伴读对话';
                    },
                    async loadConversations() {
                        if (!this.bookId) return;
                        try {
                            const resp = await fetch('/api/book/' + this.bookId + '/conversations', {
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            });
                            const data = await resp.json();
                            if (data.ok) {
                                this.conversations = data.conversations;
                                if (!this.currentConv || !this.conversations.find(c => c.id === this.currentConv)) {
                                    this.currentConv = this.conversations.length ? this.conversations[0].id : null;
                                }
                                this.persistConv();
                                this.loadHistory();
                            }
                        } catch (e) {}
                    },
                    selectConv(id) {
                        this.currentConv = id;
                        this.persistConv();
                        this.convMenu = false;
                        this.messages = [];
                        this.loadHistory();
                    },
                    async newConv() {
                        if (!this.bookId) return;
                        const title = (window.prompt('给新对话起个名字：', '对话 ' + (this.conversations.length + 1)) || '').trim();
                        if (!title) return;
                        try {
                            const resp = await fetch('/api/book/' + this.bookId + '/conversations', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                                body: JSON.stringify({ title }),
                            });
                            const data = await resp.json();
                            if (data.ok) {
                                this.conversations.push(data.conversation);
                                this.currentConv = data.conversation.id;
                                this.persistConv();
                                this.messages = [];
                                this.scrollToBottom();
                            }
                        } catch (e) {}
                    },
                    async renameConv(id) {
                        const c = this.conversations.find(x => x.id === id);
                        if (!c) return;
                        const title = (window.prompt('重命名对话：', c.title) || '').trim();
                        if (!title) return;
                        try {
                            const resp = await fetch('/api/conversations/' + id, {
                                method: 'PUT',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                                body: JSON.stringify({ title }),
                            });
                            const data = await resp.json();
                            if (data.ok) c.title = title;
                        } catch (e) {}
                    },
                    async deleteConv(id) {
                        if (!window.confirm('确定删除这个对话？其中的消息也会一并删除，且不可恢复。')) return;
                        try {
                            const resp = await fetch('/api/conversations/' + id, {
                                method: 'DELETE',
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            });
                            const data = await resp.json();
                            if (data.ok) {
                                this.conversations = this.conversations.filter(x => x.id !== id);
                                if (this.currentConv === id) {
                                    this.currentConv = this.conversations.length ? this.conversations[0].id : null;
                                    this.persistConv();
                                    this.messages = [];
                                    this.loadHistory();
                                }
                            }
                        } catch (e) {}
                    },

                    // 清理历史里可能残留的协议噪声（防御性，正常库里不会有）
                    clean(s) {
                        return (s || '').replace(/\[DONE\]/g, '').replace(/^data:\s*/g, '').trim();
                    },

                    translate(text) {
                        if (!text || this.streaming) return;
                        this.open = true;
                        this._stream('请把下面这句话翻译成中文（若本就是中文，请用通俗的话解释其含义），保留原意、简洁自然：', text);
                    },

                    async _stream(message, context) {
                        // ★ 修复 Alpine 响应式陷阱：不能 hold 裸对象引用后原地修改
                        //   push() 后 Alpine 内部创建 Proxy 包装，但局部变量仍指向原对象
                        //   后续 assistant.content += token 改的是原对象，模板读 Proxy（永远空）
                        //   正确做法：通过 this.messages[aiIdx] 索引访问（走 Proxy 链）
                        this.messages.push({ role: 'user', content: message, context: context || '' });
                        this.messages.push({ role: 'assistant', content: '' });
                        const aiIdx = this.messages.length - 1;
                        this.streaming = true;
                        this.showFloat = false;
                        try {
                            const resp = await fetch('/api/companion/ask', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'text/event-stream',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                },
                                body: JSON.stringify({ message: message, context: context || '', book_id: this.bookId, conversation_id: this.currentConv, mode: this.devil ? 'devil' : (this.socratic ? 'socratic' : '') }),
                            });
                            if (!resp.ok) {
                                this.messages[aiIdx].content = '（请求失败：' + resp.status + '）请检查 AI 设置中的密钥/协议，或稍后重试。';
                                return;
                            }
                            const reader = resp.body.getReader();
                            const decoder = new TextDecoder('utf-8');
                            let buf = '';
                            while (true) {
                                const { done, value } = await reader.read();
                                if (done) break;
                                buf += decoder.decode(value, { stream: true });
                                let frameIdx;
                                while ((frameIdx = buf.indexOf('\n\n')) !== -1) {
                                    const frame = buf.slice(0, frameIdx);
                                    buf = buf.slice(frameIdx + 2);
                                    for (const line of frame.split('\n')) {
                                        const trimmed = line.trim();
                                        if (!trimmed.startsWith('data:')) continue;
                                        let payload = trimmed.slice(trimmed.indexOf('data:') + 5).trim();
                                        if (!payload) continue;
                                        let token = payload;
                                        try { token = JSON.parse(payload); } catch (e) {}
                                        // 兼容 JSON 字符串形式的 [DONE]
                                        if (token === '[DONE]') continue;
                                        if (typeof token === 'string' && token) {
                                            this.messages[aiIdx].content += token;
                                        }
                                    }
                                }
                                this.scrollToBottom();
                            }
                        } catch (e) {
                            this.messages[aiIdx].content = this.messages[aiIdx].content || '（网络错误，请重试）';
                        } finally {
                            this.streaming = false;
                            this.context = '';
                            this.scrollToBottom();
                        }
                    },

                    // 复制消息内容（参考正常 AI 对话：每条消息可复制）
                    copy(text, i) {
                        const self = this;
                        const done = () => { self.copiedIndex = i; setTimeout(() => { if (self.copiedIndex === i) self.copiedIndex = null; }, 1500); };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(text).then(done).catch(() => self._fallbackCopy(text, done));
                        } else {
                            self._fallbackCopy(text, done);
                        }
                    },
                    _fallbackCopy(text, done) {
                        try {
                            const ta = document.createElement('textarea');
                            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                            document.body.appendChild(ta); ta.select();
                            document.execCommand('copy'); ta.remove();
                        } catch (e) {}
                        if (done) done();
                    },

                    scrollToBottom() {
                        const el = this.$refs.messages;
                        if (!el) return;
                        // 用 rAF 等浏览器完成本轮布局后再滚，避免流式 token 还没渲染就滚（更稳）
                        requestAnimationFrame(() => { el.scrollTop = el.scrollHeight; });
                    },
                };
            }

            window.companionChat = companionChatData;
            document.addEventListener('alpine:init', function () {
                if (window.Alpine && window.Alpine.data) {
                    window.Alpine.data('companionChat', companionChatData);
                }
            });
        })();
        </script>

        <!-- Scripts & styles -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <!-- B 布局外壳：左图标栏 + 顶栏 + 内容区。
         railCollapsed / mobileNav 为全局 UI 状态，供图标栏与顶栏共享。 -->
    <body class="font-sans antialiased"
          x-data="{ railCollapsed: {{ request()->routeIs('read') ? 'true' : "(localStorage.getItem('companion.rail') === '1')" }}, mobileNav: false }"
          @keydown.escape.window="mobileNav = false">

        <div class="flex h-screen overflow-hidden bg-white text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">

            <livewire:layout.navigation />

            <!-- 移动端遮罩 -->
            <div x-show="mobileNav" x-cloak @click="mobileNav = false"
                 class="fixed inset-0 z-40 bg-black/40 lg:hidden"></div>

            <!-- 内容列 -->
            <div class="flex min-w-0 flex-1 flex-col">

                <!-- 顶栏 -->
                <header class="flex h-16 shrink-0 items-center gap-2 border-b border-zinc-200 bg-white/80 px-4 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/80 sm:px-6">
                    <!-- 移动端菜单 -->
                    <button type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800 lg:hidden"
                        @click="mobileNav = true" title="菜单">
                        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>

                    <div class="flex min-w-0 flex-1 items-center gap-1.5 text-sm">
                        <span class="shrink-0 text-zinc-400">{{ config('app.name', 'AI 伴读') }}</span>
                        @php
                            $rname = optional(request()->route())?->getName() ?? '';
                            $crumb = match ($rname) {
                                'dashboard' => '书架',
                                'stats' => '阅读统计',
                                'flashcards' => '闪卡复习',
                                'knowledge' => '知识库图谱',
                                'rag' => '记忆 / 检索',
                                'settings.ai' => 'AI 设置',
                                'profile' => '账号',
                                'highlights' => '我的划线',
                                default => '',
                            };
                            if ($crumb === '' && str_starts_with($rname, 'book.')) {
                                $crumb = '本书工具';
                            }
                        @endphp
                        @if ($crumb)
                            <span class="text-zinc-300 dark:text-zinc-600">/</span>
                            <span class="truncate font-medium text-zinc-700 dark:text-zinc-200">{{ $crumb }}</span>
                        @endif
                    </div>

                    <!-- 主题切换（浅色 / 护眼 / 深色） -->
                    <div x-data="{ theme: (localStorage.getItem('companion.theme') || 'light'), pick(t){ window.CompanionTheme.set(t); this.theme = t; } }"
                         class="flex items-center gap-1 rounded-full bg-zinc-100 p-1 dark:bg-zinc-800">
                        <button @click="pick('light')"
                                :class="theme === 'light' ? 'bg-white text-primary-600 shadow-sm dark:bg-zinc-700' : 'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200'"
                                class="rounded-full px-3 py-1.5 text-xs font-medium transition duration-200" title="浅色">浅色</button>
                        <button @click="pick('sepia')"
                                :class="theme === 'sepia' ? 'bg-white text-primary-600 shadow-sm dark:bg-zinc-700' : 'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200'"
                                class="rounded-full px-3 py-1.5 text-xs font-medium transition duration-200" title="护眼">护眼</button>
                        <button @click="pick('dark')"
                                :class="theme === 'dark' ? 'bg-white text-primary-600 shadow-sm dark:bg-zinc-700' : 'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200'"
                                class="rounded-full px-3 py-1.5 text-xs font-medium transition duration-200" title="深色">深色</button>
                    </div>
                </header>

                <!-- 主内容 -->
                <main class="flex min-h-0 flex-1 flex-col">
                    @if (isset($header))
                        <header class="shrink-0 border-b border-zinc-200 bg-white/70 px-4 dark:border-zinc-800 dark:bg-zinc-950/70 sm:px-6 lg:px-8">
                            <div class="flex h-16 items-center">{{ $header }}</div>
                        </header>
                    @endif

                    <div class="min-h-0 flex-1 overflow-y-auto">
                        {{ $slot }}
                    </div>
                </main>

            </div>
        </div>

        <!-- 通用浮动下拉：teleport 到 body + fixed 定位，彻底摆脱 overflow-hidden 祖先的裁剪。
             用法：x-data="floatingDropdown()"，按钮加 x-ref="btn"，menu 用 <template x-teleport="body"> 包裹。 -->
        <script>
        (function () {
            function floatingDropdown() {
                return {
                    open: false,
                    pos: { top: '0px', right: '0px' },
                    toggle(btn) {
                        if (this.open) { this.open = false; return; }
                        this.open = true;
                        this.$nextTick(() => this.place(btn));
                    },
                    close() { this.open = false; },
                    place(btn) {
                        if (!btn) return;
                        const r = btn.getBoundingClientRect();
                        this.pos = {
                            top: (r.bottom + 4) + 'px',
                            right: (window.innerWidth - r.right) + 'px'
                        };
                    },
                    init() {
                        const update = () => { if (this.open && this.$refs.btn) this.place(this.$refs.btn); };
                        window.addEventListener('resize', update);
                        window.addEventListener('scroll', update, true);
                        // Alpine 3 生命周期清理：$cleanup 在旧版/打包版里可能不存在，用 destroy 兜底。
                        this._cleanupDropdown = () => {
                            window.removeEventListener('resize', update);
                            window.removeEventListener('scroll', update, true);
                        };
                    },
                    destroy() {
                        if (this._cleanupDropdown) this._cleanupDropdown();
                    }
                };
            }
            window.floatingDropdown = floatingDropdown;
        })();
        </script>

        @livewireScripts
        @fluxScripts
    </body>
</html>

