<?php
/** Isolated transaction: verifies routing, physical ownership and DTO redaction without retained fixtures. */
require_once __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/management.php';
function msCheck(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);}
$db=db();$db->beginTransaction();
try{
 $tag='backend050-'.bin2hex(random_bytes(6));$ids=[];
 foreach(['tiktok','shopee','lazada'] as $p)$ids[$p]=insert('INSERT INTO shops(name,platform,platforms) VALUES(?,?,?)',[$tag,$p,json_encode([$p])]);
 $sim=insert('INSERT INTO sims(phone,carrier,country,currency,location,shop,shop_id,balance) VALUES(?,?,?,?,?,?,?,?)',['+639'.random_int(100000000,999999999),'Smart','PH','PHP','fixture',$tag,$ids['tiktok'],'888']);
 foreach($ids as $id)query('INSERT INTO sim_shops(sim_id,shop_id) VALUES(?,?)',[$sim,$id]);
 $partial=['role'=>'operator','all_shops'=>false,'shop_ids'=>[$ids['shopee']],'permissions'=>permissionKeys()];$full=$partial;$full['shop_ids']=array_values($ids);
 msCheck(!ownsWholeSim($partial,$sim)&&ownsWholeSim($full,$sim),'physical ownership requires every linked shop');
 msCheck(requireSimScope($partial,$sim)['id']===$sim,'nonprimary link grants visibility');
 try{requireWholeSim($partial,$sim);throw new RuntimeException('partial mutation allowed');}catch(ApiError $e){msCheck($e->status===403,'partial physical mutation denied');}
 foreach($ids as $platform=>$shop)msCheck(messageShopRoute($sim,$platform)['id']===$shop,'platform route '.$platform);
 msCheck(messageShopRoute($sim,null)===null,'shared generic message quarantined');
 $dto=scopeSimRows($partial,query('SELECT * FROM sims WHERE id=?',[$sim]))[0];msCheck(count($dto['shops'])===1&&$dto['shop_id']===$ids['shopee']&&$dto['platforms']===['shopee'],'visible shop metadata only');
 msCheck($dto['balance']===null&&$dto['financial_restricted']&&!$dto['can_manage'],'shared balance redacted');
 $dto=scopeSimRows($full,query('SELECT * FROM sims WHERE id=?',[$sim]))[0];msCheck(count($dto['shops'])===3&&!$dto['financial_restricted']&&$dto['can_manage'],'full scope DTO');
 $device=insert('INSERT INTO devices(name,token) VALUES(?,?)',[$tag,hash('sha256',$tag)]);query('INSERT INTO bindings(device_id,subscription_id,sim_id,version) VALUES(?,?,?,?)',[$device,42,$sim,str_repeat('a',48)]);
 msCheck(!ownsWholeDevice($partial,$device)&&ownsWholeDevice($full,$device),'device ownership includes nonprimary shop links');
 $unknown=insert('INSERT INTO messages(device_id,event_id,sim_id,subscription_id,sender,body,received_at,uploaded_at,shop_id) VALUES(?,?,?,?,?,?,?,?,NULL)',[$device,$tag,$sim,42,'test','OTP 123456',microtime(true),microtime(true)]);
 $global=$partial;$global['all_shops']=true;
 try{requireDataScope($global,'message',$unknown);throw new RuntimeException('global operator unknown message allowed');}catch(ApiError $e){msCheck($e->status===404,'unknown message admin only');}
 query('UPDATE messages SET sim_id=NULL WHERE id=?',[$unknown]);msCheck(requireDataScope($global,'message',$unknown)['id']===$unknown,'legacy unbound grant preserved');
 query('DELETE FROM sim_shops WHERE sim_id=? AND shop_id<>?',[$sim,$ids['shopee']]);msCheck(messageShopRoute($sim,null)['id']===$ids['shopee'],'single linked shop generic fallback');msCheck(messageShopRoute($sim,'tiktok')===null,'identified platform mismatch stays unassigned');
 echo "PASS: multishop backend routing, scope, financial privacy, device ownership and admin quarantine\n";
}finally{if($db->inTransaction())$db->rollBack();}
