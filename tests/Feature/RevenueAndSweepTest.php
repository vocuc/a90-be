<?php

namespace Tests\Feature;

use App\Enums\OutputStatus;
use App\Models\GenerationOutput;
use Tests\TestCase;

class RevenueAndSweepTest extends TestCase
{
    public function test_images_no_worker_claims_stay_queued(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $buyer = $this->buyer();

        $this->generate($buyer, $creative, quantity: 2)->assertStatus(202);

        // Không tự thất bại dù chờ lâu: người dùng tự hủy ở trang lô
        $this->travel(1)->days();
        $this->artisan('generations:sweep-stuck')->assertSuccessful();

        $this->assertSame(2, GenerationOutput::query()->where('status', OutputStatus::Queued)->count());
    }

    public function test_expired_lease_goes_back_to_queue_for_another_worker(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $this->generate($this->buyer(), $creative)->assertStatus(202);
        $dead = $this->workerToken('dead');
        $alive = $this->workerToken('alive');

        $jobId = $this->claimJob($dead)->json('data.id');

        // Còn trong 15 phút: chưa giao lại.
        $this->travel(14)->minutes();
        $this->artisan('generations:sweep-stuck')->assertSuccessful();
        $this->claimJob($alive)->assertNoContent();

        $this->travel(2)->minutes();
        $this->artisan('generations:sweep-stuck')->assertSuccessful();
        $output = GenerationOutput::query()->findOrFail($jobId);
        $this->assertSame(OutputStatus::Queued, $output->status);
        $this->assertNull($output->worker_id);

        $this->claimJob($alive)->assertOk()->assertJsonPath('data.id', $jobId);
        $this->assertSame(2, $output->fresh()->attempts);

        // Worker cũ gửi kết quả muộn: bị từ chối, worker mới vẫn hoàn thành bình thường.
        $this->completeJob($dead, $jobId)->assertStatus(409)->assertJsonPath('code', 'JOB_NOT_ASSIGNED');
        $this->completeJob($alive, $jobId)->assertOk();
    }

    public function test_image_fails_after_three_expired_leases(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $buyer = $this->buyer();
        $this->generate($buyer, $creative)->assertStatus(202);
        $token = $this->workerToken();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $jobId = $this->claimJob($token)->assertOk()->json('data.id');
            $this->assertSame($attempt, GenerationOutput::query()->findOrFail($jobId)->attempts);
            $this->travel(16)->minutes();
            $this->artisan('generations:sweep-stuck')->assertSuccessful();
        }

        $output = GenerationOutput::query()->firstOrFail();
        $this->assertSame(OutputStatus::Failed, $output->status);
        $this->assertSame('TIMEOUT', $output->error_code);
        $this->assertSame(3, $output->attempts);
        $this->claimJob($token)->assertNoContent();
    }
}
