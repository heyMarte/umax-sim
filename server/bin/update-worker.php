<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(($argv[1]??'')==='recover'){$rid=$argv[2]??'';if(!preg_match('/^[a-f0-9]{32}$/D',$rid)){fwrite(STDERR,"任务编号无效\n");exit(1);}require dirname(__DIR__).'/var/online-updates/'.$rid.'/recover.php';exit;}
require_once dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/online_updates.php';
require_once dirname(__DIR__).'/src/backup.php';
function updateStep(array &$job,string $phase):void{$job['phase']=$phase;onlineSave(onlineJobDir($job['id']).'/job.json',$job);}
function updateCliCommand(array $arguments,string $log,int $seconds=180):void {
    $p=proc_open($arguments,[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,onlineRoot());if(!is_resource($p))throw new RuntimeException('无法启动 PHP CLI');$deadline=microtime(true)+$seconds;$exit=null;
    do{$status=proc_get_status($p);if(!$status['running']){$exit=$status['exitcode'];break;}if(microtime(true)>$deadline){proc_terminate($p);proc_close($p);throw new RuntimeException('数据库升级超时，请检查私有升级日志');}usleep(100000);}while(true);
    $closed=proc_close($p);if(($exit??$closed)!==0)throw new RuntimeException('升级命令失败，请检查私有升级日志');
}
function updateRollback(string $dir):void {
    $snapshot=onlineJson($dir.'/snapshot.json');if(!$snapshot)return;
    foreach($snapshot as $relative=>$meta){$target=onlineSafeTarget(onlineRoot(),$relative);if($meta['existed']){onlineWrite($target,file_get_contents($dir.'/old/'.$relative));chmod($target,$meta['mode']);}elseif(is_file($target)&&!is_link($target)){if(!unlink($target))throw new RuntimeException('无法移除新增文件');}}
    onlineSave(onlineRoot().'/config/.online-update-invalidate.json',array_keys($snapshot));
}
function updateWorker(string $id,bool $recover=false):void {
    $dir=onlineJobDir($id);$job=onlineJson($dir.'/job.json');if(!$job)throw new RuntimeException('更新任务不存在');
    $claim=fopen($dir.'/claim.lock','ce');if(!$claim||!flock($claim,LOCK_EX|LOCK_NB))throw new RuntimeException('此任务仍在执行');
    $site=fopen(onlineLockPath(),'ce');if(!$site)throw new RuntimeException('无法打开维护锁');$lockDeadline=microtime(true)+30;
    while(!flock($site,LOCK_EX|LOCK_NB)){if(microtime(true)>$lockDeadline){$job['status']='failed';$job['phase']='未启动';$job['summary']='请求未能在30秒内排空，未修改站点，请稍后重试。';$job['finished_at']=microtime(true);onlineSave($dir.'/job.json',$job);throw new RuntimeException($job['summary']);}usleep(100000);}
    $job=onlineJson($dir.'/job.json');$maintenance=onlineRoot().'/config/.online-update-maintenance.json';$mutated=false;$migration=$recover;$done=false;
    register_shutdown_function(function()use(&$done,&$job,$dir){if(!$done){$job['status']='interrupted';$job['summary']='升级进程意外结束。请在宝塔执行教程中的恢复命令；未确认恢复前保持维护。';$job['finished_at']=microtime(true);@onlineSave($dir.'/job.json',$job);}});
    try {
        if($recover){
            $job['status']='running';updateStep($job,'恢复旧文件');updateRollback($dir);
            updateCliCommand([onlineCli(),onlineRoot().'/bin/console.php','health'],$dir.'/recover.log');
            @unlink($maintenance);$job['status']='failed';$job['phase']='已恢复旧文件';$job['summary']='已恢复旧程序并通过数据库基本检查。数据库未被自动覆盖，如迁移已改变数据请从备份核对恢复。';$job['finished_at']=microtime(true);onlineSave($dir.'/job.json',$job);$done=true;return;
        }
        if($job['status']!=='queued')throw new RuntimeException('任务不处于待执行状态');
        onlineSave($dir.'/recovery.json',['root'=>onlineRoot(),'cli'=>onlineCli()]);onlineWrite($dir.'/recover.php',file_get_contents(onlineRoot().'/bin/recover-update.php'));if(!is_file($dir.'/snapshot.json'))onlineSave($dir.'/snapshot.json',[]);onlineSave($maintenance,['id'=>$id,'started_at'=>microtime(true)]);$job['status']='running';updateStep($job,'备份数据库与密钥');
        $backup=backupRun();$job['backup_file']=$backup['file'];onlineSave($dir.'/job.json',$job);
        $m=onlineJson($dir.'/release.json');if(!$m)throw new RuntimeException('更新清单丢失');
        updateStep($job,'校验并备份旧程序');$snapshot=[];
        foreach($m['files'] as $relative=>$meta){$target=onlineSafeTarget(onlineRoot(),$relative);$source=$dir.'/files/'.$relative;if(!is_file($source)||!hash_equals($meta['sha256'],hash_file('sha256',$source)))throw new RuntimeException('暂存更新文件发生变化');if(file_exists($target)&&!is_file($target))throw new RuntimeException('更新目标不是普通文件');$snapshot[$relative]=['existed'=>is_file($target),'mode'=>is_file($target)?fileperms($target)&0777:0600];if(is_file($target)){$old=$dir.'/old/'.$relative;if(!is_dir(dirname($old)))mkdir(dirname($old),0700,true);onlineWrite($old,file_get_contents($target));}}
        onlineSave($dir.'/snapshot.json',$snapshot);onlineSave($dir.'/recovery.json',['root'=>onlineRoot(),'cli'=>onlineCli()]);onlineWrite($dir.'/recover.php',file_get_contents(onlineRoot().'/bin/recover-update.php'));updateStep($job,'应用程序文件');$mutated=true;
        foreach($m['files'] as $relative=>$meta){$target=onlineSafeTarget(onlineRoot(),$relative);if(!is_dir(dirname($target)))mkdir(dirname($target),0755,true);onlineWrite($target,file_get_contents($dir.'/files/'.$relative));chmod($target,str_ends_with($relative,'.sh')?0700:0644);}
        updateStep($job,'升级数据库');$migration=true;updateCliCommand([onlineCli(),onlineRoot().'/bin/console.php','migrate'],$dir.'/migration.log');
        updateStep($job,'检查升级结果');updateCliCommand([onlineCli(),onlineRoot().'/bin/console.php','health',$m['version']],$dir.'/migration.log');
        onlineSave(onlineRoot().'/config/.online-update-invalidate.json',array_keys($m['files']));
        $job['status']='success';$job['phase']='升级完成';$job['summary']='后台已升级到 '.$m['version'].'，请强制刷新页面。App 需在更新页单独发布。';$job['finished_at']=microtime(true);onlineSave($dir.'/job.json',$job);@unlink($maintenance);$done=true;
        try{audit($job['actor'],'system_update_success',$id,['object_type'=>'operation','object_name'=>'后台 '.$m['version'],'summary'=>'后台升级完成，已创建数据库及密钥备份 '.$job['backup_file']]);}catch(Throwable $ignore){}
    }catch(Throwable $e){
        $restored=true;if($mutated)try{updateRollback($dir);}catch(Throwable $ignored){$restored=false;}
        $job['status']=($restored&&!$migration)?'failed':'interrupted';$job['phase']=$restored?'旧文件已恢复':'需要恢复';$job['summary']=($migration?'数据库升级或结果检查失败，已尝试恢复旧文件；请检查私有日志及备份，并执行恢复命令。':($restored?'升级未完成，网站保持原版本，请检查环境和私有升级日志。':'文件恢复失败，请按教程恢复。'));$job['finished_at']=microtime(true);onlineSave($dir.'/job.json',$job);if($restored&&!$migration)@unlink($maintenance);$done=true;
        fwrite(STDERR,$e->getMessage()."\n");
        try{audit($job['actor'],'system_update_failed',$id,['object_type'=>'operation','object_name'=>'后台升级','status'=>'failed','summary'=>$job['summary']]);}catch(Throwable $ignore){}
        throw $e;
    }finally{flock($site,LOCK_UN);fclose($site);flock($claim,LOCK_UN);fclose($claim);}
}
try{$recover=($argv[1]??'')==='recover';$id=$argv[$recover?2:1]??'';updateWorker($id,$recover);echo "更新任务结束，请查看后台状态。\n";}catch(Throwable $e){fwrite(STDERR,"更新未完成：".$e->getMessage()."\n");exit(1);}
