<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\LedgerType;
use App\Enums\OutputStatus;
use App\Enums\TransactionStatus;
use App\Models\AiCreative;
use App\Models\CreatorProfile;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Chốt kết quả của từng ảnh. Mỗi hàm idempotent: output đã chốt thì không làm gì nữa,
 * nên job chạy lặp cũng không hoàn tiền hay ghi doanh thu 2 lần.
 * Thứ tự khóa: output -> generation -> transaction -> ví.
 */
class GenerationFinalizer
{
    public function __construct(
        private CreditService $credits,
        private RevenueService $revenues,
        private AuditLogger $audit,
    ) {}

    public function markProcessing(int $outputId): ?GenerationOutput
    {
        return DB::transaction(function () use ($outputId) {
            $output = GenerationOutput::query()->lockForUpdate()->find($outputId);
            if (! $output || $output->status->isFinal()) {
                return null;
            }

            $output->update(['status' => OutputStatus::Processing, 'started_at' => $output->started_at ?? now()]);
            Generation::query()
                ->whereKey($output->generation_id)
                ->where('status', GenerationStatus::Queued)
                ->update(['status' => GenerationStatus::Processing]);

            return $output;
        });
    }

    public function complete(int $outputId, string $imagePath, string $mime, int $sizeBytes, ?int $apiKeyId): void
    {
        DB::transaction(function () use ($outputId, $imagePath, $mime, $sizeBytes, $apiKeyId) {
            $output = GenerationOutput::query()->lockForUpdate()->find($outputId);
            if (! $output || $output->status->isFinal()) {
                return;
            }

            $generation = Generation::query()->lockForUpdate()->findOrFail($output->generation_id);

            $output->update([
                'status' => OutputStatus::Completed,
                'image_path' => $imagePath,
                'mime' => $mime,
                'size_bytes' => $sizeBytes,
                'api_key_id' => $apiKeyId,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            if ($output->transaction_id) {
                $transaction = Transaction::query()->lockForUpdate()->findOrFail($output->transaction_id);
                $transaction->update(['status' => TransactionStatus::Completed, 'completed_at' => now()]);
                $revenue = $this->revenues->record($transaction);
                if ($revenue) {
                    $this->audit->log('revenue.create', $revenue, ['amount' => $revenue->amount], $transaction->creator_id);
                }
            }

            // Chỉ ảnh thành công của người mua mới tính lượt sử dụng.
            if ($generation->type === GenerationType::Paid) {
                AiCreative::query()->whereKey($generation->creative_id)->increment('usage_count');
                CreatorProfile::query()->where('user_id', $generation->creator_id)->increment('usage_count');
            }

            $generation->success_count++;
            $this->settle($generation);
        });
    }

    public function fail(int $outputId, string $errorCode, string $errorMessage, ?int $apiKeyId = null): void
    {
        DB::transaction(function () use ($outputId, $errorCode, $errorMessage, $apiKeyId) {
            $output = GenerationOutput::query()->lockForUpdate()->find($outputId);
            if (! $output || $output->status->isFinal()) {
                return;
            }

            $generation = Generation::query()->lockForUpdate()->findOrFail($output->generation_id);

            $output->update([
                'status' => OutputStatus::Failed,
                'api_key_id' => $apiKeyId ?? $output->api_key_id,
                'error_code' => $errorCode,
                'error_message' => Str::limit($errorMessage, 490),
                'finished_at' => now(),
            ]);

            if ($output->transaction_id) {
                $transaction = Transaction::query()->lockForUpdate()->findOrFail($output->transaction_id);
                $this->refund($transaction, $errorCode);
            }

            $generation->failed_count++;
            $this->settle($generation);
        });
    }

    private function refund(Transaction $transaction, string $reason): void
    {
        if ($transaction->status === TransactionStatus::Refunded) {
            return;
        }

        $wallet = $this->credits->lockWallet($transaction->user_id);
        $this->credits->apply($wallet, $transaction->amount, LedgerType::GenerationRefund, "refund:tx:{$transaction->id}", 'transaction', $transaction->id, 'Hoàn Credit do tạo ảnh thất bại');

        $transaction->update([
            'status' => TransactionStatus::Refunded,
            'failure_reason' => $reason,
            'refunded_at' => now(),
        ]);

        $this->audit->log('credit.refund', $transaction, ['amount' => $transaction->amount, 'reason' => $reason], $transaction->user_id);
    }

    private function settle(Generation $generation): void
    {
        if ($generation->success_count + $generation->failed_count >= $generation->quantity) {
            $generation->status = $generation->success_count > 0 ? GenerationStatus::Completed : GenerationStatus::Failed;
            $generation->completed_at = now();
        } else {
            $generation->status = GenerationStatus::Processing;
        }

        $generation->save();
    }
}
