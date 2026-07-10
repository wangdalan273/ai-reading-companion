<!DOCTYPE html>
<html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'AI 伴读') }} · 登录</title>

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

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-gradient-to-b from-primary-50 via-white to-white text-zinc-900 dark:from-zinc-950 dark:via-zinc-950 dark:to-zinc-950 dark:text-zinc-100 selection:bg-primary-200 selection:text-primary-900 min-h-screen">
        <!-- 温润书香装饰光晕 -->
        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
            <div class="absolute -top-32 -right-24 h-96 w-96 rounded-full bg-primary-300/30 blur-3xl"></div>
            <div class="absolute bottom-0 -left-24 h-80 w-80 rounded-full bg-primary-200/30 blur-3xl"></div>
        </div>

        <div class="flex min-h-screen flex-col items-center justify-center px-5 py-10">
            <!-- 品牌 -->
            <a href="/" class="mb-6 flex items-center gap-2 text-xl font-semibold text-zinc-900 dark:text-zinc-100">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-600 text-white text-lg">📖</span>
                <span>AI 伴读</span>
            </a>

            <div class="w-full max-w-md rounded-2xl border border-zinc-200/70 bg-white/80 p-7 shadow-xl shadow-zinc-900/5 backdrop-blur dark:border-zinc-800/70 dark:bg-zinc-900/70">
                {{ $slot }}
            </div>

            <p class="mt-6 text-center text-xs text-zinc-400">让每一次阅读都有人陪你读懂</p>
        </div>

        @livewireScripts
        @fluxScripts

        <script>
            // 高亮当前主题按钮（与全站一致）
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
