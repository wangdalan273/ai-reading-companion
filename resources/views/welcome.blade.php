<!DOCTYPE html>
<html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <!-- 主题防闪：首屏绘制前应用已保存的主题（浅色/护眼/深色） -->
        <script>
            (function () {
                try {
                    var t = localStorage.getItem('companion.theme') || 'light';
                    var h = document.documentElement;
                    h.setAttribute('data-theme', t);
                    if (t === 'dark') { h.classList.add('dark'); } else { h.classList.remove('dark'); }
                } catch (e) {}
            })();
        </script>

        <title>AI 伴读 · 边读边问的读书伴侣</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts & styles -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-white text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100 selection:bg-primary-200 selection:text-primary-900">

        <!-- 温润书香装饰光晕 -->
        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
            <div class="absolute -top-32 -right-24 h-96 w-96 rounded-full bg-primary-300/30 blur-3xl"></div>
            <div class="absolute top-1/3 -left-24 h-80 w-80 rounded-full bg-primary-200/30 blur-3xl"></div>
        </div>

        <!-- 顶栏 -->
        <header class="sticky top-0 z-30 border-b border-zinc-200/60 bg-white/70 backdrop-blur dark:border-zinc-800/60 dark:bg-zinc-950/70">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-5 py-3">
                <a href="/" class="flex items-center gap-2 font-semibold text-zinc-900 dark:text-zinc-100">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary-600 text-white">📖</span>
                    <span>AI 伴读</span>
                </a>

                <div class="flex items-center gap-3">
                    <!-- 三档主题切换（浅色 / 护眼 / 深色） -->
                    <div class="flex items-center rounded-full border border-zinc-200 p-0.5 text-xs dark:border-zinc-700" role="group" aria-label="主题切换">
                        <button type="button" onclick="window.CompanionTheme.set('light')" data-theme-btn="light" class="px-2.5 py-1 rounded-full text-zinc-500 dark:text-zinc-400 transition">浅色</button>
                        <button type="button" onclick="window.CompanionTheme.set('sepia')" data-theme-btn="sepia" class="px-2.5 py-1 rounded-full text-zinc-500 dark:text-zinc-400 transition">护眼</button>
                        <button type="button" onclick="window.CompanionTheme.set('dark')" data-theme-btn="dark" class="px-2.5 py-1 rounded-full text-zinc-500 dark:text-zinc-400 transition">深色</button>
                    </div>

                    @auth
                        <a href="{{ url('/dashboard') }}" class="rounded-lg bg-primary-600 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-primary-700">进入书架</a>
                    @else
                        <a href="{{ route('register') }}" class="rounded-lg bg-primary-600 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-primary-700">注册</a>
                        <a href="{{ route('login') }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800">登录</a>
                    @endauth
                </div>
            </div>
        </header>

        <main>
            <!-- 主视觉 -->
            <section class="relative mx-auto max-w-6xl px-5 pb-12 pt-16 text-center lg:pt-24">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-primary-200 bg-primary-50 px-3 py-1 text-xs font-medium text-primary-700 dark:border-primary-800 dark:bg-primary-900/30 dark:text-primary-300">
                    ✨ 导入即读 · 选中即问 · 划线即存
                </span>

                <h1 class="mx-auto mt-6 max-w-3xl text-4xl font-bold leading-tight tracking-tight sm:text-5xl lg:text-6xl">
                    把 <span class="gradient-text">AI 伴读</span> 放在手边，<br class="hidden sm:block" />读书不再是孤读
                </h1>

                <p class="mx-auto mt-5 max-w-2xl text-base text-zinc-600 dark:text-zinc-300 sm:text-lg">
                    导入 PDF / EPUB，在书里随手划线、选中句子就能问 AI；支持<strong class="text-primary-700 dark:text-primary-300">护眼模式</strong>降低蓝光，读累了也温润舒适。读到的金句一键导出到 Obsidian，沉淀成你的知识库。
                </p>

                <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                    @auth
                        <a href="{{ url('/dashboard') }}" class="rounded-full bg-primary-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-primary-600/20 transition hover:bg-primary-700">进入我的书架</a>
                    @else
                        <a href="{{ route('register') }}" class="rounded-full bg-primary-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-primary-600/20 transition hover:bg-primary-700">免费注册，开始使用 →</a>
                        <a href="{{ route('login') }}" class="rounded-full border border-zinc-300 px-6 py-3 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800">我已有账号</a>
                    @endauth
                </div>
            </section>

            <!-- 功能卡片 -->
            <section class="mx-auto max-w-6xl px-5 pb-20">
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="luxury-glass rounded-2xl p-5">
                        <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-primary-50 text-2xl dark:bg-primary-900/30">📥</div>
                        <h3 class="font-semibold text-zinc-800 dark:text-zinc-100">导入即读</h3>
                        <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">支持 PDF 与 EPUB，拖入即上架到书架，几秒就能开始阅读。</p>
                    </div>

                    <div class="luxury-glass rounded-2xl p-5">
                        <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-primary-50 text-2xl dark:bg-primary-900/30">💬</div>
                        <h3 class="font-semibold text-zinc-800 dark:text-zinc-100">选中即问</h3>
                        <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">在书中划选任意句子，一键「问 AI」，旁边陪你读懂难点。</p>
                    </div>

                    <div class="luxury-glass rounded-2xl p-5">
                        <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-primary-50 text-2xl dark:bg-primary-900/30">🌿</div>
                        <h3 class="font-semibold text-zinc-800 dark:text-zinc-100">护眼模式</h3>
                        <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">暖色纸质主题降低蓝光，长读不刺眼，温柔呵护双眼。</p>
                    </div>

                    <div class="luxury-glass rounded-2xl p-5">
                        <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-primary-50 text-2xl dark:bg-primary-900/30">🗂️</div>
                        <h3 class="font-semibold text-zinc-800 dark:text-zinc-100">导出沉淀</h3>
                        <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">划线笔记一键导出 Markdown，直推 Obsidian 成为你的知识库。</p>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-zinc-200 py-8 text-center text-sm text-zinc-400 dark:border-zinc-800">
            AI 伴读 · 让每一次阅读都有人陪你读懂
        </footer>

        @livewireScripts
        @fluxScripts

        <script>
            // 高亮当前主题按钮
            (function () {
                function paint() {
                    var t = window.CompanionTheme ? window.CompanionTheme.get() : 'light';
                    document.querySelectorAll('[data-theme-btn]').forEach(function (b) {
                        var on = b.getAttribute('data-theme-btn') === t;
                        b.classList.toggle('bg-primary-600', on);
                        b.classList.toggle('text-white', on);
                        b.classList.toggle('text-zinc-500', !on);
                        b.classList.toggle('dark:text-zinc-400', !on);
                    });
                }
                paint();
                window.addEventListener('companion:theme', paint);
                document.addEventListener('DOMContentLoaded', paint);
            })();
        </script>
    </body>
</html>
