# Ứng dụng PHP trên EC2 Auto Scaling và CloudWatch

Bản tiếp theo của ứng dụng quản lý công việc nhóm. PHP 8.3 và RDS MySQL 8.
Phiên PHP dùng chung qua bảng app_sessions, có khóa phiên MySQL.

Đọc Huong_dan.md để triển khai theo AWS Console. Không tự chạy User data
trên máy cá nhân. Cần VPC, RDS, IAM role, Parameter Store, ALB và ASG.

## Tóm tắt
1. Tạo repository mới, commit code và ghi SHA đủ 40 ký tự.
2. Tạo mạng hai AZ, web trong public subnets để kết nối ra Internet,
   RDS trong private DB subnets, Public access No. Không mở SSH hoặc DB từ Internet.
3. Lưu cấu hình theo config/database.example.json vào SecureString
   /lab/team-project/database và gắn role có quyền cho EC2.
4. Thay REGION, REPO_URL, COMMIT_SHA trong bản User data dùng tại Launch Template.
5. Tạo máy quản trị tạm, dùng MySQL client qua Session Manager để tạo tài khoản
   team_app và nhập database/schema.sql đúng một lần vào database team_project trống.
6. Chạy tests/mysql-session-test.php với quyền www-data trên máy tạm để kiểm tra RDS.
7. Tạo Target Group, ALB và ASG min 2 desired 2 max 3. Bật ELB health checks.
8. Tạo target tracking CPU 50, dashboard và alarm CloudWatch; thử tải có timeout.
9. Cập nhật bằng template version và Instance refresh, không git pull trên từng EC2.
10. Thu hồi ASG trước khi xóa EC2, sau đó ALB, RDS, log và tài nguyên liên quan.

## Cấu hình và giám sát
deploy/fetch_config.py ghi config/database.json riêng, ngoài DocumentRoot, quyền 640.
Không lưu mật khẩu trong Git hoặc User data. health.php kiểm tra web đã khởi tạo,
không gọi RDS. Vì vậy phải theo dõi cả HTTP 5XX và metric RDS.
CloudWatch Agent gửi log Apache, RAM và ổ đĩa; CPU và ASG metrics do AWS cung cấp.
RAM và disk theo InstanceId phải cập nhật dashboard khi máy bị thay.

Chưa có đăng nhập, phân quyền hoặc HTTPS. Chỉ sử dụng dữ liệu giả và giới hạn
nguồn truy cập ALB theo IP. RDS Single AZ là giới hạn của bài lab, không phải
kiến trúc dữ liệu có khả năng chịu lỗi nhiều AZ.

## Xác minh
Xem Kiem_tra.json về cú pháp và thử nghiệm trên bản sao SQLite. Những kiểm thử
đó không xác minh MySQL advisory locks, TLS RDS, IAM hoặc Auto Scaling thực tế.
Không cần SQLite khi triển khai. Tập lệnh MySQL session test được cung cấp để
giảng viên chạy trong môi trường AWS trước buổi học.
