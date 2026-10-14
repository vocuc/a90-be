<?php

namespace App\Services\TikTok;

use App\Enums\OutputStatus;
use App\Enums\TikTokPostStatus;
use App\Exceptions\BusinessException;
use App\Models\GenerationOutput;
use App\Models\TikTokPost;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MediaStorage;
use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Duyệt và đăng": lưu chỉnh sửa, chuẩn bị ảnh JPG/WEBP trên tên miền đã xác minh, gọi content/init (Direct Post, PHOTO).
 * Kết quả đăng do webhook TikTok báo về (TikTokWebhookService).
 */
class TikTokPublisher
{
    /** Lỗi content/init -> [HTTP status, thông báo tiếng Việt]. */
    private const INIT_ERRORS = [
        'spam_risk_too_many_posts' => [429, 'Tài khoản đã đạt giới hạn đăng bài trong ngày, thử lại sau.'],
        'spam_risk_user_banned_from_posting' => [403, 'Tài khoản TikTok này đang bị hạn chế đăng bài.'],
        'reached_active_user_cap' => [429, 'TikTok đang giới hạn số người dùng đăng qua A51, thử lại sau.'],
        'rate_limit_exceeded' => [429, 'Gửi yêu cầu lên TikTok quá nhanh, vui lòng thử lại sau ít phút.'],
        'unaudited_client_can_only_post_to_private_accounts' => [403, 'Ứng dụng A51 chưa được TikTok duyệt: chỉ đăng được lên tài khoản riêng tư với quyền "Chỉ mình tôi".'],
        'privacy_level_option_mismatch' => [422, 'Quyền riêng tư không hợp lệ với tài khoản TikTok này, vui lòng chọn lại.'],
        'url_ownership_unverified' => [422, 'Tên miền chứa ảnh chưa được xác minh với TikTok.'],
        'invalid_params' => [422, 'Dữ liệu bài đăng không hợp lệ với TikTok.'],
    ];

    private const JPEG_QUALITY = 90;

    public function __construct(
        private TikTokClient $client,
        private TikTokTokenManager $tokens,
        private TikTokAccountService $accounts,
        private TikTokPostService $posts,
        private MediaStorage $media,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{title: string, content: ?string, hashtags: string[], privacy_level: string, allow_comment: bool, auto_add_music: bool, images: array<int, array>}  $data
     */
    public function publish(User $user, TikTokPost $post, array $data): TikTokPost
    {
        $this->posts->assertEditable($post);

        $account = $user->tiktokAccount;
        if (! $account?->isUsable()) {
            throw BusinessException::tiktokNotConnected($account ? 'expired' : 'not_connected');
        }

        $hashtags = TikTokPost::normalizeHashtags($data['hashtags'] ?? []);
        if (mb_strlen(TikTokPost::description($data['content'] ?? null, $hashtags)) > TikTokPost::CONTENT_MAX) {
            throw ValidationException::withMessages(['content' => 'Nội dung kèm hashtag tối đa '.TikTokPost::CONTENT_MAX.' ký tự.']);
        }

        $images = $this->resolveImages($user, $post, $data['images']);

        // Lấy mới creator_info ngay trước khi đăng (TikTok yêu cầu): kiểm tra quyền riêng tư và được phép đăng
        $creator = $this->accounts->creator($account);
        if (! $creator['can_post']) {
            throw new BusinessException('TIKTOK_CANNOT_POST', $creator['cannot_post_reason']['message'], 409, ['reason' => $creator['cannot_post_reason']['code']]);
        }
        if (! in_array($data['privacy_level'], $creator['privacy_level_options'], true)) {
            throw ValidationException::withMessages(['privacy_level' => 'Quyền riêng tư không có trong lựa chọn của tài khoản TikTok này.']);
        }
        $allowComment = ! $creator['comment_disabled'] && $data['allow_comment'];

        // Chuyển sang publishing trước khi gọi TikTok để bấm đăng 2 lần không đăng 2 bài
        $post = DB::transaction(function () use ($post, $data, $hashtags, $allowComment, $images, $account, $creator) {
            $post = TikTokPost::query()->lockForUpdate()->findOrFail($post->id);
            $this->posts->assertEditable($post);

            $post->update([
                'title' => $data['title'],
                'content' => $data['content'] ?? '',
                'hashtags' => $hashtags,
                'privacy_level' => $data['privacy_level'],
                'allow_comment' => $allowComment,
                'auto_add_music' => $data['auto_add_music'],
                'status' => TikTokPostStatus::Publishing,
                'last_error' => null,
                'tiktok_account_id' => $account->id,
                'tiktok_open_id' => $account->open_id,
                'tiktok_username' => $creator['username'],
                'tiktok_publish_id' => null,
                'tiktok_post_id' => null,
                'tiktok_post_url' => null,
            ]);
            $this->syncImages($post, $images);

            return $post;
        });

        try {
            $urls = array_map(fn (array $image) => $this->prepareImage($post, $image['output']), $images);
            $publishId = $this->tokens->call($account, fn (string $token) => $this->client->initPhotoPost($token, [
                'post_info' => [
                    'title' => $post->title,
                    'description' => TikTokPost::description($post->content, $post->hashtags ?? []),
                    'privacy_level' => $post->privacy_level,
                    'disable_comment' => ! $post->allow_comment,
                    'auto_add_music' => $post->auto_add_music,
                    // Bỏ mục "Công khai nội dung quảng cáo" ở frontend: luôn gửi false
                    'brand_content_toggle' => false,
                    'brand_organic_toggle' => false,
                ],
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'photo_cover_index' => 0,
                    'photo_images' => $urls,
                ],
                'post_mode' => 'DIRECT_POST',
                'media_type' => 'PHOTO',
            ]));
        } catch (Throwable $e) {
            $error = $this->toBusinessException($e);
            $post->update([
                'status' => TikTokPostStatus::PendingReview,
                'last_error' => ['code' => $error->errorCode, 'message' => $error->getMessage()],
            ]);
            if ($error->errorCode === 'TIKTOK_INTERNAL_ERROR') {
                report($e);
            }
            throw $error;
        }

        $post->update(['tiktok_publish_id' => $publishId]);
        $this->audit->log('tiktok_post.publish', $post, ['publish_id' => $publishId, 'images' => count($images)], $user->id);

        return $post;
    }

    /**
     * Thứ tự cuối cùng: { image_id } (ảnh đang có trong bài) hoặc { generation_id, output_id } (ảnh có sẵn vừa thêm).
     *
     * @return array<int, array{image_id: ?int, output: GenerationOutput}> theo thứ tự (image_id null: ảnh mới thêm)
     */
    private function resolveImages(User $user, TikTokPost $post, array $items): array
    {
        $current = $post->images()->with('output')->get()->keyBy('id');
        $newRefs = [];
        $errors = [];
        $resolved = [];

        foreach ($items as $i => $item) {
            if (isset($item['image_id'])) {
                $image = $current->get((int) $item['image_id']);
                if (! $image) {
                    $errors["images.{$i}"] = 'Ảnh không còn trong bài đăng.';

                    continue;
                }
                $resolved[$i] = ['image_id' => $image->id, 'output' => $image->output];
            } else {
                $newRefs[$i] = ['generation_id' => (int) $item['generation_id'], 'output_id' => (int) $item['output_id']];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($newRefs ? $this->posts->resolveOwnedOutputs($user, $newRefs, 'images') : [] as $i => $output) {
            $resolved[$i] = ['image_id' => null, 'output' => $output];
        }
        ksort($resolved);

        $outputIds = array_map(fn ($r) => $r['output']->id, $resolved);
        if (count($outputIds) !== count(array_unique($outputIds))) {
            throw ValidationException::withMessages(['images' => 'Có ảnh bị trùng trong bài đăng.']);
        }
        if (collect($resolved)->contains(fn ($r) => $r['output']->status !== OutputStatus::Completed)) {
            throw new BusinessException('IMAGES_NOT_READY', 'Còn ảnh đang tạo hoặc bị lỗi. Hãy chờ ảnh tạo xong, tạo lại hoặc xoá ảnh lỗi trước khi đăng.', 409);
        }

        return array_values($resolved);
    }

    /** @param  array<int, array{image_id: ?int, output: GenerationOutput}>  $images */
    private function syncImages(TikTokPost $post, array $images): void
    {
        $keep = array_filter(array_column($images, 'image_id'));
        $post->images()->whereNotIn('id', $keep ?: [0])->delete();

        foreach ($images as $i => ['image_id' => $imageId, 'output' => $output]) {
            $imageId
                ? $post->images()->whereKey($imageId)->update(['position' => $i + 1])
                : $post->images()->create(['generation_id' => $output->generation_id, 'output_id' => $output->id, 'position' => $i + 1]);
        }
    }

    /** TikTok chỉ nhận JPG / WEBP qua PULL_FROM_URL: chuyển PNG sang JPG, đặt lên disk của tên miền đã xác minh. */
    private function prepareImage(TikTokPost $post, GenerationOutput $output): string
    {
        $contents = $this->media->get($output->image_path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: $output->mime;

        if ($mime === 'image/webp') {
            $extension = 'webp';
        } elseif ($mime === 'image/jpeg') {
            $extension = 'jpg';
        } else {
            $contents = $this->toJpeg($contents);
            $extension = 'jpg';
        }

        $path = 'tiktok/'.now()->format('Y/m/d')."/{$post->id}/".Str::uuid().'.'.$extension;
        Storage::disk(config('services.tiktok.media_disk'))->put($path, $contents);

        return config('services.tiktok.media_url').'/'.$path;
    }

    private function toJpeg(string $contents): string
    {
        $image = @imagecreatefromstring($contents);
        if (! $image instanceof GdImage) {
            throw new BusinessException('INVALID_IMAGE', 'Không đọc được ảnh để gửi lên TikTok.', 422);
        }

        // JPG không có kênh trong suốt: đặt nền trắng
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagedestroy($image);

        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        imagedestroy($canvas);

        return (string) ob_get_clean();
    }

    private function toBusinessException(Throwable $e): BusinessException
    {
        if ($e instanceof BusinessException) {
            return $e;
        }
        if ($e instanceof TikTokApiException) {
            if ($e->errorCode === 'scope_not_authorized') {
                return BusinessException::tiktokNotConnected('scope_missing');
            }
            [$status, $message] = self::INIT_ERRORS[$e->errorCode] ?? [422, 'TikTok từ chối đăng bài, vui lòng thử lại sau.'];

            return new BusinessException('TIKTOK_PUBLISH_REJECTED', $message, $status, ['tiktok_code' => $e->errorCode]);
        }

        return new BusinessException('TIKTOK_INTERNAL_ERROR', 'Không chuẩn bị được bài đăng, vui lòng thử lại.', 500);
    }
}
