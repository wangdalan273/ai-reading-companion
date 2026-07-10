<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-zinc-800 dark:text-zinc-100 leading-tight">
            <span class="gradient-text">💬 伴读</span>
        </h2>
    </x-slot>

    <div class="py-6" x-data="companionApp()">
        <div class="mx-auto max-w-6xl px-4">
            <div class="flex flex-col gap-4 lg:flex-row">
                <!-- 左：人格 + 检索范围 -->
                <aside class="lg:w-72 lg:shrink-0">
                    <div class="sticky top-20 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">伴读人格</div>
                        <div class="mt-2 space-y-2">
                            <template x-for="p in personas" :key="p.id">
                                <button type="button" @click="selectPersona(p.id)"
                                    class="flex w-full items-center gap-2 rounded-xl border px-3 py-2 text-left text-sm transition"
                                    :class="selectedPersonaId === p.id
                                        ? 'border-primary-400 bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200'
                                        : 'border-zinc-200 text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800'">
                                    <span class="text-lg" x-text="p.emoji"></span>
                                    <span class="min-w-0 flex-1 truncate font-medium" x-text="p.name"></span>
                                </button>
                            </template>
                        </div>

                        <a href="{{ route('companion.personas') }}"
                           class="mt-3 block rounded-xl bg-zinc-100 px-3 py-2 text-center text-xs font-medium text-zinc-600 transition hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">⚙️ 管理人格库</a>

                        <div class="mt-4 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">检索范围</div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <button type="button" @click="scope = 'all'"
                                    class="rounded-xl border px-2 py-1.5 text-xs font-medium transition"
                                    :class="scope === 'all' ? 'border-primary-400 bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200' : 'border-zinc-200 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300'">🌐 全部</button>
                                <button type="button" @click="scope = 'vault'"
                                    class="rounded-xl border px-2 py-1.5 text-xs font-medium transition"
                                    :class="scope === 'vault' ? 'border-primary-400 bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200' : 'border-zinc-200 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300'">📁 仅笔记</button>
                            </div>
                            <p class="mt-2 text-[11px] leading-relaxed text-zinc-400">
                                选「全部 / 仅笔记」时，AI 会跨书、跨你的 Obsidian 笔记检索，并带引用回答。
                            </p>
                        </div>

                        <button type="button" @click="newChat()"
                            class="mt-3 w-full rounded-xl border border-zinc-200 px-3 py-2 text-xs font-medium text-zinc-500 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800">🧹 新对话</button>
                    </div>
                </aside>

                <!-- 右：对话 -->
                <div class="min-w-0 flex-1">
                    <div class="flex h-[74vh] flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="flex items-center gap-2 border-b border-zinc-100 px-4 py-2.5 dark:border-zinc-800">
                            <span class="text-lg" x-text="currentPersona?.emoji || '🤖'"></span>
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200" x-text="currentPersona?.name || '伴读'"></span>
                            <span class="ml-auto rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400" x-text="scope === 'all' ? '跨书+笔记' : '仅笔记'"></span>
                        </div>

                        <div class="flex-1 space-y-3 overflow-y-auto p-4" x-ref="log">
                            <template x-for="(m, i) in messages" :key="i">
                                <div :class="m.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                                    <div class="max-w-[85%] rounded-2xl px-3.5 py-2.5 text-sm"
                                        :class="m.role === 'user'
                                            ? 'bg-primary-600 text-white'
                                            : 'border border-zinc-200 bg-zinc-50 text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100'">
                                        <p class="whitespace-pre-wrap break-words" x-text="m.content"></p>
                                        <button type="button" x-show="m.role === 'assistant' && m.content"
                                            @click="addToKb(m)"
                                            class="mt-2 inline-flex items-center gap-1 rounded-lg bg-emerald-100 px-2 py-1 text-[11px] font-medium text-emerald-700 transition hover:bg-emerald-200 dark:bg-emerald-900/40 dark:text-emerald-300">
                                            ＋ 加入知识库
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <div x-show="streaming" class="flex justify-start">
                                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-3.5 py-2.5 text-sm text-zinc-400 dark:border-zinc-700 dark:bg-zinc-800">正在思考…</div>
                            </div>
                        </div>

                        <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">
                            <div class="flex items-end gap-2">
                                <textarea x-model="input" x-ref="input" rows="1" @keydown.enter.prevent="send()"
                                    placeholder="问任何关于你读过的书 / 笔记的问题，或让伴读换个角度聊聊…"
                                    class="max-h-32 min-h-[42px] flex-1 resize-none rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 outline-none focus:border-primary-400 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"></textarea>
                                <button type="button" @click="send()" :disabled="streaming || !input.trim()"
                                    class="shrink-0 rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-primary-700 disabled:opacity-50">发送</button>
                            </div>
                            <p class="mt-1.5 text-[11px] text-zinc-400" x-show="toast" x-text="toast"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        function companionApp() {
            return {
                personas: [],
                selectedPersonaId: null,
                scope: 'all',
                messages: [],
                input: '',
                streaming: false,
                toast: '',

                get currentPersona() {
                    return this.personas.find(p => p.id === this.selectedPersonaId) || null;
                },

                init() {
                    const self = this;
                    this.loadPersonas().then(() => self.loadHistory());
                },

                async loadPersonas() {
                    try {
                        const resp = await fetch('/api/companion/personas', {
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        const data = await resp.json();
                        if (data.ok) {
                            this.personas = data.personas;
                            const def = data.personas.find(p => p.is_default) || data.personas[0];
                            this.selectedPersonaId = def ? def.id : null;
                        }
                    } catch (e) {}
                },

                async loadHistory() {
                    try {
                        const resp = await fetch('/api/companion/messages', {
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        const data = await resp.json();
                        if (data.ok && Array.isArray(data.messages)) {
                            this.messages = data.messages.map(m => ({
                                role: m.role,
                                content: (m.content || '').replace(/\[DONE\]/g, '').trim(),
                            }));
                            this.$nextTick(() => this.scrollToBottom());
                        }
                    } catch (e) {}
                },

                selectPersona(id) { this.selectedPersonaId = id; },

                newChat() { this.messages = []; this.scrollToBottom(); },

                csrf() {
                    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                },

                scrollToBottom() {
                    const el = this.$refs.log;
                    if (el) el.scrollTop = el.scrollHeight;
                },

                async send() {
                    const text = this.input.trim();
                    if (!text || this.streaming) return;
                    this.input = '';
                    this.messages.push({ role: 'user', content: text });
                    this.messages.push({ role: 'assistant', content: '' });
                    const aiIdx = this.messages.length - 1;
                    this.streaming = true;
                    this.$nextTick(() => this.scrollToBottom());

                    try {
                        const resp = await fetch('/api/companion/ask', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'text/event-stream',
                                'X-CSRF-TOKEN': this.csrf(),
                            },
                            body: JSON.stringify({
                                message: text,
                                persona_id: this.selectedPersonaId,
                                scope: this.scope,
                            }),
                        });
                        if (!resp.ok) {
                            this.messages[aiIdx].content = '（请求失败：' + resp.status + '）请检查「AI 设置」中的密钥 / 协议，或稍后重试。';
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
                                    if (token === '[DONE]') continue;
                                    if (typeof token === 'string' && token) {
                                        this.messages[aiIdx].content += token;
                                        this.$nextTick(() => this.scrollToBottom());
                                    }
                                }
                            }
                        }
                    } catch (e) {
                        this.messages[aiIdx].content = '（网络错误，请重试）';
                    } finally {
                        this.streaming = false;
                    }
                },

                async addToKb(m) {
                    if (!m.content) return;
                    try {
                        const resp = await fetch('/api/companion/add-to-kb', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.csrf(),
                            },
                            body: JSON.stringify({ content: m.content, scope: this.scope }),
                        });
                        const data = await resp.json();
                        this.toast = data.ok ? '✅ 已加入知识库，可在「🕸 知识库」查看并成网。' : '加入失败，请重试。';
                    } catch (e) {
                        this.toast = '网络错误，请重试。';
                    }
                    setTimeout(() => { this.toast = ''; }, 4000);
                },
            };
        }

        window.companionApp = companionApp;
        if (window.Alpine && window.Alpine.data) {
            window.Alpine.data('companionApp', companionApp);
        }
        document.addEventListener('alpine:init', () => {
            if (window.Alpine && window.Alpine.data) {
                window.Alpine.data('companionApp', companionApp);
            }
        });
    })();
    </script>
</x-app-layout>
