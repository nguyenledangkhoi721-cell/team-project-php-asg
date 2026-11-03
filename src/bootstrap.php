<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Ho_Chi_Minh');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Lab-Node: '.gethostname());
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirect(): never { header('Location: index.php', true, 303); exit; }
function field(string $key, int $max): string {
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw)) throw new InvalidArgumentException('Dữ liệu không hợp lệ.');
    $value = trim($raw);
    if ($value === '' || strlen($value) > $max) throw new InvalidArgumentException('Thiếu nội dung hoặc nội dung quá dài.');
    return $value;
}
function positiveId(mixed $value): int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new InvalidArgumentException('Mã bản ghi không hợp lệ.');
    return $id;
}

try {
    $db = require __DIR__.'/database.php';
    require __DIR__.'/DatabaseSessionHandler.php';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '1440');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>false]);
    // The lab ALB listener is HTTP and its source IP is restricted.
    // Enable secure cookies when moving to a trusted HTTPS listener.
    session_set_save_handler(new DatabaseSessionHandler($db), true);
    if (!session_start()) throw new RuntimeException('Session unavailable');
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
} catch (Throwable $ex) {
    error_log('TEAM_APP bootstrap unavailable: '.get_class($ex));
    http_response_code(503);
    exit('Dịch vụ dữ liệu hoặc phiên làm việc chưa sẵn sàng. Kiểm tra cấu hình và nhật ký.');
}
