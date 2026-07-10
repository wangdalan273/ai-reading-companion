import { Transformer } from 'markmap-lib';
import { Markmap } from 'markmap-view';

// P12 — render the book's aggregated mind-map Markdown into an interactive SVG.
// The Markdown lives in a hidden <textarea id="mindmap-source"> (filled by the
// server or by the "generate" button). markmap (MIT) does the layout, so we
// don't reinvent a tree algorithm.
document.addEventListener('DOMContentLoaded', () => {
    const svg = document.getElementById('mindmap-svg');
    const src = document.getElementById('mindmap-source');
    if (!svg || !src) return;

    const md = (src.value || src.textContent || '').trim();
    if (!md) {
        const wrap = svg.parentElement;
        if (wrap) {
            wrap.insertAdjacentHTML(
                'beforeend',
                '<p class="p-6 text-sm text-zinc-400">还没有脑图。点右上角「🤖 生成 / 重新生成脑图」，AI 会逐章总结并聚合成本书结构图。</p>'
            );
        }
        return;
    }

    try {
        const transformer = new Transformer();
        const { root } = transformer.transform(md);
        const primary = getComputedStyle(document.documentElement)
            .getPropertyValue('--primary')
            .trim() || '#6366f1';

        Markmap.create(svg, {
            autoFit: true,
            duration: 300,
            color: () => primary,
            paddingX: 12,
        }, root);
    } catch (e) {
        const wrap = svg.parentElement;
        if (wrap) {
            wrap.insertAdjacentHTML('beforeend', '<p class="p-6 text-sm text-red-500">脑图渲染失败：' + e.message + '</p>');
        }
    }
});
