    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">{{ $book->title }}</h1>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">从全书抽取「概念—关系」三元组，力导向交互图。拖拽节点、滚轮缩放、点节点看定义与出处。</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('book.analyze', [$book, 'tab' => 'mindmap']) }}"
                       class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">📊 脑图</a>
                    <a href="{{ route('read', $book) }}"
                       class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">← 返回阅读</a>
                    <button id="gen-btn"
                            class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">🤖 生成 / 重新生成</button>
                </div>
            </div>

            <div id="status" class="mb-3 text-sm text-zinc-500 dark:text-zinc-400"></div>

            <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_300px] gap-4">
                <div class="relative rounded-2xl border border-zinc-200 bg-white/80 dark:border-zinc-800 dark:bg-zinc-900/80 backdrop-blur overflow-hidden">
                    <canvas id="graph-canvas" class="w-full h-[68vh] block touch-none"></canvas>
                    <div id="hint" class="absolute bottom-2 left-3 text-[11px] text-zinc-400 pointer-events-none">滚轮缩放 · 拖背景平移 · 拖节点排版 · 点节点看详情</div>
                </div>

                <aside id="detail" class="rounded-2xl border border-zinc-200 bg-white/80 dark:border-zinc-800 dark:bg-zinc-900/80 backdrop-blur p-4 text-sm hidden">
                    <div class="text-base font-semibold text-zinc-800 dark:text-zinc-100 mb-1" id="d-label"></div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">出现频次：<span id="d-count"></span></div>
                    <div class="text-sm text-zinc-700 dark:text-zinc-300 leading-relaxed mb-3" id="d-def"></div>
                    <div class="mb-3">
                        <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">📖 原文摘录</div>
                        <div id="d-quotes" class="space-y-2 max-h-48 overflow-y-auto"></div>
                    </div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">出处章节：<span id="d-chapters"></span></div>
                </aside>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const BOOK_ID = {{ $book->id }};
        const canvas = document.getElementById('graph-canvas');
        const ctx = canvas.getContext('2d');
        const statusEl = document.getElementById('status');
        const detail = document.getElementById('detail');

        const PALETTE = ['#6366f1','#ec4899','#14b8a6','#f59e0b','#8b5cf6','#10b981','#ef4444','#3b82f6','#f97316','#06b6d4'];
        const DPR = window.devicePixelRatio || 1;

        let W = 0, H = 0;
        let graph = { nodes: [], edges: [] };
        let nodes = [], edges = [];
        const cam = { x: 0, y: 0, scale: 1 };
        let raf = 0, ticks = 0, energy = 0;

        function resize() {
            const r = canvas.getBoundingClientRect();
            W = r.width; H = r.height;
            canvas.width = Math.max(1, Math.floor(W * DPR));
            canvas.height = Math.max(1, Math.floor(H * DPR));
            ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
        }
        window.addEventListener('resize', () => { resize(); draw(); });

        function colorFor(n) {
            let h = 0;
            for (let i = 0; i < n.label.length; i++) h = (h * 31 + n.label.charCodeAt(i)) >>> 0;
            return PALETTE[h % PALETTE.length];
        }

        function w2s(x, y) {
            return { x: (x - cam.x) * cam.scale + W / 2, y: (y - cam.y) * cam.scale + H / 2 };
        }
        function s2w(x, y) {
            return { x: (x - W / 2) / cam.scale + cam.x, y: (y - H / 2) / cam.scale + cam.y };
        }

        function init() {
            const idx = {};
            nodes = graph.nodes.map((n, i) => {
                idx[n.id] = i;
                const r = 6 + Math.sqrt(n.count) * 3.2;
                const a = (i / Math.max(1, graph.nodes.length)) * Math.PI * 2;
                return {
                    ...n, r, color: colorFor(n),
                    x: Math.cos(a) * Math.min(W, H) * 0.32,
                    y: Math.sin(a) * Math.min(W, H) * 0.32,
                    vx: 0, vy: 0, fixed: false,
                };
            });
            edges = graph.edges
                .filter(e => idx[e.from] != null && idx[e.to] != null)
                .map(e => ({ a: idx[e.from], b: idx[e.to], label: e.label || '' }));
            cam.x = 0; cam.y = 0; cam.scale = 1;
            ticks = 0;
            startSim();
        }

        function step() {
            const rep = 1600, spring = 0.012, center = 0.006;
            energy = 0;
            for (let i = 0; i < nodes.length; i++) {
                for (let j = i + 1; j < nodes.length; j++) {
                    let dx = nodes[i].x - nodes[j].x, dy = nodes[i].y - nodes[j].y;
                    let d2 = dx * dx + dy * dy + 0.01, d = Math.sqrt(d2);
                    let f = rep / d2, fx = f * dx / d, fy = f * dy / d;
                    nodes[i].vx += fx; nodes[i].vy += fy;
                    nodes[j].vx -= fx; nodes[j].vy -= fy;
                }
            }
            edges.forEach(e => {
                const a = nodes[e.a], b = nodes[e.b];
                let dx = b.x - a.x, dy = b.y - a.y;
                let d = Math.sqrt(dx * dx + dy * dy) + 0.01;
                let f = (d - 90) * spring;
                let fx = f * dx / d, fy = f * dy / d;
                a.vx += fx; a.vy += fy; b.vx -= fx; b.vy -= fy;
            });
            nodes.forEach(n => {
                if (n.fixed) { n.vx = 0; n.vy = 0; return; }
                n.vx += (0 - n.x) * center;
                n.vy += (0 - n.y) * center;
                n.vx *= 0.82; n.vy *= 0.82;
                n.x += n.vx; n.y += n.vy;
                energy += n.vx * n.vx + n.vy * n.vy;
            });
        }

        function draw() {
            ctx.clearRect(0, 0, W, H);
            const nb = new Set();
            if (hoverNode != null) {
                nb.add(hoverNode);
                edges.forEach(e => {
                    if (e.a === hoverNode || e.b === hoverNode) { nb.add(e.a); nb.add(e.b); }
                });
            }
            edges.forEach(e => {
                const a = w2s(nodes[e.a].x, nodes[e.a].y);
                const b = w2s(nodes[e.b].x, nodes[e.b].y);
                const dim = hoverNode != null && !(e.a === hoverNode || e.b === hoverNode);
                ctx.strokeStyle = dim ? 'rgba(130,130,150,0.08)' : 'rgba(130,130,155,0.30)';
                ctx.lineWidth = 1;
                ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
            });
            nodes.forEach((n, i) => {
                const p = w2s(n.x, n.y);
                const dim = hoverNode != null && !nb.has(i);
                ctx.beginPath();
                ctx.arc(p.x, p.y, n.r * cam.scale, 0, Math.PI * 2);
                ctx.fillStyle = dim ? n.color + '55' : n.color;
                ctx.fill();
                ctx.lineWidth = 1.5;
                ctx.strokeStyle = 'rgba(255,255,255,0.7)';
                ctx.stroke();
                if (n.r * cam.scale > 7) {
                    ctx.fillStyle = dim ? 'rgba(120,120,130,0.5)' : '#1f2937';
                    ctx.font = (Math.max(10, 11) ) + 'px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.fillText(n.label, p.x, p.y - n.r * cam.scale - 4);
                }
            });
        }

        function loop() {
            step();
            draw();
            ticks++;
            if (ticks < 380 && energy > 0.6) {
                raf = requestAnimationFrame(loop);
            } else {
                raf = 0;
            }
        }
        function startSim() {
            if (raf) cancelAnimationFrame(raf);
            raf = requestAnimationFrame(loop);
        }

        // ---- interaction ----
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
            if (h != null) { dragNode = h; nodes[h].fixed = true; }
            else { panning = true; }
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
                cam.x -= (mx - last.x) / cam.scale;
                cam.y -= (my - last.y) / cam.scale;
                last = { x: mx, y: my };
                draw();
            } else {
                const h = hit(mx, my);
                if (h !== hoverNode) { hoverNode = h; canvas.style.cursor = h != null ? 'pointer' : 'grab'; draw(); }
            }
        });
        window.addEventListener('mouseup', e => {
            const r = canvas.getBoundingClientRect();
            const mx = e.clientX - r.left, my = e.clientY - r.top;
            if (dragNode != null) {
                if (!moved) showDetail(dragNode);
                dragNode = null;
            } else if (panning && !moved) {
                // background click: hide detail
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
            cam.scale = Math.max(0.2, Math.min(4, cam.scale * factor));
            const after = s2w(mx, my);
            cam.x += before.x - after.x;
            cam.y += before.y - after.y;
            draw();
        }, { passive: false });

        function showDetail(i) {
            const n = nodes[i];
            document.getElementById('d-label').textContent = n.label;
            document.getElementById('d-count').textContent = n.count;
            document.getElementById('d-def').textContent = n.def && n.def.trim() !== ''
                ? n.def : '（暂无定义，可从原文摘录中理解该概念）';

            const qWrap = document.getElementById('d-quotes');
            qWrap.innerHTML = '';
            if (n.quotes && n.quotes.length) {
                n.quotes.forEach(q => {
                    const div = document.createElement('div');
                    div.className = 'rounded-lg border-l-2 border-primary-400 bg-primary-50/50 dark:bg-primary-900/20 pl-2.5 py-1 text-xs text-zinc-600 dark:text-zinc-300';
                    div.textContent = q + '。';
                    qWrap.appendChild(div);
                });
            } else {
                qWrap.innerHTML = '<div class="text-xs text-zinc-400">（未找到原文摘录）</div>';
            }

            document.getElementById('d-chapters').textContent =
                (n.chapters && n.chapters.length) ? '第 ' + n.chapters.join('、') + ' 章' : '—';
            detail.classList.remove('hidden');
        }

        // ---- load ----
        async function load() {
            try {
                const resp = await fetch('/api/book/' + BOOK_ID + '/concept-graph', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                const data = await resp.json();
                if (data.ok && data.graph && data.graph.nodes && data.graph.nodes.length) {
                    graph = data.graph;
                    statusEl.textContent = '✅ 图谱已生成（节点 ' + graph.nodes.length + ' · 关系 ' + graph.edges.length + '）。点节点查看详情。';
                    resize(); init();
                } else {
                    statusEl.textContent = '本书还没有概念图谱，点右上角「生成 / 重新生成」开始（首次可能需要一些时间）。';
                }
            } catch (e) {
                statusEl.textContent = '⚠️ 加载失败：' + e.message;
            }
        }

        document.getElementById('gen-btn').addEventListener('click', async () => {
            const btn = document.getElementById('gen-btn');
            btn.disabled = true;
            statusEl.textContent = '正在抽取概念与关系（首次可能需数十秒～数分钟，请稍候）…';
            try {
                const token = document.querySelector('meta[name=csrf-token]').content;
                const resp = await fetch('/api/book/' + BOOK_ID + '/concept-graph', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                const data = await resp.json();
                if (data.ok && data.graph) {
                    graph = data.graph;
                    statusEl.textContent = '✅ 生成完成（节点 ' + graph.nodes.length + ' · 关系 ' + graph.edges.length + '）。';
                    resize(); init();
                } else {
                    statusEl.textContent = '⚠️ ' + (data.msg || '生成失败');
                    btn.disabled = false;
                }
            } catch (e) {
                statusEl.textContent = '⚠️ 请求出错：' + e.message;
                btn.disabled = false;
            }
        });

        resize();
        load();
    })();
    </script>
