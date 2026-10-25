# ThinkPHP 3.2.5 KingbaseES访问示例

安装并配置KingbaseES驱动后，应用继续使用ThinkPHP 3.2.5原有的`M()`、`D()`、`Model`及增删改查、原生SQL和事务接口。本驱动没有新增另一套业务访问API。

本文仅说明原有接口连接KingbaseES后的基本使用方式。完整的ThinkPHP接口定义仍以ThinkPHP 3.2.5官方文档和原框架源码为准。

## 1. 原有接口与适配范围

应用可以继续使用以下接口：

- 数据访问对象：`M()`、`D()`和`Model`；
- 查询设置：`field()`、`where()`、`join()`、`group()`、`having()`、`order()`和`limit()`；
- 数据操作：`add()`、`addAll()`、`find()`、`select()`、`save()`和`delete()`；
- 原生SQL：`query()`和`execute()`；
- 事务：`startTrans()`、`commit()`和`rollback()`。

这些方法均为ThinkPHP 3.2.5原有接口。本驱动在数据库Driver层处理PDO_KDB连接、兼容模式、SQL方言、元数据、生成主键和事务差异。

## 2. 使用M()访问数据表

如果应用没有为数据表定义专门的Model类，可以使用`M()`创建通用Model对象：

```php
$user = M('User');
```

该调用取得用于访问`user`表的`Think\Model`对象，本身不会立即执行SQL。实际调用`find()`、`select()`、`add()`、`save()`或`delete()`时，框架才进入数据库执行流程。

查询一条记录：

```php
$row = M('User')
    ->where(array('id' => 1))
    ->find();
```

查询多条记录：

```php
$rows = M('User')
    ->field('id,name,status')
    ->where(array('status' => 1))
    ->order('id DESC')
    ->select();
```

## 3. 使用D()加载业务Model

如果应用已经定义`UserModel`，可以继续使用：

```php
$user = D('User');
$rows = $user->where(array('status' => 1))->select();
```

`D('User')`按照ThinkPHP 3.2.5原有规则查找并实例化应用定义的`UserModel`。KingbaseES适配不会改变业务Model的装载方式。

## 4. 基础增删改查

以下示例假设当前Schema中已经存在`user`表，并包含`id`、`name`和`status`字段。

### 4.1 新增

```php
$user = M('User');

$id = $user->add(array(
    'name'   => 'Alice',
    'status' => 1,
));
```

新增成功后，`add()`按照ThinkPHP 3.2.5原接口约定返回结果。驱动根据当前兼容模式处理Auto Increment、Sequence或Identity生成的主键。

### 4.2 查询

```php
$row = M('User')
    ->where(array('id' => $id))
    ->find();
```

### 4.3 更新

```php
$affected = M('User')
    ->where(array('id' => $id))
    ->save(array(
        'name'   => 'Alice Updated',
        'status' => 0,
    ));
```

### 4.4 删除

```php
$affected = M('User')
    ->where(array('id' => $id))
    ->delete();
```

删除和更新操作必须提供明确条件。为保持ThinkPHP 3.2.5原Driver行为，Oracle模式会按原基础Driver生成带`order()`或`limit()`选项的写入SQL，该SQL可能由数据库返回语法错误；SQL Server模式会沿用原Sqlsrv Driver并忽略写操作中的`order()`和`limit()`，实际修改范围仍由`where()`条件决定。需要限制写入数量时，应先查询目标记录主键，再使用明确的主键条件执行更新或删除。

## 5. Oracle兼容模式使用Sequence新增主键

Oracle兼容模式的数据表可以使用Sequence生成主键。当业务代码调用ThinkPHP 3.2.5原有的`add()`接口，并且没有显式提供主键值时，驱动需要确定当前表使用的Sequence，在INSERT中使用`Sequence.NEXTVAL`，并在插入成功后取得本次生成的主键值。

例如，`user`表的主键字段为`id`，对应的Sequence为`user_seq`。如果驱动能够从字段元数据中识别该Sequence，应用不需要增加额外配置，业务代码仍然直接调用：

```php
$id = M('User')->add(array(
    'name'   => 'Alice',
    'status' => 1,
));
```

执行过程中，驱动完成以下处理：

1. 读取`user`表的字段和主键信息；
2. 确定主键字段使用的Sequence；
3. 在应用未提供`id`时使用`user_seq.NEXTVAL`生成主键；
4. 执行INSERT；
5. 读取本次Sequence生成的主键值；
6. 通过`add()`将主键值返回给业务代码。

如果字段元数据无法确定Sequence，或者项目使用自定义序列命名规则，可以在`Application/Common/Conf/config.php`中按表配置：

```php
'DB_KDB_SEQUENCES' => array(
    'user'  => 'user_seq',
    'order' => 'order_seq',
),
```

也可以配置一个全局兜底Sequence：

```php
'DB_KDB_SEQUENCE' => 'user_seq',
```

全局配置适用于确实需要使用同一个默认Sequence的项目；存在多个独立Sequence时，建议使用`DB_KDB_SEQUENCES`按表配置。

如果项目沿用了ThinkPHP 3.2.5原Oracle驱动的序列前缀约定，可以配置：

```php
'DB_SEQUENCE_PREFIX' => 'seq_',
```

例如表名为`user`时，驱动会检查`seq_user`是否为可用Sequence。

驱动按照以下顺序确定Sequence：

1. 当前表在`DB_KDB_SEQUENCES`中配置的Sequence；
2. `DB_KDB_SEQUENCE`配置的全局Sequence；
3. 字段元数据中识别出的Sequence；
4. 按照`DB_SEQUENCE_PREFIX + 表名`约定查找到的Sequence。

如果业务代码已经显式提供主键值，驱动使用应用提供的值，不再为本次INSERT注入`Sequence.NEXTVAL`。

MySQL兼容模式的Auto Increment和SQL Server兼容模式的Identity不使用上述Sequence配置。

## 6. 批量新增

使用ThinkPHP 3.2.5原有`addAll()`接口：

```php
$result = M('User')->addAll(array(
    array(
        'name'   => 'Alice',
        'status' => 1,
    ),
    array(
        'name'   => 'Bob',
        'status' => 1,
    ),
));
```

同一次批量新增中，各行数据的字段集合应保持一致。驱动根据当前兼容模式生成批量写入SQL，并通过PDO_KDB执行。

## 7. 分页查询

业务代码继续使用原有`limit()`接口：

```php
$rows = M('User')
    ->where(array('status' => 1))
    ->order('id ASC')
    ->limit(10, 20)
    ->select();
```

驱动根据KingbaseES兼容模式生成相应的分页SQL。需要稳定分页结果时，应同时设置确定的`order()`排序条件。

## 8. 原生SQL

应用可以继续使用`query()`和`execute()`。

查询示例：

```php
$rows = M()->query(
    'SELECT * FROM user WHERE status = 1'
);
```

写入示例：

```php
$affected = M()->execute(
    'UPDATE user SET status = 0 WHERE id = 1'
);
```

直接提交的原生SQL必须符合当前KingbaseES兼容模式。本驱动不会在MySQL、Oracle和SQL Server语法之间自动翻译任意原生SQL，也不会自动转换`CREATE TABLE`、`ALTER TABLE`或`CREATE SEQUENCE`等DDL。

推荐优先使用Model及链式查询接口，让驱动根据当前兼容模式生成SQL。

## 9. 事务提交与回滚

应用继续使用ThinkPHP 3.2.5原有事务接口：

```php
$user = M('User');

$user->startTrans();

try {
    $user->add(array(
        'name'   => 'Alice',
        'status' => 1,
    ));

    $user
        ->where(array('id' => 2))
        ->save(array('status' => 0));

    $user->commit();
} catch (\Exception $e) {
    $user->rollback();
    throw $e;
}
```

事务中的查询和写入使用同一物理连接，直至提交或回滚完成。发生约束冲突、锁超时、死锁或连接异常后，应先完成必要的回滚，再继续使用连接。

## 10. 使用注意事项

- 三种兼容模式统一配置`DB_TYPE=kingbase`；
- 推荐使用`DB_KDB_MODE=auto`自动识别服务端模式；
- 显式配置模式时，驱动仍会在正式连接和重连阶段校验实际模式；
- 原生SQL和DDL必须符合当前兼容模式；
- 复杂数据类型的实际可用范围取决于KingbaseES版本、兼容模式和PDO_KDB版本。
