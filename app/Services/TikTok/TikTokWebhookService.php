<?php

namespace App\Services\TikTok;

use App\Enums\TikTokPostStatus;
use App\Models\TikTokPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sự kiện webhook TikTok (chỉ dùng webhook, không polling publish/status/fetch):
 *   post.publish.complete / post.publish.publicly_available  -> published
 *   post.publish.failed                                      -> pending_review + last_error
 *   authorization.removed                                    -> tài khoản revoked
 * Payload: { client_key, event, create_time, user_openid, content: "<chuỗi JSON>" }.
 */
class TikTokWebhookService
{
    /** fail_reason -> thông báo tiếng Việt. */
    private const FAIL_REASONS = [
        'file_format_check_failed' => 'Định dạng ảnh không được TikTok hỗ trợ.',
        'picture_size_check_failed' => 'Kích thước ảnh không đạt yêu cầu của TikTok.',
        'photo_pull_failed' => 'TikTok không tải được ảnh, vui lòng đăng lại.',
        'video_pull_failed' => 'TikTok không tải được nội dung, vui lòng đăng lại.',
        'publish_cancelled' => 'Bài đăng đã bị huỷ trên TikTok.',
        'spam_risk_too_many_posts' => 'Tài khoản đã đạt giới hạn đăng bài trong ngày, thử lại sau.',
        'spam_risk_user_banned_from_posting' => 'Tài khoản TikTok này đang bị hạn chế đăng bài.',
        'spam_risk_text' => 'Tiêu đề hoặc nội dung bị TikTok đánh giá là spam, vui lòng sửa lại.',
        'spam_risk' => 'TikTok từ chối bài đăng do nghi ngờ spam.',
        'internal' => 'TikTok gặp lỗi khi xử lý bài đăng, vui lòng đăng lại.',
    ];

    public function __construct(private TikTokAccountService $accounts) {}

    /** @return bool false khi không tìm thấy bài của publish_id (trả lỗi để TikTok gửi lại) */
    public function handle(array $payload): bool
    {
        $event = (string) ($payload['event'] ?? '');
        $content = is_string($payload['content'] ?? null) ? (json_decode($payload['content'], true) ?: []) : (array) ($payload['content'] ?? []);

        if ($event === 'authorization.removed') {
            if (! empty($payload['user_openid'])) {
                $this->accounts->authorizationRemoved((string) $payload['user_openid']);
            }

            return true;
        }

        if (! str_starts_with($event, 'post.publish.')) {
            return true;
        }

        $publishId = (string) ($content['publish_id'] ?? '');
        if ($publishId === '') {
            return true;
        }

        $post = TikTokPost::query()->where('tiktok_publish_id', $publishId)->first();
        if (! $post) {
            // content/init vừa trả publish_id nhưng chưa kịp lưu: để TikTok gửi lại
            Log::warning('TikTok webhook: không tìm thấy bài đăng', ['event' => $event, 'publish_id' => $publishId]);

            return false;
        }

        match ($event) {
            'post.publish.complete' => $this->published($post->id, null),
            'post.publish.publicly_available' => $this->published($post->id, isset($content['post_id']) ? (string) $content['post_id'] : null),
            'post.publish.failed' => $this->failed($post->id, (string) ($content['reason'] ?? 'internal')),
            default => null, // inbox_delivered, no_longer_publicaly_available...: không đổi trạng thái
        };

        return true;
    }

    private function published(int $postId, ?string $tiktokPostId): void
    {
        DB::transaction(function () use ($postId, $tiktokPostId) {
            $post = TikTokPost::query()->lockForUpdate()->findOrFail($postId);
            if (! in_array($post->status, [TikTokPostStatus::Publishing, TikTokPostStatus::Published], true)) {
                return;
            }

            $post->fill([
                'status' => TikTokPostStatus::Published,
                'published_at' => $post->published_at ?? now(),
                'last_error' => null,
            ]);
            // post_id chỉ có ở publicly_available (bài SELF_ONLY không bao giờ có)
            if ($tiktokPostId) {
                $post->tiktok_post_id = $tiktokPostId;
                $post->tiktok_post_url = $post->tiktok_username
                    ? "https://www.tiktok.com/@{$post->tiktok_username}/photo/{$tiktokPostId}"
                    : null;
            }
            $post->save();
        });
    }

    private function failed(int $postId, string $reason): void
    {
        DB::transaction(function () use ($postId, $reason) {
            $post = TikTokPost::query()->lockForUpdate()->findOrFail($postId);
            if ($post->status !== TikTokPostStatus::Publishing) {
                return;
            }

            $error = $reason === 'auth_removed'
                ? ['code' => 'TIKTOK_NOT_CONNECTED', 'message' => 'Mất kết nối TikTok, vui lòng ủy quyền lại và đăng lại.']
                : ['code' => $reason, 'message' => self::FAIL_REASONS[$reason] ?? 'Đăng lên TikTok thất bại, vui lòng thử lại.'];

            $post->update(['status' => TikTokPostStatus::PendingReview, 'last_error' => $error]);
        });
    }
}
