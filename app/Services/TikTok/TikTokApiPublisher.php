<?php

namespace App\Services\TikTok;

use App\Enums\OutputStatus;
use App\Enums\PublishDriver;
use App\Exceptions\BusinessException;
use App\Models\GenerationOutput;
use App\Models\SocialPost;
use App\Models\SocialPublishJob;
use App\Services\MediaStorage;
use App\Services\Social\SocialPublishJobService;
use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gửi bài TikTok qua Content Posting API cho job driver api (note/dang-tiktok-qua-api.txt ở repo frontend):
 * chuẩn bị ảnh JPG / WEBP trên tên miền đã xác minh, gọi content/init (MEDIA_UPLOAD, PHOTO) -> ảnh vào hộp thư TikTok,
 * người dùng tự đăng trong app. Kết quả qua webhook (TikTokWebhookController) hoặc status/fetch (social:sweep).
 * Job lỗi lưu error_code TIKTOK_* và error_message tiếng Việt (hiện cho người dùng).
 */
class TikTokApiPublisher
{
    private const JPEG_QUALITY = 90;

    /** Lỗi / fail_reason của TikTok -> thông báo tiếng Việt. */
    private const MESSAGES = [
        'spam_risk_too_many_posts' => 'Tài khoản TikTok đã đạt giới hạn gửi bài trong ngày, thử lại sau.',
        'spam_risk_user_banned_from_posting' => 'Tài khoản TikTok này đang bị hạn chế đăng bài.',
        'spam_risk_text' => 'Tiêu đề hoặc nội dung bị TikTok đánh giá là spam, vui lòng sửa lại.',
        'spam_risk' => 'TikTok từ chối bài do nghi ngờ spam.',
        'reached_active_user_cap' => 'TikTok đang giới hạn số người dùng gửi bài qua A51, thử lại sau.',
        'url_ownership_unverified' => 'TikTok không tải được ảnh, vui lòng thử lại.',
        'photo_pull_failed' => 'TikTok không tải được ảnh, vui lòng thử lại.',
        'file_format_check_failed' => 'Định dạng ảnh không được TikTok hỗ trợ.',
        'picture_size_check_failed' => 'Kích thước ảnh không đạt yêu cầu của TikTok.',
        'invalid_params' => 'Dữ liệu bài không hợp lệ với TikTok.',
        'publish_cancelled' => 'Bài đã bị huỷ trên TikTok, có thể gửi lại.',
    ];

    private const DEFAULT_MESSAGE = 'Gửi bài sang TikTok thất bại, vui lòng thử lại.';

    /** Lỗi do cấu hình phía A51 (tên miền ảnh, dữ liệu gửi): báo cho dev. */
    private const REPORTED = ['url_ownership_unverified', 'invalid_params'];

    /** Lỗi tạm thời: để queue thử lại. */
    private const RETRYABLE = ['rate_limit_exceeded', 'internal_error'];

    public function __construct(
        private TikTokClient $client,
        private TikTokTokenManager $tokens,
        private SocialPublishJobService $jobs,
        private MediaStorage $media,
    ) {}

    /**
     * Gửi bài của job. TikTok tạm lỗi (mạng / 5xx / giới hạn tần suất) -> ném TIKTOK_UNAVAILABLE để queue thử lại;
     * các lỗi khác -> job failed ngay.
     */
    public function run(int $jobId): void
    {
        $job = $this->jobs->startApiJob($jobId);
        if (! $job) {
            return;
        }

        $account = $job->publishAccount;
        if (! $account) {
            $this->fail($job, 'TIKTOK_NOT_CONNECTED', BusinessException::tiktokNotConnected()->getMessage());

            return;
        }

        try {
            $post = $job->post()->with('images.output')->firstOrFail();
            $urls = $post->images->map(fn ($image) => $this->prepareImage($job, $image->output))->values()->all();
            $description = SocialPost::description($post->content, $post->hashtags ?? []);

            $publishId = $this->tokens->call($account, fn (string $token) => $this->client->initPhotoPost($token, [
                // MEDIA_UPLOAD chỉ nhận title + description; rỗng thì bỏ
                'post_info' => array_filter(['title' => (string) $post->title, 'description' => $description], fn ($v) => $v !== ''),
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'photo_cover_index' => 0,
                    'photo_images' => $urls,
                ],
                'post_mode' => 'MEDIA_UPLOAD',
                'media_type' => 'PHOTO',
            ]));
        } catch (BusinessException $e) {
            if ($e->errorCode === 'TIKTOK_UNAVAILABLE') {
                throw $e;
            }
            $this->fail($job, $e->errorCode === 'TIKTOK_NOT_CONNECTED' ? 'TIKTOK_NOT_CONNECTED' : 'TIKTOK_FAILED', $e->getMessage());

            return;
        } catch (TikTokApiException $e) {
            if (in_array($e->errorCode, self::RETRYABLE, true)) {
                throw BusinessException::tiktokUnavailable();
            }
            if ($e->errorCode === 'scope_not_authorized') {
                $this->tokens->markExpired($account, $e);
                $this->fail($job, 'TIKTOK_NOT_CONNECTED', BusinessException::tiktokNotConnected()->getMessage());

                return;
            }
            if (in_array($e->errorCode, self::REPORTED, true)) {
                report($e);
            }
            $this->fail($job, self::errorCode($e->errorCode), self::message($e->errorCode));

            return;
        }

        $job->update(['external_id' => $publishId, 'checked_at' => now()]);
    }

    /** Queue hết lượt thử: job thất bại (TikTok không phản hồi hoặc lỗi không lường trước). */
    public function giveUp(int $jobId, \Throwable $e): void
    {
        $job = SocialPublishJob::query()->find($jobId);
        if (! $job || $job->driver !== PublishDriver::Api) {
            return;
        }
        if (! $e instanceof BusinessException) {
            report($e);
        }
        $this->fail($job, 'TIKTOK_UNAVAILABLE', BusinessException::tiktokUnavailable()->getMessage());
    }

    /** Webhook chưa về: hỏi TikTok trạng thái các job đang gửi (gọi từ social:sweep). */
    public function checkPending(): int
    {
        $checked = 0;
        SocialPublishJob::query()
            ->where('driver', PublishDriver::Api)
            ->where('status', OutputStatus::Processing)
            ->whereNotNull('external_id')
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes(config('services.tiktok.status_check_minutes'))))
            ->with('publishAccount')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(function (SocialPublishJob $job) use (&$checked) {
                $job->update(['checked_at' => now()]);
                $checked++;
                if (! $job->publishAccount) {
                    return;
                }

                try {
                    $status = $this->tokens->call($job->publishAccount, fn (string $token) => $this->client->publishStatus($token, $job->external_id));
                } catch (BusinessException $e) {
                    if ($e->errorCode === 'TIKTOK_NOT_CONNECTED') {
                        $this->fail($job, 'TIKTOK_NOT_CONNECTED', $e->getMessage());
                    }

                    return; // TikTok tạm lỗi: lần sau hỏi lại
                } catch (TikTokApiException $e) {
                    Log::warning('TikTok status/fetch lỗi', ['job_id' => $job->id, 'code' => $e->errorCode]);

                    return;
                }

                match ($status['status'] ?? null) {
                    'SEND_TO_USER_INBOX', 'PUBLISH_COMPLETE' => $this->complete($job),
                    'FAILED' => $this->failWithReason($job, (string) ($status['fail_reason'] ?? '')),
                    default => null, // còn đang xử lý
                };
            });

        return $checked;
    }

    /**
     * Sự kiện webhook post.publish.*. Payload: { client_key, event, create_time, user_openid, content: "<JSON>" }.
     *
     * @return bool false khi không tìm thấy job của publish_id (trả lỗi để TikTok gửi lại)
     */
    public function handlePublishEvent(string $event, array $content): bool
    {
        $publishId = (string) ($content['publish_id'] ?? '');
        if ($publishId === '') {
            return true;
        }

        $job = SocialPublishJob::query()->where('driver', PublishDriver::Api)->where('external_id', $publishId)->first();
        if (! $job) {
            // content/init vừa trả publish_id nhưng chưa kịp lưu: để TikTok gửi lại
            Log::warning('TikTok webhook: không tìm thấy job', ['event' => $event, 'publish_id' => $publishId]);

            return false;
        }

        match ($event) {
            'post.publish.inbox_delivered', 'post.publish.complete', 'post.publish.publicly_available' => $this->complete($job),
            'post.publish.failed' => $this->failWithReason($job, (string) ($content['reason'] ?? '')),
            default => null,
        };

        return true;
    }

    private function complete(SocialPublishJob $job): void
    {
        $this->jobs->completeApiJob($job);
        $this->cleanup($job);
    }

    private function failWithReason(SocialPublishJob $job, string $reason): void
    {
        $reason === 'auth_removed'
            ? $this->fail($job, 'TIKTOK_NOT_CONNECTED', BusinessException::tiktokNotConnected()->getMessage())
            : $this->fail($job, self::errorCode($reason ?: 'failed'), self::message($reason));
    }

    private function fail(SocialPublishJob $job, string $errorCode, string $message): void
    {
        $this->jobs->failApiJob($job, $errorCode, $message);
        $this->cleanup($job);
    }

    /** TikTok chỉ nhận JPG / WEBP qua PULL_FROM_URL: chuyển PNG sang JPG, đặt lên disk của tên miền đã xác minh. */
    private function prepareImage(SocialPublishJob $job, GenerationOutput $output): string
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

        // Link công khai (không ký số) vì TikTok kéo ảnh bất đồng bộ; tên khó đoán, xoá khi có kết quả
        $path = self::directory($job).'/'.Str::uuid().'.'.$extension;
        Storage::disk(config('services.tiktok.media_disk'))->put($path, $contents);

        return config('services.tiktok.media_url').'/'.$path;
    }

    private function cleanup(SocialPublishJob $job): void
    {
        try {
            Storage::disk(config('services.tiktok.media_disk'))->deleteDirectory(self::directory($job));
        } catch (\Throwable $e) {
            Log::warning('Không xoá được ảnh tạm gửi TikTok', ['job_id' => $job->id, 'error' => $e->getMessage()]);
        }
    }

    private static function directory(SocialPublishJob $job): string
    {
        return "tiktok/{$job->id}";
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

    /** Mã lỗi cho admin: TIKTOK_ + mã TikTok viết hoa, vd TIKTOK_SPAM_RISK_TOO_MANY_POSTS. */
    private static function errorCode(string $tiktokCode): string
    {
        return Str::limit('TIKTOK_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $tiktokCode)), 50, '');
    }

    private static function message(string $tiktokCode): string
    {
        return self::MESSAGES[$tiktokCode] ?? self::DEFAULT_MESSAGE;
    }
}
