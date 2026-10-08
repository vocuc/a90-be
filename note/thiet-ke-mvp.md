# AI90 — Thiết kế kỹ thuật MVP (Backend API)

> Nguồn: `AI90_Dac_Ta_Nghiep_Vu_Backend_Unicode.pdf` + các câu trả lời trong `note/111.text`, `112.text`, `113.text`.
> Trạng thái: **đã triển khai** (code trong repo, 24 feature test pass). Mục 9 đang dùng phương án đề xuất.

---

## 1. Phạm vi MVP

**Có trong MVP**
- Đăng ký, đăng nhập (email + mật khẩu, Sanctum token).
- Creator: tạo Creator Profile, quản lý key Gemini, tạo/sửa/tạo thử/đăng/tạm dừng/lưu trữ Creative.
- Marketplace công khai: danh sách, chi tiết Creative, trang Creator.
- Người mua: tải ảnh, chọn số lượng và tỉ lệ khung hình, tạo ảnh (bất đồng bộ), xem trạng thái, tải kết quả, retry từng ảnh.
- Credit: ví, sổ cái (ledger), trừ và hoàn Credit tự động.
- Creator Revenue và ví Creator (pending sau 7 ngày chuyển sang available).
- Admin: **chỉ** API cộng Credit cho user.
- Audit log cho các hành động tài chính và Creative.

**Chưa làm (giai đoạn sau)**
Rút tiền, cổng thanh toán, Collection, Rating, Follow, thông báo cho Creator, các API admin khác (ẩn Creative, cấp verified, hoàn tiền Transaction đã completed). Các bảng của những phần này sẽ tạo ở giai đoạn sau.

---

## 2. Công nghệ

| Thành phần | Lựa chọn |
|---|---|
| Framework | Laravel 12, PHP 8.3 |
| Database | MySQL 8.0 (InnoDB, utf8mb4) |
| Xác thực | Laravel Sanctum (personal access token, header `Authorization: Bearer`) |
| Queue | Driver `database` (bảng `jobs`), chạy `php artisan queue:work`. Có thể đổi sang Redis sau (PHP trên OSPanel chưa có ext `redis`). |
| Lưu file | AWS S3, bucket private, trả ảnh qua **signed URL** có hạn 15 phút |
| Xóa ảnh sau 1 năm | S3 Lifecycle Rule cho prefix `generations/` |
| Lịch chạy | Laravel Scheduler (`schedule:run` mỗi phút) |
| AI | Google Gemini (model tạo ảnh, ví dụ `gemini-2.5-flash-image`) |

---

## 3. Nguyên tắc bất biến (bắt buộc giữ trong code)

1. Mọi thay đổi Credit đều nằm trong **database transaction** và có đúng 1 dòng `credit_ledger` tương ứng.
2. Ví Credit được khóa bằng `SELECT ... FOR UPDATE` trước khi kiểm tra và trừ số dư.
3. Không có Credit bị trừ mà không có Transaction, và ngược lại.
4. `idempotency_key` là duy nhất theo user, nên gửi lại cùng một request không bị trừ tiền 2 lần.
5. Frontend **không** gửi giá, model, prompt, phí. Backend đọc tất cả từ database.
6. Transaction lưu **snapshot** gồm giá, phí, model, Creator tại thời điểm tạo.
7. Creative đã có Transaction thì không được xóa cứng, chỉ chuyển sang `archived`.
8. Chỉ ảnh tạo thành công mới tăng `usage_count`.
9. Key API của Creator được mã hóa (`encrypted` cast, dùng `APP_KEY`) và không bao giờ trả về trong response.
10. Job chỉ được đưa vào queue **sau khi** database transaction đã commit (`afterCommit`).

---

## 4. Database schema

Quy ước: mọi bảng có `id BIGINT UNSIGNED` và `created_at`, `updated_at`, trừ khi ghi chú khác. Các cột "enum" được lưu dạng `VARCHAR` và ánh xạ sang PHP enum trong `app/Enums`, để thêm trạng thái mới không cần ALTER bảng. Số tiền là `BIGINT` theo đơn vị Credit (1 Credit = 1 VND, luôn là số nguyên).

### 4.1 Tài khoản

**users**
| Cột | Kiểu | Ghi chú |
|---|---|---|
| name | varchar(100) | |
| email | varchar(191) | unique |
| password | varchar | hash |
| role | enum(`user`,`admin`) | mặc định `user` |
| status | enum(`active`,`banned`) | mặc định `active` |

**creator_profiles**: mỗi user có tối đa 1 profile, tạo khi user vào Creator Studio lần đầu.
| Cột | Kiểu | Ghi chú |
|---|---|---|
| user_id | FK users | unique |
| shop_name | varchar(100) | |
| username | varchar(50) | unique, dùng cho URL `/creators/:username` |
| avatar_path, cover_path | varchar null | đường dẫn S3 |
| description | text null | |
| website | varchar null | |
| social_links | json null | |
| verified | bool | mặc định false, Admin cấp (sau MVP) |
| platform_fee_percent | decimal(5,2) null | **null thì dùng mức mặc định** trong `settings` |
| followers_count, creative_count, usage_count | int unsigned | bộ đếm |
| rating_avg | decimal(3,2) | mặc định 0 |
| rating_count | int unsigned | |

**settings**: cấu hình hệ thống dạng key-value.
| key | Giá trị mặc định |
|---|---|
| `platform_fee_percent` | `10` |
| `revenue_hold_days` | `7` |
| `generation.default_max_outputs` | `5` |
| `generation.max_outputs_limit` | `10` |
| `generation.input_max_size_kb` | `10240` |

### 4.2 AI Provider, Model, Key

**ai_providers**: dev seed.
| Cột | Kiểu |
|---|---|
| code | varchar unique (`gemini`) |
| name | varchar |
| is_active | bool |

**ai_models**: dev seed.
| Cột | Kiểu | Ghi chú |
|---|---|---|
| provider_id | FK ai_providers | |
| code | varchar | tên model gửi sang API, ví dụ `gemini-2.5-flash-image` |
| name | varchar | tên hiển thị |
| supported_aspect_ratios | json | ví dụ `["1:1","3:4","4:3","9:16","16:9",...]` |
| is_active | bool | |

**creator_api_keys**: mỗi Creator có thể có nhiều key cho mỗi Provider.
| Cột | Kiểu | Ghi chú |
|---|---|---|
| user_id | FK users | |
| provider_id | FK ai_providers | |
| label | varchar(100) | tên do Creator đặt |
| api_key | text | **mã hóa** |
| key_hint | varchar(10) | 4 ký tự cuối, chỉ để hiển thị |
| status | enum(`active`,`invalid`,`quota_exceeded`,`disabled`) | |
| cooldown_until | timestamp null | bị 429 thì tạm bỏ qua key đến thời điểm này |
| last_used_at | timestamp null | dùng để xoay vòng key |
| last_error | varchar null | |
| index | (user_id, provider_id, status) | |

### 4.3 AI Creative

**categories**: dev seed. Cột: `name`, `slug` unique, `icon` (tên icon Material Symbols, null được), `sort_order`, `is_active`.

**ai_creatives**
| Cột | Kiểu | Ghi chú |
|---|---|---|
| creator_id | FK users | |
| category_id | FK categories null | |
| model_id | FK ai_models | |
| title | varchar(150) | |
| slug | varchar(191) | unique |
| description | text null | |
| price_per_image | int unsigned | Credit cho mỗi ảnh |
| status | enum(`draft`,`pending_review`,`published`,`rejected`,`paused`,`archived`) | MVP chỉ dùng draft/published/paused/archived |
| pause_reason | enum(`manual`,`no_valid_api_key`) null | |
| hidden_at | timestamp null | Admin ẩn khỏi sàn (sau MVP, tạo cột sẵn) |
| usage_count | int unsigned | số ảnh tạo thành công |
| rating_avg, rating_count | | sau MVP |
| published_at | timestamp null | |
| index | (status, hidden_at, category_id), (creator_id, status) | |

**ai_creative_images**: ảnh mẫu, chỉ để hiển thị.
`creative_id`, `path`, `sort_order`.

**ai_creative_configs**: quan hệ 1-1 với Creative, **không bao giờ** trả về ở API public.
| Cột | Kiểu | Ghi chú |
|---|---|---|
| creative_id | FK unique | |
| system_prompt | text null | |
| creative_prompt | json null | `[{label, value}]`, tối đa 20 câu lệnh (label ≤ 100, value ≤ 5000 ký tự) |
| negative_prompt | text null | |
| max_outputs | tinyint | mặc định 5, tối đa 10 |
| allowed_aspect_ratios | json null | null thì cho phép tất cả tỉ lệ model hỗ trợ |
| default_aspect_ratio | varchar(10) | |
| extra_params | json null | ví dụ temperature |

### 4.4 Generation và Transaction

**generations**: mỗi lần người dùng bấm tạo là 1 dòng.
| Cột | Kiểu | Ghi chú |
|---|---|---|
| user_id | FK users | người tạo |
| creative_id | FK ai_creatives | |
| creator_id | FK users | snapshot |
| model_id | FK ai_models | snapshot |
| parent_generation_id | FK null | lần retry thì trỏ về Generation gốc |
| type | enum(`paid`,`owner`,`test`) | `owner` là Creator dùng Creative của mình, `test` là tạo thử; cả hai đều không trừ Credit |
| input_image_path | varchar | S3 |
| aspect_ratio | varchar(10) | |
| quantity | tinyint | |
| price_per_image | int unsigned | snapshot |
| prompt_snapshot | text | prompt đã gộp lúc tạo, phục vụ tra soát |
| status | enum(`queued`,`processing`,`completed`,`failed`,`cancelled`) | |
| success_count, failed_count | tinyint | |
| idempotency_key | varchar(100) | |
| unique | (user_id, idempotency_key) | |
| completed_at | timestamp null | |

Quy tắc trạng thái Generation: khi mọi output đã xong, nếu có ít nhất 1 ảnh thành công thì là `completed`, nếu tất cả lỗi thì là `failed`.

**generation_outputs**: mỗi ảnh là 1 dòng.
| Cột | Kiểu | Ghi chú |
|---|---|---|
| generation_id | FK | |
| position | tinyint | thứ tự ảnh 1..N |
| retry_of_output_id | FK null | ảnh này là retry của ảnh nào |
| transaction_id | FK null | null khi type là `owner` hoặc `test` |
| api_key_id | FK creator_api_keys null | key đã dùng |
| status | enum(`queued`,`processing`,`completed`,`failed`) | |
| image_path | varchar null | S3 |
| mime, width, height, size_bytes | | |
| error_code, error_message | null | |
| started_at, finished_at | timestamp null | |

**transactions**: mỗi ảnh tính phí là 1 Transaction.
| Cột | Kiểu | Ghi chú |
|---|---|---|
| code | varchar unique | mã hiển thị, ví dụ `TX20261008XXXX` |
| user_id | FK | người trả tiền |
| creator_id | FK | snapshot |
| creative_id | FK | |
| generation_id | FK | |
| generation_output_id | FK | **unique** |
| model_code | varchar | snapshot |
| amount | int unsigned | giá 1 ảnh, snapshot |
| platform_fee_percent | decimal(5,2) | snapshot |
| platform_fee_amount | int unsigned | |
| creator_amount | int unsigned | |
| status | enum(`pending`,`processing`,`completed`,`failed`,`refunded`,`cancelled`) | |
| failure_reason | varchar null | |
| refunded_at | timestamp null | |

Cách chia tiền: `creator_amount = floor(amount × (100 − fee%) / 100)`, `platform_fee_amount = amount − creator_amount`. Phần lẻ làm tròn có lợi cho nền tảng, và tổng hai phần luôn bằng `amount`.

### 4.5 Credit

**credit_wallets**: `user_id` unique, `balance` bigint unsigned. Tạo cùng lúc với user.

**credit_ledger**: chỉ thêm dòng, không bao giờ sửa hoặc xóa.
| Cột | Kiểu | Ghi chú |
|---|---|---|
| user_id | FK | |
| type | enum(`admin_topup`,`generation_charge`,`generation_refund`,`transaction_refund`,`admin_adjust`) | |
| amount | bigint | **có dấu**: trừ là số âm, cộng là số dương |
| balance_after | bigint | |
| reference_type, reference_id | | ví dụ `transaction`, 123 |
| idempotency_key | varchar null | **unique**, ví dụ `charge:tx:123`, `refund:tx:123`, `topup:<key>` |
| note | varchar null | |
| created_by | FK users null | admin thực hiện |
| created_at | timestamp | |

Khóa `refund:tx:{id}` là unique, nên mỗi Transaction chỉ hoàn tiền được 1 lần, kể cả khi job chạy lặp.

### 4.6 Doanh thu Creator

**creator_revenues**
| Cột | Kiểu | Ghi chú |
|---|---|---|
| creator_id | FK | |
| transaction_id | FK | **unique** |
| amount | int unsigned | = transactions.creator_amount |
| status | enum(`pending`,`available`,`withdrawn`,`reversed`) | |
| available_at | timestamp | = thời điểm hoàn tất + 7 ngày |
| released_at, reversed_at | null | |

**creator_wallets**: `user_id` unique.
| Cột | Ý nghĩa |
|---|---|
| pending_balance | doanh thu còn trong 7 ngày giữ |
| available_balance | tiền đã sẵn trong ví |
| withdrawable_balance | tiền đang bị khóa bởi lệnh rút (sau MVP mới dùng) |
| total_earned | tổng doanh thu từ trước đến nay |

### 4.7 Audit log

**audit_logs**: `user_id` null, `action`, `resource_type`, `resource_id`, `metadata` json, `ip`, `user_agent`, `created_at`.
Các action ghi trong MVP: `auth.login`, `creative.create`, `creative.update`, `creative.publish`, `creative.pause`, `creative.archive`, `creative.auto_paused`, `api_key.create`, `api_key.delete`, `generation.create`, `credit.charge`, `credit.refund`, `credit.admin_topup`, `revenue.create`, `revenue.release`.

---

## 5. Luồng nghiệp vụ

### 5.1 Tạo ảnh: `POST /generations`

**Request** (multipart): `creative_id`, `input_image` (jpg/png/webp, tối đa 10MB), `quantity`, `aspect_ratio`, `idempotency_key`.

**Bước đồng bộ (trong request):**
1. Xác thực user và kiểm tra `status = active`.
2. Nếu đã có Generation với cùng `(user_id, idempotency_key)`, trả lại Generation đó (HTTP 200). Nếu `creative_id` khác thì trả lỗi 409 `IDEMPOTENCY_CONFLICT`.
3. Tải Creative cùng config. Creative phải ở trạng thái `published` và `hidden_at` phải null, nếu không thì trả `CREATIVE_NOT_AVAILABLE`.
4. Kiểm tra `1 ≤ quantity ≤ config.max_outputs`, và `aspect_ratio` nằm trong danh sách cho phép.
5. Kiểm tra Creator còn ít nhất 1 key `active` cho Provider của model. Nếu không còn thì trả `CREATIVE_TEMPORARILY_UNAVAILABLE`.
6. Upload ảnh đầu vào lên S3 (`generations/{yyyy}/{mm}/{uuid}/input.ext`).
7. Xác định `type`: là `owner` nếu `user_id == creator_id`, còn lại là `paid`.
8. **Database transaction:**
   - Nếu `paid`: `SELECT credit_wallet FOR UPDATE`, tính `total = price_per_image × quantity`. Không đủ số dư thì rollback và trả 402 `INSUFFICIENT_CREDIT`.
   - Tạo `generation` (status `queued`, kèm snapshot giá, model, prompt).
   - Với mỗi ảnh i = 1..N:
     - tạo `generation_output` (status `queued`);
     - nếu `paid`: tạo `transaction` (status `processing`, snapshot phí) và 1 dòng `credit_ledger` loại `generation_charge` với khóa `charge:tx:{id}`.
   - Nếu `paid`: trừ `wallet.balance -= total`.
   - Ghi audit log.
9. Sau khi commit, đưa N job `ProcessGenerationOutput` vào queue, mỗi ảnh một job.
10. Trả HTTP 202 kèm Generation và danh sách output ở trạng thái `queued`.

Nếu bước 8 thất bại sau khi đã upload ảnh ở bước 6, file trên S3 sẽ bị thừa. Chấp nhận điều này vì lifecycle rule sẽ tự xóa.

### 5.2 Job `ProcessGenerationOutput` (mỗi ảnh)

1. Khóa output. Nếu status không còn là `queued` thì thoát, để tránh xử lý trùng.
2. Chuyển output sang `processing` và generation sang `processing`.
3. `ModelService` chọn Provider adapter theo `model.provider.code`, ở MVP là `GeminiImageProvider`.
4. **Chọn key (xoay vòng):** lấy các key `active` của Creator có `cooldown_until` null hoặc đã qua, sắp xếp theo `last_used_at` tăng dần, chọn key đầu tiên.
5. Gọi Gemini với: model code, prompt đã gộp, ảnh đầu vào và `aspect_ratio`.
6. Xử lý lỗi:
   | Lỗi | Hành động |
   |---|---|
   | 401/403, key sai | đánh dấu key `invalid` rồi **thử ngay key tiếp theo** |
   | 429, hết quota hoặc rate limit | đặt `cooldown_until = now + 10 phút` rồi thử key tiếp theo |
   | 5xx, timeout | thử lại tối đa 3 lần với backoff 10s, 30s, 60s |
   | Bị chặn vì safety, prompt hoặc ảnh không hợp lệ | thất bại luôn, không thử lại |
   | Hết key để thử | thất bại |
7. **Thành công**, trong database transaction:
   - upload ảnh kết quả lên S3, cập nhật output thành `completed`;
   - Transaction chuyển sang `completed`;
   - tạo `creator_revenue` (status `pending`, `available_at = now + 7 ngày`), cộng `creator_wallets.pending_balance` và `total_earned`;
   - tăng `ai_creatives.usage_count`, `creator_profiles.usage_count`, `generations.success_count`.
8. **Thất bại**, trong database transaction:
   - output chuyển sang `failed`;
   - nếu là `paid`: Transaction chuyển sang `refunded`, cộng lại Credit và ghi ledger `generation_refund` với khóa `refund:tx:{id}`;
   - tăng `generations.failed_count`.
9. Khi `success_count + failed_count == quantity` thì chốt trạng thái Generation.
10. **Tự tạm dừng Creative:** sau khi đánh dấu một key là `invalid`, nếu Creator không còn key nào `active` cho Provider đó thì chuyển Creative sang `paused` với `pause_reason = no_valid_api_key` và ghi audit log. Key chỉ bị cooldown 429 thì **không** pause.

**Lưới an toàn:** scheduler chạy mỗi 5 phút, tìm output ở trạng thái `processing` quá 15 phút hoặc `queued` quá 30 phút, rồi đánh dấu thất bại và hoàn Credit theo bước 8.

### 5.3 Retry: `POST /generations/{id}/retry`

- Request: `output_ids[]` (các ảnh muốn tạo lại), `idempotency_key`.
- Chỉ được retry output thuộc Generation của chính mình. Ảnh đã `completed` hay `failed` đều retry được.
- Tạo **Generation mới** với `parent_generation_id = id`, dùng lại ảnh đầu vào và tỉ lệ khung hình, `quantity = số output được chọn`, và lấy **giá, prompt hiện tại** của Creative. Mỗi output mới có `retry_of_output_id`.
- Phần còn lại giống mục 5.1: trừ Credit và tạo Transaction mới cho từng ảnh.

### 5.4 Tạo thử của Creator: `POST /creator/creatives/{id}/test`

- Dùng được cả khi Creative đang `draft`. Creator tải ảnh đầu vào lên, chọn số lượng và tỉ lệ khung hình.
- Tạo Generation với `type = test`: không có Transaction, không trừ Credit, không tạo doanh thu, không tăng `usage_count`.
- Gọi AI bằng key của chính Creator, dùng chung job ở mục 5.2.

### 5.5 Giải phóng doanh thu (scheduler, mỗi giờ)

Với mỗi `creator_revenue` có `status = pending` và `available_at ≤ now`, trong một database transaction (khóa creator_wallet):
- revenue chuyển sang `available`, ghi `released_at`;
- `pending_balance -= amount`, `available_balance += amount`.

### 5.6 Admin cộng Credit: `POST /admin/users/{id}/credits`

- Request: `amount` (số nguyên dương), `note`, `idempotency_key`.
- Trong database transaction: khóa ví, cộng số dư, ghi ledger `admin_topup` với khóa `topup:{idempotency_key}` và `created_by = admin`, ghi audit log.

### 5.7 Vòng đời Creative

```
draft ──publish──> published ──pause──> paused ──publish──> published
  │                    │                   │
  └────archive─────────┴──────archive──────┴──> archived
```
- Điều kiện **publish**: có title, model, config gồm `creative_prompt` và `max_outputs`, có ít nhất 1 ảnh mẫu, và Creator có ít nhất 1 key `active` cho Provider của model.
- **Xóa:** chỉ xóa được Creative `draft` chưa có Generation nào. Các trường hợp khác phải dùng archive.
- Sửa Creative đang `published` vẫn được và có hiệu lực ngay. Các Transaction cũ không đổi vì đã lưu snapshot.

### 5.8 Gộp prompt gửi sang Gemini

```
{system_prompt}

{creative_prompt}

Avoid: {negative_prompt}
```
Phần nào trống thì bỏ qua. `{creative_prompt}` là các câu lệnh ghép lại, mỗi câu một dòng `label: value`. Ảnh đầu vào gửi dạng `inlineData`, tỉ lệ khung hình gửi qua `generationConfig.imageConfig.aspectRatio`. Mỗi lần gọi API tạo 1 ảnh.

---

## 6. API

Tiền tố: `/api/v1`. Response JSON dạng `{ "data": ..., "meta": ... }`. Lỗi trả về dạng `{ "message": "...", "code": "INSUFFICIENT_CREDIT", "errors": {...} }`.

### 6.1 Auth
| Method | Path | Auth | Mô tả |
|---|---|---|---|
| POST | /auth/register | – | name, email, password, password_confirmation, trả về token |
| POST | /auth/login | – | email, password, trả về token |
| POST | /auth/logout | ✔ | thu hồi token hiện tại |
| GET | /auth/me | ✔ | thông tin user, số dư Credit, có Creator Profile hay chưa |

### 6.2 Marketplace (công khai)
| Method | Path | Mô tả |
|---|---|---|
| GET | /categories | danh sách danh mục `{ id, name, slug, icon }` |
| GET | /creatives | lọc theo `category`, `q`; sắp xếp `newest`, `popular`, `price_asc`, `price_desc`; có phân trang |
| GET | /creatives/{slug} | chi tiết gồm ảnh mẫu, giá, `max_outputs`, các tỉ lệ cho phép, thông tin Creator. **Không có prompt.** |
| GET | /creators/{username} | profile Creator và danh sách Creative `published` |

### 6.3 Generation (người mua)
| Method | Path | Mô tả |
|---|---|---|
| POST | /generations | tạo ảnh (mục 5.1), trả 202 |
| GET | /generations | lịch sử của tôi, có phân trang |
| GET | /generations/{id} | chi tiết, các output kèm signed URL |
| GET | /generations/{id}/status | bản rút gọn để polling: status và trạng thái từng output |
| POST | /generations/{id}/retry | mục 5.3 |
| GET | /generations/{id}/outputs/{outputId}/download | trả signed URL có header `Content-Disposition: attachment` |

### 6.4 Credit
| Method | Path | Mô tả |
|---|---|---|
| GET | /wallet/credit | số dư |
| GET | /wallet/credit/ledger | lịch sử biến động, có phân trang |

### 6.5 Creator Studio
| Method | Path | Mô tả |
|---|---|---|
| POST | /creator/profile | tạo Creator Profile |
| GET / PUT | /creator/profile | xem và sửa profile |
| POST | /creator/profile/avatar, /creator/profile/cover | upload ảnh |
| GET | /ai-models | danh sách model đang hoạt động |
| GET | /creator/api-keys | danh sách key (chỉ có label, key_hint, status) |
| POST | /creator/api-keys | provider_id, label, api_key; **gọi thử Gemini để kiểm tra key** trước khi lưu |
| PATCH | /creator/api-keys/{id} | đổi label, bật hoặc tắt key |
| DELETE | /creator/api-keys/{id} | xóa mềm (dữ liệu cũ vẫn trỏ được tới key) |
| GET | /creator/creatives | Creative của tôi, mọi trạng thái |
| POST | /creator/creatives | tạo bản nháp |
| GET | /creator/creatives/{id} | chi tiết, **có** config và prompt |
| PUT | /creator/creatives/{id} | sửa thông tin, giá, model, config |
| DELETE | /creator/creatives/{id} | chỉ áp dụng cho draft chưa có Generation |
| POST | /creator/creatives/{id}/images | upload ảnh mẫu |
| DELETE | /creator/creatives/{id}/images/{imageId} | xóa ảnh mẫu |
| PATCH | /creator/creatives/{id}/images/order | sắp xếp lại ảnh mẫu |
| POST | /creator/creatives/{id}/test | tạo thử (mục 5.4) |
| POST | /creator/creatives/{id}/publish | đăng hoặc đăng lại sau khi pause |
| POST | /creator/creatives/{id}/pause | tạm dừng |
| POST | /creator/creatives/{id}/archive | lưu trữ |
| GET | /creator/dashboard | tổng số lượt dùng, doanh thu hôm nay/tháng này, số Creative theo trạng thái |
| GET | /creator/wallet | pending, available, withdrawable, total_earned |
| GET | /creator/wallet/transactions | danh sách creator_revenues, có phân trang |

### 6.6 Admin (role = admin)
| Method | Path | Mô tả |
|---|---|---|
| POST | /admin/users/{id}/credits | cộng Credit (mục 5.6) |

### 6.7 Mã lỗi nghiệp vụ
| Code | HTTP | Ý nghĩa |
|---|---|---|
| `INSUFFICIENT_CREDIT` | 402 | không đủ Credit |
| `CREATIVE_NOT_AVAILABLE` | 404 | Creative không ở trạng thái published hoặc đã bị ẩn |
| `CREATIVE_TEMPORARILY_UNAVAILABLE` | 409 | Creator không còn key hoạt động |
| `QUANTITY_EXCEEDED` | 422 | số lượng vượt `max_outputs` |
| `ASPECT_RATIO_NOT_ALLOWED` | 422 | tỉ lệ không được phép |
| `IDEMPOTENCY_CONFLICT` | 409 | trùng key nhưng nội dung khác |
| `CREATIVE_NOT_PUBLISHABLE` | 422 | thiếu điều kiện để publish |
| `API_KEY_INVALID` | 422 | key Gemini không hợp lệ khi lưu |
| `CREATOR_PROFILE_REQUIRED` | 403 | chưa tạo Creator Profile |

---

## 7. Cấu trúc code

```
app/
  Enums/                      # CreativeStatus, GenerationStatus, TransactionStatus, LedgerType, ...
  Http/
    Controllers/Api/V1/       # Auth, Marketplace, Generation, CreditWallet, Creator/*, Admin/*
    Requests/                 # FormRequest validate từng API
    Resources/                # API Resource (ẩn prompt, ẩn api_key)
    Middleware/EnsureAdmin.php, EnsureCreatorProfile.php
  Models/
  Policies/                   # chỉ chủ sở hữu mới xem/sửa Creative, Generation
  Services/
    CreditService.php         # charge(), refund(), topup(): nơi duy nhất thay đổi số dư Credit
    GenerationService.php     # create(), retry(), test()
    RevenueService.php        # record(), release()
    PlatformFeeService.php    # lấy fee% của Creator hoặc mặc định
    CreativeService.php       # publish/pause/archive và điều kiện chuyển trạng thái
    AI/
      ModelService.php        # chọn provider adapter theo model
      ApiKeyRotator.php       # chọn key, đánh dấu invalid/cooldown
      Contracts/ImageProvider.php
      Providers/GeminiImageProvider.php
      Exceptions/             # InvalidKey, RateLimited, Transient, ContentBlocked
  Jobs/ProcessGenerationOutput.php
  Console/                    # ReleaseCreatorRevenue, SweepStuckGenerations
```

---

## 8. Kiểm thử bắt buộc (Feature test)

1. Tạo ảnh với đủ Credit: tạo đúng N Transaction, N dòng ledger, số dư giảm đúng `price × N`.
2. Không đủ Credit: trả 402, không tạo Generation hay Transaction nào.
3. Gửi lại cùng `idempotency_key`: không bị trừ tiền lần 2.
4. 2 request đồng thời mà số dư chỉ đủ cho 1: chỉ 1 request thành công.
5. Lỗi 1/4 ảnh: hoàn đúng tiền 1 ảnh, 3 doanh thu ở trạng thái pending, `usage_count += 3`.
6. Job chạy lại 2 lần trên cùng output: chỉ hoàn tiền 1 lần.
7. Key 1 bị 401 thì dùng key 2 và thành công. Mọi key đều 401 thì Creative bị auto-pause.
8. Creator tự dùng Creative của mình: không trừ Credit, không có Transaction, không có doanh thu.
9. Frontend gửi thêm `price`, `model_id`, `prompt`: các trường này bị bỏ qua.
10. Sau 7 ngày, doanh thu chuyển từ pending sang available và số dư ví Creator đúng.
11. API public không bao giờ trả prompt hoặc api_key.
12. Tổng `credit_ledger.amount` theo user luôn bằng `credit_wallets.balance`.

---

## 9. Điểm cần bạn xác nhận (code đang dùng phương án đề xuất)

1. **Giá bằng 0:** tài liệu nói không có giá tối thiểu. Có cho Creator đặt giá **0 Credit** (miễn phí) không? Nếu có thì vẫn tạo Transaction 0đ và không có doanh thu.
2. **Retry dùng giá mới hay giá cũ:** khi Creator đổi giá giữa chừng, retry tính theo **giá hiện tại** (đề xuất) hay giá của lần tạo gốc?
3. **Thời gian cooldown khi key bị 429:** đề xuất 10 phút.
4. **Bỏ `POST /auth/refresh`:** Sanctum không cần refresh token. Token đặt hạn 30 ngày được không?
5. **Giới hạn tần suất (rate limit):** đề xuất tối đa 10 request `POST /generations` mỗi phút cho mỗi user.

---

## 10. Ghi chú triển khai

- Chỉ ảnh của **người mua** (`type = paid`) mới tăng `usage_count`. Ảnh `owner` và `test` không tính.
- Giá 0 Credit hiện được phép: vẫn tạo Transaction 0đ, không ghi ledger, không tạo doanh thu.
- Lần tạo `owner`/`test` không có Transaction (`generation_outputs.transaction_id = null`).
- Response 202 của `POST /generations` luôn trả trạng thái lúc vừa đưa vào queue. Frontend cần polling `GET /generations/{id}/status`.
- Media disk: local dùng disk `media` (`storage/app/media`, URL ký số qua `/media/...`). Production đặt `MEDIA_DISK=s3` và cấu hình `AWS_*`.
