<?php
declare(strict_types=1);
/** Opaque client generation only. Never accept or persist raw SIM identifiers. */
function simObservationVersion(array $body): ?int {
 $v=$body['sim_observation_version']??null;if($v!==null&&$v!==1)fail(422,'SIM观察协议版本无效');return $v;
}
function simToken(mixed $v): ?string {
 if($v===null)return null;if(!is_string($v)||!preg_match('/^[A-Za-z0-9-]{32,64}$/D',$v))fail(422,'SIM观察标识无效');return $v;
}
function trustedSimPhone(array $row): ?string {
 return ($row['phone_hint_source']??null)!==null?normalizePhoneHint($row['phone_hint']??null):null;
}
/** Older Android exposes active per-card identity, but no public per-slot physical state. */
function simIdentityKnown(array $row): bool {
 return ($row['sim_state']??null)===5||(($row['sim_state']??null)===null&&($row['subscription_active']??false)===true&&($row['sim_state_reason']??null)==='unsupported_android_version');
}
function simRowReady(array $row,array $subs): bool {
 if(($row['subscription_id']??null)===null||($row['slot']??null)===null||!simIdentityKnown($row))return false;
 return count(array_filter($subs,fn($r)=>($r['slot']??null)===$row['slot']))===1;
}
/** Caller holds the device lock. Manual decisions anchor a fresh, unambiguous ready generation. */
function currentReadySimToken(int $device,int $sub): ?string {
 $state=query('SELECT topology_at,payload FROM device_telemetry WHERE device_id=? FOR UPDATE',[$device]);
 if(!$state||$state[0]['topology_at']===null||microtime(true)-(float)$state[0]['topology_at']>180)return null;
 $payload=json_decode($state[0]['payload'],true);if(($payload['subscriptions_status']??null)!=='ok'||($payload['sim_observation_version']??null)!==1)return null;
 $d=query('SELECT subscriptions FROM devices WHERE id=? FOR UPDATE',[$device]);$rows=json_decode($d[0]['subscriptions']??'[]',true)?:[];
 foreach($rows as $r)if(($r['subscription_id']??null)===$sub&&simRowReady($r,$rows))return $r['sim_token']??null;return null;
}
/** Locked read, including ownership for unlinked rows, without an earlier consistent snapshot. */
function simAutoScopes(array $u,array $device,array $sim,array $deviceScope): bool {
 if(globalScope($u))return true;
 $target=array_map('intval',array_column(query('SELECT shop_id FROM sim_shops WHERE sim_id=? FOR UPDATE',[$sim['id']]),'shop_id'));
 if($target?array_diff($target,$u['shop_ids']):(int)($sim['created_by']??0)!==(int)$u['id'])return false;
 if($deviceScope['foreign_unlinked'])return false;
 if($deviceScope['shops'])return !array_diff($deviceScope['shops'],$u['shop_ids']);
 return (int)($device['created_by']??0)===(int)$u['id']&&!$deviceScope['foreign_unlinked'];
}
/** Device lock serializes heartbeat, manual binding and message attribution. Release ALL invalid links first. */
function reconcileSimObservation(array $device,array $subs,string $status,?int $protocol,array $priorSubs): array {
 $id=(int)$device['id'];$bindings=query('SELECT b.*,s.phone,s.created_by,s.deleted_at FROM bindings b JOIN sims s ON s.id=b.sim_id WHERE b.device_id=? ORDER BY b.subscription_id FOR UPDATE',[$id]);
 $bySub=[];foreach($subs as $r)if($r['subscription_id']!==null)$bySub[$r['subscription_id']]=$r;
 // Keep the complete pre-change device scope: removing an inaccessible link must not grant authority.
 $scope=['shops'=>array_map('intval',array_column(query('SELECT shop_id FROM device_scopes WHERE device_id=? FOR UPDATE',[$id]),'shop_id')),'foreign_unlinked'=>false];
 foreach($bindings as $b){$shops=array_map('intval',array_column(query('SELECT shop_id FROM sim_shops WHERE sim_id=? FOR UPDATE',[$b['sim_id']]),'shop_id'));$scope['shops']=array_merge($scope['shops'],$shops);if(!$shops&&(int)($b['created_by']??0)!==(int)($device['created_by']??0))$scope['foreign_unlinked']=true;}
 $invalid=false;$remaining=[];
 foreach($bindings as $b){$sub=$b['subscription_id'];$r=$bySub[$sub]??null;$reason=null;
  if($status==='ok'){
   if(!$r)$reason='removed';
   elseif(simIdentityKnown($r)){
    $phone=trustedSimPhone($r);if($phone!==null&&$phone!==normalizePhoneHint($b['phone']))$reason='phone_changed';
    elseif($protocol===1&&($r['sim_token']??null)!==null&&$b['sim_token']!==null&&!hash_equals($b['sim_token'],$r['sim_token']))$reason='generation_changed';
   }
  }
  if($reason!==null){query('DELETE FROM bindings WHERE sim_id=? AND version=?',[$b['sim_id'],$b['version']]);$invalid=true;
   query('INSERT INTO device_events(device_id,kind,summary,at) VALUES(?,?,?,?)',[$id,'sim_association_removed','SIM观察发生变化，已解除旧收信关联（'.$reason.'）',microtime(true)]);
   audit(['id'=>null,'username'=>'device #'.$id],'auto_unbind_sim',(int)$b['sim_id'],['summary'=>'设备 #'.$id.' 的SIM观察失效，解除旧关联：'.$reason]);
  }else{$remaining[$sub]=$b;}
 }
 // Cancel SMS challenges for actual topology/generation invalidation, even without a bound SIM.
 if($status==='ok'){
  foreach($priorSubs as $p){$r=$bySub[$p['subscription_id']??-1]??null;if(!$r||(($p['sim_token']??null)!==null&&($r['sim_token']??null)!==null&&$p['sim_token']!==$r['sim_token'])||(trustedSimPhone($p)!==null&&trustedSimPhone($r??[])!==null&&trustedSimPhone($p)!==trustedSimPhone($r)))$invalid=true;}
 }
 if($invalid)query("UPDATE number_pairings SET status='failed',detail='SIM观察已变化，请核对当前收信卡' WHERE device_id=? AND status='pending'",[$id]);
 // Current account/permission locking prevents a device key from preserving a revoked grant.
 $users=$protocol===1&&$status==='ok'&&$device['created_by']!==null?query('SELECT * FROM users WHERE id=? AND active=1 AND deleted_at IS NULL FOR UPDATE',[$device['created_by']]):[];
 $u=$users?pairingCurrentAccess($users[0]):null;$authorized=$u&&can($u,'devices.bind');
 $phones=[];foreach($subs as $r){$p=trustedSimPhone($r);if($p!==null&&simRowReady($r,$subs))$phones[$p]=($phones[$p]??0)+1;}
 foreach($subs as &$r){$sub=$r['subscription_id'];$r['association_state']='pending';$r['association_reason']='pending';
  if($status!=='ok'){$r['association_state']='suspended';$r['association_reason']='observation_unavailable';continue;}
  if(!simIdentityKnown($r)){$r['association_state']='suspended';$r['association_reason']='not_ready';continue;}
  if(!simRowReady($r,$subs)){$r['association_state']='conflict';$r['association_reason']='ambiguous_slot';continue;}
  $p=trustedSimPhone($r);if($p!==null&&$phones[$p]>1){$r['association_state']='conflict';$r['association_reason']='ambiguous_phone';continue;}
  if(isset($remaining[$sub])){
   $b=$remaining[$sub];
   if($protocol===1&&($r['sim_token']??null)===null){$r['association_state']='suspended';$r['association_reason']='observation_unavailable';continue;}
   if($protocol===1&&$b['sim_token']===null){query('UPDATE bindings SET sim_token=? WHERE sim_id=? AND version=?',[$r['sim_token'],$b['sim_id'],$b['version']]);$remaining[$sub]['sim_token']=$r['sim_token'];}
   $r['association_state']='associated';$r['association_reason']='manual';continue;
  }
  if($p===null){$r['association_reason']='phone_unavailable';continue;}
  if(!$authorized||$protocol!==1||($r['sim_token']??null)===null)continue;
  // Phone records are canonical at creation; no fuzzy match and no new record is created here.
  $targets=query('SELECT * FROM sims WHERE phone=? AND deleted_at IS NULL ORDER BY id FOR UPDATE',[$p]);
  if(count($targets)!==1)continue;$target=$targets[0];
  if(!simAutoScopes($u,$device,$target,$scope))continue;
  if(query('SELECT sim_id FROM sim_auto_pauses WHERE device_id=? AND sim_id=? FOR UPDATE',[$id,$target['id']])){$r['association_reason']='manual_unbound';continue;}
  if(query('SELECT sim_id FROM bindings WHERE sim_id=? FOR UPDATE',[$target['id']])){$r['association_state']='conflict';$r['association_reason']='target_bound';continue;}
  $version=bin2hex(random_bytes(24));query('INSERT INTO bindings(device_id,subscription_id,sim_id,version,sim_token) VALUES(?,?,?,?,?)',[$id,$sub,$target['id'],$version,$r['sim_token']]);
  syncSimDeviceScopes((int)$target['id']);$r['association_state']='associated';$r['association_reason']='matched';
  audit($u,'auto_bind_sim',(int)$target['id'],['summary'=>'设备 #'.$id.' / 订阅 #'.$sub.' 根据当前SIM号码自动关联（无短信验证）']);
 }
 unset($r);return $subs;
}
/** No uncertain observation may revive a mapping, including after a legacy heartbeat downgrade. */
function simBindingActionable(array $binding,array $subs,string $status,?int $protocol): bool {
 if($binding['sim_token']===null&&$protocol!==1)return true;
 if($status!=='ok'||$protocol!==1||$binding['sim_token']===null)return false;
 foreach($subs as $r)if(($r['subscription_id']??null)===(int)$binding['subscription_id']){
  if(!simRowReady($r,$subs)||($r['sim_token']??null)!==$binding['sim_token'])return false;
  $p=trustedSimPhone($r);return $p===null||count(array_filter($subs,fn($s)=>simRowReady($s,$subs)&&trustedSimPhone($s)===$p))===1;
 }return false;
}
