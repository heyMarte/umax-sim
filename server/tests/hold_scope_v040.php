<?php
// Only called by the isolated localhost concurrency regression.
require dirname(__DIR__).'/src/bootstrap.php';
if(!getenv('SIM_CONFIG')||(!str_contains(settings()['db_name'],'test')&&!str_contains(settings()['db_name'],'audit')))exit(1);
$kind=$argv[1];$id=(int)$argv[2];$target=(int)$argv[3];$ready=$argv[4];
if(!in_array($kind,['sim','user'],true))exit(1);
db()->beginTransaction();
if($kind==='sim'){query('SELECT id FROM sims WHERE id=? FOR UPDATE',[$id]);}else{query('SELECT id FROM users WHERE id=? FOR UPDATE',[$id]);}
file_put_contents($ready,'ready');usleep(700000);
if($kind==='sim')query('UPDATE sims SET shop_id=?,shop=(SELECT name FROM shops WHERE id=?) WHERE id=?',[$target,$target,$id]);else query("UPDATE users SET role='admin',all_shops=1 WHERE id=?",[$id]);
db()->commit();
