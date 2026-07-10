@props([
    'icon' => '•',
    'title' => '',
    'desc' => '',
    'state' => 'none',   // done | pending | error | none
    'book' => null,
    'route' => '',
])

@php
    $badge = match($state) {
        'done'    => ['text' => '已生成', 'cls' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'],
        'pending' => ['text' => '生成中', 'cls' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'],
        'error'   => ['text' => '失败', 'cls' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300'],
        default   => ['text' => '未生成', 'cls' => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400'],
    };
    $href = $route && $book ? route($route, $book) : '#';
@endphp

<a href="{{ $href }}"
   class="group rounded-2xl border border-zinc-200 bg-white/80 p-4 text-left transition hover:border-primary-400 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900/80">
    <div class="flex items-start justify-between">
        <div class="text-2xl">{{ $icon }}</div>
        <span class="rounded-full px-2 py-0.5 text-[11px] {{ $badge['cls'] }}">{{ $badge['text'] }}</span>
    </div>
    <div class="mt-2 font-medium text-zinc-800 dark:text-zinc-100">{{ $title }}</div>
    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $desc }}</div>
    <div class="mt-2 text-[11px] text-primary-600 opacity-0 transition group-hover:opacity-100 dark:text-primary-400">
        {{ $state === 'none' ? '去生成 →' : '打开 →' }}
    </div>
</a>
