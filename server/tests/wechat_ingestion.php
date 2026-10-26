<?php
declare(strict_types=1);
require __DIR__.'/../src/api.php';
function ingestCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$original=wxConfig();$tag='WXHTTP056-'.bin2hex(random_bytes(5));$uid=$device=$shop=$sim=null;
try{
 $uid=insert('INSERT INTO users(username,password,role) VALUES(?,?,?)',[$tag,password_hash(bin2hex(random_bytes(8)),PASSWORD_DEFAULT),'operator']);
 foreach(['wechat.receive','messages.view','messages.copy'] as $p)query('INSERT INTO user_permissions(user_id,permission) VALUES(?,?)',[$uid,$p]);
 $shop=insert('INSERT INTO shops(name,platform) VALUES(?,?)',[$tag,'tiktok']);query('INSERT INTO user_shops(user_id,shop_id) VALUES(?,?)',[$uid,$shop]);
 $device=insert('INSERT INTO devices(name,token) VALUES(?,?)',[$tag,hash('sha256',$tag)]);
 $sim=insert('INSERT INTO sims(phone,carrier,country,currency,location,shop,shop_id,created_by) VALUES(?,?,?,?,?,?,?,?)',['+639'.random_int(100000000,999999999),'Globe','PH','PHP','',$tag,$shop,$uid]);query('INSERT INTO sim_shops(sim_id,shop_id) VALUES(?,?)',[$sim,$shop]);query('INSERT INTO bindings(device_id,subscription_id,sim_id,version) VALUES(?,?,?,?)',[$device,1,$sim,$tag]);
 query('UPDATE wx_config SET enabled=1,app_id=?,secret_cipher=?,revision=revision+1,tested_revision=revision+1 WHERE id=1',['wx3333333333333333',sealSecret('LOCAL_DUMMY_056')]);$c=wxConfig();query('UPDATE wx_config SET tested_revision=revision WHERE id=1');
 query('INSERT INTO wx_bindings(user_id,app_id,openid,version,confirmed_at) VALUES(?,?,?,?,?)',[$uid,$c['app_id'],$tag,str_repeat('c',48),microtime(true)]);
 $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$tag;$_SERVER['HTTP_X_DEVICE_ID']=(string)$device;$_SERVER['REMOTE_ADDR']='qa056-ingestion';
 $event=fn($id,$at=null,$body='TikTok verification code 001234')=>['event_id'=>$tag.'-'.$id,'subscription_id'=>1,'binding_version'=>$tag,'sender'=>'TikTok','body'=>$body,'received_at'=>$at??microtime(true)];
 $send=fn($e)=>route('POST','/api/device/messages',['events'=>[$e]]);
 $fresh=$event('fresh');$send($fresh);$send($fresh);$message=query('SELECT * FROM messages WHERE device_id=? AND event_id=?',[$device,$fresh['event_id']])[0];
 ingestCheck(count(query('SELECT * FROM wx_outbox WHERE message_id=? AND user_id=?',[$message['id'],$uid]))===1,'Repeated upload must enqueue exactly once through real device API');
 query('UPDATE wx_config SET enabled=0 WHERE id=1');$disabled=$event('disabled');$send($disabled);query('UPDATE wx_config SET enabled=1 WHERE id=1');$send($disabled);$mid=query('SELECT id FROM messages WHERE device_id=? AND event_id=?',[$device,$disabled['event_id']])[0]['id'];ingestCheck(!query('SELECT id FROM wx_outbox WHERE message_id=?',[$mid]),'Turning on must not resend messages ingested while disabled');
 foreach([$event('old',microtime(true)-301),$event('private',null,'Store newsletter without any authentication number') ] as $e){$send($e);$mid=query('SELECT id FROM messages WHERE device_id=? AND event_id=?',[$device,$e['event_id']])[0]['id'];ingestCheck(!query('SELECT id FROM wx_outbox WHERE message_id=?',[$mid]),'Old or non-OTP SMS must never be forwarded');}
 $next=$event('new-after-enable');$send($next);$mid=query('SELECT id FROM messages WHERE device_id=? AND event_id=?',[$device,$next['event_id']])[0]['id'];ingestCheck(count(query('SELECT id FROM wx_outbox WHERE message_id=?',[$mid]))===1,'A new OTP after enabling should enqueue');
 // Generation uncertainty must not leak a code to the old SIM's authorized operator.
 $phone=query('SELECT phone FROM sims WHERE id=?',[$sim])[0]['phone'];
 $observed=['subscription_id'=>1,'slot'=>1,'sim_state'=>5,'phone_hint'=>$phone,'phone_hint_source'=>'android_sim','sim_token'=>str_repeat('a',32)];
 route('POST','/api/device/heartbeat',['sim_observation_version'=>1,'subscriptions_status'=>'ok','subscriptions'=>[$observed]]);
 $sendObserved=function(string $suffix,?string $token)use($event):array{$e=$event($suffix);$e['sim_token']=$token;route('POST','/api/device/messages',['sim_observation_version'=>1,'events'=>[$e]]);return $e;};
 $good=$sendObserved('generation-good',str_repeat('a',32));
 $goodRow=query('SELECT id,sim_id,phone_snapshot FROM messages WHERE device_id=? AND event_id=?',[$device,$good['event_id']])[0];
 ingestCheck((int)$goodRow['sim_id']===$sim&&count(query('SELECT id FROM wx_outbox WHERE message_id=? AND user_id=?',[$goodRow['id'],$uid]))===1,'Exact generation keeps authorized delivery');
 foreach(['generation-wrong'=>str_repeat('b',32),'generation-missing'=>null] as $suffix=>$token){
  $e=$sendObserved($suffix,$token);$row=query('SELECT id,sim_id,phone_snapshot FROM messages WHERE device_id=? AND event_id=?',[$device,$e['event_id']])[0];
  ingestCheck($row['sim_id']===null&&$row['phone_snapshot']===''&&!query('SELECT id FROM wx_outbox WHERE message_id=? AND user_id=?',[$row['id'],$uid]),'Unknown generation cannot notify the old number operator');
 }
 $observed['sim_token']=str_repeat('c',32);$observed['phone_hint']='+8613812345678';
 route('POST','/api/device/heartbeat',['sim_observation_version'=>1,'subscriptions_status'=>'ok','subscriptions'=>[$observed]]);
 $e=$sendObserved('generation-replaced',str_repeat('a',32));$row=query('SELECT id,sim_id FROM messages WHERE device_id=? AND event_id=?',[$device,$e['event_id']])[0];
 ingestCheck($row['sim_id']===null&&!query('SELECT id FROM wx_outbox WHERE message_id=? AND user_id=?',[$row['id'],$uid]),'Replacement cannot forward under the old number');
 ingestCheck(query('SELECT phone_snapshot FROM messages WHERE id=?',[$goodRow['id']])[0]['phone_snapshot']===$phone,'Replacement never changes historical phone snapshot');
 echo "PASS real device-ingestion path: atomic once-only enqueue, generation scope, replacement privacy, immutable history, enable never backfills, stale/private excluded\n";
}finally{
 if(db()->inTransaction())db()->rollBack();if($uid){foreach(['wx_outbox','wx_bindings','wx_pending','user_permissions','user_shops'] as $t)query("DELETE FROM $t WHERE user_id=?",[$uid]);query('DELETE FROM users WHERE id=?',[$uid]);}if($device){query('DELETE FROM bindings WHERE device_id=?',[$device]);query('DELETE FROM messages WHERE device_id=?',[$device]);query('DELETE FROM devices WHERE id=?',[$device]);}if($sim){query('DELETE FROM sim_shops WHERE sim_id=?',[$sim]);query('DELETE FROM sims WHERE id=?',[$sim]);}if($shop)query('DELETE FROM shops WHERE id=?',[$shop]);$cols=array_keys($original);query('UPDATE wx_config SET '.implode(',',array_map(fn($k)=>"$k=?",$cols)).' WHERE id=1',array_values($original));
}
