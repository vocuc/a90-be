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

    /**
     * Bài viết mẫu ĐÃ LÀM XONG (người dùng chốt 2026-11-21): chỉ những bài này được bật (chọn vào tuyến nội dung, lập kế
     * hoạch, gắn template chữ). Bài chưa làm xong vẫn nằm trong thư viện (hiện "Chưa làm xong") để giữ tên cho bài cũ.
     * Làm xong 1 bài (a90-fe note/xay-kenh-tiktok/bai-viet-mau/<key>.txt "ĐÃ LÀM") thì thêm key vào đây.
     * config ai90.post_samples_enabled ghi đè (vd test: ['*'] = bật hết).
     */
    public const READY = ['top_n', 'lookbook', 'outfit_by_day', 'unboxing', 'video_type_01'];

    /** @return array<string, array> key => bài viết mẫu (cả bài chưa bật), có cờ enabled */
    public static function all(): array
    {
        static $samples = null;
        $samples ??= collect(self::definitions())->keyBy('key')->all();

        return array_map(fn ($s) => [...$s, 'enabled' => self::isEnabled($s['key']), 'hook_from_title' => self::hookFromTitle($s)], $samples);
    }

    /** @return array<string, array> chỉ bài viết mẫu đang bật */
    public static function enabled(): array
    {
        return array_filter(self::all(), fn ($s) => $s['enabled']);
    }

    public static function isEnabled(string $key): bool
    {
        $ready = config('ai90.post_samples_enabled') ?? self::READY;

        return in_array('*', $ready, true) || in_array($key, $ready, true);
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

    /** Nguồn ảnh: library = chọn ảnh có sẵn của shop; outfit_batch = tạo lô phối đồ mới khi duyệt kỳ; product_batch = tạo
     * lô tự động mới (1 sản phẩm x 1 nhóm Creative, mỗi Creative 1 ảnh) khi duyệt kỳ. */
    public const SOURCE_LIBRARY = 'library';

    public const SOURCE_OUTFIT_BATCH = 'outfit_batch';

    public const SOURCE_PRODUCT_BATCH = 'product_batch';

    /** Video từ ảnh có sẵn của 1 sản phẩm: lệnh chỉ chọn sản phẩm, A51 chọn video template + ảnh như "Tạo video" (video_type_01). */
    public const SOURCE_PRODUCT_VIDEO = 'product_video';

    /** Bài không in chữ lên ảnh / video (lô ảnh sản phẩm, video): hook = tiêu đề, lệnh không viết hook riêng. */
    public static function hookFromTitle(array $sample): bool
    {
        return in_array($sample['image_source'] ?? self::SOURCE_LIBRARY, [self::SOURCE_PRODUCT_BATCH, self::SOURCE_PRODUCT_VIDEO], true);
    }

    /** Độ dài tối đa 1 nhãn ngày in lên ảnh outfit (bài có 'outfit_labels' => true, vd outfit_by_day). */
    public const LABEL_MAX = 30;

    private static function sample(string $key, string $name, string $group, string $goal, array $formats, int $min, int $max,
        array $slideRules, bool $sameProduct, string $guide, string $imageSource = self::SOURCE_LIBRARY): array
    {
        return [
            'image_source' => $imageSource,
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
            // Kiểu bài ảnh chủ shop hay đăng (người dùng chốt 2026-11-21, giữ key unboxing): KHÔNG chọn ảnh có sẵn; bài kế
            // hoạch chỉ ghi 1 sản phẩm, duyệt kỳ -> A51 tạo LÔ TỰ ĐỘNG mới (sản phẩm x 1 nhóm Creative, mỗi Creative 1 ảnh)
            // làm ảnh của bài, ảnh KHÔNG chữ. a90-fe note/xay-kenh-tiktok/bai-viet-mau/unboxing.txt
            self::sample('unboxing', 'Đập hộp', 'showcase', 'sale', $photo, 1, \App\Models\SocialPost::MAX_IMAGES, [
                self::rule('each', 'product', false, 'Ảnh lô tự động của sản phẩm (mỗi Creative trong nhóm 1 ảnh), không chữ'),
            ], true, 'Không chọn ảnh có sẵn, không chữ trên ảnh. Chọn 1 sản phẩm hợp chủ đề (kehoach.py products theo tag chủ đề, ưu tiên used thấp / lâu chưa dùng) -> product_batch.product_id; duyệt kỳ A51 tạo lô tự động (sản phẩm x 1 nhóm Creative) làm ảnh. KHÔNG viết hook: chỉ viết tiêu đề, A51 lấy tiêu đề làm hook (không trùng tiêu đề + sản phẩm bài khác). Tiêu đề nêu dịp / tình huống của chủ đề + điểm nổi bật của đôi giày (theo nhãn + tag), giọng chân dung khách hàng.', self::SOURCE_PRODUCT_BATCH),
            self::sample('closeup', 'Soi cận cảnh', 'showcase', 'trust', $photo, 2, 4, [
                self::rule('first', 'product', true, 'Toàn cảnh + hook "Soi kỹ trước khi mua"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 chi tiết: đường may, đế, lót, logo'),
            ], true, 'Chứng minh chất lượng. Chữ mỗi slide nêu đúng 1 chi tiết, ngắn (<= 8 từ).'),
            // new_arrival (Hàng mới về): đã bỏ 2026-11-22 (người dùng chốt), chưa từng có bài / tuyến dùng
            self::sample('color_options', '1 mẫu nhiều màu', 'showcase', 'sale', $photo, 2, 6, [
                self::rule('first', 'product', true, 'Hook "Bạn chọn màu nào?"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 màu của cùng kiểu, chữ = tên màu'),
            ], false, 'Dùng khi có nhiều ảnh cùng kiểu khác màu. Kéo comment chọn màu.'),

            // Bài VIDEO giữ nguyên luồng video hiện nay (người dùng chốt 2026-11-21, tên "Video - Type 01"): lệnh chọn 1 sản
            // phẩm hợp chủ đề (product_id), A51 chọn video template đang bật (ngẫu nhiên trong các template sản phẩm đủ ảnh) +
            // ảnh của sản phẩm (ít dùng cho video trước, mới trước) như nút "Tạo video"; worker đăng bài dựng video từ ảnh rồi
            // đăng. a90-fe note/xay-kenh-tiktok/bai-viet-mau/video_type_01.txt
            self::sample('video_type_01', 'Video - Type 01', 'showcase', 'sale', ['video'], 1, \App\Models\SocialPost::MAX_IMAGES, [
                self::rule('each', 'product', false, 'Ảnh của 1 sản phẩm, A51 chọn theo video template, không chữ'),
            ], true, 'Chỉ dùng cho slot VIDEO. Không chọn ảnh, không chọn template: gửi product_id = 1 sản phẩm hợp chủ đề (kehoach.py products theo tag chủ đề, ưu tiên used thấp / lâu chưa dùng); A51 tự chọn video template + ảnh của sản phẩm, worker đăng bài dựng video rồi đăng. KHÔNG viết hook: chỉ viết tiêu đề, A51 lấy tiêu đề làm hook. Tiêu đề nêu dịp / tình huống + điểm nổi bật của đôi giày.', self::SOURCE_PRODUCT_VIDEO),

            // ---- So sánh, chọn lựa ----
            self::sample('this_or_that', 'A hay B', 'compare', 'engagement', $photo, 2, 3, [
                self::rule('first', 'product', true, 'Đôi A + câu hỏi "... chọn A hay B?"'),
                self::rule('each', 'product', true, 'Chữ "A: ..." / "B: ..." mô tả ngắn'),
            ], false, '2 đôi khác kiểu cùng tầm giá / cùng màu để khó chọn. Hook theo tình huống (đi làm, hẹn hò, dịp lễ).'),
            // Bìa GHÉP (người dùng chốt 2026-11-21): lệnh chỉ gửi N ảnh #1..#N, CONTENT-WORKER dựng thêm bìa ghép các ảnh
            // đó + in hook (A51 không dựng). a90-fe note/xay-kenh-tiktok/bai-viet-mau/top_n.txt
            [...self::sample('top_n', 'Top N', 'compare', 'save', $photo, 5, 8, [
                self::rule('first', 'product', true, 'Bìa GHÉP 4 ảnh đầu (N = 4-5) hoặc 6 ảnh đầu (N >= 6), content-worker dựng + hook "Top N đôi ..."'),
                self::rule('each', 'product', true, 'Mỗi slide 1 mẫu, chữ "#1 ...", "#2 ..." + lý do ngắn'),
            ], false, 'Danh sách xếp hạng theo 1 tiêu chí (đi chúc Tết, đi làm cả ngày, đi chơi...). Hook = "Top N đôi ..." với N = số ảnh gửi. CHỈ gửi N ảnh sản phẩm của N mẫu KHÁC NHAU (4-7, #1 trước), KHÔNG gửi ảnh bìa: content-worker tự dựng bìa ghép 4 ảnh đầu (N = 4-5) hoặc 6 ảnh đầu (N >= 6) + in hook. Chữ ảnh thứ k bắt đầu "#k " rồi kiểu giày + lý do 2-4 từ ("#1 Cao gót mũi nhọn, sang chảnh"). Không nêu giá nếu Yêu cầu kênh không có. Caption kiểu "Bạn chốt số mấy? Lưu lại để sắm".'),
                'cover_collage' => true],
            self::sample('which_suits_you', 'Bạn hợp đôi nào', 'compare', 'engagement', $photo, 3, 6, [
                self::rule('first', 'any', true, 'Hook "Bạn hợp đôi nào theo ...?"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 nhóm (phong cách / tính cách / cung) + 1 đôi'),
            ], false, 'Gắn giày với phong cách / tính cách. Người xem tag bạn bè, comment nhóm của mình.'),

            // ---- Phối đồ ----
            // Ảnh lấy bằng LÔ PHỐI ĐỒ mới (người dùng chốt 2026-11-21): bài kế hoạch ghi số ảnh, duyệt kỳ -> tạo lô phối đồ,
            // worker phối đồ chạy như luồng hiện tại (ảnh 1 = bìa có hook do worker đặt, ảnh 2..N = outfit có "Outfit N")
            self::sample('lookbook', 'Phối đồ', 'outfit', 'save', $photo, 3, 9, [
                self::rule('first', 'outfit', false, 'Ảnh bìa lô phối đồ: worker in đúng hook (chủ đề) của bài lên bìa'),
                self::rule('each', 'outfit', false, 'Ảnh outfit của lô, đã in sẵn "Outfit N"'),
            ], false, 'Không chọn ảnh có sẵn. Gửi hook = CHỦ ĐỀ cả lô (in lên bìa, worker không tạo hook khác), outfit.tags = 1-5 hashtag chủ đề để worker tìm outfit trên OOTD (vd henho, sangchanh, tet), outfit.count = tổng số ảnh lô (gồm 1 bìa), outfit.product_id nếu muốn lô gắn 1 sản phẩm. Caption: kết bằng câu hỏi "Bạn chọn outfit số mấy?".', 'outfit_batch'),
            self::sample('one_shoe_many_looks', '1 đôi N cách phối', 'outfit', 'save', $photo, 3, 6, [
                self::rule('first', 'product', true, 'Ảnh đôi giày + hook "1 đôi ... phối N kiểu"'),
                self::rule('each', 'outfit', true, 'Mỗi outfit có đôi giày cùng kiểu, chữ = dịp / phong cách'),
            ], false, 'Slide đầu là giày, các slide sau là outfit mang kiểu giày tương tự.'),
            self::sample('box_to_feet', 'Từ hộp lên chân', 'outfit', 'sale', $photo, 2, 4, [
                self::rule('first', 'product', true, 'Giày trong hộp, chữ bỏ lửng "Đôi này..."'),
                self::rule('middle', 'outfit', true, 'Outfit mang ĐÚNG kiểu giày đó "...mang lên thế này"'),
                self::rule('last', 'any', true, 'Chốt mã / size'),
            ], false, 'Chỉ dùng khi có ảnh phối đồ mang đúng kiểu giày của ảnh sản phẩm (xem ảnh để chắc).'),
            // Cũng lấy ảnh bằng LÔ PHỐI ĐỒ mới (người dùng chốt 2026-11-21): worker in nhãn ngày (outfit.labels) lên ảnh
            // outfit thay "Outfit N" để số không lệch với ngày. a90-fe note/xay-kenh-tiktok/bai-viet-mau/outfit_by_day.txt
            [...self::sample('outfit_by_day', 'Outfit theo ngày / dịp', 'outfit', 'save', $photo, 3, 8, [
                self::rule('first', 'outfit', false, 'Ảnh bìa lô phối đồ: worker in đúng hook "N ngày ... mặc gì?" lên bìa'),
                self::rule('each', 'outfit', false, 'Ảnh outfit của lô, worker in nhãn ngày (outfit.labels) thay "Outfit N"'),
            ], false, 'Không chọn ảnh có sẵn. Hook = "N ngày ... mặc gì?" (Tết, tuần đi làm, chuyến du lịch), in lên bìa. outfit.count = tổng số ảnh lô (gồm 1 bìa); outfit.labels = đúng outfit.count - 1 nhãn theo thứ tự ngày, dạng "<Ngày>: <mô tả 2-4 từ>" ("Mùng 1: Đi chúc Tết", "Thứ 2: Họp đầu tuần"; mô tả theo dịp, không tả món vì worker chọn outfit theo hashtag), tối đa '.self::LABEL_MAX.' ký tự, không emoji; outfit.tags = 1-5 hashtag chủ đề tìm outfit trên OOTD; outfit.product_id tuỳ chọn. Caption kiểu "Lưu lại kẻo ... đứng trước tủ đồ".', 'outfit_batch'),
                'outfit_labels' => true],

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
