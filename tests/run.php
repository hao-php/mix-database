<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
if (!defined('TEST_DATABASES')) {
    fwrite(STDERR, "tests/db.php 必须定义 TEST_DATABASES\n");
    exit(1);
}
$targets = array_filter(TEST_DATABASES, static function ($config) {
    return !empty($config['dsn']);
});
if (!$targets) {
    fwrite(STDERR, "没有配置测试数据库\n");
    exit(1);
}
$exitCode = 0;
foreach ($targets as $driver => $config) {
    echo "\n=== {$driver} 测试 ===\n";
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../vendor/bin/phpunit')
        . ' -c ' . escapeshellarg(__DIR__ . '/phpunit.xml.dist');
    foreach (array_slice($argv, 1) as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    $env = 'TEST_DRIVER=' . escapeshellarg($driver) . ' ';
    passthru($env . $command, $code);
    if ($code !== 0) {
        $exitCode = 1;
    }
}
exit($exitCode);
