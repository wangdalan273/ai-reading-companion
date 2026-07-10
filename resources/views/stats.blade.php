<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-zinc-800 dark:text-zinc-100 leading-tight">
            <span class="gradient-text">阅读统计</span>
        </h2>
    </x-slot>

    <div class="py-4">
        <livewire:stats />
    </div>
</x-app-layout>
