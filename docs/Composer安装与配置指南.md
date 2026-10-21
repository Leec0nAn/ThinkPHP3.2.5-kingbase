# ThinkPHP 3.2.5 KingbaseES Composer安装与配置指南

## 1. 运行环境

使用本驱动包前，应满足以下条件：

- ThinkPHP 3.2.5；
- PHP 5.6；
- Composer 2.2 LTS；
- 已安装与KingbaseES版本、操作系统、CPU架构、PHP版本及线程安全模式相匹配的金仓官方PDO_KDB驱动。

金仓官方驱动可从以下地址获取：

[电科金仓接口驱动下载中心](https://www.kingbase.com.cn/download.html#drive)

本项目验证使用的驱动包为：

```text
KingbaseES_V009R001C010B0004_X64_Linux_PHP_NTS_5.6.tar
```

### 1.1 检查PHP运行环境

安装驱动前，先确认ThinkPHP 3.2.5项目使用的PHP版本、CPU架构、线程安全模式和扩展目录。

以下示例假设PHP 5.6的可执行文件位于`/usr/bin/php5.6`：

```
/usr/bin/php5.6 -v
uname -m
/usr/bin/php5.6 -i | grep 'Thread Safety'
/usr/bin/php5.6 -r 'echo ini_get("extension_dir"), PHP_EOL;'
```

如果PHP 5.6安装在其他位置，请将命令中的`/usr/bin/php5.6`替换为实际路径。可以使用以下命令查找：

```
which php5.6
```

各命令分别用于确认：

- `php5.6 -v`：查看PHP版本；
- `uname -m`：查看CPU架构，例如`x86_64`或`aarch64`；
- `Thread Safety`：查看PHP使用TS还是NTS模式；
- `extension_dir`：查看PHP扩展文件的安装目录。

线程安全模式与驱动包的对应关系如下：

- 输出`Thread Safety => disabled`时，应下载PHP 5.6 NTS驱动；
- 输出`Thread Safety => enabled`时，应下载PHP 5.6 TS驱动。

选择的PDO_KDB驱动必须同时与KingbaseES版本、操作系统、CPU架构、PHP版本和线程安全模式一致。

### 1.2 解压驱动包

以本项目验证使用的驱动包为例：

```bash
tar -xf KingbaseES_V009R001C010B0004_X64_Linux_PHP_NTS_5.6.tar
cd KingbaseES_V9_X64_Linux_PHP_NTS_5.6
```

解压后的目录中应包含：

```text
pdo_kdb.so
libkci.so.5
```

### 1.3 安装客户端动态库

如果系统已经安装匹配版本的KingbaseES客户端或服务端，并且以下命令能够找到`libkci.so.5`，无需覆盖现有文件：

```bash
ldconfig -p | grep 'libkci.so.5'
```

如果系统中尚未安装匹配的`libkci.so.5`，可以将驱动包附带的客户端库安装到独立目录：

```bash
sudo install -d /opt/kingbasees-v9/php5.6/lib
sudo install -m 0755 libkci.so.5 /opt/kingbasees-v9/php5.6/lib/libkci.so.5
echo '/opt/kingbasees-v9/php5.6/lib' | \
  sudo tee /etc/ld.so.conf.d/kingbasees-php56.conf
sudo ldconfig
```

再次确认动态链接器能够找到该文件：

```bash
ldconfig -p | grep 'libkci.so.5'
```

### 1.4 安装PDO_KDB扩展

读取当前PHP的扩展目录，并将`pdo_kdb.so`安装到该目录：

```bash
PHP56_BIN=/usr/bin/php5.6
PHP_EXT_DIR="$("$PHP56_BIN" -r 'echo ini_get("extension_dir");')"
printf 'PHP extension directory: %s\n' "$PHP_EXT_DIR"
sudo install -m 0644 pdo_kdb.so "$PHP_EXT_DIR/pdo_kdb.so"
```

在Debian或Ubuntu的多版本PHP环境中，可以创建扩展配置并启用：

```bash
echo 'extension=pdo_kdb.so' | \
  sudo tee /etc/php/5.6/mods-available/pdo_kdb.ini
sudo phpenmod -v 5.6 pdo_kdb
```

如果系统没有`phpenmod`命令，执行`"$PHP56_BIN" --ini`找到当前加载的`php.ini`，然后在该文件中加入：

```ini
extension=pdo_kdb.so
```

使用PHP-FPM或Apache运行应用时，还需要在对应SAPI的PHP配置中启用扩展，并重新启动相应服务。服务名称因安装方式而异，例如：

```bash
sudo systemctl restart php5.6-fpm
```

或者：

```bash
sudo systemctl restart apache2
```

只需重启实际使用的服务。如果应用仅通过PHP CLI运行，则无需重启Web服务。

### 1.5 验证安装结果

先检查扩展的动态库依赖：

```bash
PHP56_BIN=/usr/bin/php5.6
PHP_EXT_DIR="$("$PHP56_BIN" -r 'echo ini_get("extension_dir");')"
ldd "$PHP_EXT_DIR/pdo_kdb.so" | grep -E 'libkci|not found'
```

输出中不应出现`not found`。如果除`libkci.so.5`外仍有其他依赖缺失，应安装与当前驱动版本匹配的完整KingbaseES客户端库，不应混用其他KingbaseES版本的客户端文件。随后执行：

```bash
PHP56_BIN=/usr/bin/php5.6
"$PHP56_BIN" -m | grep -i pdo_kdb
"$PHP56_BIN" --ri pdo_kdb
"$PHP56_BIN" -r 'print_r(PDO::getAvailableDrivers());'
```

PDO驱动列表中应包含`kdb`。

## 2. 安装Composer包

### 从GitHub正式标签安装

安装正式版本：

```bash
composer require kingbase/thinkphp32-kingbase:^1.0
```

## 3. 加载Composer自动加载文件

传统ThinkPHP 3.2.5项目需要在应用入口文件中先加载Composer自动加载文件，再加载ThinkPHP框架：

```php
<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/ThinkPHP/ThinkPHP.php';
```

如果项目已经加载`vendor/autoload.php`，无需重复加载。

Composer通过本包的类映射配置加载`Think\Db\Driver\Kingbase`主驱动，以及三种兼容模式对应的方言和元数据处理类。

## 4. 配置KingbaseES连接

将KingbaseES连接配置合并到ThinkPHP 3.2.5应用的数据库配置文件中。常用配置文件位置为：

```text
Application/Common/Conf/config.php
```

配置示例：

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

    // 可选：auto、mysql、oracle或sqlserver。
    'DB_KDB_MODE'   => 'auto',

    // 可选；留空时使用数据库会话的默认Schema。
    'DB_SCHEMA'     => 'public',
);
```

主要配置项如下：

| 配置项 | 说明 |
| --- | --- |
| `DB_TYPE` | 固定填写`kingbase` |
| `DB_HOST` | KingbaseES服务器地址 |
| `DB_PORT` | KingbaseES服务端口 |
| `DB_NAME` | 数据库名称 |
| `DB_USER` | 数据库用户名 |
| `DB_PWD` | 数据库密码 |
| `DB_CHARSET` | 连接字符集 |
| `DB_PARAMS` | 传递给PDO连接的附加参数 |
| `DB_DEBUG` | 是否启用数据库调试 |
| `DB_KDB_MODE` | 可选的KingbaseES兼容模式 |
| `DB_SCHEMA` | 可选的数据库Schema |

配置完成后，ThinkPHP 3.2.5的Db工厂根据`DB_TYPE=kingbase`加载本包提供的`Think\Db\Driver\Kingbase`驱动。数据库连接在业务代码首次执行实际数据库操作时建立。

## 5. 配置兼容模式

本驱动支持KingbaseES的MySQL、Oracle和SQL Server三种兼容模式。

| KingbaseES兼容模式 | `DB_KDB_MODE`配置值 | 常用Schema示例 |
| --- | --- | --- |
| MySQL兼容模式 | `mysql` | `public` |
| Oracle兼容模式 | `oracle` | 当前用户对应的Schema |
| SQL Server兼容模式 | `sqlserver` | `dbo` |

### 5.1 自动识别

推荐配置：

```php
'DB_KDB_MODE' => 'auto',
```

也可以省略`DB_KDB_MODE`。驱动建立连接后读取服务端`database_mode`，并自动选择对应的SQL方言和元数据处理逻辑。

### 5.2 显式指定

应用也可以将`DB_KDB_MODE`设置为`mysql`、`oracle`或`sqlserver`。显式配置表示应用期望连接的兼容模式，不能用于切换数据库实例的实际模式。

驱动在正式连接和重连时校验服务端实际模式。如果配置模式与实际模式不一致，驱动会在业务SQL执行前抛出模式错配异常。

## 6. 配置Schema

可以通过`DB_SCHEMA`指定应用访问的Schema：

```php
'DB_SCHEMA' => 'public',
```

如果省略`DB_SCHEMA`或将其设置为空字符串，驱动使用数据库会话的默认Schema。

常见配置示例：

```php
// MySQL兼容模式
'DB_SCHEMA' => 'public',

// Oracle兼容模式
'DB_SCHEMA' => 'system',

// SQL Server兼容模式
'DB_SCHEMA' => 'dbo',
```

实际值应以目标数据表所在Schema为准。

## 7. 验证安装结果

安装本包后执行：

```bash
vendor/bin/kingbase-doctor
```

未提供数据库连接环境变量时，该命令检查PHP版本、PDO扩展和PDO_KDB加载状态。

如需验证真实数据库连接，可以设置：

```bash
export KDB_HOST=127.0.0.1
export KDB_PORT=54321
export KDB_DBNAME=test
export KDB_USER=system
export KDB_PASSWORD='<数据库密码>'
export KDB_MODE=mysql

vendor/bin/kingbase-doctor
```

诊断输出不会主动显示数据库密码。

还可以在应用中执行一个最小查询，确认ThinkPHP已经加载Kingbase驱动：

```php
$result = M()->query('SELECT 1 AS ok');
var_dump($result);
```

原生SQL必须符合当前KingbaseES兼容模式。

## 8. 常见问题

### 8.1 找不到PDO_KDB扩展

执行：

```bash
php -v
php --ini
php --ri pdo_kdb
```

检查驱动是否与PHP版本、CPU架构和TS/NTS模式一致，并确认`pdo_kdb.so`与`libkci.so.5`能够被PHP进程加载。

### 8.2 Composer提示缺少ext-pdo_kdb

确认执行Composer的PHP与运行ThinkPHP应用的PHP是同一个环境。系统存在多个PHP版本时，应使用对应的PHP 5.6和Composer 2.2 LTS执行安装。

### 8.3 找不到Kingbase驱动类

确认入口文件在加载ThinkPHP前执行：

```php
require __DIR__ . '/vendor/autoload.php';
```

然后重新生成Composer自动加载文件：

```bash
composer dump-autoload
```

### 8.4 提示兼容模式不匹配

检查`DB_KDB_MODE`是否与服务端实际兼容模式一致。如果不需要固定模式，可配置为`auto`。

### 8.5 查询不到数据表

检查`DB_SCHEMA`、数据表所在Schema、数据库用户权限和大小写敏感对象名称。
