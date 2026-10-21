# ThinkPHP 3.2.5 KingbaseES 数据库驱动

本项目为ThinkPHP 3.2.5提供独立的KingbaseES数据库适配包。应用通过
`DB_TYPE=kingbase`进入本包提供的`Think\Db\Driver\Kingbase`驱动，业务代码继续使用
ThinkPHP原有的`M()`、`D()`、`Model`、`query()`和`execute()`等接口，不需要修改
ThinkPHP 3.2.5框架源码。

数据库物理连接统一使用金仓官方`PDO_KDB`扩展及`kdb:` DSN。驱动在连接后识别或校验
KingbaseES兼容模式，并为MySQL、Oracle和SQL Server三种兼容模式选择相应的SQL方言、
元数据和生成主键处理逻辑。

## 主要功能

- 通过`DB_TYPE=kingbase`接入ThinkPHP 3.2.5数据库工厂；
- 使用PDO_KDB建立KingbaseES连接，并输出不包含密码的连接诊断信息；
- 未配置模式时读取服务端`database_mode`自动选择方言；
- 显式配置模式时，在正式连接和重连阶段校验服务端实际模式；
- 初始化或自动发现当前Schema，并在重连后重新建立会话状态；
- 处理三种兼容模式的标识符、分页、锁、随机排序和批量写入差异；
- 读取表、字段、主键、Identity、Sequence和自增属性等元数据；
- 按ThinkPHP 3.2.5原接口约定返回Auto Increment、Identity或Sequence生成的主键；
- 支持事务、参数绑定、LOB及二进制数据处理；
- 保持`M()`、`D()`、`add()`、`find()`、`select()`、`save()`和`delete()`等业务调用方式不变。

## 代码结构

```text
ThinkPHP3.2.5-kingbase/
├── composer.json
├── README.md
├── LICENSE
├── bin/
│   └── kingbase-doctor
├── docs/
│   ├── Composer安装与配置指南.md
│   └── KingbaseES访问示例.md
├── examples/
│   └── config.php
└── src/Think/Db/Driver/
    ├── Kingbase.class.php
    └── Kingbase/
        ├── DialectInterface.class.php
        ├── Metadata.class.php
        ├── Mysql.class.php
        ├── Oracle.class.php
        └── Sqlsrv.class.php
```

各组件职责如下：

- `Kingbase.class.php`：统一主驱动，负责PDO_KDB连接、模式识别与校验、Schema会话、
  SQL执行、参数绑定、事务、元数据入口和生成主键返回；
- `DialectInterface.class.php`：定义三种兼容模式共同使用的方言接口；
- `Mysql.class.php`：处理MySQL兼容模式的反引号、分页、锁、函数和写入语法；
- `Oracle.class.php`：处理Oracle兼容模式的双引号、分页、锁、Sequence和LOB差异；
- `Sqlsrv.class.php`：处理SQL Server兼容模式的方括号、分页、Identity和事务语法；
- `Metadata.class.php`：按当前模式及Schema读取表、字段、主键和生成属性；
- `kingbase-doctor`：检查PHP、PDO_KDB和可选的KingbaseES连接环境；
- `examples/config.php`：提供可直接合并到ThinkPHP应用中的配置示例。

## 环境要求

- ThinkPHP 3.2.5；
- PHP 5.6；
- Composer 2.2 LTS；
- 与PHP ABI、TS/NTS和CPU架构匹配的金仓官方`pdo_kdb`扩展；
- `libkci`及其依赖能够被PHP进程加载。

金仓官方驱动可从[电科金仓接口驱动下载中心](https://www.kingbase.com.cn/download.html#drive)
获取。应根据KingbaseES版本、操作系统、CPU架构、PHP版本和TS/NTS模式选择对应的驱动包。

先确认PDO_KDB已加载：

```bash
php -m | grep -i pdo_kdb
php -r 'print_r(PDO::getAvailableDrivers());'
```

第二条命令的输出必须包含`kdb`。

## 安装

本包通过GitHub发布正式标签。安装前，先在应用中登记VCS仓库：

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/Leec0nAn/ThinkPHP3.2.5-kingbase.git"
        }
    ],
    "require": {
        "kingbase/thinkphp32-kingbase": "^1.0"
    }
}
```

或者执行：

```bash
composer config repositories.thinkphp32-kingbase vcs \
  https://github.com/Leec0nAn/ThinkPHP3.2.5-kingbase.git
composer require kingbase/thinkphp32-kingbase:^1.0
```

上述方式要求仓库已发布正式标签，例如`v1.0.0`。

传统ThinkPHP 3.2.5应用需要在入口文件中先加载Composer，再加载框架：

```php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/ThinkPHP/ThinkPHP.php';
```

如果应用已经加载`vendor/autoload.php`，无需重复加载。

## 应用配置

将以下配置合并到`Application/Common/Conf/config.php`：

```php
<?php
return array(
    'DB_TYPE'       => 'kingbase',
    'DB_HOST'       => '127.0.0.1',
    'DB_PORT'       => '54321',
    'DB_NAME'       => 'test',
    'DB_USER'       => 'system',
    'DB_PWD'        => '<数据库密码>',
    'DB_CHARSET'    => 'utf8',
    'DB_PARAMS'     => array(),
    'DB_DEBUG'      => false,

    // 可选：auto、mysql、oracle或sqlserver；省略时自动识别。
    'DB_KDB_MODE'   => 'auto',

    // 可选；省略或留空时使用数据库会话的current_schema()。
    'DB_SCHEMA'     => 'public',

    // Oracle兼容模式可选：表名与序列名映射。
    'DB_KDB_SEQUENCES' => array(
        // 'user' => 'user_seq',
    ),
);
```

三种兼容模式统一使用`DB_TYPE=kingbase`。`DB_KDB_MODE`仅用于自动识别或显式声明
预期兼容模式，不用于切换数据库驱动类型。

| KingbaseES兼容模式 | `DB_KDB_MODE`值 | 常见Schema | 示例端口 |
| --- | --- | --- | --- |
| MySQL | `mysql` | `public` | `54321` |
| Oracle | `oracle` | 当前用户对应Schema，例如`system` | `54322` |
| SQL Server | `sqlserver` | `dbo` | `54323` |

未填写`DB_KDB_MODE`或设置为`auto`时，驱动连接后读取服务端`database_mode`。显式填写
模式时，该值表示期望模式，驱动仍会在正式连接及重连时进行二次校验；不一致时在业务SQL
执行前抛出异常。

## 使用示例

配置完成后，业务代码继续使用ThinkPHP 3.2.5原有接口：

```php
$user = M('User');

$id = $user->add(array(
    'name'   => 'Alice',
    'status' => 1,
));

$row = $user
    ->where(array('id' => $id))
    ->find();
```

如果应用已经定义`UserModel`，仍可使用：

```php
$user = D('User');
$rows = $user->where(array('status' => 1))->select();
```

直接通过`query()`或`execute()`提交的原生SQL必须符合当前KingbaseES兼容模式，本驱动
不会在MySQL、Oracle和SQL Server语法之间自动翻译任意原生SQL或DDL。

完整安装步骤和使用示例见：

- [Composer安装与配置指南](docs/Composer安装与配置指南.md)
- [KingbaseES访问示例](docs/KingbaseES访问示例.md)

## 环境诊断

安装后可以执行：

```bash
vendor/bin/kingbase-doctor
```

未配置连接环境变量时，该命令只检查PHP和PDO_KDB。需要检查真实连接时设置：

```bash
export KDB_HOST=127.0.0.1
export KDB_PORT=54321
export KDB_DBNAME=test
export KDB_USER=system
export KDB_PASSWORD='<数据库密码>'
export KDB_MODE=mysql

vendor/bin/kingbase-doctor
```

诊断输出不会主动打印数据库密码。
