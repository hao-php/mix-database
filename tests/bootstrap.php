<?php

require __DIR__ . '/../vendor/autoload.php';

const MYSQL_DSN = 'mysql:host=qh-mysql57;port=3306;charset=utf8;dbname=test';
const MYSQL_USERNAME = 'root';
const MYSQL_PASSWORD = 'dcqhmsql';

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
    ]);
    $scheduler->add(function () use ($func) {
        call_user_func($func);
    });
    $scheduler->start();
}
