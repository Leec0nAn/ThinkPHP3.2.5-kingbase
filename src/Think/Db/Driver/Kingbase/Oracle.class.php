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
     * 返回以 ThinkPHP 3.2.5 原 Oracle Driver 为基础的 SELECT 模板。
     *
     * KingbaseES Oracle兼容模式支持ROWNUM，因此完整保持原框架的
     * SELECT模板及其既有能力边界。
     *
     * @return string
     */
    public function getSelectSql()
    {
        return 'SELECT * FROM (SELECT thinkphp.*, rownum AS numrow FROM (SELECT  %DISTINCT% %FIELD% FROM %TABLE%%JOIN%%WHERE%%GROUP%%HAVING%%ORDER%) thinkphp ) %LIMIT%%COMMENT%';
    }

    /** 保持ThinkPHP 3.2.5原Oracle Driver继承的标识符处理行为。 */
    public function quoteIdentifier($identifier, $strict = false)
    {
        return $identifier;
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
     * 按照 ThinkPHP 3.2.5 原 Oracle Driver 规则生成 ROWNUM 分页条件。
     *
     * @param mixed $limit 分页参数
     * @return string
     */
    public function parseLimit($limit)
    {
        $limitStr = '';
        if (!empty($limit)) {
            $parts = explode(',', (string) $limit);
            if (count($parts) > 1) {
                $limitStr = '(numrow>' . $parts[0] . ') AND (numrow<=' . ($parts[0] + $parts[1]) . ')';
            } else {
                $limitStr = '(numrow>0 AND numrow<=' . $parts[0] . ')';
            }
        }
        return $limitStr ? ' WHERE ' . $limitStr : '';
    }

    /**
     * 保持原Driver的锁定子句。
     *
     * @param boolean $lock 是否加锁
     * @return string
     */
    public function parseLock($lock = false)
    {
        return $lock ? ' FOR UPDATE ' : '';
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

}
