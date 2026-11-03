<?php
declare(strict_types=1);
$file = dirname(__DIR__).'/config/database.json';
if (!is_file($file)) throw new RuntimeException('Missing private database configuration');
$cfg = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
foreach (['host','database','username','password','ssl_ca'] as $key) {
    if (!is_string($cfg[$key] ?? null) || $cfg[$key] === '') throw new RuntimeException('Invalid database configuration');
}
if (!is_file($cfg['ssl_ca'])) throw new RuntimeException('Missing RDS CA bundle');
return new PDO('mysql:host='.$cfg['host'].';port=3306;dbname='.$cfg['database'].';charset=utf8mb4',
    $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_TIMEOUT=>5,
        PDO::MYSQL_ATTR_SSL_CA=>$cfg['ssl_ca'],
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=>true,
    ]);
