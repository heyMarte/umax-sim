<?php
declare(strict_types=1);

/** Backups intentionally have no web execution entry point. Never return internal paths/errors. */
function backupDirectory(bool $create = false): string {
    $configured = getenv('SIM_BACKUP_DIR') ?: (settings()['backup_dir'] ?? '/var/lib/sim-manager-backups');
    if (!is_string($configured) || !str_starts_with($configured, '/') || str_contains($configured, "\0")) throw new RuntimeException('备份目录必须是绝对路径');
    $public = realpath(dirname(__DIR__).'/public');
    // Resolve the existing parent before mkdir, including symlinks and .. components.
    $ancestor = $configured;
    while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) $ancestor = dirname($ancestor);
    $resolved = realpath($ancestor);
    if ($resolved === false || ($public && ($resolved === $public || str_starts_with($resolved, $public.'/')))) throw new RuntimeException('备份目录不得位于public内');
    if ($create && !is_dir($configured) && !mkdir($configured,0700,true)) throw new RuntimeException('无法创建备份目录');
    $path = realpath($configured);
    if ($path === false) throw new RuntimeException('备份目录尚未配置');
    if ($public && ($path === $public || str_starts_with($path,$public.'/'))) throw new RuntimeException('备份目录不得位于public内');
    if ($create && !chmod($path,0700)) throw new RuntimeException('无法设置备份目录权限');
    return $path;
}
function backupInstanceFingerprint(): string {
    $c=settings();
    return hash('sha256',json_encode([(string)$c['db_host'],(int)$c['db_port'],(string)$c['db_name']],JSON_THROW_ON_ERROR));
}
function backupStatus(): array {
    $result = ['configured'=>false,'schedule_verified'=>false,'state'=>'自动备份未配置 / 从未成功','latest_success'=>null,'file'=>null,'size'=>null,'verified_at'=>null,'last_error'=>null];
    try {
        $dir=backupDirectory();
        $result['configured']=true;
        $state=json_decode((string)@file_get_contents($dir.'/status.json'),true);
        if (!is_array($state)) return $result;
        if (!hash_equals(backupInstanceFingerprint(),(string)($state['instance']??''))) {
            $result['state']='备份记录属于其他数据库，请为本站配置独立目录/重新执行';
            return $result;
        }
        $result['last_error']=empty($state['last_error'])?null:'最近一次备份失败，请在服务器检查目录权限、磁盘和数据库';
        $name=$state['file']??'';
        if (!is_string($name) || !preg_match('/^sim-backup-[0-9TZ-]+-[a-f0-9]{12}\.tar$/D',$name)) return $result;
        $file=$dir.'/'.$name;
        if (!is_file($file) || is_link($file) || filesize($file)!==($state['size']??null) || filemtime($file)!==($state['mtime']??null)) {
            $result['last_error']='最近备份文件缺失或大小/修改时间变化，请执行命令行校验'; return $result;
        }
        $result['latest_success']=$state['latest_success']??null;
        $result['file']=$name;$result['size']=filesize($file);$result['verified_at']=$state['verified_at']??null;
        $result['state']='已有成功备份；定时任务需在宝塔确认';
    } catch(Throwable $e) { /* Configuration and absolute paths must not escape through health API. */ }
    return $result;
}
function backupWrite(string $path, string $contents): void {
    $h=fopen($path,'xb'); if(!$h) throw new RuntimeException('备份写入失败');
    try { chmod($path,0600); $offset=0;while($offset<strlen($contents)){ $n=fwrite($h,substr($contents,$offset)); if($n===false||$n===0)throw new RuntimeException('备份写入失败');$offset+=$n;} } finally { fclose($h); }
}
function backupSaveState(string $dir,array $state): void {
    $temp=$dir.'/.status-'.bin2hex(random_bytes(6));
    backupWrite($temp,json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    if(!rename($temp,$dir.'/status.json')) { @unlink($temp); throw new RuntimeException('备份状态写入失败'); }
}
function backupIdentifier(string $name): string { return '`'.str_replace('`','``',$name).'`'; }
/** Consistent InnoDB data snapshot. Refuse unsupported objects rather than silently omitting them. */
function backupDump(PDO $pdo,string $target): array {
    $tables=$pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    $engine=$pdo->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach($tables as $table) if($table[1]!=='BASE TABLE'||strtoupper((string)($engine[$table[0]]??''))!=='INNODB')throw new RuntimeException('备份仅支持InnoDB基础表');
    foreach(['TRIGGERS','ROUTINES','EVENTS'] as $kind) {
        $column=$kind==='ROUTINES'?'ROUTINE_SCHEMA':($kind==='EVENTS'?'EVENT_SCHEMA':'TRIGGER_SCHEMA');
        if((int)$pdo->query("SELECT COUNT(*) FROM information_schema.$kind WHERE $column=DATABASE()")->fetchColumn()>0)throw new RuntimeException('数据库包含备份不支持的自定义对象');
    }
    $h=fopen($target,'xb');if(!$h)throw new RuntimeException('备份写入失败');chmod($target,0600);
    $write=static function(string $s)use($h):void{ $offset=0;while($offset<strlen($s)){$n=fwrite($h,substr($s,$offset));if($n===false||$n===0)throw new RuntimeException('备份写入失败');$offset+=$n;} };
    $counts=[];
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $write("-- SIM Manager complete application tables; restore into EMPTY database\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach($tables as $table){
            $name=$table[0];$quoted=backupIdentifier($name);
            $ddl=$pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM)[1];
            $write($ddl.";\n");
            $columns=[];foreach($pdo->query('SHOW FULL COLUMNS FROM '.$quoted) as $column)if(!preg_match('/(?:VIRTUAL|STORED) GENERATED/i',$column['Extra']))$columns[]=$column['Field'];
            $list=implode(',',array_map('backupIdentifier',$columns));
            $s=$pdo->query('SELECT '.$list.' FROM '.$quoted);$counts[$name]=0;
            while($row=$s->fetch(PDO::FETCH_NUM)){
                $values=array_map(static fn($v)=>$v===null?'NULL':"X'".bin2hex((string)$v)."'",$row);
                $write('INSERT INTO '.$quoted.' ('.$list.') VALUES ('.implode(',',$values).");\n");$counts[$name]++;
            }
        }
        $write("SET FOREIGN_KEY_CHECKS=1;\n");$pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;} finally{fclose($h);}
    return $counts;
}
function backupVerify(string $file): array {
    if(PHP_SAPI!=='cli')throw new RuntimeException('仅允许命令行校验');
    $tar=new PharData($file);
    $manifest=json_decode($tar['manifest.json']->getContent(),true,512,JSON_THROW_ON_ERROR);
    if(($manifest['format']??null)!==1 || count($tar)!==4)throw new RuntimeException('备份格式错误');
    foreach(['database.sql','config.php','keyring.php'] as $name){
        $entry=$manifest['files'][$name]??null;
        if(!is_array($entry)||!isset($tar[$name])||$tar[$name]->isLink()||$tar[$name]->getSize()!==$entry['size'])throw new RuntimeException('备份文件不完整');
        $hash=hash_file('sha256','phar://'.$file.'/'.$name);
        if(!is_string($hash)||!hash_equals($entry['sha256'],$hash))throw new RuntimeException('备份校验失败');
    }
    return $manifest;
}
function backupRun(): array {
    if(PHP_SAPI!=='cli')throw new RuntimeException('仅允许命令行备份');
    $old=umask(0077);$dir=null;$stage=null;$archive=null;$lock=null;$locked=false;
    try {
        $dir=backupDirectory(true);
        if(is_link($dir.'/.lock'))throw new RuntimeException('备份锁无效');
        $lock=fopen($dir.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('备份正在执行');$locked=true;chmod($dir.'/.lock',0600);
        $id='sim-backup-'.gmdate('Y-m-d\TH-i-s\Z').'-'.bin2hex(random_bytes(6));
        $stage=$dir.'/.'.$id;mkdir($stage,0700);
        $config=getenv('SIM_CONFIG')?:dirname(__DIR__).'/config/config.php';
        $keyring=getenv('SIM_KEYRING')?:dirname(__DIR__).'/config/keyring.php';
        // Validate master key before copying it. Do not initialize or replace it here.
        require_once __DIR__.'/secrets.php';masterKey();
        foreach(['config.php'=>$config,'keyring.php'=>$keyring] as $name=>$source){$content=file_get_contents($source);if($content===false)throw new RuntimeException('配置不可读取');backupWrite($stage.'/'.$name,$content);}
        $counts=backupDump(db(),$stage.'/database.sql');
        $manifest=['format'=>1,'app_version'=>SIM_VERSION,'created_at'=>gmdate(DATE_ATOM),'tables'=>$counts,'files'=>[]];
        foreach(['database.sql','config.php','keyring.php'] as $name)$manifest['files'][$name]=['size'=>filesize($stage.'/'.$name),'sha256'=>hash_file('sha256',$stage.'/'.$name)];
        backupWrite($stage.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $archive=$dir.'/.'.$id.'.tar';$tar=new PharData($archive);
        foreach(['database.sql','config.php','keyring.php','manifest.json'] as $name)$tar->addFile($stage.'/'.$name,$name);
        unset($tar);chmod($archive,0600);backupVerify($archive);
        $final=$dir.'/'.$id.'.tar';if(!rename($archive,$final))throw new RuntimeException('无法保存备份');$archive=null;
        $state=['instance'=>backupInstanceFingerprint(),'latest_success'=>$manifest['created_at'],'file'=>basename($final),'size'=>filesize($final),'sha256'=>hash_file('sha256',$final),'mtime'=>filemtime($final),'verified_at'=>gmdate(DATE_ATOM),'last_error'=>null];
        backupSaveState($dir,$state);return ['file'=>basename($final),'size'=>$state['size'],'created_at'=>$state['latest_success']];
    } catch(Throwable $e){
        if($dir && $locked){$state=json_decode((string)@file_get_contents($dir.'/status.json'),true);if(!is_array($state))$state=[];$state['last_error']=true;try{backupSaveState($dir,$state);}catch(Throwable $ignored){}}
        throw new RuntimeException('备份失败：检查备份目录权限、磁盘、PHP Phar扩展及数据库（仅支持InnoDB基础表）');
    } finally {
        if($archive)@unlink($archive);
        if($stage&&is_dir($stage)){foreach(['database.sql','config.php','keyring.php','manifest.json']as $name)@unlink($stage.'/'.$name);@rmdir($stage);}
        if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}umask($old);
    }
}
