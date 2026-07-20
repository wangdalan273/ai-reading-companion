// Reader engine: lazy-loads epub.js and drives the paginated viewer.
// Kept fully outside Livewire's diffing (the #viewer container is wire:ignore)
// so epub.js's injected iframes survive Livewire updates.
//
// Self-initialises on DOMContentLoaded by scanning for [data-reader-url], which
// removes any Alpine x-init ordering race. Any failure is surfaced visibly
// (never a silent white screen).

const CompanionReader = {
    book: null,
    rendition: null,
    container: null,
    bookUrl: null,
    bookId: null,
    toc: [],
    highlights: new Map(), // id -> cfi
    booted: false,
    lastSelection: '', // 最近一次选中的文本（供出测验等跨组件读取 iframe 选区）
    readingState: null,
    readingStateTimer: null,

    boot() {
        if (this.booted) return;
        const el = document.querySelector('[data-reader-url]');
        if (!el) return;
        this.booted = true;
        this.container = el;
        this.bookUrl = el.getAttribute('data-reader-url');
        this.bookId = el.getAttribute('data-book-id');
        this._installErrorGuard();
        this._installTheme();
        this._installHeartbeat();
        this._showLoading('正在打开本书…');
        this._start();
    },

    async _start() {
        try {
            const mod = await import('epubjs');
            const ePub = mod.default || mod;

            // --- KEY FIX (P6) -------------------------------------------------
            // We fetch the book file ourselves (with session credentials) and hand
            // the resulting ArrayBuffer to epub.js. This forces epub.js to treat it
            // as a PACKAGED book and unzip it in memory — it will NEVER try to
            // resolve "META-INF/container.xml" (or any other path) as a network
            // request relative to our suffix-less /book/{id}/file URL. That wrong
            // "unpacked directory" assumption was the cause of the 404 + perpetual
            // loading spinner.
            this._showLoading('正在下载并打开本书…');
            const resp = await fetch(this.bookUrl, { credentials: 'same-origin' });
            if (!resp.ok) {
                throw new Error('书籍文件请求失败（HTTP ' + resp.status + '）');
            }
            const buf = await resp.arrayBuffer();
            this.book = ePub(buf);

            this.book.loaded.manifest
                .then(() => {})
                .catch((e) => this._fail('书籍解析失败', e));
            this.book.loaded.metadata
                .then(() => {})
                .catch(() => {});

            this.rendition = this.book.renderTo(this.container, {
                width: '100%',
                height: '100%',
                spread: 'none',
                flow: 'paginated',
                manager: 'default',
            });

            this.rendition.on('relocated', (loc) => this._onRelocated(loc));

            // ★ 划线 CFI 捕获（核心修复）：epub.js 在选区完成时（mouseup 后 250ms）
            // 抛出已经算好的 CFI 字符串。这是最可靠的来源——在 iframe 失焦、选区被清
            // 之前就已捕获。点击工具条按钮时再去读选区必空（旧方案失败根因），所以这里
            // 缓存起来，_highlight 直接用。
            this.rendition.on('selected', (cfirange, contents) => {
                if (cfirange) {
                    this._pendingCfi = cfirange;
                    try {
                        const sel = (contents && contents.window) ? contents.window.getSelection() : null;
                        this._pendingSelText = sel ? sel.toString() : '';
                    } catch (e) { this._pendingSelText = ''; }
                }
            });

            this.readingState = await this._loadReadingState();
            this.rendition
                .display(this.readingState?.locator || undefined)
                .then(() => {
                    this._hideLoading();
                    this._wireSelection();
                    this.rendition.on('rendered', () => {
                        this._wireSelection();
                        // Re-apply eye-care / dark styling after epub.js swaps page content
                        this._applyReaderTheme(this._currentTheme());
                    });
                    this._applyReaderTheme(this._currentTheme());
                    this._loadHighlights();
                    this._maybeJumpToHighlight();
                    this._installGestures();
                })
                .catch((e) => this._fail('书籍渲染失败', e));

            this.book.loaded.navigation
                .then((nav) => {
                    this.toc = this._flatten(nav.toc || []);
                    window.dispatchEvent(new CustomEvent('companion:toc', { detail: this.toc }));
                })
                .catch(() => {});

            this.container.setAttribute('tabindex', '0');
            this.container.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowRight') this.next();
                if (e.key === 'ArrowLeft') this.prev();
            });
        } catch (e) {
            this._fail('初始化失败', e);
        }
    },

    _flatten(toc, depth = 0) {
        let out = [];
        for (const item of toc) {
            out.push({ label: (depth ? '    '.repeat(depth) : '') + (item.label || '').trim(), href: item.href });
            if (item.subitems && item.subitems.length) {
                out = out.concat(this._flatten(item.subitems, depth + 1));
            }
        }
        return out;
    },

    // ---- selection → floating toolbar (划线 / 问 AI / 翻译) ----
    _wireSelection() {
        const iframe = this.container.querySelector('iframe');
        if (!iframe || !iframe.contentDocument) return;

        const doc = iframe.contentDocument;
        const handler = () => {
            const sel = doc.getSelection();
            if (!sel || sel.isCollapsed) {
                this._hideFloat();
                return;
            }
            const text = sel.toString().trim();
            if (!text) {
                this._hideFloat();
                return;
            }
            const rect = sel.getRangeAt(0).getBoundingClientRect();
            this._showFloat(text, rect);
        };

        doc.addEventListener('mouseup', handler);
        doc.addEventListener('selectionchange', handler);
    },

    _showFloat(text, rect) {
        this.lastSelection = text; // 记录最近选区，供出测验 modal 跨组件读取
        // ★ 关键修复：点击工具条按钮会令 iframe 失焦、选区被清除，等 _highlight
        //   再去读选区已空→划线失败。所以在选区仍活的此刻就把 CFI 算好缓存。
        try { this._pendingCfi = this._getSelectionCfi(); } catch (e) { this._pendingCfi = null; }
        let bar = document.getElementById('companion-sel-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'companion-sel-bar';
            bar.className = 'companion-sel-bar';
            document.body.appendChild(bar);
        }
        bar.innerHTML =
            '<button type="button" data-act="hl">🖍 划线</button>' +
            '<button type="button" data-act="ask">💬 问 AI</button>' +
            '<button type="button" data-act="tr">🌐 翻译</button>' +
            '<button type="button" data-act="def">📖 解释</button>' +
            '<button type="button" data-act="fc">🃏 闪卡</button>' +
            '<button type="button" data-act="sh">📤 分享</button>';
        this._lastRect = rect;
        const base = this._iframeBase();
        bar.style.display = 'flex';
        const bw = bar.offsetWidth || 320;
        const bh = bar.offsetHeight || 40;
        const vw = window.innerWidth;
        // 水平居中于选区，更贴近文字（间距 4px）
        let left = base.left + rect.left + rect.width / 2 + window.scrollX - bw / 2;
        let top = base.top + rect.top + window.scrollY - bh - 4;
        if (top < window.scrollY + 4) top = base.top + rect.bottom + window.scrollY + 4; // 上方空间不足则放下方
        left = Math.max(4, Math.min(left, vw - bw - 4));
        bar.style.left = left + 'px';
        bar.style.top = top + 'px';
        bar.onclick = (e) => {
            const act = e.target.getAttribute('data-act');
            if (act === 'hl') this._highlight(text);
            else if (act === 'ask') this._askAi(text);
            else if (act === 'tr') this._translate(text);
            else if (act === 'def') this._define(text);
            else if (act === 'fc') this._saveFlashcard(text);
            else if (act === 'sh') this._shareCard(text);
            this._hideFloat();
            const s = this.container.querySelector('iframe')?.contentDocument?.getSelection?.();
            if (s) s.removeAllRanges();
        };
    },

    _translate(text) {
        window.dispatchEvent(new CustomEvent('companion:translate-selection', { detail: text }));
    },

    // ---- 闪卡：把划线存为复习卡 ----
    async _saveFlashcard(text) {
        try {
            const res = await fetch(`/book/${this.bookId}/flashcards`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this._csrf(),
                },
                body: JSON.stringify({ quote: text }),
            });
            const data = await res.json();
            if (data.ok) this._toast('🃏 已存为闪卡，去「闪卡复习」练记');
        } catch (e) {
            this._toast('闪卡保存失败');
        }
    },

    // ---- 金句分享卡片：canvas 绘图 → navigator.share / 下载 PNG ----
    _shareCard(text) {
        const bookTitle = document.querySelector('[data-book-title]')?.getAttribute('data-book-title') || 'AI 伴读';
        const theme = this._currentTheme();
        const canvas = document.createElement('canvas');
        canvas.width = 1080;
        canvas.height = 1080;
        const ctx = canvas.getContext('2d');

        // 背景渐变
        const grad = ctx.createLinearGradient(0, 0, 1080, 1080);
        if (theme === 'dark') {
            grad.addColorStop(0, '#1c1917'); grad.addColorStop(1, '#292524');
        } else if (theme === 'sepia') {
            grad.addColorStop(0, '#FBF4E6'); grad.addColorStop(1, '#F0E0C0');
        } else {
            grad.addColorStop(0, '#FFFBF0'); grad.addColorStop(1, '#FFF5E0');
        }
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, 1080, 1080);

        // 装饰边框
        ctx.strokeStyle = theme === 'dark' ? '#BA7517' : '#D4933A';
        ctx.lineWidth = 3;
        ctx.strokeRect(40, 40, 1000, 1000);
        ctx.lineWidth = 1;
        ctx.strokeRect(55, 55, 970, 970);

        // 引号装饰
        ctx.font = '200px Georgia, serif';
        ctx.fillStyle = theme === 'dark' ? 'rgba(186,117,23,0.3)' : 'rgba(212,147,58,0.2)';
        ctx.fillText('\u201C', 80, 260);

        // 金句正文（自动换行）
        ctx.font = '42px "PingFang SC", "Microsoft YaHei", sans-serif';
        ctx.fillStyle = theme === 'dark' ? '#e7e5e4' : (theme === 'sepia' ? '#3A2104' : '#27272A');
        const maxWidth = 880;
        const lines = this._wrapText(ctx, text, maxWidth);
        const startY = 300;
        const lineH = 62;
        lines.slice(0, 9).forEach((line, i) => {
            ctx.fillText(line, 100, startY + i * lineH);
        });

        // 书名
        ctx.font = '28px "PingFang SC", sans-serif';
        ctx.fillStyle = theme === 'dark' ? '#ECB659' : '#9A5E10';
        ctx.fillText('\u2014\u2014 ' + bookTitle, 100, startY + Math.min(lines.length, 9) * lineH + 40);

        // 水印
        ctx.font = '24px sans-serif';
        ctx.fillStyle = theme === 'dark' ? 'rgba(231,229,228,0.4)' : 'rgba(55,55,55,0.35)';
        ctx.fillText('AI 伴读 \u00B7 reading-companion', 100, 1020);

        canvas.toBlob((blob) => {
            if (!blob) return;
            const file = new File([blob], 'ai-bandu-quote.png', { type: 'image/png' });
            if (navigator.canShare && navigator.canShare({ files: [file] })) {
                navigator.share({ files: [file], title: bookTitle, text: text }).catch(() => {});
            } else {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'ai-bandu-quote.png';
                a.click();
                setTimeout(() => URL.revokeObjectURL(url), 3000);
                this._toast('已下载图片，可分享到朋友圈');
            }
        }, 'image/png');
    },

    _wrapText(ctx, text, maxWidth) {
        const chars = text.split('');
        const lines = [];
        let current = '';
        for (const ch of chars) {
            const test = current + ch;
            if (ctx.measureText(test).width > maxWidth && current) {
                lines.push(current);
                current = ch === '\n' ? '' : ch;
            } else if (ch === '\n') {
                lines.push(current);
                current = '';
            } else {
                current = test;
            }
        }
        if (current) lines.push(current);
        return lines;
    },

    _toast(msg) {
        let t = document.getElementById('companion-toast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'companion-toast';
            t.className = 'companion-toast';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.style.display = 'block';
        clearTimeout(this._toastTimer);
        this._toastTimer = setTimeout(() => { t.style.display = 'none'; }, 2500);
    },

    _hideFloat() {
        const b = document.getElementById('companion-sel-bar');
        if (b) b.style.display = 'none';
        // 选区已收起 → 缓存的 CFI 失效，避免误用上一次的旧位置
        this._pendingCfi = null;
        this._pendingSelText = '';
    },

    _askAi(text) {
        window.dispatchEvent(new CustomEvent('companion:ask-selection', { detail: text }));
    },

    // ---- N5 术语悬停：选中词 → 就地气泡解释（最低认知中断） ----
    _define(text) {
        const rect = this._lastRect || { left: window.innerWidth / 2, top: 120 };
        const context = this._selectionContext() || text;
        this._showDefPop(text, '…解释中', rect, true);
        fetch('/api/companion/define', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this._csrf(),
            },
            body: JSON.stringify({ term: text, context: context, book_id: this.bookId }),
        })
            .then((r) => r.json())
            .then((d) => {
                this._showDefPop(text, (d.definition || '（未能生成解释）'), rect, false);
            })
            .catch(() => {
                this._showDefPop(text, '（网络错误，请重试）', rect, false);
            });
    },

    // 从选中文本所在的块级元素抓取上下文（前后句），给解释提供语境。
    _selectionContext() {
        const sel = this._iframeSelection();
        if (!sel || sel.isCollapsed || !sel.anchorNode) return '';
        let node = sel.anchorNode;
        if (node.nodeType === 3) node = node.parentElement;
        const block = node ? node.closest('p, div, blockquote, li, section, article') : null;
        const ctx = (block ? block.innerText : (sel.anchorNode.textContent || '')).trim();
        return ctx.length > 600 ? ctx.slice(0, 600) : ctx;
    },

    _showDefPop(term, def, rect, loading) {
        let pop = document.getElementById('companion-def-pop');
        if (!pop) {
            pop = document.createElement('div');
            pop.id = 'companion-def-pop';
            pop.className = 'companion-def-pop';
            document.body.appendChild(pop);
        }
        pop.innerHTML =
            '<div class="def-head"><span class="def-term">📖 ' + this._esc(term) + '</span>' +
            '<button type="button" class="def-close" aria-label="关闭">×</button></div>' +
            '<div class="def-body' + (loading ? ' def-loading' : '') + '">' + this._esc(def) + '</div>';
        // 定位：优先浮在选区上方并水平居中；空间不够则放下方
        const base = this._iframeBase();
        const popW = 320, vw = window.innerWidth;
        let left = base.left + rect.left + rect.width / 2 + window.scrollX - popW / 2;
        pop.style.display = 'block';
        const popH = pop.offsetHeight || 160;
        let top = base.top + rect.top + window.scrollY - popH - 6;
        if (top < window.scrollY + 4) top = base.top + rect.bottom + window.scrollY + 6; // 上方不足则放下方
        left = Math.max(4, Math.min(left, vw - popW - 4));
        pop.style.left = left + 'px';
        pop.style.top = top + 'px';
        pop.style.display = 'block';
        pop.querySelector('.def-close').onclick = (e) => { e.stopPropagation(); this._hideDefPop(); };
        // 点击外部关闭（延迟绑定避免立即触发）
        clearTimeout(this._defTimer);
        this._defTimer = setTimeout(() => {
            const close = (ev) => {
                if (pop && !pop.contains(ev.target)) {
                    this._hideDefPop();
                    document.removeEventListener('click', close, true);
                }
            };
            document.addEventListener('click', close, true);
        }, 0);
    },

    _hideDefPop() {
        const p = document.getElementById('companion-def-pop');
        if (p) p.style.display = 'none';
    },

    _esc(s) {
        return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    },

    // ---- gestures: swipe to flip + left/right edge tap zones ----
    _installGestures() {
        const container = this.container;
        if (!container) return;

        // Edge tap zones: clicking the far-left / far-right strip turns the page.
        // The center 70% stays free for text selection.
        const makeZone = (side) => {
            const z = document.createElement('div');
            z.className = 'companion-tapzone companion-tapzone-' + side;
            z.setAttribute('aria-hidden', 'true');
            z.addEventListener('click', (e) => {
                e.preventDefault();
                if (side === 'left') this.prev();
                else this.next();
            });
            container.appendChild(z);
            return z;
        };
        const leftZone = makeZone('left');
        const rightZone = makeZone('right');

        // 翻页热区默认穿透（pointer-events:none），只有鼠标真正贴左/右边缘时才
        // 激活接管翻页，避免大片热区吞掉文字选取（修复 3）。
        let raf = 0;
        container.addEventListener('mousemove', (e) => {
            if (raf) return;
            const clientX = e.clientX;
            raf = requestAnimationFrame(() => {
                raf = 0;
                const r = container.getBoundingClientRect();
                const x = clientX - r.left;
                const edge = Math.max(48, r.width * 0.08);
                if (x <= edge) {
                    leftZone.classList.add('active');
                    rightZone.classList.remove('active');
                } else if (x >= r.width - edge) {
                    rightZone.classList.add('active');
                    leftZone.classList.remove('active');
                } else {
                    leftZone.classList.remove('active');
                    rightZone.classList.remove('active');
                }
            });
        });
        container.addEventListener('mouseleave', () => {
            leftZone.classList.remove('active');
            rightZone.classList.remove('active');
        });

        // Swipe (touch): flip only on a clean horizontal flick, and never when the
        // user ended up with selected text (that gesture was a selection, not a flip).
        let sx = 0, sy = 0, st = 0, tracking = false;
        container.addEventListener('touchstart', (e) => {
            if (e.touches.length !== 1) { tracking = false; return; }
            sx = e.touches[0].clientX;
            sy = e.touches[0].clientY;
            st = Date.now();
            tracking = true;
        }, { passive: true });
        container.addEventListener('touchend', (e) => {
            if (!tracking) return;
            tracking = false;
            const t = e.changedTouches[0];
            const dx = t.clientX - sx;
            const dy = t.clientY - sy;
            const dt = Date.now() - st;
            const sel = this._iframeSelection();
            if (sel && !sel.isCollapsed) return; // was selecting text, not flipping
            if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.5 && dt < 700) {
                if (dx < 0) this.next();
                else this.prev();
            }
        }, { passive: true });
    },

    _iframeSelection() {
        const iframe = this.container.querySelector('iframe');
        if (!iframe || !iframe.contentDocument) return null;
        return iframe.contentDocument.getSelection();
    },

    // iframe 在页面中的位置（把"iframe 内部选区坐标"映射到页面文档坐标，修复浮条偏移）
    _iframeBase() {
        const iframe = this.container.querySelector('iframe');
        if (!iframe) return { left: 0, top: 0, bottom: 0 };
        const r = iframe.getBoundingClientRect();
        return { left: r.left, top: r.top, bottom: r.bottom };
    },

    // epub.js 当前位置变化 → 同步左侧目录高亮（修复 2）
    _onRelocated(loc) {
        const cur = (loc && loc.start && loc.start.href) || '';
        const locator = loc && loc.start && loc.start.cfi;
        if (locator) this._scheduleReadingStateSave(locator, Number(loc.start.percentage || 0));
        if (!cur || !this.toc.length) return;
        const norm = (h) => decodeURIComponent(h || '').split('#')[0].replace(/^\.?\//, '');
        const target = norm(cur);
        let match = this.toc.find((it) => norm(it.href) === target);
        if (!match) match = this.toc.find((it) => target.endsWith(norm(it.href)) || norm(it.href).endsWith(target));
        if (match) {
            window.dispatchEvent(new CustomEvent('companion:relocated', { detail: match.href }));
            if (this.readingState) this.readingState.section_title = match.label.trim();
        }
    },

    async _loadReadingState() {
        try {
            const response = await fetch(`/api/reading/state/${this.bookId}`, { credentials: 'same-origin' });
            if (!response.ok) return null;
            return (await response.json()).state || null;
        } catch (e) {
            return null;
        }
    },

    _scheduleReadingStateSave(locator, progress) {
        this.readingState = {
            ...(this.readingState || {}),
            format: 'epub',
            locator,
            progress: Math.max(0, Math.min(1, Number(progress) || Number(this.readingState?.progress) || 0)),
            bookmarks: this.readingState?.bookmarks || [],
            client_updated_at: new Date().toISOString(),
        };
        clearTimeout(this.readingStateTimer);
        this.readingStateTimer = setTimeout(() => {
            fetch(`/api/reading/state/${this.bookId}`, {
                method: 'PUT', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this._csrf() },
                body: JSON.stringify(this.readingState),
            }).catch(() => {});
        }, 1200);
    },

    // ---- highlights (persisted as CFI) ----

    // 版本鲁棒的选区 CFI 提取：
    //  - 优先 rendition.getSelectionCfi()（部分 epub.js 版本有，返回 string 或 Promise）
    //  - 0.3.93 没有该方法 → 经 rendition.manager.getContents() 取当前 Contents，
    //    用 contents.cfiFromRange(range) 生成 CFI（epub.js 内部就是这套）。
    //    修复「getSelectionCfi is not a function」导致划线静默失败的问题。
    _getSelectionCfi() {
        try {
            const rendition = this.rendition;
            if (!rendition) return null;
            if (typeof rendition.getSelectionCfi === 'function') {
                return rendition.getSelectionCfi();
            }
            const mgr = rendition.manager;
            if (!mgr || typeof mgr.getContents !== 'function') return null;
            const list = mgr.getContents();
            if (!list || !list.length) return null;
            const contents = list[0];
            const iframe = this.container.querySelector('iframe');
            const win = (contents && contents.window) || (iframe && iframe.contentWindow);
            if (!win) return null;
            const sel = win.getSelection();
            if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return null;
            const range = sel.getRangeAt(0);
            if (contents && typeof contents.cfiFromRange === 'function') {
                return contents.cfiFromRange(range);
            }
            return null;
        } catch (e) {
            return null;
        }
    },

    async _highlight(text) {
        try {
            // 主源：rendition 'selected' 事件在选区存活期已缓存的 CFI（点击按钮后
            // iframe 失焦、选区被清也能用）。缓存失效再尝试现读作为兜底。
            let cfi = this._pendingCfi;
            this._pendingCfi = null;
            if (!cfi) cfi = this._getSelectionCfi();
            if (cfi && typeof cfi.then === 'function') cfi = await cfi;
            if (!cfi) {
                this._toast('未取到选区位置，请重新选中文字再划线');
                return;
            }
            const quote = (text && text.trim()) ? text : (this._pendingSelText || '');
            const res = await fetch(`/book/${this.bookId}/annotations`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this._csrf(),
                },
                body: JSON.stringify({ loc: cfi, quote }),
            });
            const data = await res.json().catch(() => ({ ok: false, msg: '服务器无响应' }));
            if (!data.ok) {
                // 只有后端真的没存成功，才报"保存失败"
                this._toast(data.msg || '划线保存失败，请重试');
                return;
            }
            // ★ 关键修复：保存成功（已落库）与视觉渲染解耦。
            // 渲染（rendition.annotations.add）若因视图未就绪抛错，不再误报"失败"，
            // 因为记录已存在，刷新/翻页后由 _loadHighlights 仍会显示。
            let rendered = false;
            try {
                this._addHighlight(data.id, cfi, quote);
                rendered = true;
            } catch (e) {
                console.warn('[CompanionReader] highlight render deferred (saved ok):', e);
            }
            if (!rendered) {
                // 视图尚未就绪时，延后一次性重试渲染当前区段
                setTimeout(() => {
                    try { this._addHighlight(data.id, cfi, quote); } catch (_) { /* 留给 reload 渲染 */ }
                }, 250);
            }
            this._toast('🖍 已划线');
        } catch (e) {
            console.error('[CompanionReader] highlight failed', e);
            this._toast('划线失败，请重试');
        }
    },

    _addHighlight(id, cfi, text) {
        this.highlights.set(id, cfi);
        // 注意：annotations.add(type, cfiRange, data, cb, className, styles)
        // 第 5 个是 className（字符串类名），第 6 个才是 styles。
        // 之前把 styles 对象错当 className 传入，导致 fill 从未生效 → 高亮不可见。
        // 这里：className 用 'cr-hl'，配合注入 iframe 的 CSS 上色；styles 作 SVG 属性兜底。
        this.rendition.annotations.add(
            'highlight',
            cfi,
            { id },
            (e) => this._showHlMenu(id, e),
            'cr-hl',
            {
                // 半透明琥珀色：浅色/深色/护眼三主题下都清晰可见（不使用 mix-blend-mode，
                // 否则深色与护眼背景下 multiply 会让黄色高亮几乎隐形）。
                fill: 'rgba(255, 193, 7, 0.45)',
                'fill-opacity': '0.45',
            }
        );
    },

    async _loadHighlights() {
        try {
            const res = await fetch(`/book/${this.bookId}/annotations`, { headers: { 'X-CSRF-TOKEN': this._csrf() } });
            const data = await res.json();
            (data.annotations || []).forEach((a) => this._addHighlight(a.id, a.loc, a.quote));
        } catch (e) {
            console.error('[CompanionReader] load highlights failed', e);
        }
    },

    // 划线查看页点击某条 → 带 ?hl=<id> 打开阅读器 → 自动定位到该划线
    async _maybeJumpToHighlight() {
        const id = new URLSearchParams(location.search).get('hl');
        if (!id) return;
        try {
            const res = await fetch(`/book/${this.bookId}/annotations`, { headers: { 'X-CSRF-TOKEN': this._csrf() } });
            const data = await res.json();
            const found = (data.annotations || []).find((a) => String(a.id) === String(id));
            if (found && found.loc) {
                this.rendition.display(found.loc);
                this._toast('📍 已跳转到划线位置');
            }
        } catch (e) { /* best-effort */ }
    },

    async _removeHighlight(id) {
        try {
            await fetch(`/book/${this.bookId}/annotations/${id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': this._csrf() },
            });
            const cfi = this.highlights.get(id);
            // ★ 关键修复：epubjs 的 remove 用 encodeURI(cfiRange + type) 作 key，
            // 不传 type 时 hash 与 add 时(cfi+"highlight")不一致 → 视觉删除 no-op。
            if (cfi) this.rendition.annotations.remove(cfi, 'highlight');
            this.highlights.delete(id);
        } catch (e) {
            console.error('[CompanionReader] remove highlight failed', e);
        }
    },

    _showHlMenu(id, e) {
        e?.preventDefault?.();
        let menu = document.getElementById('companion-hl-menu');
        if (!menu) {
            menu = document.createElement('div');
            menu.id = 'companion-hl-menu';
            menu.className = 'companion-hl-menu';
            document.body.appendChild(menu);
        }
        menu.innerHTML =
            '<button type="button" data-act="ask">💬 问 AI</button>' +
            '<button type="button" data-act="del">🗑 删除划线</button>';
        // ★ 关键修复：高亮在 iframe 内，e.target 的坐标是 iframe 视口坐标，
        // 必须叠加 iframe 在页面中的位置，否则菜单被推到屏幕外（三栏布局下尤其明显）。
        // 这里让菜单紧贴高亮区域底部，水平居中，避免离选区太远。
        const iframe = this.container.querySelector('iframe');
        const base = (iframe && iframe.getBoundingClientRect) ? iframe.getBoundingClientRect() : { left: 0, top: 0 };
        const rect = (e && e.target && e.target.getBoundingClientRect?.()) || { left: 0, top: 0, width: 0 };
        menu.style.display = 'flex';
        const mw = menu.offsetWidth || 180;
        let left = base.left + rect.left + rect.width / 2 + window.scrollX - mw / 2;
        let top = base.top + rect.bottom + window.scrollY + 4;
        left = Math.max(4, Math.min(left, window.innerWidth - mw - 4));
        menu.onclick = (ev) => {
            const act = ev.target.getAttribute('data-act');
            if (act === 'ask') this._askAiFromId(id);
            else if (act === 'del') this._removeHighlight(id);
            menu.style.display = 'none';
        };
    },

    _askAiFromId(id) {
        const cfi = this.highlights.get(id);
        // best-effort: we stored quote nowhere client-side; ask server is overkill,
        // so just open the AI panel and let the user quote. Simpler: resolve quote
        // from the highlight's stored annotation via a quick fetch.
        fetch(`/book/${this.bookId}/annotations/${id}`, { headers: { 'X-CSRF-TOKEN': this._csrf() } })
            .then((r) => r.json())
            .then((d) => this._askAi(d.quote || ''))
            .catch(() => this._askAi(''));
    },

    // ---- UI helpers ----
    _csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    },

    _showLoading(msg) {
        this._removeOverlay();
        const o = document.createElement('div');
        o.className = 'companion-overlay';
        o.id = 'companion-overlay';
        o.innerHTML =
            '<div class="companion-spinner"></div><p>' + msg + '</p>';
        this.container.appendChild(o);
    },

    _hideLoading() {
        this._removeOverlay();
    },

    _removeOverlay() {
        const o = document.getElementById('companion-overlay');
        if (o) o.remove();
    },

    _fail(stage, err) {
        this._hideLoading();
        // eslint-disable-next-line no-console
        console.error('[CompanionReader]', stage, this.bookUrl, err);
        const msg = (err && (err.message || err.reason)) ? err.message || err.reason : String(err);
        let banner = document.getElementById('companion-err');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'companion-err';
            banner.className = 'companion-err';
            document.body.appendChild(banner);
        }
        banner.innerHTML =
            '<div class="companion-err-box">' +
            '<b>⚠️ 阅读器出错了（' + stage + '）</b>' +
            '<p>' + msg + '</p>' +
            '<small>' + this.bookUrl + '</small>' +
            '<button type="button" onclick="location.reload()">重新加载</button>' +
            '</div>';
        banner.style.display = 'flex';
    },

    _installErrorGuard() {
        // 只弹真正致命的 reader 错误；过滤无害噪声，避免把正常工作的阅读器
        // 误判为「打不开」：
        //  - 沙箱 EPUB iframe 内脚本被浏览器拦截（about:srcdoc）
        //  - 浏览器内部 "ResizeObserver loop" 提示（epub.js 缩放时偶发，无害）
        //  - CustomElementRegistry 重复注册（历史双加载残留，非阅读器问题）
        //  - 跨域 iframe 无详情的错误
        const isBenign = (msg, file) => {
            if (file && String(file).includes('srcdoc')) return true;
            const m = String(msg || '');
            if (m.includes('ResizeObserver loop completed')) return true;
            if (m.includes('Blocked script execution')) return true;
            if (m.includes('CustomElementRegistry')) return true;
            return false;
        };

        window.addEventListener('error', (e) => {
            if (!this.booted) return;
            const msg = e && e.message ? e.message : '';
            const file = e && e.filename ? e.filename : '';
            // 跨域 / 沙箱 iframe 抛出的错误往往没有可用详情，直接忽略
            if (!msg && !e.error) return;
            if (isBenign(msg, file)) return;
            this._fail('页面脚本错误', e.error || msg);
        });
        window.addEventListener('unhandledrejection', (e) => {
            if (!this.booted) return;
            const r = e && e.reason ? (e.reason.message || e.reason) : '';
            if (!r || isBenign(String(r), '')) return;
            this._fail('异步错误', e.reason);
        });
    },

    // ---- 阅读时长心跳：页面可见时累计秒数，每 30 秒上报一次 ----
    _installHeartbeat() {
        this._hbAccum = 0;
        this._hbLast = Date.now();
        this._hbTimer = null;
        this._hbVisible = !document.hidden;

        document.addEventListener('visibilitychange', () => {
            this._hbVisible = !document.hidden;
            if (this._hbVisible) this._hbLast = Date.now();
        });

        this._hbTimer = setInterval(() => {
            if (!this._hbVisible || !this.bookId) return;
            const now = Date.now();
            const delta = Math.min(30, Math.round((now - this._hbLast) / 1000));
            if (delta < 1) return;
            this._hbLast = now;
            this._hbAccum += delta;
            this._sendHeartbeat(delta);
        }, 30000);

        // 页面关闭前尽力发送最后一批
        window.addEventListener('beforeunload', () => {
            if (this._hbAccum > 0) {
                this._sendHeartbeat(this._hbAccum, true);
            }
        });
    },

    _sendHeartbeat(seconds, useBeacon) {
        const payload = JSON.stringify({ book_id: parseInt(this.bookId), seconds });
        const headers = {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': this._csrf(),
        };
        try {
            if (useBeacon && navigator.sendBeacon) {
                navigator.sendBeacon('/api/reading/log', new Blob([payload], { type: 'application/json' }));
            } else {
                fetch('/api/reading/log', {
                    method: 'POST',
                    headers,
                    body: payload,
                    keepalive: true,
                }).catch(() => {});
            }
        } catch (e) { /* silent — heartbeat is best-effort */ }
    },

    // ---- 护眼 / 深色：把主题样式注入 epub.js 的 iframe 正文 ----
    _currentTheme() {
        return (window.CompanionTheme && window.CompanionTheme.get) ? window.CompanionTheme.get() : 'light';
    },

    _installTheme() {
        window.addEventListener('companion:theme', (e) => this._applyReaderTheme(e.detail));
    },

    _applyReaderTheme(theme) {
        if (!this.container) return;
        const iframes = this.container.querySelectorAll('iframe');
        iframes.forEach((iframe) => {
            const doc = iframe.contentDocument;
            if (!doc) return;
            let style = doc.getElementById('companion-theme-style');
            if (!style) {
                style = doc.createElement('style');
                style.id = 'companion-theme-style';
                (doc.head || doc.documentElement).appendChild(style);
            }
            // 幂等：内容未变化就不重写，避免反复触发布局/ResizeObserver 抖动
            const next = this._themeCss(theme);
            if (style.textContent === next) return;
            style.textContent = next;
        });
    },

    _themeCss(theme) {
        // 高亮配色：随主题切换/换页始终注入 iframe，CSS fill 优先级高于 SVG 属性，
        // 保证划线在 浅色/深色/护眼 三模式下都清晰可见。
        const hl = '.cr-hl,.cr-hl rect{cursor:pointer;fill:rgba(255,193,7,0.45) !important;}';
        if (theme === 'sepia') {
            return 'html,body,section,p,div,span,li,a,blockquote,pre,code,h1,h2,h3,h4,h5,h6,' +
                'table,tr,td,th,ul,ol,dl,dt,dd{background-color:#FBF4E6 !important;color:#3A2104 !important;}' +
                'a{color:#9A5E10 !important;}' +
                'img{filter:sepia(0.18) brightness(0.96);}' + hl;
        } else if (theme === 'dark') {
            return 'html,body,section,p,div,span,li,a,blockquote,pre,code,h1,h2,h3,h4,h5,h6,' +
                'table,tr,td,th,ul,ol,dl,dt,dd{background-color:#1c1917 !important;color:#e7e5e4 !important;}' +
                'a{color:#ECB659 !important;}' +
                'img{filter:brightness(0.85);}' + hl;
        }
        return hl;
    },

    // ---- TTS 语音朗读：浏览器原生 speechSynthesis ----
    ttsPlaying: false,
    _ttsUtter: null,

    ttsToggle() {
        if (this.ttsPlaying) this.ttsStop();
        else this.ttsStart();
    },

    ttsStart() {
        const text = this._currentPageText();
        if (!text) return;
        this.ttsStop();
        const u = new SpeechSynthesisUtterance(text);
        u.lang = 'zh-CN';
        u.rate = 1.0;
        u.onend = () => {
            this.ttsPlaying = false;
            this._updateTtsBtn();
            // 自动翻页续读
            if (this._ttsAuto) {
                this.next();
                setTimeout(() => {
                    if (!this.ttsPlaying && this._ttsAuto) this.ttsStart();
                }, 800);
            }
        };
        this._ttsUtter = u;
        this.ttsPlaying = true;
        this._ttsAuto = true;
        this._updateTtsBtn();
        speechSynthesis.speak(u);
    },

    ttsStop() {
        this._ttsAuto = false;
        speechSynthesis.cancel();
        this.ttsPlaying = false;
        this._ttsUtter = null;
        this._updateTtsBtn();
    },

    _updateTtsBtn() {
        const btn = document.getElementById('tts-toggle');
        if (btn) {
            btn.textContent = this.ttsPlaying ? '\u23F9 停止朗读' : '\u25B6 朗读';
            btn.classList.toggle('tts-active', this.ttsPlaying);
        }
    },

    _currentPageText() {
        const iframe = this.container.querySelector('iframe');
        if (!iframe || !iframe.contentDocument) return '';
        const doc = iframe.contentDocument;
        // 优先取正文区域，兜底取全 body
        const main = doc.querySelector('body');
        return main ? main.innerText.trim() : '';
    },

    next() {
        if (this.rendition) this.rendition.next();
    },

    prev() {
        if (this.rendition) this.rendition.prev();
    },

    go(href) {
        if (this.rendition) this.rendition.display(href);
    },
};

document.addEventListener('DOMContentLoaded', () => CompanionReader.boot());
window.CompanionReader = CompanionReader;
