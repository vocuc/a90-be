<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\VideoTemplateResource;
use App\Models\VideoTemplate;
use App\Models\Worker;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Máy làm template (D:\video-tmp, npm run sync-templates) gửi TOÀN BỘ danh sách template đang có, A51 đồng bộ vào shop
 * worker được gắn (admin -> Worker -> Shop đăng bài):
 *   PUT /api/v1/video-templates/sync { templates: [ { key, name, image_count }, ... ] }
 * - key (ID template trên máy làm template) chưa có -> tạo; có rồi -> cập nhật tên / số ảnh, đang ẩn thì hiện lại.
 * - Template đồng bộ trước đây nhưng không còn trong danh sách -> ẩn (không random / chọn được; bài đã tạo giữ nguyên).
 * - Template shop tự tạo không bị đụng tới.
 * Worker chưa gắn tài khoản tạo video -> 409 VIDEO_ACCOUNT_NOT_ASSIGNED.
 */
class WorkerVideoTemplateController extends Controller
{
    public const MAX_TEMPLATES = 200;

    public function sync(Request $request, AuditLogger $audit): JsonResponse
    {
        /** @var Worker $worker */
        $worker = $request->attributes->get('worker');
        $user = $worker->videoUser;
        if (! $user) {
            throw new BusinessException('VIDEO_ACCOUNT_NOT_ASSIGNED', 'Worker chưa được gắn tài khoản A51 tạo video.', 409);
        }

        $data = $request->validate([
            'templates' => ['present', 'array', 'max:'.self::MAX_TEMPLATES],
            'templates.*.key' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct'],
            'templates.*.name' => ['required', 'string', 'max:150', 'distinct:ignore_case'],
            'templates.*.image_count' => ['required', 'integer', 'min:1', 'max:'.VideoTemplate::MAX_IMAGE_COUNT],
        ]);

        $result = DB::transaction(function () use ($user, $data) {
            $existing = VideoTemplate::query()->where('user_id', $user->id)->whereNotNull('source_key')
                ->lockForUpdate()->get()->keyBy('source_key');
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'hidden' => 0];

            foreach ($data['templates'] as $item) {
                $key = (string) $item['key'];
                $values = ['name' => trim($item['name']), 'image_count' => (int) $item['image_count'], 'hidden_at' => null];
                $template = $existing->get($key);
                if (! $template) {
                    VideoTemplate::query()->create(['user_id' => $user->id, 'source_key' => $key, 'video_url' => null, ...$values]);
                    $counts['created']++;
                    continue;
                }
                $template->fill($values);
                $counts[$template->isDirty() ? 'updated' : 'unchanged']++;
                $template->save();
            }

            $keys = array_map(fn ($item) => (string) $item['key'], $data['templates']);
            $counts['hidden'] = VideoTemplate::query()->where('user_id', $user->id)->whereNotNull('source_key')
                ->whereNotIn('source_key', $keys)->whereNull('hidden_at')
                ->update(['hidden_at' => now()]);

            return $counts;
        });

        $audit->log('video_template.sync', null, ['worker_id' => $worker->id, ...$result], $user->id);

        $templates = VideoTemplate::query()->where('user_id', $user->id)->whereNotNull('source_key')->visible()
            ->orderBy('id')->get();

        return response()->json(['data' => [
            ...$result,
            'account' => ['id' => $user->id, 'name' => $user->name],
            'templates' => VideoTemplateResource::collection($templates),
        ]]);
    }
}
