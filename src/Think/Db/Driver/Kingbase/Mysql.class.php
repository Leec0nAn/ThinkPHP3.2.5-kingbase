<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | KingbaseES MySQL compatibility dialect
// +----------------------------------------------------------------------

namespace Think\Db\Driver\Kingbase;

class Mysql implements DialectInterface
{
    /**
     * 返回服务端 database_mode 对应的 MySQL 模式标识。
     *
     * @return string
     */
    public function getMode()
    {
        return 'mysql';
    }

    /**
     * 返回 TP3.2 组装 MySQL 兼容查询时使用的 SELECT 模板。
     *
     * @return string
     */
    public function getSelectSql()
    {
        return 'SELECT%DISTINCT% %FIELD% FROM %TABLE%%FORCE%%JOIN%%WHERE%%GROUP%%HAVING%%ORDER%%LIMIT% %UNION%%LOCK%%COMMENT%';
    }

    /**
     * 使用反引号引用普通标识符，同时保留函数、表达式和通配符原样。
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
            if ('*' !== $part && !preg_match('/^`.*`$/', $part)) {
                $part = '`' . str_replace('`', '``', $part) . '`';
            }
        }
        unset($part);
        return implode('.', $parts);
    }

    /**
     * 去除目录查询结果中的外层引号，得到 MySQL 模式对象名。
     *
     * @param string $identifier 标识符
     * @return string
     */
    public function normalizeIdentifier($identifier)
    {
        return trim($identifier, " \t\n\r\0\x0B`\"");
    }

    /**
     * 将 TP3.2 的“偏移量,数量”转换成 LIMIT/OFFSET 分页子句。
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
        return ' LIMIT ' . $count . ($offset > 0 ? ' OFFSET ' . $offset : '') . ' ';
    }

    /**
     * 根据查询选项生成 MySQL 兼容模式的行锁子句。
     *
     * @param boolean $lock 是否加锁
     * @return string
     */
    public function parseLock($lock = false)
    {
        return $lock ? ' FOR UPDATE ' : '';
    }

    /**
     * 返回 MySQL 兼容模式使用的随机排序函数。
     *
     * @return string
     */
    public function parseRand()
    {
        return 'rand()';
    }

    /**
     * 判断输入是否已经是 SQL 表达式，避免对其再次添加反引号。
     *
     * @param mixed $identifier 标识符或表达式
     * @return boolean
     */
    private function isExpression($identifier)
    {
        return '' === $identifier
            || '*' === $identifier
            || preg_match('/[,\'"()\s]/', $identifier)
            || false !== strpos($identifier, '`');
    }
}
