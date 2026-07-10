import './reader';
import './mindmap';

// 全局主题控制：浅色 / 护眼 / 深色。
// 通过 data-theme + .dark 双管驱动（覆盖 Tailwind 颜色变量），
// 并广播 companion:theme 事件，供阅读器把护眼样式注入 epub iframe。
window.CompanionTheme = {
    set(theme) {
        const h = document.documentElement;
        h.setAttribute('data-theme', theme);
        if (theme === 'dark') h.classList.add('dark');
        else h.classList.remove('dark');
        try { localStorage.setItem('companion.theme', theme); } catch (e) {}
        window.dispatchEvent(new CustomEvent('companion:theme', { detail: theme }));
    },
    get() {
        try { return localStorage.getItem('companion.theme') || 'light'; } catch (e) { return 'light'; }
    },
};
