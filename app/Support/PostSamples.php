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
    /** Nhóm theo cách sản xuất (người dùng chốt 2026-11-23): bài ra video -> video (kể cả dựng từ ảnh phối đồ); bài có
     * ảnh phối đồ (kể cả lai ảnh sản phẩm) -> outfit; còn lại -> product. Mục đích bài xem field goal. */
    public const GROUPS = [
        'outfit' => 'Phối đồ',
        'product' => 'Ảnh sản phẩm',
        'video' => 'Video',
    ];

    public const GOALS = ['reach', 'engagement', 'save', 'sale', 'trust'];

    public const IMAGE_KINDS = ['product', 'outfit', 'any'];

    /**
     * Bài viết mẫu ĐÃ LÀM XONG (người dùng chốt 2026-11-21): chỉ những bài này được bật (chọn vào tuyến nội dung, lập kế
     * hoạch, gắn template chữ). Bài chưa làm xong vẫn nằm trong thư viện (hiện "Chưa làm xong") để giữ tên cho bài cũ.
     * Làm xong 1 bài (a90-fe note/xay-kenh-tiktok/bai-viet-mau/<key>.txt "ĐÃ LÀM") thì thêm key vào đây.
     * config ai90.post_samples_enabled ghi đè (vd test: ['*'] = bật hết).
     */
    public const READY = ['top_n', 'shoe_by_outfit', 'lookbook', 'outfit_by_day', 'one_shoe_many_looks', 'unboxing', 'video_type_01'];

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
            // ---- Trưng bày hàng (nhóm product; video_type_01 nhóm video) ----
            // Kiểu bài ảnh chủ shop hay đăng (người dùng chốt 2026-11-21, giữ key unboxing): KHÔNG chọn ảnh có sẵn; bài kế
            // hoạch chỉ ghi 1 sản phẩm, duyệt kỳ -> A51 tạo LÔ TỰ ĐỘNG mới (sản phẩm x 1 nhóm Creative, mỗi Creative 1 ảnh)
            // làm ảnh của bài, ảnh KHÔNG chữ. a90-fe note/xay-kenh-tiktok/bai-viet-mau/unboxing.txt
            self::sample('unboxing', 'Unbox - basic', 'product', 'sale', $photo, 1, \App\Models\SocialPost::MAX_IMAGES, [
                self::rule('each', 'product', false, 'Ảnh lô tự động của sản phẩm (mỗi Creative trong nhóm 1 ảnh), không chữ'),
            ], true, 'Không chọn ảnh có sẵn, không chữ trên ảnh. Chọn 1 sản phẩm hợp chủ đề (kehoach.py products theo tag chủ đề, ưu tiên used thấp / lâu chưa dùng) -> product_batch.product_id; duyệt kỳ A51 tạo lô tự động (sản phẩm x 1 nhóm Creative) làm ảnh. KHÔNG viết hook: chỉ viết tiêu đề, A51 lấy tiêu đề làm hook (không trùng tiêu đề + sản phẩm bài khác). Tiêu đề nêu dịp / tình huống của chủ đề + điểm nổi bật của đôi giày (theo nhãn + tag), giọng chân dung khách hàng.', self::SOURCE_PRODUCT_BATCH),
            self::sample('closeup', 'Soi cận cảnh', 'product', 'trust', $photo, 2, 4, [
                self::rule('first', 'product', true, 'Toàn cảnh + hook "Soi kỹ trước khi mua"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 chi tiết: đường may, đế, lót, logo'),
            ], true, 'Chứng minh chất lượng. Chữ mỗi slide nêu đúng 1 chi tiết, ngắn (<= 8 từ).'),
            // new_arrival (Hàng mới về): đã bỏ 2026-11-22 (người dùng chốt), chưa từng có bài / tuyến dùng
            self::sample('color_options', '1 mẫu nhiều màu', 'product', 'sale', $photo, 2, 6, [
                self::rule('first', 'product', true, 'Hook "Bạn chọn màu nào?"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 màu của cùng kiểu, chữ = tên màu'),
            ], false, 'Dùng khi có nhiều ảnh cùng kiểu khác màu. Kéo comment chọn màu.'),

            // Bài VIDEO giữ nguyên luồng video hiện nay (người dùng chốt 2026-11-21, tên "Video - Type 01"): lệnh chọn 1 sản
            // phẩm hợp chủ đề (product_id), A51 chọn video template đang bật (ngẫu nhiên trong các template sản phẩm đủ ảnh) +
            // ảnh của sản phẩm (ít dùng cho video trước, mới trước) như nút "Tạo video"; worker đăng bài dựng video từ ảnh rồi
            // đăng. a90-fe note/xay-kenh-tiktok/bai-viet-mau/video_type_01.txt
            self::sample('video_type_01', 'Video - Type 01', 'video', 'sale', ['video'], 1, \App\Models\SocialPost::MAX_IMAGES, [
                self::rule('each', 'product', false, 'Ảnh của 1 sản phẩm, A51 chọn theo video template, không chữ'),
            ], true, 'Chỉ dùng cho slot VIDEO. Không chọn ảnh, không chọn template: gửi product_id = 1 sản phẩm hợp chủ đề (kehoach.py products theo tag chủ đề, ưu tiên used thấp / lâu chưa dùng); A51 tự chọn video template + ảnh của sản phẩm, worker đăng bài dựng video rồi đăng. KHÔNG viết hook: chỉ viết tiêu đề, A51 lấy tiêu đề làm hook. Tiêu đề nêu dịp / tình huống + điểm nổi bật của đôi giày.', self::SOURCE_PRODUCT_VIDEO),

            // ---- So sánh, chọn lựa (nhóm product) ----
            self::sample('this_or_that', 'A hay B', 'product', 'engagement', $photo, 2, 3, [
                self::rule('first', 'product', true, 'Đôi A + câu hỏi "... chọn A hay B?"'),
                self::rule('each', 'product', true, 'Chữ "A: ..." / "B: ..." mô tả ngắn'),
            ], false, '2 đôi khác kiểu cùng tầm giá / cùng màu để khó chọn. Hook theo tình huống (đi làm, hẹn hò, dịp lễ).'),
            // Bìa GHÉP (người dùng chốt 2026-11-21): lệnh chỉ gửi N ảnh #1..#N, CONTENT-WORKER dựng thêm bìa ghép các ảnh
            // đó + in hook (A51 không dựng). a90-fe note/xay-kenh-tiktok/bai-viet-mau/top_n.txt
            [...self::sample('top_n', 'Top N', 'product', 'save', $photo, 5, 8, [
                self::rule('first', 'product', true, 'Bìa GHÉP 4 ảnh đầu (N = 4-5) hoặc 6 ảnh đầu (N >= 6), content-worker dựng + hook "Top N đôi ..."'),
                self::rule('each', 'product', true, 'Mỗi slide 1 mẫu, chữ "#1 ...", "#2 ..." + lý do ngắn'),
            ], false, 'Danh sách xếp hạng theo 1 tiêu chí (đi chúc Tết, đi làm cả ngày, đi chơi...). Hook = "Top N đôi ..." với N = số ảnh gửi. CHỈ gửi N ảnh sản phẩm của N mẫu KHÁC NHAU (4-7, #1 trước), KHÔNG gửi ảnh bìa: content-worker tự dựng bìa ghép 4 ảnh đầu (N = 4-5) hoặc 6 ảnh đầu (N >= 6) + in hook. Chữ ảnh thứ k bắt đầu "#k " rồi kiểu giày + lý do 2-4 từ ("#1 Cao gót mũi nhọn, sang chảnh"). Không nêu giá nếu Yêu cầu kênh không có. Caption kiểu "Bạn chốt số mấy? Lưu lại để sắm".'),
                'cover_collage' => true],
            // Chọn giày theo trang phục (người dùng chốt 2026-11-24, a90-fe note/xay-kenh-tiktok/bai-viet-mau/shoe_by_outfit.txt):
            // lệnh gửi 3-6 ảnh giày (mỗi ảnh 1 sản phẩm khác nhau) + chữ "<trang phục> → <loại giày>, <lý do>"; CONTENT-WORKER
            // dựng bìa theo mẫu bìa OOTD "05 · Tạp chí" (ảnh đầu + hook) và in chữ từng slide
            [...self::sample('shoe_by_outfit', 'Chọn giày theo trang phục', 'product', 'save', $photo, 4, 7, [
                self::rule('first', 'product', true, 'Bìa: content-worker dựng theo mẫu bìa "05 · Tạp chí" (ảnh giày đầu + hook)'),
                self::rule('each', 'product', true, 'Mỗi slide 1 trang phục + 1 đôi giày shop: "<trang phục>: <loại giày>, <lý do>"'),
            ], false, 'Hướng dẫn chọn giày theo trang phục. CHỈ gửi 3-6 ảnh giày của 3-6 sản phẩm KHÁC NHAU (thường khác loại giày), KHÔNG gửi ảnh bìa: content-worker tự dựng bìa từ ảnh đầu + hook. Mỗi ảnh có chữ "<trang phục>: <loại giày>, <lý do 2-5 từ>" (vd "Váy maxi: sandal quai mảnh, nhẹ chân"; không dùng mũi tên), trang phục mỗi slide khác nhau, <= 45 ký tự, không emoji. Hook hướng dẫn chung, không nêu màu / tên đôi cụ thể (vd "Mặc gì đi giày gì? Lưu lại kẻo quên"; cụm trong *...* tô hồng, không bắt buộc). Caption kiểu "Bạn hay mặc kiểu nào nhất? Lưu lại để phối".'),
                'cover_collage' => true],
            self::sample('which_suits_you', 'Bạn hợp đôi nào', 'product', 'engagement', $photo, 3, 6, [
                self::rule('first', 'any', true, 'Hook "Bạn hợp đôi nào theo ...?"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 nhóm (phong cách / tính cách / cung) + 1 đôi'),
            ], false, 'Gắn giày với phong cách / tính cách. Người xem tag bạn bè, comment nhóm của mình.'),

            // ---- Phối đồ (nhóm outfit) ----
            // Ảnh lấy bằng LÔ PHỐI ĐỒ mới (người dùng chốt 2026-11-21): bài kế hoạch ghi số ảnh, duyệt kỳ -> tạo lô phối đồ,
            // worker phối đồ chạy như luồng hiện tại (ảnh 1 = bìa có hook do worker đặt, ảnh 2..N = outfit có "Outfit N")
            self::sample('lookbook', 'Outfit - basic', 'outfit', 'save', $photo, 3, 9, [
                self::rule('first', 'outfit', false, 'Ảnh bìa lô phối đồ: worker in đúng hook (chủ đề) của bài lên bìa'),
                self::rule('each', 'outfit', false, 'Ảnh outfit của lô, đã in sẵn "Outfit N"'),
            ], false, 'Không chọn ảnh có sẵn. Gửi hook = CHỦ ĐỀ cả lô (in lên bìa, worker không tạo hook khác), outfit.tags = 1-5 hashtag chủ đề để worker tìm outfit trên OOTD (vd henho, sangchanh, tet), outfit.count = tổng số ảnh lô (gồm 1 bìa), outfit.product_id nếu muốn lô gắn 1 sản phẩm. Caption: kết bằng câu hỏi "Bạn chọn outfit số mấy?".', 'outfit_batch'),
            // Lô phối đồ GẮN SẢN PHẨM (người dùng chốt 2026-11-23/24): worker phối đồ chọn outfit OOTD theo hashtag chủ đề +
            // cùng loại giày với sản phẩm (tags.kieu), vẽ bằng Google Flow với ĐÚNG đôi giày của ảnh sản phẩm, không chữ. Lô
            // KHÔNG có ảnh bìa: ảnh outfit xong -> CONTENT-WORKER dựng bìa = 1 ảnh lịch sử tạo ảnh của sản phẩm + hook in phía
            // trên kiểu bìa tạp chí (cover_collage: thêm bìa ở đầu). a90-fe note/xay-kenh-tiktok/bai-viet-mau/one_shoe_many_looks.txt
            [...self::sample('one_shoe_many_looks', 'Outfit - theo sản phẩm', 'outfit', 'save', $photo, 4, 6, [
                self::rule('first', 'product', false, 'Bìa: content-worker lấy 1 ảnh lịch sử tạo ảnh của sản phẩm, in hook theo mẫu bìa OOTD "05 · Tạp chí"'),
                self::rule('each', 'outfit', false, 'Ảnh outfit mang ĐÚNG đôi giày sản phẩm (Flow vẽ), không chữ'),
            ], true, 'Không chọn ảnh có sẵn. BẮT BUỘC outfit.product_id = 1 sản phẩm đã có tag kieu (loại giày, chạy /gan-tag-san-pham nếu thiếu). Hook kiểu HƯỚNG DẪN PHỐI ĐỒ THEO LOẠI GIÀY: nêu loại giày của sản phẩm (cao gót, sneaker, sandal, dép quai...), KHÔNG nêu màu / tên / hãng đôi cụ thể, ~45 ký tự, vd "Cao gót mặc gì cho sang?", "Mix đồ với dép quai: *4 cách* đi chơi" (cụm trong *...* tô hồng, không bắt buộc); content-worker in lên bìa (ảnh lịch sử tạo ảnh của sản phẩm) theo mẫu bìa "05 · Tạp chí": nhãn "LOOK CỦA TUẦN", tiêu đề chữ có chân căn trái, dòng phụ "N cách phối • kéo sang xem →". outfit.count = tổng số ảnh lô (gồm 1 bìa); outfit.tags = 1-5 hashtag chủ đề tìm outfit trên OOTD (worker lọc thêm outfit cùng loại giày với sản phẩm). Caption kết bằng "Bạn thích kiểu số mấy?".', 'outfit_batch'),
                'outfit_mode' => 'product', 'cover_collage' => true],
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

            // ---- Kể chuyện (nhóm product; before_after nhóm outfit) ----
            self::sample('pov', 'POV', 'product', 'reach', $photo, 2, 3, [
                self::rule('first', 'any', true, 'Câu "POV: ..." hài, dễ đồng cảm'),
                self::rule('last', 'product', true, 'Lộ ra đôi giày + câu chốt vui'),
            ], false, 'Câu POV là trọng tâm, viết như người thật nói. Không quảng cáo lộ liễu ở slide đầu.'),
            self::sample('meme_types', 'Meme "N kiểu người"', 'product', 'reach', $photo, 3, 5, [
                self::rule('first', 'any', true, 'Hook "Khách mua giày có N loại"'),
                self::rule('each', 'any', true, 'Mỗi slide 1 kiểu người + 1 đôi giày hợp'),
            ], false, 'Hài nhẹ nhàng, không chê khách. Người xem tag bạn bè.'),
            self::sample('before_after', 'Trước / sau', 'outfit', 'reach', $photo, 2, 4, [
                self::rule('first', 'any', true, '"Trước khi có đôi này..."'),
                self::rule('last', 'outfit', true, '"...và sau khi có" (outfit đẹp)'),
            ], false, 'Đối lập rõ: trước (bình thường / lúng túng) và sau (xinh, tự tin).'),

            // ---- Hữu ích (nhóm product) ----
            self::sample('size_guide', 'Chọn size', 'product', 'trust', $photo, 2, 4, [
                self::rule('first', 'product', true, 'Hook "Mua giày online sợ sai size?"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 mẹo ngắn chọn size'),
            ], false, 'Mẹo thực tế, ngắn gọn. Chữ trên ảnh tối đa 2 dòng mỗi slide.'),
            self::sample('care_tips', 'Bảo quản / mẹo', 'product', 'save', $photo, 2, 5, [
                self::rule('first', 'product', true, 'Hook mẹo (đi cao gót không đau, giữ giày trắng...)'),
                self::rule('each', 'product', true, 'Mỗi slide 1 mẹo'),
            ], false, 'Nội dung đáng lưu lại. Mỗi slide đúng 1 mẹo.'),
            self::sample('faq', 'Hỏi đáp', 'product', 'trust', $photo, 2, 5, [
                self::rule('first', 'product', true, 'Câu hỏi khách hay hỏi'),
                self::rule('each', 'product', true, 'Câu trả lời ngắn'),
            ], false, 'Lấy câu hỏi thật hay gặp (ship, đổi size, chất liệu). Trả lời thẳng.'),

            // ---- Gợi ý theo người nhận (nhóm product) ----
            self::sample('gift_by_receiver', 'Quà theo người nhận', 'product', 'sale', $photo, 3, 6, [
                self::rule('first', 'product', true, 'Hook "Hết đau đầu chọn quà ..."'),
                self::rule('each', 'product', true, 'Mỗi slide 1 người nhận: "Tặng mẹ: ...", "Tặng bạn thân: ..."'),
            ], false, 'Gắn mỗi đôi với 1 người nhận hợp tuổi / phong cách. Hợp mùa lễ, Tết, 8/3, 20/10.'),
            self::sample('by_budget', 'Theo mức giá', 'product', 'sale', $photo, 3, 6, [
                self::rule('first', 'product', true, 'Hook "Giày xinh dưới ...k"'),
                self::rule('each', 'product', true, 'Mỗi slide 1 mẫu trong tầm giá'),
            ], false, 'Chỉ dùng khi chân dung khách hàng có mức giá. Không bịa giá cụ thể nếu không có.'),

            // ---- Chuỗi bài (nhóm video) ----
            self::sample('daily_drop', 'Mỗi ngày 1 mẫu / đếm ngược', 'video', 'engagement', $both, 1, 3, [
                self::rule('first', 'product', true, '"Còn N ngày nữa là ..." hoặc "Mẫu hôm nay"'),
                self::rule('each', 'product', false, 'Góc khác của mẫu hôm nay'),
            ], true, 'Bài nối tiếp mỗi ngày, đếm ngược tới dịp lễ gần nhất trong kỳ. Số ngày phải đúng với ngày đăng.'),
            self::sample('numbered', 'Đánh số tập', 'video', 'engagement', $both, 1, 4, [
                self::rule('first', 'any', true, '"Outfit số N" / "Tập N: ..."'),
                self::rule('each', 'any', false, ''),
            ], false, 'Số tập tăng dần theo các bài numbered trước của kênh.'),

            // ---- Ưu đãi (nhóm product) ----
            self::sample('sale', 'Ưu đãi', 'product', 'sale', $photo, 1, 4, [
                self::rule('first', 'product', true, 'Hook ưu đãi / số lượng có hạn'),
                self::rule('each', 'product', true, 'Mẫu + lợi ích ngắn'),
            ], false, 'Không bịa mức giảm giá cụ thể nếu Yêu cầu của kênh không nêu. Dùng ít (tỉ lệ thấp).'),
        ];
    }
}
