<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-zinc-800 dark:text-zinc-100 leading-tight">
            <span class="gradient-text">👥 人物关系</span>
        </h2>
    </x-slot>

    @include('partials.book-analyze.characters')
</x-app-layout>
