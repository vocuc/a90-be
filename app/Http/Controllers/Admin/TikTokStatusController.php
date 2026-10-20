<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\SocialPublishJob;
use App\Services\TikTok\TikTokApiException;
use App\Services\TikTok\TikTokClient;
use App\Services\TikTok\TikTokTokenManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tra trạng thái một bài gửi TikTok qua API theo publish_id (social_publish_jobs.external_id): gọi status/fetch bằng
 * token của tài khoản đã gửi bài. Chỉ xem, không đổi trạng thái job (việc đó do webhook / social:sweep).
 */
class TikTokStatusController extends Controller
{
    /** status của TikTok -> mô tả cho admin. */
    public const STATUS_LABELS = [
        'PROCESSING_UPLOAD' => 'Đang tải lên TikTok',
        'PROCESSING_DOWNLOAD' => 'TikTok đang tải ảnh từ A51',
        'SEND_TO_USER_INBOX' => 'Đã vào hộp thư của người dùng, chờ người dùng mở app TikTok để đăng',
        'PUBLISH_COMPLETE' => 'Người dùng đã đăng bài',
        'FAILED' => 'Thất bại',
    ];

    public function __invoke(Request $request, TikTokClient $client, TikTokTokenManager $tokens): View
    {
        $publishId = trim((string) $request->query('publish_id'));
        $job = null;
        $result = null;
        $error = null;

        if ($publishId !== '') {
            $job = SocialPublishJob::query()->with(['post.user', 'publishAccount'])->where('external_id', $publishId)->first();

            if (! $job) {
                $error = 'Không tìm thấy job nào có publish_id này.';
            } elseif (! $job->publishAccount) {
                $error = 'Job không còn gắn tài khoản TikTok (đã huỷ kết nối), không có token để hỏi TikTok.';
            } else {
                try {
                    $result = $tokens->call($job->publishAccount, fn (string $token) => $client->publishStatus($token, $publishId));
                } catch (TikTokApiException $e) {
                    $error = "TikTok trả lỗi {$e->errorCode}: {$e->getMessage()}";
                } catch (BusinessException $e) {
                    $error = $e->getMessage();
                }
            }
        }

        return view('admin.tiktok-status.index', compact('publishId', 'job', 'result', 'error'));
    }
}
