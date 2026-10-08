<?php

namespace App\Services;

use App\Enums\CreativePauseReason;
use App\Enums\CreativeStatus;
use App\Exceptions\BusinessException;
use App\Models\AiCreative;
use App\Models\AiModel;
use App\Models\CreatorProfile;
use App\Models\User;
use App\Services\AI\ApiKeyRotator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreativeService
{
    private const CONFIG_FIELDS = [
        'system_prompt', 'creative_prompt', 'negative_prompt',
        'max_outputs', 'allowed_aspect_ratios', 'default_aspect_ratio', 'extra_params',
    ];

    public function __construct(
        private ApiKeyRotator $keys,
        private SettingService $settings,
        private AuditLogger $audit,
        private MediaStorage $media,
    ) {}

    public function create(User $creator, array $data): AiCreative
    {
        return DB::transaction(function () use ($creator, $data) {
            $creative = AiCreative::query()->create([
                ...Arr::except($data, self::CONFIG_FIELDS),
                'creator_id' => $creator->id,
                'slug' => $this->makeSlug($data['title']),
                'status' => CreativeStatus::Draft,
            ]);

            $creative->config()->create([
                'max_outputs' => $this->settings->int('generation.default_max_outputs'),
                'default_aspect_ratio' => '1:1',
                ...Arr::only($data, self::CONFIG_FIELDS),
            ]);

            $this->audit->log('creative.create', $creative);

            return $creative;
        });
    }

    public function update(AiCreative $creative, array $data): AiCreative
    {
        if ($creative->status === CreativeStatus::Archived) {
            throw BusinessException::invalidState('Không thể sửa AI Creative đã lưu trữ.');
        }

        DB::transaction(function () use ($creative, $data) {
            $creative->update(Arr::except($data, self::CONFIG_FIELDS));

            $config = Arr::only($data, self::CONFIG_FIELDS);
            if ($config) {
                $creative->config()->updateOrCreate([], $config);
            }

            $this->audit->log('creative.update', $creative, ['fields' => array_keys($data)]);
        });

        return $creative->refresh();
    }

    /** @return string[] lý do chưa đăng được, rỗng nghĩa là đủ điều kiện */
    public function publishBlockers(AiCreative $creative): array
    {
        $creative->loadMissing(['config', 'model.provider']);
        $reasons = [];

        if (! $creative->model?->is_active) {
            $reasons[] = 'Model AI không còn hoạt động.';
        }
        if (trim((string) $creative->config?->creative_prompt) === '') {
            $reasons[] = 'Chưa nhập Creative Prompt.';
        }
        if (! $creative->images()->exists()) {
            $reasons[] = 'Cần ít nhất 1 ảnh mẫu.';
        }
        if ($creative->model && ! $this->keys->hasActiveKey($creative->creator_id, $creative->model->provider_id)) {
            $reasons[] = 'Chưa có API key hoạt động cho '.$creative->model->provider->name.'.';
        }

        return $reasons;
    }

    public function publish(AiCreative $creative): AiCreative
    {
        if (! in_array($creative->status, [CreativeStatus::Draft, CreativeStatus::Paused], true)) {
            throw BusinessException::invalidState('Chỉ đăng được AI Creative ở trạng thái nháp hoặc tạm dừng.');
        }

        if ($reasons = $this->publishBlockers($creative)) {
            throw BusinessException::notPublishable($reasons);
        }

        $creative->update([
            'status' => CreativeStatus::Published,
            'pause_reason' => null,
            'published_at' => $creative->published_at ?? now(),
        ]);
        $this->refreshCreatorCount($creative->creator_id);
        $this->audit->log('creative.publish', $creative);

        return $creative;
    }

    public function pause(AiCreative $creative, CreativePauseReason $reason = CreativePauseReason::Manual): AiCreative
    {
        if ($creative->status !== CreativeStatus::Published) {
            throw BusinessException::invalidState('Chỉ tạm dừng được AI Creative đang xuất bản.');
        }

        $creative->update(['status' => CreativeStatus::Paused, 'pause_reason' => $reason]);
        $this->refreshCreatorCount($creative->creator_id);
        $this->audit->log($reason === CreativePauseReason::Manual ? 'creative.pause' : 'creative.auto_paused', $creative, [
            'reason' => $reason->value,
        ], $reason === CreativePauseReason::Manual ? null : $creative->creator_id);

        return $creative;
    }

    public function archive(AiCreative $creative): AiCreative
    {
        if ($creative->status === CreativeStatus::Archived) {
            return $creative;
        }

        $creative->update(['status' => CreativeStatus::Archived]);
        $this->refreshCreatorCount($creative->creator_id);
        $this->audit->log('creative.archive', $creative);

        return $creative;
    }

    /** Chỉ xóa cứng được bản nháp chưa từng tạo ảnh. */
    public function delete(AiCreative $creative): void
    {
        if ($creative->status !== CreativeStatus::Draft || $creative->generations()->exists()) {
            throw BusinessException::invalidState('Chỉ xóa được bản nháp chưa tạo ảnh. Hãy dùng lưu trữ (archive).');
        }

        $paths = $creative->images()->pluck('path');
        DB::transaction(function () use ($creative) {
            $this->audit->log('creative.delete', $creative, ['title' => $creative->title]);
            $creative->delete();
        });
        $paths->each(fn ($path) => $this->media->delete($path));
    }

    /**
     * Creator không còn key active cho Provider: tạm dừng mọi Creative đang xuất bản dùng Provider đó.
     */
    public function pauseIfNoActiveKey(int $creatorId, int $providerId): int
    {
        if ($this->keys->hasActiveKey($creatorId, $providerId)) {
            return 0;
        }

        $modelIds = AiModel::query()->where('provider_id', $providerId)->pluck('id');
        $creatives = AiCreative::query()
            ->where('creator_id', $creatorId)
            ->where('status', CreativeStatus::Published)
            ->whereIn('model_id', $modelIds)
            ->get();

        foreach ($creatives as $creative) {
            $this->pause($creative, CreativePauseReason::NoValidApiKey);
        }

        return $creatives->count();
    }

    private function refreshCreatorCount(int $creatorId): void
    {
        CreatorProfile::query()->where('user_id', $creatorId)->update([
            'creative_count' => AiCreative::query()
                ->where('creator_id', $creatorId)
                ->where('status', CreativeStatus::Published)
                ->count(),
        ]);
    }

    private function makeSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 170, '') ?: 'creative';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (AiCreative::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
