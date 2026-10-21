<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | KingbaseES PDO_KDB dialect contract
// +----------------------------------------------------------------------

namespace Think\Db\Driver\Kingbase;

interface DialectInterface
{
    /**
     * 返回与 KingbaseES database_mode 参数一致的兼容模式标识。
     *
     * @return string mysql、oracle 或 sqlserver
     */
    public function getMode();

    /**
     * 返回当前兼容模式供 ThinkPHP 3.2 组装查询使用的 SELECT 模板。
     *
     * @return string 包含 TP3.2 模板占位符的 SELECT SQL
     */
    public function getSelectSql();

    /**
     * 按当前兼容模式引用表名、字段名或别名。
     *
     * @param mixed   $identifier 标识符、标识符数组或 SQL 表达式
     * @param boolean $strict     是否启用严格格式校验
     * @return mixed 引用后的标识符，数组输入返回数组
     */
    public function quoteIdentifier($identifier, $strict = false);

    /**
     * 规范化未引用的对象名，供系统目录和元数据查询使用。
     *
     * @param string $identifier 原始对象名
     * @return string 当前兼容模式规范化后的对象名
     */
    public function normalizeIdentifier($identifier);

    /**
     * 将 TP3.2 分页配置转换为当前兼容模式的 SELECT 分页子句。
     *
     * @param mixed $limit 数量或“偏移量,数量”配置
     * @return string 分页 SQL 子句
     */
    public function parseLimit($limit);

    /**
     * 根据查询锁选项生成当前兼容模式的行锁子句。
     *
     * @param boolean $lock 是否锁定查询结果
     * @return string 行锁 SQL 子句；未启用时为空字符串
     */
    public function parseLock($lock = false);

    /**
     * 返回当前兼容模式用于随机排序的函数或表达式。
     *
     * @return string
     */
    public function parseRand();
}
