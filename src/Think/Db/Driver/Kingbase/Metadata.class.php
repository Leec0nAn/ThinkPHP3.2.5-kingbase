<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Schema-scoped KingbaseES metadata reader
// +----------------------------------------------------------------------

namespace Think\Db\Driver\Kingbase;

class Metadata
{
    private $mode;
    private $schema;

    /**
     * 初始化指定兼容模式和 Schema 的元数据读取器。
     *
     * @param string $mode   KingbaseES 兼容模式
     * @param string $schema 元数据查询限定的 Schema
     */
    public function __construct($mode, $schema)
    {
        $this->mode   = strtolower($mode);
        $this->schema = $this->assertSimpleName($schema);
    }

    /**
     * 读取当前 Schema 中的普通表，并排除视图及其他对象类型。
     *
     * @param object $driver Kingbase 驱动实例，用于执行查询和转义字符串
     * @return array
     */
    public function getTables($driver)
    {
        $schema = $driver->escapeString($this->schema);
        $sql    = "SELECT table_name FROM information_schema.tables "
            . "WHERE lower(table_schema)=lower('" . $schema . "') "
            // MySQL mode exposes table_type as an enum. Calling upper() on it
            // is ambiguous in KES, while direct comparison works in all modes.
            . "AND table_type='BASE TABLE' ORDER BY table_name";
        $rows = $driver->query($sql);
        $info = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $info[] = current($row);
            }
        }
        return $info;
    }

    /**
     * 读取表字段并转换为 TP3.2 所需的字段、主键、自增及序列元数据结构。
     *
     * @param object $driver    Kingbase 驱动实例
     * @param string $tableName 表名或 Schema 限定表名
     * @return array
     */
    public function getFields($driver, $tableName)
    {
        list($schema, $table) = $this->splitTableName($tableName);
        $schema = $driver->escapeString($schema);
        $table  = $driver->escapeString($table);

        if ('mysql' === $this->mode) {
            $sql = "SELECT c.column_name,c.data_type,c.column_type,c.is_nullable,c.column_default,"
                . "c.column_key,CASE WHEN pk.column_name IS NULL THEN 0 ELSE 1 END AS is_primary,"
                . "CASE WHEN EXISTS (SELECT 1 FROM sys_catalog.sys_attribute a "
                . "JOIN sys_catalog.sys_class cls ON a.attrelid=cls.oid "
                . "JOIN sys_catalog.sys_namespace ns ON cls.relnamespace=ns.oid "
                . "WHERE lower(ns.nspname)=lower(c.table_schema) "
                . "AND lower(cls.relname)=lower(c.table_name) "
                . "AND lower(a.attname)=lower(c.column_name) AND a.attidentity<>'') "
                . "THEN 'auto_increment' ELSE c.extra END AS extra,c.column_comment "
                . "FROM information_schema.columns c LEFT JOIN ("
                . "SELECT kcu.table_schema,kcu.table_name,kcu.column_name "
                . "FROM information_schema.table_constraints tc "
                . "JOIN information_schema.key_column_usage kcu "
                . "ON tc.constraint_catalog=kcu.constraint_catalog "
                . "AND tc.constraint_schema=kcu.constraint_schema "
                . "AND tc.constraint_name=kcu.constraint_name "
                . "AND tc.table_schema=kcu.table_schema AND tc.table_name=kcu.table_name "
                . "WHERE tc.constraint_type='PRIMARY KEY'"
                . ") pk ON lower(c.table_schema)=lower(pk.table_schema) "
                . "AND lower(c.table_name)=lower(pk.table_name) "
                . "AND lower(c.column_name)=lower(pk.column_name) "
                . "WHERE lower(c.table_schema)=lower('" . $schema . "') "
                . "AND lower(c.table_name)=lower('" . $table . "') ORDER BY c.ordinal_position";
        } else {
            $identityExpression = 'c.is_identity';
            if ('sqlserver' === $this->mode) {
                $objectName = $driver->escapeString($schema . '.' . $table);
                $identityExpression = "CASE WHEN EXISTS (SELECT 1 FROM sys.columns sc "
                    . "WHERE sc.object_id=OBJECT_ID('" . $objectName . "') "
                    . "AND lower(sc.name)=lower(c.column_name) AND sc.is_identity=1) "
                    . "THEN 'YES' ELSE c.is_identity END";
            }
            $sql = "SELECT c.column_name,c.data_type,c.is_nullable,c.column_default,"
                . $identityExpression . " AS is_identity,"
                . "CASE WHEN pk.column_name IS NULL THEN 0 ELSE 1 END AS is_primary "
                . "FROM information_schema.columns c LEFT JOIN ("
                . "SELECT kcu.table_schema,kcu.table_name,kcu.column_name "
                . "FROM information_schema.table_constraints tc "
                . "JOIN information_schema.key_column_usage kcu "
                . "ON tc.constraint_catalog=kcu.constraint_catalog "
                . "AND tc.constraint_schema=kcu.constraint_schema "
                . "AND tc.constraint_name=kcu.constraint_name "
                . "AND tc.table_schema=kcu.table_schema AND tc.table_name=kcu.table_name "
                . "WHERE upper(tc.constraint_type)='PRIMARY KEY'"
                . ") pk ON lower(c.table_schema)=lower(pk.table_schema) "
                . "AND lower(c.table_name)=lower(pk.table_name) "
                . "AND lower(c.column_name)=lower(pk.column_name) "
                . "WHERE lower(c.table_schema)=lower('" . $schema . "') "
                . "AND lower(c.table_name)=lower('" . $table . "') ORDER BY c.ordinal_position";
        }

        $rows = $driver->query($sql);
        $info = array();
        if (!is_array($rows)) {
            return $info;
        }

        foreach ($rows as $row) {
            $row      = array_change_key_case($row, CASE_LOWER);
            $name     = strtolower($row['column_name']);
            $default  = isset($row['column_default']) ? $row['column_default'] : null;
            $primary  = 'mysql' === $this->mode
                ? ('pri' === strtolower($row['column_key']) || !empty($row['is_primary']))
                : !empty($row['is_primary']);
            $autoinc  = 'mysql' === $this->mode
                ? (false !== stripos($row['extra'], 'auto_increment'))
                : ('yes' === strtolower($row['is_identity']) || null !== $this->extractSequence($default));
            $type = 'mysql' === $this->mode && !empty($row['column_type'])
                ? $row['column_type']
                : $row['data_type'];

            $info[$name] = array(
                'name'     => $name,
                'type'     => strtolower($type),
                'notnull'  => 'no' === strtolower($row['is_nullable']),
                'default'  => $default,
                'primary'  => (bool) $primary,
                'autoinc'  => (bool) $autoinc,
                'sequence' => $this->extractSequence($default),
                'comment'  => isset($row['column_comment']) ? $row['column_comment'] : '',
            );
        }
        return $info;
    }

    /**
     * 检查指定序列是否存在，未显式指定 Schema 时使用当前 Schema。
     *
     * @param object $driver   Kingbase 驱动实例
     * @param string $sequence 序列名或 Schema 限定序列名
     * @return boolean
     */
    public function sequenceExists($driver, $sequence)
    {
        list($schema, $name) = $this->splitSequenceName($sequence);
        $schema = $driver->escapeString($schema);
        $name   = $driver->escapeString($name);
        $sql    = "SELECT 1 FROM information_schema.sequences "
            . "WHERE lower(sequence_schema)=lower('" . $schema . "') "
            . "AND lower(sequence_name)=lower('" . $name . "')";
        $rows = $driver->query($sql);
        return !empty($rows);
    }

    /**
     * 拆分并校验表名，返回安全的 Schema 和非限定表名。
     *
     * @param string $tableName 表名、限定表名或带别名的表表达式
     * @return array
     */
    private function splitTableName($tableName)
    {
        $tableName = trim((string) $tableName);
        $tableName = preg_split('/\s+/', $tableName);
        $tableName = $tableName[0];
        $tableName = str_replace(array('`', '"', '[', ']'), '', $tableName);
        $parts     = explode('.', $tableName);
        if (count($parts) > 1) {
            $table  = array_pop($parts);
            $schema = array_pop($parts);
        } else {
            $schema = $this->schema;
            $table  = $parts[0];
        }
        return array($this->assertSimpleName($schema), $this->assertSimpleName($table));
    }

    /**
     * 拆分并校验序列名，未限定时补充当前 Schema。
     *
     * @param string $sequence 序列名或限定序列名
     * @return array
     */
    private function splitSequenceName($sequence)
    {
        $sequence = str_replace(array('`', '"', '[', ']'), '', trim($sequence));
        $parts    = explode('.', $sequence);
        if (count($parts) > 1) {
            $name   = array_pop($parts);
            $schema = array_pop($parts);
        } else {
            $schema = $this->schema;
            $name   = $parts[0];
        }
        return array($this->assertSimpleName($schema), $this->assertSimpleName($name));
    }

    /**
     * 校验单段数据库对象名，阻止将任意 SQL 片段拼入元数据语句。
     *
     * @param string $name 对象名
     * @return string
     * @throws \InvalidArgumentException 对象名不符合安全规则时抛出
     */
    private function assertSimpleName($name)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_$#]*$/', $name)) {
            throw new \InvalidArgumentException('Unsupported database object name: ' . $name);
        }
        return $name;
    }

    /**
     * 从字段默认值中提取 Oracle NEXTVAL 或 PostgreSQL 风格 nextval 序列名。
     *
     * @param mixed $default 字段默认值
     * @return string|null
     */
    private function extractSequence($default)
    {
        if (!is_string($default) || '' === $default) {
            return null;
        }
        if (preg_match('/([A-Za-z_][A-Za-z0-9_$#]*(?:\.[A-Za-z_][A-Za-z0-9_$#]*)?)\s*\.\s*NEXTVAL/i', $default, $match)) {
            return $match[1];
        }
        if (preg_match('/nextval\(\s*[\'\"]([^\'\"]+)[\'\"]/i', $default, $match)) {
            $sequence = preg_replace('/::.*$/', '', $match[1]);
            return trim($sequence, '"');
        }
        return null;
    }
}
