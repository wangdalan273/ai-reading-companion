<?php

namespace Tests\Unit;

use App\Services\AnalysisChapterPlanner;
use PHPUnit\Framework\TestCase;

class AnalysisChapterPlannerTest extends TestCase
{
    public function test_summary_keeps_every_chapter_in_source_order(): void
    {
        $chapters = collect(range(1, 12))->map(fn (int $idx) => (object) ['idx' => $idx]);

        $planned = (new AnalysisChapterPlanner)->allForSummary($chapters);

        $this->assertSame(range(1, 12), $planned->pluck('idx')->all());
    }

    public function test_bounded_analysis_samples_the_whole_book_including_ending(): void
    {
        $chapters = collect(range(1, 12))->map(fn (int $idx) => (object) ['idx' => $idx]);

        $planned = (new AnalysisChapterPlanner)->representative($chapters, 4);

        $this->assertCount(4, $planned);
        $this->assertSame(1, $planned->first()->idx);
        $this->assertSame(12, $planned->last()->idx);
        $this->assertGreaterThan(1, $planned[1]->idx);
        $this->assertLessThan(12, $planned[2]->idx);
    }
}
