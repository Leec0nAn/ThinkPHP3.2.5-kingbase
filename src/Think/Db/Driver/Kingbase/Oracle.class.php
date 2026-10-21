<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | KingbaseES Oracle compatibility dialect
// +----------------------------------------------------------------------

namespace Think\Db\Driver\Kingbase;

class Oracle implements DialectInterface
{
    /**
     * 返回服务端 database_mode 对应的 Oracle 模式标识。
     *
     * @return string
     */
    public function getMode()
    {
        return 'oracle';
    }

    /**
     * 返回使用 OFFSET/FETCH 分页的 Oracle 兼容 SELECT 模板。
     *
     * @return string
     */
    public function getSelectSql()
    {
        // KES Oracle mode supports the SQL:2008 OFFSET/FETCH form.  It avoids
        // leaking the numrow helper column used by ThinkPHP's OCI driver.
        return 'SELECT%DISTINCT% %FIELD% FROM %TABLE%%JOIN%%WHERE%%GROUP%%HAVING%%ORDER%%LIMIT% %UNION%%LOCK%%COMMENT%';
    }

    /**
     * 将普通对象名转为大写并使用双引号引用，表达式保持原样。
     *
     * @param mixed   $identifier 标识符或表达式
     * @param boolean $strict     是否只允许简单标识符
     * @return mixed
     */
    public function quoteIdentifier($identifier, $strict = false)
    {
        if (is_int($identifier)) {
            return $identifier;
        }

        $identifier = trim($identifier);
        if ($strict && !preg_match('/^[\w\.\*]+$/', $identifier)) {
            throw new \InvalidArgumentException('Unsupported identifier: ' . $identifier);
        }

        if ($this->isExpression($identifier)) {
            return $identifier;
        }

        $parts = explode('.', $identifier);
        foreach ($parts as &$part) {
            if ('*' !== $part && !preg_match('/^".*"$/', $part)) {
                $part = '"' . str_replace('"', '""', strtoupper($part)) . '"';
            }
        }
        unset($part);
        return implode('.', $parts);
    }

    /**
     * 将未引用的 Oracle 对象名规范化为目录查询使用的大写形式。
     *
     * @param string $identifier 标识符
     * @return string
     */
    public function normalizeIdentifier($identifier)
    {
        return strtoupper(trim($identifier, " \t\n\r\0\x0B`\"[]"));
    }

    /**
     * 将 TP3.2 分页参数转换为 OFFSET/FETCH 子句。
     *
     * @param mixed $limit 分页参数
     * @return string
     */
    public function parseLimit($limit)
    {
        if ('' === (string) $limit) {
            return '';
        }

        $parts  = array_map('trim', explode(',', (string) $limit, 2));
        $offset = count($parts) > 1 ? (int) $parts[0] : 0;
        $count  = count($parts) > 1 ? (int) $parts[1] : (int) $parts[0];
        return ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $count . ' ROWS ONLY ';
    }

    /**
     * 根据查询选项生成 Oracle 兼容模式的 FOR UPDATE NOWAIT 子句。
     *
     * @param boolean $lock 是否加锁
     * @return string
     */
    public function parseLock($lock = false)
    {
        return $lock ? ' FOR UPDATE NOWAIT ' : '';
    }

    /**
     * 返回 KingbaseES Oracle 模式可用的随机排序表达式。
     *
     * @return string
     */
    public function parseRand()
    {
        // DBMS_RANDOM is not installed in a default KES Oracle instance.
        return 'random()';
    }

    /**
     * 判断输入是否为函数、别名、通配符或已经引用的 SQL 表达式。
     *
     * @param mixed $identifier 标识符或表达式
     * @return boolean
     */
    private function isExpression($identifier)
    {
        return '' === $identifier
            || '*' === $identifier
            || preg_match('/[,\'`()\s]/', $identifier)
            || false !== strpos($identifier, '"');
    }
}
