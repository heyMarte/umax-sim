<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/migrations.php';
if(!str_contains(settings()['db_name'],'test'))throw new RuntimeException('Only a local disposable test database is allowed');
function v059check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);}
$tag='migration059-'.bin2hex(random_bytes(6));$d=insert('INSERT INTO devices(name,token) VALUES(?,?)',[$tag,hash('sha256',$tag)]);$s=0;
try{
 $s=insert('INSERT INTO sims(phone,carrier,location,shop) VALUES(?,?,?,?)',['+639'.random_int(100000000,999999999),'Smart','','']);
 query('INSERT INTO bindings(device_id,subscription_id,sim_id,version,sim_token) VALUES(?,?,?,?,?)',[$d,7,$s,str_repeat('v',48),str_repeat('t',32)]);
 query('INSERT INTO sim_auto_pauses(device_id,sim_id,paused_at) VALUES(?,?,?)',[$d,$s,123.0]);
 migrate();migrate();
 $column=query("SHOW FULL COLUMNS FROM bindings WHERE Field='sim_token'")[0];v059check($column['Null']==='YES'&&$column['Collation']==='ascii_bin','token schema must be nullable and case-sensitive');
 v059check(query('SELECT sim_token FROM bindings WHERE device_id=?',[$d])[0]['sim_token']===str_repeat('t',32),'migration lost a current generation');
 v059check((float)query('SELECT paused_at FROM sim_auto_pauses WHERE device_id=? AND sim_id=?',[$d,$s])[0]['paused_at']===123.0,'migration lost explicit unbind pause');
 query('UPDATE bindings SET sim_token=NULL WHERE device_id=?',[$d]);v059check(query('SELECT sim_token FROM bindings WHERE device_id=?',[$d])[0]['sim_token']===null,'legacy null token must remain supported');
 echo "PASS repeated SIM lifecycle migration preserves token, null legacy binding and manual-unbind pause\n";
}finally{query('DELETE FROM bindings WHERE device_id=?',[$d]);query('DELETE FROM sim_auto_pauses WHERE device_id=?',[$d]);if($s)query('DELETE FROM sims WHERE id=?',[$s]);query('DELETE FROM devices WHERE id=?',[$d]);}
