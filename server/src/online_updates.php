<?php
declare(strict_types=1);
const ONLINE_MAX_BYTES=67108864;
function onlineRoot():string{return $GLOBALS['online_test_root']??dirname(__DIR__);}
function onlineDir():string {
    $root=onlineRoot();$dir=$root.'/var/online-updates';
    foreach([$root.'/var',$dir] as $path){if(is_link($path))throw new RuntimeException('更新目录不允许符号链接');if(!is_dir($path)&&!mkdir($path,0700,true))throw new RuntimeException('无法创建更新目录，请设置网站目录写权限');}
    return $dir;
}
function onlineWrite(string $file,string $bytes):void {
    $temp=$file.'.'.bin2hex(random_bytes(6)).'.tmp';
    if(file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes)){@unlink($temp);throw new RuntimeException('更新文件写入失败，请检查磁盘空间');}
    chmod($temp,0600);if(!rename($temp,$file)){@unlink($temp);throw new RuntimeException('更新文件保存失败');}
}
function onlineJson(string $file):?array {if(!is_file($file)||is_link($file))return null;try{$v=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);return is_array($v)?$v:null;}catch(Throwable $e){return null;}}
function onlineSave(string $file,array $v):void{onlineWrite($file,json_encode($v,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));}
function onlineAdmin(array $u):void{if($u['role']!=='admin')fail(403,'只有管理员可以管理系统更新');}
function onlineCli():string{return getenv('SIM_PHP_CLI')?:settings()['php_cli']??'/www/server/php/82/bin/php';}
function onlineLockPath():string{return onlineRoot().'/config/.online-update.lock';}
function onlineJobDir(string $id):string{if(!preg_match('/^[a-f0-9]{32}$/D',$id))fail(422,'更新编号无效');return onlineDir().'/'.$id;}
function onlineGate():mixed {
    $file=onlineDir().'/queue.lock';if(is_link($file))throw new RuntimeException('更新锁无效');$h=fopen($file,'ce');if(!$h||!flock($h,LOCK_EX|LOCK_NB))fail(409,'已有更新操作正在执行，请稍后重试');chmod($file,0600);return $h;
}
function onlineLatest():?array{$id=onlineJson(onlineDir().'/latest.json')['id']??null;return is_string($id)?onlineJson(onlineJobDir($id).'/job.json'):null;}
function onlineBusy():void{$job=onlineLatest();if($job&&in_array($job['status'],['queued','running','interrupted'],true))fail(409,'更新任务尚未结束，请先查看任务状态；中断任务需按教程恢复');}
function onlinePathAllowed(string $name):bool {
    if($name===''||str_contains($name,"\0")||str_contains($name,'\\')||str_contains($name,':')||str_starts_with($name,'/')||in_array('..',explode('/',$name),true)||in_array('.',explode('/',$name),true)||str_contains($name,'//'))return false;
    if($name==='README.md')return true;
    if(preg_match('#^src/[A-Za-z0-9_/-]+\.php$#D',$name)||preg_match('#^bin/[A-Za-z0-9_/-]+\.(php|sh)$#D',$name))return true;
    if(in_array($name,['public/index.php','public/update-guard.php'],true))return true;
    if(preg_match('#^public/static/[A-Za-z0-9_./-]+\.(js|css|html|svg|png|woff2?)$#D',$name))return true;
    return str_starts_with($name,'docs/')&&str_ends_with($name,'.md')&&!str_contains($name,'/.');
}
function onlineSafeTarget(string $root,string $relative):string {
    if(!onlinePathAllowed($relative))throw new RuntimeException('更新包含禁止覆盖的路径');
    $target=$root.'/'.$relative;$check=$target;
    while($check!==$root){if(is_link($check))throw new RuntimeException('目标路径含符号链接');$check=dirname($check);}
    return $target;
}
function onlineRequirements():array {
    $root=onlineRoot();$cli=onlineCli();
    $checks=[['label'=>'ZIP 扩展','ok'=>class_exists('ZipArchive'),'detail'=>'宝塔 PHP 8.2 扩展中安装 zip'],['label'=>'PHP CLI','ok'=>is_file($cli)&&is_executable($cli),'detail'=>'配置 php_cli 指向宝塔 PHP 8.2 CLI'],['label'=>'升级进程','ok'=>function_exists('proc_open')&&function_exists('proc_close')&&function_exists('proc_get_status')&&function_exists('proc_terminate'),'detail'=>'PHP 禁用函数中移除 proc_open、proc_close、proc_get_status、proc_terminate'],['label'=>'站点目录写权限','ok'=>true,'detail'=>'网站运行用户需能写 public、src、bin、docs、config 和项目根目录']];
    foreach(['','public','src','bin','docs','config'] as $p)if(!is_dir($root.($p?'/'.$p:''))||!is_writable($root.($p?'/'.$p:'')))$checks[3]['ok']=false;
    try{require_once __DIR__.'/backup.php';$b=backupDirectory(true);$checks[]=['label'=>'数据库备份目录','ok'=>is_writable($b),'detail'=>'需位于 public 外，网站用户和 CLI 均可读写'];}catch(Throwable $e){$checks[]=['label'=>'数据库备份目录','ok'=>false,'detail'=>'请按备份教程配置 public 外的 backup_dir，并授予网站用户写权限'];}
    return $checks;
}
function onlineStageSummary(array $m,string $id):array{return ['id'=>$id,'version'=>$m['version'],'notes'=>$m['notes'],'backend_available'=>version_compare($m['version'],SIM_VERSION,'>'),'files_count'=>count($m['files']),'app'=>isset($m['app'])?array_intersect_key($m['app'],array_flip(['version_code','version_name','size'])):null];}
function onlineStatus():array {
    $dir=onlineDir();$stageId=onlineJson($dir.'/staged.json')['id']??null;$m=is_string($stageId)?onlineJson(onlineJobDir($stageId).'/release.json'):null;
    $job=onlineLatest();if($job&&$job['status']==='queued'&&microtime(true)-(float)$job['started_at']>120){$job['summary']='任务仍在等待启动。请检查 PHP CLI/禁用函数，或执行教程中的恢复命令。';}
    $req=onlineRequirements();require_once __DIR__.'/app_release.php';return ['app_staged'=>($appStaged=onlineJson($dir.'/app-staged.json'))?appStagedSummary($appStaged):null,'share_path'=>appSharePath(),'current_version'=>SIM_VERSION,'requirements'=>$req,'ready'=>!in_array(false,array_column($req,'ok'),true),'staged'=>$m?onlineStageSummary($m,$stageId):null,'job'=>$job,'published_app'=>onlineJson($dir.'/app.json')];
}
function onlineExtract(string $zipPath,string $id):array {
    if(!class_exists('ZipArchive'))fail(422,'请先在宝塔 PHP 8.2 中安装 zip 扩展');
    $z=new ZipArchive();if($z->open($zipPath)!==true)fail(422,'不是有效的 ZIP 更新包');
    try {
        if($z->numFiles<2||$z->numFiles>1024)fail(422,'更新包文件数量无效');
        $seen=[];$total=0;
        for($i=0;$i<$z->numFiles;$i++){$s=$z->statIndex($i);$name=$s['name'];if(isset($seen[$name]))fail(422,'更新包包含重复路径');$seen[$name]=true;$total+=$s['size'];if($total>ONLINE_MAX_BYTES||$s['size']>ONLINE_MAX_BYTES)fail(422,'解压后更新包过大');$z->getExternalAttributesIndex($i,$os,$attrs);$type=($attrs>>16)&0170000;if($type!==0&&$type!==0100000)fail(422,'更新包不允许目录或符号链接');}
        $raw=$z->getFromName('release.json');if($raw===false||strlen($raw)>1048576)fail(422,'缺少专用 release.json 清单，请使用专用在线更新包');
        try{$m=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){fail(422,'更新清单格式错误');}
        if(!is_array($m)||($m['format']??null)!==1||!is_string($m['version']??null)||!preg_match('/^\d+\.\d+\.\d+$/D',$m['version'])||!is_string($m['min_version']??null)||!preg_match('/^\d+\.\d+\.\d+$/D',$m['min_version'])||!is_string($m['notes']??null)||mb_strlen($m['notes'])>5000||!is_array($m['files']??null)||array_is_list($m['files'])||!$m['files'])fail(422,'更新清单字段无效');
        if(version_compare(SIM_VERSION,$m['min_version'],'<'))fail(422,'当前版本过旧，请先更新到 '.$m['min_version']);
        if(version_compare($m['version'],SIM_VERSION,'<'))fail(422,'不允许降级后台版本');
        foreach(['src/version.php','src/bootstrap.php','src/migrations.php','public/index.php','public/update-guard.php','bin/console.php','bin/update-worker.php','bin/recover-update.php','src/online_updates.php'] as $core)if(!isset($m['files'][$core]))fail(422,'更新包缺少完整升级所需文件');
        $entries=$m['files'];foreach($entries as $name=>$meta)if(!is_string($name)||!onlinePathAllowed($name))fail(422,'更新包包含禁止覆盖的路径');
        if(isset($m['app'])){$app=$m['app'];if(!is_array($app)||!is_int($app['version_code']??null)||$app['version_code']<1||!is_string($app['version_name']??null)||!preg_match('/^\d+\.\d+\.\d+$/D',$app['version_name'])||($app['file']??null)!=='app/collector.apk')fail(422,'App 清单无效');$entries[$app['file']]=$app;}
        if(count($entries)+1!==count($seen))fail(422,'清单与压缩包文件不一致');
        $stage=onlineJobDir($id);if(file_exists($stage))fail(409,'更新编号已存在');mkdir($stage,0700);
        foreach($entries as $name=>$meta){
            if(!is_array($meta)||!is_int($meta['size']??null)||$meta['size']<1||$meta['size']>ONLINE_MAX_BYTES||!is_string($meta['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$meta['sha256']))fail(422,'文件校验清单无效');
            $s=$z->statName($name);if(!$s||$s['size']!==$meta['size'])fail(422,'更新文件大小不匹配');$content=$z->getFromName($name);if($content===false||!hash_equals($meta['sha256'],hash('sha256',$content)))fail(422,'更新文件校验失败');
            $path=$stage.'/files/'.$name;if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);onlineWrite($path,$content);
        }
        $versionSource=file_get_contents($stage.'/files/src/version.php');if(!preg_match('/const SIM_VERSION\s*=\s*[\'"]'.preg_quote($m['version'],'/').'[\'"]\s*;/',$versionSource))fail(422,'程序版本与清单不一致');
        if(isset($m['app'])){$apk=new ZipArchive();if($apk->open($stage.'/files/app/collector.apk')!==true)fail(422,'App 文件不是有效 APK');try{if($apk->locateName('AndroidManifest.xml')===false||$apk->locateName('classes.dex')===false)fail(422,'App 文件缺少 Android 必要内容');}finally{$apk->close();}}
        onlineSave($stage.'/release.json',$m);return $m;
    }finally{$z->close();}
}
function onlinePublish(array $u,string $id):array {
    onlineAdmin($u);$gate=onlineGate();try{onlineBusy();$dir=onlineJobDir($id);$m=onlineJson($dir.'/release.json');if(!$m||!isset($m['app']))fail(422,'请先上传包含 App 的更新包');require_once __DIR__.'/app_release.php';return appPublishFile($u,$id,$m['app'],$dir.'/files/app/collector.apk',$m['notes']);}finally{flock($gate,LOCK_UN);fclose($gate);}
}

function onlineUpload(array $u,array $file):array {
    onlineAdmin($u);$lock=onlineGate();try{onlineBusy();if(($file['error']??-1)!==UPLOAD_ERR_OK)fail(422,'上传失败，请检查宝塔 upload_max_filesize、post_max_size 及 Nginx 上传限制');if(!isset($file['tmp_name'])||!is_uploaded_file($file['tmp_name'])||($file['size']??0)<1||$file['size']>ONLINE_MAX_BYTES)fail(422,'请选择不超过 64MB 的专用 ZIP 更新包');$id=bin2hex(random_bytes(16));$m=onlineExtract($file['tmp_name'],$id);onlineSave(onlineDir().'/staged.json',['id'=>$id]);audit($u,'stage_system_update',$id,['object_type'=>'operation','object_name'=>'更新包 '.$m['version'],'summary'=>'上传并校验更新包 '.$m['version']]);return ['staged'=>onlineStageSummary($m,$id)];}finally{flock($lock,LOCK_UN);fclose($lock);}
}
/** Pipes avoid PHP-level /dev/null opens blocked by Baota open_basedir. */
function onlineSpawn(string $cli,string $worker,string $id,string $log,string $cwd):bool {
    $command='nohup '.escapeshellarg($cli).' '.escapeshellarg($worker).' '.escapeshellarg($id).' > '.escapeshellarg($log).' 2>&1 < /dev/null &';
    $proc=proc_open(['/bin/sh','-c',$command],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd);
    if(!is_resource($proc))return false;
    foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
    return proc_close($proc)===0;
}
function onlineApply(array $u,string $id):array {
    onlineAdmin($u);$queue=onlineGate();try{onlineBusy();$m=onlineJson(onlineJobDir($id).'/release.json');if(!$m||!version_compare($m['version'],SIM_VERSION,'>'))fail(422,'当前程序已是此版本或尚未上传更新包');$requirements=onlineRequirements();if(in_array(false,array_column($requirements,'ok'),true))fail(422,'请先处理页面列出的升级环境问题');
        $file=onlineLockPath();if(is_link($file))fail(422,'维护锁路径无效');$h=fopen($file,'ce');if(!$h)fail(422,'无法创建维护锁，请检查 config 目录权限');chmod($file,0600);if(!isset($GLOBALS['_update_guard_lock'])){if(!flock($h,LOCK_SH))fail(503,'无法建立升级保护');$GLOBALS['_update_guard_lock']=$h;}else fclose($h);
        $job=['id'=>$id,'status'=>'queued','phase'=>'等待启动','summary'=>'升级任务已排队，尚未应用。','backup_file'=>null,'started_at'=>microtime(true),'finished_at'=>null,'actor'=>['id'=>$u['id'],'username'=>$u['username'],'role'=>'admin'],'target_version'=>$m['version']];onlineSave(onlineJobDir($id).'/job.json',$job);onlineSave(onlineDir().'/latest.json',['id'=>$id]);
        $log=onlineJobDir($id).'/worker.log';
        if(!onlineSpawn(onlineCli(),onlineRoot().'/bin/update-worker.php',$id,$log,onlineRoot())){$job['status']='failed';$job['summary']='升级进程未能启动，请检查 PHP CLI 和禁用函数。';$job['finished_at']=microtime(true);onlineSave(onlineJobDir($id).'/job.json',$job);fail(503,$job['summary']);}
        audit($u,'apply_system_update',$id,['object_type'=>'operation','object_name'=>'后台 '.$m['version'],'summary'=>'启动后台升级到 '.$m['version']]);unset($job['actor']);return ['job'=>$job];
    }finally{flock($queue,LOCK_UN);fclose($queue);}
}
function onlineDeviceManifest():array{$app=onlineJson(onlineDir().'/app.json');return $app?['available'=>true,'version_code'=>$app['version_code'],'version_name'=>$app['version_name'],'notes'=>$app['notes'],'sha256'=>$app['sha256'],'size'=>$app['size'],'download_path'=>'/api/device/app-update/download']:['available'=>false];}
function onlineDownload():never {device();$app=onlineJson(onlineDir().'/app.json');if(!$app)fail(404,'尚未发布 App 更新');onlineStreamApk($app);}
function onlineStreamApk(array $app):never {
    $path=onlineDir().'/app-'.$app['sha256'].'.apk';if(!is_file($path)||is_link($path)||filesize($path)!==$app['size'])fail(503,'更新文件不可用，请联系管理员重新发布');$h=fopen($path,'rb');if(!$h)fail(503,'无法读取更新文件');header('Content-Type: application/vnd.android.package-archive');header('Content-Length: '.$app['size']);header('Content-Disposition: attachment; filename="Umax-SIM-'.$app['version_name'].'.apk"');header('Cache-Control: no-store');header('X-Robots-Tag: noindex');fpassthru($h);fclose($h);exit;
}

function onlineCancel(array $u,string $id):array {
    onlineAdmin($u);$gate=onlineGate();$claim=null;
    try{$dir=onlineJobDir($id);$job=onlineJson($dir.'/job.json');if(!$job||$job['status']!=='queued')fail(409,'只有尚未启动的排队任务可以取消');$claim=fopen($dir.'/claim.lock','ce');if(!$claim||!flock($claim,LOCK_EX|LOCK_NB))fail(409,'升级进程已接管任务，不能取消');$job['status']='failed';$job['phase']='已取消';$job['summary']='管理员取消了尚未启动的任务，未修改站点。';$job['finished_at']=microtime(true);onlineSave($dir.'/job.json',$job);audit($u,'cancel_system_update',$id,['object_type'=>'operation','object_name'=>'后台升级','summary'=>$job['summary']]);return ['ok'=>true];}
    finally{if(is_resource($claim)){flock($claim,LOCK_UN);fclose($claim);}flock($gate,LOCK_UN);fclose($gate);}
}
