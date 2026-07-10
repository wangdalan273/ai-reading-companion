    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">{{ $book->title }}</h1>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">从全书抽取「主张—证据—反驳」论证骨架，培养批判性阅读。点主张看完整论述与批判性质询，点证据/反驳看它与哪条主张相连。</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('book.analyze', [$book, 'tab' => 'mindmap']) }}"
                       class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">📊 脑图</a>
                    <a href="{{ route('book.analyze', [$book, 'tab' => 'graph']) }}"
                       class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">🕸 图谱</a>
                    <a href="{{ route('book.analyze', [$book, 'tab' => 'characters']) }}"
                       class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">👥 人物</a>
                    <a href="{{ route('read', $book) }}"
                       class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">← 返回阅读</a>
                    <button id="gen-btn"
                            class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">🤖 生成 / 重新生成</button>
                </div>
            </div>

            <div id="status" class="mb-3 text-sm text-zinc-500 dark:text-zinc-400"></div>

            <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] gap-4">
                <div class="min-w-0">
                    <div class="relative rounded-2xl border border-zinc-200 bg-white/80 dark:border-zinc-800 dark:bg-zinc-900/80 backdrop-blur overflow-hidden">
                        <canvas id="arg-canvas" class="w-full h-[54vh] block touch-none"></canvas>
                        <div class="absolute top-3 left-3 flex flex-wrap gap-2 text-[11px] pointer-events-none">
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-400/15 px-2 py-0.5 text-amber-600 dark:text-amber-300"><span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span>主张</span>
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-400/15 px-2 py-0.5 text-emerald-600 dark:text-emerald-300"><span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>证据</span>
                            <span class="inline-flex items-center gap-1 rounded-full bg-rose-400/15 px-2 py-0.5 text-rose-600 dark:text-rose-300"><span class="inline-block h-2 w-2 rotate-45 bg-rose-500"></span>反驳</span>
                        </div>
                        <div id="hint" class="absolute bottom-2 left-3 text-[11px] text-zinc-400 pointer-events-none">滚轮缩放 · 拖背景平移 · 拖节点排版 · 点主张看论述</div>
                    </div>
                    <div class="mt-3 rounded-2xl border border-zinc-200 bg-white/80 dark:border-zinc-800 dark:bg-zinc-900/80 backdrop-blur p-3 flex flex-wrap items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400">
                        <span>📊 主张 <b id="stat-claims" class="text-zinc-700 dark:text-zinc-200">0</b></span>
                        <span>✅ 证据 <b id="stat-ev" class="text-zinc-700 dark:text-zinc-200">0</b></span>
                        <span>⚔ 反驳 <b id="stat-co" class="text-zinc-700 dark:text-zinc-200">0</b></span>
                        <span id="stat-genre" class="ml-auto"></span>
                    </div>
                </div>

                <aside class="rounded-2xl border border-zinc-200 bg-white/80 dark:border-zinc-800 dark:bg-zinc-900/80 backdrop-blur p-4 text-sm">
                    <div id="focus" class="hidden">
                        <div class="flex items-center gap-2 mb-1">
                            <span id="f-type" class="rounded-full px-2 py-0.5 text-[11px] font-medium"></span>
                            <span id="f-chapter" class="text-[11px] text-zinc-400"></span>
                        </div>
                        <div id="f-text" class="text-sm font-medium text-zinc-800 dark:text-zinc-100 leading-relaxed mb-2"></div>
                        <div class="rounded-xl border border-amber-300/50 bg-amber-50/70 p-2.5 dark:border-amber-500/30 dark:bg-amber-500/10">
                            <div class="text-[11px] font-semibold text-amber-700 dark:text-amber-300 mb-1">🤔 批判性质询</div>
                            <div id="f-challenge" class="text-xs text-amber-800/90 dark:text-amber-200/90 leading-relaxed"></div>
                        </div>
                        <div id="f-links" class="mt-3 space-y-1.5"></div>
                    </div>
                    <div id="focus-empty" class="text-xs text-zinc-400 dark:text-zinc-500">点图中任意「主张」节点，这里显示它的完整论述、批判性质询，以及支撑它的证据与反驳。</div>

                    <div class="mt-4 mb-2 text-xs font-medium text-zinc-600 dark:text-zinc-300">📜 全部主张（点查看）</div>
                    <div id="outline" class="space-y-1.5 max-h-[34vh] overflow-y-auto pr-1"></div>
                </aside>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const BOOK_ID = {{ $book->id }};
        const canvas = document.getElementById('arg-canvas');
        const ctx = canvas.getContext('2d');
        const statusEl = document.getElementById('status');
        const outline = document.getElementById('outline');

        const DPR = window.devicePixelRatio || 1;
        const isDark = document.documentElement.classList.contains('dark');
        const LABEL = isDark ? '#e5e7eb' : '#1f2937';

        const C_CLAIM = '#f59e0b', C_EVID = '#10b981', C_COUNTER = '#ef4444';

        let W = 0, H = 0;
        let data = { genre: 'unknown', claims: [], evidence: [], counter: [] };
        let nodes = [], edges = [];
        const cam = { x: 0, y: 0, scale: 1 };
        let raf = 0, ticks = 0, energy = 0;
        let focusNode = null;

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
            nodes = [];
            const cIdx = {};
            data.claims.forEach((c, i) => {
                cIdx[c.id] = nodes.length;
                const r = 9 + Math.sqrt((c.count || 1)) * 2.4;
                const a = (i / Math.max(1, data.claims.length)) * Math.PI * 2;
                nodes.push({ ...c, kind: 'claim', r, color: C_CLAIM,
                    x: Math.cos(a) * Math.min(W, H) * 0.30, y: Math.sin(a) * Math.min(W, H) * 0.30, vx: 0, vy: 0, fixed: false });
            });
            data.evidence.forEach((e) => {
                const ci = cIdx[e.claim_id];
                if (ci == null) return;
                const idx = nodes.length;
                nodes.push({ ...e, kind: 'ev', r: 5, color: C_EVID,
                    x: nodes[ci].x + (Math.random() - 0.5) * 60, y: nodes[ci].y + (Math.random() - 0.5) * 60, vx: 0, vy: 0, fixed: false });
            });
            data.counter.forEach((k) => {
                const ci = cIdx[k.claim_id];
                if (ci == null) return;
                const idx = nodes.length;
                nodes.push({ ...k, kind: 'co', r: 6, color: C_COUNTER,
                    x: nodes[ci].x + (Math.random() - 0.5) * 60, y: nodes[ci].y + (Math.random() - 0.5) * 60, vx: 0, vy: 0, fixed: false });
            });

            edges = [];
            let evN = data.claims.length;
            data.evidence.forEach((e) => {
                const ci = cIdx[e.claim_id];
                if (ci == null) return;
                edges.push({ a: ci, b: evN, kind: 'ev', label: e.type || '' });
                evN++;
            });
            let coN = data.claims.length + data.evidence.length;
            data.counter.forEach((k) => {
                const ci = cIdx[k.claim_id];
                if (ci == null) return;
                edges.push({ a: ci, b: coN, kind: 'co', label: k.type || '' });
                coN++;
            });

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
                let f = (d - 70) * spring;
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
            if (hoverNode != null || focusNode != null) {
                const root = hoverNode != null ? hoverNode : focusNode;
                nb.add(root);
                edges.forEach(e => {
                    if (e.a === root || e.b === root) { nb.add(e.a); nb.add(e.b); }
                });
            }
            edges.forEach(e => {
                const a = w2s(nodes[e.a].x, nodes[e.a].y);
                const b = w2s(nodes[e.b].x, nodes[e.b].y);
                const dim = (nb.size && !(e.a === (hoverNode ?? focusNode) || e.b === (hoverNode ?? focusNode)));
                ctx.strokeStyle = e.kind === 'ev'
                    ? (dim ? 'rgba(16,185,129,0.07)' : 'rgba(16,185,129,0.55)')
                    : (dim ? 'rgba(239,68,68,0.07)' : 'rgba(239,68,68,0.55)');
                ctx.lineWidth = 1.2;
                ctx.setLineDash(e.kind === 'co' ? [4, 3] : []);
                ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
                ctx.setLineDash([]);
                if (e.label) {
                    const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
                    ctx.font = '10px sans-serif'; ctx.textAlign = 'center';
                    ctx.fillStyle = dim ? 'rgba(120,120,130,0.35)'
                        : (e.kind === 'ev' ? 'rgba(5,150,105,0.9)' : 'rgba(220,38,38,0.9)');
                    ctx.fillText(e.label, mx, my - 2);
                }
            });
            nodes.forEach((n, i) => {
                const p = w2s(n.x, n.y);
                const dim = nb.size && !nb.has(i);
                if (n.kind === 'co') {
                    drawDiamond(p, n.r * cam.scale, dim ? n.color + '55' : n.color);
                } else {
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, n.r * cam.scale, 0, Math.PI * 2);
                    ctx.fillStyle = dim ? n.color + '55' : n.color;
                    ctx.fill();
                    ctx.lineWidth = 1.5; ctx.strokeStyle = 'rgba(255,255,255,0.7)'; ctx.stroke();
                }
                if (n.r * cam.scale > 5 && (n.kind === 'claim')) {
                    ctx.fillStyle = dim ? 'rgba(120,120,130,0.5)' : LABEL;
                    ctx.font = '11px sans-serif'; ctx.textAlign = 'center';
                    const t = (n.text || '').slice(0, 14);
                    ctx.fillText(t + (n.text && n.text.length > 14 ? '…' : ''), p.x, p.y - n.r * cam.scale - 4);
                }
            });
        }

        function drawDiamond(p, r, color) {
            ctx.beginPath();
            ctx.moveTo(p.x, p.y - r); ctx.lineTo(p.x + r, p.y);
            ctx.lineTo(p.x, p.y + r); ctx.lineTo(p.x - r, p.y); ctx.closePath();
            ctx.fillStyle = color; ctx.fill();
            ctx.lineWidth = 1.5; ctx.strokeStyle = 'rgba(255,255,255,0.7)'; ctx.stroke();
        }

        function loop() {
            step(); draw(); ticks++;
            if (ticks < 380 && energy > 0.6) raf = requestAnimationFrame(loop);
            else raf = 0;
        }
        function startSim() { if (raf) cancelAnimationFrame(raf); raf = requestAnimationFrame(loop); }

        // ---- interaction ----
        let hoverNode = null, dragNode = null, panning = false;
        let last = { x: 0, y: 0 }, down = { x: 0, y: 0 }, moved = false;

        function hit(mx, my) {
            for (let i = nodes.length - 1; i >= 0; i--) {
                const p = w2s(nodes[i].x, nodes[i].y);
                const rr = nodes[i].r * cam.scale + 5;
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
                nodes[dragNode].vx = 0; nodes[dragNode].vy = 0; startSim();
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
                dragNode = null;
            } else if (panning && !moved) {
                if (focusNode != null) { focusNode = null; document.getElementById('focus').classList.add('hidden'); document.getElementById('focus-empty').classList.remove('hidden'); draw(); }
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
            cam.x += before.x - after.x; cam.y += before.y - after.y;
            draw();
        }, { passive: false });

        function showDetail(i) {
            const n = nodes[i];
            focusNode = i;
            document.getElementById('focus').classList.remove('hidden');
            document.getElementById('focus-empty').classList.add('hidden');
            const fType = document.getElementById('f-type');
            const fChapter = document.getElementById('f-chapter');
            const fText = document.getElementById('f-text');
            const fChallenge = document.getElementById('f-challenge');
            const fLinks = document.getElementById('f-links');

            if (n.kind === 'claim') {
                fType.textContent = n.type || '主张';
                fType.style.backgroundColor = C_CLAIM + '22'; fType.style.color = C_CLAIM;
                fChapter.textContent = (n.chapters && n.chapters.length) ? '第 ' + n.chapters.join('、') + ' 章' : '';
                fText.textContent = n.text || '';
                fChallenge.textContent = n.challenge || '（离线模式无质询；在「AI 设置」填入密钥后由真实模型生成批判性质询）';
                fLinks.innerHTML = '';
                const evs = edges.filter(e => e.a === i && e.kind === 'ev').map(e => nodes[e.b]);
                const cos = edges.filter(e => e.a === i && e.kind === 'co').map(e => nodes[e.b]);
                if (evs.length) {
                    const h = document.createElement('div'); h.className = 'text-[11px] font-medium text-emerald-600 dark:text-emerald-300 mb-0.5'; h.textContent = '✅ 支撑证据';
                    fLinks.appendChild(h);
                    evs.forEach(ev => { const d = document.createElement('div'); d.className = 'text-xs text-zinc-600 dark:text-zinc-300 leading-relaxed ml-2 mb-1'; d.textContent = '· ' + (ev.text || ''); fLinks.appendChild(d); });
                }
                if (cos.length) {
                    const h = document.createElement('div'); h.className = 'text-[11px] font-medium text-rose-600 dark:text-rose-300 mt-1 mb-0.5'; h.textContent = '⚔ 反驳 / 反方';
                    fLinks.appendChild(h);
                    cos.forEach(co => { const d = document.createElement('div'); d.className = 'text-xs text-zinc-600 dark:text-zinc-300 leading-relaxed ml-2 mb-1'; d.textContent = '· ' + (co.text || ''); fLinks.appendChild(d); });
                }
                if (!evs.length && !cos.length) {
                    const d = document.createElement('div'); d.className = 'text-xs text-zinc-400'; d.textContent = '（该主张暂无显式证据或反驳被抽取）'; fLinks.appendChild(d);
                }
            } else {
                const claim = nodes[n.kind === 'ev' ? edges.find(e => e.b === i).a : edges.find(e => e.b === i).a];
                fType.textContent = n.kind === 'ev' ? ('证据 · ' + (n.type || '')) : ('反驳 · ' + (n.type || ''));
                fType.style.backgroundColor = (n.kind === 'ev' ? C_EVID : C_COUNTER) + '22';
                fType.style.color = n.kind === 'ev' ? C_EVID : C_COUNTER;
                fChapter.textContent = '';
                fText.textContent = n.text || '';
                fChallenge.textContent = '';
                fLinks.innerHTML = '';
                const link = document.createElement('div'); link.className = 'text-xs text-zinc-500 dark:text-zinc-400'; link.textContent = '↑ 关联主张：' + (claim && claim.text ? claim.text.slice(0, 40) + (claim.text.length > 40 ? '…' : '') : '');
                fLinks.appendChild(link);
            }
            draw();
        }

        function renderOutline() {
            outline.innerHTML = '';
            if (!data.claims.length) return;
            data.claims.forEach((c, i) => {
                const btn = document.createElement('button');
                btn.className = 'w-full text-left rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1.5 hover:border-primary-400 dark:border-zinc-700 dark:bg-zinc-800/60';
                const dot = document.createElement('span'); dot.className = 'inline-block h-2 w-2 rounded-full mr-1.5 align-middle bg-amber-500';
                const txt = document.createElement('span'); txt.className = 'text-xs text-zinc-700 dark:text-zinc-200 align-middle';
                txt.textContent = c.text ? c.text.slice(0, 26) + (c.text.length > 26 ? '…' : '') : '(空)';
                btn.appendChild(dot); btn.appendChild(txt);
                btn.addEventListener('click', () => showDetail(i));
                outline.appendChild(btn);
            });
        }

        function setStats() {
            document.getElementById('stat-claims').textContent = data.claims.length;
            document.getElementById('stat-ev').textContent = data.evidence.length;
            document.getElementById('stat-co').textContent = data.counter.length;
            const g = document.getElementById('stat-genre');
            g.textContent = data.genre === 'nonfiction' ? '📘 疑似非虚构'
                : (data.genre === 'novel' ? '📗 疑似小说' : '📙 体裁未判定');
        }

        // ---- load ----
        async function load() {
            try {
                const resp = await fetch('/api/book/' + BOOK_ID + '/argument', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                const d = await resp.json();
                if (d.ok && d.map && d.map.claims && d.map.claims.length) {
                    data = d.map;
                    let msg = '✅ 论证地图已生成（主张 ' + data.claims.length
                        + ' · 证据 ' + data.evidence.length
                        + ' · 反驳 ' + data.counter.length + '）。点主张查看论述与批判性质询。';
                    if (d.msg) msg += ' ' + d.msg;
                    statusEl.textContent = msg;
                    resize(); init(); renderOutline(); setStats();
                } else {
                    statusEl.textContent = '本书还没有论证地图，点右上角「生成 / 重新生成」开始（非虚构类效果最佳，首次可能需要一些时间）。';
                }
            } catch (e) {
                statusEl.textContent = '⚠️ 加载失败：' + e.message;
            }
        }

        document.getElementById('gen-btn').addEventListener('click', async () => {
            const btn = document.getElementById('gen-btn');
            btn.disabled = true;
            statusEl.textContent = '正在抽取论证骨架（主张—证据—反驳，首次可能需数十秒～数分钟，请稍候）…';
            try {
                const token = document.querySelector('meta[name=csrf-token]').content;
                const resp = await fetch('/api/book/' + BOOK_ID + '/argument', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                const d = await resp.json();
                if (d.ok && d.map) {
                    data = d.map;
                    let msg = '✅ 生成完成（主张 ' + data.claims.length + ' · 证据 ' + data.evidence.length + ' · 反驳 ' + data.counter.length + '）。';
                    if (d.msg) msg += ' ' + d.msg;
                    statusEl.textContent = msg;
                    resize(); init(); renderOutline(); setStats();
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
    })();
    </script>
