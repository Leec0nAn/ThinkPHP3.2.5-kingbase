<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | KingbaseES database driver using PDO_KDB
// +----------------------------------------------------------------------

namespace Think\Db\Driver;

use PDO;
use Think\Db\Driver;
use Think\Db\Driver\Kingbase\DialectInterface;
use Think\Db\Driver\Kingbase\Metadata;

/**
 * KingbaseES driver.
 *
 * PDO_KDB only provides the transport. SQL generation, metadata and generated
 * key handling are delegated to the selected KES compatibility dialect.
 */
class Kingbase extends Driver
{
    /** @var DialectInterface */
    protected $dialect;

    /** @var Metadata */
    protected $metadata;

    protected $mode = '';
    protected $autoDetectMode = false;
    protected $schema = '';
    protected $schemaConfigured = false;
    protected $initializedLinks = array();
    protected $pendingSequence = null;
    protected $pendingLobFields = array();
    protected $generatedKeyContextPrepared = false;
    protected $generatedKeyExpected = false;

    /**
     * 初始化 Kingbase 驱动，选择三模式方言并准备可选的 Schema 元数据读取器。
     *
     * @param array|string $config TP3.2 数据库连接配置
     * @throws \InvalidArgumentException 兼容模式或 Schema 配置无效时抛出
     */
    public function __construct($config = '')
    {
        $configArray = is_array($config) ? $config : array();
        $configuredMode = isset($configArray['kdb_mode'])
            ? $configArray['kdb_mode']
            : C('DB_KDB_MODE');
        $modeValue = strtolower(trim((string) $configuredMode));
        $this->autoDetectMode = '' === $modeValue || 'auto' === $modeValue;
        $this->mode = $this->normalizeMode($configuredMode);
        if (!$this->autoDetectMode && '' === $this->mode) {
            throw new \InvalidArgumentException(
                'DB_KDB_MODE must be auto, mysql, oracle or sqlserver'
            );
        }

        $schema = isset($configArray['schema']) ? $configArray['schema'] : C('DB_SCHEMA');
        if (!empty($schema)) {
            $this->schema = $this->validateObjectName($schema, 'schema');
            $this->schemaConfigured = true;
        }

        parent::__construct($config);

        if ('' !== $this->mode) {
            $this->initializeDialect($this->mode);
        } else {
            // SQL生成前必须先确定方言，因此自动模式在驱动构造阶段建立一次连接。
            // 后续查询和重连仍会校验每个物理连接的实际database_mode。
            $this->connect();
        }
    }

    /**
     * 根据已配置或自动探测到的模式创建方言及元数据读取器。
     *
     * @param string $mode mysql、oracle或sqlserver
     * @return void
     * @throws \UnexpectedValueException 服务端返回未知模式时抛出
     */
    private function initializeDialect($mode)
    {
        $mode = $this->normalizeMode($mode);
        $dialects = array(
            'mysql'     => 'Think\\Db\\Driver\\Kingbase\\Mysql',
            'oracle'    => 'Think\\Db\\Driver\\Kingbase\\Oracle',
            'sqlserver' => 'Think\\Db\\Driver\\Kingbase\\Sqlsrv',
        );
        if (!isset($dialects[$mode])) {
            throw new \UnexpectedValueException('Unsupported KES database_mode: ' . $mode);
        }

        $class = $dialects[$mode];
        $this->mode = $mode;
        $this->dialect = new $class();
        $this->selectSql = $this->dialect->getSelectSql();
        if ($this->schemaConfigured) {
            $this->metadata = new Metadata($this->mode, $this->schema);
        }
    }

    /**
     * 根据主机、端口和数据库名生成 PDO_KDB DSN。
     *
     * @param array $config 数据库连接配置
     * @return string
     * @throws \InvalidArgumentException 缺少主机或数据库名时抛出
     */
    protected function parseDsn($config)
    {
        if (empty($config['hostname']) || empty($config['database'])) {
            throw new \InvalidArgumentException('KDB hostname and database are required');
        }
        $dsn = 'kdb:host=' . $config['hostname'] . ';dbname=' . $config['database'];
        if (!empty($config['hostport'])) {
            $dsn .= ';port=' . $config['hostport'];
        }
        return $dsn;
    }

    /**
     * 建立 PDO_KDB 连接，校验服务端兼容模式，并初始化或发现会话 Schema。
     *
     * 每个物理连接只初始化一次；连接失败时生成不包含口令的诊断信息。
     *
     * @param array|string $config         当前连接配置，为空时使用驱动默认配置
     * @param integer      $linkNum        连接编号
     * @param mixed        $autoConnection 失败后的备用连接配置
     * @return PDO
     * @throws \RuntimeException PDO 连接失败且没有可用备用连接时抛出
     * @throws \UnexpectedValueException 驱动、兼容模式或 Schema 不匹配时抛出
     */
    public function connect($config = '', $linkNum = 0, $autoConnection = false)
    {
        $newConnection = !isset($this->linkID[$linkNum]);
        $connectionStarted = microtime(true);
        if (!isset($this->linkID[$linkNum])) {
            if (empty($config)) {
                $config = $this->config;
            }

            try {
                if (empty($config['dsn'])) {
                    $config['dsn'] = $this->parseDsn($config);
                }
                if (version_compare(PHP_VERSION, '5.3.6', '<=')) {
                    $this->options[PDO::ATTR_EMULATE_PREPARES] = false;
                }
                $this->linkID[$linkNum] = new PDO(
                    $config['dsn'],
                    $config['username'],
                    $config['password'],
                    $this->options
                );
            } catch (\PDOException $e) {
                $message = $this->formatConnectionFailure($config, $e);
                if ($autoConnection) {
                    trace($message, '', 'ERR');
                    return $this->connect($autoConnection, $linkNum);
                }
                $code = is_numeric($e->getCode()) ? (int) $e->getCode() : 0;
                throw new \RuntimeException($message, $code, $e);
            }
        }

        $pdo = $this->linkID[$linkNum];
        $hash = spl_object_hash($pdo);
        if (!isset($this->initializedLinks[$hash])) {
            if ('kdb' !== strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME))) {
                throw new \UnexpectedValueException('Think\\Db\\Driver\\Kingbase requires PDO_KDB');
            }

            $serverMode = $this->normalizeMode((string) $pdo->query(
                "SELECT current_setting('database_mode', true)"
            )->fetchColumn());
            if ('' === $serverMode) {
                throw new \UnexpectedValueException(
                    'Unsupported or empty KES database_mode returned by server'
                );
            }
            if (null === $this->dialect) {
                $this->initializeDialect($serverMode);
            } elseif ($serverMode !== $this->dialect->getMode()) {
                throw new \UnexpectedValueException(
                    'KES compatibility mode mismatch: configured ' . $this->mode . ', server ' . $serverMode
                );
            }

            if ($this->schemaConfigured) {
                $quotedSchema = '"' . str_replace('"', '""', $this->schema) . '"';
                $searchPath = 'public' === strtolower($this->schema)
                    ? $quotedSchema
                    : $quotedSchema . ', public';
                $pdo->exec('SET search_path TO ' . $searchPath);
            }

            $currentSchema = $this->validateObjectName(
                (string) $pdo->query('SELECT current_schema()')->fetchColumn(),
                'current schema'
            );
            if ($this->schemaConfigured && 0 !== strcasecmp($currentSchema, $this->schema)) {
                throw new \UnexpectedValueException(
                    'KES schema initialization failed: expected ' . $this->schema . ', got ' . $currentSchema
                );
            }
            if (!$this->schemaConfigured) {
                if ('' === $this->schema) {
                    $this->schema = $currentSchema;
                    $this->metadata = new Metadata($this->mode, $this->schema);
                } elseif (0 !== strcasecmp($currentSchema, $this->schema)) {
                    throw new \UnexpectedValueException(
                        'KES default schema mismatch between connections: expected '
                        . $this->schema . ', got ' . $currentSchema
                    );
                }
            }
            $this->initializedLinks[$hash] = true;
        }
        if ($newConnection && function_exists('kdb_test_log')) {
            kdb_test_log('CONNECT', 'PDO_KDB connection initialized', array(
                'mode' => $this->mode,
                'host' => isset($config['hostname']) ? $config['hostname'] : $this->config['hostname'],
                'port' => isset($config['hostport']) ? $config['hostport'] : $this->config['hostport'],
                'database' => isset($config['database']) ? $config['database'] : $this->config['database'],
                'schema' => $this->schema,
                'runtime_ms' => (int) round((microtime(true) - $connectionStarted) * 1000),
            ));
        }
        return $pdo;
    }

    /**
     * 返回当前驱动配置的 KingbaseES 兼容模式。
     *
     * @return string
     */
    public function getKdbMode()
    {
        return $this->mode;
    }

    /**
     * 返回已配置或从当前 PDO_KDB 会话发现的 Schema。
     *
     * @return string
     */
    public function getKdbSchema()
    {
        return $this->schema;
    }

    /**
     * 读取指定表的 TP3.2 字段元数据。
     *
     * @param string $tableName 表名或 Schema 限定表名
     * @return array
     */
    public function getFields($tableName)
    {
        $this->ensureMetadata();
        return $this->metadata->getFields($this, $tableName);
    }

    /**
     * 返回当前 Schema 中的普通表列表。
     *
     * @param string $dbName TP3.2 兼容参数；实际查询范围由当前 Schema 决定
     * @return array
     */
    public function getTables($dbName = '')
    {
        $this->ensureMetadata();
        return $this->metadata->getTables($this);
    }

    /**
     * 委托当前方言引用字段、表名或别名。
     *
     * @param mixed   $key    标识符或表达式
     * @param boolean $strict 是否启用严格标识符检查
     * @return mixed
     */
    public function parseKey($key, $strict = false)
    {
        return $this->dialect->quoteIdentifier($key, $strict);
    }

    /**
     * 将 TP3.2 分页选项转换为当前兼容模式的分页子句。
     *
     * @param mixed $limit 分页参数
     * @return string
     */
    public function parseLimit($limit)
    {
        return $this->dialect->parseLimit($limit);
    }

    /**
     * SQL Server原Driver使用ROW_NUMBER分页，即使业务未指定排序也需要一个ORDER BY。
     * 其他兼容模式继续使用原Driver的排序解析逻辑。
     *
     * @param mixed $order 排序配置
     * @return string
     */
    protected function parseOrder($order)
    {
        if ('sqlserver' === $this->mode && empty($order)) {
            return ' ORDER BY ' . $this->dialect->parseRand();
        }

        return parent::parseOrder($order);
    }

    /**
     * 将锁定选项转换为当前兼容模式的行锁子句。
     *
     * @param boolean $lock 是否锁定查询结果
     * @return string
     */
    protected function parseLock($lock = false)
    {
        return $this->dialect->parseLock($lock);
    }

    /**
     * 返回当前兼容模式的随机排序函数或表达式。
     *
     * @return string
     */
    protected function parseRand()
    {
        return $this->dialect->parseRand();
    }

    /**
     * 仅在 MySQL 兼容模式保留 TP3.2 的索引强制子句。
     *
     * @param mixed $index 索引配置
     * @return string
     */
    protected function parseForce($index)
    {
        return 'mysql' === $this->mode ? parent::parseForce($index) : '';
    }

    /**
     * 转义拼接到元数据 SQL 字符串字面量中的单引号。
     *
     * @param string $str 原始字符串
     * @return string
     */
    public function escapeString($str)
    {
        return str_replace("'", "''", $str);
    }

    /**
     * 执行查询，并对 MySQL 兼容函数的命名占位符补充必要的 TEXT 类型转换。
     *
     * PDO_KDB 有时无法推断多态函数参数的占位符类型，因此先规范化 SQL，再交给
     * TP3.2 公共查询流程；测试日志函数存在时记录 SQL、返回行数和耗时。
     *
     * @param string  $str      SQL 语句
     * @param boolean $fetchSql 是否只返回构造后的 SQL
     * @param boolean $master   是否强制使用主连接
     * @return mixed
     */
    public function query($str, $fetchSql = false, $master = false)
    {
        $str = $this->normalizePreparedSql($str);
        $started = microtime(true);
        $result = parent::query($str, $fetchSql, $master);
        if (function_exists('kdb_test_log_sql')) {
            kdb_test_log_sql($fetchSql ? 'BUILD' : 'SQL', $str, array(
                'rows' => is_array($result) ? count($result) : null,
                'runtime_ms' => (int) round((microtime(true) - $started) * 1000),
            ));
        }
        return $result;
    }

    /**
     * 插入单行数据，并在进入 TP3.2 公共 INSERT 构造流程前完成三模式适配。
     *
     * Oracle 模式按配置注入序列 NEXTVAL，MySQL 模式处理二进制字面量，所有模式
     * 自动识别 LOB 字段；REPLACE 沿用原 Driver 的生成规则。
     *
     * @param array   $data    待写入数据
     * @param array   $options TP3.2 查询选项
     * @param boolean $replace 是否生成 REPLACE
     * @return mixed
     */
    public function insert($data, $options = array(), $replace = false)
    {
        $this->resetGeneratedKeyContext();
        if ('oracle' === $this->mode && empty($options['fetch_sql'])) {
            $data = $this->prepareOracleInsert($data, $options);
        } elseif ('mysql' === $this->mode && empty($options['fetch_sql'])) {
            $data = $this->prepareMysqlBinaryLiterals($data, $options);
        }
        if (empty($options['fetch_sql'])) {
            $this->prepareGeneratedKeyContext($data, $options);
        }
        $this->pendingLobFields = $this->detectLobFields($data, $options);
        try {
            return parent::insert($data, $options, $replace);
        } finally {
            $this->pendingLobFields = array();
        }
    }

    /**
     * 使用标准 VALUES 语法批量插入多行数据。
     *
     * PDO_KDB在KingbaseES Oracle和SQL Server模式下无法稳定推断UNION ALL中的
     * 占位符类型，因此三种模式统一生成INSERT ... VALUES (...), (...)；同时执行
     * 序列、二进制和LOB适配。
     *
     * @param array   $dataSet 多行数据
     * @param array   $options TP3.2 查询选项
     * @param boolean $replace 是否生成 REPLACE
     * @return mixed
     */
    public function insertAll($dataSet, $options = array(), $replace = false)
    {
        $this->resetGeneratedKeyContext();
        if (empty($dataSet)) {
            return false;
        }
        if ('oracle' === $this->mode && empty($options['fetch_sql'])) {
            foreach ($dataSet as $index => $data) {
                $dataSet[$index] = $this->prepareOracleInsert($data, $options);
            }
        } elseif ('mysql' === $this->mode && empty($options['fetch_sql'])) {
            foreach ($dataSet as $index => $data) {
                $dataSet[$index] = $this->prepareMysqlBinaryLiterals($data, $options);
            }
        }

        $this->pendingLobFields = $this->detectDataSetLobFields($dataSet, $options);
        try {
            $values      = array();
            $this->model = isset($options['model']) ? $options['model'] : '_think_';
            $this->parseBind(!empty($options['bind']) ? $options['bind'] : array());
            $fields = array_map(array($this, 'parseKey'), array_keys($dataSet[0]));
            foreach ($dataSet as $data) {
                $row = array();
                foreach ($data as $key => $value) {
                    if (is_array($value) && isset($value[0]) && 'exp' === strtolower($value[0])) {
                        $row[] = $value[1];
                    } elseif (is_null($value)) {
                        $row[] = 'NULL';
                    } elseif (is_scalar($value)) {
                        if (is_string($value) && 0 === strpos($value, ':') && isset($this->bind[$value])) {
                            $row[] = $value;
                        } else {
                            $name  = $key . '_' . count($this->bind);
                            $row[] = ':' . $name;
                            $this->bindParam($name, $value);
                        }
                    }
                }
                $values[] = '(' . implode(',', $row) . ')';
            }

            // 原 Mysql Driver 支持批量 REPLACE；Oracle 与 Sqlsrv 继承的基础 Driver
            // 对批量写入始终生成 INSERT。适配层保持这一原有差异。
            $verb = 'mysql' === $this->mode
                && (true === $replace || (is_numeric($replace) && $replace > 0))
                ? 'REPLACE'
                : 'INSERT';
            $sql  = $verb . ' INTO ' . $this->parseTable($options['table'])
                . ' (' . implode(',', $fields) . ') VALUES ' . implode(',', $values);
            $sql .= $this->parseComment(!empty($options['comment']) ? $options['comment'] : '');
            return $this->execute($sql, !empty($options['fetch_sql']));
        } finally {
            $this->pendingLobFields = array();
        }
    }

    /**
     * 预处理并执行写 SQL，避免 TP3.2 父驱动无条件调用 lastInsertId()。
     *
     * 本方法负责参数绑定、影响行数、三模式插入主键回读和详细测试日志；fetchSql
     * 为真时仅返回插值后的调试 SQL，不访问数据库。
     *
     * @param string  $str      SQL 语句
     * @param boolean $fetchSql 是否只返回构造后的 SQL
     * @return mixed
     */
    public function execute($str, $fetchSql = false)
    {
        $started = microtime(true);
        $str = $this->normalizePreparedSql($str);
        $this->initConnect(true);
        if (!$this->_linkID) {
            return false;
        }

        $this->queryStr = $this->interpolateDebugSql($str, $this->bind);
        if ($fetchSql) {
            $this->resetGeneratedKeyContext();
            if (function_exists('kdb_test_log_sql')) {
                kdb_test_log_sql('BUILD', $str, array('executed' => 'no'));
            }
            return $this->queryStr;
        }
        if (!empty($this->PDOStatement)) {
            $this->free();
        }

        $this->executeTimes++;
        N('db_write', 1);
        $this->debug(true);
        $this->PDOStatement = $this->_linkID->prepare($str);
        if (false === $this->PDOStatement) {
            $this->bind = array();
            $this->resetGeneratedKeyContext();
            $this->error();
            return false;
        }

        foreach ($this->bind as $key => $value) {
            if (is_array($value)) {
                $this->PDOStatement->bindValue($key, $value[0], $value[1]);
            } else {
                $this->PDOStatement->bindValue($key, $value);
            }
        }
        $this->bind = array();

        try {
            $result = $this->PDOStatement->execute();
            $this->debug(false);
            if (false === $result) {
                $this->resetGeneratedKeyContext();
                $this->error();
                return false;
            }
            $this->numRows = $this->PDOStatement->rowCount();
            if (preg_match('/^\s*INSERT\s+INTO\s+/i', $str)) {
                $this->captureLastInsertId($str);
            } else {
                $this->resetGeneratedKeyContext();
            }
            $this->pendingSequence = null;
            if (function_exists('kdb_test_log_sql')) {
                kdb_test_log_sql('SQL', $str, array(
                    'affected_rows' => $this->numRows,
                    'runtime_ms' => (int) round((microtime(true) - $started) * 1000),
                ));
            }
            return $this->numRows;
        } catch (\PDOException $exception) {
            $this->resetGeneratedKeyContext();
            if (function_exists('kdb_test_log_sql')) {
                kdb_test_log_sql('FAIL', $str, array(
                    'error' => $exception->getMessage(),
                    'runtime_ms' => (int) round((microtime(true) - $started) * 1000),
                ));
            }
            $this->error();
            return false;
        }
    }

    /**
     * 开启事务并维护 TP3.2 的嵌套事务层级。
     *
     * PDO_KDB 在 SQL Server 模式调用 PDO::beginTransaction() 时会生成不受支持的
     * 裸 BEGIN，因此该模式显式执行 BEGIN TRANSACTION；其他模式沿用 PDO 事务接口。
     *
     * @return boolean|null 成功时返回 null，失败时返回 false
     */
    public function startTrans()
    {
        $this->initConnect(true);
        if (!$this->_linkID) {
            return false;
        }
        try {
            if (0 === $this->transTimes) {
                if ('sqlserver' === $this->mode) {
                    $this->_linkID->exec('BEGIN TRANSACTION');
                } else {
                    $this->_linkID->beginTransaction();
                }
                $this->transPDO = $this->_linkID;
            }
            $this->transTimes++;
            return null;
        } catch (\PDOException $exception) {
            $this->error = $exception->getMessage();
            return false;
        }
    }

    /**
     * 提交最外层事务；嵌套调用只递减 TP3.2 的逻辑事务层级。
     *
     * SQL Server 模式显式执行 COMMIT，其他模式调用 PDO::commit()。
     *
     * @return boolean
     */
    public function commit()
    {
        if (1 === $this->transTimes) {
            try {
                $result = 'sqlserver' === $this->mode
                    ? false !== $this->_linkID->exec('COMMIT')
                    : $this->_linkID->commit();
                $this->transTimes = 0;
                $this->transPDO   = null;
                if (!$result) {
                    return false;
                }
            } catch (\PDOException $exception) {
                $this->error = $exception->getMessage();
                return false;
            }
        } else {
            $this->transTimes = $this->transTimes <= 0 ? 0 : $this->transTimes - 1;
        }
        return true;
    }

    /**
     * 回滚当前物理事务并清空 TP3.2 的逻辑事务层级。
     *
     * @return boolean
     */
    public function rollback()
    {
        if ($this->transTimes > 0) {
            try {
                $result = 'sqlserver' === $this->mode
                    ? false !== $this->_linkID->exec('ROLLBACK')
                    : $this->_linkID->rollBack();
                $this->transTimes = 0;
                $this->transPDO   = null;
                if (!$result) {
                    return false;
                }
            } catch (\PDOException $exception) {
                $this->error = $exception->getMessage();
                return false;
            }
        }
        return true;
    }

    /**
     * 按原Driver行为更新数据，同时保留PDO_KDB的LOB绑定处理。
     *
     * MySQL和Oracle沿用基础Driver；SQL Server复现原Sqlsrv Driver忽略
     * ORDER/LIMIT并保留LOCK、COMMENT的行为。
     *
     * @param array $data    更新数据
     * @param array $options TP3.2 查询选项
     * @return mixed
     */
    public function update($data, $options)
    {
        $this->pendingLobFields = $this->detectLobFields($data, $options);
        try {
            if ('sqlserver' !== $this->mode) {
                return parent::update($data, $options);
            }
            $this->model = $options['model'];
            $this->parseBind(!empty($options['bind']) ? $options['bind'] : array());
            $sql = 'UPDATE ' . $this->parseTable($options['table'])
                . $this->parseSet($data)
                . $this->parseWhere(!empty($options['where']) ? $options['where'] : '')
                . $this->parseLock(isset($options['lock']) ? $options['lock'] : false)
                . $this->parseComment(!empty($options['comment']) ? $options['comment'] : '');
            return $this->execute($sql, !empty($options['fetch_sql']));
        } finally {
            $this->pendingLobFields = array();
        }
    }

    /**
     * 按原Driver行为删除数据。
     *
     * MySQL和Oracle沿用基础Driver；SQL Server复现原Sqlsrv Driver忽略
     * ORDER/LIMIT并保留LOCK、COMMENT的行为。
     *
     * @param array $options TP3.2 查询选项
     * @return mixed
     */
    public function delete($options = array())
    {
        if ('sqlserver' !== $this->mode) {
            return parent::delete($options);
        }
        $this->model = $options['model'];
        $this->parseBind(!empty($options['bind']) ? $options['bind'] : array());
        $sql = 'DELETE FROM ' . $this->parseTable($options['table'])
            . $this->parseWhere(!empty($options['where']) ? $options['where'] : '')
            . $this->parseLock(isset($options['lock']) ? $options['lock'] : false)
            . $this->parseComment(!empty($options['comment']) ? $options['comment'] : '');
        return $this->execute($sql, !empty($options['fetch_sql']));
    }

    /**
     * 关闭全部连接并清理事务和 Schema 推断状态。
     *
     * @return void
     */
    public function close()
    {
        $this->_linkID          = null;
        $this->linkID           = array();
        $this->initializedLinks = array();
        $this->transPDO         = null;
        $this->transTimes       = 0;
        if (!$this->schemaConfigured) {
            $this->schema   = '';
            $this->metadata = null;
        }
    }

    /**
     * 确保当前连接已解析 Schema，并且元数据读取器可以使用。
     *
     * @return void
     * @throws \UnexpectedValueException 无法解析当前 Schema 时抛出
     */
    private function ensureMetadata()
    {
        if (!$this->metadata) {
            $this->initConnect(true);
        }
        if (!$this->metadata || '' === $this->schema) {
            throw new \UnexpectedValueException('Unable to resolve the current KES schema');
        }
    }

    /**
     * 将 PDO 连接异常转换为包含主机、端口和数据库信息的单行诊断文本。
     *
     * @param array         $config    连接配置
     * @param \PDOException $exception 原始 PDO 异常
     * @return string
     */
    private function formatConnectionFailure($config, \PDOException $exception)
    {
        $details = array();
        $fields = array(
            'hostname' => 'host',
            'hostport' => 'port',
            'database' => 'database',
        );
        foreach ($fields as $key => $label) {
            if (isset($config[$key]) && '' !== (string) $config[$key]) {
                $details[] = $label . '=' . $this->sanitizeDiagnosticValue($config[$key]);
            }
        }
        if (empty($details)) {
            $details[] = 'connection parameters unavailable';
        }

        return 'KES connection failed (' . implode(', ', $details) . '): '
            . $this->sanitizeDiagnosticValue($exception->getMessage());
    }

    /**
     * 清理诊断值中的换行和制表符，并限制最大长度。
     *
     * @param mixed $value 原始诊断值
     * @return string
     */
    private function sanitizeDiagnosticValue($value)
    {
        $value = trim(str_replace(array("\r", "\n", "\t"), ' ', (string) $value));
        if (strlen($value) > 500) {
            return substr($value, 0, 497) . '...';
        }
        return $value;
    }

    /**
     * 为 Oracle INSERT 解析主键序列，并在缺少主键值时注入 NEXTVAL 表达式。
     *
     * @param array $data    待写入数据
     * @param array $options TP3.2 查询选项
     * @return array
     */
    private function prepareOracleInsert($data, $options)
    {
        if (empty($options['table'])) {
            return $data;
        }
        $fields   = $this->getFields($options['table']);
        $sequence = $this->configuredSequence($options['table']);
        $primary  = null;
        foreach ($fields as $field) {
            if (!empty($field['primary'])) {
                $primary = $field['name'];
                if (empty($sequence) && !empty($field['sequence'])) {
                    $sequence = $field['sequence'];
                }
                break;
            }
        }

        if (empty($sequence)) {
            $sequence = $this->conventionalSequence($options['table']);
        }
        if (!empty($sequence) && $primary && !array_key_exists($primary, $data)) {
            $this->pendingSequence = $sequence;
            $data[$primary] = array('exp', $this->quoteOracleObject($sequence) . '.NEXTVAL');
        }
        return $data;
    }

    /**
     * 对 MySQL 模式写入 BLOB 的十六进制表达式补充显式 BLOB 类型转换。
     *
     * @param array $data    待写入数据
     * @param array $options TP3.2 查询选项
     * @return array
     */
    private function prepareMysqlBinaryLiterals($data, $options)
    {
        $literalFields = array();
        foreach ($data as $field => $value) {
            if (is_array($value) && isset($value[0], $value[1])
                && 'exp' === strtolower($value[0])
                && preg_match('/^X\'[0-9A-F]+\'$/i', trim($value[1]))) {
                $literalFields[] = $field;
            }
        }
        if (empty($literalFields) || empty($options['table'])) {
            return $data;
        }

        $fields = $this->getFields($options['table']);
        foreach ($literalFields as $field) {
            $key = strtolower($field);
            if (isset($fields[$key]) && false !== strpos(strtolower($fields[$key]['type']), 'blob')) {
                $data[$field][1] = 'CAST(' . trim($data[$field][1]) . ' AS BLOB)';
            }
        }
        return $data;
    }

    /**
     * 识别需要通过 PDO::PARAM_LOB 绑定的二进制字段。
     *
     * PDO_KDB 在部分 BLOB 路径中会把普通字符串绑定当作 NUL 结尾文本；将目标列为
     * blob、binary、bytea、raw 或 image 的字符串值标记为 LOB，确保零字节及大对象完整传输。
     *
     * @param array $data    待绑定数据
     * @param array $options TP3.2 查询选项
     * @return array 字段名到启用状态的映射
     */
    private function detectLobFields($data, $options)
    {
        if (empty($options['table']) || !is_array($data)) {
            return array();
        }
        $fields = $this->getFields($options['table']);
        $lobFields = array();
        foreach ($data as $field => $value) {
            if (!is_string($value)) {
                continue;
            }
            $key = strtolower(str_replace(array('`', '"', '[', ']'), '', $field));
            if (!isset($fields[$key])) {
                continue;
            }
            $type = strtolower($fields[$key]['type']);
            if (preg_match('/blob|binary|bytea|\braw\b|\bimage\b/', $type)) {
                $lobFields[$key] = true;
            }
        }
        return $lobFields;
    }

    /**
     * 合并批量数据中所有需要使用 PDO::PARAM_LOB 绑定的字段。
     *
     * @param array $dataSet 多行数据
     * @param array $options TP3.2 查询选项
     * @return array
     */
    private function detectDataSetLobFields($dataSet, $options)
    {
        $lobFields = array();
        foreach ($dataSet as $data) {
            $lobFields = array_merge($lobFields, $this->detectLobFields($data, $options));
        }
        return $lobFields;
    }

    /**
     * 记录命名参数；二进制字段强制使用 PDO::PARAM_LOB，其余沿用父类规则。
     *
     * @param string $name  参数名
     * @param mixed  $value 参数值
     * @return void
     */
    protected function bindParam($name, $value)
    {
        foreach ($this->pendingLobFields as $field => $enabled) {
            if ($name === $field || 0 === strpos($name, $field . '_')) {
                $this->bind[':' . $name] = array($value, PDO::PARAM_LOB);
                return;
            }
        }
        parent::bindParam($name, $value);
    }

    /**
     * 按当前兼容模式读取最近一次 INSERT 生成的自增、序列或 Identity 主键。
     *
     * @param string $sql 已执行的 INSERT SQL，用于识别 Oracle NEXTVAL
     * @return void
     */
    private function captureLastInsertId($sql)
    {
        $this->lastInsID = null;
        if (!$this->generatedKeyContextPrepared || !$this->generatedKeyExpected) {
            $this->generatedKeyContextPrepared = false;
            $this->generatedKeyExpected = false;
            return;
        }
        if ('mysql' === $this->mode) {
            // PDO_KDB当前未实现可靠的lastInsertId()，直接使用MySQL兼容函数，
            // 并确保查询与INSERT位于同一个物理连接和会话中。
            $this->lastInsID = $this->_linkID->query('SELECT LAST_INSERT_ID()')->fetchColumn();
            $this->generatedKeyContextPrepared = false;
            $this->generatedKeyExpected = false;
            return;
        }
        if ('oracle' === $this->mode) {
            $sequence = $this->pendingSequence;
            if (empty($sequence)) {
                $sequence = $this->oracleSequenceExpression($sql);
            }
            if (!empty($sequence)) {
                $this->lastInsID = $this->_linkID->query(
                    'SELECT ' . $this->quoteOracleObject($sequence) . '.CURRVAL FROM dual'
                )->fetchColumn();
            }
            $this->generatedKeyContextPrepared = false;
            $this->generatedKeyExpected = false;
            return;
        }

        foreach (array('SELECT SCOPE_IDENTITY() AS id', 'SELECT @@IDENTITY AS id') as $identitySql) {
            try {
                $id = $this->_linkID->query($identitySql)->fetchColumn();
                if (false !== $id && null !== $id && '' !== $id) {
                    $this->lastInsID = $id;
                    $this->generatedKeyContextPrepared = false;
                    $this->generatedKeyExpected = false;
                    return;
                }
            } catch (\PDOException $exception) {
                // Try the documented fallback on the same connection.
            }
        }
        $this->generatedKeyContextPrepared = false;
        $this->generatedKeyExpected = false;
    }

    /**
     * 清除本次 INSERT 的生成主键判断，防止历史会话值泄漏到后续写入。
     *
     * @return void
     */
    private function resetGeneratedKeyContext()
    {
        $this->generatedKeyContextPrepared = false;
        $this->generatedKeyExpected = false;
        $this->pendingSequence = null;
        $this->lastInsID = null;
    }

    /**
     * 根据字段元数据和本次写入数据判断数据库是否应生成单一主键。
     *
     * 只有单行 INSERT 经过本方法确认后，captureLastInsertId() 才允许读取
     * LAST_INSERT_ID()、CURRVAL 或 Identity。显式普通主键、复合主键、批量
     * 写入和原生 execute() 均不会建立该上下文。
     *
     * @param array $data    本次写入数据
     * @param array $options TP3.2 查询选项
     * @return void
     */
    private function prepareGeneratedKeyContext($data, $options)
    {
        $this->generatedKeyContextPrepared = true;
        $this->generatedKeyExpected = false;
        $this->lastInsID = null;

        if ('oracle' === $this->mode) {
            $this->generatedKeyExpected = !empty($this->pendingSequence)
                || $this->dataUsesOracleNextval($data);
            return;
        }

        if (empty($options['table'])) {
            return;
        }
        $fields = $this->getFields($options['table']);
        $primaryFields = array();
        $generatedField = null;
        foreach ($fields as $field) {
            if (!empty($field['primary'])) {
                $primaryFields[] = $field['name'];
                if (!empty($field['autoinc'])) {
                    $generatedField = $field['name'];
                }
            }
        }
        if (1 !== count($primaryFields) || empty($generatedField)) {
            return;
        }

        list($exists, $value) = $this->findInsertField($data, $generatedField);
        $this->generatedKeyExpected = !$exists || $this->isGeneratedFieldValue($value);
    }

    /**
     * 以大小写不敏感方式在 INSERT 数据中查找字段。
     *
     * @param array  $data  写入数据
     * @param string $field 字段名
     * @return array [是否存在, 字段值]
     */
    private function findInsertField($data, $field)
    {
        foreach ((array) $data as $key => $value) {
            $name = strtolower(str_replace(array('`', '"', '[', ']'), '', (string) $key));
            if (false !== strpos($name, '.')) {
                $parts = explode('.', $name);
                $name = end($parts);
            }
            if (0 === strcasecmp($name, $field)) {
                return array(true, $value);
            }
        }
        return array(false, null);
    }

    /**
     * 判断显式字段值是否仍要求数据库生成主键。
     *
     * @param mixed $value 字段值
     * @return boolean
     */
    private function isGeneratedFieldValue($value)
    {
        if (null === $value) {
            return true;
        }
        return is_array($value) && isset($value[0], $value[1])
            && 'exp' === strtolower((string) $value[0])
            && 'default' === strtolower(trim((string) $value[1]));
    }

    /**
     * 判断显式 Oracle 表达式中是否使用了真实 NEXTVAL。
     *
     * @param array $data 写入数据
     * @return boolean
     */
    private function dataUsesOracleNextval($data)
    {
        foreach ((array) $data as $value) {
            if (is_array($value) && isset($value[0], $value[1])
                && 'exp' === strtolower((string) $value[0])
                && null !== $this->oracleSequenceExpression('SELECT ' . $value[1])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 从实际执行的 NEXTVAL 表达式提取序列名，支持带引号和 Schema 限定的对象。
     *
     * 忽略字符串及注释中的 NEXTVAL 文本；普通显式主键不触发 CURRVAL 回读。
     *
     * @param string $sql 实际执行的 INSERT SQL
     * @return string|null
     */
    private function oracleSequenceExpression($sql)
    {
        $identifier = '(?:"[A-Za-z_][A-Za-z0-9_$#]*"|[A-Za-z_][A-Za-z0-9_$#]*)';
        $code = $this->maskSqlQuotedText($sql, false);
        if (preg_match('/(?<![A-Za-z0-9_$#"])((' . $identifier . ')(?:\s*\.\s*'
            . $identifier . ')?)\s*\.\s*NEXTVAL\b/i', $code, $match)) {
            return preg_replace('/["\s]/', '', $match[1]);
        }
        return null;
    }

    /**
     * 从表级映射或全局配置中解析显式配置的 Oracle 序列名。
     *
     * @param string $tableName 表名
     * @return string|null
     */
    private function configuredSequence($tableName)
    {
        $table = $this->unqualifiedName($tableName);
        $map   = C('DB_KDB_SEQUENCES');
        if (is_array($map)) {
            foreach ($map as $mappedTable => $sequence) {
                if (0 === strcasecmp($this->unqualifiedName($mappedTable), $table)) {
                    return $this->validateQualifiedObjectName($sequence, 'sequence');
                }
            }
        }
        $sequence = C('DB_KDB_SEQUENCE');
        return empty($sequence) ? null : $this->validateQualifiedObjectName($sequence, 'sequence');
    }

    /**
     * 按 DB_SEQUENCE_PREFIX + 非限定表名约定查找现有 Oracle 序列。
     *
     * @param string $tableName 表名
     * @return string|null
     */
    private function conventionalSequence($tableName)
    {
        $table  = $this->unqualifiedName($tableName);
        $prefix = (string) C('DB_PREFIX');
        if ('' !== $prefix && 0 === stripos($table, $prefix)) {
            $table = substr($table, strlen($prefix));
        }
        $candidate = (string) C('DB_SEQUENCE_PREFIX') . $table;
        if ('' !== $candidate && $this->metadata->sequenceExists($this, $candidate)) {
            return $candidate;
        }
        return null;
    }

    /**
     * 校验并逐段引用 Oracle 序列名，支持可选的 Schema 限定。
     *
     * @param string $name 序列名
     * @return string
     */
    private function quoteOracleObject($name)
    {
        $name  = $this->validateQualifiedObjectName($name, 'sequence');
        $parts = explode('.', $name);
        foreach ($parts as &$part) {
            $part = $this->dialect->quoteIdentifier($part);
        }
        unset($part);
        return implode('.', $parts);
    }

    /**
     * 仅用于调试输出，将绑定值安全替换到 SQL 副本中而不参与真实执行。
     *
     * @param string $sql  带命名占位符的 SQL
     * @param array  $bind 绑定值
     * @return string
     */
    private function interpolateDebugSql($sql, $bind)
    {
        if (empty($bind)) {
            return $sql;
        }
        $replace = array();
        foreach ($bind as $key => $value) {
            if (is_array($value)) {
                $value = $value[0];
            }
            if (is_null($value)) {
                $replace[$key] = 'NULL';
            } elseif (is_bool($value)) {
                $replace[$key] = $value ? '1' : '0';
            } elseif (is_int($value) || is_float($value)) {
                $replace[$key] = (string) $value;
            } else {
                $replace[$key] = "'" . $this->escapeString($value) . "'";
            }
        }
        return strtr($sql, $replace);
    }

    /**
     * 为 MySQL 兼容函数的命名占位符补充 TEXT 类型转换，避免 PDO_KDB 推断失败。
     *
     * @param string $sql 待执行 SQL
     * @return string
     */
    private function normalizePreparedSql($sql)
    {
        if ('mysql' !== $this->mode || false === strpos($sql, ':')) {
            return $sql;
        }

        // 等长屏蔽字面量、引用标识符与注释，匹配偏移仍对应原始 SQL。
        $code = $this->maskSqlQuotedText($sql);
        $placeholder = '(:[A-Za-z_][A-Za-z0-9_]*)';
        $patterns = array(
            '/\bFIND_IN_SET\s*\(\s*' . $placeholder . '/i',
        );
        $replacements = array();
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as $match) {
                $replacements[$match[1]] = $match[0];
            }
        }
        krsort($replacements, SORT_NUMERIC);
        foreach ($replacements as $offset => $value) {
            $sql = substr_replace($sql, 'CAST(' . $value . ' AS TEXT)', $offset, strlen($value));
        }
        return $sql;
    }

    /**
     * 等长屏蔽 SQL 的引号内容及注释，避免兼容性改写触碰用户字符串。
     *
     * 支持转义/双写引号、方括号标识符、美元引用、行注释与嵌套块注释。
     * 此方法只用于定位 SQL 代码，不参与参数绑定或 SQL 安全校验。
     *
     * @param string $sql 原始 SQL
     * @param boolean $maskIdentifiers 是否同时屏蔽引用标识符
     * @return string 与原 SQL 字节长度一致的代码视图
     */
    private function maskSqlQuotedText($sql, $maskIdentifiers = true)
    {
        $masked = $sql;
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $start = $i;
            $char = $sql[$i];
            $pair = substr($sql, $i, 2);
            if ('--' === $pair || ('mysql' === $this->mode && '#' === $char)) {
                while ($i < $length && "\n" !== $sql[$i] && "\r" !== $sql[$i]) {
                    $i++;
                }
            } elseif ('/*' === $pair) {
                $depth = 1;
                $i += 2;
                while ($i < $length && $depth > 0) {
                    $pair = substr($sql, $i, 2);
                    if ('/*' === $pair || '*/' === $pair) {
                        $depth += '/*' === $pair ? 1 : -1;
                        $i += 2;
                    } else {
                        $i++;
                    }
                }
            } elseif ("'" === $char || '"' === $char || '`' === $char || '[' === $char) {
                $end = '[' === $char ? ']' : $char;
                $i++;
                while ($i < $length) {
                    if ('\\' === $sql[$i] && '[' !== $char) {
                        $i = min($length, $i + 2);
                    } elseif ($end === $sql[$i]) {
                        $i++;
                        if ($i < $length && $end === $sql[$i]) {
                            $i++;
                        } else {
                            break;
                        }
                    } else {
                        $i++;
                    }
                }
            } elseif ('$' === $char && preg_match('/^\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/', substr($sql, $i), $match)) {
                $end = strpos($sql, $match[0], $i + strlen($match[0]));
                $i = false === $end ? $length : $end + strlen($match[0]);
            } else {
                $i++;
                continue;
            }
            if (!$maskIdentifiers && ('"' === $char || '`' === $char || '[' === $char)) {
                continue;
            }
            $masked = substr_replace($masked, str_repeat(' ', $i - $start), $start, $i - $start);
        }
        return $masked;
    }

    /**
     * 将配置中的连接器别名统一转换为 mysql、oracle 或 sqlserver。
     *
     * @param mixed $mode 用户配置的模式名称
     * @return string 无法识别时返回空字符串
     */
    private function normalizeMode($mode)
    {
        $mode = strtolower(trim((string) $mode));
        $map = array(
            'mysql'           => 'mysql',
            'kingbasemysql'   => 'mysql',
            'oracle'          => 'oracle',
            'kingbaseoracle'  => 'oracle',
            'sqlsrv'          => 'sqlserver',
            'sqlserver'       => 'sqlserver',
            'kingbasesqlsrv'  => 'sqlserver',
            'kingbasesqlserver' => 'sqlserver',
        );
        return isset($map[$mode]) ? $map[$mode] : '';
    }

    /**
     * 校验单段数据库对象名，阻止任意 SQL 片段进入标识符位置。
     *
     * @param mixed  $name  对象名
     * @param string $label 诊断标签
     * @return string
     * @throws \InvalidArgumentException 对象名不符合安全规则时抛出
     */
    private function validateObjectName($name, $label)
    {
        if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_$#]*$/', $name)) {
            throw new \InvalidArgumentException('Invalid KDB ' . $label . ': ' . $name);
        }
        return $name;
    }

    /**
     * 校验最多包含 Schema 和对象名两段的限定对象名。
     *
     * @param mixed  $name  限定对象名
     * @param string $label 诊断标签
     * @return string
     * @throws \InvalidArgumentException 对象名不符合安全规则时抛出
     */
    private function validateQualifiedObjectName($name, $label)
    {
        $parts = explode('.', str_replace(array('`', '"', '[', ']'), '', (string) $name));
        if (count($parts) > 2) {
            throw new \InvalidArgumentException('Invalid KDB ' . $label . ': ' . $name);
        }
        foreach ($parts as $part) {
            $this->validateObjectName($part, $label);
        }
        return implode('.', $parts);
    }

    /**
     * 从限定表名或带别名表名中提取不含引号的最后一段对象名。
     *
     * @param mixed $name 表名表达式
     * @return string
     */
    private function unqualifiedName($name)
    {
        $name  = preg_split('/\s+/', trim((string) $name));
        $parts = explode('.', str_replace(array('`', '"', '[', ']'), '', $name[0]));
        return array_pop($parts);
    }
}
