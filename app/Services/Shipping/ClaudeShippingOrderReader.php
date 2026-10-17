<?php

namespace App\Services\Shipping;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\TextBlockParam;
use Illuminate\Support\Facades\Log;

/**
 * Đọc ảnh bằng Claude API (mặc định claude-haiku-5-5), gọi thẳng trong request tạo đơn, không qua worker.
 * Mọi ảnh của đơn gửi trong 1 message; kết quả ép dạng JSON bằng structured outputs.
 */
class ClaudeShippingOrderReader implements ShippingOrderReader
{
    private const MAX_TOKENS = 4000;

    private const SYSTEM = <<<'TXT'
        Bạn trích xuất thông tin giao hàng cho một đơn hàng tại Việt Nam từ ảnh chụp màn hình đoạn chat giữa người bán
        và người mua (Zalo, Messenger, tin nhắn...). Các ảnh là cùng một đơn, theo thứ tự thời gian.

        Quy tắc:
        - Chỉ lấy thông tin NGƯỜI NHẬN (người mua), không lấy thông tin người bán.
        - Thông tin xuất hiện nhiều lần hoặc được sửa lại: lấy bản mới nhất.
        - to_phone: chỉ gồm chữ số, đổi +84 thành 0.
        - Địa chỉ: tách street (số nhà, tên đường, thôn/xóm/ấp, toà nhà), ward (phường/xã), district (quận/huyện,
          null nếu địa chỉ không có), province (tỉnh/thành phố). raw_address là địa chỉ nguyên văn như trong ảnh.
        - cod_amount: tiền hàng người mua phải trả khi nhận hàng, đơn vị VND, số nguyên ("350k" = 350000,
          "1tr2" = 1200000). KHÔNG cộng phí ship / phí vận chuyển: nếu chat ghi tổng đã gồm ship (vd. "300k + 30k ship
          = 330k") thì chỉ lấy tiền hàng (300000). Nếu chat ghi đã chuyển khoản / không thu tiền thì 0.
        - note: yêu cầu giao hàng người mua dặn (giờ giao, gọi trước...), không có thì null.
        - Không bịa: không thấy thông tin thì để null.
        - low_confidence_fields: các trường bạn không chắc (ảnh mờ, nhiều giá trị mâu thuẫn, phải đoán).
        TXT;

    public function read(array $images): ?array
    {
        try {
            $client = new Client(
                apiKey: config('services.anthropic.api_key'),
                requestOptions: ['timeout' => config('services.anthropic.timeout'), 'maxRetries' => 1],
            );

            $content = array_map(fn ($image) => ImageBlockParam::with(
                source: Base64ImageSource::with(data: base64_encode($image['contents']), mediaType: $image['mime']),
            ), $images);
            $content[] = TextBlockParam::with(text: 'Trích thông tin người nhận của đơn hàng trong các ảnh trên.');

            $message = $client->messages->create(
                model: config('services.anthropic.shipping_model'),
                maxTokens: self::MAX_TOKENS,
                system: self::SYSTEM,
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: [
                    // Trích xuất đơn giản: effort thấp cho nhanh và rẻ
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => self::schema()],
                ],
            );
        } catch (AnthropicException $e) {
            Log::warning('Shipping order AI read failed', ['error' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }

        if ($message->stopReason !== 'end_turn') {
            Log::warning('Shipping order AI read stopped', [
                'stop_reason' => $message->stopReason,
                'category' => $message->stopDetails?->category,
            ]);

            return null;
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }
        Log::warning('Shipping order AI read returned no JSON', ['message_id' => $message->id]);

        return null;
    }

    private static function schema(): array
    {
        $nullable = fn (string $type) => ['anyOf' => [['type' => $type], ['type' => 'null']]];
        $fields = [
            'to_name' => $nullable('string'),
            'to_phone' => $nullable('string'),
            'street' => $nullable('string'),
            'ward' => $nullable('string'),
            'district' => $nullable('string'),
            'province' => $nullable('string'),
            'raw_address' => $nullable('string'),
            'cod_amount' => $nullable('integer'),
            'note' => $nullable('string'),
            'low_confidence_fields' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => ['to_name', 'to_phone', 'address', 'cod_amount', 'note']],
            ],
        ];

        return [
            'type' => 'object',
            'properties' => $fields,
            'required' => array_keys($fields),
            'additionalProperties' => false,
        ];
    }
}
