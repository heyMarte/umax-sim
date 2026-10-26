#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/bootstrap.php';
if(count($argv)!==2||!in_array($argv[1],['--once','--loop'],true)){fwrite(STDERR,"Usage: php bin/wechat-worker.php --once|--loop\n");exit(2);}
$loop=$argv[1]==='--loop';
try{if($loop&&!wxLock('daemon')){echo "WeChat worker already running.\n";exit(0);}}catch(Throwable){fwrite(STDERR,"WeChat worker unavailable; check database configuration.\n");exit(1);}
$sourceSignature=static fn()=>hash_file('sha256',__DIR__.'/../src/wechat.php').hash_file('sha256',__DIR__.'/../src/version.php').hash_file('sha256',__FILE__);
$startedSource=$sourceSignature();$first=true;
do{
 try{$r=wxRun();if(!$loop||$first||$r['processed']>0)echo json_encode(['at'=>gmdate('c')]+$r,JSON_THROW_ON_ERROR)."\n";$first=false;}
 catch(Throwable){fwrite(STDERR,"WeChat worker unavailable; check database/keyring configuration and restart.\n");exit(1);}
 if($loop){sleep(3);if($sourceSignature()!==$startedSource){echo "Application updated; restart WeChat worker.\n";break;}}
}while($loop);
