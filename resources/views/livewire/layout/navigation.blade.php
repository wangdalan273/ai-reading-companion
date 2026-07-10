<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/');
    }
}; ?>

<!-- 左图标栏（B 布局）：桌面常驻可收起，移动端为抽屉。
     railCollapsed / mobileNav 由 app.blade 的祖先 x-data 提供。 -->
<aside
    class="z-50 flex flex-col border-r border-zinc-200 bg-zinc-50 transition-[width,transform] duration-200 ease-out dark:border-zinc-800 dark:bg-zinc-900
           fixed inset-y-0 left-0 w-[248px] -translate-x-full
           lg:static lg:w-60 lg:translate-x-0"
    :class="(railCollapsed ? 'lg:w-[76px]' : 'lg:w-[224px]') + (mobileNav ? ' translate-x-0' : ' -translate-x-full lg:translate-x-0')"
    x-cloak>

    <!-- 品牌 + 收起按钮 -->
    <div class="flex h-16 shrink-0 items-center gap-2 border-b border-zinc-200 px-3 dark:border-zinc-800">
        <a href="{{ route('dashboard') }}" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary-600 text-white shadow-sm">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                <path d="M5 4h6v15H6a2 2 0 01-1-2z" />
                <path d="M19 4h-6v15h5a2 2 0 001-2z" />
            </svg>
        </a>
        <span class="whitespace-nowrap font-semibold text-zinc-800 dark:text-zinc-100" x-show="!railCollapsed">AI 伴读</span>
        <button type="button"
            class="ml-auto hidden h-8 w-8 items-center justify-center rounded-lg text-zinc-400 hover:bg-zinc-200 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 lg:inline-flex"
            @click="railCollapsed = !railCollapsed; localStorage.setItem('companion.rail', railCollapsed ? '1' : '0')"
            :title="railCollapsed ? '展开侧栏' : '收起侧栏'">
            <svg class="h-4 w-4" :class="railCollapsed ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
    </div>

    <!-- 主导航 -->
    <nav class="flex-1 space-y-1 overflow-y-auto px-2 py-3">
        <a href="{{ route('dashboard') }}"
           class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
            <span class="w-6 shrink-0 text-center text-lg">📚</span>
            <span class="whitespace-nowrap" x-show="!railCollapsed">书架</span>
        </a>

        <a href="{{ route('stats') }}"
           class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('stats') ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
            <span class="w-6 shrink-0 text-center text-lg">📊</span>
            <span class="whitespace-nowrap" x-show="!railCollapsed">阅读统计</span>
        </a>

        <a href="{{ route('flashcards') }}"
           class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('flashcards') ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
            <span class="w-6 shrink-0 text-center text-lg">🗂️</span>
            <span class="whitespace-nowrap" x-show="!railCollapsed">闪卡复习</span>
        </a>

        <a href="{{ route('knowledge-base') }}"
           class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('knowledge-base','knowledge','rag','highlights') ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
            <span class="w-6 shrink-0 text-center text-lg">🕸️</span>
            <span class="whitespace-nowrap" x-show="!railCollapsed">知识库</span>
        </a>

        <a href="{{ route('companion') }}"
           class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('companion','companion.personas') ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
            <span class="w-6 shrink-0 text-center text-lg">💬</span>
            <span class="whitespace-nowrap" x-show="!railCollapsed">伴读</span>
        </a>

        <div class="my-2 border-t border-zinc-200 dark:border-zinc-800"></div>

        <a href="{{ route('settings.ai') }}"
           class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('settings.ai') ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
            <span class="w-6 shrink-0 text-center text-lg">⚙️</span>
            <span class="whitespace-nowrap" x-show="!railCollapsed">AI 设置</span>
        </a>
    </nav>

    <!-- 底部：账号 -->
    <div class="shrink-0 border-t border-zinc-200 p-2 dark:border-zinc-800">
        <x-dropdown align="right" width="48">
            <x-slot name="trigger">
                <button class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs dark:bg-primary-900/40">👤</span>
                    <span class="min-w-0 flex-1 truncate text-left" x-show="!railCollapsed" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></span>
                    <svg x-show="!railCollapsed" class="h-4 w-4 text-zinc-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                </button>
            </x-slot>

            <x-slot name="content">
                <x-dropdown-link :href="route('profile')">
                    {{ __('账号') }}
                </x-dropdown-link>

                <button wire:click="logout" class="w-full text-start">
                    <x-dropdown-link>
                        {{ __('退出登录') }}
                    </x-dropdown-link>
                </button>
            </x-slot>
        </x-dropdown>
    </div>
</aside>
