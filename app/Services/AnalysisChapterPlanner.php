<?php

namespace App\Services;

use Illuminate\Support\Collection;

class AnalysisChapterPlanner
{
    public function allForSummary(iterable $chapters): Collection
    {
        return collect($chapters)->sortBy('idx')->values();
    }

    /**
     * Keep synchronous LLM work bounded while sampling the beginning, middle,
     * and ending instead of silently analysing only the first chapters.
     */
    public function representative(iterable $chapters, int $limit): Collection
    {
        $ordered = $this->allForSummary($chapters);
        $count = $ordered->count();
        if ($limit <= 0 || $count <= $limit) {
            return $ordered;
        }
        if ($limit === 1) {
            return $ordered->take(1)->values();
        }

        $indexes = collect(range(0, $limit - 1))
            ->map(fn (int $step) => (int) round($step * ($count - 1) / ($limit - 1)))
            ->unique()
            ->values();

        return $indexes->map(fn (int $index) => $ordered[$index])->values();
    }
}
