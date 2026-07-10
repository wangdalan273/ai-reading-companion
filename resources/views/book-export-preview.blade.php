<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-zinc-800 dark:text-zinc-100 leading-tight">
                📤 导出<span class="gradient-text">预览</span>
            </h2>
            <a href="{{ route('read', $book) }}"
               class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 dark:border-zinc-700 dark:text-zinc-200 hover:border-primary-400 hover:text-primary-600">
                ← 返回阅读
            </a>
        </div>
    </x-slot>

    <div class="py-8" x-data="exportPreview()" x-init="load()">
        <div class="max-w-3xl mx-auto sm:px-4 lg:px-6">
            <!-- 标题 + 操作 -->
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">{{ $book->title }}</h1>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        <span x-text="type === 'conversation' ? 'AI 对话记录' : '划线 + AI 解读笔记'"></span>
                        · 确认内容无误后再下载 / 写入 Obsidian
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="download()" :disabled="loading"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow hover:bg-primary-700 disabled:opacity-50">
                        ⬇️ 下载 .md
                    </button>
                    <button type="button" @click="pushObsidian()" :disabled="loading || pushing"
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm text-zinc-600 hover:border-primary-400 hover:text-primary-600 dark:border-zinc-700 dark:text-zinc-300 disabled:opacity-50"
                        title="写入已配置的 Obsidian vault 文件夹">
                        <span x-show="!pushing">🗒️ 写入 Obsidian</span>
                        <span x-show="pushing">写入中…</span>
                    </button>
                </div>
            </div>

            <p x-show="msg" class="mb-3 rounded-lg px-3 py-2 text-sm" :class="msgOk ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300'" x-text="msg"></p>

            <!-- 加载 / 预览 -->
            <div class="rounded-2xl border border-zinc-200 bg-white/80 p-6 dark:border-zinc-800 dark:bg-zinc-900/80 min-h-[300px]">
                <p x-show="loading" class="text-sm text-zinc-400">正在生成预览…</p>
                <article x-show="!loading" x-ref="preview" class="md-preview text-sm leading-relaxed text-zinc-800 dark:text-zinc-100"></article>
            </div>

            <p class="mt-3 text-xs text-zinc-400">
                说明：导出的 Markdown 带 frontmatter + <code>[!note]</code> callout + <code>[[双链]]</code>，可直接丢进 Obsidian 打开。
            </p>
        </div>
    </div>

    <script>
        function exportPreview() {
            return {
                type: '{{ $type }}',
                bookId: {{ $book->id }},
                markdown: '',
                filename: '',
                loading: true,
                pushing: false,
                msg: '',
                msgOk: true,

                async load() {
                    this.loading = true;
                    try {
                        const tok = document.querySelector('meta[name=csrf-token]').content;
                        const url = '/book/' + this.bookId + '/export/' + (this.type === 'conversation' ? 'conversation' : 'markdown') + '?preview=1';
                        const resp = await fetch(url, { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': tok } });
                        const data = await resp.json();
                        if (data.ok) {
                            this.markdown = data.markdown;
                            this.filename = data.filename;
                            this.render(this.markdown);
                        } else {
                            this.msg = data.msg || '生成失败'; this.msgOk = false;
                        }
                    } catch (e) {
                        this.msg = '加载失败：' + e.message; this.msgOk = false;
                    } finally {
                        this.loading = false;
                    }
                },

                // 轻量 Markdown → HTML（覆盖 Obsidian 常用语法：标题/粗斜体/行内代码/
                // 引用/callout/列表/frontmatter），先转义再渲染，安全无 XSS。
                render(md) {
                    const esc = (s) => s.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
                    let html = '';
                    let inFence = false;
                    const lines = md.split('\n');
                    let listOpen = false;
                    const closeList = () => { if (listOpen) { html += '</ul>'; listOpen = false; } };

                    for (let raw of lines) {
                        const line = raw;
                        if (line.startsWith('```')) { inFence = !inFence; html += inFence ? '<pre><code>' : '</code></pre>'; continue; }
                        if (inFence) { html += esc(line) + '\n'; continue; }

                        // frontmatter 块（--- 之间）按浅灰小字显示
                        if (/^---/.test(line)) { html += '<div class="text-[11px] text-zinc-400 font-mono">---</div>'; continue; }
                        if (/^\s*(title|author|date|tags|source):/.test(line)) { html += '<div class="text-[11px] text-zinc-400 font-mono">' + esc(line) + '</div>'; continue; }

                        let m;
                        if ((m = line.match(/^(#{1,6})\s+(.*)$/))) {
                            closeList();
                            const lv = m[1].length;
                            const txt = this.inline(esc(m[2]));
                            html += '<h' + lv + ' class="font-semibold mt-4 mb-1 text-zinc-800 dark:text-zinc-100">' + txt + '</h' + lv + '>';
                        } else if ((m = line.match(/^>\s*\[!(\w+)\]\s*(.*)$/))) {
                            closeList();
                            const kind = m[1].toLowerCase();
                            const cls = kind === 'quiz' ? 'bg-indigo-50 border-indigo-300 dark:bg-indigo-900/20'
                                : (kind === 'question' ? 'bg-amber-50 border-amber-300 dark:bg-amber-900/20' : 'bg-primary-50 border-primary-300 dark:bg-primary-900/20');
                            html += '<blockquote class="border-l-4 ' + cls + ' pl-3 py-1 my-2 rounded-r text-zinc-700 dark:text-zinc-200">' + this.inline(esc(m[2] || kind)) + '</blockquote>';
                        } else if ((m = line.match(/^>\s?(.*)$/))) {
                            closeList();
                            html += '<blockquote class="border-l-4 border-zinc-300 pl-3 py-1 my-1 text-zinc-600 dark:text-zinc-300">' + this.inline(esc(m[1])) + '</blockquote>';
                        } else if ((m = line.match(/^[-*]\s+(.*)$/))) {
                            if (!listOpen) { html += '<ul class="list-disc pl-5 my-1 space-y-1">'; listOpen = true; }
                            html += '<li>' + this.inline(esc(m[1])) + '</li>';
                        } else if (line.trim() === '') {
                            closeList(); html += '<div class="h-2"></div>';
                        } else {
                            closeList();
                            html += '<p class="my-1">' + this.inline(esc(line)) + '</p>';
                        }
                    }
                    closeList();
                    if (inFence) html += '</code></pre>';
                    this.$refs.preview.innerHTML = html;
                },

                inline(s) {
                    return s
                        .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
                        .replace(/(^|[^*])\*([^*]+)\*/g, '$1<i>$2</i>')
                        .replace(/`([^`]+)`/g, '<code class="px-1 rounded bg-zinc-100 dark:bg-zinc-800 text-[12px]">$1</code>')
                        .replace(/\[\[([^\]]+)\]\]/g, '<span class="text-primary-600">[[$1]]</span>');
                },

                download() {
                    if (!this.filename) return;
                    const url = '/book/' + this.bookId + '/export/' + (this.type === 'conversation' ? 'conversation' : 'markdown');
                    const a = document.createElement('a');
                    a.href = url; a.download = this.filename;
                    a.click();
                },

                async pushObsidian() {
                    this.pushing = true; this.msg = '';
                    try {
                        const tok = document.querySelector('meta[name=csrf-token]').content;
                        const resp = await fetch('/book/' + this.bookId + '/export/obsidian', {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': tok },
                        });
                        const data = await resp.json();
                        this.msgOk = !!data.ok;
                        this.msg = data.ok ? ('已写入 Obsidian：' + (data.path || '')) : (data.msg || '写入失败');
                    } catch (e) {
                        this.msgOk = false; this.msg = '写入失败：' + e.message;
                    } finally {
                        this.pushing = false;
                    }
                },
            };
        }
    </script>
</x-app-layout>
