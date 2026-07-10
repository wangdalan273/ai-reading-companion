    <div class="py-6">
        <div class="max-w-5xl mx-auto px-4">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <h1 class="text-lg font-semibold text-zinc-800 dark:text-zinc-100">{{ $book->title }}</h1>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">AI 逐章总结 → 全书结构化脑图。点节点可缩放、拖拽。</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('read', $book) }}"
                       class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">← 返回阅读</a>
                    <a href="{{ route('book.analyze', [$book, 'tab' => 'graph']) }}"
                       class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">🕸 概念图谱</a>
                    <button id="gen-btn"
                            class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">🤖 生成 / 重新生成脑图</button>
                    <button id="dl-md" type="button"
                            class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 hover:border-primary-400 hover:text-primary-600 dark:border-zinc-700 dark:text-zinc-300"
                            title="下载脑图源 Markdown">⬇️ MD</button>
                    <button id="dl-svg" type="button"
                            class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-600 hover:border-primary-400 hover:text-primary-600 dark:border-zinc-700 dark:text-zinc-300"
                            title="下载脑图 SVG 图片">⬇️ SVG</button>
                </div>
            </div>

            <div id="status" class="mb-3 text-sm text-zinc-500 dark:text-zinc-400"></div>

            <div class="rounded-2xl border border-zinc-200 bg-white/80 dark:border-zinc-800 dark:bg-zinc-900/80 backdrop-blur overflow-hidden">
                <svg id="mindmap-svg" class="w-full h-[72vh]"></svg>
                <textarea id="mindmap-source" class="hidden">{{ $book->mindmap_md ?? '' }}</textarea>
            </div>
        </div>
    </div>

    <script>
        function dlMindmap(kind) {
            const src = document.getElementById('mindmap-source').value;
            if (!src.trim()) { alert('还没有脑图内容，请先生成。'); return; }
            let blob, name;
            if (kind === 'md') {
                blob = new Blob([src], { type: 'text/markdown;charset=utf-8' });
                name = '{{ $book->id }}-{{ preg_replace("/[^\\p{L}\\p{N}_-]/u", "-", $book->title) }}-脑图.md';
            } else {
                const svg = document.getElementById('mindmap-svg');
                const clone = svg.cloneNode(true);
                clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
                const data = new XMLSerializer().serializeToString(clone);
                blob = new Blob([data], { type: 'image/svg+xml;charset=utf-8' });
                name = '{{ $book->id }}-{{ preg_replace("/[^\\p{L}\\p{N}_-]/u", "-", $book->title) }}-脑图.svg';
            }
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = name; a.click();
            setTimeout(() => URL.revokeObjectURL(url), 2000);
        }
        document.getElementById('dl-md').addEventListener('click', () => dlMindmap('md'));
        document.getElementById('dl-svg').addEventListener('click', () => dlMindmap('svg'));

        document.getElementById('gen-btn').addEventListener('click', async () => {
            const btn = document.getElementById('gen-btn');
            const status = document.getElementById('status');
            btn.disabled = true;
            status.innerHTML = '⏳ 正在逐章总结（取前 8 章，约 30-90 秒）。<br><b>生成期间整站会短暂无响应，请勿打开其他页面或刷新。</b>';
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 150000);
            try {
                const token = document.querySelector('meta[name=csrf-token]').content;
                const resp = await fetch('/api/book/{{ $book->id }}/analyze', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    signal: controller.signal,
                });
                clearTimeout(timer);
                const data = await resp.json();
                if (data.ok) {
                    document.getElementById('mindmap-source').value = data.mindmap_md;
                    status.textContent = '✅ 生成完成，正在渲染…';
                    location.reload();
                } else {
                    status.textContent = '⚠️ ' + (data.msg || '生成失败');
                    btn.disabled = false;
                }
            } catch (e) {
                clearTimeout(timer);
                status.textContent = e.name === 'AbortError'
                    ? '⚠️ 生成超时（超过 150 秒）。模型响应慢或额度受限，请稍后重试。'
                    : '⚠️ 请求出错：' + e.message;
                btn.disabled = false;
            }
        });
    </script>
