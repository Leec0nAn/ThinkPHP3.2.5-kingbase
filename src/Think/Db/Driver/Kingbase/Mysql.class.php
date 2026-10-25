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
            E('not support data:' . $identifier);
        }

        // 与原Mysql Driver::parseKey()保持一致：仅引用简单标识符，
        // 限定字段、函数和表达式保持原样。
        if ('*' !== $identifier && !preg_match('/[,\'"\*\(\)`\.\s]/', $identifier)) {
            $identifier = '`' . $identifier . '`';
        }

        return $identifier;
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
     * 保留ThinkPHP 3.2.5原MySQL Driver的LIMIT offset,count分页语法。
     *
     * @param mixed $limit 分页参数
     * @return string
     */
    public function parseLimit($limit)
    {
        return (!empty($limit) && false === strpos($limit, '('))
            ? ' LIMIT ' . $limit . ' '
            : '';
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

}
