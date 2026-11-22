<?php

namespace App\Services\Channel;

use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Models\Channel;
use App\Models\ContentPlan;
use App\Models\ContentPlanItem;
use App\Models\SocialPost;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Làm mới giờ đăng" của 1 kỳ (tab Kế hoạch, cạnh "Dời lịch"; người dùng yêu cầu 2026-11-22): sau khi đổi khung giờ ở
 * Cấu hình, đặt lại giờ các bài của kỳ theo khung giờ hiện tại của kênh.
 *
 * Đơn vị được đổi giờ (đều phải sau now + LEAD_MINUTES), chỉ trong kỳ này:
 * - bài còn nháp, hoặc đã thành bài đăng mà worker chưa nhận;
 * - slot giờ trống của kỳ (giờ "Tạo bổ sung" sau này dùng).
 * Bài đăng khác của kênh + bài nháp của kỳ khác giữ nguyên, giờ mới tránh sát các bài đó.
 * Mỗi đơn vị GIỮ NGÀY, chỉ đổi giờ: trong từng ngày, số đơn vị chia đều vào các khung giờ (dư thì khung đầu nhận thêm),
 * giờ ngẫu nhiên trong phần của nó như ChannelPlanService::computeSlots, giữ thứ tự cũ, cách bài khác >= MIN_GAP_MINUTES.
 * Hôm nay chỉ dùng phần khung giờ còn lại; hết khung thì giữ giờ cũ (đếm vào skipped).
 */
class ChannelRescheduleService
{
    private const EDITABLE = [SocialPostStatus::Generating, SocialPostStatus::PendingReview, SocialPostStatus::Queued];

    public function __construct(private AuditLogger $audit) {}

    /** @return array{moved: int, skipped: int} */
    public function redistribute(ContentPlan $plan): array
    {
        $channel = $plan->channel()->firstOrFail();
        $windows = collect($channel->time_slots ?? [])->sortBy('start')->values();
        if ($windows->isEmpty()) {
            throw new BusinessException('NO_TIME_SLOTS', 'Kênh chưa có khung giờ đăng.', 422);
        }

        return DB::transaction(function () use ($channel, $plan, $windows) {
            Channel::query()->lockForUpdate()->find($channel->id);
            $plan = ContentPlan::query()->lockForUpdate()->findOrFail($plan->id);
            if (! in_array($plan->status, [ContentPlan::DRAFT, ContentPlan::APPROVED], true)) {
                throw new BusinessException('PLAN_CLOSED', 'Chỉ làm mới giờ đăng được kỳ chờ duyệt / đã duyệt.', 409);
            }
            $tz = config('app.timezone');
            $earliest = CarbonImmutable::now()->addMinutes(ChannelPlanService::LEAD_MINUTES);
            $units = $this->units($plan, $earliest);
            if ($units->isEmpty()) {
                return ['moved' => 0, 'skipped' => 0];
            }

            // Giờ cố định: bài đăng khác của kênh + bài nháp (chưa thành bài đăng) của kỳ khác còn mở
            $movingPostIds = $units->pluck('post')->filter()->pluck('id')->all();
            $today = CarbonImmutable::today($tz);
            $fixed = $channel->posts()
                ->whereNotIn('id', $movingPostIds ?: [0])
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '>=', $today)
                ->pluck('scheduled_at')
                ->merge(ContentPlanItem::query()->where('status', ContentPlanItem::DRAFT)->where('plan_id', '!=', $plan->id)
                    ->whereHas('plan', fn ($q) => $q->where('channel_id', $channel->id)->whereIn('status', [ContentPlan::DRAFT, ContentPlan::APPROVED]))
                    ->where('scheduled_at', '>=', $today)->pluck('scheduled_at'))
                ->map(fn ($at) => CarbonImmutable::parse($at)->setTimezone($tz));

            $moved = 0;
            $skipped = 0;
            $changedSlots = [];
            foreach ($units->groupBy(fn ($u) => $u['at']->setTimezone($tz)->toDateString()) as $date => $dayUnits) {
                $day = CarbonImmutable::parse($date, $tz);
                $taken = $fixed->filter(fn ($at) => $at->toDateString() === $date)->values()->all();
                $times = $this->dayTimes($day, $windows, $dayUnits->count(), $earliest, $taken);
                if ($times === null) {
                    $skipped += $dayUnits->count();

                    continue;
                }
                foreach ($dayUnits->sortBy(fn ($u) => $u['at']->getTimestamp())->values() as $i => $unit) {
                    $at = $times[$i];
                    if ($at->eq($unit['at'])) {
                        continue;
                    }
                    $unit['item']?->update(['scheduled_at' => $at]);
                    $unit['post']?->update(['scheduled_at' => $at]);
                    $changedSlots[$unit['slot']] = $at->toIso8601String();
                    $moved++;
                }
            }

            if ($changedSlots) {
                $plan->slots = collect($plan->slots ?? [])
                    ->map(fn ($s) => isset($changedSlots[(int) $s['index']]) ? [...$s, 'at' => $changedSlots[(int) $s['index']]] : $s)
                    ->sortBy('at')->values()->all();
                $plan->save();
            }
            $this->audit->log('content_plan.reset_times', $plan, ['moved' => $moved, 'skipped' => $skipped, 'time_slots' => $windows->all()]);

            return ['moved' => $moved, 'skipped' => $skipped];
        });
    }

    /**
     * @return Collection<int, array{at: CarbonImmutable, item: ?ContentPlanItem, post: ?SocialPost, slot: int}>
     */
    private function units(ContentPlan $plan, CarbonImmutable $earliest): Collection
    {
        $units = collect();
        $usedSlots = [];
        foreach ($plan->items()->with('post')->lockForUpdate()->get() as $item) {
            $post = $item->post;
            $live = $item->status === ContentPlanItem::DRAFT || ($item->status !== ContentPlanItem::CANCELLED && $post);
            $usedSlots[(int) $item->slot_index] = $live;
            $movable = $item->status === ContentPlanItem::DRAFT || ($live && in_array($post->status, self::EDITABLE, true));
            $at = CarbonImmutable::parse($post?->scheduled_at ?? $item->scheduled_at);
            if ($movable && $at->gt($earliest)) {
                $units->push(['at' => $at, 'item' => $item, 'post' => $post, 'slot' => (int) $item->slot_index]);
            }
        }
        // Slot trống (chưa có bài / bài đã huỷ): đổi luôn để "Tạo bổ sung" sau này rơi vào khung mới
        foreach ($plan->slots ?? [] as $slot) {
            $at = CarbonImmutable::parse($slot['at']);
            if (! ($usedSlots[(int) $slot['index']] ?? false) && $at->gt($earliest)) {
                $units->push(['at' => $at, 'item' => null, 'post' => null, 'slot' => (int) $slot['index']]);
            }
        }

        return $units;
    }

    /**
     * $count giờ trong ngày theo khung giờ, tăng dần. Hôm nay: chỉ phần khung còn sau $earliest. null: không còn khung nào.
     *
     * @param  CarbonImmutable[]  $taken
     * @return CarbonImmutable[]|null
     */
    private function dayTimes(CarbonImmutable $day, Collection $windows, int $count, CarbonImmutable $earliest, array $taken): ?array
    {
        $gap = ChannelPlanService::MIN_GAP_MINUTES;
        $ranges = $windows->map(function ($w) use ($day, $earliest) {
            $from = $day->setTimeFromTimeString($w['start']);
            $to = $day->setTimeFromTimeString($w['end']);
            if ($from->lte($earliest)) {
                $from = $earliest->addMinute()->second(0);
            }

            return $to->gt($from) ? [$from, $to] : null;
        })->filter()->values();
        if ($ranges->isEmpty()) {
            return null;
        }

        $per = array_fill(0, $ranges->count(), intdiv($count, $ranges->count()));
        for ($i = 0; $i < $count % $ranges->count(); $i++) {
            $per[$i]++;
        }
        $fits = function (CarbonImmutable $at) use (&$taken, $gap): bool {
            foreach ($taken as $t) {
                if (abs($t->diffInMinutes($at, false)) < $gap) {
                    return false;
                }
            }

            return true;
        };

        $times = [];
        foreach ($ranges as $r => [$from, $to]) {
            $n = $per[$r];
            if ($n === 0) {
                continue;
            }
            $segment = intdiv(max(0, (int) $from->diffInMinutes($to)), $n);
            for ($k = 0; $k < $n; $k++) {
                $segStart = $from->addMinutes($segment * $k);
                $room = max(0, $segment - $gap);
                $at = null;
                // Thử ngẫu nhiên trong phần; không được thì quét từng phút; vẫn không được thì lấy đầu phần
                for ($try = 0; $try < 20 && ! $at; $try++) {
                    $cand = $segStart->addMinutes($room > 0 ? random_int(0, $room) : 0)->second(0);
                    $at = $fits($cand) ? $cand : null;
                }
                for ($m = 0; $m <= $room && ! $at; $m++) {
                    $cand = $segStart->addMinutes($m)->second(0);
                    $at = $fits($cand) ? $cand : null;
                }
                $at ??= $segStart->second(0);
                $taken[] = $at;
                $times[] = $at;
            }
        }
        usort($times, fn ($a, $b) => $a <=> $b);

        return $times;
    }
}
