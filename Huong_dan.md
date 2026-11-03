# Triển khai ứng dụng PHP trên Auto Scaling Group và giám sát bằng CloudWatch
Bài lab tiếp nối ứng dụng quản lý công việc nhóm bằng PHP và MySQL. Sinh viên chuyển ứng dụng từ một EC2 sang một nhóm EC2 có khả năng tăng giảm số máy, dùng Application Load Balancer để phân phối yêu cầu và CloudWatch để theo dõi hoạt động.
Bộ mã nguồn mới giữ chức năng quản lý thành viên và giao việc của bài trước. CSDL được chuyển sang RDS MySQL; phiên PHP cũng lưu trong RDS để biểu mẫu vẫn dùng được khi hai yêu cầu liên tiếp đến hai EC2 khác nhau. Mỗi máy được tạo từ cùng một Launch Template và một mã commit GitHub xác định.
## Kết quả cần đạt
- Triển khai hai máy web ở hai Availability Zone và truy cập qua địa chỉ DNS của ALB.
- Tăng từ hai lên ba EC2 khi tải CPU tăng, sau đó giảm về hai khi hết tải.
- Theo dõi CPU, số máy, tình trạng target, nhật ký Apache và cảnh báo CloudWatch.
- Chứng minh dữ liệu và phiên biểu mẫu không phụ thuộc vào một máy web.
- Phân biệt tăng giảm năng lực với thay thế máy không còn hoạt động tốt.
## Điều kiện và phạm vi
Cần hoàn thành bài PHP MySQL GitHub EC2, biết sử dụng Terminal và có quyền tạo VPC, EC2, ALB, Auto Scaling, RDS, IAM, Systems Manager, CloudWatch và SNS. Thời lượng gợi ý 3 buổi, mỗi buổi 2 tiết; RDS và một số thao tác thay máy có thể cần thêm thời gian chờ.
Bài thực hành dùng dữ liệu giả. Ứng dụng chưa có đăng nhập, phân quyền hoặc HTTPS. Chỉ mở ALB cho IP public của người thực hiện; máy web không nhận HTTP trực tiếp từ Internet. RDS Single AZ trong bài chưa bảo đảm khả năng chịu lỗi của tầng dữ liệu.
## Tệp đi kèm
Gói Ma_nguon_PHP_ASG_CloudWatch.zip chứa ứng dụng, schema, User data, chính sách IAM mẫu, cấu hình CloudWatch Agent, sơ đồ và hướng dẫn dạng Markdown. Tạo repository mới team-project-php-asg để giữ nguyên phiên bản EC2 của bài trước.
Không tạo tài nguyên bằng tài khoản root của AWS. Nhờ giảng viên cấp quyền theo tài khoản thực hành; IAM instance role của EC2 và quyền của người thao tác Console là hai phạm vi khác nhau.

# 1 Kiến trúc bài lab
![Sơ đồ](So_do_ASG_CloudWatch.png)
ALB nhận HTTP 80 rồi gửi đến Target Group chứa các EC2 trong Auto Scaling Group. Mỗi máy chạy Apache và PHP, truy cập chung RDS MySQL qua cổng 3306. Web không cài MySQL Server và không lưu dữ liệu nghiệp vụ trên ổ cục bộ.
Hai public subnet có đường ra Internet để máy web tải gói, lấy mã GitHub và gọi API AWS. EC2 cần public IPv4 cho luồng ra trong mô hình không có NAT này; Security Group vẫn chặn HTTP trực tiếp và không mở SSH. RDS nằm trong hai private subnet của DB subnet group và đặt Public access là No.
CloudWatch nhận CPU từ EC2, số lượng máy từ Auto Scaling và thông tin target từ ALB. CloudWatch Agent bổ sung RAM, dung lượng ổ và nhật ký Apache. Target tracking dùng metric CPU để quyết định số máy; CloudWatch không tự tạo EC2 nếu chưa có scaling policy.
## Thành phần có trạng thái
RDS lưu bảng members, tasks và app_sessions. Bảng app_sessions dùng chung token CSRF và thông báo giữa các EC2; mã nguồn có khóa phiên MySQL để hai yêu cầu cùng phiên không ghi đè lẫn nhau. ALB stickiness được tắt để kiểm tra đúng việc dùng chung phiên.
Điểm health.php chỉ kiểm tra tầng web đã khởi tạo xong, không truy vấn RDS. Khi RDS lỗi, ứng dụng có thể trả 503 dù target vẫn Healthy. Theo dõi riêng lỗi HTTP và RDS để tránh thay cả nhóm EC2 vì một sự cố CSDL dùng chung.

# 2 Chuẩn bị mã nguồn trên GitHub
## Bước 1 Tạo repository cho phiên bản ASG
Giải nén gói mới, mở thư mục php_team_project_asg trên Ubuntu cục bộ. Bản này dùng chung chức năng với bài trước nhưng cấu hình kết nối và phiên đã thay đổi. Không sao chép config.local.php cũ vào dự án mới.
```
git init -b main
git config user.name "Ho ten sinh vien"
git config user.email "EMAIL_CUA_BAN"
git add .
git diff --cached --stat
git diff --cached
git commit -m "Chuan bi PHP cho Auto Scaling va CloudWatch"
```
Trên GitHub chọn New repository, tên team-project-php-asg, Public, không tạo README mặc định. Repository chỉ chứa code, dữ liệu mẫu và schema. Xác thực Git như bài trước; không dùng mật khẩu tài khoản GitHub làm mật khẩu Git HTTPS.
```
git remote add origin https://github.com/TEN_GITHUB/team-project-php-asg.git
git push -u origin main
git rev-parse HEAD
```
Ghi lại chuỗi SHA đủ 40 ký tự. Mỗi phiên bản Launch Template phải tham chiếu SHA cụ thể này, tránh máy mới chạy code khác với máy đang phục vụ khi nhánh main thay đổi.
## Các tệp bổ sung
| Tệp | Vai trò |
|---|---|
| src/database.php | Kết nối PDO MySQL và xác minh chứng chỉ TLS của RDS |
| src/DatabaseSessionHandler.php | Lưu phiên trong MySQL, khóa theo mã phiên |
| database/schema.sql | Bảng nghiệp vụ và bảng app_sessions |
| public/health.php | Health check của ALB, không gọi CSDL |
| deploy/user-data.sh | Tự cài máy web khi EC2 mới được tạo |
| deploy/fetch_config.py | Đọc SecureString và ghi cấu hình riêng trên máy |
| monitoring/cloudwatch-agent.json | Thu RAM, ổ đĩa và nhật ký Apache |
Không commit config/database.json, khóa pem hoặc tệp sao lưu. Tệp config/database.example.json chỉ là mẫu. Không đặt mật khẩu trong User data; nội dung User data không phải kho bí mật.

# 3 Tạo VPC và các Security Group
## Bước 2 Tạo mạng trong AWS Console
Chọn cùng một Region cho toàn bộ tài nguyên, ví dụ Asia Pacific Singapore. Mở VPC, Create VPC, chọn VPC and more. Đặt tên gốc php-asg-lab và xem sơ đồ trước khi tạo.
| Trường | Giá trị |
|---|---|
| IPv4 CIDR | 10.20.0.0/16, điều chỉnh nếu trùng mạng đang dùng |
| Availability Zones | 2 |
| Public subnets | 2 |
| Private subnets | 2 |
| NAT gateways | None |
| VPC endpoints | None trong bài này |
| DNS | Bật DNS resolution và DNS hostnames |
Kiểm tra route table của public subnet có 0.0.0.0/0 đến Internet Gateway. Private subnet không có route đến Internet Gateway hoặc NAT. Ghi lại VPC ID, hai public subnet và hai private subnet theo tên và Availability Zone.
## Bước 3 Tạo ba nhóm bảo mật
Mở EC2, Security Groups, Create security group. Cả ba nhóm thuộc VPC vừa tạo. Tạo đủ nhóm rồi sửa inbound để tham chiếu đúng Security Group ID.
| Nhóm | Inbound | Nguồn |
|---|---|---|
| php-alb-sg | HTTP TCP 80 | IP public của sinh viên kèm /32 |
| php-web-sg | HTTP TCP 80 | Security Group php-alb-sg |
| php-db-sg | MySQL TCP 3306 | Security Group php-web-sg |
Giữ outbound mặc định trong bài để đơn giản hóa truy cập RDS và các dịch vụ cài đặt. Không mở SSH 22; quản trị qua Session Manager. Máy web có public IP phục vụ kết nối ra nhưng inbound chỉ chấp nhận ALB. Không thêm 0.0.0.0/0 cho HTTP của ALB hoặc cho MySQL.
Nếu nhiều nhóm dùng chung một ALB, chỉ thêm các IP được phép. Khi đổi mạng, cập nhật IP nguồn trong php-alb-sg. Nếu sử dụng IPv6 hoặc VPN, xác định đúng IP public của luồng trình duyệt; bài lab chỉ cấu hình IPv4.

# 4 Tạo RDS MySQL dùng chung
## Bước 4 Tạo DB subnet group
Mở RDS, Subnet groups, Create DB subnet group. Đặt tên php-asg-db-subnets, chọn VPC của lab, hai Availability Zone và hai private subnet tương ứng. Không chọn public subnet cho tầng CSDL.
## Bước 5 Tạo database
Mở RDS, Databases, Create database, chọn Standard create. Chọn MySQL bản còn được hỗ trợ trong Region, ưu tiên nhánh 8.4 nếu có; mã sử dụng cú pháp tương thích MySQL 8. Không chọn Aurora hoặc cấu hình cluster cho bài này.
| Trường | Giá trị hoặc thao tác |
|---|---|
| Template | Dev Test hoặc tùy chọn thực hành phù hợp tài khoản |
| Availability | Single DB instance, Single AZ |
| DB identifier | php-asg-db |
| Master username | dbadmin |
| Credentials | Tự đặt mật khẩu riêng, không đưa vào báo cáo |
| DB instance class | Burstable db.t3.micro nếu được hỗ trợ |
| Storage | gp3 20 GiB nếu được hỗ trợ; tắt tự tăng dung lượng cho lab |
| Compute connection | Không tự kết nối đến EC2 có sẵn |
| VPC và subnet group | VPC lab và php-asg-db-subnets |
| Public access | No |
| Security Group | Chỉ php-db-sg, bỏ nhóm mặc định nếu tự thêm |
| Initial database name | team_project |
Chọn dung lượng và loại máy thực tế được Console hỗ trợ; xem giá trước khi xác nhận. Đặt thời gian giữ backup ngắn phù hợp lab, không bật các tính năng giám sát có phí bổ sung khi chưa cần. Ghi nhận lựa chọn deletion protection để biết cách xóa sau buổi học.
Chờ trạng thái Available. Trong Connectivity and security, sao chép Endpoint dạng tên miền RDS và cổng 3306. Không dùng IP để thay endpoint vì kết nối cần kiểm tra tên máy trong chứng chỉ TLS.
RDS này độc lập với ổ của nhóm web. Tạo hoặc xóa EC2 không làm mất bảng công việc. Tuy nhiên Single AZ không tự trở thành Multi AZ chỉ vì nhóm web có hai Availability Zone.

# 5 Lưu cấu hình riêng và tạo IAM role
## Bước 6 Tạo SecureString trong Parameter Store
Mở Systems Manager, Parameter Store, Create parameter. Name là /lab/team-project/database, Tier Standard, Type SecureString, khóa mã hóa AWS managed key aws/ssm. Value là JSON một dòng hoặc nhiều dòng theo mẫu sau:
```
{
  "host": "ENDPOINT_RDS_CUA_BAN",
  "database": "team_project",
  "username": "team_app",
  "password": "MAT_KHAU_UNG_DUNG_RIENG",
  "ssl_ca": "/etc/ssl/certs/aws-rds-global-bundle.pem"
}
```
Chọn mật khẩu ứng dụng riêng và giữ để tạo tài khoản MySQL ở bước 10. Không dùng mật khẩu dbadmin. Nếu mật khẩu có dấu nháy kép hoặc dấu gạch chéo ngược, phải mã hóa đúng JSON; có thể dùng chuỗi dài chỉ gồm chữ và số cho dữ liệu thực hành.
## Bước 7 Tạo role cho EC2
Mở IAM, Roles, Create role. Trusted entity là AWS service, Use case EC2. Gắn AmazonSSMManagedInstanceCore và CloudWatchAgentServerPolicy. Đặt tên php-asg-ec2-role. Mở role, Add permissions, Create inline policy, chọn JSON và dùng deploy/parameter-read-policy.json.
Thay REGION và ACCOUNT_ID trong ARN bằng Region và ID tài khoản hiện tại. Ví dụ đường dẫn tài nguyên phải kết thúc bằng parameter/lab/team-project/database. Chính sách chỉ bổ sung ssm:GetParameter cho đúng tham số cần đọc; không cấp quyền sửa tham số.
Với khóa mặc định aws/ssm, không thêm khóa tự quản lý. Nếu dùng customer managed KMS key thay cho lựa chọn trong bài, cần cấp kms:Decrypt trên đúng key và kiểm tra key policy. Không thêm AdministratorAccess cho instance role để chữa lỗi truy cập.
Hai managed policy dùng trong lab có phạm vi rộng hơn chính sách tối thiểu của một ứng dụng vận hành thật; đặc biệt AmazonSSMManagedInstanceCore có các quyền đọc tham số riêng. Không coi inline policy bổ sung là giới hạn toàn bộ quyền đọc tham số của role.
Role này được chọn trong IAM instance profile của Launch Template. Không tạo access key rồi lưu lên máy web. Khi chạy, SDK Python nhận thông tin xác thực tạm thời từ instance role.

# 6 Tạo Launch Template và kiểm tra máy đầu tiên
## Bước 8 Chuẩn bị User data
Mở deploy/user-data.sh trên máy cục bộ, tạo bản sao dùng để dán lên Console. Thay REGION, REPO_URL và COMMIT_SHA. REGION phải đúng nơi chứa Parameter Store; COMMIT_SHA là chuỗi đủ 40 ký tự của bước 1. Không thay bằng main hoặc latest.
Script cài Apache, PHP, MySQL client, Boto3, stress-ng và CloudWatch Agent. Nó lấy code tại SHA đã chọn, đọc SecureString bằng instance role, ghi config/database.json quyền root:www-data 640, bật site và health.php. Nó không cài MySQL Server, không nhập schema và không ghi mật khẩu ra log.
Các gói được lấy từ kho chính thức khi máy khởi tạo; phiên bản bản vá có thể khác theo thời điểm. Với hệ thống vận hành thật, nên chuẩn bị AMI đã kiểm thử để giảm thời gian tạo máy và phụ thuộc tải gói.
## Bước 9 Tạo template trên EC2 Console
Mở EC2, Launch Templates, Create launch template. Đặt tên php-project-template; bật hướng dẫn dùng với Auto Scaling nếu có. Điền các trường sau:
| Trường | Giá trị |
|---|---|
| AMI | Ubuntu Server 24.04 LTS của Canonical, x86 64 |
| Instance type | t3.micro cho tầng web; không chạy MySQL Server |
| Key pair | Không chọn, dùng Session Manager |
| Subnet | Không chỉ định trong template, ASG sẽ chọn |
| Security Group | php-web-sg của VPC lab |
| Network interface | Device 0, Auto assign public IP Enable, xóa cùng instance |
| Storage | gp3 12 GiB, Delete on termination bật |
| IAM instance profile | php-asg-ec2-role |
| Detailed CloudWatch monitoring | Enable để có CPU chu kỳ một phút |
| Metadata options | IMDSv2 required, HTTP endpoint enabled |
| User data | Dán bản script đã thay ba giá trị, không tự mã hóa base64 |
Chọn Security Group trong network interface nếu Console yêu cầu; không cấu hình hai vị trí mâu thuẫn. Không gắn Elastic IP hoặc địa chỉ IP cố định. Lưu template và ghi lại version number.
Từ Actions của template chọn Launch instance from template. Đặt tên php-asg-admin-temp, chọn một public subnet của lab và xác nhận public IP được cấp. Đây là EC2 tạm để khởi tạo CSDL, chưa thuộc ASG.

# 7 Khởi tạo bảng từ máy quản trị tạm
## Bước 10 Kết nối bằng Session Manager
Chờ EC2 tạm chạy và hoàn tất khởi tạo. Mở EC2, chọn máy, Connect, Session Manager, Connect. Tài khoản Console cần quyền mở phiên SSM; instance role không cấp thay quyền cho người dùng Console. Máy phải có SSM Agent, role và kết nối HTTPS ra dịch vụ SSM.
Trong phiên Terminal trên web, kiểm tra:
```
sudo cloud-init status --wait
sudo tail -n 40 /var/log/cloud-init-output.log
curl -i http://127.0.0.1/health.php
```
Health trả 200 sau khi User data hoàn tất, dù tài khoản MySQL chưa được tạo. Không mở index.php để kết luận CSDL sẵn sàng ở giai đoạn này. Nếu Session Manager chưa mở được, xem mục xử lý lỗi trước khi thêm cổng SSH.
Kết nối RDS bằng tài khoản quản trị. Thay RDS_ENDPOINT bằng endpoint thật; nhập mật khẩu tại lời nhắc, không ghi mật khẩu trong câu lệnh.
```
mysql -h RDS_ENDPOINT -u dbadmin -p \
 --ssl-ca=/etc/ssl/certs/aws-rds-global-bundle.pem \
 --ssl-mode=VERIFY_IDENTITY team_project
```
Trong cửa sổ MySQL, tạo tài khoản team_app bằng đúng mật khẩu đã lưu trong SecureString:
```
CREATE USER 'team_app'@'%' IDENTIFIED BY 'MAT_KHAU_UNG_DUNG_RIENG' REQUIRE SSL;
GRANT SELECT, INSERT, UPDATE, DELETE ON team_project.* TO 'team_app'@'%';
SOURCE /var/www/team-project/database/schema.sql;
SHOW TABLES;
EXIT;
```
Kết quả có members, tasks và app_sessions. Ký hiệu % cho phép các địa chỉ máy web mới kết nối; luồng mạng vẫn bị giới hạn bởi php-db-sg và yêu cầu TLS. Không dùng tài khoản dbadmin làm tài khoản ứng dụng.
## Bước 11 Kiểm tra kết nối và phiên chung
```
sudo -u www-data php /var/www/team-project/tests/mysql-session-test.php
curl -I http://127.0.0.1/index.php
```
Kiểm thử cần báo PASS và index.php trả 200. Tập lệnh dùng hai kết nối RDS để kiểm tra ghi đọc phiên, rồi xóa bản ghi thử. Không chạy schema.sql trong User data; nếu mỗi máy mới nhập lại schema thì việc mở rộng sẽ gây lỗi bảng hoặc có nguy cơ mất dữ liệu nếu schema được sửa thiếu cẩn thận.

# 8 Tạo Target Group và Application Load Balancer
## Bước 12 Tạo Target Group
Mở EC2, Target Groups, Create target group. Target type Instances, Name php-project-tg, Protocol HTTP, Port 80, IP type IPv4, VPC của lab, Protocol version HTTP1.
| Health check | Giá trị |
|---|---|
| Protocol và path | HTTP, /health.php |
| Success codes | 200 |
| Interval và timeout | 30 giây và 5 giây |
| Healthy threshold | 2 |
| Unhealthy threshold | 2 |
Ở bước Register targets, bỏ qua máy tạm và không đăng ký thủ công. ASG sẽ tự đăng ký và gỡ các máy. Sau khi tạo, mở Attributes, giữ thuật toán Round robin và tắt Stickiness. Có thể đặt Deregistration delay 60 giây cho buổi thực hành; chờ yêu cầu đang xử lý hoàn tất trước khi máy bị loại.
## Bước 13 Tạo ALB
Mở EC2, Load Balancers, Create load balancer, chọn Application Load Balancer. Điền:
| Trường | Giá trị |
|---|---|
| Name | php-project-alb |
| Scheme và IP type | Internet facing, IPv4 |
| VPC và mappings | VPC lab, hai public subnet ở hai Availability Zone |
| Security Group | Chỉ php-alb-sg |
| Listener | HTTP 80 |
| Default action | Forward đến php-project-tg |
Chờ ALB Active rồi sao chép DNS name. Lúc chưa có target khỏe, ALB có thể trả 503; đây chưa phải bằng chứng ứng dụng lỗi. Không thêm EC2 tạm vào target group để làm kết quả có vẻ đã hoạt động.
Không cấu hình ALB stickiness để chữa lỗi token CSRF. Phiên phải được chia sẻ qua RDS. Với bài này, HTTP chỉ dành cho dữ liệu giả và IP được phép; khi mở cho người dùng thật phải có HTTPS, đăng nhập và phân quyền.

# 9 Tạo Auto Scaling Group
## Bước 14 Chọn template và mạng
Mở EC2, Auto Scaling Groups, Create Auto Scaling group. Name php-project-asg. Chọn php-project-template và version số cụ thể đã kiểm tra; tránh trỏ Latest vì phiên bản có thể thay đổi ngoài dự kiến.
Ở Network, chọn VPC lab và hai public subnet của tầng web. Dùng On demand cho bài này để dễ quan sát, không dùng Spot. Gắn existing load balancer target group php-project-tg. Bật thêm Elastic Load Balancing health checks.
| Tham số | Giá trị |
|---|---|
| Desired capacity | 2 |
| Minimum capacity | 2 |
| Maximum capacity | 3 |
| Health check grace period | 600 giây, điều chỉnh nếu cài máy thực tế lâu hơn |
| Default instance warmup | Bật, 300 giây, điều chỉnh theo thời gian khởi tạo |
| Scaling policy lúc tạo | Chưa tạo, thêm ở bước 17 |
| Group metrics collection | Bật chu kỳ một phút |
| Tag lan truyền đến instance | Project = php-asg-lab |
Nếu group metrics hoặc warmup không xuất hiện trong trình tạo, vào Details hoặc Monitoring của nhóm sau khi tạo để bật. Grace period giảm nguy cơ ASG thay máy trong lúc ứng dụng còn cài đặt; nó không trì hoãn việc ALB kiểm tra target. Warmup dùng trong đánh giá scaling, không thay cho grace period.
## Bước 15 Chờ hai target Healthy
Xem Instance management, Activity history và Target Groups, Targets. Chờ hai EC2 InService và hai target Healthy, kiểm tra chúng được phân bố qua hai Availability Zone khi tài nguyên khả dụng. Nếu bị tạo rồi xóa lặp lại, dừng thử tải và xem log khởi tạo; không tăng max size để che lỗi bootstrap.
Mở http://DNS_ALB trên trình duyệt. Thêm thành viên, giao việc, sửa trạng thái và tải lại. Giao diện hiển thị hostname của máy phục vụ. Từ Terminal máy cá nhân chạy nhiều lần lệnh sau để quan sát health.php được trả từ các máy khác nhau:
```
curl http://DNS_ALB/health.php
```
Khi hai máy đã hoạt động đúng, terminate EC2 php-asg-admin-temp để tránh tính phí kéo dài. Không terminate EC2 của bài trước nếu chưa chủ động kết thúc bài đó.

# 10 Xây dựng Dashboard CloudWatch
## Bước 16 Tạo dashboard
Mở CloudWatch cùng Region, Dashboards, Create dashboard, tên php-project-monitor. Chọn Line hoặc Number cho các widget, dùng thời gian xem 1 giờ và chu kỳ 1 phút. Trong All metrics, chọn đúng namespace và dimensions dưới đây.
| Widget | Namespace và metric | Dimension và thống kê |
|---|---|---|
| CPU nhóm web | AWS/EC2 CPUUtilization | AutoScalingGroupName = php-project-asg, Average |
| Quy mô nhóm | AWS/AutoScaling GroupDesiredCapacity và GroupInServiceInstances | AutoScalingGroupName, Average |
| Máy đang khởi tạo | AWS/AutoScaling GroupPendingInstances | AutoScalingGroupName, Average |
| Target khỏe | AWS/ApplicationELB HealthyHostCount | LoadBalancer và TargetGroup của lab, Minimum |
| Yêu cầu | AWS/ApplicationELB RequestCount | LoadBalancer của lab, Sum |
| Độ trễ | AWS/ApplicationELB TargetResponseTime | LoadBalancer của lab, Average hoặc p95 |
| Lỗi web | AWS/ApplicationELB HTTPCode_Target_5XX_Count | LoadBalancer của lab, Sum |
| Lỗi ALB | AWS/ApplicationELB HTTPCode_ELB_5XX_Count | LoadBalancer của lab, Sum |
CPU nhóm dùng AutoScalingGroupName, không chọn nhầm InstanceId của một máy. GroupDesiredCapacity và GroupInServiceInstances thuộc AWS/AutoScaling; CPU thuộc AWS/EC2. Dữ liệu mới có thể cần vài phút mới xuất hiện.
Thêm widget CWAgent mem_used_percent và disk_used_percent cho từng InstanceId. Với ổ đĩa, chọn path / và các dimension thiết bị, filesystem đi kèm đúng máy. Khi ASG thay máy, ID mới tạo chuỗi metric mới; thêm hoặc thay widget tương ứng, không coi metric trống của ID cũ là máy mới bị lỗi.
Thêm tùy chọn RDS CPUUtilization, DatabaseConnections và FreeableMemory với DBInstanceIdentifier = php-asg-db để phân biệt nút thắt web với CSDL. Tăng số EC2 không tự tăng CPU hoặc số kết nối cho RDS.
## Xác nhận agent và dữ liệu
Tại một máy web qua Session Manager, kiểm tra dịch vụ agent. Nếu RAM và disk không có, xem IAM role, cấu hình và log agent; không cài thêm agent chỉ để nhận CPU EC2 vốn đã có sẵn.
```
sudo /opt/aws/amazon-cloudwatch-agent/bin/amazon-cloudwatch-agent-ctl -m ec2 -a status
sudo tail -n 30 /opt/aws/amazon-cloudwatch-agent/logs/amazon-cloudwatch-agent.log
```

# 11 Chính sách tăng giảm số máy và cảnh báo
## Bước 17 Thêm Target tracking scaling policy
Mở EC2, Auto Scaling Groups, chọn nhóm, Automatic scaling, Create dynamic scaling policy. Policy type Target tracking scaling, tên keep-cpu-50, Metric type Average CPU utilization, Target value 50. Cho phép scale in; dùng default instance warmup 300 giây của nhóm.
Với min 2 và max 3, nhóm chỉ tăng đến ba và giảm thấp nhất về hai. Mức CPU 50 là giá trị mục tiêu, không có nghĩa cứ một mẫu vượt 50 là có máy ngay. Thời gian khởi tạo, metric, warmup và đánh giá của policy đều ảnh hưởng đến kết quả.
Auto Scaling tự tạo và quản lý các alarm phục vụ target tracking. Xem các alarm đó trong CloudWatch nhưng không sửa ngưỡng, số mẫu hoặc gán thêm hành động điều chỉnh số máy cho chúng. Scale in thường chậm hơn scale out; không hứa thời gian giảm máy cố định.
## Bước 18 Tạo cảnh báo giám sát riêng
Mở SNS, Topics, Create topic, Standard, tên php-project-alerts. Tạo Subscription Protocol Email và xác nhận từ email nhận được. Đây là thao tác sinh viên thực hiện trong lab; không điền email người khác khi chưa được đồng ý.
Trong CloudWatch, Alarms, All alarms, Create alarm. Chọn đúng metric và tạo ba alarm dưới đây, dùng chu kỳ một phút. Trong Additional configuration đặt số điểm theo yêu cầu.
| Alarm | Điều kiện | Missing data và hành động |
|---|---|---|
| php-cpu-high | CPU nhóm Average lớn hơn 70, 3 trên 3 mẫu | Missing, gửi SNS khi ALARM |
| php-healthy-low | HealthyHostCount Minimum nhỏ hơn 2, 2 trên 2 mẫu | Breaching, gửi SNS khi ALARM |
| php-target-5xx | HTTPCode_Target_5XX_Count Sum từ 5, 2 trên 3 mẫu | Not breaching, gửi SNS khi ALARM |
Hai alarm đầu cần chọn dimension nhóm hoặc cặp LoadBalancer và TargetGroup đúng lab. Metric lỗi 5XX có thể không xuất hiện khi không có lỗi; không tạo lỗi CSDL để ép xuất hiện metric. Có thể cấu hình alarm này sau khi có dữ liệu, hoặc bổ sung bằng công cụ được giảng viên chuẩn bị.
Các alarm riêng chỉ thông báo, không gắn EC2 action hoặc Auto Scaling action. Sau scale out, CPU trung bình có thể giảm và php-cpu-high chuyển OK; điều này không phủ nhận việc vừa có tải cao. Ghi lại thời điểm chuyển trạng thái thay vì chỉ chụp trạng thái cuối.

# 12 Thử tăng và giảm số máy theo tải CPU
## Bước 19 Tạo tải trên hai máy đang chạy
Chỉ thực hiện khi cả hai EC2 đã InService, target Healthy và đã hết warmup. Mở Systems Manager, Run Command, Run command, chọn document AWS-RunShellScript. Trong Target selection, chọn thủ công đúng hai InstanceId hiện tại thuộc php-project-asg; không chọn mọi EC2 trong tài khoản.
Trong Commands nhập lệnh dưới. Đặt Execution timeout 900 giây, maximum concurrency 2 nếu giao diện có trường này. Không cần lưu output vào S3; không in bí mật trong command.
```
stress-ng --cpu "$(nproc)" --cpu-load 80 --timeout 600s --metrics-brief
```
Lệnh tạo tải CPU trong 10 phút trên cả hai máy. Đây là tải tổng hợp bên trong EC2 để kiểm chứng scaling theo CPU, không phải đo số người dùng hoặc tốc độ xử lý ứng dụng. Không gửi thử tải đến dịch vụ hoặc địa chỉ ngoài bài lab.
Nếu chỉ tạo tải một trong hai máy, CPU trung bình có thể khoảng 40 phần trăm và không đạt mục tiêu 50. Không tự kết luận policy hỏng. Tải trên hai máy ban đầu giúp quan sát rõ hơn; EC2 mới tạo không tự nhận lại lệnh thử tải đã gửi trước đó.
## Bước 20 Thu thập bằng chứng scale out
Mở dashboard CloudWatch, Activity history của ASG, Instance management và Target Group song song. Ghi thời điểm bắt đầu tải, CPU trung bình, alarm do policy quản lý, Desired capacity, InService và Target Healthy.
Kết quả cần quan sát là Desired từ 2 lên 3, sau đó Pending giảm khi máy mới vào InService và Healthy. Có thể có độ trễ vài phút hoặc lâu hơn do cài gói, quota và trạng thái mạng. Nếu sau 10 đến 15 phút chưa có thay đổi, xem nguyên nhân trước khi chạy lại hoặc tăng thời gian thử.
## Bước 21 Kết thúc tải và chờ scale in
stress-ng tự dừng sau timeout. Nếu cần dừng sớm, gửi một Run Command mới đến đúng hai InstanceId ban đầu với pkill -TERM -x stress-ng. Hủy command trên Console không nên được coi là bằng chứng mọi tiến trình con đã dừng; kiểm tra bằng pgrep -a stress-ng.
Theo dõi CPU giảm và hoạt động policy cho đến khi nhóm về hai máy. Có thể phải chờ 15 phút hoặc hơn; warmup và alarm của policy làm kết quả khác với việc đổi Desired thủ công. Không sửa managed alarm để buộc nhóm giảm nhanh.
Máy t3 có cơ chế CPU credit; thử tải và chế độ Unlimited có thể phát sinh phí bổ sung. Giữ thời gian thử ngắn, không chạy stress-ng qua đêm. Không đổi max size vượt 3 để làm thí nghiệm này.

# 13 Kiểm tra dữ liệu dùng chung và thay thế máy lỗi
## Bước 22 Kiểm tra biểu mẫu qua hai máy
Khi nhóm có hai target Healthy và Stickiness tắt, mở ứng dụng, thêm thành viên và công việc giả. Tải lại nhiều lần; hostname có thể thay đổi nhưng danh sách phải giống nhau. Sửa công việc và xác nhận không gặp lỗi token CSRF khi yêu cầu GET và POST đi qua các máy khác nhau.
Để đối chiếu rõ, dùng trình duyệt tải biểu mẫu khi hiển thị hostname A, sau đó thực hiện một số yêu cầu và ghi nhận hostname sau khi gửi. Nếu muốn kiểm chứng tự động, cần dùng một cookie jar chung; mỗi lần curl mới không giữ cookie sẽ tạo phiên mới nên không kiểm tra được chia sẻ phiên.
## Bước 23 Dừng Apache trên một máy
Kết thúc thử tải trước. Chọn một EC2 đang InService của nhóm và ghi lại InstanceId. Vào Connect, Session Manager, chạy:
```
sudo systemctl stop apache2
```
Không dừng Apache trên cả hai máy và không xóa RDS. ALB sẽ thấy target Unhealthy sau các lần kiểm tra không đạt và tiếp tục gửi yêu cầu đến máy còn khỏe. Với ELB health checks đã bật trên ASG, nhóm sẽ thay máy lỗi bằng máy mới.
Quan sát HealthyHostCount giảm, alarm php-healthy-low chuyển ALARM nếu đủ thời gian và điều kiện đánh giá, rồi về OK khi phục hồi. Ghi nhận EC2 cũ bị thay bằng InstanceId mới. Khi sự cố phục hồi nhanh hơn hai chu kỳ cảnh báo, không nhất thiết có email; xem lịch sử metric và Activity history để giải thích.
Truy cập ứng dụng, kiểm tra công việc đã tạo vẫn còn và tiếp tục sửa được. Sau phục hồi cần hai target Healthy. Không kỳ vọng mọi yêu cầu trong lúc chuyển máy đều thành công; kết nối đang chạy có thể lỗi và cần gửi lại.
## Phân biệt hai cơ chế
| Thí nghiệm | Nguyên nhân | Bằng chứng chính |
|---|---|---|
| Tăng giảm số máy | CPU và target tracking | Desired đổi 2 đến 3 rồi về 2 |
| Thay máy lỗi | ELB health check báo Unhealthy | InstanceId thay đổi, quy mô mục tiêu được duy trì |
Dừng một EC2 trong ASG cũng có thể làm nhóm tạo máy bù. Vì vậy stop máy riêng lẻ không phải cách kết thúc lab; phải xử lý ASG trước khi thu hồi EC2.

# 14 Xem nhật ký và chẩn đoán sự cố
## Bước 24 Xem CloudWatch Logs
Mở CloudWatch, Log groups. Cần có /lab/team-project/access và /lab/team-project/error, mỗi EC2 có log stream theo InstanceId. Thời gian giữ log được đặt 3 ngày trong cấu hình agent; khi máy bị thay, log stream cũ vẫn còn trong giới hạn lưu giữ.
Mở Logs Insights, chọn nhóm access, đặt khoảng thời gian 30 phút và chạy truy vấn ngắn sau:
```
fields @timestamp, @logStream, @message
| sort @timestamp desc
| limit 30
```
Nhật ký access có cả yêu cầu health check. Số dòng access không tương đương số người dùng. Chọn nhóm error và dùng truy vấn dưới khi ứng dụng trả 503 hoặc báo không lưu được:
```
fields @timestamp, @logStream, @message
| filter @message like /TEAM_APP|PHP|error|Uncaught/
| sort @timestamp desc
| limit 30
```
Giới hạn thời gian và số lần chạy vì Logs Insights có thể tính phí theo lượng dữ liệu quét. Không ghi mật khẩu, JSON cấu hình hoặc nội dung phiên vào log để hỗ trợ chẩn đoán.
## Những lỗi thường gặp
| Hiện tượng | Kiểm tra và xử lý |
|---|---|
| Không mở được Session Manager | Role, SSM Agent của AMI, public IP, route Internet, HTTPS outbound và quyền của người thao tác |
| EC2 tạo rồi bị thay liên tục | cloud-init-output.log, commit SHA, GetParameter, TLS CA, cú pháp Apache; tăng grace khi đã xác định máy cài chậm |
| ALB 503 | Có target đăng ký và Healthy chưa; đúng path health.php, port 80 và Security Group chưa |
| ALB 502 hoặc 504 | Apache, timeout, PHP và kết nối RDS; phân biệt lỗi ALB với lỗi 5XX từ target |
| Web 503 nhưng health vẫn 200 | Cấu hình RDS, DNS, php-db-sg, quyền team_app, TLS và bảng app_sessions |
| POST bị CSRF 403 sau đổi máy | Cả hai máy có cùng schema phiên, cùng RDS, cùng code, cookie của cùng trình duyệt; không chữa bằng stickiness |
| Không tăng máy | CPU dimension nhóm, đủ tải trên hai máy, max 3, policy bật, quota EC2 và Activity history |
| Không có log hoặc RAM | CloudWatch Agent, role, Internet ra ngoài, đúng Region, log stream và InstanceId hiện tại |

# 15 Cập nhật phiên bản trong Auto Scaling Group
Không dùng git pull trên từng EC2 để cập nhật nhóm. Máy bị thay sẽ lại lấy SHA trong template, làm các máy chạy khác phiên bản. Bài lab dùng Launch Template version mới và Instance refresh.
## Bước 25 Tạo bản code mới
Ở máy cục bộ, đổi APP_VERSION trong public/index.php từ 2.0.0 sang 2.1.0, kiểm tra cú pháp, commit và push. Ghi lại SHA mới bằng git rev-parse HEAD.
Trong EC2, Launch Templates, chọn php-project-template, Actions, Modify template Create new version. Giữ các trường đã kiểm tra, chỉ đổi COMMIT_SHA trong User data sang SHA mới. Ghi mô tả phiên bản và lưu. Không sửa JSON cấu hình riêng vào Git.
## Bước 26 Chọn template version và refresh
Mở ASG, Details, Launch template, Edit; chọn version mới theo số cụ thể. Việc đổi template không tự làm các EC2 cũ thay đổi. Mở Instance refresh, Start instance refresh, chọn thay theo Rolling và các tham số trong bảng dưới nếu Console hỗ trợ.
| Tham số | Giá trị thực hành |
|---|---|
| Minimum healthy percentage | 50 |
| Maximum healthy percentage | 100 |
| Instance warmup | 300 giây hoặc giá trị đã đo thực tế |
| Skip matching | Bật nếu có, để giữ máy đã đúng template version |
Với hai máy và tỷ lệ tối thiểu 50, có thể chỉ còn một máy phục vụ trong lúc thay máy, nên chỉ refresh khi tải thấp và đã kiểm tra RDS. Giữ max size 3. Nếu giao diện không cho cấu hình đúng các tỷ lệ trên, đọc phần giải thích của Console trước khi bắt đầu; không mặc định một lượt thay hết máy.
Quan sát tiến độ, target mới Healthy, hostname và Phiên bản 2.1.0. Kiểm tra dữ liệu còn nguyên. Khi refresh hoàn tất, đối chiếu template version ở cả hai EC2 trong Instance management.
Nếu phiên bản mới lỗi, dừng refresh, xem log và đổi ASG về template version tốt trước đó, rồi thực hiện refresh có kiểm soát. Không giả định đổi con trỏ template sẽ tự khôi phục các máy đã bị thay. Bài này không cấu hình rollback tự động.
Sửa SecureString cũng không tự cập nhật tệp config trên máy đang chạy. Sau khi đổi mật khẩu hoặc endpoint, phải phối hợp thay cấu hình và refresh; không đổi mật khẩu RDS giữa thí nghiệm để thử phản ứng của ASG.

# 16 Kiểm thử nộp bài và tiêu chí đánh giá
Bảng dưới là yêu cầu sinh viên thực hiện trong tài khoản lab, không phải kết quả triển khai đã được xác nhận sẵn.
| Mã | Thực hiện | Kết quả cần đạt |
|---|---|---|
| A01 | Kiểm tra mã và template | Repository không có mật khẩu, template dùng SHA xác định |
| A02 | Kiểm tra kết nối web | Hai target Healthy ở hai Availability Zone khi tài nguyên khả dụng |
| A03 | Thêm và sửa việc qua ALB | Cùng dữ liệu khi đổi hostname, không mất phiên CSRF |
| A04 | Mở public IP của một EC2 từ máy cá nhân | HTTP trực tiếp bị Security Group chặn |
| A05 | Xem dashboard | Có CPU nhóm, Desired, InService, Pending và HealthyHostCount |
| A06 | Xem CloudWatch Agent | Có RAM, disk và log stream của từng máy |
| A07 | Thử tải CPU trên hai máy | Desired tăng 2 lên 3, target mới Healthy |
| A08 | Kết thúc tải | CPU giảm, nhóm trở về 2 khi policy cho phép |
| A09 | Dừng Apache trên một máy | Máy bị thay, số máy khỏe phục hồi và dữ liệu còn |
| A10 | Xem cảnh báo | Có lịch sử alarm và subscription SNS đã xác nhận |
| A11 | Refresh phiên bản | Hai máy chạy 2.1.0 và giữ nguyên dữ liệu |
| A12 | Kết thúc lab | Không còn ASG, ALB, RDS và tài nguyên lab gây phí |
## Hồ sơ nộp bài
Nộp đường dẫn GitHub, hai SHA, sơ đồ, ảnh cấu hình Security Group và bảng kiểm thử. Chụp dashboard trước tải, khi tăng máy và sau giảm máy. Kèm Activity history có thời điểm, ảnh hai target Healthy và ảnh thay máy lỗi. Không gửi mật khẩu, parameter value, khóa pem hoặc thông tin truy cập AWS.
| Nội dung | Điểm |
|---|---|
| Mã nguồn dùng RDS và phiên dùng chung | 2 |
| ALB và ASG đúng cấu hình mạng và health check | 2 |
| Dashboard CloudWatch và nhật ký | 2 |
| Bằng chứng scale out scale in và thay máy | 2 |
| Cảnh báo cập nhật phiên bản và thu hồi tài nguyên | 2 |
Giải thích vì sao CPU trên một máy cao chưa chắc làm CPU trung bình nhóm vượt ngưỡng; vì sao thêm máy web không tự làm RDS mạnh hơn; và vì sao health.php không gọi RDS trong thiết kế này.

# 17 Chi phí và thu hồi tài nguyên
## Kiểm tra chi phí trước khi tạo
Bài lab có ít nhất hai EC2 web, một RDS, một ALB và tạm thời một EC2 quản trị. Khi thử tải, ASG có thể tăng lên ba web. EBS, IPv4 public, ALB, RDS, CloudWatch detailed monitoring, custom metrics, log ingestion, Logs Insights, alarm, dashboard và SNS có thể phát sinh phí theo tài khoản và mức dùng.
Không mặc định bài lab được Free Tier chi trả. Giữ max size 3, đặt thời gian kết thúc buổi thực hành và kiểm tra Billing. Không có NAT Gateway trong mô hình này. ASG không tự tắt tài nguyên khi lớp kết thúc; ngân sách cảnh báo không phải giới hạn chi tiêu cứng.
## Bước 27 Thu hồi theo thứ tự
1. Dừng thử tải. Lưu bằng chứng và sao lưu dữ liệu cần giữ ra nơi được phép. Kiểm tra đúng tên và ID tài nguyên lab.
2. Trong ASG, tắt hoặc xóa scaling policy của lab, đặt Min = 0, Desired = 0. Nếu có scheduled action, xóa trước. Chờ các EC2 bị terminate rồi xóa ASG. Không chỉ stop từng máy vì nhóm có thể tạo máy khác bù vào.
3. Xóa ALB, rồi xóa Target Group khi không còn listener dùng. Kiểm tra EC2 quản trị tạm đã terminate. Xóa Launch Template của lab nếu không giữ cho buổi sau.
4. Với RDS, tắt deletion protection nếu đang bật, chọn Delete database. Với dữ liệu giả có thể bỏ final snapshot sau khi xác nhận không cần giữ; nếu tạo snapshot thì snapshot vẫn có thể tính phí. Kiểm tra retained automated backups và snapshots còn lại.
5. Xóa các alarm do người dùng tạo, dashboard, hai CloudWatch log group và SNS subscription/topic của lab khi không còn dùng. Alarm target tracking thường được dịch vụ quản lý; kiểm tra phần còn lại thay vì xóa nhầm alarm của nhóm khác.
6. Xóa parameter riêng và role/policy riêng sau khi không còn instance dùng. Xóa DB subnet group, các Security Group, subnet, route table và VPC lab theo dependency. Chờ ENI do ALB và RDS tạo được dịch vụ giải phóng.
7. Kiểm tra EBS volume, snapshot và public IP còn giữ, chỉ xóa phần đúng lab. Xem lại Billing sau đó vì số liệu cập nhật có độ trễ.
## Giới hạn của bộ tài liệu
Mã được kiểm tra cú pháp và cấu trúc tệp ở môi trường soạn bài. Việc xác minh kết nối RDS, phiên MySQL, cài đặt User data, IAM, ALB, scaling và metric cần được thực hiện trong tài khoản AWS của lớp. Không coi kiểm tra tĩnh là bằng chứng hệ thống đã được triển khai hoặc có khả năng chịu tải xác định.

# 18 Tài liệu tham khảo
Đối chiếu nguồn chính thức ngày 2 tháng 11 năm 2026. Nhãn giao diện và lựa chọn máy có thể khác theo Region, quyền tài khoản và phiên bản Console; đối chiếu chức năng tương ứng trước khi tạo tài nguyên.
## Auto Scaling và Load Balancer
Target tracking scaling policies
https://docs.aws.amazon.com/autoscaling/ec2/userguide/as-scaling-target-tracking.html
Group metrics và namespace AWS AutoScaling
https://docs.aws.amazon.com/autoscaling/ec2/userguide/ec2-auto-scaling-metrics.html
Health check grace period
https://docs.aws.amazon.com/autoscaling/ec2/userguide/health-check-grace-period.html
Default instance warmup
https://docs.aws.amazon.com/autoscaling/ec2/userguide/ec2-auto-scaling-default-instance-warmup.html
Gắn ALB với Auto Scaling Group
https://docs.aws.amazon.com/autoscaling/ec2/userguide/attach-load-balancer-asg.html
ALB CloudWatch metrics
https://docs.aws.amazon.com/elasticloadbalancing/latest/application/load-balancer-cloudwatch-metrics.html
## CloudWatch và Systems Manager
Cấu hình CloudWatch Agent
https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/CloudWatch-Agent-Configuration-File-Details.html
Tải agent chính thức
https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/download-CloudWatch-Agent-on-EC2-Instance-commandline-first.html
SSM Agent trên Ubuntu
https://docs.aws.amazon.com/systems-manager/latest/userguide/agent-install-ubuntu.html
SecureString và khóa KMS
https://docs.aws.amazon.com/systems-manager/latest/userguide/secure-string-parameter-kms-encryption.html
## Dữ liệu và phiên làm việc
Kết nối SSL TLS với RDS
https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.SSL.html
PHP SessionHandlerInterface
https://www.php.net/manual/en/class.sessionhandlerinterface.php
PHP SessionUpdateTimestampHandlerInterface
https://www.php.net/manual/en/class.sessionupdatetimestamphandlerinterface.php
Trước buổi dạy, giảng viên cần chạy trọn quy trình, đo thời gian khởi tạo để điều chỉnh grace period và warmup, kiểm tra quyền IAM và xác nhận quota trong tài khoản thực hành.
