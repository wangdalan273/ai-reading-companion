<?php

use App\Models\ReadingLog;
use Carbon\Carbon;
use Livewire\Volt\Component;

new class extends Component
{
    public $streak = 0;
    public $longest = 0;
    public $totalMinutes = 0;
    public $heatmap = [];
    public $todaySeconds = 0;

    public function mount(): void
    {
        $userId = auth()->id();
        $today = Carbon::today();

        // 近 364 天的阅读记录
        $logs = ReadingLog::where('user_id', $userId)
            ->where('log_date', '>=', $today->copy()->subDays(364))
            ->orderBy('log_date')
            ->get()
            ->keyBy(fn ($l) => $l->log_date->toDateString());

        // 构造热力格数据（364 个格子 = 52 周 × 7 天）
        $this->heatmap = [];
        $start = $today->copy()->subDays(364);
        for ($i = 0; $i < 365; $i++) {
            $date = $start->copy()->addDays($i);
            $key = $date->toDateString();
            $seconds = $logs->has($key) ? $logs[$key]->seconds : 0;
            $level = 0;
            if ($seconds >= 1800) $level = 4;       // 30min+
            elseif ($seconds >= 1200) $level = 3;   // 20min+
            elseif ($seconds >= 600) $level = 2;    // 10min+
            elseif ($seconds > 0) $level = 1;       // any
            $this->heatmap[] = ['date' => $key, 'level' => $level, 'seconds' => $seconds];
        }

        // 当前连读
        $cursor = $today->copy();
        while ($logs->has($cursor->toDateString())) {
            $this->streak++;
            $cursor = $cursor->subDay();
        }

        // 最长连读
        $cur = 0;
        $prev = null;
        foreach ($logs as $key => $log) {
            if ($prev && Carbon::parse($prev)->diffInDays(Carbon::parse($key)) === 1) {
                $cur++;
            } else {
                $cur = 1;
            }
            $this->longest = max($this->longest, $cur);
            $prev = $key;
        }

        $this->totalMinutes = round($logs->sum('seconds') / 60);
        $this->todaySeconds = $logs->has($today->toDateString()) ? $logs[$today->toDateString()]->seconds : 0;
    }
};

?>

<div class="space-y-5">
    <!-- 概览瓦片 -->
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-900/30">🔥</div>
            <div>
                <div class="text-xl font-bold leading-none text-zinc-800 dark:text-zinc-100">{{ $streak }}</div>
                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">当前连读（天）</div>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30">🏆</div>
            <div>
                <div class="text-xl font-bold leading-none text-zinc-800 dark:text-zinc-100">{{ $longest }}</div>
                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">最长连读（天）</div>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-900/30">⏱️</div>
            <div>
                <div class="text-xl font-bold leading-none text-zinc-800 dark:text-zinc-100">{{ $totalMinutes }}</div>
                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">累计阅读（分钟）</div>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-900/30">☀️</div>
            <div>
                <div class="text-xl font-bold leading-none text-zinc-800 dark:text-zinc-100">{{ intdiv($todaySeconds, 60) }}</div>
                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">今日阅读（分钟）</div>
            </div>
        </div>
    </div>

    <!-- 热力图面板 -->
    <section class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <header class="flex items-center justify-between gap-3 border-b border-zinc-200 px-5 py-3 dark:border-zinc-800">
            <div class="flex items-center gap-2">
                <span class="text-base">📈</span>
                <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">阅读热力图</h3>
            </div>
            <div class="flex items-center gap-1 text-xs text-zinc-400">
                <span>少</span>
                <div class="reading-heatmap-cell" data-level="0"></div>
                <div class="reading-heatmap-cell" data-level="1"></div>
                <div class="reading-heatmap-cell" data-level="2"></div>
                <div class="reading-heatmap-cell" data-level="3"></div>
                <div class="reading-heatmap-cell" data-level="4"></div>
                <span>多</span>
            </div>
        </header>
        <div class="p-5">
            <div class="reading-heatmap" style="grid-template-columns: repeat(53, 1fr); gap: 3px; overflow-x: auto;">
                @foreach ($heatmap as $cell)
                    <div class="reading-heatmap-cell" data-level="{{ $cell['level'] }}"
                        title="{{ $cell['date'] }}：{{ intdiv($cell['seconds'], 60) }} 分钟"></div>
                @endforeach
            </div>
            <p class="mt-3 text-center text-xs text-zinc-400">每格代表一天，颜色越深阅读越久。坚持阅读，让格子亮起来！</p>
        </div>
    </section>

    <!-- 快捷入口 -->
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('dashboard') }}" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-primary-700">回到书架</a>
        <a href="{{ route('flashcards') }}" class="rounded-lg border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">闪卡复习</a>
    </div>
</div>
