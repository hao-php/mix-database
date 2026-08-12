<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PoolTest extends TestCase
{

    private function unbufferedPool(): \Haoa\MixDatabase\Database
    {
        $db = new \Haoa\MixDatabase\Database(
            MYSQL_DSN,
            MYSQL_USERNAME,
            MYSQL_PASSWORD,
            [\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]
        );
        $db->startPool(1, 1);
        return $db;
    }

    private function connectionId(\Haoa\MixDatabase\Connection $connection): int
    {
        $property = new \ReflectionProperty(
            \Haoa\MixDatabase\AbstractConnection::class,
            'connector'
        );
        $property->setAccessible(true);
        $connector = $property->getValue($connection);
        $statement = $connector->instance()->query('SELECT CONNECTION_ID()');
        $id = (int) $statement->fetchColumn();
        $statement->closeCursor();
        return $id;
    }

    private function killConnection(int $id): void
    {
        db()->exec('KILL CONNECTION ' . $id);
    }

    /** 未缓冲链式查询应持有连接到读取完成，并在临时 Connection 析构后归还 */
    public function testUnbufferedChainReturnsConnectionOnDestruct(): void
    {
        $db = $this->unbufferedPool();
        swoole_co_run(function () use ($db) {
            $row = $db->debug(function () use ($db) {
                // execute 完成后结果尚未读取，连接仍由临时 Connection 持有。
                $this->assertSame(1, $db->poolStats()['active']);
                $this->assertSame(0, $db->poolStats()['idle']);
            })->raw('SELECT 1 AS value UNION ALL SELECT 2')->first();
            $this->assertEquals(1, $row->value);
            // 链式表达式结束后临时 Connection 析构并归还连接。
            $this->assertSame(0, $db->poolStats()['active']);
            $this->assertSame(1, $db->poolStats()['idle']);

            $rows = $db->raw('SELECT 1 AS value UNION ALL SELECT 2')->get();
            $this->assertCount(2, $rows);
            $this->assertSame(0, $db->poolStats()['active']);
            $this->assertTrue($db->tableExists('users'));
        });
    }

    /** INSERT/UPDATE/DELETE 等无结果集语句应在 execute 后立即归还连接 */
    public function testStatementWithoutResultReturnsConnectionImmediately(): void
    {
        $db = $this->unbufferedPool();
        swoole_co_run(function () use ($db) {
            // 即使业务暂时保存返回对象，无结果集语句也不占用池连接。
            $connection = $db->exec('UPDATE users SET balance = balance WHERE id = -1');
            $this->assertSame(0, $connection->rowCount());
            $this->assertSame(0, $db->poolStats()['active']);
            $this->assertSame(1, $db->poolStats()['idle']);
        });
    }

    /** 事务提交或回滚前应关闭未读取的结果集，避免 MySQL 2014 错误 */
    public function testTransactionClosesUnreadResultBeforeCommitAndRollback(): void
    {
        $db = $this->unbufferedPool();
        swoole_co_run(function () use ($db) {
            $tx = $db->beginTransaction();
            $tx->raw('SELECT 1 UNION ALL SELECT 2');
            $tx->commit();
            $this->assertSame(0, $db->poolStats()['active']);
            $this->assertSame(1, $db->poolStats()['idle']);

            $tx = $db->beginTransaction();
            $tx->raw('SELECT 1 UNION ALL SELECT 2');
            $tx->rollback();
            $this->assertSame(0, $db->poolStats()['active']);
            $this->assertSame(1, $db->poolStats()['idle']);
        });
    }

    /** prepare/execute 阶段连接断开时应自动重连并重试一次 */
    public function testReconnectRetriesExecution(): void
    {
        $db = $this->unbufferedPool();
        swoole_co_run(function () use ($db) {
            $connection = $db->table('users')->where('id = ?', 1);
            $this->killConnection($this->connectionId($connection));
            $this->assertEquals(1, $connection->first()->id);
            unset($connection);
            $this->assertSame(1, $db->poolStats()['idle']);
        });
    }

    /** fetch 阶段断线应抛出 PDOException，并在析构时丢弃异常连接 */
    public function testFetchDisconnectThrowsAndDiscardsConnection(): void
    {
        $db = $this->unbufferedPool();
        swoole_co_run(function () use ($db) {
            $connection = $db->table('users');
            $id = $this->connectionId($connection);
            $connection->raw(
                "SELECT REPEAT('x', 1048576) AS value "
                . 'FROM information_schema.columns LIMIT 20'
            );
            $this->killConnection($id);

            try {
                $connection->queryAll();
                $this->fail('fetch 断线后应抛出 PDOException');
            } catch (\PDOException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            unset($connection);
            $this->assertSame(1, $db->poolStats()['idle']);
        });
    }

    /** 多个协程争抢单个连接时，未缓冲结果读取完成前连接不得被其他协程借走 */
    public function testCoroutinesCannotBorrowConnectionWithUnreadResult(): void
    {
        $db = $this->unbufferedPool();
        swoole_co_run(function () use ($db) {
            $ready = new \Swoole\Coroutine\Channel(1);
            $done = new \Swoole\Coroutine\Channel(2);
            $results = [];

            go(function () use ($db, $ready, $done, &$results) {
                $row = $db->debug(function () use ($ready) {
                    $ready->push(true);
                    \Swoole\Coroutine::sleep(0.05);
                })->raw(
                    'SELECT 1 AS value UNION ALL SELECT SLEEP(0.1) AS value'
                )->first();
                $results['first'] = (int) $row->value;
                $done->push(true);
            });

            go(function () use ($db, $ready, $done, &$results) {
                $ready->pop();
                $results['second'] = (int) $db->raw('SELECT 2')->queryScalar();
                $done->push(true);
            });

            $done->pop();
            $done->pop();

            $this->assertSame(1, $results['first']);
            $this->assertSame(2, $results['second']);
            $this->assertSame(0, $db->poolStats()['active']);
            $this->assertSame(1, $db->poolStats()['idle']);
        });
    }

//    public function testConnReturn(): void
//    {
//        $_this = $this;
//        $func = function () use ($_this) {
//            $db = db();
//            $db->startPool(1, 1);
//            for ($i = 0; $i < 100; $i++) {
//                go(function () use ($db, $i) {
//                    $db->debug(function (\Haoa\MixDatabase\ConnectionInterface $conn) {
//                        $stat = $conn->statement();
//                        while ($row = $stat->fetch()) {
//                            usleep(100000);
//                        }
//                    })->raw('select sleep(0.1)');
//                    // echo sprintf("%d: %s\n", $i, json_encode($db->poolStats()));
//                });
//            }
//            $_this->assertTrue(true);
//        };
//        swoole_co_run($func);
//    }

    /** 协程数超过 maxOpen 时应等待空闲连接，验证连接池容量限制 */
    public function testMaxOpen(): void
    {
        $_this = $this;
        $func = function () use ($_this) {
            $db = db();
            $max = swoole_cpu_num() * 2;
            $db->startPool($max / 2, $max / 2);
            $time = microtime(true);
            $chan = new \Swoole\Coroutine\Channel();
            for ($i = 0; $i < $max; $i++) {
                go(function () use ($db, $chan) {
                    $db->raw('select sleep(1)')->queryAll();
                    $chan->push(true);
                });
            }
            for ($i = 0; $i < $max; $i++) {
                $chan->pop();
            }
            $duration = microtime(true) - $time;
            $_this->assertTrue($duration >= 2 && $duration <= 3);
        };
        swoole_co_run($func);
    }

    /** 两个耗时查询应通过 Swoole Hook 并发执行，而不是在同一协程串行阻塞 */
    public function testQueryTriggersCoroutineSwitch(): void
    {
        $_this = $this;
        $func = function () use ($_this) {
            $pool = pool();
            $chan = new \Swoole\Coroutine\Channel(2);

            $start = microtime(true);

            for ($i = 0; $i < 2; $i++) {
                go(function () use ($pool, $chan) {
                    // 模拟耗时查询，依赖 Swoole 协程 hook + 连接池触发切换
                    $pool->raw('select sleep(1)')->queryAll();
                    $chan->push(true);
                });
            }

            $chan->pop();
            $chan->pop();

            $duration = microtime(true) - $start;

            // 如果查询在同一个协程串行执行，大约需要 2 秒；
            // 若触发协程切换并并发执行，则总耗时应接近 1 秒。
            $_this->assertTrue($duration > 0.8 && $duration < 1.8, 'duration=' . $duration);
        };

        swoole_co_run($func);
    }

    /*
    public function testMaxLifetime(): void
    {
        $_this = $this;
        $func  = function () use ($_this) {
            $db = db();
            $db->setMaxLifetime(1);

            $conn = $db->borrow();
            $id   = spl_object_hash($conn);
            $conn = null;
            sleep(1);
            $conn = $db->borrow();
            $id1  = spl_object_hash($conn);

            $_this->assertNotEquals($id, $id1);
        };
        swoole_run($func);
    }

    public function testWaitTimeout(): void
    {
        $_this = $this;
        $func  = function () use ($_this) {
            $db = db();
            $db->setMaxOpenConns(1);
            $db->setWaitTimeout(0.001);

            $conn = $db->borrow();
            try {
                $db->borrow();
            } catch (\Throwable $exception) {
                $_this->assertContains('Wait timeout', $exception->getMessage());
            }
        };
        swoole_run($func);
    }
    */

}
