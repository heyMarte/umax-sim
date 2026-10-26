<?php
declare(strict_types=1);
/** A proof is scoped to one device and one current authorization grant. Never infer a receiver. */
function pairingResponse(array $r): array {
 $out=array_intersect_key($r,array_flip(['id','code','phone','status','detail','subscription_id','slot']));
 $out['previous_binding']=$r['old_binding']?json_decode($r['old_binding'],true):null;
 $out['expires_at']=(float)$r['expires_at'];$out['server_at']=microtime(true);return $out;
}
function numberPairingRoute(array $u,string $method,string $path,array $b): ?array {
 if(!preg_match('#^/api/admin/devices/(\d+)/pair-number(?:/([a-f0-9]{32}))?$#D',$path,$m))return null;
 $device=(int)$m[1];requirePermission($u,'devices.bind');requireDeviceScope($u,$device,true);
 if($method==='GET'&&isset($m[2])){
  $r=query('SELECT * FROM number_pairings WHERE id=? AND device_id=?',[$m[2],$device]);if(!$r)fail(404,'验证不存在');$r=$r[0];requireWholeSim($u,(int)$r['sim_id']);
  if($r['status']==='pending'&&(float)$r['expires_at']<=microtime(true)){query("UPDATE number_pairings SET status='expired',detail='验证码已过期，请重新发起' WHERE id=? AND status='pending'",[$r['id']]);$r=query('SELECT * FROM number_pairings WHERE id=?',[$r['id']])[0];}
  return pairingResponse($r);
 }
 if($method!=='POST'||isset($m[2]))fail(404,'接口不存在');
 $sim=intField($b,'sim_id');$now=microtime(true);
 db()->beginTransaction();try{
  lockBindingDevices($sim,$device);
  $d=query('SELECT * FROM devices WHERE id=? AND deleted_at IS NULL AND revoked=0 FOR UPDATE',[$device]);if(!$d)fail(404,'设备不存在或已停用');requireDeviceScope($u,$device,true);
  $s=query('SELECT * FROM sims WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$sim]);if(!$s)fail(404,'号码不存在');requireWholeSim($u,$sim);
  $old=assertBindingSource($u,$sim,$b);
  query("UPDATE number_pairings SET status=IF(expires_at<=?,'expired','failed'),detail='已被新的号码验证替代' WHERE device_id=? AND status='pending'",[$now,$device]);
  $id=bin2hex(random_bytes(16));$code='UMAX-LINK-'.bin2hex(random_bytes(24));
  query('INSERT INTO number_pairings(id,device_id,sim_id,initiator_id,code,phone,started_at,expires_at,old_binding) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$device,$sim,$u['id'],$code,$s[0]['phone'],$now,$now+120,$old?json_encode($old):null]);
  $r=query('SELECT * FROM number_pairings WHERE id=?',[$id])[0];audit($u,'start_number_pairing',$sim,['summary'=>'发起短信验证绑定号码到设备 #'.$device]);db()->commit();return pairingResponse($r);
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
/** Called under the device lock, before saving a new immutable message snapshot. */
function consumeNumberPairing(array $device,string $event,?int $sub,string $body,float $received,float $now): void {
 $matches=query('SELECT * FROM number_pairings WHERE device_id=? AND BINARY code=BINARY ? FOR UPDATE',[$device['id'],$body]);
 if(!$matches)return;$r=$matches[0];if($r['status']!=='pending')return;
 $reason=null;$slot=null;
 if((float)$r['expires_at']<=$now){query("UPDATE number_pairings SET status='expired',detail='验证码已过期，请重新发起' WHERE id=?",[$r['id']]);return;}
 if(query('SELECT id FROM messages WHERE device_id=? AND event_id=?',[$device['id'],$event])){query("UPDATE number_pairings SET status='failed',detail='已上传过的短信不能用于新的验证，请重新发起' WHERE id=?",[$r['id']]);return;}
 if($received<(float)$r['started_at']||$received>$now+10||$received>(float)$r['expires_at'])$reason='短信时间不在本次验证有效期内，请重新验证';
 elseif($sub===null||$sub<0)$reason='手机未提供明确的收信卡，请检查电话权限后重新验证';
 $top=query('SELECT topology,topology_at,payload FROM device_telemetry WHERE device_id=?',[$device['id']]);
 if($reason===null){
  $readStatus=$top?json_decode($top[0]['payload']??'{}',true):[];
  $entries=$top&&($readStatus['subscriptions_status']??null)==='ok'?json_decode($top[0]['topology']??'null',true):null;
  $found=is_array($entries)?array_values(array_filter($entries,fn($v)=>isset($v['sub'])&&(int)$v['sub']===$sub)):[];
  if(!$top||!$top[0]['topology_at']||$now-(float)$top[0]['topology_at']>180||count($found)!==1)$reason='收信卡信息未知或已过期，请先让手机同步再验证';
  else{$slot=(int)$found[0]['slot'];if(count(array_filter($entries,fn($v)=>(int)($v['slot']??-1)===$slot))!==1)$reason='手机报告的收信卡信息不明确，请重新同步';}
  $fresh=query('SELECT subscriptions,heartbeat FROM devices WHERE id=?',[$device['id']])[0];
  $active=array_values(array_filter(json_decode($fresh['subscriptions']??'[]',true)?:[],fn($v)=>($v['subscription_id']??null)===$sub&&($v['slot']??null)===$slot&&($v['sim_state']??null)===5));
  if($reason===null&&count($active)!==1)$reason='无法确认收信卡当前可用，请检查SIM状态后重新验证';
 }
 // Lock and reload the initiator: revoked permissions, deleted accounts and changed scopes take effect now.
 $users=query('SELECT * FROM users WHERE id=? AND active=1 AND deleted_at IS NULL FOR UPDATE',[$r['initiator_id']]);
 $sim=query('SELECT * FROM sims WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$r['sim_id']]);
 if($reason===null){
  if(!$users||!$sim)$reason='发起账户或目标号码已停用';
  elseif($sim[0]['phone']!==$r['phone'])$reason='目标手机号已变化，请重新发起验证';
  else{$u=pairingCurrentAccess($users[0]);if(!can($u,'devices.bind')||!pairingCurrentScopes($u,(int)$device['id'],(int)$r['sim_id']))$reason='授权范围已变化，请由有完整权限的账户重新验证';}
 }
 $old=$r['old_binding']?json_decode($r['old_binding'],true):null;$current=query('SELECT * FROM bindings WHERE sim_id=? FOR UPDATE',[$r['sim_id']]);$current=$current[0]??null;
 if($reason===null&&$old!==$current)$reason='原绑定已变化，请重新发起验证';
 if($reason===null&&$old&&!ownsWholeDevice($u,(int)$old['device_id']))$reason='原设备授权范围已变化';
 if($reason===null&&query('SELECT sim_id FROM bindings WHERE device_id=? AND subscription_id=? FOR UPDATE',[$device['id'],$sub]))$reason='目标收信卡已经绑定，请先核对';
 if($reason!==null){query("UPDATE number_pairings SET status='failed',detail=? WHERE id=?",[$reason,$r['id']]);return;}
 if($old)query('DELETE FROM bindings WHERE sim_id=? AND version=?',[$r['sim_id'],$old['version']]);
 $version=bin2hex(random_bytes(24));
 query('INSERT INTO bindings(device_id,subscription_id,sim_id,version,sim_token) VALUES(?,?,?,?,?)',[$device['id'],$sub,$r['sim_id'],$version,currentReadySimToken((int)$device['id'],$sub)]);query('DELETE FROM sim_auto_pauses WHERE device_id=? AND sim_id=?',[$device['id'],$r['sim_id']]);syncSimDeviceScopes((int)$r['sim_id']);
 query("UPDATE number_pairings SET status='completed',detail=NULL,subscription_id=?,slot=?,message_event_id=? WHERE id=?",[$sub,$slot,$event,$r['id']]);
 audit($u,'bind_sim',(int)$r['sim_id'],['summary'=>'短信验证绑定号码到设备 #'.$device['id']]);
}
function normalizePhoneHint(mixed $phone): ?string {
 if(!is_string($phone)||strlen($phone)>64)return null;$v=preg_replace('/[\s()\-]/','',$phone);
 if(preg_match('/^(?:\+?86)?(1[3-9][0-9]{9})$/D',$v,$m))return '+86'.$m[1];
 if(preg_match('/^(?:\+?63|0)?(9[0-9]{9})$/D',$v,$m))return '+63'.$m[1];return null;
}

/** Locking reads avoid a transaction's earlier consistent snapshot reviving stale grants. */
function pairingCurrentAccess(array $u): array {
 $u['permissions']=$u['role']==='admin'?permissionKeys():array_column(query('SELECT permission FROM user_permissions WHERE user_id=? FOR UPDATE',[$u['id']]),'permission');
 $u['all_shops']=$u['role']==='admin'||(bool)$u['all_shops'];
 $u['shop_ids']=array_map('intval',array_column(query('SELECT shop_id FROM user_shops WHERE user_id=? FOR UPDATE',[$u['id']]),'shop_id'));return $u;
}
function pairingCurrentScopes(array $u,int $device,int $sim): bool {
 if(globalScope($u))return true;
 $target=array_map('intval',array_column(query('SELECT shop_id FROM sim_shops WHERE sim_id=? FOR UPDATE',[$sim]),'shop_id'));
 if($target?array_diff($target,$u['shop_ids']):!ownsUnlinkedSim($u,$sim))return false;
 $ids=array_map('intval',array_column(query('SELECT shop_id FROM device_scopes WHERE device_id=? FOR UPDATE',[$device]),'shop_id'));
 $links=query('SELECT ss.shop_id FROM sim_shops ss JOIN bindings b ON b.sim_id=ss.sim_id WHERE b.device_id=? FOR UPDATE',[$device]);
 $ids=array_merge($ids,array_map('intval',array_column($links,'shop_id')));
 if($ids)return !array_diff($ids,$u['shop_ids']);
 $d=query('SELECT created_by FROM devices WHERE id=? FOR UPDATE',[$device]);
 return $d&&(int)$d[0]['created_by']===(int)$u['id']&&ownsUnboundDevice($u,$device);
}

/** Optional explicit Android-number suggestion; manual legacy binding stays compatible. */
function validatePhoneHintConfirmation(int $device,int $sub,array $sim,mixed $confirmation): void {
 $error='手机号码提示已变化、过期或不明确，请同步手机后重新确认，或使用短信验证';
 if(!is_string($confirmation)||$confirmation!==$sim['phone'])fail(409,$error);
 $phone=normalizePhoneHint($sim['phone']);if($phone===null)fail(409,$error);
 $state=query('SELECT topology_at,topology,payload FROM device_telemetry WHERE device_id=? FOR UPDATE',[$device]);
 if(!$state||$state[0]['topology_at']===null||microtime(true)-(float)$state[0]['topology_at']>180)fail(409,$error);
 $payload=json_decode($state[0]['payload'],true);
 if(($payload['subscriptions_status']??null)!=='ok')fail(409,$error);
 $d=query('SELECT subscriptions FROM devices WHERE id=? FOR UPDATE',[$device]);
 $rows=json_decode($d[0]['subscriptions']??'[]',true)?:[];
 $matches=array_values(array_filter($rows,fn($r)=>simRowReady($r,$rows)&&($r['phone_hint_source']??null)==='android_sim'&&normalizePhoneHint($r['phone_hint']??null)===$phone));
 if(count($matches)!==1||($matches[0]['subscription_id']??null)!==$sub||($matches[0]['slot']??null)===null)fail(409,$error);
 $slot=$matches[0]['slot'];
 if(count(array_filter($rows,fn($r)=>($r['slot']??null)===$slot))!==1)fail(409,$error);
 $top=json_decode($state[0]['topology']??'null',true);
 if(!is_array($top)||count(array_filter($top,fn($r)=>($r['sub']??null)===$sub&&($r['slot']??null)===$slot))!==1)fail(409,$error);
}
