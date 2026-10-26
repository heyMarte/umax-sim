<?php
/** Integration test: requires disposable SIM_CONFIG database account with CREATE/DROP DATABASE. */
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/backup.php';
function checkBackup(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);}
if(!getenv('SIM_CONFIG')||!getenv('SIM_KEYRING')||!getenv('SIM_BACKUP_DIR'))throw new RuntimeException('Explicit test config/keyring/backup directory required');
$result=backupRun();$dir=backupDirectory();$path=$dir.'/'.$result['file'];$manifest=backupVerify($path);
checkBackup(backupStatus()['file']===$result['file'],'health evidence');
$originalState=file_get_contents($dir.'/status.json');
try {
    $fixture=json_decode($originalState,true,512,JSON_THROW_ON_ERROR);$fixture['instance']=hash('sha256','different-test-database');
    backupSaveState($dir,$fixture);$mismatch=backupStatus();
    checkBackup($mismatch['latest_success']===null && $mismatch['file']===null && str_contains($mismatch['state'],'其他数据库'),'different database backup evidence rejected');
} finally {
    backupSaveState($dir,json_decode($originalState,true,512,JSON_THROW_ON_ERROR));
}
checkBackup((fileperms($dir)&0777)===0700,'directory permissions');checkBackup((fileperms($path)&0777)===0600,'archive permissions');
$tar=new PharData($path);
checkBackup($tar['config.php']->getContent()===file_get_contents(getenv('SIM_CONFIG')),'config byte preservation');
checkBackup($tar['keyring.php']->getContent()===file_get_contents(getenv('SIM_KEYRING')),'keyring byte preservation');
$c=settings();$pdo=new PDO('mysql:host='.$c['db_host'].';port='.(int)$c['db_port'].';charset=utf8mb4',$c['db_user'],$c['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$restore='sim_backup_restore_'.bin2hex(random_bytes(5));$probe='sim_backup_probe_'.bin2hex(random_bytes(5));
$scratch=$dir.'/.test-'.bin2hex(random_bytes(5));mkdir($scratch,0700);
try{
    $pdo->exec('CREATE DATABASE '.backupIdentifier($restore));$pdo->exec('USE '.backupIdentifier($restore));
    $sql=$tar['database.sql']->getContent();
    foreach(preg_split('/;\n/',$sql) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
    foreach($manifest['tables'] as $table=>$count)checkBackup((int)$pdo->query('SELECT COUNT(*) FROM '.backupIdentifier($table))->fetchColumn()===$count,'restored table count');
    $key=require getenv('SIM_KEYRING');$master=base64_decode($key['master_key'],true);
    foreach($pdo->query('SELECT secret_cipher FROM devices WHERE secret_cipher IS NOT NULL') as $row){$raw=base64_decode($row['secret_cipher'],true);checkBackup(openssl_decrypt(substr($raw,28),'aes-256-gcm',$master,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'sim-device-key')!==false,'restored encrypted key');}
    $pdo->exec('CREATE DATABASE '.backupIdentifier($probe));$pdo->exec('USE '.backupIdentifier($probe));
    $pdo->exec('CREATE TABLE probe (id INT PRIMARY KEY, payload LONGBLOB, nullable VARCHAR(30), amount DECIMAL(12,2), n INT, derived INT GENERATED ALWAYS AS (n+1) STORED) ENGINE=InnoDB');
    $value="\x00\xff\x80'\\;\n中文";$insert=$pdo->prepare('INSERT INTO probe(id,payload,nullable,amount,n) VALUES(1,?,NULL,12.34,5)');$insert->execute([$value]);
    backupDump($pdo,$scratch.'/probe.sql');$expected=$pdo->query('SELECT * FROM probe')->fetch(PDO::FETCH_ASSOC);
    $pdo->exec('DROP TABLE probe');
    foreach(preg_split('/;\n/',file_get_contents($scratch.'/probe.sql')) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
    checkBackup($expected===$pdo->query('SELECT * FROM probe')->fetch(PDO::FETCH_ASSOC),'binary/null/decimal/generated round trip');
    $bad=$scratch.'/bad.tar';copy($path,$bad);$badTar=new PharData($bad);$badTar->addFromString('database.sql','corrupted');unset($badTar);
    $rejected=false;try{backupVerify($bad);}catch(Throwable $e){$rejected=true;}checkBackup($rejected,'corruption rejected');
    echo "PASS: full restore, all table counts, restored device ciphertext, config+keyring bytes, 0700/0600, binary/NULL/decimal/generated round trip, corruption rejection.\n";
}finally{
    $pdo->exec('DROP DATABASE IF EXISTS '.backupIdentifier($restore));$pdo->exec('DROP DATABASE IF EXISTS '.backupIdentifier($probe));
    foreach(glob($scratch.'/*') as $file)unlink($file);rmdir($scratch);
}
