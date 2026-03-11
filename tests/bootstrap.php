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

/**
 * @return \Haoa\MixDatabase\Database
 */
function db()
{
    return new \Haoa\MixDatabase\Database(MYSQL_DSN, MYSQL_USERNAME, MYSQL_PASSWORD);
}

/**
 * @return \Haoa\MixDatabase\Database
 */
function pool()
{
    $db = new \Haoa\MixDatabase\Database(MYSQL_DSN, MYSQL_USERNAME, MYSQL_PASSWORD);
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
