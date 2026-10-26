<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/migrations.php';
try {
 $action=$argv[1]??'';
 if($action==='install') {
  $sql=file_get_contents(dirname(__DIR__).'/database/schema.sql');
  foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')db()->exec($statement);
  migrate();
  echo "表结构已就绪。请执行 create-admin。\n";
 } elseif($action==='health') {if(isset($argv[2])&&!hash_equals(SIM_VERSION,$argv[2]))throw new RuntimeException('程序版本校验失败');if(!query('SELECT version FROM schema_versions WHERE version=?',[SIM_VERSION]))throw new RuntimeException('数据库版本校验失败');masterKey();echo SIM_VERSION." healthy\n";
 } elseif($action==='migrate') {migrate();echo "升级完成（".SIM_VERSION."），请妥善备份config/keyring.php。\n";
 } elseif($action==='create-admin') {
  $name=getenv('SIM_ADMIN_USER');$pw=getenv('SIM_ADMIN_PASSWORD');
  if(!$name||!$pw)throw new RuntimeException('请使用 bash bin/create-admin.sh PHP路径 交互创建管理员');
  createUser($name,$pw,'admin');echo "管理员已创建。\n";
 } elseif($action==='cleanup') {clean();echo "清理完成。\n";}
 else {echo "用法: php bin/console.php install|migrate|create-admin|cleanup\n";exit(1);}
} catch(ApiError $e) {fwrite(STDERR,$e->getMessage()."\n");exit(1);}
catch(Throwable $e) {fwrite(STDERR,"执行失败：请检查数据库配置、PHP 扩展；重复账户无法再次创建。\n");exit(1);}
