<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-zinc-800 dark:text-zinc-100 leading-tight">
                我的<span class="gradient-text">书架</span>
            </h2>
            <div class="flex items-center gap-2">
                <a href="{{ route('knowledge-base', ['tab' => 'graph']) }}"
                   class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 dark:border-zinc-700 dark:text-zinc-200 hover:border-primary-400 hover:text-primary-600">
                    🕸 知识库图谱
                </a>
                <a href="{{ route('knowledge-base', ['tab' => 'rag']) }}"
                   class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white shadow hover:bg-primary-700">
                    🧠 记忆 / 检索
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            <livewire:dashboard />
        </div>
    </div>
</x-app-layout>
