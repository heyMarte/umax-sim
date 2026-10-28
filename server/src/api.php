<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/management.php';

function legacyRoute(string $method,string $path,array $b): mixed {
    $now=microtime(true);
    if ($method==='GET' && $path==='/health') {
        query('SELECT 1');
        try{$ready=query('SELECT version FROM schema_versions WHERE version=?',[SIM_VERSION]);}catch(PDOException $e){if(($e->errorInfo[1]??null)===1146)fail(503,'程序已更新，数据库尚未升级，请先执行 migrate');throw $e;}
        if(!$ready)fail(503,'数据库需要升级，请先执行 migrate');masterKey();return ['ok'=>true,'version'=>SIM_VERSION];
    }
    if ($method==='POST' && $path==='/api/login') {
        $name=stringField($b,'username',100);$password=stringField($b,'password',256);
        $ip=substr($_SERVER['REMOTE_ADDR']??'unknown',0,64);
        // 原子计数使并发尝试也受限。仅信任 TCP 来源，不信任任意代理请求头。
        query('INSERT INTO attempts(ip,count,since) VALUES(?,1,?) ON DUPLICATE KEY UPDATE count=IF(since<? ,1,count+1),since=IF(since<? ,VALUES(since),since)',[$ip,$now,$now-900,$now-900]);
        $attempt=query('SELECT count FROM attempts WHERE ip=?',[$ip])[0];
        if ((int)$attempt['count']>10) fail(429,'登录尝试过多，请15分钟后重试');
        $u=query('SELECT * FROM users WHERE username=? AND active=1 AND deleted_at IS NULL',[$name]);
        if (!$u || !password_verify($password,$u[0]['password'])) fail(401,'账号或密码错误');
        query('DELETE FROM attempts WHERE ip=?',[$ip]);
        $token=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(32));
        query('INSERT INTO sessions(token,user_id,csrf,expires) VALUES(?,?,?,?)',[hash('sha256',$token),$u[0]['id'],$csrf,$now+28800]);
        setcookie('session',$token,['expires'=>time()+28800,'path'=>'/','secure'=>settings()['secure_cookie']??true,'httponly'=>true,'samesite'=>'Strict']);
        audit($u[0],'login',$u[0]['id']);
        return ['csrf'=>$csrf,'role'=>$u[0]['role']];
    }
    if (str_starts_with($path,'/api/device/')) {
        $d=device();
        if($method==='POST'&&$path==='/api/device/app-update'){require_once __DIR__.'/online_updates.php';return onlineDeviceManifest();}
        if($method==='POST'&&$path==='/api/device/poll')return refreshPoll((int)$d['id']);
        if($method==='POST'&&$path==='/api/device/check')return startReceiveCheck($d,$b);
        if($method==='POST'&&$path==='/api/device/check-status')return receiveCheckStatus($d,$b);
        if ($method==='POST' && $path==='/api/device/heartbeat') {
            return acceptHeartbeat($d,$b);
        }
        if ($method==='POST' && $path==='/api/device/messages') {
            $protocol=simObservationVersion($b);$events=$b['events']??null;
            if (!is_array($events)||!array_is_list($events)||count($events)>100) fail(422,'events 必须为最多100项的数组');
            $valid=[];
            foreach($events as $e) {
                if (!is_array($e)) fail(422,'短信数据格式错误');
                $id=stringField($e,'event_id',100);
                if (!preg_match('/^[\x21-\x7e]+$/D',$id)) fail(422,'event_id 必须为可打印 ASCII 标识');
                $sub=intField($e,'subscription_id',true);
                $version=$e['binding_version']??null;
                if ($version!==null && (!is_string($version)||strlen($version)>100)) fail(422,'绑定版本格式错误');
                $sender=stringField($e,'sender',200,false);$body=stringField($e,'body',10000,false);
                $received=$e['received_at']??null;
                if ((!is_int($received)&&!is_float($received))||!is_finite((float)$received)||$received<0) fail(422,'短信时间格式错误');
                $valid[]=[$id,$sub,$version,$sender,$body,$received,simToken($e['sim_token']??null)];
            }
            db()->beginTransaction();
            try {
                if(!query('SELECT id FROM devices WHERE id=? AND revoked=0 AND deleted_at IS NULL FOR UPDATE',[$d['id']]))fail(401,'设备已停用');
                foreach($valid as [$id,$sub,$version,$sender,$body,$received,$token]) {
                    if(query('SELECT event_id FROM message_tombstones WHERE device_id=? AND event_id=?',[$d['id'],$id]))continue;
                    consumeNumberPairing($d,$id,$sub,$body,(float)$received,$now);
                    $binding=query('SELECT b.sim_id,b.subscription_id,b.sim_token,s.shop_id,s.phone,s.shop,s.created_by FROM bindings b JOIN sims s ON b.sim_id=s.id WHERE b.device_id=? AND b.subscription_id=? AND b.version=? FOR UPDATE',[$d['id'],$sub,$version]);
                    if($binding){
                        $anchor=$binding[0]['sim_token'];
                        if(($protocol===1&&($token===null||$anchor===null||!hash_equals($anchor,$token)))||($protocol!==1&&$anchor!==null))$binding=[];
                        elseif($anchor!==null){
                            $state=query('SELECT payload FROM device_telemetry WHERE device_id=?',[$d['id']]);$observation=json_decode($state[0]['payload']??'{}',true)?:[];
                            $current=query('SELECT subscriptions FROM devices WHERE id=?',[$d['id']]);$subs=json_decode($current[0]['subscriptions']??'[]',true)?:[];
                            if(!simBindingActionable($binding[0],$subs,$observation['subscriptions_status']??'legacy',$observation['sim_observation_version']??null))$binding=[];
                        }
                    }
                    $inferredPlatform=platform_message_infer($sender,$body);$messageShop=isset($binding[0])?messageShopRoute((int)$binding[0]['sim_id'],$inferredPlatform):null;
                    // 重复 event_id 只确认，不重新计算旧短信归属。
                    $saveStatement=db()->prepare('INSERT INTO messages(device_id,event_id,sim_id,subscription_id,sender,body,received_at,uploaded_at,shop_id,phone_snapshot,shop_snapshot,platform,otp_code,owner_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE event_id=VALUES(event_id)');$saveStatement->execute([$d['id'],$id,$binding[0]['sim_id']??null,$sub,$sender,$body,$received,$now,$messageShop['id']??null,$binding[0]['phone']??'',$messageShop['name']??'',$inferredPlatform,messageOtp($sender,$body)?:null,isset($binding[0])&&!simShopIds((int)$binding[0]['sim_id'])?($binding[0]['created_by']??null):null]);
                    $freshMessage=$saveStatement->rowCount()===1;$saved=query('SELECT id FROM messages WHERE device_id=? AND event_id=?',[$d['id'],$id]);markReceiveCheck($d,(int)$saved[0]['id']);if($freshMessage)wxEnqueue((int)$saved[0]['id']);
                }
                query('UPDATE devices SET heartbeat=? WHERE id=?',[$now,$d['id']]);
                db()->commit();
            } catch(Throwable $e) { if(db()->inTransaction())db()->rollBack();throw $e; }
            return ['accepted'=>array_column($valid,0)];
        }
        fail(404,'接口不存在');
    }
    $u=user();
    if($method==='GET'&&$path==='/api/admin/backup/status'){require_once __DIR__.'/backup.php';return backupStatus();}
    if($method==='POST'&&$path==='/api/devices/refresh-status')return snapshotRequestStatuses($u,$b);
    if($method==='POST'&&$path==='/api/devices/refresh')return requestSnapshots($u,$b);
    $managed=managementRoute($method,$path,$b,$u);if($managed!==null)return $managed;
    if($method==='GET'&&$path==='/api/alerts')return alertResponse($u);
    if($method==='POST'&&$path==='/api/alerts/mute'){
        $key=stringField($b,'key',150);$duration=intField($b,'duration');
        if(!in_array($duration,[600,3600,86400],true))fail(422,'暂缓时长无效');
        $items=buildAlerts(deviceRows(),simRows(),microtime(true));if(!in_array($key,array_column($items,'key'),true))fail(404,'该异常已恢复，请刷新');
        query('INSERT INTO alert_mutes(user_id,alert_key,until_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE until_at=VALUES(until_at)',[$u['id'],$key,microtime(true)+$duration]);audit($u,'mute_alert',$key);return ['ok'=>true];
    }
    if ($method==='GET' && $path==='/api/me') return array_intersect_key($u,array_flip(['id','username','role','csrf']));
    if ($method==='POST' && $path==='/api/logout') {
        query('DELETE FROM sessions WHERE token=?',[hash('sha256',$_COOKIE['session']??'')]);
        setcookie('session','',['expires'=>time()-3600,'path'=>'/','secure'=>settings()['secure_cookie']??true,'httponly'=>true,'samesite'=>'Strict']);
        return ['ok'=>true];
    }
    if ($path==='/api/admin/users') {
        if ($method==='GET') {$rows=query('SELECT * FROM users WHERE deleted_at IS NULL ORDER BY id');$out=[];foreach($rows as $r)if(canManageAccount($u,$r)){$r=loadUserAccess($r);$out[]=array_intersect_key($r,array_flip(['id','username','role','active','all_shops','shop_ids']));}return $out;}
        if ($method==='POST') {
            $name=stringField($b,'username',100);createUser($name,stringField($b,'password',256),stringField($b,'role',16));
            audit($u,'create_user',$name,['object_name'=>$name]);return ['ok'=>true];
        }
    }
    if ($method==='POST' && preg_match('#^/api/admin/users/(\d+)/disable$#D',$path,$m)) {
        if ((int)$m[1]===(int)$u['id']) fail(422,'不能禁用自己');
        query('UPDATE users SET active=0 WHERE id=?',[$m[1]]);audit($u,'disable_user',$m[1]);return ['ok'=>true];
    }
    if ($method==='POST' && $path==='/api/admin/devices') {
        $name=stringField($b,'name',100);$custom=array_key_exists('token',$b)?stringField($b,'token',1000000,false):'';$token=$custom!==''?$custom:randomDeviceKey();
        $id=insert('INSERT INTO devices(name,token,secret_cipher,created_by) VALUES(?,?,?,?)',[$name,hash('sha256',$token),sealSecret($token),$u['id']]);
        foreach(array_unique($b['shop_ids']??[]) as $shopId)query('INSERT IGNORE INTO device_scopes(device_id,shop_id) VALUES(?,?)',[$id,$shopId]);audit($u,'create_device',$id);return ['id'=>$id,'token'=>$token,'name'=>$name];
    }
    if ($method==='GET' && $path==='/api/devices') return deviceRows();
    if ($method==='POST' && preg_match('#^/api/admin/devices/(\d+)/revoke$#D',$path,$m)) {
        query('UPDATE devices SET revoked=1 WHERE id=?',[$m[1]]);audit($u,'revoke_device',$m[1]);return ['ok'=>true];
    }
    if ($method==='POST' && $path==='/api/admin/bind') {
        $device=intField($b,'device_id');$sub=intField($b,'subscription_id');$sim=intField($b,'sim_id');
        if (!query('SELECT id FROM devices WHERE id=? AND revoked=0 AND deleted_at IS NULL',[$device])||!query('SELECT id FROM sims WHERE id=? AND deleted_at IS NULL',[$sim])) fail(422,'设备或卡不存在');
        $version=bin2hex(random_bytes(24));
        query('INSERT INTO bindings(device_id,subscription_id,sim_id,version) VALUES(?,?,?,?)',[$device,$sub,$sim,$version]);
        syncSimDeviceScopes($sim);audit($u,'bind_sim',$sim,['summary'=>'绑定号码到设备 #'.$device.' / 订阅 #'.$sub]);return ['ok'=>true,'version'=>$version];
    }
    if ($method==='POST' && preg_match('#^/api/admin/unbind/(\d+)$#D',$path,$m)) {
        query('DELETE FROM bindings WHERE sim_id=?',[$m[1]]);audit($u,'unbind_sim',$m[1]);return ['ok'=>true];
    }
    if ($path==='/api/sims') {
        if ($method==='GET'){$filter=platform_filter();return array_values(array_filter(simRows(),fn($row)=>platform_matches($row['platforms']??[],$filter)));}
        if ($method==='POST') {
            [$phone,$carrier,$country,$currency]=simIdentity($b);
            $shop=resolveShop($u,$b);db()->beginTransaction();try{$shop=query('SELECT * FROM shops WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$shop['id']])[0]??null;if(!$shop)fail(404,'店铺不可用，请刷新后重试');$id=insert('INSERT INTO sims(phone,carrier,country,currency,location,shop,shop_id,created_by) VALUES(?,?,?,?,?,?,?,?)',[$phone,$carrier,$country,$currency,stringField($b,'location',50),$shop['name'],$shop['id'],$u['id']]);query('INSERT INTO sim_shops(sim_id,shop_id) VALUES(?,?)',[$id,$shop['id']]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
            audit($u,'create_sim',$id);return ['id'=>$id];
        }
    }
    if ($method==='GET' && $path==='/api/messages') {
        $search=$_GET['q']??'';if(!is_string($search))fail(422,'搜索格式错误');
        $q='%'.mb_substr($search,0,100).'%';
        $kind=$_GET['kind']??'otp';if(!is_string($kind)||!in_array($kind,['otp','all'],true))fail(422,'短信类型筛选无效');
        $page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if($page===false)fail(422,'页码无效');
        $offset=($page-1)*50;
        $status=$_GET['status']??'all';
        if(!is_string($status)||!in_array($status,['all','unassigned'],true))fail(422,'短信筛选条件无效');
        $where='m.deleted_at IS NULL AND m.uploaded_at>=? AND ((m.body LIKE ? AND (m.otp_code IS NOT NULL OR '.($u['role']==='admin'?'1=1':'0=1').')) OR m.shop_snapshot LIKE ? OR m.phone_snapshot LIKE ?)'.($status==='unassigned'?' AND m.shop_id IS NULL':'');
        $where.=' AND '.dataScopeSql($u,'m');
        $params=[$now-604800,$q,$q,$q];if($kind==='otp')$where.=' AND m.otp_code IS NOT NULL';
        $platform=platform_filter();if($platform==='unknown')$where.=' AND m.platform IS NULL';elseif($platform!=='all'){$where.=' AND m.platform=?';$params[]=$platform;}
        $total=query('SELECT COUNT(*) AS total FROM messages m LEFT JOIN sims s ON m.sim_id=s.id WHERE '.$where,$params)[0]['total'];
        header('X-Total-Count: '.(int)$total);
        $rows=query('SELECT m.*,m.phone_snapshot AS phone,m.shop_snapshot AS shop FROM messages m LEFT JOIN sims s ON m.sim_id=s.id WHERE '.$where.' ORDER BY m.id DESC LIMIT 50 OFFSET '.(int)$offset,$params);
        foreach($rows as &$row){$row=platform_enrich_message(platform_attach($row));$row=messageListRow($row,$u);}unset($row);
        audit($u,'view_messages','page:'.$page,['object_type'=>'query','object_name'=>'短信列表（第'.$page.'页）','shop_name'=>implode('、',array_unique(array_column($rows,'shop'))),'summary'=>'读取短信列表，第'.$page.'页，'.count($rows).'条','shop_ids'=>array_values(array_unique(array_filter(array_map(fn($r)=>$r['shop_id']===null?null:(int)$r['shop_id'],$rows))))]);
        return $rows;
    }
    if ($method==='POST' && preg_match('#^/api/messages/(\d+)/(copy|view)$#D',$path,$m)) {
        $message=requireDataScope($u,'message',(int)$m[1]);$code=$message['otp_code']??'';
        if($code===''&&$u['role']!=='admin')fail(403,'无验证码短信仅管理员可查看');
        if($m[2]==='copy'&&$code==='')fail(422,'此短信未识别到验证码');
        audit($u,$m[2]==='copy'?'copy_code':($code===''?'view_private_message':'view_message'),$m[1]);return ['ok'=>true,'body'=>$message['body'],'code'=>$code];
    }
    if ($method==='POST' && preg_match('#^/api/sims/(\d+)/(balances|recharges|settings)$#D',$path,$m)) {
        $id=(int)$m[1];$kind=$m[2];
        if(!query('SELECT id FROM sims WHERE id=? AND deleted_at IS NULL',[$id]))fail(404,'卡片不存在');
        if($kind==='settings') {
            db()->beginTransaction();try{$locked=query('SELECT * FROM sims WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]);if(!$locked||!ownsWholeSim($u,$id))fail(404,'号码所属店铺已变化，请刷新后重试');query('UPDATE sims SET threshold=?,package_expires=?,preservation_status=?,next_check=? WHERE id=?',[amount($b,'threshold'),dateField($b,'package_expires'),stringField($b,'preservation_status',200),dateField($b,'next_check'),$id]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        } else {
            $a=amount($b);$note=stringField($b,'note',500,false);
            db()->beginTransaction();
            try {
                if(!($locked=query('SELECT * FROM sims WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id])))fail(404,'号码已删除');
                if(!ownsWholeSim($u,$id))fail(404,'号码不在授权范围');
                $recordShop=isset($b['shop_id'])?intField($b,'shop_id'):($locked[0]['shop_id']===null?null:(int)$locked[0]['shop_id']);if($recordShop!==null&&(!in_array($recordShop,simShopIds($id),true)||!canShop($u,$recordShop)))fail(404,'流水店铺不在号码关联范围');$recordName=$recordShop===null?'':query('SELECT name FROM shops WHERE id=?',[$recordShop])[0]['name'];query('INSERT INTO records(sim_id,kind,amount,at,note,shop_id,phone_snapshot,shop_snapshot,owner_id,currency_snapshot) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$kind,$a,$now,$note,$recordShop,$locked[0]['phone'],$recordName,$locked[0]['created_by'],$locked[0]['currency']]);
                if($kind==='balances')refreshBalance($id);
                db()->commit();
            }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        }
        audit($u,$kind,$id);return ['ok'=>true];
    }
    if ($method==='GET' && $path==='/api/records'){[$limit,$offset]=listPage();header('X-Total-Count: '.query('SELECT COUNT(*) n FROM records WHERE deleted_at IS NULL AND '.dataScopeSql($u,'',false))[0]['n']);return query('SELECT r.*,r.currency_snapshot currency,r.phone_snapshot AS phone,r.shop_snapshot AS shop FROM records r LEFT JOIN sims s ON s.id=r.sim_id WHERE r.deleted_at IS NULL AND '.dataScopeSql($u,'r',false).' ORDER BY r.id DESC LIMIT '.$limit.' OFFSET '.$offset);}
    if ($method==='GET' && $path==='/api/audit'){[$limit,$offset]=listPage();header('X-Total-Count: '.query('SELECT COUNT(*) n FROM audit')[0]['n']);return query('SELECT * FROM audit ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset);}
    if ($method==='GET' && $path==='/api/overview') {
        $ss=simRows();$dd=deviceRows();
        return ['total'=>count($ss),'online'=>count(array_filter($dd,fn($d)=>$d['online'])),'devices'=>count($dd),'unknown_balance'=>count(array_filter($ss,fn($s)=>$s['balance']===null)),'low_balance'=>count(array_filter($ss,fn($s)=>$s['balance']!==null && (float)$s['balance']<(float)$s['threshold']))];
    }
    fail(404,'接口不存在');
}

function route(string $method,string $path,array $b): mixed {
 if($path==='/health'||$path==='/api/login'||str_starts_with($path,'/api/device/'))return legacyRoute($method,$path,$b);
 $u=user();$GLOBALS['request_user']=$u;header('X-Access-Context: '.$u['access_context']);
 if($method==='GET'&&$path==='/api/me')return array_intersect_key($u,array_flip(['id','username','role','csrf','permissions','all_shops','shop_ids','access_context']))+['platform_catalog'=>platform_options()];
 if($method==='POST'&&$path==='/api/logout'){$r=legacyRoute($method,$path,$b);audit($u,'logout',$u['id']);return $r;}
 try{
  if(str_starts_with($path,'/api/admin/updates/')){require_once __DIR__.'/online_updates.php';onlineAdmin($u);if($method==='GET'&&$path==='/api/admin/updates/status')return onlineStatus();if($method==='POST'&&$path==='/api/admin/updates/publish-staged-app'){require_once __DIR__.'/app_release.php';return appPublishStaged($u,stringField($b,'id',32));}if($method==='POST'&&$path==='/api/admin/updates/cancel')return onlineCancel($u,stringField($b,'id',32));if($method==='POST'&&$path==='/api/admin/updates/apply')return onlineApply($u,stringField($b,'id',32));if($method==='POST'&&$path==='/api/admin/updates/publish-app')return onlinePublish($u,stringField($b,'id',32));fail(404,'更新接口不存在');}
  $mail=mailRoute($u,$method,$path,$b);if($mail!==null)return $mail;
  $wechat=wxRoute($u,$method,$path,$b);if($wechat!==null)return $wechat;
  $acl=aclRoute($method,$path,$b,$u);if($acl!==null)return $acl;
  routePermission($u,$method,$path,$b);
  if($method==='GET'&&$path==='/api/admin/trash')return scopedTrash($u);
  if($method==='GET'&&$path==='/api/audit')return scopedAuditRows($u);
  $pairResult=numberPairingRoute($u,$method,$path,$b);if($pairResult!==null)return $pairResult;
  $deviceResult=deviceMutation($u,$method,$path,$b);if($deviceResult!==null)return $deviceResult;
  if($method==='POST'&&$path==='/api/sims'&&(isset($b['shops'])||(!isset($b['shop_id'])&&!isset($b['shop']))))return simLinkRoute($u,'POST','/api/sims/onboard',$b+['shops'=>[]]);
  if($method==='PUT'&&preg_match('#^/api/sims/(\d+)$#D',$path,$sm)&&(!isset($b['shop_id'])&&!isset($b['shop'])))return simLinkRoute($u,'PUT','/api/sims/'.$sm[1].'/shops',['number'=>$b,'shops'=>$b['shops']??array_map(fn($s)=>['shop_id'=>(int)$s['id'],'platform'=>$s['platform']],simShops((int)$sm[1]))]);
  $newResult=release053Route($u,$method,$path,$b);if($newResult!==null)return $newResult;
  $linkResult=simLinkRoute($u,$method,$path,$b);if($linkResult!==null)return $linkResult;
  $bindResult=bindingMutation($u,$method,$path,$b);if($bindResult!==null)return $bindResult;
  $result=legacyRoute($method,$path,$b);
  if($method==='GET'&&preg_match('#^/api/sims/(\d+)$#D',$path))$result=scopeSimRows($u,[$result])[0];
  if($method==='GET'&&preg_match('#^/api/admin/devices/(\d+)$#D',$path,$m)&&!ownsWholeDevice($u,(int)$m[1])){$result['name']='共享采集设备 #'.$m[1];$result['location']='';$result['notes']='';}
  return $result;
 }catch(ApiError $e){
  if(in_array($e->status,[403,404,409,422],true))try{audit($u,'operation_denied',$path,['status'=>'denied','object_type'=>'request','object_name'=>requestActionLabel($method,$path),'summary'=>requestActionLabel($method,$path).'：'.$e->getMessage(),'changes'=>[]]);}catch(Throwable $ignore){}
  throw $e;
 }
}
