<div class="min-h-full bg-zinc-50 px-4 py-6 dark:bg-zinc-950" x-data="ragApp()">
    <div class="mx-auto max-w-6xl">
        {{-- 顶栏 --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-zinc-800 dark:text-zinc-100">🔌 数据来源 / 索引</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    连接你的书、Obsidian vault 或任意笔记文件夹，建立可检索的跨源知识库。对话请去「💬 伴读」。
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('knowledge-base', ['tab' => 'graph']) }}"
                   class="rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">🕸 图谱</a>
                <a href="{{ route('knowledge-base', ['tab' => 'highlights']) }}"
                   class="rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">🖍 划线</a>
                <a href="{{ route('companion') }}"
                   class="rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">💬 去伴读</a>
                <button @click="reindex()" :disabled="indexing"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 disabled:opacity-50">
                    <span x-show="!indexing">🔄 重建索引</span>
                    <span x-show="indexing">索引中…</span>
                </button>
            </div>
        </div>

        {{-- 索引统计 --}}
        <div class="mb-6 grid grid-cols-3 gap-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="text-2xl font-bold text-primary-600" x-text="stats.book"></div>
                <div class="text-xs text-zinc-500">📚 书片段</div>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="text-2xl font-bold text-emerald-600" x-text="stats.obsidian"></div>
                <div class="text-xs text-zinc-500">🔗 Obsidian 笔记</div>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="text-2xl font-bold text-amber-600" x-text="stats.note"></div>
                <div class="text-xs text-zinc-500">📝 通用笔记</div>
            </div>
        </div>

        {{-- 三步上手：让用户一眼看懂用途与流程 --}}
        <div class="mb-6 rounded-2xl border border-zinc-200 bg-gradient-to-br from-primary-50/60 to-white p-5 dark:border-zinc-800 dark:from-primary-950/20 dark:to-zinc-900">
            <div class="font-semibold text-zinc-800 dark:text-zinc-100">🧭 三步上手：把书与笔记变成可检索的「第二大脑」</div>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-white/70 p-3 dark:bg-zinc-800/50">
                    <div class="text-xs font-semibold text-primary-600 dark:text-primary-400">① 连接数据源</div>
                    <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">在下方填 Obsidian vault 路径，或任意笔记文件夹。都不填也能用（只索引已导入的书）。</p>
                </div>
                <div class="rounded-xl bg-white/70 p-3 dark:bg-zinc-800/50">
                    <div class="text-xs font-semibold text-primary-600 dark:text-primary-400">② 重建索引</div>
                    <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">点右上「重建索引」，系统会把所有来源切成分片、建检索。上方数字即已入库片段数。</p>
                </div>
                <div class="rounded-xl bg-white/70 p-3 dark:bg-zinc-800/50">
                    <div class="text-xs font-semibold text-primary-600 dark:text-primary-400">③ 去伴读提问</div>
                    <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">知识库建好后，到「💬 伴读」选人格与范围（跨书/仅笔记）进行跨源问答。</p>
                </div>
            </div>
            <p class="mt-3 text-xs text-zinc-400">💡 顶部数字 = 当前已索引的片段量；为 0 时先点「重建索引」。数据都在你本机，不上传第三方。</p>
        </div>

        {{-- 连接器配置 --}}
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="mb-1 text-lg font-semibold text-zinc-800 dark:text-zinc-100">🔌 连接器配置</h2>
            <p class="mb-3 text-xs text-zinc-400">告诉系统去哪里读你的资料。配好后点「重建索引」才会生效。</p>
            <label class="mb-1 block text-sm font-medium text-zinc-600 dark:text-zinc-300">Obsidian vault 路径（头等连接器）</label>
            <div class="mb-1 flex gap-2">
                <input type="text" x-model="vaultPath" list="recent-vault" placeholder="如 D:/Notes/我的vault"
                       class="flex-1 rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                <button type="button" @click="vaultPath=''" class="shrink-0 rounded-lg border border-zinc-300 px-2 text-xs text-zinc-500 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">清空</button>
            </div>
            <datalist id="recent-vault">
                <template x-for="p in recentPaths" :key="p"><option :value="p"></option></template>
            </datalist>
            <label class="mb-1 mt-3 block text-sm font-medium text-zinc-600 dark:text-zinc-300">通用笔记文件夹（不绑定 Obsidian）</label>
            <div class="mb-3 flex gap-2">
                <input type="text" x-model="noteFolder" list="recent-note" placeholder="如 D:/my-notes"
                       class="flex-1 rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                <button type="button" @click="noteFolder=''" class="shrink-0 rounded-lg border border-zinc-300 px-2 text-xs text-zinc-500 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">清空</button>
            </div>
            <datalist id="recent-note">
                <template x-for="p in recentPaths" :key="p"><option :value="p"></option></template>
            </datalist>
            <button @click="saveSettings()" :disabled="saving"
                    class="rounded-lg bg-zinc-800 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-700">
                <span x-show="!saving">💾 保存路径</span><span x-show="saving">保存中…</span>
            </button>
            <p x-show="settingsMsg" x-text="settingsMsg" class="mt-2 text-xs"
               :class="settingsMsg.startsWith('✅') ? 'text-green-600 dark:text-green-400' : 'text-rose-500'"></p>
            <p class="mt-2 text-xs text-zinc-400">两个都可为空：都不配时仅索引已导入的书。点「重建索引」会自动保存当前路径再索引。</p>
            <p class="mt-1 text-xs text-zinc-400">💡 路径需填<b>服务器本机的绝对路径</b>（如 <code>D:/Notes/我的vault</code>）。浏览器安全限制不让网页直接选服务器文件夹，故只能手填——这是本地自托管应用的常规做法。</p>
        </section>
    </div>

    <script>
        function ragApp() {
            return {
                stats: @json($stats),
                vaultPath: @json($vault_path),
                noteFolder: @json($note_folder),
                indexing: false,
                saving: false,
                settingsMsg: '',
                csrf: @json(csrf_token()),
                recentPaths: [],

                init() {
                    try {
                        this.recentPaths = JSON.parse(localStorage.getItem('rag.recentPaths') || '[]');
                    } catch (e) { this.recentPaths = []; }
                },

                rememberPath(p) {
                    if (!p) return;
                    const arr = this.recentPaths.filter(x => x !== p);
                    arr.unshift(p);
                    this.recentPaths = arr.slice(0, 8);
                    try { localStorage.setItem('rag.recentPaths', JSON.stringify(this.recentPaths)); } catch (e) {}
                },

                async saveSettings() {
                    this.saving = true;
                    try {
                        const r = await fetch('/rag/settings', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                            body: JSON.stringify({ vault_path: this.vaultPath, note_folder: this.noteFolder })
                        });
                        if (!r.ok) {
                            if (r.status === 419) {
                                this.settingsMsg = '⚠️ 页面已过期（CSRF 失效），请刷新本页后再保存。';
                                this.saving = false;
                                return;
                            }
                            throw new Error('HTTP ' + r.status);
                        }
                        const d = await r.json().catch(() => ({}));
                        this.settingsMsg = (d && d.note) ? '✅ ' + d.note : '✅ 路径已保存';
                        this.rememberPath(this.vaultPath);
                        this.rememberPath(this.noteFolder);
                    } catch (e) {
                        this.settingsMsg = '⚠️ 保存失败：' + e.message;
                    } finally {
                        this.saving = false;
                    }
                },

                async reindex() {
                    this.indexing = true;
                    this.settingsMsg = '';
                    try {
                        await fetch('/rag/settings', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                            body: JSON.stringify({ vault_path: this.vaultPath, note_folder: this.noteFolder })
                        });
                        const r = await fetch('/rag/index', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' }
                        });
                        const d = await r.json();
                        if (d.ok) {
                            this.stats = { book: d.stats.book, obsidian: d.stats.obsidian, note: d.stats.note };
                            this.settingsMsg = '✅ 已保存路径并重建索引';
                        } else {
                            this.settingsMsg = d.msg || '⚠️ 索引失败';
                        }
                    } finally {
                        this.indexing = false;
                    }
                }
            };
        }
    </script>
</div>
