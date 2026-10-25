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
        return 'SELECT T1.* FROM (SELECT thinkphp.*, ROW_NUMBER() OVER (%ORDER%) AS ROW_NUMBER FROM (SELECT %DISTINCT% %FIELD% FROM %TABLE%%JOIN%%WHERE%%GROUP%%HAVING% %UNION%) AS thinkphp) AS T1 %LIMIT%%COMMENT%';
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
        $identifier = trim($identifier);
        if ($strict && !preg_match('/^[\w\.\*]+$/', $identifier)) {
            E('not support data:' . $identifier);
        }

        // 完整保持原Sqlsrv Driver::parseKey()行为，包括严格模式下将
        // 限定字段整体放入方括号的既有处理。
        if ($strict || (!is_numeric($identifier)
            && !preg_match('/[,\'"\*\(\)\[\.\s]/', $identifier))) {
            $identifier = '[' . $identifier . ']';
        }

        return $identifier;
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
     * 沿用ThinkPHP 3.2.5 SQL Server Driver的ROW_NUMBER分页范围。
     *
     * @param mixed $limit 分页参数
     * @return string
     */
    public function parseLimit($limit)
    {
        if ('' === (string) $limit) {
            return '';
        }

        $parts = array_map('trim', explode(',', (string) $limit, 2));
        if (count($parts) > 1) {
            $limitStr = '(T1.ROW_NUMBER BETWEEN ' . (int) $parts[0]
                . ' + 1 AND ' . (int) $parts[0] . ' + ' . (int) $parts[1] . ')';
        } else {
            $limitStr = '(T1.ROW_NUMBER BETWEEN 1 AND ' . (int) $parts[0] . ')';
        }

        return 'WHERE ' . $limitStr;
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
     * 保持 ThinkPHP 3.2.5 原 Sqlsrv Driver 的随机排序表达式。
     *
     * @return string
     */
    public function parseRand()
    {
        return 'rand()';
    }

}
