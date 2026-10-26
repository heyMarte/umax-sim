# 备份、维护与更新

本文对应0.6.0；本地干净安装与迁移验证见VALIDATION.md。

## 备份内容

数据库、`config/config.php`和`config/keyring.php`属于同一套恢复资料。数据库中有加密密钥，只有数据库而丢失原keyring不能恢复这些密文。

备份目录必须在public之外，只有服务运行用户及必要管理员可访问。备份中可能包含短信、账户哈希与连接配置，不提交到Git仓库或公开Release。

在server目录执行：

```bash
/www/server/php/82/bin/php bin/backup.php
```

读取命令返回的实际备份文件，在命令行验证：

```bash
/www/server/php/82/bin/php bin/backup.php verify /私有目录/实际备份文件.tar
```

宝塔计划任务示例，每天执行一次：

```bash
cd /www/wwwroot/umax-sim/server || exit 1
/www/server/php/82/bin/php bin/backup.php
```

使用与网站相容的运行身份。确认计划任务确实执行、备份文件可校验，并单独保存离机副本；后台出现一次成功记录不等于计划任务已永久生效。保留周期自行设置，不能无限累积敏感数据。

## 清理短信

现有查询逻辑隐藏收到超过7天的短信；实际清理仍需执行任务。每小时执行：

```bash
cd /www/wwwroot/umax-sim/server || exit 1
/www/server/php/82/bin/php bin/console.php cleanup
```

使用备份时也应考虑备份自身的数据保留期限。

## 后台更新

更新前备份数据库和配置，确认目标版本的升级说明。源码ZIP与专用在线发布ZIP用途不同，GitHub/Gitee自动生成的源码ZIP不能直接当作专用release.json更新包。

- 在线：仅上传本项目构建且包含release.json的专用后台发布包，等待备份、升级和最终结果。
- 手动：按release说明覆盖程序代码，保留config.php、keyring.php、var及私有备份，执行migrate与health。

```bash
cd /www/wwwroot/umax-sim/server
/www/server/php/82/bin/php bin/console.php migrate
/www/server/php/82/bin/php bin/console.php health
```

不要将新站点install、删除表或重新生成keyring当作升级步骤。更新过程异常时按对应版本的恢复文档处理，避免多个升级worker并发执行。

## App独立发布

管理员进入「系统更新 → App独立发布」，上传APK、识别版本，再确认发布。后台版本无需随App一起变化。发布前核对包名、签名、递增versionCode和版本说明；服务器识别元数据，手机还会校验签名与完整性。

发布后本站`/a`提供新版本下载，设备更新接口也提供同一发布版本。下载链接不固定指向开发者的服务器。

## 恢复演练

现有备份包需在隔离环境恢复并验证，不直接覆盖正在使用的生产库。完整恢复包括数据库、原config与keyring、适配的代码版本；启用原手机前核对设备授权和归属。

微信worker在检测到代码更新后可能退出，需要由服务管理器重新拉起；更新后检查运行时间和队列，不只看进程名。
