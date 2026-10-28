<?php
declare(strict_types=1);
/** Physical ownership always comes from the bridge; shop_id is a compatibility primary. */
function simShops(int $id): array {
 return array_map('platform_enrich_shop',query('SELECT sh.id,sh.name,sh.platform,sh.platforms FROM sim_shops ss JOIN shops sh ON sh.id=ss.shop_id WHERE ss.sim_id=? ORDER BY sh.id',[$id]));
}
function simShopIds(int $id): array {return array_map('intval',array_column(simShops($id),'id'));}
function ownsWholeSim(array $u,int $id): bool {$ids=simShopIds($id);return globalScope($u)||($ids?!array_diff($ids,$u['shop_ids']):ownsUnlinkedSim($u,$id));}
function requireWholeSim(array $u,int $id,bool $deleted=false): array {$s=requireSimScope($u,$id,$deleted);if(!ownsWholeSim($u,$id))fail(403,'共享号码需要完整关联店铺范围');return $s;}
function ownsUnlinkedSim(array $u,int $id): bool {return (bool)query('SELECT id FROM sims WHERE id=? AND created_by=? AND NOT EXISTS(SELECT 1 FROM sim_shops WHERE sim_id=sims.id)',[$id,$u['id']]);}
function simScopeSql(array $u,string $id): string {if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/D',$id))throw new LogicException('scope column');return globalScope($u)?'1=1':'(EXISTS (SELECT 1 FROM sim_shops scope_link WHERE scope_link.sim_id='.$id.' AND '.scopeSql($u,'scope_link.shop_id').') OR (EXISTS(SELECT 1 FROM sims scope_owner WHERE scope_owner.id='.$id.' AND scope_owner.created_by='.(int)$u['id'].') AND NOT EXISTS(SELECT 1 FROM sim_shops scope_empty WHERE scope_empty.sim_id='.$id.')))';}
function syncSimDeviceScopes(int $sim): void {query('INSERT IGNORE INTO device_scopes(device_id,shop_id) SELECT b.device_id,ss.shop_id FROM bindings b JOIN sim_shops ss ON ss.sim_id=b.sim_id JOIN devices d ON d.id=b.device_id WHERE b.sim_id=? AND d.created_by IS NULL',[$sim]);}
function linkedShopDisplay(int $sim): string {$labels=array_column(platform_options(),'label','value');return implode(' / ',array_map(fn($s)=>isset($labels[$s['platform']])?$labels[$s['platform']].' · '.$s['name']:$s['name'],simShops($sim)));}
function messageShopRoute(int $sim,?string $platform): ?array {
 $shops=simShops($sim);if(count($shops)===1&&($platform===null||($shops[0]['platform']===''&&!$shops[0]['platforms'])))return $shops[0];
 if($platform===null)return null;
 $matches=array_values(array_filter($shops,fn($s)=>$s['platform']===$platform||($s['platform']===''&&in_array($platform,$s['platforms'],true))));return count($matches)===1?$matches[0]:null;
}
function canonicalPlatform(array $b,string $fallback='',bool $historical=false): string {$p=$b['platform']??$fallback;if($historical&&$p===$fallback&&platform_stored_key($p))return $p;if(!is_string($p)||!in_array($p,array_merge([''],platform_keys()),true))fail(422,'店铺平台无效');return $p;}
/** Resolve/create independent shops inside the caller's transaction. */
function resolveLinkedShops(array &$u,mixed $input,?int $preserveSim=null): array {
 if(!is_array($input)||!array_is_list($input)||($u['role']!=='admin'&&count($input)>16))fail(422,'关联店铺数量超过平台目录上限');$out=[];$seen=[];
 foreach($input as $entry){
  if(!is_array($entry))fail(422,'店铺格式无效');$shop=null;
  if(isset($entry['shop_id'])){
   if(isset($entry['name']))fail(422,'请选择已有店铺或填写新店铺名称');$sid=intField($entry,'shop_id');$rows=query('SELECT * FROM shops WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$sid]);if(!$rows||!canShop($u,$sid))fail(404,'店铺不存在或不在授权范围');$shop=$rows[0];
  }
  $keep=$shop!==null&&$preserveSim!==null&&in_array($sid,simShopIds($preserveSim),true);
  $platform=canonicalPlatform($entry,$shop['platform']??'',$keep);
  if($u['role']!=='admin'&&(($platform===''&&!isset($entry['shop_id']))||isset($seen[$platform])))fail(422,'每个平台只能关联一家店铺');$seen[$platform]=true;
  if($shop!==null){
   if($u['role']!=='admin'&&$shop['platform']!==$platform&&!($shop['platform']===''&&in_array($platform,platform_normalize($shop['platforms']),true)))fail(422,'店铺平台与所选平台不符');
   if(array_key_exists('rename',$entry)){
    requirePermission($u,'shops.edit');$name=nonblank($entry,'rename',200);
    if(!isset($entry['rename_from'])||!is_string($entry['rename_from'])||$entry['rename_from']!==$shop['name'])fail(409,'店铺名称已被修改，请刷新后重试');
    if($name!==$shop['name']){auditRemember('shop',$sid,$shop);query('UPDATE shops SET name=? WHERE id=?',[$name,$sid]);query('UPDATE sims SET shop=? WHERE shop_id=?',[$name,$sid]);$shop['name']=$name;audit($u,'edit_shop',$sid);}
   }
  }else{
   requirePermission($u,'shops.create');$name=nonblank($entry,'name',200);$sid=insert('INSERT INTO shops(name,platform,platforms) VALUES(?,?,?)',[$name,$platform,json_encode([$platform])]);$shop=['id'=>$sid,'name'=>$name,'platform'=>$platform];
   if(!globalScope($u)){query('INSERT IGNORE INTO user_shops(user_id,shop_id) VALUES(?,?)',[$u['id'],$sid]);$u['shop_ids'][]=(int)$sid;}audit($u,'create_shop',$sid,['shop_ids'=>[$sid]]);
  }
  if(isset($out[$sid])){if($u['role']==='admin')continue;fail(422,'不能重复选择同一家店铺');}
  if(isset($entry['operator_id'])){
   requirePermission($u,'users.permissions');$operator=intField($entry,'operator_id');$target=query('SELECT * FROM users WHERE id=? AND active=1 AND deleted_at IS NULL FOR UPDATE',[$operator]);if(!$target||$target[0]['role']!=='operator'||!canManageAccount($u,$target[0]))fail(404,'运营账户不在可管理范围');
   query('INSERT IGNORE INTO user_shops(user_id,shop_id) VALUES(?,?)',[$operator,$sid]);query('DELETE FROM sessions WHERE user_id=?',[$operator]);audit($u,'assign_permissions',$operator,['object_type'=>'user','object_name'=>$target[0]['username'],'shop_ids'=>[$sid],'summary'=>'追加店铺授权：'.$shop['name']]);
  }
  $out[$sid]=['id'=>(int)$sid,'name'=>$shop['name'],'platform'=>$shop['platform']];
 }
 if($u['role']!=='admin')validateLinkedShopPlatforms(array_values($out));
 return array_values($out);
}
function validateLinkedShopPlatforms(array $shops): void {
 // Legacy groups may cover several platforms. Reject overlaps that make routing ambiguous.
 $claims=[];foreach($shops as $shop){$s=query('SELECT platform,platforms FROM shops WHERE id=?',[$shop['id']])[0];foreach($s['platform']!==''?[$s['platform']]:platform_normalize($s['platforms']) as $p){if(isset($claims[$p]))fail(422,'该平台已关联其他店铺，请使用号码关联编辑更换店铺');$claims[$p]=true;}}
}
function saveSimLinks(array $u,int $id,array $shops): void {
 $before=simShopIds($id);query('DELETE FROM sim_shops WHERE sim_id=?',[$id]);foreach($shops as $shop)query('INSERT INTO sim_shops(sim_id,shop_id) VALUES(?,?)',[$id,$shop['id']]);
 $s=query('SELECT shop_id FROM sims WHERE id=?',[$id])[0];$ids=array_column($shops,'id');$primary=(int)$s['shop_id'];if(!in_array($primary,$ids,true))$primary=$shops?(int)$shops[0]['id']:null;$name=$primary?query('SELECT name FROM shops WHERE id=?',[$primary])[0]['name']:'';query('UPDATE sims SET shop_id=?,shop=? WHERE id=?',[$primary,$name,$id]);syncSimDeviceScopes($id);refreshBalance($id);
 audit($u,'edit_sim',$id,['shop_ids'=>array_values(array_unique(array_merge($before,$ids))),'summary'=>'更新号码关联店铺','changes'=>[['field'=>'shop_ids','before'=>$before,'after'=>$ids]]]);
}
/** Used inside a transaction; deleting a label does not delete a physical SIM or its history. */
function detachShopLinks(array $u,int $shop): void {
 $ids=array_map('intval',array_column(query('SELECT sim_id AS id FROM sim_shops WHERE shop_id=? UNION SELECT id FROM sims WHERE shop_id=?',[$shop,$shop]),'id'));sort($ids);
 foreach($ids as $sim){query('SELECT id FROM sims WHERE id=? FOR UPDATE',[$sim]);$remaining=array_values(array_filter(simShops($sim),fn($s)=>(int)$s['id']!==$shop));saveSimLinks($u,$sim,$remaining);}
 query('DELETE FROM device_scopes WHERE shop_id=?',[$shop]);query('UPDATE mail_accounts SET shop_id=NULL WHERE shop_id=?',[$shop]);
}
function simLinkRoute(array $u,string $method,string $path,array $b): ?array {
 $onboard=$path==='/api/sims/onboard'&&$method==='POST';$edit=$method==='PUT'&&preg_match('#^/api/sims/(\d+)/shops$#D',$path,$m);if(!$onboard&&!$edit)return null;
 db()->beginTransaction();try{
  $created=false;
  if($edit){$id=(int)$m[1];query('SELECT id FROM sims WHERE id=? FOR UPDATE',[$id]);$existing=requireWholeSim($u,$id);auditRemember('sim',$id,$existing);
   if(array_key_exists('number',$b)){
    if(!is_array($b['number']))fail(422,'号码资料格式无效');$number=$b['number'];[$phone,$carrier,$country,$currency]=simIdentity($number);
    if($u['role']!=='admin'&&$country!==$existing['country']&&query('SELECT id FROM records WHERE sim_id=? LIMIT 1',[$id]))fail(422,'已有资金流水的号码不能更换国家或币种');
    query('UPDATE sims SET phone=?,carrier=?,country=?,currency=?,location=? WHERE id=?',[$phone,$carrier,$country,$currency,stringField($number,'location',50,false),$id]);
   }
  }
  elseif(isset($b['sim_id'])){$id=intField($b,'sim_id');query('SELECT id FROM sims WHERE id=? FOR UPDATE',[$id]);$s=requireWholeSim($u,$id);[$phone]=simIdentity($b);if($phone!==$s['phone'])fail(422,'复用号码与手机号不一致');}
  else{[$phone,$carrier,$country,$currency]=simIdentity($b);if(query('SELECT id FROM sims WHERE phone=? FOR UPDATE',[$phone]))fail(409,'号码已存在，请显式选择有权限的号码复用');$created=true;}
  $shops=resolveLinkedShops($u,$b['shops']??null,$created?null:$id);
  if($onboard&&!$created){$merged=[];foreach(simShops($id) as $shop)$merged[(int)$shop['id']]=array_intersect_key($shop,array_flip(['id','name','platform']));foreach($shops as $shop)$merged[$shop['id']]=$shop;$shops=array_values($merged);if($u['role']!=='admin')validateLinkedShopPlatforms($shops);}
  if($created){$id=insert('INSERT INTO sims(phone,carrier,country,currency,location,shop,shop_id,created_by) VALUES(?,?,?,?,?,?,?,?)',[$phone,$carrier,$country,$currency,stringField($b,'location',50,false),$shops[0]['name']??'',$shops[0]['id']??null,$u['id']]);audit($u,'create_sim',$id,['shop_ids'=>array_column($shops,'id')]);}
  saveSimLinks($u,$id,$shops);$result=['id'=>$id,'created'=>$created,'shops'=>$shops];
  if(array_key_exists('binding',$b)){
   requirePermission($u,'devices.bind');if(!is_array($b['binding']))fail(422,'绑定格式无效');$device=intField($b['binding'],'device_id');$sub=intField($b['binding'],'subscription_id');query('SELECT id FROM devices WHERE id=? FOR UPDATE',[$device]);requireDeviceScope($u,$device,true);if(query('SELECT id FROM devices WHERE id=? AND revoked=0 AND deleted_at IS NULL',[$device])===[])fail(404,'设备已停用');
   if(array_key_exists('phone_hint_confirmation',$b['binding']))validatePhoneHintConfirmation($device,$sub,query('SELECT * FROM sims WHERE id=?',[$id])[0],$b['binding']['phone_hint_confirmation']);
   $version=bin2hex(random_bytes(24));query('INSERT INTO bindings(device_id,subscription_id,sim_id,version,sim_token) VALUES(?,?,?,?,?)',[$device,$sub,$id,$version,currentReadySimToken($device,$sub)]);query('DELETE FROM sim_auto_pauses WHERE device_id=? AND sim_id=?',[$device,$id]);syncSimDeviceScopes($id);audit($u,'bind_sim',$id,['shop_ids'=>array_column($shops,'id'),'summary'=>(isset($b['binding']['phone_hint_confirmation'])?'确认手机识别号码并绑定':'人工确认号码并绑定').'到设备 #'.$device.' / 订阅 #'.$sub]);$result['binding']=['device_id'=>$device,'subscription_id'=>$sub,'version'=>$version];
  }
  db()->commit();return $result;
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
