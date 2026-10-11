# AI90 — Backend API

Backend cho AI90 viết bằng **Laravel 12**. Creator tạo AI Creative, người mua tải ảnh sản phẩm lên và tạo ảnh bằng Credit.

- Dự án này gồm **API** cho frontend React (một dự án riêng) và **trang quản trị** AdminLTE tại `/admin`.
- Đặc tả nghiệp vụ: `AI90_Dac_Ta_Nghiep_Vu_Backend_Unicode.pdf`
- Thiết kế kỹ thuật (database, luồng xử lý, danh sách API, mã lỗi): `note/thiet-ke-mvp.md`

---

## Mục lục

1. [Yêu cầu](#1-yêu-cầu)
2. [Cài đặt trên Windows + OSPanel](#2-cài-đặt-trên-windows--ospanel)
3. [Chạy dự án](#3-chạy-dự-án)
4. [Thử nhanh toàn bộ luồng](#4-thử-nhanh-toàn-bộ-luồng)
5. [Chạy test](#5-chạy-test)
6. [Biến môi trường](#6-biến-môi-trường)
7. [Triển khai production](#7-triển-khai-production)
8. [Trang quản trị](#8-trang-quản-trị)
9. [Xử lý sự cố](#9-xử-lý-sự-cố)

---

## 1. Yêu cầu

| Thành phần | Phiên bản | Ghi chú |
|---|---|---|
| PHP | **8.2 trở lên** (khuyên dùng 8.3) | Cần extension: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd`, `curl`, `bcmath`, `intl` |
| MySQL | 8.0 | Hoặc MariaDB 10.6+ |
| Composer | 2.x | |
| Git | bất kỳ | |

> ⚠️ Laravel 12 **không chạy trên PHP 8.1**. Nếu gõ `php -v` ra 8.1, xem [mục 9](#9-xử-lý-sự-cố).

Không cần Node.js, vì trang quản trị tải AdminLTE từ CDN.

---

## 2. Cài đặt trên Windows + OSPanel

Các lệnh dưới đây chạy trong **PowerShell**, tại thư mục dự án `D:\OSPanel\home\a90.local`.

### Bước 1. Lấy mã nguồn

```powershell
cd D:\OSPanel\home
git clone https://github.com/vocuc/a90-be.git a90.local
cd a90.local
```

### Bước 2. Dùng PHP 8.3 và Composer của OSPanel

Mỗi lần mở terminal mới, chạy dòng sau để `php` trỏ đúng sang PHP 8.3:

```powershell
$env:Path = "D:\OSPanel\modules\PHP-8.3\PHP;" + $env:Path
php -v   # phải ra PHP 8.3.x
```

Composer có sẵn trong OSPanel. Tạo alias cho tiện:

```powershell
function composer { php D:\OSPanel\data\PHP-8.3\default\composer\composer.phar @args }
composer -V
```

### Bước 3. Cài thư viện

```powershell
composer install
```

### Bước 4. Tạo file `.env`

```powershell
copy .env.example .env
php artisan key:generate
```

`.env.example` đã cấu hình sẵn cho OSPanel: MySQL 8.0 ở host `127.127.126.26`, user `root`, không mật khẩu. Nếu máy bạn khác, sửa các dòng `DB_*` (xem [mục 6](#6-biến-môi-trường)).

> 🔑 **Đừng đổi `APP_KEY` sau khi đã có dữ liệu.** API key Gemini của Creator được mã hóa bằng `APP_KEY`; đổi khóa thì toàn bộ key cũ không giải mã được nữa.

### Bước 5. Tạo database

Bật module **MySQL-8.0** trong OSPanel, rồi chạy:

```powershell
D:\OSPanel\modules\MySQL-8.0\bin\mysql.exe -h127.127.126.26 -uroot -e "CREATE DATABASE IF NOT EXISTS a90 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS a90_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

- `a90`: database chính.
- `a90_test`: chỉ dùng khi chạy test.

### Bước 6. Tạo bảng và dữ liệu mẫu

```powershell
php artisan migrate --seed
```

Seeder tạo sẵn:
- cấu hình mặc định (Creator nhận 10% giá mỗi ảnh, giữ doanh thu 7 ngày, tối đa 5 ảnh mỗi lần…);
- provider **Google Gemini** và model `gemini-2.5-flash-image`;
- 7 danh mục;
- tài khoản admin: **`admin@a90.local` / `password`**. Đổi bằng cách đặt `ADMIN_EMAIL`, `ADMIN_PASSWORD` trong `.env` trước khi seed.

### Bước 7. Gắn domain `a90.local` trong OSPanel

Dự án đã có sẵn file `.osp/project.ini` (PHP 8.3, thư mục web `public`). **Khởi động lại OSPanel** để nó nhận domain mới, rồi mở:

- API: `http://a90.local/api/v1/categories` (sẽ trả về danh sách danh mục dạng JSON)
- Trang quản trị: `http://a90.local/admin`

> Không dùng OSPanel? Chạy `php artisan serve` rồi mở `http://127.0.0.1:8000`. Khi đó đổi `APP_URL=http://127.0.0.1:8000` trong `.env` để link ảnh đúng.

---

## 3. Chạy dự án

Tạo ảnh chạy **bất đồng bộ qua queue**, nên ngoài web server bạn cần chạy thêm **2 tiến trình**, mỗi tiến trình một cửa sổ terminal (nhớ chạy lại lệnh `$env:Path` ở bước 2):

```powershell
# Terminal 1: worker xử lý tạo ảnh. BẮT BUỘC, thiếu nó thì ảnh mãi ở trạng thái "queued".
php artisan queue:work --tries=4 --timeout=300

# Terminal 2: lịch chạy định kỳ.
php artisan schedule:work
```

| Tiến trình | Làm gì |
|---|---|
| Web (OSPanel hoặc `artisan serve`) | Nhận request API và trang quản trị |
| `queue:work` | Gọi Gemini, lưu ảnh, chốt Transaction, trừ hoặc hoàn Credit |
| `schedule:work` | Mỗi giờ: chuyển doanh thu Creator đủ 7 ngày sang `available`. Mỗi 5 phút: đánh dấu thất bại và hoàn Credit cho ảnh bị kẹt |

> Sửa code PHP xong thì **khởi động lại `queue:work`**. Worker giữ code cũ trong bộ nhớ.

---

## 4. Thử nhanh toàn bộ luồng

Bạn cần một **API key Gemini thật**, lấy tại https://aistudio.google.com/apikey.

Các lệnh dưới dùng `curl.exe` trong PowerShell. Mọi request API phải gửi header `Accept: application/json`.

```powershell
$api = "http://a90.local/api/v1"
$h = @{ Accept = "application/json" }
```

**1) Đăng ký tài khoản, sau đó dùng chính tài khoản này làm Creator**

```powershell
$r = Invoke-RestMethod -Method Post "$api/auth/register" -Headers $h -Body @{
  name = "Creator A"; email = "creator@test.com"; password = "secret123"; password_confirmation = "secret123" }
$creator = @{ Accept = "application/json"; Authorization = "Bearer $($r.data.token)" }

Invoke-RestMethod -Method Post "$api/creator/profile" -Headers $creator -Body @{ shop_name = "Shop A"; username = "shop-a" }
Invoke-RestMethod -Method Post "$api/creator/api-keys" -Headers $creator -Body @{ provider_id = 1; label = "Key 1"; api_key = "AIza...KEY_CỦA_BẠN" }
```

**2) Tạo Creative, thêm ảnh mẫu, đăng lên sàn**

```powershell
$c = Invoke-RestMethod -Method Post "$api/creator/creatives" -Headers $creator -ContentType "application/json" -Body (@{
  title = "Ảnh sản phẩm trên bàn đá"; model_id = 1; price_per_image = 2000
  creative_prompt = @(@{ label = "Bối cảnh"; value = "Place the product on a white marble table, soft studio lighting, photorealistic" }) } | ConvertTo-Json -Depth 5)
$id = $c.data.id

curl.exe -H "Accept: application/json" -H "Authorization: Bearer $($r.data.token)" -F "image=@D:\anh-mau.jpg" "$api/creator/creatives/$id/images"
Invoke-RestMethod -Method Post "$api/creator/creatives/$id/publish" -Headers $creator
```

**3) Cộng Credit cho người mua**

Tạo tài khoản người mua thứ hai giống bước 1. Sau đó vào `http://a90.local/admin`, chọn **Người dùng**, mở người mua và dùng ô **Điều chỉnh Credit** để cộng, ví dụ 20.000.

**4) Người mua tạo ảnh và theo dõi kết quả**

```powershell
curl.exe -H "Accept: application/json" -H "Authorization: Bearer <TOKEN_NGƯỜI_MUA>" `
  -F "creative_id=$id" -F "input_image=@D:\san-pham.png" -F "quantity=2" -F "aspect_ratio=1:1" `
  -F "idempotency_key=$([guid]::NewGuid())" "$api/generations"
# Response 202 trả về "id". Gọi status định kỳ cho tới khi status = completed hoặc failed:
curl.exe -H "Accept: application/json" -H "Authorization: Bearer <TOKEN_NGƯỜI_MUA>" "$api/generations/<ID>/status"
```

Kết quả trong `outputs[].image_url` là link có hạn 15 phút. Gọi `GET /generations/<ID>/outputs/<OUTPUT_ID>/download` để lấy link tải về.

Danh sách đầy đủ API: chạy `php artisan route:list --path=api`, hoặc xem `note/thiet-ke-mvp.md` mục 6.

---

## 5. Chạy test

```powershell
php artisan test
```

- Test chạy trên database **`a90_test`** (đã tạo ở bước 5), cấu hình trong `phpunit.xml`. Mỗi lần chạy, dữ liệu trong database này bị xóa sạch.
- Gemini được giả lập (`Http::fake`), nên không tốn quota và không cần Internet.
- Kiểm tra code style: `php vendor/bin/pint --test`. Tự sửa: `php vendor/bin/pint`.

---

## 6. Biến môi trường

Các biến quan trọng trong `.env`:

| Biến | Mặc định | Ý nghĩa |
|---|---|---|
| `APP_URL` | `http://a90.local` | Dùng để tạo link ảnh. Phải khớp domain bạn truy cập |
| `APP_DEBUG` | `true` | **Đặt `false` trên production** |
| `DB_HOST` / `DB_PORT` | `127.127.126.26` / `3306` | IP MySQL 8.0 của OSPanel. Máy khác thường là `127.0.0.1` |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `a90` / `root` / *(trống)* | |
| `QUEUE_CONNECTION` | `database` | Queue lưu trong bảng `jobs`. Có thể đổi sang `redis` nếu PHP có extension redis |
| `MEDIA_DISK` | `media` | `media` = lưu ở `storage/app/media` (local). `s3` = lưu lên AWS S3 |
| `SIGNED_URL_TTL_MINUTES` | `15` | Thời hạn của link ảnh trả cho frontend |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` | | Bắt buộc khi `MEDIA_DISK=s3` |
| `GEMINI_BASE_URL` | `https://generativelanguage.googleapis.com/v1beta` | |
| `GEMINI_TIMEOUT_SECONDS` | `120` | Thời gian chờ tối đa cho mỗi lần gọi Gemini |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | `admin@a90.local` / `password` | Chỉ dùng khi seed ở môi trường khác production |

Nhóm cấu hình nghiệp vụ (phí, số ngày giữ doanh thu, số ảnh tối đa…) **không nằm trong `.env`**. Sửa chúng ở trang quản trị, mục **Cấu hình**.

**Không cần mở CORS thêm.** Laravel đã cho phép frontend React ở domain khác gọi `/api/*`. Muốn giới hạn domain thì chạy `php artisan config:publish cors` rồi sửa `allowed_origins`.

---

## 7. Triển khai production

Các bước trên server Linux:

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=AiModelSeeder --force   # lần đầu, hoặc khi thêm model mới
php artisan db:seed --class=SettingSeeder --force   # lần đầu
php artisan db:seed --class=CategorySeeder --force  # lần đầu
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

Seeder **không tạo tài khoản admin trên production**. Để tạo admin, đăng ký tài khoản bình thường, rồi chạy:

```bash
php artisan tinker --execute="App\Models\User::where('email','you@domain.com')->update(['role'=>'admin'])"
```

**Chạy nền.** Dùng **Supervisor** cho queue worker:

```ini
[program:a90-worker]
command=php /var/www/a90/artisan queue:work --tries=4 --timeout=300 --sleep=1
numprocs=2
autostart=true
autorestart=true
user=www-data
stopwaitsecs=320
```

và **cron** cho scheduler:

```cron
* * * * * cd /var/www/a90 && php artisan schedule:run >> /dev/null 2>&1
```

**Lưu ảnh trên S3:**
- Đặt `MEDIA_DISK=s3` và điền các biến `AWS_*`.
- Để bucket ở chế độ **private**. Backend chỉ trả link có ký số, không cần public.
- Tạo **S3 Lifecycle Rule** xóa object sau **365 ngày** cho prefix `generations/`. Đây là yêu cầu "xóa ảnh sau 1 năm".

**Checklist trước khi chạy thật:**
- `APP_ENV=production`, `APP_DEBUG=false`
- Đổi mật khẩu admin
- Sao lưu `APP_KEY` cẩn thận. Mất khóa này thì mất toàn bộ API key của Creator
- Bật HTTPS

---

## 8. Trang quản trị

Địa chỉ: `/admin`. Chỉ tài khoản có `role = admin` đăng nhập được. Đăng nhập sai 5 lần sẽ bị khóa 5 phút.

| Màn hình | Chức năng |
|---|---|
| Dashboard | Số liệu tổng quan, lượt tạo ảnh gần đây, Creative dùng nhiều nhất |
| Người dùng | Tìm kiếm, xem chi tiết, **cộng/trừ Credit**, khóa/mở khóa (khóa sẽ thu hồi mọi token API) |
| Creator | Cấp/thu hồi **verified**, đặt **% Creator nhận riêng** (để trống = dùng mức mặc định) |
| AI Creative | Xem chi tiết kể cả prompt, **ẩn/hiện** trên Marketplace |
| Danh mục | Thêm/sửa tên, slug, **icon** (Material Symbols), thứ tự; **bật/tắt**; xóa (chỉ khi chưa có Creative) |
| Lượt tạo ảnh | Xem từng ảnh, lỗi, Transaction, key đã dùng, prompt đã gửi |
| Transaction · Sổ cái Credit · Doanh thu Creator | Tra cứu (chỉ đọc) |
| Cấu hình | Phí mặc định, số ngày giữ doanh thu, giới hạn số ảnh, dung lượng ảnh, thời gian tạm nghỉ của key |
| Nhật ký hệ thống | Toàn bộ audit log |

Giao diện dùng AdminLTE 3.2 tải từ CDN jsDelivr, nên máy admin cần có Internet.

---

## 9. Xử lý sự cố

| Triệu chứng | Nguyên nhân và cách sửa |
|---|---|
| `php -v` ra 8.1, hoặc composer báo cần PHP ≥ 8.2 | Terminal đang dùng PHP khác. Chạy lại lệnh `$env:Path = ...` ở bước 2 |
| `SQLSTATE[HY000] [2002]` khi migrate | MySQL chưa bật, hoặc sai `DB_HOST`. Bật module MySQL-8.0 trong OSPanel. IP của module xem trong `D:\OSPanel\config\MySQL-8.0\default\settings.ini` |
| Tạo ảnh xong nhưng mãi ở trạng thái `queued` | Chưa chạy `php artisan queue:work`. Sau 30 phút, scheduler sẽ tự hoàn Credit cho các ảnh này |
| Sửa code mà kết quả tạo ảnh không đổi | Worker giữ code cũ. Khởi động lại `queue:work` |
| Ảnh lỗi với mã `NO_USABLE_API_KEY` | Mọi key Gemini của Creator đều sai hoặc hết quota. Creator cần thêm key mới rồi đăng lại Creative (Creative đã bị tự tạm dừng) |
| Ảnh lỗi với mã `CONTENT_BLOCKED` | Gemini chặn vì chính sách an toàn. Credit đã được hoàn |
| Link ảnh báo 403 hoặc hết hạn | Link chỉ sống 15 phút. Gọi lại API để lấy link mới. Kiểm tra `APP_URL` khớp domain đang dùng |
| Thêm key Gemini báo `API_KEY_INVALID` | Key sai, hoặc chưa bật Generative Language API cho project của key đó |
| Không vào được `http://a90.local` | Khởi động lại OSPanel để nhận `.osp/project.ini`. Hoặc dùng `php artisan serve` |
| `php artisan test` lỗi kết nối DB | Chưa tạo database `a90_test`, hoặc `DB_HOST` trong `.env` sai. Test dùng chung host với `.env` |
| Muốn làm lại database từ đầu | `php artisan migrate:fresh --seed`. **Lệnh này xóa toàn bộ dữ liệu** |

Log lỗi nằm ở `storage/logs/laravel.log`.
