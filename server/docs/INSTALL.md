# 安装说明

本文对应0.7.0，源码布局为server/与android/。先下载发布包，或从维护者仓库取得指定版本；GitHub主仓库计划地址为https://github.com/heyMarte/umax-sim，发布状态以仓库页面为准。

以下使用`sim.example.com`与`/www/wwwroot/umax-sim/server`作为示例，替换为自己的域名及实际部署目录。

## 1. 准备环境

使用Linux服务器，安装Nginx、PHP8.2及MySQL8.0/MariaDB10.6+。已有生产数据库不要为本项目重新安装或覆盖。

PHP网站与CLI均需检查PDO、pdo_mysql、mbstring、openssl。App发布、专用更新包需zip扩展；微信推送与Outlook邮箱收取需curl；项目备份使用Phar/tar支持。基础后台不需要打开exec或shell_exec。

后台在线升级还需要可执行的PHP CLI，以及proc_open、proc_close、proc_get_status和proc_terminate。若不启用在线升级，可采用命令行手动维护，不必为基础收码开启这些进程函数。

宝塔PHP8.2示例：

```bash
/www/server/php/82/bin/php -v
/www/server/php/82/bin/php -m
```

CLI扩展与网站PHP扩展可能不同，两边都要确认。

## 2. 域名、站点与数据库

1. 为域名配置正确的A记录；如不使用IPv6，不要保留指向其他服务器的AAAA记录。
2. 放行HTTP80和HTTPS443；数据库仅向必要的本机或私有网络开放。
3. 在宝塔创建PHP8.2站点及独立的utf8mb4数据库，记录数据库名、用户名和密码。
4. 上传正式发行的源码包到项目目录。若从Git仓库取源码，部署指定release/tag；不要把工作目录、数据库备份和历史APK全部上传到网站。
5. 站点公开根目录设为`/www/wwwroot/umax-sim/server/public`。站点可访问的项目目录须包含server根目录，以便PHP读取src与config；保留站点的open_basedir隔离；所需的PHP CLI真实路径和私有备份目录也要加入本站允许范围，见下节。

## 3. 填写配置并设置权限

复制`server/config/config.example.php`为`server/config/config.php`，填写：

```php
<?php
return [
    'php_cli' => '/www/server/php/82/bin/php',
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'umax_sim',
    'db_user' => 'umax_sim',
    'db_password' => '替换为数据库密码',
    'secure_cookie' => true,
    'backup_dir' => '/var/lib/umax-sim-backups',
];
```

PHP单引号字符串内的单引号、反斜线需正确转义。配置不填写服务器面板密码。

以下假设PHP-FPM以www用户运行；其他环境替换为实际用户：

```bash
install -d -o www -g www -m 700 /var/lib/umax-sim-backups
install -d -o www -g www -m 700 /www/wwwroot/umax-sim/server/var
chown www:www /www/wwwroot/umax-sim/server/config
chown www:www /www/wwwroot/umax-sim/server/config/config.php
chmod 750 /www/wwwroot/umax-sim/server/config
chmod 640 /www/wwwroot/umax-sim/server/config/config.php
```

目录不设置为777。在线升级会改写程序目录，仅在启用此功能时按后台环境检查授予必要的站点代码写权限，并保留上线前备份。

### 宝塔open_basedir允许范围

如果使用上述CLI及备份路径，本站PHP允许范围除server根目录和系统临时目录外，还需包含PHP8.2真实安装目录和备份目录，例如：

```ini
open_basedir=/www/wwwroot/umax-sim/server/:/tmp/:/www/server/php/82/:/var/lib/umax-sim-backups/
```

在宝塔对**此站点**维护生效的防跨站/.user.ini或PHP-FPM站点配置，不全局关闭隔离。若CLI是符号链接，加入`readlink -f /www/server/php/82/bin/php`所指向的实际安装目录。路径都替换为本站实际值，保存并重新加载生效配置。

只允许server目录时，后台的is_file(php_cli)及备份状态检查会被open_basedir阻止，即使命令行可正常执行也会显示升级环境失败。也可把私有备份目录放到server/var/backups下（仍在public之外），这样无需额外放行/var/lib路径；修改backup_dir后同步调整计划任务。

## 4. 初始化并创建管理员

在项目server目录执行：

```bash
cd /www/wwwroot/umax-sim/server
/www/server/php/82/bin/php bin/console.php install
bash bin/create-admin.sh /www/server/php/82/bin/php
/www/server/php/82/bin/php bin/console.php health
chown www:www config/keyring.php
chmod 640 config/keyring.php
```

`install`用于新站点，管理员脚本会交互询问账户及密码。没有默认密码。已有数据的站点应执行迁移流程，不能删除数据库重装。

`config/keyring.php`由安装流程创建，用于解密已有密钥。它必须和数据库一起备份。若原密钥文件丢失，恢复原文件，不要手动生成替代文件。

## 5. 配置Nginx及上传限制

在宝塔站点“伪静态”中配置路由；若已存在同一个location，编辑原块，不重复添加：

```nginx
location / {
    try_files $uri /index.php$is_args$args;
}
```

使用宝塔生成的PHP8.2处理规则。普通Nginx/PHP-FPM部署也需要正常执行index.php，并把Authorization请求头传到PHP。若当前FastCGI配置未传递，补充：

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

这行属于PHP的FastCGI location，不单独放在server之外。不要直接复制别人的整段server覆盖现有证书与PHP配置。

本项目发布包应用层限制为64MiB。为multipart表单预留空间，可配置：

```nginx
client_max_body_size 80m;
```

网站PHP配置示例：

```ini
upload_max_filesize = 64M
post_max_size = 80M
```

修改后重载网站PHP/Nginx并检查配置。反向代理或CDN若另有限制，也需相容；只有上传包时才需要较大的请求体，普通JSON接口仍有独立限制。

## 6. HTTPS与首次登录

申请并部署域名HTTPS证书，开启强制HTTPS。手机扫描、设备接口和后台登录均使用自己的域名，不使用示例域名。生产环境保持`secure_cookie=true`。

访问`https://sim.example.com/health`检查服务，再访问根路径登录。完成创建账户、录入一张手机卡、接入一台手机后，再批量使用。

## 7. 必要运维

按[备份与更新](OPERATIONS.md)设置短信清理和站点备份。手机操作见[Android接入](ANDROID.md)。微信推送是可选项，需额外按[微信配置](WECHAT.md)配置worker。

## 常见故障

| 现象 | 排查 |
| --- | --- |
| 页面或API404 | Nginx根目录是否为public；try_files是否生效 |
| could not find driver | 网站与CLI的pdo_mysql扩展 |
| 数据库不可用 | 实际数据库账户、连接、schema及migrate结果 |
| HTTPS登录后反复退出 | 证书、Cookie、是否混用HTTP与HTTPS |
| 上传失败/413 | Nginx、PHP、上游代理三处限制和磁盘空间 |
| 手机401 | 后台域名、接入码、设备是否停用、Authorization是否传入PHP |
| 升级进程未启动 | php_cli路径、进程函数、目录权限、open_basedir及后台升级日志 |
| /a提示尚未发布App | 管理员需先在App独立发布中上传并确认APK |

提交故障信息时隐藏密码、接入码、二维码、密钥和真实短信内容。
