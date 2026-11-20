<?php

namespace App\Support;

/**
 * Thư viện BÀI VIẾT MẪU (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 3): cách dựng 1 bài TikTok. Dùng chung
 * mọi shop. Thêm bài viết mẫu mới = đội A51 cùng Claude xây theo yêu cầu shop rồi thêm vào đây + deploy.
 * KHÔNG đổi key của bài đã dùng (key lưu vào bài đăng để GĐ4 phân tích hiệu quả).
 *
 * slide_rules[].at: first | middle | last | each (mọi slide chưa có luật riêng).
 * slide_rules[].image: product (ảnh sản phẩm, generations.kind = creative) | outfit (ảnh phối đồ) | any.
 * slide_rules[].text: slide đó được có chữ chèn lên ảnh hay không (bài viết mẫu quyết định slide nào có chữ).
 */
final class PostSamples
{
    public const GROUPS = [
        'showcase' => 'Trưng bày hàng',
        'compare' => 'So sánh, chọn lựa',
        'outfit' => 'Phối đồ',
        'story' => 'Kể chuyện, cảm xúc',
        'tips' => 'Hữu ích, tư vấn',
        'gift' => 'Gợi ý theo người nhận',
        'series' => 'Chuỗi bài',
        'promo' => 'Ưu đãi',
    ];

    public const GOALS = ['reach', 'engagement', 'save', 'sale', 'trust'];

    public const IMAGE_KINDS = ['product', 'outfit', 'any'];

    /** @return array<string, array> key => bài viết mẫu */
    public static function all(): array
    {
        static $samples = null;

        return $samples ??= collect(self::definitions())->keyBy('key')->all();
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Luật của slide thứ $index (0-based) trong bài $count slide. */
    public static function slideRule(array $sample, int $index, int $count): array
    {
        $at = match (true) {
            $index === 0 => 'first',
            $index === $count - 1 => 'last',
            default => 'middle',
        };
        $rules = collect($sample['slide_rules']);

        return $rules->firstWhere('at', $at) ?? $rules->firstWhere('at', 'each') ?? ['at' => 'each', 'image' => 'any', 'text' => false];
    }

    private static function sample(string $key, string $name, string $group, string $goal, array $formats, int $min, int $max,
        array $slideRules, bool $sameProduct, string $guide): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'group' => $group,
            'goal' => $goal,
            'formats' => $formats,
            'slides' => ['min' => $min, 'max' => $max],
            'slide_rules' => $slideRules,
            'same_product' => $sameProduct,
            'guide' => $guide,
        ];
    }

    private static function rule(string $at, string $image, bool $text, string $note = ''): array
    {
        return ['at' => $at, 'image' => $image, 'text' => $text, 'note' => $note];
    }

    private static function definitions(): array
    {
        $photo = ['photo'];
        $both = ['photo', 'video'];

        return [
            // ---- Trưng bày hàng ----
            self::sample('unboxing', 'Đập hộp', 'showcase', 'sale', $both, 2, 5, [
                self::rule('first', 'product', true, 'Ảnh tay cầm giày / mở hộp, chữ hook'),
                self::rule('middle', 'product', false, 'Các góc khác của CÙNG mẫu'),
                self::rule('last', 'product', true, 'Chốt: mã / kêu gọi inbox'),
            ], true, 'Đập hộp 1 mẫu. Hook gợi tò mò ("Đập hộp đôi ... đi chúc Tết"). Slide cuối kêu gọi hành động ngắn.'),
            self::sample('closeup', 'Soi cận cảnh', 'showcase', 'trust', $photo, 2, 4, [
                self::rule('first', 'product', true, 'Toàn cảnh + hook "Soi kỹ trước khi mua"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 chi tiết: đường may, đế, lót, logo'),
            ], true, 'Chứng minh chất lượng. Chữ mỗi slide nêu đúng 1 chi tiết, ngắn (<= 8 từ).'),
            self::sample('new_arrival', 'Hàng mới về', 'showcase', 'sale', $both, 3, 8, [
                self::rule('first', 'product', true, 'Ảnh đẹp nhất + hook "Lô ... vừa về"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 mẫu KHÁC NHAU, chữ = tên kiểu giày ngắn'),
            ], false, 'Nhiều mẫu khác nhau. Tạo cảm giác mới, số lượng có hạn.'),
            self::sample('color_options', '1 mẫu nhiều màu', 'showcase', 'sale', $photo, 2, 6, [
                self::rule('first', 'product', true, 'Hook "Bạn chọn màu nào?"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 màu của cùng kiểu, chữ = tên màu'),
            ], false, 'Dùng khi có nhiều ảnh cùng kiểu khác màu. Kéo comment chọn màu.'),

            // ---- So sánh, chọn lựa ----
            self::sample('this_or_that', 'A hay B', 'compare', 'engagement', $photo, 2, 3, [
                self::rule('first', 'product', true, 'Đôi A + câu hỏi "... chọn A hay B?"'),
                self::rule('each', 'product', true, 'Chữ "A: ..." / "B: ..." mô tả ngắn'),
            ], false, '2 đôi khác kiểu cùng tầm giá / cùng màu để khó chọn. Hook theo tình huống (đi làm, hẹn hò, dịp lễ).'),
            self::sample('top_n', 'Top N', 'compare', 'save', $both, 4, 8, [
                self::rule('first', 'any', true, 'Tiêu đề "Top N đôi ... "'),
                self::rule('each', 'product', true, 'Mỗi slide 1 mẫu, chữ "#1 ...", "#2 ..." + lý do ngắn'),
            ], false, 'Danh sách xếp hạng theo 1 tiêu chí (đi chúc Tết, đi làm, dưới 500k...). Đánh số đúng thứ tự.'),
            self::sample('which_suits_you', 'Bạn hợp đôi nào', 'compare', 'engagement', $photo, 3, 6, [
                self::rule('first', 'any', true, 'Hook "Bạn hợp đôi nào theo ...?"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 nhóm (phong cách / tính cách / cung) + 1 đôi'),
            ], false, 'Gắn giày với phong cách / tính cách. Người xem tag bạn bè, comment nhóm của mình.'),

            // ---- Phối đồ ----
            self::sample('lookbook', 'Lookbook N outfit', 'outfit', 'save', $both, 3, 9, [
                self::rule('first', 'outfit', true, 'Hook "N outfit ... "'),
                self::rule('each', 'outfit', true, 'Chữ ngắn cho từng outfit hoặc để trống (ảnh phối đồ đã có "Outfit N")'),
            ], false, 'Chuỗi ảnh phối đồ. Kết bằng câu hỏi "Bạn chọn outfit số mấy?".'),
            self::sample('one_shoe_many_looks', '1 đôi N cách phối', 'outfit', 'save', $photo, 3, 6, [
                self::rule('first', 'product', true, 'Ảnh đôi giày + hook "1 đôi ... phối N kiểu"'),
                self::rule('each', 'outfit', true, 'Mỗi outfit có đôi giày cùng kiểu, chữ = dịp / phong cách'),
            ], false, 'Slide đầu là giày, các slide sau là outfit mang kiểu giày tương tự.'),
            self::sample('box_to_feet', 'Từ hộp lên chân', 'outfit', 'sale', $photo, 2, 4, [
                self::rule('first', 'product', true, 'Giày trong hộp, chữ bỏ lửng "Đôi này..."'),
                self::rule('middle', 'outfit', true, 'Outfit mang ĐÚNG kiểu giày đó "...mang lên thế này"'),
                self::rule('last', 'any', true, 'Chốt mã / size'),
            ], false, 'Chỉ dùng khi có ảnh phối đồ mang đúng kiểu giày của ảnh sản phẩm (xem ảnh để chắc).'),
            self::sample('outfit_by_day', 'Outfit theo ngày / dịp', 'outfit', 'save', $both, 3, 8, [
                self::rule('first', 'outfit', true, 'Hook "7 ngày ... mặc gì?"'),
                self::rule('each', 'outfit', true, 'Mỗi slide 1 ngày / 1 dịp: "Mùng 1: ...", "Thứ 2: ..."'),
            ], false, 'Gắn mỗi outfit với 1 ngày hoặc dịp trong hook (Tết, tuần đi làm, chuyến du lịch).'),

            // ---- Kể chuyện ----
            self::sample('pov', 'POV', 'story', 'reach', $photo, 2, 3, [
                self::rule('first', 'any', true, 'Câu "POV: ..." hài, dễ đồng cảm'),
                self::rule('last', 'product', true, 'Lộ ra đôi giày + câu chốt vui'),
            ], false, 'Câu POV là trọng tâm, viết như người thật nói. Không quảng cáo lộ liễu ở slide đầu.'),
            self::sample('meme_types', 'Meme "N kiểu người"', 'story', 'reach', $photo, 3, 5, [
                self::rule('first', 'any', true, 'Hook "Khách mua giày có N loại"'),
                self::rule('each', 'any', true, 'Mỗi slide 1 kiểu người + 1 đôi giày hợp'),
            ], false, 'Hài nhẹ nhàng, không chê khách. Người xem tag bạn bè.'),
            self::sample('before_after', 'Trước / sau', 'story', 'reach', $photo, 2, 4, [
                self::rule('first', 'any', true, '"Trước khi có đôi này..."'),
                self::rule('last', 'outfit', true, '"...và sau khi có" (outfit đẹp)'),
            ], false, 'Đối lập rõ: trước (bình thường / lúng túng) và sau (xinh, tự tin).'),

            // ---- Hữu ích ----
            self::sample('size_guide', 'Chọn size', 'tips', 'trust', $photo, 2, 4, [
                self::rule('first', 'product', true, 'Hook "Mua giày online sợ sai size?"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 mẹo ngắn chọn size'),
            ], false, 'Mẹo thực tế, ngắn gọn. Chữ trên ảnh tối đa 2 dòng mỗi slide.'),
            self::sample('care_tips', 'Bảo quản / mẹo', 'tips', 'save', $photo, 2, 5, [
                self::rule('first', 'product', true, 'Hook mẹo (đi cao gót không đau, giữ giày trắng...)'),
                self::rule('each', 'product', true, 'Mỗi slide 1 mẹo'),
            ], false, 'Nội dung đáng lưu lại. Mỗi slide đúng 1 mẹo.'),
            self::sample('faq', 'Hỏi đáp', 'tips', 'trust', $photo, 2, 5, [
                self::rule('first', 'product', true, 'Câu hỏi khách hay hỏi'),
                self::rule('each', 'product', true, 'Câu trả lời ngắn'),
            ], false, 'Lấy câu hỏi thật hay gặp (ship, đổi size, chất liệu). Trả lời thẳng.'),

            // ---- Gợi ý theo người nhận ----
            self::sample('gift_by_receiver', 'Quà theo người nhận', 'gift', 'sale', $photo, 3, 6, [
                self::rule('first', 'product', true, 'Hook "Hết đau đầu chọn quà ..."'),
                self::rule('each', 'product', true, 'Mỗi slide 1 người nhận: "Tặng mẹ: ...", "Tặng bạn thân: ..."'),
            ], false, 'Gắn mỗi đôi với 1 người nhận hợp tuổi / phong cách. Hợp mùa lễ, Tết, 8/3, 20/10.'),
            self::sample('by_budget', 'Theo mức giá', 'gift', 'sale', $photo, 3, 6, [
                self::rule('first', 'product', true, 'Hook "Giày xinh dưới ...k"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 mẫu trong tầm giá'),
            ], false, 'Chỉ dùng khi chân dung khách hàng có mức giá. Không bịa giá cụ thể nếu không có.'),

            // ---- Chuỗi bài ----
            self::sample('daily_drop', 'Mỗi ngày 1 mẫu / đếm ngược', 'series', 'engagement', $both, 1, 3, [
                self::rule('first', 'product', true, '"Còn N ngày nữa là ..." hoặc "Mẫu hôm nay"'),
                self::rule('each', 'product', false, 'Góc khác của mẫu hôm nay'),
            ], true, 'Bài nối tiếp mỗi ngày, đếm ngược tới dịp lễ gần nhất trong kỳ. Số ngày phải đúng với ngày đăng.'),
            self::sample('numbered', 'Đánh số tập', 'series', 'engagement', $both, 1, 4, [
                self::rule('first', 'any', true, '"Outfit số N" / "Tập N: ..."'),
                self::rule('each', 'any', false, ''),
            ], false, 'Số tập tăng dần theo các bài numbered trước của kênh.'),

            // ---- Ưu đãi ----
            self::sample('sale', 'Ưu đãi', 'promo', 'sale', $photo, 1, 4, [
                self::rule('first', 'product', true, 'Hook ưu đãi / số lượng có hạn'),
                self::rule('each', 'product', true, 'Mẫu + lợi ích ngắn'),
            ], false, 'Không bịa mức giảm giá cụ thể nếu Yêu cầu của kênh không nêu. Dùng ít (tỉ lệ thấp).'),
        ];
    }
}
