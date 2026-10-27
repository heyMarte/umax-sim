<?php
declare(strict_types=1);
require __DIR__.'/../src/api.php';
require __DIR__.'/../src/migrations.php';
if(!str_ends_with(settings()['db_name'],'_test'))throw new RuntimeException('Disposable _test database required');
function sncheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);}
$u=loadUserAccess(query("SELECT * FROM users WHERE role='admin' AND active=1 AND deleted_at IS NULL ORDER BY id LIMIT 1")[0]);
$prefix='label-'.bin2hex(random_bytes(5));$shops=[];$sims=[];$message=0;$device=0;
try {
 $a=aclRoute('POST','/api/shops',['name'=>$prefix.'-A','platforms'=>['tiktok']],$u)['id'];$shops[]=$a;
 $b=aclRoute('POST','/api/shops',['name'=>$prefix.'-B','platforms'=>['tiktok']],$u)['id'];$shops[]=$b;
 foreach([$a,$b] as $id){$sims[]=insert('INSERT INTO sims(phone,carrier,country,currency,location,shop,shop_id,created_by) VALUES(?,?,?,?,?,?,?,?)',['+639'.random_int(100000000,999999999),'Globe','PH','PHP','fixture',query('SELECT name FROM shops WHERE id=?',[$id])[0]['name'],$id,$u['id']]);query('INSERT INTO sim_shops(sim_id,shop_id) VALUES(?,?)',[$sims[array_key_last($sims)],$id]);}
 $device=insert('INSERT INTO devices(name,token) VALUES(?,?)',[$prefix,hash('sha256',$prefix)]);
 $message=insert('INSERT INTO messages(device_id,event_id,sim_id,sender,body,received_at,uploaded_at,shop_id,shop_snapshot,phone_snapshot) VALUES(?,?,?,?,?,?,?,?,?,?)',[$device,$prefix,$sims[1],'fixture','OTP 123456',microtime(true),microtime(true),$b,$prefix.'-B',query('SELECT phone FROM sims WHERE id=?',[$sims[1]])[0]['phone']]);
 // Same path as the screenshot: edit a legacy shop to an existing label.
 aclRoute('PUT','/api/shops/'.$b,['name'=>$prefix.'-A','name_from'=>$prefix.'-B','platforms'=>['tiktok']],$u);
 sncheck(query('SELECT name FROM shops WHERE id=?',[$b])[0]['name']===$prefix.'-A','rename to existing label');
 sncheck(simShopIds($sims[0])===[$a]&&simShopIds($sims[1])===[$b],'same labels keep different links');
 sncheck(query('SELECT shop_snapshot FROM messages WHERE id=?',[$message])[0]['shop_snapshot']===$prefix.'-B','historical message snapshot preserved');
 $scoped=$u;$scoped['role']='operator';$scoped['all_shops']=false;$scoped['shop_ids']=[$a];
 $visible=scopeSimRows($scoped,query('SELECT * FROM sims WHERE id IN (?,?)',$sims));sncheck(count($visible)===1&&(int)$visible[0]['id']===$sims[0],'same label cannot grant other shop visibility');
 $c=aclRoute('POST','/api/shops',['name'=>$prefix.'-A','platform'=>'tiktok'],$u)['id'];$shops[]=$c;
 $d=aclRoute('POST','/api/shops',['name'=>$prefix.'-A','platform'=>'tiktok'],$u)['id'];$shops[]=$d;sncheck($c!==$d,'same canonical platform permits separate same-name shops');
 query('UPDATE shops SET deleted_at=? WHERE id=?',[microtime(true),$c]);
 $e=aclRoute('POST','/api/shops',['name'=>$prefix.'-A','platform'=>'tiktok'],$u)['id'];$shops[]=$e;
 query('UPDATE shops SET deleted_at=NULL WHERE id=?',[$c]);sncheck(query('SELECT deleted_at FROM shops WHERE id=?',[$c])[0]['deleted_at']===null,'restore does not collide with reused label');
 try{resolveShop($u,['shop'=>$prefix.'-A']);throw new LogicException('ambiguous name silently resolved');}catch(ApiError $ex){sncheck($ex->status===422,'legacy name-only API must not pick a random identity');}
 migrate();migrate();sncheck(simShopIds($sims[1])===[$b],'repeat migration preserves links with duplicate labels');
 sncheck(!array_filter(query('SHOW INDEX FROM shops'),fn($idx)=>$idx['Column_name']==='name'&&(int)$idx['Non_unique']===0),'shop names have no unique index');
 echo "PASS duplicate shop labels, recycle-name reuse, same-ID links, historical snapshots, scoped visibility and repeat migration\n";
} finally {
 if(db()->inTransaction())db()->rollBack();if($message)query('DELETE FROM messages WHERE id=?',[$message]);if($device)query('DELETE FROM devices WHERE id=?',[$device]);
 foreach($sims as $id){query('DELETE FROM sim_shops WHERE sim_id=?',[$id]);query('DELETE FROM sims WHERE id=?',[$id]);}
 foreach($shops as $id)query('DELETE FROM shops WHERE id=?',[$id]);
}
