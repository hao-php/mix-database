<?php

// 测试数据库连接示例配置
// 复制本文件为 db.php 并按需修改为你的本地环境配置

// 按需填写一个或两个连接；留空的驱动不运行。
const TEST_DATABASES = [
    'mysql' => [
        'dsn' => 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4;dbname=test',
        'username' => 'root',
        'password' => '123456',
    ],
    'pgsql' => [
        'dsn' => '', // 例如 pgsql:host=127.0.0.1;port=5432;dbname=test
        'username' => '',
        'password' => '',
    ],
];

