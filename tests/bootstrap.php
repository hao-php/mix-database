<?php

require __DIR__ . '/../vendor/autoload.php';

// 加载数据库配置：tests/db.php（由 db.example.php 复制而来）
$dbConfigFile = __DIR__ . '/db.php';
if (! file_exists($dbConfigFile)) {
    throw new RuntimeException(
        "测试数据库配置不存在：{$dbConfigFile}，" .
        "请先复制 tests/db.example.php 为 tests/db.php 并修改为你的本地配置。"
    );
}
require $dbConfigFile;

// 只读取新格式的双驱动配置。
if (!defined('TEST_DATABASES')) {
    throw new RuntimeException('tests/db.php 必须定义 TEST_DATABASES');
}
$configured = array_filter(TEST_DATABASES, static function ($config) {
    return !empty($config['dsn']);
});
if (!$configured) {
    throw new RuntimeException('tests/db.php 未配置数据库连接');
}
$keys = array_keys($configured);
$selected = getenv('TEST_DRIVER') ?: $keys[0];
if (!isset($configured[$selected])) {
    throw new RuntimeException('未配置测试驱动：' . $selected);
}
$config = $configured[$selected];
if (strpos($config['dsn'], $selected . ':') !== 0) {
    throw new RuntimeException('测试驱动与 DSN 不匹配：' . $selected);
}
define('TEST_DRIVER', $selected);
define('TEST_DSN', $config['dsn']);
define('TEST_USERNAME', $config['username'] ?? '');
define('TEST_PASSWORD', $config['password'] ?? '');
require __DIR__ . '/fixtures.php';
initializeTestFixtures();

/**
 * @return \Haoa\MixDatabase\Database
 */
function db()
{
    return new \Haoa\MixDatabase\Database(TEST_DSN, TEST_USERNAME, TEST_PASSWORD);
}

/**
 * @return \Haoa\MixDatabase\Database
 */
function pool()
{
    $db = new \Haoa\MixDatabase\Database(TEST_DSN, TEST_USERNAME, TEST_PASSWORD);
    $db->startPool(10, 10);
    return $db;
}

function swoole_co_run($func)
{
    $scheduler = new \Swoole\Coroutine\Scheduler;
    $scheduler->set([
        'hook_flags' => SWOOLE_HOOK_ALL,
        'log_level' => SWOOLE_LOG_WARNING,
    ]);
    $scheduler->add(function () use ($func) {
        call_user_func($func);
    });
    $scheduler->start();
}
