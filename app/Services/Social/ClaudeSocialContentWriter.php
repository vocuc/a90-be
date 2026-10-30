<?php

namespace App\Services\Social;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\TextBlockParam;
use Illuminate\Support\Facades\Log;

/**
 * Viết nội dung bài đăng bằng Claude API (mặc định claude-haiku-5-5), không qua worker.
 * Hashtag ép bằng enum trong JSON schema: Claude chỉ chọn được hashtag trong kho của shop.
 */
class ClaudeSocialContentWriter implements SocialContentWriter
{
    private const MAX_TOKENS = 4000;

    private const SYSTEM = <<<'TXT'
        Bạn là người viết nội dung bài đăng mạng xã hội (TikTok, Instagram...) cho các shop bán hàng tại Việt Nam.
        Bạn nhận ảnh của bài (ảnh đầu là ảnh bìa) và thông tin các mẫu ảnh đã dùng để tạo ảnh.

        Quy tắc:
        - Viết tiếng Việt có dấu, tự nhiên, gần gũi, hợp để bán hàng; có thể dùng vài emoji, không lạm dụng.
        - title: một câu ngắn gây chú ý, tối đa {title_max} ký tự, không có hashtag.
        - content: 2-5 câu mô tả điều nổi bật trong ảnh và lời kêu gọi xem / mua, tối đa {content_max} ký tự.
          KHÔNG viết hashtag (#...) trong content.
        - hashtags: chọn tối đa {hashtags_max} hashtag liên quan nhất tới ảnh, CHỈ chọn trong danh sách được cho, giữ
          nguyên cách viết, không có dấu #. Không có hashtag nào phù hợp hoặc danh sách rỗng thì trả mảng rỗng.
        - Không bịa thông tin không có trong ảnh / mẫu (giá, khuyến mãi, thương hiệu, số điện thoại...).
        TXT;

    public function write(array $request, array $images): ?array
    {
        $limits = $request['limits'];
        $system = strtr(self::SYSTEM, [
            '{title_max}' => $limits['title_max'],
            '{content_max}' => $limits['content_max'],
            '{hashtags_max}' => $limits['hashtags_max'],
        ]);

        try {
            $client = new Client(
                apiKey: config('services.anthropic.api_key'),
                requestOptions: ['timeout' => config('services.anthropic.timeout'), 'maxRetries' => 1],
            );

            $content = array_map(fn ($image) => ImageBlockParam::with(
                source: Base64ImageSource::with(data: base64_encode($image['contents']), mediaType: $image['mime']),
            ), $images);
            $content[] = TextBlockParam::with(text: $this->prompt($request));

            $message = $client->messages->create(
                model: config('services.anthropic.social_model'),
                maxTokens: self::MAX_TOKENS,
                system: $system,
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => self::schema($request['hashtags'])],
                ],
            );
        } catch (AnthropicException $e) {
            Log::warning('Social content AI write failed', ['error' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }

        if ($message->stopReason !== 'end_turn') {
            Log::warning('Social content AI write stopped', [
                'stop_reason' => $message->stopReason,
                'category' => $message->stopDetails?->category,
            ]);

            return null;
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                if (is_array($data) && is_string($data['title'] ?? null) && is_string($data['content'] ?? null)) {
                    return [
                        'title' => $data['title'],
                        'content' => $data['content'],
                        'hashtags' => array_values(array_filter((array) ($data['hashtags'] ?? []), 'is_string')),
                    ];
                }
            }
        }
        Log::warning('Social content AI write returned no JSON', ['message_id' => $message->id]);

        return null;
    }

    private function prompt(array $request): string
    {
        $creatives = collect($request['creatives'])
            ->map(fn ($c) => '- '.collect([$c['title'], $c['category'] ? "ngành hàng: {$c['category']}" : null, $c['description']])->filter()->implode(' | '))
            ->implode("\n");
        $hashtags = $request['hashtags'] ? implode(', ', $request['hashtags']) : '(rỗng)';

        return "Mẫu ảnh đã dùng:\n".($creatives ?: '(không có)')."\n\nDanh sách hashtag được chọn: {$hashtags}\n\n"
            .'Viết tiêu đề, nội dung và chọn hashtag cho bài đăng gồm các ảnh trên.';
    }

    /** @param  string[]  $hashtags */
    private static function schema(array $hashtags): array
    {
        // Kho rỗng: không cho chọn hashtag (enum rỗng không hợp lệ nên bỏ hẳn trường)
        $fields = [
            'title' => ['type' => 'string'],
            'content' => ['type' => 'string'],
            ...($hashtags ? ['hashtags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_values($hashtags)]]] : []),
        ];

        return [
            'type' => 'object',
            'properties' => $fields,
            'required' => array_keys($fields),
            'additionalProperties' => false,
        ];
    }
}
