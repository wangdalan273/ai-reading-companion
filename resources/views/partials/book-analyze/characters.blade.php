    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">{{ $book->title }}</h1>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">从全书抽取人物、关系与关键事件，力导向交互图 + 时间线。拖拽节点、滚轮缩放、点人物看关系、点事件看剧情。</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('book.analyze', [$book, 'tab' => 'mindmap']) }}"
                       class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">📊 脑图</a>
                    <a href="{{ route('book.analyze', [$book, 'tab' => 'graph']) }}"
                       class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">🕸 图谱</a>
                    <a href="{{ route('read', $book) }}"
                       class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">← 返回阅读</a>
                    <button id="gen-btn"
                            class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">🤖 生成 / 重新生成</button>
                </div>
            </div>

            <div id="status" class="mb-3 text-sm text-zinc-500 dark:text-zinc-400"></div>

            <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_300px] gap-4">
                <div class="min-w-0">
                    <div class="relative rounded-2xl border border-zinc-200 bg-white/80 dark:border-zinc-800 dark:bg-zinc-900/80 backdrop-blur overflow-hidden">
                        <canvas id="char-canvas" class="w-full h-[54vh] block touch-none"></canvas>
                        <div id="hint" class="absolute bottom-2 left-3 text-[11px] text-zinc-400 pointer-events-none">滚轮缩放 · 拖背景平移 · 拖节点排版 · 点人物看关系</div>
                        <div id="event-card" class="absolute top-3 right-3 z-10 hidden max-w-[260px] rounded-xl border border-zinc-200 bg-white/95 p-3 text-sm shadow-xl backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95">
                            <div class="flex items-start justify-between gap-2">
                                <div id="ev-time" class="text-xs font-medium text-primary-600"></div>
                                <button id="ev-close" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">✕</button>
                            </div>
                            <div id="ev-desc" class="mt-1 text-sm text-zinc-700 dark:text-zinc-200 leading-relaxed"></div>
                            <div id="ev-chars" class="mt-2 text-xs text-zinc-500 dark:text-zinc-400"></div>
                        </div>
                    </div>

                    <div class="mt-3 rounded-2xl border border-zinc-200 bg-white/80 dark:border-zinc-800 dark:bg-zinc-900/80 backdrop-blur p-3">
                        <div class="mb-2 flex items-center gap-2 text-xs font-medium text-zinc-600 dark:text-zinc-300">
                            <span>📜 时间线</span>
                            <span class="text-zinc-400">（点事件看剧情，可横向滚动）</span>
                        </div>
                        <div id="timeline" class="flex gap-2 overflow-x-auto pb-1 text-xs"></div>
                    </div>
                </div>

                <aside id="detail" class="rounded-2xl border border-zinc-200 bg-white/80 dark:border-zinc-800 dark:bg-zinc-900/80 backdrop-blur p-4 text-sm hidden">
                    <div class="text-base font-semibold text-zinc-800 dark:text-zinc-100 mb-1" id="d-name"></div>
                    <div class="mb-2 text-xs"><span class="inline-block rounded-full px-2 py-0.5" id="d-faction"></span></div>
                    <div class="text-sm text-zinc-700 dark:text-zinc-300 leading-relaxed mb-3" id="d-desc"></div>
                    <div class="mb-3">
                        <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">📖 原文摘录</div>
                        <div id="d-quotes" class="space-y-2 max-h-48 overflow-y-auto"></div>
                    </div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mb-1">出场章节：<span id="d-chapters"></span></div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">关联人物：</div>
                    <div id="d-related" class="mt-1 flex flex-wrap gap-1"></div>
                </aside>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const BOOK_ID = {{ $book->id }};
        const canvas = document.getElementById('char-canvas');
        const ctx = canvas.getContext('2d');
        const statusEl = document.getElementById('status');
        const detail = document.getElementById('detail');
        const timeline = document.getElementById('timeline');
        const eventCard = document.getElementById('event-card');

        const PALETTE = ['#6366f1','#ec4899','#14b8a6','#f59e0b','#8b5cf6','#10b981','#ef4444','#3b82f6','#f97316','#06b6d4','#a855f7','#22c55e'];
        const DPR = window.devicePixelRatio || 1;
        const isDark = document.documentElement.classList.contains('dark');
        const LABEL = isDark ? '#e5e7eb' : '#1f2937';

        let W = 0, H = 0;
        let data = { genre: 'unknown', characters: [], relations: [], events: [] };
        let nodes = [], edges = [];
        const cam = { x: 0, y: 0, scale: 1 };
        let raf = 0, ticks = 0, energy = 0;
        const factionColor = {};

        function factionKey(f) { return (f && f.trim() !== '') ? f.trim() : '未分阵营'; }
        function colorForFaction(f) {
            const k = factionKey(f);
            if (factionColor[k]) return factionColor[k];
            let h = 0;
            for (let i = 0; i < k.length; i++) h = (h * 31 + k.charCodeAt(i)) >>> 0;
            return factionColor[k] = PALETTE[h % PALETTE.length];
        }

        function resize() {
            const r = canvas.getBoundingClientRect();
            W = r.width; H = r.height;
            canvas.width = Math.max(1, Math.floor(W * DPR));
            canvas.height = Math.max(1, Math.floor(H * DPR));
            ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
        }
        window.addEventListener('resize', () => { resize(); draw(); });

        function w2s(x, y) {
            return { x: (x - cam.x) * cam.scale + W / 2, y: (y - cam.y) * cam.scale + H / 2 };
        }
        function s2w(x, y) {
            return { x: (x - W / 2) / cam.scale + cam.x, y: (y - H / 2) / cam.scale + cam.y };
        }

        function init() {
            const idx = {};
            nodes = data.characters.map((c, i) => {
                idx[c.name] = i;
                const r = 7 + Math.sqrt(c.count || 1) * 2.6;
                const a = (i / Math.max(1, data.characters.length)) * Math.PI * 2;
                return {
                    ...c, r, color: colorForFaction(c.faction),
                    x: Math.cos(a) * Math.min(W, H) * 0.30,
                    y: Math.sin(a) * Math.min(W, H) * 0.30,
                    vx: 0, vy: 0, fixed: false,
                };
            });
            edges = data.relations
                .filter(e => idx[e.from] != null && idx[e.to] != null)
                .map(e => ({ a: idx[e.from], b: idx[e.to], type: e.type || '', label: e.type || '' }));
            cam.x = 0; cam.y = 0; cam.scale = 1;
            ticks = 0;
            startSim();
        }

        function step() {
            const rep = 1700, spring = 0.012, center = 0.006;
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
                let f = (d - 95) * spring;
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
                ctx.strokeStyle = dim ? 'rgba(130,130,150,0.07)' : 'rgba(130,130,155,0.32)';
                ctx.lineWidth = 1;
                ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
                if (e.label && e.label !== '共现') {
                    const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
                    ctx.font = '10px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.fillStyle = dim ? 'rgba(120,120,130,0.35)' : 'rgba(100,100,120,0.85)';
                    ctx.fillText(e.label, mx, my - 2);
                }
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
                if (n.r * cam.scale > 6) {
                    ctx.fillStyle = dim ? 'rgba(120,120,130,0.5)' : LABEL;
                    ctx.font = '11px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.fillText(n.name, p.x, p.y - n.r * cam.scale - 4);
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
            if (dragNode != null) {
                if (!moved) showDetail(dragNode);
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
            cam.scale = Math.max(0.2, Math.min(4, cam.scale * factor));
            const after = s2w(mx, my);
            cam.x += before.x - after.x;
            cam.y += before.y - after.y;
            draw();
        }, { passive: false });

        function showDetail(i) {
            const n = nodes[i];
            document.getElementById('d-name').textContent = n.name;
            const fac = document.getElementById('d-faction');
            const fk = factionKey(n.faction);
            fac.textContent = fk;
            fac.style.backgroundColor = colorForFaction(n.faction) + '22';
            fac.style.color = colorForFaction(n.faction);
            document.getElementById('d-desc').textContent = n.desc && n.desc.trim() !== ''
                ? n.desc : '（暂无人物介绍，可从原文摘录中理解）';

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
            const related = document.getElementById('d-related');
            related.innerHTML = '';
            const rels = edges.filter(e => e.a === i || e.b === i);
            if (!rels.length) {
                related.innerHTML = '<span class="text-xs text-zinc-400">无显式关系</span>';
            }
            rels.forEach(e => {
                const other = e.a === i ? nodes[e.b] : nodes[e.a];
                const tag = document.createElement('span');
                tag.className = 'rounded-full border border-zinc-200 px-2 py-0.5 text-[11px] text-zinc-600 dark:border-zinc-700 dark:text-zinc-300';
                tag.textContent = (e.a === i ? '' : '↔ ') + other.name + ' · ' + (e.type || '关联');
                related.appendChild(tag);
            });
            detail.classList.remove('hidden');
        }

        function renderTimeline() {
            timeline.innerHTML = '';
            if (!data.events || !data.events.length) {
                const tip = document.createElement('div');
                tip.className = 'text-zinc-400 dark:text-zinc-500';
                tip.textContent = '（离线模式无时间线；在「AI 设置」填入密钥后由真实模型生成关键事件）';
                timeline.appendChild(tip);
                return;
            }
            data.events.forEach(ev => {
                const chip = document.createElement('button');
                chip.className = 'shrink-0 rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1.5 text-left hover:border-primary-400 dark:border-zinc-700 dark:bg-zinc-800/60';
                chip.innerHTML = '<div class="font-medium text-zinc-700 dark:text-zinc-200">' + (ev.time || '—') + '</div>'
                    + '<div class="text-zinc-500 dark:text-zinc-400 line-clamp-1 max-w-[160px]">' + (ev.desc || '') + '</div>';
                chip.addEventListener('click', () => showEvent(ev));
                timeline.appendChild(chip);
            });
        }

        function showEvent(ev) {
            document.getElementById('ev-time').textContent = ev.time || '—';
            document.getElementById('ev-desc').textContent = ev.desc || '';
            const chars = (ev.characters && ev.characters.length) ? ev.characters.join('、') : '—';
            document.getElementById('ev-chars').textContent = '涉及人物：' + chars;
            eventCard.classList.remove('hidden');
        }
        document.getElementById('ev-close').addEventListener('click', () => eventCard.classList.add('hidden'));

        // ---- load ----
        async function load() {
            try {
                const resp = await fetch('/api/book/' + BOOK_ID + '/characters', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                const d = await resp.json();
                if (d.ok && d.graph && d.graph.characters && d.graph.characters.length) {
                    data = d.graph;
                    let msg = '✅ 人物关系已生成（人物 ' + data.characters.length
                        + ' · 关系 ' + data.relations.length
                        + ' · 事件 ' + (data.events ? data.events.length : 0) + '）。点人物查看关系。';
                    if (data.genre === 'nonfiction') {
                        msg += ' 本书疑似非虚构，人物关系可能不完整。';
                    }
                    statusEl.textContent = msg;
                    resize(); init(); renderTimeline();
                } else {
                    statusEl.textContent = '本书还没有人物关系图，点右上角「生成 / 重新生成」开始（小说类效果最佳，首次可能需要一些时间）。';
                }
            } catch (e) {
                statusEl.textContent = '⚠️ 加载失败：' + e.message;
            }
        }

        document.getElementById('gen-btn').addEventListener('click', async () => {
            const btn = document.getElementById('gen-btn');
            btn.disabled = true;
            statusEl.innerHTML = '⏳ 正在逐章抽取人物、关系与事件（取前 8 章，约 30-90 秒）。<br><b>生成期间整站会短暂无响应，请勿打开其他页面或刷新。</b>';
            // 单进程 dev server 下生成会阻塞；前端设超时兜底，避免无限等待
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 150000);
            try {
                const token = document.querySelector('meta[name=csrf-token]').content;
                const resp = await fetch('/api/book/' + BOOK_ID + '/characters', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    signal: controller.signal,
                });
                clearTimeout(timer);
                const d = await resp.json();
                if (d.ok && d.graph) {
                    data = d.graph;
                    let msg = '✅ 生成完成（人物 ' + data.characters.length + ' · 关系 ' + data.relations.length + '）。';
                    if (data.genre === 'nonfiction') msg += ' 本书疑似非虚构，人物关系可能不完整。';
                    if (d.msg) msg += ' ' + d.msg;
                    statusEl.textContent = msg;
                    resize(); init(); renderTimeline();
                } else {
                    statusEl.textContent = '⚠️ ' + (d.msg || '生成失败');
                    btn.disabled = false;
                }
            } catch (e) {
                clearTimeout(timer);
                statusEl.textContent = e.name === 'AbortError'
                    ? '⚠️ 生成超时（超过 150 秒）。可能是模型响应慢或额度受限，请稍后重试，或先在「AI 设置」确认密钥可用。'
                    : '⚠️ 请求出错：' + e.message;
                btn.disabled = false;
            }
        });

        resize();
        load();
    })();
    </script>
