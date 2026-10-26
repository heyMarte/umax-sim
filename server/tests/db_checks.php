<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
if(!str_ends_with(settings()['db_name'],'_test'))throw new RuntimeException('只允许在 _test 测试库执行');
$now=microtime(true);
query('INSERT INTO messages(device_id,event_id,sender,body,received_at,uploaded_at) VALUES(?,?,?,?,?,?)',[1,'retention-old','test','old',$now-604900,$now-604900]);
query('INSERT INTO messages(device_id,event_id,sender,body,received_at,uploaded_at) VALUES(?,?,?,?,?,?)',[1,'retention-new','test','new',$now-604700,$now-604700]);
query('INSERT INTO sessions(token,user_id,csrf,expires) VALUES(?,?,?,?)',[str_repeat('f',64),1,str_repeat('e',64),$now-1]);
clean();
if(query('SELECT id FROM messages WHERE event_id=?',['retention-old']))throw new RuntimeException('旧短信未删除');
if(!query('SELECT id FROM messages WHERE event_id=?',['retention-new']))throw new RuntimeException('未到期短信误删');
if(query('SELECT token FROM sessions WHERE token=?',[str_repeat('f',64)]))throw new RuntimeException('旧会话未删除');
$id=insert('INSERT INTO devices(name,token,heartbeat) VALUES(?,?,?)',['heartbeat-test',hash('sha256',random_bytes(32)),$now-181]);
$rows=deviceRows();$found=array_values(array_filter($rows,fn($r)=>(int)$r['id']===$id))[0];
if($found['online'])throw new RuntimeException('过期心跳未离线');
query('DELETE FROM devices WHERE id=?',[$id]);
echo "PASS: database retention and heartbeat expiry\n";
