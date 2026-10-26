<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/online_updates.php';
$allowed=ini_get('open_basedir');if($allowed===''||str_contains($allowed,'/dev'))throw new RuntimeException('Test requires restrictive open_basedir excluding /dev');
$dir=dirname(__DIR__).'/var/openbasedir-test';if(!is_dir($dir))mkdir($dir,0700,true);$log=$dir.'/'.bin2hex(random_bytes(8)).'.log';
set_error_handler(function($severity,$message){throw new RuntimeException($message);});
try{if(!onlineSpawn('/bin/sh','-c','printf SPAWN_OK',$log,dirname(__DIR__)))throw new RuntimeException('spawn failed');$deadline=microtime(true)+3;while((!is_file($log)||file_get_contents($log)!=='SPAWN_OK')&&microtime(true)<$deadline)usleep(50000);if(!is_file($log)||file_get_contents($log)!=='SPAWN_OK')throw new RuntimeException('child did not execute');echo "PASS background launch under restricted open_basedir without allowing /dev/null\n";}finally{restore_error_handler();@unlink($log);}
