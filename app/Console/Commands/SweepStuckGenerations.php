<?php

namespace App\Console\Commands;

use App\Enums\OutputStatus;
use App\Models\GenerationOutput;
use App\Services\GenerationFinalizer;
use Illuminate\Console\Command;

/**
 * Lưới an toàn: ảnh bị kẹt (worker chết, job mất) sẽ bị đánh dấu thất bại và hoàn Credit.
 */
class SweepStuckGenerations extends Command
{
    protected $signature = 'generations:sweep-stuck';

    protected $description = 'Đánh dấu thất bại và hoàn Credit cho các ảnh bị kẹt quá lâu';

    public function handle(GenerationFinalizer $finalizer): int
    {
        $processingBefore = now()->subMinutes(config('ai90.generation.stuck_processing_minutes'));
        $queuedBefore = now()->subMinutes(config('ai90.generation.stuck_queued_minutes'));

        $ids = GenerationOutput::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', OutputStatus::Processing)->where('updated_at', '<', $processingBefore))
                ->orWhere(fn ($q) => $q->where('status', OutputStatus::Queued)->where('created_at', '<', $queuedBefore)))
            ->pluck('id');

        foreach ($ids as $id) {
            $finalizer->fail($id, 'TIMEOUT', 'Quá thời gian xử lý.');
        }

        $this->info("Đã xử lý {$ids->count()} ảnh bị kẹt.");

        return self::SUCCESS;
    }
}
