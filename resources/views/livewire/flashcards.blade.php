<?php

use App\Models\Flashcard;
use Livewire\Volt\Component;

new class extends Component
{
    public $dueCount = 0;
    public $totalCards = 0;

    public function mount(): void
    {
        $userId = auth()->id();
        $this->dueCount = Flashcard::where('user_id', $userId)
            ->where('due_date', '<=', now()->toDateString())
            ->count();
        $this->totalCards = Flashcard::where('user_id', $userId)->count();
    }
};

?>

<div class="space-y-5"
     x-data="flashcardReview({
        csrf: '{{ csrf_token() }}',
        dueCount: {{ $dueCount }},
        totalCards: {{ $totalCards }}
     })"
     @keydown.window="if(!loading && currentIndex < cards.length && cards.length>0){
        if($event.key===' '||$event.key==='Enter'){flipped=!flipped;$event.preventDefault();}
        else if($event.key==='1'){review(false);}
        else if($event.key==='2'){review(true);}
     }">

    <!-- 概览瓦片 -->
    <div class="grid grid-cols-3 gap-4">
        <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-900/30">🃏</div>
            <div>
                <div class="text-xl font-bold leading-none text-zinc-800 dark:text-zinc-100" x-text="cards.length"></div>
                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">本轮待复习</div>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30">⏳</div>
            <div>
                <div class="text-xl font-bold leading-none text-zinc-800 dark:text-zinc-100" x-text="Math.max(0, cards.length - currentIndex)"></div>
                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">剩余</div>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">📚</div>
            <div>
                <div class="text-xl font-bold leading-none text-zinc-800 dark:text-zinc-100" x-text="totalCards"></div>
                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">总闪卡</div>
            </div>
        </div>
    </div>

    <!-- 复习面板 -->
    <section class="mx-auto max-w-2xl rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <!-- 加载中 -->
        <div x-show="loading" class="flex flex-col items-center justify-center py-16">
            <div class="inline-block h-8 w-8 animate-spin rounded-full border-2 border-primary-300 border-t-primary-600"></div>
            <p class="mt-3 text-sm text-zinc-500">正在加载闪卡…</p>
        </div>

        <!-- 空状态 -->
        <div x-show="!loading && cards.length === 0" class="flex flex-col items-center justify-center py-16 text-center">
            <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary-50 text-3xl dark:bg-primary-900/30">🎉</div>
            <p class="text-zinc-600 dark:text-zinc-300" x-text="finished ? '今日复习完成！' : '暂无到期闪卡'"></p>
            <p class="mt-1 text-sm text-zinc-400" x-show="!finished">在阅读时选中文字 → 点「🃏 闪卡」即可创建复习卡。</p>
            <p class="mt-1 text-sm text-zinc-400" x-show="finished">坚持复习，让读过变记住！</p>
        </div>

        <!-- 卡片复习 -->
        <div x-show="!loading && currentIndex < cards.length && cards.length > 0" class="space-y-4 p-6">
            <!-- 进度 -->
            <div class="flex items-center justify-between text-sm text-zinc-400">
                <span x-text="(currentIndex + 1) + ' / ' + cards.length"></span>
                <span x-text="'盒 ' + currentCard().box"></span>
            </div>
            <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                <div class="h-full bg-primary-500 transition-all duration-300"
                     :style="'width: ' + ((currentIndex / cards.length) * 100) + '%'"></div>
            </div>

            <!-- 闪卡卡片 -->
            <div class="flashcard min-h-[300px]" @click="flipped = !flipped"
                 :class="{ 'flipped': flipped }">
                <div class="flashcard-inner min-h-[300px]">
                    <!-- 正面：金句 -->
                    <div class="flashcard-face flex min-h-[300px] items-center justify-center rounded-2xl border border-zinc-200 bg-white p-8 dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="text-center">
                            <p class="mb-3 text-xs text-zinc-400">点击卡片查看出处</p>
                            <p class="text-lg leading-relaxed text-zinc-800 dark:text-zinc-100" x-text="currentCard().front"></p>
                        </div>
                    </div>
                    <!-- 背面：书名 -->
                    <div class="flashcard-face flashcard-back flex min-h-[300px] items-center justify-center rounded-2xl border border-primary-200 bg-primary-50 p-8 dark:border-primary-800 dark:bg-primary-900/20">
                        <div class="text-center">
                            <p class="mb-2 text-xs text-primary-500">出处</p>
                            <p class="text-lg font-medium text-zinc-800 dark:text-zinc-100" x-text="currentCard().back"></p>
                            <p class="mt-3 text-xs text-zinc-400" x-text="currentCard().book?.title || ''"></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 操作按钮 -->
            <div class="flex justify-center gap-3">
                <button @click="review(false)"
                    class="flex-1 rounded-xl border border-red-200 bg-red-50 px-8 py-3 text-sm font-medium text-red-600 transition hover:bg-red-100 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400 sm:flex-none">
                    😅 不认识
                </button>
                <button @click="review(true)"
                    class="flex-1 rounded-xl border border-green-200 bg-green-50 px-8 py-3 text-sm font-medium text-green-600 transition hover:bg-green-100 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400 sm:flex-none">
                    😊 认识
                </button>
            </div>
            <p class="text-center text-xs text-zinc-400">「认识」延长下次复习间隔，「不认识」明天再练。键盘：<kbd class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">空格</kbd>翻面 · <kbd class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">1</kbd>不认识 · <kbd class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">2</kbd>认识</p>
        </div>
    </section>

    <!-- 快捷入口 -->
    <div class="flex flex-wrap justify-center gap-2">
        <a href="{{ route('dashboard') }}" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-primary-700">回到书架</a>
        <a href="{{ route('stats') }}" class="rounded-lg border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">阅读统计</a>
    </div>
</div>

<script>
function flashcardReview(cfg) {
    return {
        cards: [],
        currentIndex: 0,
        flipped: false,
        loading: true,
        finished: false,
        totalCards: cfg.totalCards,

        async init() {
            await this.loadCards();
        },

        async loadCards() {
            this.loading = true;
            try {
                const res = await fetch('/api/flashcards/due', {
                    headers: { 'X-CSRF-TOKEN': cfg.csrf }
                });
                const data = await res.json();
                this.cards = data.cards || [];
            } catch (e) {
                console.error('Failed to load flashcards', e);
            } finally {
                this.loading = false;
            }
        },

        currentCard() {
            return this.cards[this.currentIndex] || {};
        },

        async review(known) {
            if (this.currentIndex >= this.cards.length) return;
            const card = this.currentCard();
            try {
                await fetch('/api/flashcards/' + card.id + '/review', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': cfg.csrf,
                    },
                    body: JSON.stringify({ known: known }),
                });
            } catch (e) { /* silent */ }
            this.currentIndex++;
            this.flipped = false;
            if (this.currentIndex >= this.cards.length) {
                this.finished = true;
                this.totalCards = Math.max(0, this.totalCards - this.currentIndex);
            }
        },
    };
}
</script>
