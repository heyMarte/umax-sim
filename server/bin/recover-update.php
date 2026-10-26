<?php
/** A copy lives beside each snapshot; no mutable application includes. CLI only. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$recoveryDir=__DIR__;$job=null;$site=null;$claim=null;$locked=false;
function recoveryJson(string $p):array{$v=json_decode((string)file_get_contents($p),true,32,JSON_THROW_ON_ERROR);if(!is_array($v))throw new RuntimeException('恢复数据无效');return $v;}
function recoveryWrite(string $p,string $bytes):void{$tmp=$p.'.recovery-tmp';if(is_link($tmp))throw new RuntimeException('恢复路径异常');if(file_put_contents($tmp,$bytes)!==strlen($bytes)||!rename($tmp,$p))throw new RuntimeException('恢复文件失败');}
try{
 $cfg=recoveryJson($recoveryDir.'/recovery.json');$root=realpath($cfg['root']);if(!$root||realpath($root.'/var/online-updates/'.basename($recoveryDir))!==realpath($recoveryDir))throw new RuntimeException('恢复目录不匹配');
 $job=recoveryJson($recoveryDir.'/job.json');$claim=fopen($recoveryDir.'/claim.lock','ce');$site=fopen($root.'/config/.online-update.lock','ce');if(!flock($claim,LOCK_EX|LOCK_NB)||!flock($site,LOCK_EX|LOCK_NB))throw new RuntimeException('更新仍在运行，请稍后恢复');$locked=true;
 $maintenance=$root.'/config/.online-update-maintenance.json';recoveryWrite($maintenance,json_encode(['id'=>$job['id']]));
 $snapshot=recoveryJson($recoveryDir.'/snapshot.json');
 foreach($snapshot as $relative=>$meta){
  if(!is_string($relative)||str_contains($relative,'..')||str_contains($relative,'\\')||str_contains($relative,':')||!preg_match('#^(src/|bin/|public/|docs/|README\.md$)#D',$relative))throw new RuntimeException('恢复路径无效');
  $target=$root.'/'.$relative;$check=$target;while($check!==$root){if(is_link($check))throw new RuntimeException('恢复目标为符号链接');$check=dirname($check);}
  if($meta['existed']){$bytes=file_get_contents($recoveryDir.'/old/'.$relative);if($bytes===false)throw new RuntimeException('旧文件缺失');recoveryWrite($target,$bytes);chmod($target,$meta['mode']);}elseif(is_file($target)&&!unlink($target))throw new RuntimeException('移除新文件失败');
 }
 recoveryWrite($root.'/config/.online-update-invalidate.json',json_encode(array_keys($snapshot)));
 $p=proc_open([$cfg['cli'],$root.'/bin/console.php','health'],[0=>['file','/dev/null','r'],1=>['file',$recoveryDir.'/recover.log','a'],2=>['file',$recoveryDir.'/recover.log','a']],$pipes,$root);if(!is_resource($p))throw new RuntimeException('无法运行恢复检查');$deadline=microtime(true)+60;
 do{$state=proc_get_status($p);if(!$state['running'])break;if(microtime(true)>$deadline){proc_terminate($p);throw new RuntimeException('恢复检查超时');}usleep(100000);}while(true);proc_close($p);if($state['exitcode']!==0)throw new RuntimeException('旧程序数据库检查失败，请保留维护状态并检查备份和恢复日志');
 $job['status']='failed';$job['phase']='旧文件已恢复';$job['summary']='旧程序已恢复并通过基本检查；数据库未被自动覆盖，请核对迁移及备份。';$job['finished_at']=microtime(true);recoveryWrite($recoveryDir.'/job.json',json_encode($job,JSON_UNESCAPED_UNICODE));unlink($maintenance);echo "旧程序已恢复，数据库未自动覆盖。\n";
}catch(Throwable $e){if($job&&$locked){$job['status']='interrupted';$job['summary']='恢复未通过，保持维护状态，请检查恢复日志和数据库备份。';@recoveryWrite($recoveryDir.'/job.json',json_encode($job,JSON_UNESCAPED_UNICODE));}fwrite(STDERR,$e->getMessage()."\n");exit(1);}
