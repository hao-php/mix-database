# 测试用例列表

| 测试文件 | 测试方法 | 描述 |
|---------|---------|------|
| **DriverTest.php** | | 驱动架构测试 |
| | testFactoryCreatesMysqlDriver | 工厂创建 MySQL 驱动 |
| | testFactoryCreatesPgsqlDriver | 工厂创建 PostgreSQL 驱动 |
| | testFactoryThrowsOnUnsupportedDsn | 不支持的 DSN 抛出异常 |
| | testFactoryRegisterCustomDriver | 注册自定义驱动 |
| | testMysqlBuildLimit | MySQL LIMIT 语法构建 |
| | testMysqlSharedLock | MySQL 共享锁语法 |
| | testMysqlForUpdate | MySQL 排它锁语法 |
| | testMysqlDisconnectMessages | MySQL 断连消息列表 |
| | testPgsqlBuildLimit | PostgreSQL LIMIT 语法构建 |
| | testPgsqlSharedLock | PostgreSQL 共享锁语法 |
| | testPgsqlForUpdate | PostgreSQL 排它锁语法 |
| | testPgsqlDisconnectMessages | PostgreSQL 断连消息列表 |
| | testMysqlGetQuoteChar | MySQL 获取引号字符 |
| | testMysqlQuoteTableNameDisabledByDefault | MySQL 表名引号默认关闭 |
| | testMysqlQuoteTableNameEnabled | MySQL 表名引号开启 |
| | testMysqlQuoteColumnNameDisabledByDefault | MySQL 列名引号默认关闭 |
| | testMysqlQuoteColumnNameEnabled | MySQL 列名引号开启 |
| | testPgsqlGetQuoteChar | PostgreSQL 获取引号字符 |
| | testPgsqlQuoteTableNameDisabledByDefault | PostgreSQL 表名引号默认关闭 |
| | testPgsqlQuoteTableNameEnabled | PostgreSQL 表名引号开启 |
| | testPgsqlQuoteColumnNameDisabledByDefault | PostgreSQL 列名引号默认关闭 |
| | testPgsqlQuoteColumnNameEnabled | PostgreSQL 列名引号开启 |
| | testMysqlBuildReplaceInsert | MySQL REPLACE INTO 语句构建 |
| | testMysqlBuildInsertOnDuplicateKey | MySQL ON DUPLICATE KEY 语句构建 |
| | testPgsqlBuildInsertOnConflictDoNothing | PostgreSQL ON CONFLICT DO NOTHING 构建 |
| | testPgsqlBuildInsertOnConflictDoUpdate | PostgreSQL ON CONFLICT DO UPDATE 构建 |
| | testPgsqlBuildInsertOnConflictMultipleColumns | PostgreSQL ON CONFLICT 多列构建 |
| | testPgsqlBuildInsertReturning | PostgreSQL INSERT RETURNING 构建 |
| **DatabaseTest.php** | | Database 入口类测试 |
| | testGetDriver | 获取驱动实例 |
| | testQuoteIdentifiersDisabledByDefault | 引号功能默认关闭 |
| | testQuoteIdentifiersEnabled | 引号功能开启 |
| | testCallUnsupportedMethodThrows | 调用不支持的方法抛出异常 |
| **QueryBuilderTest.php** | | 查询构建器测试 |
| | testSelectOrder | ORDER BY 排序查询 |
| | testSelectLimit | LIMIT 分页查询 |
| | testSelectGroupHaving | GROUP BY 和 HAVING 查询 |
| | testSelectJoin | JOIN 多表关联查询 |
| | testWhereAnd | AND 条件组合查询 |
| | testWhereOr | OR 条件组合查询 |
| | testWhereIn | IN 条件查询 |
| **CrudTest.php** | | CRUD 操作测试 |
| | testInsert | 单条插入 |
| | testInsertWithExpr | 使用 Expr 表达式插入 |
| | testInsertNonExistentTable | 插入不存在的表抛异常 |
| | testBatchInsert | 批量插入 |
| | testUpdateSingleField | 更新单个字段 |
| | testUpdateMultipleFields | 更新多个字段 |
| | testUpdateWithExpr | 使用 Expr 表达式更新 |
| | testDelete | 删除操作 |
| | testExec | 执行原生 SQL |
| | testRawQuery | 原生查询返回多行 |
| | testRawQueryFirst | 原生查询返回单行 |
| **MysqlIntegrationTest.php** | | MySQL 集成测试 |
| | testSelectFirst | 查询单行记录 |
| | testSelectValue | 查询单个字段值 |
| | testSelectWithFields | 指定字段查询 |
| | testReplaceInsert | REPLACE INTO 插入 |
| | testInsertOnDuplicateKey | ON DUPLICATE KEY 更新 |
| | testCallPgsqlMethodOnMysqlThrows | MySQL 调用 PgSQL 方法抛异常 |
| | testInsertSql | INSERT SQL 语句验证 |
| | testSharedLockSql | 共享锁 SQL 验证 |
| | testLockForUpdateSql | 排它锁 SQL 验证 |
| **TransTest.php** | | 事务测试 |
| | testLastInsertIdRowCount | 无连接池事务的 lastInsertId 和 rowCount |
| | testPoolLastInsertIdRowCount | 有连接池事务的 lastInsertId 和 rowCount |
| | testBindParams | 事务内绑定参数残留问题测试 |
| | testRollback | 事务回滚测试 |
| | testAuto | 自动事务测试 |
| | testCommitPersistsData | commit 后数据持久化验证 |
| | testRollbackDoesNotPersistData | rollback 后数据不持久化验证 |
| **PoolTest.php** | | 连接池测试 |
| | testMaxOpen | 连接池最大连接数限制 |
| | testQueryTriggersCoroutineSwitch | 查询触发协程切换 |
| | testWaitTimeout | 连接池等待超时 |
| **Context/ContextDatabaseTest.php** | | Context\Database 测试 |
| | testConstruct | Context\Database 构造函数 |
| | testBeginTransaction | 开启事务 |
| | testNestedTransaction | 嵌套事务复用 TransactionWrapper |
| | testNestedTransactionRealCommitOnOuter | 嵌套事务最外层 commit 提交 |
| | testGetContextTx | 获取运行上下文中的事务对象 |
| | testDelContextTx | 删除运行上下文中的事务对象 |
| | testQueryLogToSqlWithNamedBindings | 命名绑定参数转 SQL |
| | testQueryLogToSqlWithPositionalBindings | 位置占位符绑定转 SQL |
| | testQueryLogToSqlWithArrayBindings | 数组绑定(IN)展开 |
| | testQueryLogToSqlWithEmptyBindings | 空绑定时保持原 SQL |
| | testQueryLogToSqlWithoutBindings | 无 bindings 字段时保持原 SQL |
| | testInsertAndCommit | 事务提交后数据可查询 |
| | testInsertAndRollback | 事务回滚后数据不可查询 |
| | testNestedTransactionRealRollbackOnOuter | 嵌套事务最外层 rollback 回滚 |
| | testTransactionWithCallback | commit 时触发回调 |
| | testTransactionContextIsolation | 不同 Database 实例事务隔离 |
| | testGetContext | 获取运行上下文对象 |
| | testTransactionWrapperExecAndRaw | TransactionWrapper 中执行 SQL |
| | testTransactionWrapperDebug | TransactionWrapper 中使用 debug |
| **Context/ContextModelTest.php** | | Context\Model 测试 |
| | testWhereAndGetLastSql | where 条件与 getLastSql 验证 |
| | testInsert | insert 插入操作 |
| | testInsertGetId | insertGetId 返回自增主键 |
| | testBatchInsert | batchInsert 批量插入 |
| | testUpdate | update 单字段更新 |
| | testUpdateMultiple | updates 多字段更新 |
| | testDelete | delete 按主键删除 |
| | testGetAll | get 获取多行结果 |
| | testGetFirst | first 获取第一行 |
| | testCount | count 统计记录数 |
| | testGetValue | value 获取单字段值 |
| | testGetColumn | column 获取某列所有值 |
| | testComplexQuery | 复杂查询(多条件+排序+limit) |
| | testUpdateWithAutoTimestamp | update 自动维护 updated_at |
| | testInsertWithAutoTimestamp | insert 自动维护 created_at/updated_at |
| | testWhereException | where 非法格式抛异常 |
| | testUpdateWithoutWhereException | update 无 where 条件抛异常 |
| | testDeleteWithoutWhereException | delete 无 where 条件抛异常 |