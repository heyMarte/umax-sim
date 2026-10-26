<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/online_updates.php';
$base=__DIR__.'/../../../work/update-package-tests-'.bin2hex(random_bytes(5));mkdir($base,0700,true);$GLOBALS['online_test_root']=$base;mkdir($base.'/config',0700);
function ensure(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function releaseFixture(array $extra=[],bool $badHash=false,?string $extraZip=null,bool $symlink=false):string {
 $files=[];foreach(['src/version.php','src/bootstrap.php','src/migrations.php','public/index.php','public/update-guard.php','bin/console.php','bin/update-worker.php','bin/recover-update.php','src/online_updates.php'] as $name)$files[$name]=file_get_contents(__DIR__.'/../'.$name);
 $files=array_merge($files,$extra);$m=['format'=>1,'version'=>SIM_VERSION,'min_version'=>'0.4.2','notes'=>'test','files'=>[]];foreach($files as $n=>$c)$m['files'][$n]=['size'=>strlen($c),'sha256'=>hash('sha256',$c)];if($badHash)$m['files']['src/bootstrap.php']['sha256']=str_repeat('0',64);
 $file=$GLOBALS['online_test_root'].'/'.bin2hex(random_bytes(8)).'.zip';$z=new ZipArchive();$z->open($file,ZipArchive::CREATE);$z->addFromString('release.json',json_encode($m));foreach($files as $n=>$c)$z->addFromString($n,$c);if($extraZip)$z->addFromString($extraZip,'bad');if($symlink)$z->setExternalAttributesName('src/bootstrap.php',ZipArchive::OPSYS_UNIX,0120777<<16);$z->close();return $file;
}
function rejected(string $zip,string $label):void{try{onlineExtract($zip,bin2hex(random_bytes(16)));}catch(ApiError $e){ensure($e->status===422,$label.' status');return;}throw new RuntimeException('accepted '.$label);}
foreach(['../config.php','/tmp/pwn','src/../config/config.php','public/static/../../config/x.php','src/a\\b.php','config/keyring.php','public/static/payload.php','docs/../config/config.md',"src/evil\0.php"] as $path)ensure(!onlinePathAllowed($path),'path '.$path);
$valid=releaseFixture();$id=bin2hex(random_bytes(16));$m=onlineExtract($valid,$id);ensure($m['version']===SIM_VERSION,'valid manifest');ensure(is_file(onlineJobDir($id).'/files/src/version.php'),'staged only');ensure(!is_file($base.'/src/version.php'),'site untouched by staging');
rejected(releaseFixture(['../escape.php'=>'bad']),'zip traversal');rejected(releaseFixture(['config/config.php'=>'bad']),'config overwrite');rejected(releaseFixture([],true),'tampered hash');rejected(releaseFixture([],false,'hidden.php'),'undeclared file');rejected(releaseFixture([],false,null,true),'symlink');
$target=$base.'/src';mkdir($target);symlink($base.'/config',$target.'/link');try{onlineSafeTarget($base,'src/link/pwn.php');throw new RuntimeException('symlink target accepted');}catch(RuntimeException $e){ensure($e->getMessage()!=='symlink target accepted','target path symlink rejected');}
try{onlineAdmin(['role'=>'operator']);throw new RuntimeException('operator accepted');}catch(ApiError $e){ensure($e->status===403,'strict admin');}
echo "PASS update package traversal/config protection/hash/unlisted files/symlink rejection, staging-only writes, strict admin\n";
