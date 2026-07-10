<div class="py-8" x-data="{ filter: 'all', async del(id, btn){ try { const r = await fetch('/book/'+btn.dataset.book+'/annotations/'+id, {method:'DELETE', headers:{'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content}}); if(r.ok){ btn.closest('[data-hl-item]').remove(); } else { alert('删除失败，请重试'); } } catch(e){ alert('删除失败，请重试'); } } }">
        <div class="max-w-5xl mx-auto sm:px-4 lg:px-6">

            @if ($annotations->isEmpty())
                <!-- 空态 -->
                <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-zinc-300 bg-white/60 py-20 text-center dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="text-5xl mb-4">📖</div>
                    <p class="text-lg font-medium text-zinc-700 dark:text-zinc-200">还没有划线</p>
                    <p class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">
                        在读一本书时，选中句子 → 点「🖍 划线」，划过的金句会集中出现在这里，随时回看、跳回原文、导出。
                    </p>
                    <a href="{{ route('knowledge-base', ['tab' => 'graph']) }}"
                       class="mt-5 rounded-full bg-primary-600 px-5 py-2 text-sm font-medium text-white shadow hover:bg-primary-700">
                        去看看知识库图谱
                    </a>
                </div>
            @else
                <!-- 说明 -->
                <div class="mb-5 rounded-2xl border border-zinc-200 bg-gradient-to-br from-primary-50/60 to-white p-4 text-sm text-zinc-600 dark:border-zinc-800 dark:from-primary-950/30 dark:to-zinc-900/60 dark:text-zinc-300">
                    这里汇集了你读过的所有书里划出的重点。点任意一条可<b>跳回原文对应位置</b>；也可按书筛选、把整本书的划线导出成 Markdown（可一键写入 Obsidian）。
                </div>

                <!-- 按书筛选 -->
                <div class="mb-6 flex flex-wrap items-center gap-2">
                    <button type="button" @click="filter = 'all'"
                        :class="filter === 'all' ? 'rounded-full bg-primary-600 px-3 py-1.5 text-sm font-medium text-white' : 'rounded-full border border-zinc-300 px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800'">
                        全部书（{{ $annotations->count() }}）
                    </button>
                    @foreach ($books->where('annotations_count', '>', 0) as $b)
                        <button type="button" @click="filter = '{{ $b->id }}'"
                            :class="filter == '{{ $b->id }}' ? 'rounded-full bg-primary-600 px-3 py-1.5 text-sm font-medium text-white' : 'rounded-full border border-zinc-300 px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800'">
                            {{ $b->title }}（{{ $b->annotations_count }}）
                        </button>
                    @endforeach
                </div>

                <!-- 分组列表（服务端已按书分组） -->
                @php $grouped = $annotations->groupBy('book_id'); @endphp
                @foreach ($grouped as $bookId => $items)
                    @php $bk = $items->first()->book; @endphp
                    <section class="mb-8" x-show="filter === 'all' || filter == '{{ $bookId }}'" x-cloak>
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="flex items-center gap-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                                <span>📚</span>
                                <span class="max-w-[60%] truncate">{{ $bk->title ?? '未知书籍' }}</span>
                                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $items->count() }} 条</span>
                            </h3>
                            <a href="{{ route('book.export.preview', ['book' => $bk->id, 'type' => 'markdown']) }}"
                               class="rounded-lg border border-zinc-300 px-2.5 py-1 text-xs text-zinc-600 hover:border-primary-400 hover:text-primary-600 dark:border-zinc-700 dark:text-zinc-300"
                               title="预览并导出本书划线为 Markdown">📤 导出</a>
                        </div>

                        <div class="space-y-2">
                            @foreach ($items as $a)
                                <div data-hl-item class="group flex gap-3 rounded-xl border border-zinc-200 bg-white/80 p-3.5 transition hover:border-primary-400 hover:shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70 dark:hover:border-primary-700">
                                    <div class="mt-0.5 w-1 shrink-0 rounded-full bg-amber-400/80"></div>
                                    <a href="{{ route('read', $a->book_id) }}?hl={{ $a->id }}"
                                       class="min-w-0 flex-1">
                                        <p class="line-clamp-3 text-sm leading-relaxed text-zinc-800 dark:text-zinc-100">{{ str_replace(["\n","\r"], ' ', $a->quote) }}</p>
                                        <div class="mt-1.5 flex items-center gap-2 text-[11px] text-zinc-400">
                                            <span>📍 跳回原文</span>
                                            <span>·</span>
                                            <span>{{ $a->created_at->format('Y-m-d H:i') }}</span>
                                        </div>
                                    </a>
                                    <button type="button" data-book="{{ $a->book_id }}" @click.stop="del({{ $a->id }}, $el)"
                                        class="shrink-0 self-start rounded-lg border border-zinc-200 px-2 py-1 text-xs text-zinc-400 transition hover:border-red-400 hover:text-red-500 dark:border-zinc-700"
                                        title="删除这条划线">🗑 删除</button>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            @endif
        </div>
    </div>
