# AI90 — Backend API

Laravel 12 API cho AI90: Creator tạo AI Creative, người mua tải ảnh sản phẩm và tạo ảnh bằng Credit.
Frontend (React) là một dự án riêng.

- Đặc tả nghiệp vụ: `AI90_Dac_Ta_Nghiep_Vu_Backend_Unicode.pdf`
- Thiết kế kỹ thuật MVP (schema, luồng, API): `note/thiet-ke-mvp.md`

## Yêu cầu

- PHP 8.3 (OSPanel: `D:\OSPanel\modules\PHP-8.3\PHP`)
- MySQL 8.0 (OSPanel: host `127.127.126.26`, user `root`, không mật khẩu)
- Composer 2 (OSPanel: `D:\OSPanel\data\PHP-8.3\default\composer\composer.phar`)

## Cài đặt

```bash
composer install
cp .env.example .env
php artisan key:generate
# tạo database a90 (và a90_test để chạy test), utf8mb4
php artisan migrate --seed
```

Seeder tạo: cấu hình mặc định (`settings`), provider Gemini + model `gemini-2.5-flash-image`, danh mục,
và tài khoản admin local `admin@a90.local` / `password` (đổi bằng `ADMIN_EMAIL`, `ADMIN_PASSWORD`).

## Chạy

Cần **3 tiến trình**:

```bash
php artisan serve                 # hoặc chạy qua OSPanel tại http://a90.local
php artisan queue:work --tries=4  # xử lý tạo ảnh (bắt buộc)
php artisan schedule:work         # giải phóng doanh thu mỗi giờ, quét ảnh bị kẹt mỗi 5 phút
```

Trên production, thay `schedule:work` bằng cron `* * * * * php artisan schedule:run`, và chạy `queue:work` bằng Supervisor.

## Lưu ảnh

- Local: `MEDIA_DISK=media`, ảnh nằm ở `storage/app/media`, trả về qua signed URL `/media/...`.
- Production: `MEDIA_DISK=s3` và điền `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`.
  Bucket để private. Tạo **S3 Lifecycle Rule** xóa object sau 365 ngày cho prefix `generations/`.

## Test

```bash
php artisan test
```

Test chạy trên MySQL database `a90_test` (cấu hình trong `phpunit.xml`). Gemini được giả lập bằng `Http::fake`.

## API

Tiền tố `/api/v1`, xác thực bằng header `Authorization: Bearer <token>` (Sanctum, token hết hạn sau 30 ngày).
Lỗi nghiệp vụ trả về `{ "message", "code", "context" }`. Danh sách endpoint và mã lỗi: xem `note/thiet-ke-mvp.md` mục 6,
hoặc chạy `php artisan route:list --path=api`.
