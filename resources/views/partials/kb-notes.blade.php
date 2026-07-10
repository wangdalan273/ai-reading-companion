<div class="py-4" x-data="notesApp()" x-init="initNotes()">
    <div class="px-4 sm:px-6 lg:px-8">
        <!-- 页头 -->
        <div class="mb-5 rounded-2xl border border-zinc-200 bg-gradient-to-br from-primary-50/60 to-white p-4 dark:border-zinc-800 dark:from-primary-950/20 dark:to-zinc-900">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="font-semibold text-zinc-800 dark:text-zinc-100">📝 文本笔记</div>
                    <p class="mt-1 max-w-2xl text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
                        这里汇集知识库中所有可检索的原文：书、Obsidian 笔记、通用笔记、伴读对话里一键收藏的回答。它们和「🕸 图谱」是同一份数据的两面——图谱看关系，这里看内容。支持两种浏览方式：<b>瀑布流</b>适合随性扫读、<b>分组折叠</b>适合按来源精准定位；搜索、筛选、展开、删除均可用。
                    </p>
                </div>
                <a href="{{ route('knowledge-base', ['tab' => 'graph']) }}" class="hidden shrink-0 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 sm:block">🕸 看图谱</a>
            </div>
        </div>

        <!-- 搜索 + 筛选 + 布局切换 -->
        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <input type="text" x-model="q" @keydown.enter="search()"
                       placeholder="搜索标题、正文或文件路径…"
                       class="w-full rounded-xl border border-zinc-300 bg-white py-2.5 pl-10 pr-4 text-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:focus:ring-primary-800">
                <span class="absolute left-3 top-2.5 text-zinc-400">🔍</span>
                <button type="button" @click="search()" class="absolute right-2 top-1.5 rounded-lg bg-primary-600 px-3 py-1 text-xs font-medium text-white hover:bg-primary-700">搜索</button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" @click="type='all'; search()"
                    :class="type==='all' ? 'rounded-full bg-primary-600 px-3 py-1.5 text-xs font-medium text-white' : 'rounded-full border border-zinc-300 px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800'">全部</button>
                <button type="button" @click="type='book'; search()"
                    :class="type==='book' ? 'rounded-full bg-blue-600 px-3 py-1.5 text-xs font-medium text-white' : 'rounded-full border border-zinc-300 px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800'">📖 书</button>
                <button type="button" @click="type='obsidian'; search()"
                    :class="type==='obsidian' ? 'rounded-full bg-fuchsia-600 px-3 py-1.5 text-xs font-medium text-white' : 'rounded-full border border-zinc-300 px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800'">🔗 Obsidian</button>
                <button type="button" @click="type='note'; search()"
                    :class="type==='note' ? 'rounded-full bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white' : 'rounded-full border border-zinc-300 px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800'">📝 通用笔记</button>
                <button type="button" @click="type='companion'; search()"
                    :class="type==='companion' ? 'rounded-full bg-amber-600 px-3 py-1.5 text-xs font-medium text-white' : 'rounded-full border border-zinc-300 px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800'">💬 伴读收藏</button>
            </div>

            <!-- 布局切换：瀑布流 / 分组折叠 -->
            <div class="inline-flex shrink-0 rounded-xl border border-zinc-200 bg-white p-1 dark:border-zinc-800 dark:bg-zinc-900" role="group" aria-label="布局切换">
                <button type="button" @click="setLayout('masonry')"
                    :aria-pressed="layout==='masonry'"
                    :class="layout==='masonry' ? 'rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white' : 'rounded-lg px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800'">
                    ▦ 瀑布流
                </button>
                <button type="button" @click="setLayout('grouped')"
                    :aria-pressed="layout==='grouped'"
                    :class="layout==='grouped' ? 'rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white' : 'rounded-lg px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800'">
                    ▤ 分组
                </button>
            </div>
        </div>

        <!-- 结果信息 -->
        <div class="mb-3 flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
            <span>共 <span x-text="items.length" class="font-medium text-zinc-700 dark:text-zinc-200"></span> 条原子卡片</span>
            <span x-show="loading" class="flex items-center gap-1.5">
                <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                加载中…
            </span>
        </div>

        <!-- ============ 瀑布流 ============ -->
        <div x-show="layout==='masonry'" x-cloak
             class="gap-4 columns-1 sm:columns-2 lg:columns-3">
            <template x-for="item in items" :key="cardKey(item)">
                <div class="mb-4 break-inside-avoid rounded-2xl border border-zinc-200 border-l-4 bg-white p-4 transition hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900"
                     :class="borderClass(item.type)">
                    <div class="mb-2 flex items-center gap-2">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl text-base" :class="badgeClass(item.type)">
                            <span x-text="typeIcon(item.type)"></span>
                        </div>
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide" :class="chipClass(item.type)" x-text="typeLabel(item.type)"></span>
                        <span class="ml-auto text-[10px] text-zinc-400" x-text="item.chunks + ' 段 · ' + formatDate(item.updated_at)"></span>
                    </div>
                    <h3 class="mb-1 text-sm font-semibold leading-snug text-zinc-800 dark:text-zinc-100" x-text="item.title"></h3>
                    <p class="mb-2 line-clamp-2 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300" x-text="item.preview || '（暂无预览）'"></p>
                    <div x-show="item.links && item.links.length" class="mb-3 flex flex-wrap gap-1">
                        <template x-for="lk in item.links.slice(0, 4)" :key="lk">
                            <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400" x-text="'[[ ' + lk + ' ]]'"></span>
                        </template>
                        <span x-show="item.links.length > 4" class="text-[10px] text-zinc-400">+更多</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="open(item)" class="flex-1 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700">展开详情</button>
                        <a x-show="item.type === 'book' && item.book_id" :href="'/read/' + item.book_id"
                           class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">📖 阅读</a>
                        <button type="button" x-show="item.type !== 'book'" @click="askDelete(item)"
                            class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs text-rose-600 hover:bg-rose-50 dark:border-rose-900/40 dark:text-rose-400 dark:hover:bg-rose-900/20">🗑</button>
                    </div>
                </div>
            </template>
        </div>

        <!-- ============ 分组折叠 ============ -->
        <div x-show="layout==='grouped'" x-cloak class="space-y-4">
            <template x-for="g in groupList()" :key="g.type">
                <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <button type="button" @click="toggleGroup(g.type)"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                            :aria-expanded="openGroups[g.type]">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl text-base" :class="badgeClass(g.type)">
                            <span x-text="g.icon"></span>
                        </div>
                        <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100" x-text="g.label"></span>
                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400" x-text="g.items.length"></span>
                        <svg class="ml-auto h-4 w-4 text-zinc-400 transition-transform duration-200" :class="openGroups[g.type] ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor"><path d="M5 7l5 5 5-5z"/></svg>
                    </button>
                    <div x-show="openGroups[g.type]" class="border-t border-zinc-100 px-4 py-3 dark:border-zinc-800">
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                            <template x-for="item in g.items" :key="cardKey(item)">
                                <div class="rounded-2xl border border-zinc-200 border-l-4 bg-white p-4 transition hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900"
                                     :class="borderClass(item.type)">
                                    <div class="mb-2 flex items-center gap-2">
                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide" :class="chipClass(item.type)" x-text="typeLabel(item.type)"></span>
                                        <span class="ml-auto text-[10px] text-zinc-400" x-text="item.chunks + ' 段 · ' + formatDate(item.updated_at)"></span>
                                    </div>
                                    <h3 class="mb-1 text-sm font-semibold leading-snug text-zinc-800 dark:text-zinc-100" x-text="item.title"></h3>
                                    <p class="mb-2 line-clamp-2 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300" x-text="item.preview || '（暂无预览）'"></p>
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="open(item)" class="flex-1 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700">展开详情</button>
                                        <a x-show="item.type === 'book' && item.book_id" :href="'/read/' + item.book_id"
                                           class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">📖</a>
                                        <button type="button" x-show="item.type !== 'book'" @click="askDelete(item)"
                                            class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs text-rose-600 hover:bg-rose-50 dark:border-rose-900/40 dark:text-rose-400 dark:hover:bg-rose-900/20">🗑</button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </section>
            </template>
        </div>

        <!-- 空态 -->
        <div x-show="!loading && items.length === 0" class="mt-10 rounded-2xl border border-dashed border-zinc-300 bg-white/60 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900/40">
            <div class="mb-3 text-4xl">📝</div>
            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">暂无笔记</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">先去导入书、配置 Obsidian vault，或在伴读中把精彩回答加入知识库。</p>
            <a href="{{ route('knowledge-base', ['tab' => 'rag']) }}" class="mt-4 inline-block rounded-full bg-primary-600 px-4 py-2 text-xs font-medium text-white hover:bg-primary-700">去配置来源</a>
        </div>
    </div>

    <!-- 详情弹窗 -->
    <div x-show="detail" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/50" @click="detail = null"></div>
        <div class="relative max-h-[88vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-5 shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="mb-1 flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide"
                              :class="chipClass(detail.type)" x-text="typeLabel(detail.type)"></span>
                        <span class="text-[10px] text-zinc-400" x-text="detail.chunks + ' 段'"></span>
                    </div>
                    <h3 class="text-base font-semibold text-zinc-800 dark:text-zinc-100" x-text="detail.title"></h3>
                </div>
                <button type="button" @click="detail = null" class="shrink-0 rounded-lg p-1 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">✕</button>
            </div>

            <div class="mb-4 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-400">
                <div x-show="detail.type === 'book' && detail.book_id">📖 书籍 · <a :href="'/read/' + detail.book_id" class="text-primary-600 hover:underline">打开阅读</a></div>
                <div x-show="detail.source_path">📁 <span x-text="detail.source_path"></span></div>
                <div x-show="detail.meta && detail.meta.tags && detail.meta.tags.length">🏷 标签：<span x-text="detail.meta.tags.join(' ')"></span></div>
                <div x-show="detail.links && detail.links.length">🔗 双链：<span x-text="detail.links.map(l => '[[' + l + ']]').join(' ')"></span></div>
                <div>🕒 <span x-text="formatDate(detail.updated_at)"></span></div>
            </div>

            <div class="space-y-3">
                <template x-for="(chunk, idx) in detailChunks" :key="idx">
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="mb-1 text-[10px] text-zinc-400" x-text="'片段 #' + (idx + 1)"></div>
                        <div class="whitespace-pre-wrap text-sm leading-relaxed text-zinc-700 dark:text-zinc-200" x-text="chunk"></div>
                    </div>
                </template>
            </div>

            <p x-show="detailChunks.length === 0 && !detailLoading" class="text-sm text-zinc-400">没有更多片段。</p>
            <p x-show="detailLoading" class="text-sm text-zinc-400">加载片段中…</p>
        </div>
    </div>

    <!-- 删除确认弹窗 -->
    <div x-show="itemToDelete" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/50" @click="itemToDelete = null"></div>
        <div class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-5 shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-3 flex items-center gap-2 text-rose-600">
                <span class="text-xl">🗑</span>
                <span class="font-semibold">删除笔记</span>
            </div>
            <p class="mb-4 text-sm text-zinc-700 dark:text-zinc-200">
                确定删除「<span class="font-medium" x-text="itemToDelete ? itemToDelete.title : ''"></span>」吗？此操作会从知识库中移除对应的全部片段，不可恢复。图谱需要重新生成才会同步。
            </p>
            <div class="flex justify-end gap-2">
                <button type="button" @click="itemToDelete = null" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">取消</button>
                <button type="button" @click="confirmDelete()" :disabled="deleteLoading"
                    class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700 disabled:opacity-50">
                    <span x-show="!deleteLoading">确认删除</span>
                    <span x-show="deleteLoading">删除中…</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 操作提示 -->
    <div x-show="notice" x-cloak x-transition class="fixed bottom-5 right-5 z-50 rounded-xl bg-zinc-900 px-4 py-2 text-sm text-white shadow-xl dark:bg-white dark:text-zinc-900"
         x-text="notice"></div>

    <script>
        function notesApp() {
            // 类型配色用「完整字面量 class」映射，确保 Tailwind JIT 能扫描生成（避免字符串拼接导致类丢失）
            const STYLES = {
                book: {
                    badge: 'bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300',
                    border: 'border-l-blue-500',
                    chip: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'
                },
                obsidian: {
                    badge: 'bg-fuchsia-50 text-fuchsia-600 dark:bg-fuchsia-900/30 dark:text-fuchsia-300',
                    border: 'border-l-fuchsia-500',
                    chip: 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-900/40 dark:text-fuchsia-300'
                },
                note: {
                    badge: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-300',
                    border: 'border-l-emerald-500',
                    chip: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                },
                companion: {
                    badge: 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-300',
                    border: 'border-l-amber-500',
                    chip: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
                },
                _: {
                    badge: 'bg-zinc-50 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300',
                    border: 'border-l-zinc-500',
                    chip: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'
                }
            };

            return {
                q: '',
                type: 'all',
                layout: 'masonry',
                items: @json($notes),
                loading: false,
                detail: null,
                detailChunks: [],
                detailLoading: false,
                itemToDelete: null,
                deleteLoading: false,
                notice: '',
                csrf: @json(csrf_token()),
                openGroups: { book: true, obsidian: true, note: true, companion: true },

                initNotes() {
                    try {
                        const saved = localStorage.getItem('kb-notes-layout');
                        if (saved === 'masonry' || saved === 'grouped') this.layout = saved;
                    } catch (e) {}
                },

                setLayout(l) {
                    this.layout = l;
                    try { localStorage.setItem('kb-notes-layout', l); } catch (e) {}
                },

                cardKey(item) {
                    return item.type + '|' + (item.book_id || '') + '|' + (item.source_path || '') + '|' + item.title;
                },

                typeIcon(t) {
                    return { book: '📖', obsidian: '🔗', note: '📝', companion: '💬' }[t] || '📄';
                },

                typeLabel(t) {
                    return { book: '书', obsidian: 'Obsidian 笔记', note: '通用笔记', companion: '伴读收藏' }[t] || '笔记';
                },

                badgeClass(t) { return (STYLES[t] || STYLES._).badge; },
                borderClass(t) { return (STYLES[t] || STYLES._).border; },
                chipClass(t) { return (STYLES[t] || STYLES._).chip; },

                // 分组折叠：按来源类型聚合（顺序固定，便于扫读）；与顶部类型筛选联动
                groupList() {
                    const order = ['book', 'obsidian', 'note', 'companion'];
                    const map = {};
                    for (const it of this.items) {
                        if (!map[it.type]) map[it.type] = [];
                        map[it.type].push(it);
                    }
                    return order
                        .filter(t => map[t])
                        .map(t => ({ type: t, label: this.typeLabel(t), icon: this.typeIcon(t), items: map[t] }));
                },

                toggleGroup(t) {
                    this.openGroups[t] = !this.openGroups[t];
                },

                formatDate(d) {
                    if (!d) return '';
                    const date = new Date(d);
                    return date.toLocaleString('zh-CN', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                },

                showNotice(msg) {
                    this.notice = msg;
                    setTimeout(() => { if (this.notice === msg) this.notice = ''; }, 2500);
                },

                async search() {
                    this.loading = true;
                    try {
                        const params = new URLSearchParams({ q: this.q, type: this.type });
                        const r = await fetch('/api/knowledge/notes?' + params.toString(), {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        const d = await r.json();
                        this.items = d.ok ? d.items : [];
                    } catch (e) {
                        this.items = [];
                    } finally {
                        this.loading = false;
                    }
                },

                async open(item) {
                    this.detail = item;
                    this.detailChunks = [item.preview || '（暂无内容）'];
                    this.detailLoading = true;
                    try {
                        const params = new URLSearchParams({
                            type: item.type,
                            book_id: item.book_id || '',
                            source_path: item.source_path || '',
                            title: item.title
                        });
                        const r = await fetch('/api/knowledge/chunks?' + params.toString(), {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        const d = await r.json();
                        if (d.ok && d.chunks && d.chunks.length) {
                            this.detailChunks = d.chunks;
                        }
                    } catch (e) {
                        // 保持预览
                    } finally {
                        this.detailLoading = false;
                    }
                },

                askDelete(item) {
                    this.itemToDelete = item;
                },

                async confirmDelete() {
                    if (!this.itemToDelete) return;
                    this.deleteLoading = true;
                    try {
                        const item = this.itemToDelete;
                        const params = new URLSearchParams({
                            type: item.type,
                            book_id: item.book_id || '',
                            source_path: item.source_path || '',
                            title: item.title
                        });
                        const r = await fetch('/api/knowledge/notes?' + params.toString(), {
                            method: 'DELETE',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': this.csrf
                            }
                        });
                        const d = await r.json();
                        if (d.ok) {
                            this.showNotice('已删除「' + item.title + '」');
                            this.itemToDelete = null;
                            await this.search();
                        } else {
                            this.showNotice('删除失败：' + (d.msg || '未知错误'));
                        }
                    } catch (e) {
                        this.showNotice('删除失败：' + e.message);
                    } finally {
                        this.deleteLoading = false;
                    }
                }
            };
        }
    </script>
</div>
