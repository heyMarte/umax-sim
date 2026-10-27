<?php
declare(strict_types=1);
function permissionCatalog(): array {
 $groups=[
 'dashboard'=>['总览',['dashboard.view'=>'查看总览']],
 'shops'=>['店铺',['shops.view'=>'查看店铺','shops.create'=>'新增店铺','shops.edit'=>'编辑店铺','shops.delete'=>'删除店铺']],
 'sims'=>['号码',['sims.view'=>'查看号码','sims.create'=>'新增号码','sims.edit'=>'编辑号码','sims.delete'=>'删除号码','sims.settings'=>'保号及提醒设置']],
 'messages'=>['短信',['messages.view'=>'查看短信及验证码','messages.copy'=>'复制验证码','messages.delete'=>'删除短信','messages.unassigned'=>'查看未归属短信（需全部店铺范围）']],
 'devices'=>['设备',['devices.view'=>'查看设备','devices.create'=>'新增设备','devices.edit'=>'编辑设备','devices.delete'=>'删除设备','devices.control'=>'停用设备','devices.bind'=>'绑定与解绑卡槽','devices.keys.view'=>'查看设备密钥','devices.keys.rotate'=>'更换设备密钥','devices.refresh'=>'请求手机上报']],
 'records'=>['余额与充值',['records.view'=>'查看流水与余额','records.balances.create'=>'录入余额','records.recharges.create'=>'记录充值','records.edit'=>'编辑流水','records.delete'=>'删除流水']],
 'alerts'=>['提醒',['alerts.view'=>'查看提醒','alerts.mute'=>'暂缓提醒','alerts.dismiss'=>'删除与恢复本人提醒']],
 'users'=>['账户及授权',['users.view'=>'查看账户','users.create'=>'新增账户','users.edit'=>'编辑及禁用账户','users.delete'=>'删除账户','users.permissions'=>'分配自己范围内的权限与店铺']],
 'audit'=>['操作记录',['audit.view'=>'查看本人操作记录（店铺受限）','audit.all'=>'查看授权店铺内全员记录']],
 'trash'=>['回收站',['trash.view'=>'查看授权范围回收站','trash.restore'=>'恢复数据（仍需对应管理权限）','trash.purge'=>'永久删除回收站数据']],
 'wechat'=>['微信推送',['wechat.manage'=>'配置全站微信推送通道','wechat.receive'=>'接收本人授权范围验证码']],
 'backup'=>['备份',['backup.view'=>'查看全站备份状态与恢复说明']]];
 $out=[];foreach($groups as $key=>[$label,$permissions]){$items=[];foreach($permissions as $p=>$l)$items[]=['key'=>$p,'label'=>$l];$out[]=['key'=>$key,'label'=>$label,'permissions'=>$items];}return ['groups'=>$out];
}
function permissionKeys(): array {$out=[];foreach(permissionCatalog()['groups'] as $g)foreach($g['permissions'] as $p)$out[]=$p['key'];return $out;}
function loadUserAccess(array $u): array {
 $u['access_context']=hash('sha256',(string)($u['csrf']??''));
 $u['permissions']=$u['role']==='admin'?permissionKeys():array_column(query('SELECT permission FROM user_permissions WHERE user_id=?',[$u['id']]),'permission');
 $u['all_shops']=$u['role']==='admin'||(bool)$u['all_shops'];$u['shop_ids']=array_map('intval',array_column(query('SELECT shop_id FROM user_shops WHERE user_id=?',[$u['id']]),'shop_id'));return $u;
}
function can(array $u,string $p): bool {return in_array($p,permissionKeys(),true)&&($u['role']==='admin'||in_array($p,$u['permissions']??[],true));}
function requirePermission(array $u,string $p): void {if(!can($u,$p))fail(403,'没有此操作权限，请联系管理员分配');}
function canShop(array $u,int|null $id): bool {return $id!==null&&($u['all_shops']||in_array($id,$u['shop_ids'],true));}
function scopeSql(array $u,string $column): string {if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/D',$column))throw new LogicException('scope column');return $u['all_shops']?'1=1':($u['shop_ids']?$column.' IN ('.implode(',',array_map('intval',$u['shop_ids'])).')':'0=1');}
function globalScope(array $u): bool{return (bool)$u['all_shops'];}
function requireSimScope(array $u,int $id,bool $deleted=false): array {$r=query('SELECT * FROM sims WHERE id=?'.($deleted?'':' AND deleted_at IS NULL'),[$id]);if(!$r||(!globalScope($u)&&!array_intersect(simShopIds($id),$u['shop_ids'])&&!ownsUnlinkedSim($u,$id)))fail(404,'号码不存在或不在授权店铺范围');return $r[0];}
function requireDataScope(array $u,string $kind,int $id,bool $deleted=false): array {
 if(!in_array($kind,['record','message'],true))throw new LogicException('data kind');$table=$kind==='record'?'records':'messages';$r=query("SELECT * FROM $table WHERE id=?".($deleted?'':' AND deleted_at IS NULL'),[$id]);
 if(!$r)fail(404,'数据不存在');$row=$r[0];$allowed=canShop($u,$row['shop_id']===null?null:(int)$row['shop_id']);
 if(!$allowed&&$kind==='message'&&$row['shop_id']===null)$allowed=$u['role']==='admin'||($row['owner_id']!==null&&(int)$row['owner_id']===(int)$u['id'])||($row['sim_id']===null&&$row['owner_id']===null&&$row['phone_snapshot']===''&&globalScope($u)&&can($u,'messages.unassigned'));
 if(!$allowed&&$kind==='record'&&$row['shop_id']===null)$allowed=globalScope($u)||($row['owner_id']!==null&&(int)$row['owner_id']===(int)$u['id']);
 if(!$allowed)fail(404,'数据不存在或不在授权店铺范围');return $row;
}
function deviceOwnership(int $id): array {return array_map('intval',array_column(query('SELECT shop_id FROM device_scopes WHERE device_id=? UNION SELECT ss.shop_id FROM sim_shops ss JOIN bindings b ON b.sim_id=ss.sim_id WHERE b.device_id=?',[$id,$id]),'shop_id'));}
function ownsUnboundDevice(array $u,int $id): bool {return (bool)query('SELECT id FROM devices WHERE id=? AND created_by=? AND NOT EXISTS(SELECT 1 FROM bindings b JOIN sims s ON s.id=b.sim_id WHERE b.device_id=devices.id AND (s.created_by IS NULL OR s.created_by<>devices.created_by OR EXISTS(SELECT 1 FROM sim_shops ss WHERE ss.sim_id=s.id))) AND NOT EXISTS(SELECT 1 FROM device_scopes WHERE device_id=devices.id)',[$id,$u['id']]);}
function seesDevice(array $u,int $id): bool {return globalScope($u)||ownsUnboundDevice($u,$id)||(bool)array_intersect(deviceOwnership($id),$u['shop_ids']);}
function ownsWholeDevice(array $u,int $id): bool {if(globalScope($u))return true;$ids=deviceOwnership($id);return $ids?!array_diff($ids,$u['shop_ids']):ownsUnboundDevice($u,$id);}
function requireDeviceScope(array $u,int $id,bool $full=false,bool $deleted=false): array {
 $rows=query('SELECT * FROM devices WHERE id=?'.($deleted?'':' AND deleted_at IS NULL'),[$id]);if(!$rows)fail(404,'设备不存在');
 $ok=$full?ownsWholeDevice($u,$id):seesDevice($u,$id);if(!$ok)fail(404,'设备不存在或不在完整授权范围');return $rows[0];
}
function scopeSimRows(array $u,array $rows): array {
 $out=[];foreach($rows as $r){$all=simShops((int)$r['id']);$visible=array_values(array_filter($all,fn($s)=>canShop($u,(int)$s['id'])));if(!$visible&&!globalScope($u)&&!ownsUnlinkedSim($u,(int)$r['id']))continue;
 $r['shops']=$visible;$r['can_manage']=ownsWholeSim($u,(int)$r['id']);$r['financial_restricted']=!$r['can_manage'];
 $r['shop_id']=$visible[0]['id']??null;$r['shop']=implode(' / ',array_column($visible,'name'));$r['platforms']=[];foreach($visible as $shop)$r['platforms']=array_values(array_unique(array_merge($r['platforms'],$shop['platforms'])));
 if(!can($u,'records.view')||$r['financial_restricted'])foreach(['balance','checked_at','threshold'] as $key)$r[$key]=null;$out[]=$r;
 }return $out;
}
function scopeDeviceRows(array $u,array $rows): array {
 $out=[];foreach($rows as $d){$id=(int)$d['id'];if(!seesDevice($u,$id))continue;$full=ownsWholeDevice($u,$id);$d['can_manage']=$full;
  if(!$full){$subs=array_map('intval',array_column(query('SELECT b.subscription_id FROM bindings b JOIN sims s ON b.sim_id=s.id WHERE b.device_id=? AND '.simScopeSql($u,'s.id'),[$id]),'subscription_id'));
   $raw=json_decode($d['subscriptions']??'[]',true);$d['subscriptions']=json_encode(array_values(array_filter(is_array($raw)?$raw:[],fn($s)=>isset($s['subscription_id'])&&in_array((int)$s['subscription_id'],$subs,true))),JSON_UNESCAPED_UNICODE);
   $d['name']='共享采集设备 #'.$id;$d['location']='';$d['notes']='';$d['key_available']=false;$d['receive_check']=null;
   if($d['telemetry']){$d['telemetry']['events']=[];$d['telemetry']['data']['subscriptions']=[];foreach(['pending_count','oldest_pending_age_seconds','quarantined_count','last_received_at','last_sms_upload_at','last_error','consecutive_failures'] as $key)$d['telemetry']['data'][$key]=null;}
  }
  $out[]=$d;
 }return $out;
}
function resolveShop(array $u,array $b,?array $existing=null): array {
 if(isset($b['shop_id'])){$id=intField($b,'shop_id');$r=query('SELECT * FROM shops WHERE id=? AND deleted_at IS NULL',[$id]);}
 elseif(isset($b['shop'])){$name=trim(stringField($b,'shop',200));$r=query('SELECT * FROM shops WHERE BINARY name=BINARY ? AND deleted_at IS NULL',[$name]);if(!$r){requirePermission($u,'shops.create');if($name==='')fail(422,'店铺名称不能为空');$id=insert('INSERT INTO shops(name) VALUES(?)',[$name]);if(!globalScope($u))query('INSERT IGNORE INTO user_shops(user_id,shop_id) VALUES(?,?)',[$u['id'],$id]);$r=query('SELECT * FROM shops WHERE id=?',[$id]);}}
 elseif($existing){$r=query('SELECT * FROM shops WHERE id=? AND deleted_at IS NULL',[$existing['shop_id']]);}else fail(422,'请选择店铺');
 if(count($r)>1)fail(422,'存在同名跨平台店铺，请使用店铺ID');if(!$r)fail(404,'店铺不存在');if(!canShop($u,(int)$r[0]['id'])&&!($r[0]['id']==($id??null)&&isset($name)&&can($u,'shops.create')))fail(404,'店铺不在授权范围');return $r[0];
}
function aclRoute(string $method,string $path,array $b,array $u): mixed {
 if($method==='GET'&&$path==='/api/permissions/catalog')return permissionCatalog();
 if($path==='/api/shops'&&$method==='GET'){requireAnyPermission($u,['shops.view','sims.create','sims.edit','devices.create','users.permissions']);$filter=platform_filter();return array_values(array_filter(array_map(function($shop)use($u){$shop=platform_enrich_shop($shop);if(can($u,'sims.view'))$shop['linked_sims']=query('SELECT s.id,s.phone FROM sim_shops ss JOIN sims s ON s.id=ss.sim_id WHERE ss.shop_id=? AND s.deleted_at IS NULL ORDER BY s.id',[$shop['id']]);return $shop;},query('SELECT id,name,platform,platforms FROM shops WHERE deleted_at IS NULL AND '.scopeSql($u,'id').' ORDER BY name,id')),fn($row)=>platform_matches($row['platforms'],$filter)));}
 if($path==='/api/shops'&&$method==='POST'){requirePermission($u,'shops.create');$name=trim(stringField($b,'name',200));if($name==='')fail(422,'店铺名称不能为空');$id=insert('INSERT INTO shops(name,platform,platforms) VALUES(?,?,?)',[$name,canonicalPlatform($b),isset($b['platform'])&&canonicalPlatform($b)!==''?json_encode([canonicalPlatform($b)]):platform_input($b)]);if(!globalScope($u))query('INSERT INTO user_shops(user_id,shop_id) VALUES(?,?)',[$u['id'],$id]);audit($u,'create_shop',$id);return ['id'=>$id];}
 if(preg_match('#^/api/shops/(\d+)$#D',$path,$m)){$id=(int)$m[1];requirePermission($u,$method==='DELETE'?'shops.delete':'shops.edit');if(!canShop($u,$id))fail(404,'店铺不存在');$r=query('SELECT * FROM shops WHERE id=? AND deleted_at IS NULL',[$id]);if(!$r)fail(404,'店铺不存在');auditRemember('shop',$id,$r[0]);
  if($method==='PUT'){$name=trim(stringField($b,'name',200));if($name==='')fail(422,'店铺名称不能为空');db()->beginTransaction();try{$live=query('SELECT * FROM shops WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]);if(!$live)fail(404,'店铺不存在');$current=$live[0];if(array_key_exists('name_from',$b)&&(!is_string($b['name_from'])||$b['name_from']!==$current['name']))fail(409,'店铺名称已被修改，请刷新后重试');$GLOBALS['_audit_before']['shop:'.$id]=array_intersect_key($current,array_flip(auditFields('shop')));$platform=canonicalPlatform($b,$current['platform'],true);$tags=$platform!==''?json_encode([$platform]):platform_input($b,$current['platforms']);if($platform!==''&&isset($b['platforms'])&&platform_normalize(platform_input($b))!==[$platform])fail(422,'独立店铺只能设置一个对应平台');if(($platform!==$current['platform']||platform_normalize($tags)!==platform_normalize($current['platforms']))&&query('SELECT sim_id FROM sim_shops WHERE shop_id=? LIMIT 1',[$id]))fail(422,'关联号码的店铺不能更换平台，请先调整号码关联');query('UPDATE shops SET name=?,platform=?,platforms=? WHERE id=?',[$name,$platform,$tags,$id]);query('UPDATE sims SET shop=? WHERE shop_id=?',[$name,$id]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}audit($u,'edit_shop',$id);return ['ok'=>true];}
  if($method==='DELETE'){
   db()->beginTransaction();try{
    $locked=query('SELECT * FROM shops WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]);if(!$locked)fail(404,'店铺不存在');
    $GLOBALS['_audit_before']['shop:'.$id]=array_intersect_key($locked[0],array_flip(auditFields('shop')));
    // Onboarding and legacy SIM writes lock the same active shop before adding a link.
    if(query('SELECT sim_id FROM sim_shops WHERE shop_id=? LIMIT 1 FOR UPDATE',[$id])||query('SELECT id FROM messages WHERE shop_id=? LIMIT 1 FOR UPDATE',[$id])||query('SELECT id FROM records WHERE shop_id=? LIMIT 1 FOR UPDATE',[$id]))fail(422,'店铺仍有号码或历史号码，请保留店铺以维护数据归属');
    query('UPDATE shops SET deleted_at=? WHERE id=?',[microtime(true),$id]);audit($u,'delete_shop',$id);db()->commit();return ['ok'=>true];
   }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
  }
 }
 if(preg_match('#^/api/admin/users/(\d+)/permissions$#D',$path,$m)){
  requirePermission($u,'users.permissions');$id=(int)$m[1];$rows=query('SELECT * FROM users WHERE id=? AND deleted_at IS NULL',[$id]);if(!$rows)fail(404,'账户不存在');$target=loadUserAccess($rows[0]);if(!canManageAccount($u,$target))fail(404,'账户不在可授权范围');
  if($method==='GET')return ['permissions'=>$target['permissions'],'all_shops'=>$target['all_shops'],'shop_ids'=>$target['shop_ids']];
  if($method==='PUT'){
   if($target['role']==='admin')fail(422,'管理员保留完整权限。请先在账户编辑中改为运营，再分配细项权限');
   $perms=$b['permissions']??null;$ids=$b['shop_ids']??null;$all=$b['all_shops']??null;if(!is_array($perms)||!array_is_list($perms)||!is_array($ids)||!array_is_list($ids)||!is_bool($all)||count($perms)>100||count($ids)>1000)fail(422,'权限与店铺范围格式错误');
   foreach($perms as $p)if(!is_string($p)||!in_array($p,permissionKeys(),true))fail(422,'包含未知权限');foreach($ids as $i)if(!is_int($i)||$i<1||!query('SELECT id FROM shops WHERE id=? AND deleted_at IS NULL',[$i]))fail(422,'包含无效店铺');
   if($u['role']!=='admin'&&(($all&&!globalScope($u))||array_diff($perms,$u['permissions'])||(!globalScope($u)&&array_diff($ids,$u['shop_ids']))))fail(403,'不能授予超出本人范围的权限或店铺');
   db()->beginTransaction();try{$locked=query('SELECT * FROM users WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]);if(!$locked||$locked[0]['role']==='admin'||!canManageAccount($u,$locked[0]))fail(422,'账户状态已变化，请刷新后重试');query('DELETE FROM user_permissions WHERE user_id=?',[$id]);query('DELETE FROM user_shops WHERE user_id=?',[$id]);foreach(array_unique($perms) as $p)query('INSERT INTO user_permissions(user_id,permission) VALUES(?,?)',[$id,$p]);foreach(array_unique($ids) as $i)query('INSERT INTO user_shops(user_id,shop_id) VALUES(?,?)',[$id,$i]);query('UPDATE users SET all_shops=? WHERE id=?',[$all?1:0,$id]);query('DELETE FROM sessions WHERE user_id=?',[$id]);audit($u,'assign_permissions',$id,['object_type'=>'user','object_name'=>$target['username'],'changes'=>[['field'=>'permissions','before'=>permissionLabels($target['permissions']),'after'=>permissionLabels(array_values(array_unique($perms)))],['field'=>'shop_ids','before'=>shopLabels($target['shop_ids']),'after'=>shopLabels(array_values(array_unique($ids)))],['field'=>'all_shops','before'=>$target['all_shops'],'after'=>$all]]]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
   return ['ok'=>true,'notice'=>'权限已保存，该账户需重新登录后使用新权限'];
  }
 }
 return null;
}
function requireAnyPermission(array $u,array $permissions): void {foreach($permissions as $p)if(can($u,$p))return;fail(403,'没有此操作权限，请联系管理员分配');}
function routePermission(array $u,string $method,string $path,array $body): void {
 $p=null;
 if($path==='/api/overview')$p='dashboard.view';
 elseif($path==='/api/sims/onboard'){$p=isset($body['sim_id'])?'sims.edit':'sims.create';if($method!=='POST')fail(404,'接口不存在');if(isset($body['sim_id']))requireWholeSim($u,intField($body,'sim_id'));}
 elseif(preg_match('#^/api/sims/(\d+)/shops$#D',$path,$m)){if($method!=='PUT')fail(404,'接口不存在');requireWholeSim($u,(int)$m[1]);$p='sims.edit';}
 elseif($path==='/api/sims'){
  if($method==='GET'){requireAnyPermission($u,['sims.view','records.balances.create','records.recharges.create','devices.bind']);return;}$p='sims.create';
 }
 elseif(preg_match('#^/api/sims/(\d+)(?:/(balances|recharges|settings))?$#D',$path,$m)){
  if($method==='GET')requireSimScope($u,(int)$m[1]);else requireWholeSim($u,(int)$m[1]);$p=isset($m[2])?['balances'=>'records.balances.create','recharges'=>'records.recharges.create','settings'=>'sims.settings'][$m[2]]:match($method){'GET'=>'sims.view','PUT'=>'sims.edit','DELETE'=>'sims.delete',default=>'sims.view'};
  if($method!=='GET')auditPrepare('sim',(int)$m[1]);
 }
 elseif($path==='/api/messages')$p='messages.view';
 elseif(preg_match('#^/api/messages/(\d+)(?:/(copy|view))?$#D',$path,$m)){requireDataScope($u,'message',(int)$m[1]);$p=isset($m[2])?($m[2]==='copy'?'messages.copy':'messages.view'):'messages.delete';if(isset($m[2]))requirePermission($u,'messages.view');auditPrepare('message',(int)$m[1]);}
 elseif($path==='/api/records')$p='records.view';
 elseif(preg_match('#^/api/records/(\d+)$#D',$path,$m)){$record=requireDataScope($u,'record',(int)$m[1]);if($method!=='GET'&&!ownsWholeSim($u,(int)$record['sim_id']))fail(403,'共享号码资金修改需要完整关联范围');$p=match($method){'GET'=>'records.view','PUT'=>'records.edit','DELETE'=>'records.delete',default=>'records.view'};auditPrepare('record',(int)$m[1]);}
 elseif($path==='/api/devices')$p='devices.view';
 elseif($path==='/api/devices/refresh-status')$p='devices.refresh';
 elseif($path==='/api/devices/refresh'){$p='devices.refresh';if(isset($body['device_ids'])&&is_array($body['device_ids']))foreach($body['device_ids'] as $id)if(is_int($id))requireDeviceScope($u,$id);}
 elseif(preg_match('#^/api/admin/devices/(\d+)/pair-number(?:/([a-f0-9]{32}))?$#D',$path,$m)){$p='devices.bind';requireDeviceScope($u,(int)$m[1],true);}
 elseif($path==='/api/admin/devices'){$p='devices.create';requirePermission($u,$p);if(isset($body['shop_ids'])){if(!is_array($body['shop_ids'])||!array_is_list($body['shop_ids']))fail(422,'店铺列表格式无效');foreach($body['shop_ids'] as $id)if(!is_int($id)||!canShop($u,$id)||!query('SELECT id FROM shops WHERE id=? AND deleted_at IS NULL',[$id]))fail(404,'店铺不在授权范围');}}
 elseif(preg_match('#^/api/admin/devices/(\d+)(?:/(secret|key|revoke))?$#D',$path,$m)){
  $op=$m[2]??'';$p=match($op){'secret'=>'devices.keys.view','key'=>'devices.keys.rotate','revoke'=>'devices.control',default=>match($method){'GET'=>'devices.view','PUT'=>'devices.edit','DELETE'=>'devices.delete',default=>'devices.view'}};requirePermission($u,$p);requireDeviceScope($u,(int)$m[1],$method!=='GET');auditPrepare('device',(int)$m[1]);
 }
 elseif($path==='/api/admin/bind'){$p='devices.bind';$d=intField($body,'device_id');$sim=intField($body,'sim_id');requireDeviceScope($u,$d,true);requireWholeSim($u,$sim);auditPrepare('sim',$sim);}
 elseif(preg_match('#^/api/admin/unbind/(\d+)$#D',$path,$m)){$p='devices.bind';requireWholeSim($u,(int)$m[1]);auditPrepare('sim',(int)$m[1]);}
 elseif($path==='/api/alerts')$p='alerts.view';
 elseif(in_array($path,['/api/alerts/dismiss','/api/alerts/undismiss'],true)){$p='alerts.dismiss';requirePermission($u,'alerts.view');}
 elseif($path==='/api/alerts/mute'){$p='alerts.mute';requirePermission($u,'alerts.view');}
 elseif($path==='/api/admin/users'){if($method==='GET'){requireAnyPermission($u,['users.view','users.permissions']);return;}$p='users.create';if(($body['role']??'operator')==='admin'&&$u['role']!=='admin')fail(403,'只有超级管理员可创建管理员账户');}
 elseif(preg_match('#^/api/admin/users/(\d+)(?:/disable)?$#D',$path,$m)){
  $p=match($method){'GET'=>'users.view','DELETE'=>'users.delete',default=>'users.edit'};$r=query('SELECT * FROM users WHERE id=? AND deleted_at IS NULL',[(int)$m[1]]);if(!$r||!canManageAccount($u,$r[0]))fail(404,'账户不存在或不在可管理范围');if(($r[0]['role']==='admin'||(isset($body['role'])&&$body['role']==='admin'))&&$u['role']!=='admin')fail(403,'只有超级管理员可管理管理员身份');auditPrepare('user',(int)$m[1]);
 }
 elseif($path==='/api/audit')$p='audit.view';
 elseif($path==='/api/admin/backup/status')$p='backup.view';
 elseif($path==='/api/admin/trash')$p='trash.view';
 elseif($path==='/api/admin/trash/clear'||preg_match('#^/api/admin/trash/(device|sim|user|record|message|shop)/(\d+)$#D',$path)){$p='trash.purge';requirePermission($u,'trash.view');}
 elseif(preg_match('#^/api/sims/(\d+)/transfer-shops$#D',$path,$m)){$p='sims.edit';requireWholeSim($u,(int)$m[1]);requireWholeSim($u,intField($body,'target_sim_id'));}
 elseif(preg_match('#^/api/admin/restore/(device|sim|user|record|message|shop)/(\d+)$#D',$path,$m)){
  $p='trash.restore';$kind=$m[1];$id=(int)$m[2];requirePermission($u,['device'=>'devices.edit','sim'=>'sims.edit','user'=>'users.edit','record'=>'records.edit','message'=>'messages.delete','shop'=>'shops.edit'][$kind]);
  if($kind==='device')requireDeviceScope($u,$id,true,true);elseif($kind==='sim')requireWholeSim($u,$id,true);elseif(in_array($kind,['message','record'],true)){$data=requireDataScope($u,$kind,$id,true);if($kind==='record'&&!ownsWholeSim($u,(int)$data['sim_id']))fail(403,'共享号码资金修改需要完整关联范围');}elseif($kind==='shop'){if(!canShop($u,$id))fail(404,'店铺不存在');}else{requirePermission($u,'users.permissions');$target=query('SELECT * FROM users WHERE id=? AND deleted_at IS NOT NULL',[$id]);if(!$target||!canManageAccount($u,$target[0]))fail(404,'账户不在可管理范围');}
  auditPrepare($kind,$id);
 }
 if($p===null)fail(404,'接口不存在');requirePermission($u,$p);
}
function scopedTrash(array $u): array {
 [$limit,$offset]=listPage();$kind=$_GET['kind']??'all';if(!is_string($kind)||!in_array($kind,['all','device','sim','user','record','message','shop'],true))fail(422,'回收站类型无效');
 $rows=trashCandidates($u,$kind);usort($rows,fn($a,$b)=>$b['deleted_at']<=>$a['deleted_at']);header('X-Total-Count: '.count($rows));$rows=array_slice($rows,$offset,$limit);
 foreach($rows as &$r){$row=trashReadAuthorize($u,$r['kind'],(int)$r['id']);$full=true;try{trashAuthorize($u,$r['kind'],(int)$r['id']);}catch(ApiError $e){$full=false;}$reason=purgeBlock($r['kind'],(int)$r['id'],$row,$u);$has=$full&&can($u,'trash.purge')&&can($u,trashPermission($r['kind']));$r['can_purge']=$has&&$reason===null;$r['can_restore']=$full&&can($u,'trash.restore')&&can($u,trashPermission($r['kind'],true));if($r['kind']==='record'&&(!$row['sim_id']||!query('SELECT id FROM sims WHERE id=? AND deleted_at IS NULL',[$row['sim_id']])))$r['can_restore']=false;$r['purge_block_reason']=$has?$reason:'缺少永久删除或对应数据删除权限';}return $rows;
}

function shopLabels(array $ids): array {if(!$ids)return [];return array_column(query('SELECT name FROM shops WHERE id IN ('.implode(',',array_map('intval',$ids)).') ORDER BY id'),'name');}

function permissionLabels(array $keys): array {$labels=[];foreach(permissionCatalog()['groups'] as $g)foreach($g['permissions'] as $p)$labels[$p['key']]=$p['label'];return array_map(fn($k)=>$labels[$k]??$k,$keys);}
function canManageAccount(array $u,array $target): bool {
 if($u['role']==='admin')return true;
 $target=isset($target['permissions'])?$target:loadUserAccess($target);
 if($target['role']==='admin'||($target['all_shops']&&!$u['all_shops']))return false;
 if(!$u['all_shops']&&array_diff($target['shop_ids'],$u['shop_ids']))return false;
 return !array_diff($target['permissions'],$u['permissions']);
}
function deviceMutation(array $u,string $method,string $path,array $b): ?array {
 if(!preg_match('#^/api/admin/devices/(\d+)(?:/(secret|key|revoke))?$#D',$path,$m)||$method==='GET')return null;
 $id=(int)$m[1];$op=$m[2]??'';db()->beginTransaction();try{
  if(!query('SELECT id FROM devices WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]))fail(404,'设备不存在');
  $d=requireDeviceScope($u,$id,true);$action='';
  if($op==='secret'&&$method==='POST'){$result=$d['secret_cipher']?['id'=>$id,'token'=>openSecret($d['secret_cipher']),'legacy'=>false]:['token'=>null,'legacy'=>true,'notice'=>'旧版摘要无法还原，请更换一次密钥'];$action='reveal_device_key';}
  elseif($op==='key'&&$method==='POST'){$result=changeKey($u,$id,$b);db()->commit();return $result;}
  elseif($op==='revoke'&&$method==='POST'){query('UPDATE devices SET revoked=1 WHERE id=?',[$id]);$result=['ok'=>true];$action='revoke_device';}
  elseif($op===''&&$method==='PUT'){query('UPDATE devices SET name=?,location=?,notes=? WHERE id=?',[nonblank($b,'name',100),stringField($b,'location',50,false),stringField($b,'notes',500,false),$id]);$result=['ok'=>true];$action='edit_device';}
  elseif($op===''&&$method==='DELETE'){query('UPDATE devices SET deleted_at=?,revoked=1 WHERE id=?',[microtime(true),$id]);query('DELETE FROM bindings WHERE device_id=?',[$id]);$result=['ok'=>true];$action='delete_device';}
  else fail(404,'接口不存在');
  audit($u,$action,$id);db()->commit();return $result;
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function bindingMutation(array $u,string $method,string $path,array $b): ?array {
 if($method!=='POST')return null;
 if($path==='/api/admin/bind'){
  $device=intField($b,'device_id');$sim=intField($b,'sim_id');$sub=intField($b,'subscription_id');db()->beginTransaction();try{
   lockBindingDevices($sim,$device);
   if(!query('SELECT id FROM devices WHERE id=? AND deleted_at IS NULL AND revoked=0 FOR UPDATE',[$device]))fail(404,'设备不存在或已停用');requireDeviceScope($u,$device,true);
   $locked=query('SELECT * FROM sims WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$sim]);if(!$locked||!ownsWholeSim($u,$sim))fail(404,'号码不在授权范围');
   $old=assertBindingSource($u,$sim,$b);if(query('SELECT sim_id FROM bindings WHERE device_id=? AND subscription_id=? FOR UPDATE',[$device,$sub]))fail(409,'目标卡槽已有绑定，请先核对');
   if(array_key_exists('phone_hint_confirmation',$b))validatePhoneHintConfirmation($device,$sub,$locked[0],$b['phone_hint_confirmation']);
   if($old)query('DELETE FROM bindings WHERE sim_id=? AND version=?',[$sim,$old['version']]);
   $version=bin2hex(random_bytes(24));query('INSERT INTO bindings(device_id,subscription_id,sim_id,version,sim_token) VALUES(?,?,?,?,?)',[$device,$sub,$sim,$version,currentReadySimToken($device,$sub)]);query('DELETE FROM sim_auto_pauses WHERE device_id=? AND sim_id=?',[$device,$sim]);syncSimDeviceScopes($sim);audit($u,'bind_sim',$sim,['summary'=>(isset($b['phone_hint_confirmation'])?'确认手机识别号码并绑定':'人工确认号码并绑定').'到设备 #'.$device.' / 订阅 #'.$sub]);db()->commit();return ['ok'=>true,'version'=>$version,'previous_binding'=>$old,'binding'=>['device_id'=>$device,'subscription_id'=>$sub,'sim_id'=>$sim,'version'=>$version]];
  }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 }
 if(preg_match('#^/api/admin/unbind/(\d+)$#D',$path,$m)){
  $sim=(int)$m[1];db()->beginTransaction();try{
   $before=query('SELECT device_id FROM bindings WHERE sim_id=?',[$sim]);if($before)lockBindingDevices($sim,(int)$before[0]['device_id']);
   $s=query('SELECT * FROM sims WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$sim]);if(!$s||!ownsWholeSim($u,$sim))fail(404,'号码不在授权范围');
   $current=query('SELECT device_id FROM bindings WHERE sim_id=? FOR UPDATE',[$sim]);
   if($current&&(!$before||(int)$before[0]['device_id']!==(int)$current[0]['device_id']))fail(409,'绑定已变化，请刷新后重试');
   if($current)query('INSERT INTO sim_auto_pauses(device_id,sim_id,paused_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE paused_at=VALUES(paused_at)',[$current[0]['device_id'],$sim,microtime(true)]);
   query('DELETE FROM bindings WHERE sim_id=?',[$sim]);audit($u,'unbind_sim',$sim);db()->commit();return ['ok'=>true];
  }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 }
 return null;
}
function requestActionLabel(string $method,string $path): string {
 if(preg_match('#^/api/admin/users/(\d+)/permissions$#D',$path,$m))return '分配店铺与权限（账户 #'.$m[1].'）';
 if(preg_match('#^/api/messages/(\d+)/(copy|view)$#D',$path,$m))return ($m[2]==='copy'?'复制验证码':'查看短信全文').'（短信 #'.$m[1].'）';
 if(preg_match('#^/api/sims/(\d+)/(balances|recharges|settings)$#D',$path,$m))return ['balances'=>'录入余额','recharges'=>'记录充值','settings'=>'修改保号提醒'][$m[2]].'（号码 #'.$m[1].'）';
 if(preg_match('#^/api/admin/devices/(\d+)/(secret|key|revoke)$#D',$path,$m))return ['secret'=>'查看密钥','key'=>'更换密钥','revoke'=>'停用设备'][$m[2]].'（设备 #'.$m[1].'）';
 if(preg_match('#^/api/admin/restore/([^/]+)/(\d+)$#D',$path,$m))return '恢复'.(['sim'=>'号码','device'=>'设备','message'=>'短信','record'=>'流水','user'=>'账户','shop'=>'店铺'][$m[1]]??'数据').' #'.$m[2];
 if(preg_match('#^/api/(?:admin/)?(sims|devices|users|shops|records|messages)(?:/(\d+))?$#D',$path,$m))return (['GET'=>'查看','POST'=>'新增','PUT'=>'编辑','DELETE'=>'删除'][$method]??$method).['sims'=>'号码','devices'=>'设备','users'=>'账户','shops'=>'店铺','records'=>'流水','messages'=>'短信'][$m[1]].(isset($m[2])?' #'.$m[2]:'');
 return ['GET'=>'查看','POST'=>'提交','PUT'=>'修改','DELETE'=>'删除'][$method]??'请求';
}

function dataScopeSql(array $u,string $alias='',bool $message=true): string {
 $p=$alias===''?'':$alias.'.';$shop=$p.'shop_id';$sim=$p.'sim_id';
 $linked=scopeSql($u,$shop).' AND '.$shop.' IS NOT NULL';
 $unlinked=$shop.' IS NULL AND '.$p.'owner_id='.(int)$u['id'];
 if(!$message&&globalScope($u)||$message&&$u['role']==='admin')$unlinked=$shop.' IS NULL';
 $or=$message&&globalScope($u)&&can($u,'messages.unassigned')?' OR ('.$shop.' IS NULL AND '.$sim.' IS NULL AND '.$p."owner_id IS NULL AND ".$p."phone_snapshot='')":'';
 return '(('.$linked.') OR ('.$unlinked.')'.$or.')';
}
