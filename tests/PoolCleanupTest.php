<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Haoa\MixDatabase\Database as CleanupClient;
use Haoa\MixDatabase\Connector as CleanupDriver;
use Haoa\ObjectPool\DialerInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/** 使用真实客户端与连接池、模拟物理连接的清理集成测试。 */
final class PoolCleanupTest extends TestCase
{
    /** 缺少协程环境时明确跳过，不使用业务服务配置。 */
    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('swoole') || !function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('连接池清理测试需要 Swoole 协程环境');
        }
    }

    /**
     * 在独立调度器内验证行为，确保断言异常交回 PHPUnit。
     * @param callable $callback 用例逻辑
     */
    private function runCoroutine(callable $callback): void
    {
        $failure = null;
        $started = Swoole\Coroutine\run(static function () use ($callback, &$failure): void {
            try {
                $callback();
            } catch (Throwable $exception) {
                $failure = $exception;
            }
        });
        if ($failure !== null) {
            throw $failure;
        }
        $this->assertNotSame(false, $started);
    }

    /**
     * 读取受保护的连接池状态，不依赖应用容器。
     * @param object $object 待读取对象
     * @param string $name 属性名
     * @return mixed
     */
    private function readProperty(object $object, string $name)
    {
        $property = new ReflectionProperty($object, $name);
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        return $property->getValue($object);
    }

    /**
     * 注入模拟依赖，避免建立真实网络连接。
     * @param object $object 待设置对象
     * @param string $name 属性名
     * @param mixed $value 属性值
     */
    private function writeProperty(object $object, string $name, $value): void
    {
        $property = new ReflectionProperty($object, $name);
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue($object, $value);
    }

    /**
     * 启动真实客户端连接池，仅替换拨号器和物理连接。
     * @param int $maxLifetime 最大生命周期，单位秒
     * @param int|null $maxIdleTime 最大空闲时间，null 表示使用旧四参数调用
     */
    private function createClient(int $maxLifetime = 0, ?int $maxIdleTime = 10): array
    {
        // 跳过会立即建连的客户端构造函数，仍执行真实 startPool 路径。
        $client = (new ReflectionClass(CleanupClient::class))->newInstanceWithoutConstructor();
        if ($maxIdleTime === null) {
            $client->startPool(2, 2, $maxLifetime, 1);
        } else {
            $client->startPool(2, 2, $maxLifetime, 1, $maxIdleTime);
        }
        $pool = $this->readProperty($client, 'pool');
        $state = (object) ['connections' => [], 'closed' => 0];
        $dialer = $this->createMock(DialerInterface::class);
        $dialer->method('dial')->willReturnCallback(function () use ($state): object {
            $connection = $this->getMockBuilder(CleanupDriver::class)->disableOriginalConstructor()->onlyMethods(['close', 'instance'])->getMock();
            $pdo = $this->createStub(PDO::class);
            $pdo->method('inTransaction')->willReturn(false);
            $connection->method('instance')->willReturn($pdo);
            $connection->method('close')->willReturnCallback(static function () use ($state): void {
                ++$state->closed;
            });
            $state->connections[] = $connection;
            return $connection;
        });
        $this->writeProperty($pool, 'dialer', $dialer);
        return [$client, $pool, $state];
    }

    /**
     * 借用真实 Connection 包装对象，验证其析构归还行为。
     * @param CleanupClient $client 待借用客户端
     */
    private function borrowConnection(CleanupClient $client): object
    {
        $method = new ReflectionMethod($client, 'borrow');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        return $method->invoke($client);
    }

    /** 第五个启动参数应实际控制回收，回收后只在下次使用时建连。 */
    public function testStartPoolForwardsIdleTimeAndReopensOnDemand(): void
    {
        $this->runCoroutine(function (): void {
            [$client, $pool, $state] = $this->createClient();
            $connection = $this->borrowConnection($client);
            unset($connection);
            $this->assertSame(1, $client->poolStats()['idle']);
            $state->connections[0]->lastReturnTime = time() - 20;
            $this->assertSame(1, $client->cleanupIdleConnections());
            $this->assertSame(1, $state->closed);
            $this->assertCount(1, $state->connections);
            $this->assertSame(['total' => 0, 'idle' => 0, 'active' => 0], $client->poolStats());
            $next = $this->borrowConnection($client);
            $this->assertCount(2, $state->connections);
            unset($next);
            $this->assertSame(1, $client->poolStats()['idle']);
        });
    }

    /** 原四参数调用默认不启用空闲超时，并继续复用连接。 */
    public function testLegacyStartPoolKeepsIdleTimeoutDisabled(): void
    {
        $this->runCoroutine(function (): void {
            [$client, $pool, $state] = $this->createClient(0, null);
            $connection = $this->borrowConnection($client);
            unset($connection);
            $state->connections[0]->lastReturnTime = 1;
            $this->assertSame(0, $client->cleanupIdleConnections());
            $next = $this->borrowConnection($client);
            $this->assertCount(1, $state->connections);
            $this->assertSame(0, $state->closed);
            unset($next);
        });
    }

    /** 生命周期回收应通过客户端接口生效。 */
    public function testLifetimeCleanupIsForwarded(): void
    {
        $this->runCoroutine(function (): void {
            [$client, $pool, $state] = $this->createClient(10, 0);
            $connection = $this->borrowConnection($client);
            unset($connection);
            $state->connections[0]->createTime = time() - 20;
            $this->assertSame(1, $client->cleanupIdleConnections());
            $this->assertSame(1, $state->closed);
        });
    }

    /** 清理不能关闭使用中的包装对象，归还后才开始空闲计时。 */
    public function testActiveConnectionSurvivesCleanupAndStartsIdleClockOnReturn(): void
    {
        $this->runCoroutine(function (): void {
            [$client, $pool, $state] = $this->createClient();
            $connection = $this->borrowConnection($client);
            $driver = $state->connections[0];
            $driver->createTime = 1;
            $this->assertSame(0, $driver->lastReturnTime);
            $this->assertSame(0, $client->cleanupIdleConnections());
            $this->assertSame(0, $state->closed);
            $this->assertSame(1, $client->poolStats()['active']);
            $before = time();
            unset($connection);
            $this->assertGreaterThanOrEqual($before, $driver->lastReturnTime);
            $this->assertSame(0, $client->cleanupIdleConnections());
            $this->assertSame(1, $client->poolStats()['idle']);
        });
    }

    /** 使用期间生命周期到期，不能中断业务，析构归还时才关闭。 */
    public function testLifetimeExpiresOnlyAfterActiveConnectionReturns(): void
    {
        $this->runCoroutine(function (): void {
            [$client, $pool, $state] = $this->createClient(10, 0);
            $connection = $this->borrowConnection($client);
            $state->connections[0]->createTime = time() - 20;
            $this->assertSame(0, $client->cleanupIdleConnections());
            $this->assertSame(0, $state->closed);
            unset($connection);
            $this->assertSame(1, $state->closed);
            $this->assertSame(0, $client->poolStats()['total']);
        });
    }

    /** 动态配置应直接更新现有池，不重建或重置计时。 */
    public function testChangingIdleTimePreservesCurrentPoolAndClock(): void
    {
        $this->runCoroutine(function (): void {
            [$client, $pool, $state] = $this->createClient(0, 100);
            $connection = $this->borrowConnection($client);
            unset($connection);
            $returnedAt = time() - 20;
            $state->connections[0]->lastReturnTime = $returnedAt;
            $client->setConnMaxIdleTime(10);
            $this->assertSame($pool, $this->readProperty($client, 'pool'));
            $this->assertSame($returnedAt, $state->connections[0]->lastReturnTime);
            $this->assertSame(1, $client->cleanupIdleConnections());
            $this->assertCount(1, $state->connections);
        });
    }

    /** 非正数配置应禁用空闲超时，重新启用时仍按原归还时间判断。 */
    public function testNonPositiveIdleTimeDisablesCleanup(): void
    {
        $this->runCoroutine(function (): void {
            foreach ([0, -1] as $value) {
                [$client, $pool, $state] = $this->createClient(0, $value);
                $connection = $this->borrowConnection($client);
                unset($connection);
                $state->connections[0]->lastReturnTime = 1;
                $this->assertSame(0, $client->cleanupIdleConnections());
                $client->setConnMaxIdleTime(10);
                $client->setConnMaxIdleTime($value);
                $this->assertSame(0, $client->cleanupIdleConnections());
                $client->setConnMaxIdleTime(10);
                $this->assertSame(1, $client->cleanupIdleConnections());
            }
        });
    }

    /** 其他配置触发池重建后，应保留已设置的空闲时间。 */
    public function testRebuiltPoolsKeepIdleTimeConfiguration(): void
    {
        $this->runCoroutine(function (): void {
            [$client, $pool] = $this->createClient();
            $client->setConnMaxIdleTime(5);
            foreach (['setMaxOpenConns' => 3, 'setMaxIdleConns' => 3, 'setConnMaxLifetime' => 20, 'setPoolWaitTimeout' => 0.5] as $method => $value) {
                $client->$method($value);
                $rebuilt = $this->readProperty($client, 'pool');
                $this->assertNotSame($pool, $rebuilt);
                $this->assertSame(5, $this->readProperty($rebuilt, 'maxIdleTime'));
                $this->assertSame(0, $client->cleanupIdleConnections());
                $pool = $rebuilt;
            }
        });
    }

    /** 未启用池时清理为空操作，之后启动池仍应采用明确的新配置。 */
    public function testCleanupWithoutPoolReturnsZero(): void
    {
        $client = (new ReflectionClass(CleanupClient::class))->newInstanceWithoutConstructor();
        $client->setConnMaxIdleTime(10);
        $this->assertSame(0, $client->cleanupIdleConnections());
        $this->assertSame([], $client->poolStats());
    }

    /** 定时器启动后应真正调用清理方法，完成后主动停止以允许 CLI 退出。 */
    public function testScheduledCleanupRemovesExpiredIdleConnection(): void
    {
        $this->runCoroutine(function (): void {
            [$client, $pool, $state] = $this->createClient();
            $connection = $this->borrowConnection($client);
            unset($connection);
            $state->connections[0]->lastReturnTime = time() - 20;
            $done = new Channel(1);
            $timerId = 0;
            $timerId = Swoole\Timer::tick(10, static function () use ($client, $done, &$timerId): void {
                Swoole\Timer::clear($timerId);
                try {
                    $done->push($client->cleanupIdleConnections());
                } catch (Throwable $exception) {
                    $done->push($exception);
                }
            });
            try {
                $this->assertSame(1, $done->pop(1));
                $this->assertSame(1, $state->closed);
                $this->assertSame(0, $client->poolStats()['total']);
            } finally {
                if (Swoole\Timer::exists($timerId)) {
                    Swoole\Timer::clear($timerId);
                }
            }
        });
    }

    /** Context 包装层应转发启动配置、动态配置和清理结果。 */
    public function testContextForwardsPoolCleanupInterfaces(): void
    {
        $this->runCoroutine(function (): void {
            [$client, $pool, $state] = $this->createClient();
            $context = (new ReflectionClass(\Haoa\MixDatabase\Context\Database::class))->newInstanceWithoutConstructor();
            $this->writeProperty($context, 'db', $client);
            $context->setConnMaxIdleTime(5);
            $this->assertSame($pool, $this->readProperty($client, 'pool'));
            $connection = $this->borrowConnection($client);
            unset($connection);
            $state->connections[0]->lastReturnTime = time() - 20;
            $this->assertSame(1, $context->cleanupIdleConnections());
            $this->assertSame(1, $state->closed);
            $context->startPool(2, 2, 0, 1, 7);
            $this->assertSame(7, $this->readProperty($this->readProperty($client, 'pool'), 'maxIdleTime'));
            $this->assertSame(0, $context->cleanupIdleConnections());
        });
    }

}
