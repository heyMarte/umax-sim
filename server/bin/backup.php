<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/backup.php';
try {
    if(($argv[1]??'')==='verify' && isset($argv[2])) {backupVerify($argv[2]);echo "备份完整性校验通过（含数据库、配置和主密钥）。\n";}
    elseif(count($argv)===1){echo json_encode(backupRun(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";}
    else {fwrite(STDERR,"用法：php bin/backup.php [verify 备份绝对路径]\n");exit(1);}
} catch(Throwable $e){fwrite(STDERR,"备份操作失败，请检查目录权限、磁盘、PHP Phar扩展、数据库及备份文件完整性。\n");exit(1);}
