<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | KingbaseES SQL Server compatibility dialect
// +----------------------------------------------------------------------

namespace Think\Db\Driver\Kingbase;

class Sqlsrv implements DialectInterface
{
    /**
     * 返回服务端 database_mode 对应的 SQL Server 模式标识。
     *
     * @return string
     */
    public function getMode()
    {
        return 'sqlserver';
    }

    /**
     * 返回 SQL Server 兼容查询使用的 SELECT 模板。
     *
     * @return string
     */
    public function getSelectSql()
    {
        return 'SELECT%DISTINCT% %FIELD% FROM %TABLE%%JOIN%%WHERE%%GROUP%%HAVING%%ORDER%%LIMIT% %UNION%%LOCK%%COMMENT%';
    }

    /**
     * 使用方括号引用普通标识符，同时保留 SQL 表达式原样。
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
            if ('*' !== $part && !preg_match('/^\[.*\]$/', $part)) {
                $part = '[' . str_replace(']', ']]', $part) . ']';
            }
        }
        unset($part);
        return implode('.', $parts);
    }

    /**
     * 去除对象名的外层引号或方括号，供元数据查询使用。
     *
     * @param string $identifier 标识符
     * @return string
     */
    public function normalizeIdentifier($identifier)
    {
        return trim($identifier, " \t\n\r\0\x0B`\"[]");
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
     * 根据查询选项生成 SQL Server 兼容模式可接受的行锁子句。
     *
     * @param boolean $lock 是否加锁
     * @return string
     */
    public function parseLock($lock = false)
    {
        // KES SQL Server compatibility mode accepts the common trailing form
        // and applies an update lock without rewriting FROM table hints.
        return $lock ? ' FOR UPDATE ' : '';
    }

    /**
     * 返回 SQL Server 风格的随机排序函数。
     *
     * @return string
     */
    public function parseRand()
    {
        return 'NEWID()';
    }

    /**
     * 判断输入是否已是 SQL 表达式，避免重复添加方括号。
     *
     * @param mixed $identifier 标识符或表达式
     * @return boolean
     */
    private function isExpression($identifier)
    {
        return '' === $identifier
            || '*' === $identifier
            || preg_match('/[,\'"`()\s]/', $identifier)
            || false !== strpos($identifier, '[');
    }
}
