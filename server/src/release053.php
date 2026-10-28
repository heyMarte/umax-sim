<?php
declare(strict_types=1);
function release053Route(array $u,string $method,string $path,array $b): ?array {
 if($method==='POST'&&in_array($path,['/api/alerts/dismiss','/api/alerts/undismiss'],true)){
  $key=stringField($b,'key',150);$state=alertResponse($u);$active=array_merge($state['items'],$state['dismissed']);
  if(!in_array($key,array_column($active,'key'),true))fail(404,'提醒已恢复或不在授权范围');
  if($path==='/api/alerts/dismiss')query('INSERT IGNORE INTO alert_dismissals(user_id,alert_key) VALUES(?,?)',[$u['id'],$key]);else query('DELETE FROM alert_dismissals WHERE user_id=? AND alert_key=?',[$u['id'],$key]);
  audit($u,$path==='/api/alerts/dismiss'?'dismiss_alert':'undismiss_alert',$key,['object_name'=>$key]);return alertResponse($u);
 }
 if($method==='POST'&&preg_match('#^/api/sims/(\d+)/transfer-shops$#D',$path,$m)){
  $source=(int)$m[1];$target=intField($b,'target_sim_id');$ids=$b['shop_ids']??null;
  if($source===$target||!is_array($ids)||!array_is_list($ids)||!$ids||($u['role']!=='admin'&&count($ids)>16)||count(array_unique($ids))!==count($ids))fail(422,'请选择不同目标号码和需要转移的店铺');foreach($ids as $id)if(!is_int($id)||$id<1)fail(422,'店铺编号无效');sort($ids);
  db()->beginTransaction();try{
   $oldBindings=query('SELECT * FROM bindings WHERE sim_id IN (?,?) ORDER BY sim_id',[$source,$target]);$devices=array_unique(array_map('intval',array_column($oldBindings,'device_id')));sort($devices);foreach($devices as $device)query('SELECT id FROM devices WHERE id=? FOR UPDATE',[$device]);
   $simIds=[$source,$target];sort($simIds);foreach($simIds as $id)query('SELECT id FROM sims WHERE id=? FOR UPDATE',[$id]);
   if($oldBindings!==query('SELECT * FROM bindings WHERE sim_id IN (?,?) ORDER BY sim_id FOR UPDATE',[$source,$target]))fail(409,'号码绑定已变化，请刷新后重试');
   requireWholeSim($u,$source);requireWholeSim($u,$target);$from=simShops($source);$to=simShops($target);
   foreach($ids as $id){query('SELECT id FROM shops WHERE id=? FOR UPDATE',[$id]);if(!in_array($id,array_column($from,'id'),true))fail(409,'原号码店铺关联已变化，请刷新');if(in_array($id,array_column($to,'id'),true))fail(409,'目标号码已经关联该店铺');}
   $moving=array_values(array_filter($from,fn($s)=>in_array((int)$s['id'],$ids,true)));foreach($moving as $shop)canonicalPlatform($shop);$merged=array_merge($to,$moving);if($u['role']!=='admin'&&count($merged)>16)fail(409,'目标号码关联店铺超过平台目录上限');
   try{if($u['role']!=='admin')validateLinkedShopPlatforms($merged);}catch(ApiError $e){fail(409,'目标号码已有同平台店铺，请先核对关联');}
   saveSimLinks($u,$source,array_values(array_filter($from,fn($s)=>!in_array((int)$s['id'],$ids,true))));saveSimLinks($u,$target,$merged);
   foreach($oldBindings as $binding)query('UPDATE bindings SET version=? WHERE sim_id=?',[bin2hex(random_bytes(24)),$binding['sim_id']]);
   audit($u,'transfer_sim_shops',$source,['object_type'=>'sim','shop_ids'=>$ids,'summary'=>'将店铺关联从号码 #'.$source.' 转移到号码 #'.$target]);db()->commit();return ['ok'=>true,'source_sim_id'=>$source,'target_sim_id'=>$target,'shop_ids'=>$ids,'source_shops'=>simShops($source),'target_shops'=>simShops($target)];
  }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 }
 if($method==='DELETE'&&preg_match('#^/api/admin/trash/(device|sim|user|record|message|shop)/(\d+)$#D',$path,$m))return purgeTrashItem($u,$m[1],(int)$m[2]);
 if($method==='POST'&&$path==='/api/admin/trash/clear'){
  $kind=$b['kind']??'all';if(!in_array($kind,['all','device','sim','user','record','message','shop'],true))fail(422,'回收站类型无效');$rows=trashCandidates($u,$kind);$deleted=0;$skipped=[];
  foreach($rows as $r){try{$result=purgeTrashItem($u,$r['kind'],(int)$r['id']);$deleted+=$result['deleted'];}catch(ApiError $e){$skipped[]=['kind'=>$r['kind'],'id'=>(int)$r['id'],'label'=>$r['label'],'detail'=>$e->getMessage()];}}
  return ['deleted'=>$deleted,'skipped'=>$skipped];
 }
 return null;
}
function trashPermission(string $kind,bool $restore=false): string {return ['sim'=>'sims','device'=>'devices','user'=>'users','shop'=>'shops','record'=>'records','message'=>'messages'][$kind].'.'.($restore&&$kind!=='message'?'edit':'delete');}
function trashAuthorize(array $u,string $kind,int $id): array {
 if($kind==='sim')return requireWholeSim($u,$id,true);
 if($kind==='device')return requireDeviceScope($u,$id,true,true);
 if(in_array($kind,['message','record'],true)){$r=requireDataScope($u,$kind,$id,true);if($kind==='record'&&$r['sim_id']!==null&&query('SELECT id FROM sims WHERE id=?',[$r['sim_id']])&&!ownsWholeSim($u,(int)$r['sim_id']))fail(403,'共享号码资金修改需要完整关联范围');return $r;}
 $table=assetTable($kind);$r=query("SELECT * FROM `$table` WHERE id=?",[$id]);if(!$r)fail(404,'数据不存在');$r=$r[0];
 if($kind==='shop'&&!canShop($u,$id))fail(404,'店铺不在授权范围');
 if($kind==='user'){requirePermission($u,'users.permissions');if(!canManageAccount($u,$r))fail(404,'账户不在可管理范围');}return $r;
}
function purgeBlock(string $kind,int $id,array $r,array $u): ?string {
 if($r['deleted_at']===null)return '只能永久删除回收站中的数据';
 if($u['role']==='admin')return null;
 if($kind==='user'){
  if($id===(int)$u['id'])return '不能永久删除自己';if($r['role']==='admin'&&query("SELECT id FROM users WHERE role='admin' AND active=1 AND deleted_at IS NULL AND id<>?",[$id])===[])return '必须保留可用管理员';
  if(query('SELECT id FROM mail_accounts WHERE created_by=? LIMIT 1',[$id])||query('SELECT id FROM sims WHERE created_by=? LIMIT 1',[$id])||query('SELECT id FROM devices WHERE created_by=? LIMIT 1',[$id]))return '账户仍拥有号码或设备，请先处理所属数据';
 }
 if($kind==='shop')foreach(['sims'=>'shop_id','sim_shops'=>'shop_id','messages'=>'shop_id','records'=>'shop_id','device_scopes'=>'shop_id'] as $table=>$col)if(query("SELECT $col FROM `$table` WHERE $col=? LIMIT 1",[$id]))return '店铺仍被号码、设备或历史数据引用，不能永久删除';
 if($kind==='device'&&query('SELECT sim_id FROM bindings WHERE device_id=? LIMIT 1',[$id]))return '设备仍有绑定，请先解除绑定';
 if($kind==='sim'&&query('SELECT device_id FROM bindings WHERE sim_id=? LIMIT 1',[$id]))return '号码仍有绑定，请先解除绑定';return null;
}
function purgeTrashItem(array $u,string $kind,int $id): array {
 requirePermission($u,'trash.purge');requirePermission($u,trashPermission($kind));$table=assetTable($kind);$snapshot=$kind==='message'?query('SELECT device_id FROM messages WHERE id=?',[$id]):[];db()->beginTransaction();try{
  if($snapshot)query('SELECT id FROM devices WHERE id=? FOR UPDATE',[$snapshot[0]['device_id']]);
  query("SELECT id FROM `$table` WHERE id=? FOR UPDATE",[$id]);$r=trashAuthorize($u,$kind,$id);$reason=purgeBlock($kind,$id,$r,$u);if($reason)fail(409,$reason);auditRemember($kind,$id,$r);
  audit($u,'purge_'.$kind,$id,['object_type'=>$kind,'summary'=>'永久删除回收站数据：'.($r['phone']??$r['name']??$r['username']??$r['phone_snapshot']??('#'.$id))]);
  if($kind==='message'){query('INSERT IGNORE INTO message_tombstones(device_id,event_id) VALUES(?,?)',[$r['device_id'],$r['event_id']]);query('UPDATE receive_checks SET message_id=NULL WHERE message_id=?',[$id]);}
  if($kind==='sim'){
   query('DELETE FROM bindings WHERE sim_id=?',[$id]);
   query('UPDATE messages SET sim_id=NULL WHERE sim_id=?',[$id]);query('UPDATE records SET sim_id=NULL WHERE sim_id=?',[$id]);query('UPDATE receive_checks SET sim_id=NULL WHERE sim_id=?',[$id]);query('DELETE FROM sim_shops WHERE sim_id=?',[$id]);query('DELETE FROM number_pairings WHERE sim_id=?',[$id]);query('DELETE FROM sim_auto_pauses WHERE sim_id=?',[$id]);
  }
  if($kind==='device'){
   query('DELETE FROM bindings WHERE device_id=?',[$id]);
   // History keeps immutable phone/shop snapshots; device identity is only a historical numeric reference.
   foreach(['device_scopes','device_telemetry','device_events','device_refresh_requests','receive_checks','number_pairings','sim_auto_pauses'] as $t)query("DELETE FROM `$t` WHERE device_id=?",[$id]);
  }
  if($kind==='user'){
   // Retain physical assets and readable snapshots when their former owner is removed.
   if($u['role']==='admin'){
   $owner=(int)$u['id']===$id?null:(int)$u['id'];
   foreach(['sims','devices','mail_accounts'] as $t)query("UPDATE `$t` SET created_by=? WHERE created_by=?",[$owner,$id]);
   foreach(['messages','records'] as $t)query("UPDATE `$t` SET owner_id=? WHERE owner_id=?",[$owner,$id]);
   }
   foreach(['sessions','user_permissions','user_shops','alert_mutes','alert_dismissals','wx_bindings','wx_pending','wx_outbox','mail_grants'] as $t)query("DELETE FROM `$t` WHERE user_id=?",[$id]);
   query('DELETE FROM number_pairings WHERE initiator_id=?',[$id]);query('DELETE FROM mail_oauth WHERE initiator_id=?',[$id]);
  }
  if($kind==='shop'){
   detachShopLinks($u,$id);
   // Removing a shop must not turn its history into the original creator's private data.
   foreach(['messages','records'] as $t)query("UPDATE `$t` SET shop_id=NULL,owner_id=? WHERE shop_id=?",[$u['id'],$id]);
   query('DELETE FROM user_shops WHERE shop_id=?',[$id]);
  }
  if($kind==='record'&&$r['kind']==='balances'){
   query('DELETE FROM records WHERE id=? AND deleted_at IS NOT NULL',[$id]);if($r['sim_id']!==null)refreshBalance((int)$r['sim_id']);
  }
  query("DELETE FROM `$table` WHERE id=? AND deleted_at IS NOT NULL",[$id]);db()->commit();return ['deleted'=>1,'skipped'=>[]];
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function trashCandidates(array $u,string $kind='all'): array {
 $out=[];foreach(['device','sim','user','record','message','shop'] as $k){if($kind!=='all'&&$kind!==$k)continue;if(!can($u,['device'=>'devices.view','sim'=>'sims.view','user'=>'users.view','record'=>'records.view','message'=>'messages.view','shop'=>'shops.view'][$k]))continue;$table=assetTable($k);
  foreach(query("SELECT * FROM `$table` WHERE deleted_at IS NOT NULL ORDER BY id") as $r){try{trashReadAuthorize($u,$k,(int)$r['id']);}catch(ApiError $e){continue;}$r['label']=$r['phone']??$r['name']??$r['username']??($k==='record'?$r['kind'].' '.$r['amount']:($r['sender']??'短信').' · 短信');$out[]=['id'=>(int)$r['id'],'kind'=>$k,'label'=>$r['label'],'deleted_at'=>$r['deleted_at']];}
 }return $out;
}
/** Explicit optimistic source assertion, shared by proof and manual confirmation. */
function assertBindingSource(array $u,int $sim,array $b): ?array {
 $old=query('SELECT * FROM bindings WHERE sim_id=? FOR UPDATE',[$sim]);$old=$old[0]??null;
 if($old){if(($b['replace_binding']??false)!==true)fail(409,'号码已经绑定；请明确确认转移到另一台设备');requireDeviceScope($u,(int)$old['device_id'],true);foreach(['expected_device_id','expected_subscription_id','expected_binding_version'] as $required)if(!array_key_exists($required,$b))fail(409,'请刷新并完整确认原设备、收信卡和绑定版本后重试');}
 foreach(['expected_device_id'=>'device_id','expected_subscription_id'=>'subscription_id','expected_binding_version'=>'version'] as $input=>$col)if(array_key_exists($input,$b)&&($old===null||(string)$b[$input]!== (string)$old[$col]))fail(409,'原绑定已变化，请刷新后重新确认');
 return $old;
}

function trashReadAuthorize(array $u,string $kind,int $id): array {if($kind==='sim')return requireSimScope($u,$id,true);if($kind==='record')return requireDataScope($u,$kind,$id,true);return trashAuthorize($u,$kind,$id);}
function lockBindingDevices(int $sim,int $target): void {
 $before=query('SELECT device_id,version FROM bindings WHERE sim_id=?',[$sim]);$ids=[$target];if($before)$ids[]=(int)$before[0]['device_id'];$ids=array_unique($ids);sort($ids);foreach($ids as $id)query('SELECT id FROM devices WHERE id=? FOR UPDATE',[$id]);
 $current=query('SELECT device_id,version FROM bindings WHERE sim_id=? FOR UPDATE',[$sim]);if($before!==$current)fail(409,'原绑定已变化，请刷新后重新确认');
}
/** A hidden or stale measurement is not evidence that a dismissed condition recovered. */
function alertConditionRecovered(string $key,array $devices,array $sims,float $now): bool {
 $parts=explode(':',$key);if(count($parts)!==4)return false;[$kind,$device,$sim]=$parts;$d=null;$s=null;foreach($devices as $v)if((int)$v['id']===(int)$device)$d=$v;foreach($sims as $v)if((int)$v['id']===(int)$sim)$s=$v;
 if((int)$sim>0){if(!$s)return true;$today=(new DateTimeImmutable('@'.(int)$now))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');return match($kind){'balance'=>$s['balance']!==null&&(float)$s['balance']>=(float)$s['threshold'],'check_due'=>!$s['next_check']||$s['next_check']>$today,'package_due'=>!$s['package_expires']||$s['package_expires']>=$today,default=>false};}
 if(!$d||($d['revoked']??false))return true;$online=$d['heartbeat']&&$now-(float)$d['heartbeat']<=180;
 if($kind==='waiting')return (bool)$d['heartbeat'];if($kind==='offline')return (bool)$online;
 $t=$d['telemetry']??null;if($kind==='upgrade')return $t!==null;if(!$online||!$t||!$t['fresh'])return false;if($kind==='stale')return true;$m=$t['data'];
 if($kind==='unavailable')return ($m['battery_percent']??null)!==null||($m['app_version']??null)!==null||($m['permissions']['phone']??null)!==null;
 if(str_starts_with($kind,'topology_'))return !array_filter($t['events']??[],fn($e)=>'topology_'.$e['id']===$kind&&$now-(float)$e['at']<86400);
 if(str_starts_with($kind,'sim_service_')||str_starts_with($kind,'signal_')){
  $sub=(int)substr($kind,strrpos($kind,'_')+1);foreach(json_decode($d['subscriptions']??'[]',true)?:[] as $v)if(($v['subscription_id']??null)===$sub)return str_starts_with($kind,'signal_')?($v['signal_level']??null)!==null&&$v['signal_level']>1&&($v['signal_age_seconds']??999)>=0&&($v['signal_age_seconds']??999)<=180:($v['service_state']??null)==='in_service';return false;
 }
 return match($kind){
 'battery'=>($m['battery_percent']??null)!==null&&($m['battery_percent']>20||($m['charging']??null)===true),
 'temperature'=>($m['battery_temp_c']??null)!==null&&$m['battery_temp_c']<45,
 'sms_permission'=>($m['permissions']['sms']??null)===true,'phone_permission'=>($m['permissions']['phone']??null)===true,
 'quarantine'=>($m['quarantined_count']??null)===0,'network'=>isset($m['network_transport'])&&!in_array($m['network_transport'],['none','unknown'],true),
 'backlog'=>($m['pending_count']??null)===0||(($m['oldest_pending_age_seconds']??null)!==null&&$m['oldest_pending_age_seconds']<120&&($m['consecutive_failures']??99)<2),
 'sync_failure'=>($m['consecutive_failures']??99)<3,default=>false};
}
function observeAlertRecovery(?int $device=null,?int $user=null): void {
 $rows=query('SELECT user_id,alert_key FROM alert_dismissals'.($user!==null?' WHERE user_id=?':''),$user!==null?[$user]:[]);
 if($device!==null)$rows=array_values(array_filter($rows,function($r)use($device){$p=explode(':',$r['alert_key']);return (int)($p[1]??0)===$device&&(int)($p[2]??0)===0;}));if(!$rows)return;
 $request=$GLOBALS['request_user']??null;unset($GLOBALS['request_user']);try{$devices=deviceRows();$sims=$device===null?simRows():[];}finally{if($request!==null)$GLOBALS['request_user']=$request;}
 foreach($rows as $row)if(alertConditionRecovered($row['alert_key'],$devices,$sims,microtime(true)))query('DELETE FROM alert_dismissals WHERE user_id=? AND alert_key=?',[$row['user_id'],$row['alert_key']]);
}
