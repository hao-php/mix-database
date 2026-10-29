<?php

declare(strict_types=1);

/** 为当前测试驱动准备独立的基础表；仅删除本轮创建的表。 */
function initializeTestFixtures(): void
{
    if (getenv('TEST_NO_FIXTURES')) {
        return;
    }
    $pdo = new PDO(TEST_DSN, TEST_USERNAME, TEST_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $created = [];
    $mysql = TEST_DRIVER === 'mysql';
    foreach (['mixdb_test_users', 'mixdb_test_news'] as $table) {
        $exists = $mysql
            ? $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?')
            : $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?');
        $exists->execute([$table]);
        if ($exists->fetchColumn()) {
            continue;
        }
        if ($table === 'mixdb_test_users') {
            $pdo->exec($mysql
                ? 'CREATE TABLE mixdb_test_users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL DEFAULT \'\', balance INT UNSIGNED NOT NULL, add_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB'
                : 'CREATE TABLE mixdb_test_users (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL DEFAULT \'\', balance INTEGER NOT NULL, add_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
            $pdo->exec("INSERT INTO mixdb_test_users (id, name, balance) VALUES (1, 'foo2', 102)");
            if ($mysql) {
                $pdo->exec('ALTER TABLE mixdb_test_users AUTO_INCREMENT = 11');
            } else {
                $pdo->exec("SELECT setval(pg_get_serial_sequence('mixdb_test_users', 'id'), 10, true)");
            }
        } else {
            $pdo->exec($mysql
                ? 'CREATE TABLE mixdb_test_news (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, uid INT UNSIGNED NOT NULL, title VARCHAR(100) NOT NULL, content VARCHAR(100) NOT NULL) ENGINE=InnoDB'
                : 'CREATE TABLE mixdb_test_news (id SERIAL PRIMARY KEY, uid INTEGER NOT NULL, title VARCHAR(100) NOT NULL, content VARCHAR(100) NOT NULL)');
            $pdo->exec("INSERT INTO mixdb_test_news (uid, title, content) VALUES (1, '标题1', '内容1')");
        }
        $created[] = $table;
    }
    register_shutdown_function(static function () use ($pdo, $created) {
        foreach (array_reverse($created) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    });
}
