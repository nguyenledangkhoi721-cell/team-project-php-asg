<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$ready = is_file(dirname(__DIR__).'/runtime/ready') && extension_loaded('pdo_mysql');
http_response_code($ready ? 200 : 503);
echo json_encode(['status'=>$ready?'ok':'starting','node'=>gethostname()], JSON_THROW_ON_ERROR);
// Local web readiness only. Database health is monitored separately to avoid
// replacing every web node when a shared database has an outage.
