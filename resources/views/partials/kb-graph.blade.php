<div class="py-4">
    <div class="px-4 sm:px-6 lg:px-8">
        <!-- 标题与操作 -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="max-w-3xl">
                <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">我的第二大脑 · 知识库图谱</h1>
                <p class="text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
                    把书与 Obsidian 笔记连成一张网：每本书 / 每篇笔记是一张<b>原子卡</b>，Obsidian 的 <code>[[]]</code> 双链是<b>强连接</b>（紫色虚线），内容相关的书与笔记会被自动发现为<b>弱关联</b>（灰线）。点任意节点看卡片详情、相关链接，并一键跳回原文。
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('knowledge-base', ['tab' => 'notes']) }}"
                   class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">📝 文本笔记</a>
                <a href="{{ route('knowledge-base', ['tab' => 'rag']) }}"
                   class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">🔌 来源 / 索引</a>
                <a href="{{ route('knowledge-base', ['tab' => 'highlights']) }}"
                   class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">🖍 划线笔记</a>
                <button id="fit-btn"
                        class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">🔍 重置视图</button>
                <button id="gen-btn"
                        class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">🤖 生成 / 重新生成</button>
            </div>
        </div>

        <div id="status" class="mb-3 text-sm text-zinc-500 dark:text-zinc-400"></div>

        <!-- 统计瓦片 -->
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/30">📖</div>
                <div><div id="stat-books" class="text-xl font-bold leading-none text-zinc-800 dark:text-zinc-100">0</div><div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">书</div></div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30">📝</div>
                <div><div id="stat-notes" class="text-xl font-bold leading-none text-zinc-800 dark:text-zinc-100">0</div><div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">笔记</div></div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-fuchsia-50 text-fuchsia-600 dark:bg-fuchsia-900/30">🔗</div>
                <div><div id="stat-wikilinks" class="text-xl font-bold leading-none text-zinc-800 dark:text-zinc-100">0</div><div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">双链</div></div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">🕸</div>
                <div><div id="stat-related" class="text-xl font-bold leading-none text-zinc-800 dark:text-zinc-100">0</div><div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">关联</div></div>
            </div>
        </div>

        <!-- 图谱主区域：全宽 + 悬浮详情面板 -->
        <div class="relative">
            <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800 sm:px-5">
                    <div class="flex items-center gap-2">
                        <span class="text-base">🕸</span>
                        <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">关系图谱</h3>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 text-[11px] text-zinc-500 dark:text-zinc-400">
                        <span><span class="inline-block h-2.5 w-2.5 rounded-full align-middle" style="background:#3b82f6"></span> 书</span>
                        <span><span class="inline-block h-2.5 w-2.5 rounded-full align-middle" style="background:#10b981"></span> 笔记</span>
                        <span><span class="inline-block h-2.5 w-2.5 rounded-full align-middle" style="background:#a855f7"></span> Obsidian</span>
                        <span><span class="inline-block h-2.5 w-2.5 rounded-full align-middle" style="background:#9ca3af"></span> 双链目标</span>
                    </div>
                </header>
                <div class="relative">
                    <canvas id="kg-canvas" class="block h-[60vh] w-full touch-none lg:h-[calc(100vh-13rem)]"></canvas>
                    <div id="hint" class="pointer-events-none absolute bottom-2 left-3 text-[11px] text-zinc-400">滚轮缩放 · 拖背景平移 · 拖节点排版 · 点卡片看详情与跳转</div>
                </div>
            </section>

            <!-- 详情面板：悬浮在图谱右上角，点节点后滑出 -->
            <aside id="detail" class="absolute right-3 top-3 z-10 hidden max-h-[min(560px,70vh)] w-80 overflow-y-auto rounded-2xl border border-zinc-200 bg-white/95 p-4 text-sm shadow-xl backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95 sm:w-96">
                <div class="mb-2 flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <span id="d-icon" class="text-lg">📝</span>
                        <div id="d-name" class="break-words text-base font-semibold text-zinc-800 dark:text-zinc-100"></div>
                    </div>
                    <button type="button" onclick="document.getElementById('detail').classList.add('hidden')" class="shrink-0 rounded-lg p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">✕</button>
                </div>
                <div id="d-type" class="mb-2 inline-block rounded-full px-2 py-0.5 text-xs"></div>
                <div id="d-preview" class="mb-3 max-h-52 overflow-auto text-sm leading-relaxed text-zinc-700 dark:text-zinc-300"></div>
                <div id="d-jump" class="mb-3"></div>
                <div class="mb-1 text-xs text-zinc-500 dark:text-zinc-400">关联卡片（反向链接感）：</div>
                <div id="d-related" class="mt-1 flex flex-wrap gap-1"></div>
            </aside>
        </div>

        <!-- 怎么用：可折叠，不占地方 -->
        <details class="group mt-4 rounded-2xl border border-zinc-200 bg-white p-3 text-xs text-zinc-500 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
            <summary class="flex cursor-pointer list-none items-center gap-2 font-medium text-zinc-700 dark:text-zinc-200">
                <span class="transition group-open:rotate-90">▶</span>
                💡 怎么用（3 步）
            </summary>
            <ol class="mt-2 list-decimal list-inside space-y-1 pl-1">
                <li>在「🔌 来源」页填好 Obsidian vault 路径（或笔记文件夹），点<b>重建索引</b>。</li>
                <li>回到这里点「生成 / 重新生成」，等待数秒聚合完成。</li>
                <li>在图上<b>滚轮缩放、拖背景平移、拖节点排版</b>；<b>点节点</b>看详情与跳转。笔记里写 <code>[[另一篇笔记标题]]</code> 即会被识别为强连接。</li>
            </ol>
            <p class="mt-1 text-zinc-400">图谱与「🔌 来源」检索共用同一份索引：图谱帮你看<b>关系</b>，检索帮你看<b>原文</b>，两者互补。想要浏览文字内容请去「📝 文本笔记」。</p>
        </details>
    </div>
</div>

<script>
(function () {
    const canvas = document.getElementById('kg-canvas');
    const ctx = canvas.getContext('2d');
    const statusEl = document.getElementById('status');
    const detail = document.getElementById('detail');

    const TYPE_META = {
        book:     { icon: '📖', color: '#3b82f6', label: '书' },
        note:     { icon: '📝', color: '#10b981', label: '笔记' },
        obsidian: { icon: '🔗', color: '#a855f7', label: 'Obsidian 笔记' },
        wikilink: { icon: '🔗', color: '#9ca3af', label: '双链目标（缺文件）' },
    };
    const DPR = window.devicePixelRatio || 1;
    const isDark = document.documentElement.classList.contains('dark');
    const LABEL = isDark ? '#e5e7eb' : '#1f2937';

    let W = 0, H = 0;
    let data = { nodes: [], edges: [], stats: null };
    let nodes = [], edges = [];
    const cam = { x: 0, y: 0, scale: 1 };
    let raf = 0, ticks = 0, energy = 0;
    let allNodes = [], allEdges = [];
    let showAll = true;

    function resize() {
        const r = canvas.getBoundingClientRect();
        W = Math.max(1, r.width);
        H = Math.max(1, r.height);
        canvas.width = Math.max(1, Math.floor(W * DPR));
        canvas.height = Math.max(1, Math.floor(H * DPR));
        ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    }
    window.addEventListener('resize', () => { resize(); draw(); });

    function w2s(x, y) { return { x: (x - cam.x) * cam.scale + W / 2, y: (y - cam.y) * cam.scale + H / 2 }; }
    function s2w(x, y) { return { x: (x - W / 2) / cam.scale + cam.x, y: (y - H / 2) / cam.scale + cam.y }; }

    function metaOf(n) { return TYPE_META[n.type] || TYPE_META.note; }

    function radiusFor(degree) {
        return Math.max(6, Math.min(26, 6 + Math.sqrt(degree || 1) * 2.2));
    }

    function init() {
        const n = data.nodes.length;
        if (n === 0) {
            nodes = []; edges = []; draw(); return;
        }
        const baseR = Math.min(W, H) * 0.22;
        nodes = data.nodes.map((c, i) => {
            const r = radiusFor(c.degree || 1);
            const a = (i / Math.max(1, n)) * Math.PI * 2;
            return {
                ...c, r,
                x: Math.cos(a) * baseR,
                y: Math.sin(a) * baseR,
                vx: 0, vy: 0, fixed: false,
            };
        });
        const idx = {};
        nodes.forEach((n, i) => idx[n.id] = i);
        edges = data.edges
            .filter(e => idx[e.a] != null && idx[e.b] != null)
            .map(e => ({ a: idx[e.a], b: idx[e.b], type: e.type || 'related', label: e.label || '' }));
        cam.x = 0; cam.y = 0; cam.scale = 1;
        ticks = 0;
        startSim();
    }

    function step() {
        const rep = 1200, spring = 0.015, center = 0.015, damping = 0.80;
        energy = 0;
        for (let i = 0; i < nodes.length; i++) {
            for (let j = i + 1; j < nodes.length; j++) {
                let dx = nodes[i].x - nodes[j].x, dy = nodes[i].y - nodes[j].y;
                let d2 = dx * dx + dy * dy + 0.01;
                let d = Math.sqrt(d2);
                let minD = (nodes[i].r + nodes[j].r) * 1.2;
                let f = d < minD ? (rep / (minD * minD)) * 3 : (rep / d2);
                f = Math.min(f, 4);
                let fx = f * dx / d, fy = f * dy / d;
                nodes[i].vx += fx; nodes[i].vy += fy;
                nodes[j].vx -= fx; nodes[j].vy -= fy;
            }
        }
        edges.forEach(e => {
            const a = nodes[e.a], b = nodes[e.b];
            const target = e.type === 'wikilink' ? 100 : 140;
            let dx = b.x - a.x, dy = b.y - a.y;
            let d = Math.sqrt(dx * dx + dy * dy) + 0.01;
            let f = (d - target) * spring;
            f = Math.max(-2, Math.min(2, f));
            let fx = f * dx / d, fy = f * dy / d;
            a.vx += fx; a.vy += fy; b.vx -= fx; b.vy -= fy;
        });
        nodes.forEach(node => {
            if (node.fixed) { node.vx = 0; node.vy = 0; return; }
            node.vx += (0 - node.x) * center;
            node.vy += (0 - node.y) * center;
            node.vx *= damping; node.vy *= damping;
            node.x += node.vx; node.y += node.vy;
            energy += node.vx * node.vx + node.vy * node.vy;
        });
    }

    function draw() {
        ctx.clearRect(0, 0, W, H);
        const nb = new Set();
        if (hoverNode != null) {
            nb.add(hoverNode);
            edges.forEach(e => { if (e.a === hoverNode || e.b === hoverNode) { nb.add(e.a); nb.add(e.b); } });
        }
        edges.forEach(e => {
            const a = w2s(nodes[e.a].x, nodes[e.a].y);
            const b = w2s(nodes[e.b].x, nodes[e.b].y);
            const dim = hoverNode != null && !(e.a === hoverNode || e.b === hoverNode);
            if (e.type === 'wikilink') {
                ctx.strokeStyle = dim ? 'rgba(168,85,247,0.12)' : 'rgba(168,85,247,0.60)';
                ctx.setLineDash([5, 4]);
            } else {
                ctx.strokeStyle = dim ? 'rgba(130,130,150,0.08)' : 'rgba(130,130,155,0.28)';
                ctx.setLineDash([]);
            }
            ctx.lineWidth = e.type === 'wikilink' ? 1.6 : 1;
            ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
            ctx.setLineDash([]);
            if (e.label && e.type === 'wikilink') {
                const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
                ctx.font = '10px sans-serif'; ctx.textAlign = 'center';
                ctx.fillStyle = dim ? 'rgba(168,85,247,0.3)' : 'rgba(168,85,247,0.9)';
                ctx.fillText(e.label, mx, my - 2);
            }
        });
        nodes.forEach((node, i) => {
            const p = w2s(node.x, node.y);
            const m = metaOf(node);
            const dim = hoverNode != null && !nb.has(i);
            ctx.beginPath();
            ctx.arc(p.x, p.y, node.r * cam.scale, 0, Math.PI * 2);
            ctx.fillStyle = dim ? m.color + '55' : m.color;
            ctx.fill();
            ctx.lineWidth = 1.5;
            ctx.strokeStyle = 'rgba(255,255,255,0.7)';
            ctx.stroke();
            if (node.r * cam.scale > 5) {
                ctx.fillStyle = dim ? 'rgba(120,120,130,0.5)' : LABEL;
                ctx.font = '11px sans-serif'; ctx.textAlign = 'center';
                const label = node.title.length > 14 ? node.title.slice(0, 13) + '…' : node.title;
                ctx.fillText(label, p.x, p.y - node.r * cam.scale - 4);
            }
        });
    }

    function loop() {
        step(); draw(); ticks++;
        if (ticks < 350 && energy > 0.5) { raf = requestAnimationFrame(loop); }
        else { raf = 0; fitToView(); }
    }
    function startSim() { if (raf) cancelAnimationFrame(raf); raf = requestAnimationFrame(loop); }

    function fitToView() {
        if (!nodes.length) return;
        let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        nodes.forEach(n => {
            const rr = n.r;
            minX = Math.min(minX, n.x - rr); minY = Math.min(minY, n.y - rr);
            maxX = Math.max(maxX, n.x + rr); maxY = Math.max(maxY, n.y + rr);
        });
        const bw = Math.max(1, maxX - minX), bh = Math.max(1, maxY - minY);
        const scaleX = (W * 0.82) / bw, scaleY = (H * 0.82) / bh;
        cam.scale = Math.max(0.12, Math.min(2.5, Math.min(scaleX, scaleY)));
        cam.x = (minX + maxX) / 2;
        cam.y = (minY + maxY) / 2;
        draw();
    }
    document.getElementById('fit-btn').addEventListener('click', fitToView);

    let hoverNode = null, dragNode = null, panning = false;
    let last = { x: 0, y: 0 }, down = { x: 0, y: 0 }, moved = false;

    function hit(mx, my) {
        for (let i = nodes.length - 1; i >= 0; i--) {
            const p = w2s(nodes[i].x, nodes[i].y);
            const rr = nodes[i].r * cam.scale + 4;
            if ((mx - p.x) ** 2 + (my - p.y) ** 2 <= rr * rr) return i;
        }
        return null;
    }

    canvas.addEventListener('mousedown', e => {
        const r = canvas.getBoundingClientRect();
        const mx = e.clientX - r.left, my = e.clientY - r.top;
        down = { x: mx, y: my }; moved = false; last = { x: mx, y: my };
        const h = hit(mx, my);
        if (h != null) { dragNode = h; nodes[h].fixed = true; } else { panning = true; }
    });
    window.addEventListener('mousemove', e => {
        const r = canvas.getBoundingClientRect();
        const mx = e.clientX - r.left, my = e.clientY - r.top;
        if (Math.abs(mx - down.x) + Math.abs(my - down.y) > 4) moved = true;
        if (dragNode != null) {
            const w = s2w(mx, my);
            nodes[dragNode].x = w.x; nodes[dragNode].y = w.y;
            nodes[dragNode].vx = 0; nodes[dragNode].vy = 0;
            startSim();
        } else if (panning) {
            cam.x -= (mx - last.x) / cam.scale; cam.y -= (my - last.y) / cam.scale;
            last = { x: mx, y: my }; draw();
        } else {
            const h = hit(mx, my);
            if (h !== hoverNode) { hoverNode = h; canvas.style.cursor = h != null ? 'pointer' : 'grab'; draw(); }
        }
    });
    window.addEventListener('mouseup', e => {
        if (dragNode != null) {
            if (!moved) showDetail(dragNode);
            nodes[dragNode].fixed = false;
            dragNode = null;
        } else if (panning && !moved) {
            detail.classList.add('hidden');
        }
        panning = false;
    });
    canvas.addEventListener('wheel', e => {
        e.preventDefault();
        const r = canvas.getBoundingClientRect();
        const mx = e.clientX - r.left, my = e.clientY - r.top;
        const before = s2w(mx, my);
        const factor = e.deltaY < 0 ? 1.12 : 1 / 1.12;
        cam.scale = Math.max(0.1, Math.min(4, cam.scale * factor));
        const after = s2w(mx, my);
        cam.x += before.x - after.x; cam.y += before.y - after.y;
        draw();
    }, { passive: false });

    function showDetail(i) {
        const n = nodes[i];
        const m = metaOf(n);
        document.getElementById('d-icon').textContent = m.icon;
        document.getElementById('d-name').textContent = n.title;
        const t = document.getElementById('d-type');
        t.textContent = m.label;
        t.style.backgroundColor = m.color + '22';
        t.style.color = m.color;
        document.getElementById('d-preview').textContent = n.preview && n.preview.trim() !== ''
            ? n.preview : '（暂无内容预览）';

        const jump = document.getElementById('d-jump');
        jump.innerHTML = '';
        if (n.type === 'book' && n.book_id) {
            const a = document.createElement('a');
            a.href = '/read/' + n.book_id;
            a.className = 'inline-block rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700';
            a.textContent = '📖 打开这本书';
            jump.appendChild(a);
        } else if (n.source_path) {
            const span = document.createElement('div');
            span.className = 'text-xs text-zinc-500 dark:text-zinc-400 break-all';
            span.textContent = '📁 文件：' + n.source_path;
            jump.appendChild(span);
        }

        const related = document.getElementById('d-related');
        related.innerHTML = '';
        const rels = edges.filter(e => e.a === i || e.b === i);
        if (!rels.length) {
            related.innerHTML = '<span class="text-xs text-zinc-400">无关联</span>';
        }
        rels.forEach(e => {
            const other = e.a === i ? nodes[e.b] : nodes[e.a];
            const tag = document.createElement('button');
            tag.className = 'rounded-full border border-zinc-200 px-2 py-0.5 text-[11px] text-zinc-600 dark:border-zinc-700 dark:text-zinc-300 hover:border-primary-400';
            const verb = e.type === 'wikilink' ? '双链→' : '相关↔';
            tag.textContent = verb + ' ' + (other.title.length > 12 ? other.title.slice(0, 11) + '…' : other.title);
            tag.addEventListener('click', () => showDetail(e.a === i ? e.b : e.a));
            related.appendChild(tag);
        });
        detail.classList.remove('hidden');
    }

    function applyStats(s) {
        if (!s) return;
        document.getElementById('stat-books').textContent = s.books || 0;
        document.getElementById('stat-notes').textContent = s.notes || 0;
        document.getElementById('stat-wikilinks').textContent = s.wikilinks || 0;
        document.getElementById('stat-related').textContent = s.related || 0;
    }

    async function load() {
        try {
            const resp = await fetch('/api/knowledge', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const d = await resp.json();
            if (d.ok && d.graph && d.graph.nodes && d.graph.nodes.length) {
                data = d.graph;
                allNodes = d.graph.nodes;
                allEdges = d.graph.edges;
                applyStats(data.stats);
                let msg = '✅ 知识库图谱已生成（书 ' + data.stats.books + ' · 笔记 ' + data.stats.notes
                    + ' · 双链 ' + data.stats.wikilinks + ' · 关联 ' + data.stats.related + '）。点卡片看详情与跳转。';
                statusEl.textContent = msg;
                resize(); init();
            } else {
                statusEl.textContent = '还没有知识库图谱，点右上角「生成 / 重新生成」开始（建议先在「🔌 来源」配好 Obsidian vault 并重建索引）。';
            }
        } catch (e) {
            statusEl.textContent = '⚠️ 加载失败：' + e.message;
        }
    }

    document.getElementById('gen-btn').addEventListener('click', async () => {
        const btn = document.getElementById('gen-btn');
        btn.disabled = true;
        statusEl.textContent = '正在聚合书与笔记、计算双链与共现关联（数秒）…';
        try {
            const token = document.querySelector('meta[name=csrf-token]').content;
            const resp = await fetch('/api/knowledge', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const d = await resp.json();
            if (d.ok && d.graph) {
                data = d.graph;
                allNodes = d.graph.nodes;
                allEdges = d.graph.edges;
                applyStats(data.stats);
                statusEl.textContent = '✅ 生成完成（书 ' + data.stats.books + ' · 笔记 ' + data.stats.notes
                    + ' · 双链 ' + data.stats.wikilinks + ' · 关联 ' + data.stats.related + '）。';
                resize(); init();
            } else {
                statusEl.textContent = '⚠️ ' + (d.msg || '生成失败');
                btn.disabled = false;
            }
        } catch (e) {
            statusEl.textContent = '⚠️ 请求出错：' + e.message;
            btn.disabled = false;
        }
    });

    resize();
    load();

    window.__kgResize = function () { resize(); if (nodes.length) { draw(); } };
})();
</script>
