<?php

namespace App\Services;

use App\Enums\CreativeStatus;
use App\Exceptions\BusinessException;
use App\Models\AiCreative;
use App\Models\CreatorProfile;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreativeService
{
    private const CONFIG_FIELDS = ['input_schema', 'prompt_template', 'max_outputs', 'default_aspect_ratio'];

    public function __construct(
        private SettingService $settings,
        private AuditLogger $audit,
        private MediaStorage $media,
    ) {}

    public function create(User $creator, array $data): AiCreative
    {
        $data = $this->prepareTemplate($data);

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
        $data = $this->prepareTemplate($data);

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
        $creative->loadMissing(['config', 'model']);
        $reasons = [];

        if (! $creative->model?->is_active) {
            $reasons[] = 'Model AI không còn hoạt động.';
        }
        if (trim((string) $creative->config?->prompt_template) === '') {
            $reasons[] = 'Chưa viết Prompt Template.';
        }
        if (! collect($creative->config?->input_schema ?? [])->contains('type', 'image')) {
            $reasons[] = 'Template cần ít nhất 1 trường ảnh.';
        }
        if (! $creative->images()->exists()) {
            $reasons[] = 'Cần ít nhất 1 ảnh mẫu.';
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
            'published_at' => $creative->published_at ?? now(),
        ]);
        $this->refreshCreatorCount($creative->creator_id);
        $this->audit->log('creative.publish', $creative);

        return $creative;
    }

    public function pause(AiCreative $creative): AiCreative
    {
        if ($creative->status !== CreativeStatus::Published) {
            throw BusinessException::invalidState('Chỉ tạm dừng được AI Creative đang xuất bản.');
        }

        $creative->update(['status' => CreativeStatus::Paused]);
        $this->refreshCreatorCount($creative->creator_id);
        $this->audit->log('creative.pause', $creative);

        return $creative;
    }

    /** Chỉ xóa cứng được bản nháp chưa từng tạo ảnh. */
    public function delete(AiCreative $creative): void
    {
        if ($creative->status !== CreativeStatus::Draft || $creative->generations()->exists()) {
            throw BusinessException::invalidState('Chỉ xóa được bản nháp chưa tạo ảnh. Creative đang bán thì hãy tạm dừng (pause).');
        }

        $paths = $creative->images()->get(['path', 'thumb_path'])
            ->flatMap(fn ($image) => [$image->path, $image->thumb_path]);
        DB::transaction(function () use ($creative) {
            $this->audit->log('creative.delete', $creative, ['title' => $creative->title]);
            $creative->delete();
        });
        $paths->each(fn ($path) => $this->media->delete($path));
    }

    /** Admin ẩn Creative khỏi Marketplace (không đổi trạng thái của Creator). */
    public function hide(AiCreative $creative, ?string $reason = null): AiCreative
    {
        if ($creative->hidden_at === null) {
            $creative->update(['hidden_at' => now()]);
            $this->audit->log('admin.creative.hide', $creative, ['reason' => $reason]);
        }

        return $creative;
    }

    public function unhide(AiCreative $creative): AiCreative
    {
        if ($creative->hidden_at !== null) {
            $creative->update(['hidden_at' => null]);
            $this->audit->log('admin.creative.unhide', $creative);
        }

        return $creative;
    }

    /** Chuẩn hóa input_schema; tỉ lệ mặc định lấy từ trường tỉ lệ khung hình. */
    private function prepareTemplate(array $data): array
    {
        if (empty($data['input_schema'])) {
            return $data;
        }

        $data['input_schema'] = CreativeTemplate::normalize($data['input_schema']);
        $data['default_aspect_ratio'] = CreativeTemplate::aspectField($data['input_schema'])['default_value'];

        return $data;
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
