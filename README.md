# Telegram Lead Bot — hướng dẫn cài đặt trên cPanel

## Cấu trúc thư mục cần upload lên hosting

Upload nguyên cụm này vào **1 thư mục duy nhất** trong `public_html`, ví dụ:

```
public_html/
└── telegram-bot/
    ├── config.php          ← điền token, mật khẩu DB, API key ở đây
    ├── webhook.php          ← Telegram gọi vào đây mỗi khi có tin nhắn mới
    ├── daily_summary.php    ← chạy bằng Cron Job mỗi ngày để gửi báo cáo
    ├── gemini_helper.php    ← xử lý câu hỏi qua Gemini (lệnh /hoi)
    ├── setwebhook.php       ← chỉ chạy 1 LẦN để đăng ký webhook, sau đó XOÁ
    └── schema.sql           ← chạy 1 lần trong phpMyAdmin để tạo bảng
```

Lưu ý: `schema.sql` **không upload lên hosting** — chỉ dùng để copy nội dung
dán vào phpMyAdmin. 5 file `.php` còn lại mới là thứ cần upload.

## Thứ tự thực hiện

### 1. Tạo database
cPanel → **MySQL Databases** → tạo database (vd `leads`) → tạo user → gán
quyền ALL PRIVILEGES cho user vào database đó. Ghi lại 3 thứ: tên database,
username, password (cPanel thường tự thêm tiền tố kiểu `tenuser_`).

### 2. Tạo bảng
cPanel → **phpMyAdmin** → chọn database vừa tạo → tab **SQL** → dán toàn bộ
nội dung `schema.sql` → **Go**.

### 3. Upload file
File Manager (hoặc FTP) → vào `public_html` → tạo thư mục `telegram-bot` →
upload 5 file `.php` liệt kê ở trên (không upload `schema.sql`).

### 4. Điền `config.php`
Mở file, điền:
- `BOT_TOKEN` — lấy từ @BotFather
- `WEBHOOK_SECRET` — tự nghĩ ra 1 chuỗi bất kỳ, không cần nhớ ý nghĩa
- `GEMINI_API_KEY` — lấy tại https://aistudio.google.com/apikey (miễn phí)
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — theo bước 1

### 5. Đăng ký webhook
Mở `setwebhook.php`, sửa dòng `$webhookUrl` thành đúng domain thật, ví dụ:
```
https://domain-cua-ban.com/telegram-bot/webhook.php
```
Domain phải chạy **HTTPS** (cPanel thường có AutoSSL sẵn).

Sau đó mở link `https://domain-cua-ban.com/telegram-bot/setwebhook.php`
trên trình duyệt **1 lần** → thấy `"ok":true` là xong.
**Ngay sau đó xoá file `setwebhook.php` khỏi hosting** để tránh lộ token.

### 6. Cấu hình bot trên Telegram
- @BotFather → chọn bot → **Bot Settings → Group Privacy → Turn off**
  (để bot đọc được tin nhắn thường trong nhóm)
- Add bot vào nhóm "Data Xanh SM" (nếu chưa có)

### 7. Tạo Cron Job
cPanel → **Cron Jobs** → thêm job chạy hàng ngày lúc 23:55, lệnh:
```
php /home/tenuser/public_html/telegram-bot/daily_summary.php
```
(thay `tenuser` bằng username cPanel thật — xem đường dẫn chính xác trong
File Manager, click chuột phải vào file → Copy Path)

## Cách dùng sau khi cài xong

- Mỗi tin nhắn lead từ LadiPage gửi vào nhóm → bot tự lưu vào database.
- Mỗi ngày lúc 23:55 → bot tự gửi báo cáo tổng/lead unique/lead trùng vào nhóm.
- Gõ trong nhóm bất cứ lúc nào:
  ```
  /hoi hôm nay có bao nhiêu lead mới?
  /hoi khu vực nào nhiều lead nhất tuần này?
  ```
  → bot sẽ hỏi Gemini dựa trên số liệu thật và trả lời ngay trong nhóm.
