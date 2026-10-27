<?php
declare(strict_types=1);
require_once __DIR__.'/secrets.php';
function manageAdmin(array $u): void { /* HTTP action rights and scopes are enforced by routePermission. */ }
function assetTable(string $kind): string {
    $tables=['device'=>'devices','sim'=>'sims','user'=>'users','record'=>'records','message'=>'messages','shop'=>'shops'];
    if(!isset($tables[$kind]))fail(404,'对象类型不存在');return $tables[$kind];
}
function asset(string $kind,int $id,bool $deleted=false): array {
    $table=assetTable($kind);$rows=query("SELECT * FROM `$table` WHERE id=?".($deleted?'':' AND deleted_at IS NULL'),[$id]);
    if(!$rows)fail(404,'对象不存在或已删除');auditRemember($kind,$id,$rows[0]);return $rows[0];
}
function nonblank(array $b,string $key,int $max): string {
    $s=trim(stringField($b,$key,$max));if($s==='')fail(422,fieldLabel($key).'不能为空');return $s;
}
function guardAdminChange(array $target,string $role,int $active): void {
    if($target['role']==='admin'&&(!$active||$role!=='admin')){
        $admins=query("SELECT id FROM users WHERE role='admin' AND active=1 AND deleted_at IS NULL FOR UPDATE");
        if((int)$target['active']===1&&count($admins)<=1)fail(422,'必须保留至少一个可用的管理员账户');
    }
}
function refreshBalance(int $sim): void {
    $latest=query("SELECT amount,at FROM records WHERE sim_id=? AND kind='balances' AND deleted_at IS NULL AND shop_id <=> (SELECT shop_id FROM sims WHERE id=?) AND currency_snapshot=(SELECT currency FROM sims WHERE id=?) ORDER BY at DESC,id DESC LIMIT 1",[$sim,$sim,$sim]);
    query('UPDATE sims SET balance=?,checked_at=? WHERE id=?',[$latest[0]['amount']??null,$latest[0]['at']??null,$sim]);
}
function changeKey(array $u,int $id,array $b): array {
    $existing=asset('device',$id);$custom=stringField($b,'token',1000000,false);
    $token=$custom!==''?$custom:randomDeviceKey();$sealed=sealSecret($token);
    query('UPDATE devices SET token=?,secret_cipher=?,revoked=0 WHERE id=?',[hash('sha256',$token),$sealed,$id]);
    audit($u,'rotate_device_key',$id);return ['id'=>$id,'token'=>$token,'notice'=>'旧密钥立即失效，请在手机停止采集后重新扫码或粘贴配置'];
}
function simIdentity(array $b): array {
 $phone=preg_replace('/[\s()\-]/u','',stringField($b,'phone',32));
 if(preg_match('/^(?:0|63|\+63)?(9[0-9]{9})$/D',$phone,$m))$phone='+63'.$m[1];
 if(preg_match('/^(?:86|\+86)?(1[3-9][0-9]{9})$/D',$phone,$m))$phone='+86'.$m[1];
 $country=$b['country']??(str_starts_with($phone,'+86')?'CN':'PH');
 $carrier=stringField($b,'carrier',16);
 $ok=$country==='CN'?preg_match('/^\+861[3-9]\d{9}$/D',$phone)&&in_array($carrier,['中国移动','中国联通','中国电信','中国广电'],true):($country==='PH'&&preg_match('/^\+639\d{9}$/D',$phone)&&in_array($carrier,['Globe','Smart'],true));
 if(!$ok)fail(422,'请检查国家、手机号和运营商是否匹配');
 return [$phone,$carrier,$country,$country==='CN'?'CNY':'PHP'];
}
function listPage(): array {
 $page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
 if($page===false)fail(422,'页码无效');return [50,($page-1)*50];
}
function managementRoute(string $method,string $path,array $b,array $u): ?array {
    if($method==='GET'&&$path==='/api/admin/trash'){
        manageAdmin($u);[$limit,$offset]=listPage();$parts=[];
        foreach(['device','sim','user','record','message'] as $kind){$table=assetTable($kind);
         $expr=match($kind){'device'=>'name','sim'=>"CONCAT(phone,' · ',shop)",'user'=>'username','record'=>"CONCAT(kind,' ',amount)",'message'=>"CONCAT(sender,' · 短信')"};
         $parts[]="SELECT id,'$kind' AS kind,$expr AS label,deleted_at FROM `$table` WHERE deleted_at IS NOT NULL";
        }
        $kind=$_GET['kind']??'all';if(!is_string($kind)||!in_array($kind,['all','device','sim','user','record','message'],true))fail(422,'回收站类型无效');
        $sql='('.implode(' UNION ALL ',$parts).') t';$where=$kind==='all'?'':" WHERE kind=".db()->quote($kind);
        header('X-Total-Count: '.query('SELECT COUNT(*) n FROM '.$sql.$where)[0]['n']);
        return query('SELECT * FROM '.$sql.$where.' ORDER BY deleted_at DESC,kind,id DESC LIMIT '.$limit.' OFFSET '.$offset);
    }
    if($method==='POST'&&preg_match('#^/api/admin/restore/(device|sim|user|record|message|shop)/(\d+)$#D',$path,$m)){
        manageAdmin($u);$kind=$m[1];$id=(int)$m[2];$r=asset($kind,$id,true);if($r['deleted_at']===null)fail(409,'该对象已经恢复');
        if($u['role']!=='admin'&&$kind==='record'&&!query('SELECT id FROM sims WHERE id=? AND deleted_at IS NULL',[$r['sim_id']]))fail(422,'请先恢复该记录对应的号码');
        $table=assetTable($kind);db()->beginTransaction();try{
            if($kind==='user'){$lockedUser=query('SELECT * FROM users WHERE id=? FOR UPDATE',[$id]);if(!$lockedUser||!canManageAccount($u,$lockedUser[0]))fail(404,'账户不在可管理范围');}
            if($kind==='sim'){query('SELECT id FROM sims WHERE id=? FOR UPDATE',[$id]);requireWholeSim($u,$id,true);}
            if($kind==='device'){query('SELECT id FROM devices WHERE id=? FOR UPDATE',[$id]);requireDeviceScope($u,$id,true,true);}
            if($kind==='record'){query('SELECT id FROM sims WHERE id=? FOR UPDATE',[$r['sim_id']]);if(!ownsWholeSim($u,(int)$r['sim_id']))fail(403,'共享号码资金修改需要完整关联范围');}
            if(!query("SELECT id FROM `$table` WHERE id=? AND deleted_at IS NOT NULL FOR UPDATE",[$id]))fail(409,'数据已经恢复或永久删除，请刷新回收站');
            query("UPDATE `$table` SET deleted_at=NULL".($kind==='device'?',revoked=1':($kind==='user'?',active=0':'')).' WHERE id=?',[$id]);
            if($kind==='record'&&$r['kind']==='balances')refreshBalance((int)$r['sim_id']);
            db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        audit($u,'restore_'.$kind,$id);return ['ok'=>true,'notice'=>match($kind){'device'=>'已恢复。设备仍停用，请更换密钥后重新接入','user'=>'已恢复。账户仍禁用，可在账户管理中启用','record'=>$r['kind']==='balances'?'余额查询记录已恢复，当前余额已重新计算':'充值记录已恢复，实际余额不会自动增加','message'=>'短信已恢复，保留期仍按原上传时间计算',default=>'已恢复。原绑定不会自动恢复，请按需重新绑定'}];
    }
    if(preg_match('#^/api/admin/devices/(\d+)(?:/(secret|key))?$#D',$path,$m)){
        manageAdmin($u);$id=(int)$m[1];$op=$m[2]??'';$d=asset('device',$id);
        if($op==='secret'&&$method==='POST'){
            if(!$d['secret_cipher'])return ['token'=>null,'legacy'=>true,'notice'=>'旧版只保留校验摘要，无法还原。更换一次密钥后即可再次查看'];
            $token=openSecret($d['secret_cipher']);audit($u,'reveal_device_key',$id);return ['id'=>$id,'token'=>$token,'legacy'=>false];
        }
        if($op==='key'&&$method==='POST')return changeKey($u,$id,$b);
        if($op===''&&$method==='PUT'){
            query('UPDATE devices SET name=?,location=?,notes=? WHERE id=?',[nonblank($b,'name',100),stringField($b,'location',50,false),stringField($b,'notes',500,false),$id]);
            audit($u,'edit_device',$id);return ['ok'=>true];
        }
        if($op===''&&$method==='DELETE'){
            db()->beginTransaction();try{query('UPDATE devices SET deleted_at=?,revoked=1 WHERE id=?',[microtime(true),$id]);query('DELETE FROM bindings WHERE device_id=?',[$id]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
            audit($u,'delete_device',$id);return ['ok'=>true];
        }
        if($op===''&&$method==='GET')return array_intersect_key($d,array_flip(['id','name','location','notes','revoked','heartbeat']));
    }
    if(preg_match('#^/api/sims/(\d+)$#D',$path,$m)){
        $id=(int)$m[1];$s=asset('sim',$id);
        if($method==='GET')return array_diff_key($s,['deleted_at'=>true]);
        if($method==='PUT'){
            [$phone,$carrier,$country,$currency]=simIdentity($b);$shop=resolveShop($u,$b,$s);
            $boundDevices=array_map('intval',array_column(query('SELECT device_id FROM bindings WHERE sim_id=?',[$id]),'device_id'));
            db()->beginTransaction();try{
             foreach($boundDevices as $deviceId)query('SELECT id FROM devices WHERE id=? FOR UPDATE',[$deviceId]);
             $locked=query('SELECT * FROM sims WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]);if(!$locked||!ownsWholeSim($u,$id))fail(404,'号码不在授权范围');
             $GLOBALS['_audit_before']['sim:'.$id]=array_intersect_key($locked[0],array_flip(auditFields('sim')));
             $currentDevices=array_map('intval',array_column(query('SELECT device_id FROM bindings WHERE sim_id=? FOR UPDATE',[$id]),'device_id'));if($currentDevices!==$boundDevices)fail(409,'卡槽绑定已变化，请刷新后重试');
             $liveShop=query('SELECT * FROM shops WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$shop['id']]);if(!$liveShop)fail(404,'店铺不可用，请刷新后重试');$shop=$liveShop[0];if(count(simShopIds($id))>1&&(int)$shop['id']!==(int)$locked[0]['shop_id'])fail(422,'共享号码请使用店铺关联接口调整店铺');
             if($u['role']!=='admin'&&$locked[0]['currency']!==$currency&&query('SELECT id FROM records WHERE sim_id=? LIMIT 1',[$id]))fail(422,'已有流水的号码不能更换币种，请新增号码保留原账目');
             query('UPDATE sims SET phone=?,carrier=?,country=?,currency=?,location=?,shop=?,shop_id=? WHERE id=?',[$phone,$carrier,$country,$currency,stringField($b,'location',50),$shop['name'],$shop['id'],$id]);if((int)$locked[0]['shop_id']!==(int)$shop['id']){query('DELETE FROM sim_shops WHERE sim_id=?',[$id]);query('INSERT INTO sim_shops(sim_id,shop_id) VALUES(?,?)',[$id,$shop['id']]);refreshBalance($id);}refreshBalance($id);syncSimDeviceScopes($id);db()->commit();
            }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
            audit($u,'edit_sim',$id);return ['ok'=>true];
        }
        if($method==='DELETE'){
            manageAdmin($u);db()->beginTransaction();try{$locked=query('SELECT * FROM sims WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]);if(!$locked||!ownsWholeSim($u,$id))fail(404,'号码不在授权范围');query('UPDATE sims SET deleted_at=? WHERE id=?',[microtime(true),$id]);query('DELETE FROM bindings WHERE sim_id=?',[$id]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
            audit($u,'delete_sim',$id);return ['ok'=>true];
        }
    }
    if(preg_match('#^/api/admin/users/(\d+)(?:/(disable))?$#D',$path,$m)){
        manageAdmin($u);$id=(int)$m[1];$target=asset('user',$id);
        if($method==='GET'&&!isset($m[2]))return array_intersect_key($target,array_flip(['id','username','role','active']));
        if(($method==='POST'&&($m[2]??'')==='disable')||$method==='DELETE'||$method==='PUT'){
            if($u['role']!=='admin'&&$id===(int)$u['id']&&$method!=='PUT')fail(422,'不能删除或禁用当前登录账户');
            $role=$method==='PUT'&&isset($b['role'])?stringField($b,'role',16):$target['role'];
            if(isset($b['active'])&&!is_bool($b['active'])&&!in_array($b['active'],[0,1],true))fail(422,'账户状态必须为启用或禁用');
            $active=$method==='PUT'?(isset($b['active'])?((bool)$b['active']?1:0):(int)$target['active']):0;
            if(!in_array($role,['admin','operator'],true))fail(422,'权限类型无效');
            $name=$method==='PUT'?nonblank($b,'username',100):$target['username'];
            $pw=$method==='PUT'?stringField($b,'password',256,false):'';
            if($pw!==''&&(strlen($pw)<6||strlen($pw)>72))fail(422,'密码为6至72字节');
            db()->beginTransaction();try{
                $lockedTarget=query('SELECT * FROM users WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]);if(!$lockedTarget||!canManageAccount($u,$lockedTarget[0]))fail(404,'账户不在可管理范围');$target=$lockedTarget[0];if($target['role']==='admin')requirePermission($u,'users.permissions');
                if($u['role']!=='admin')guardAdminChange($target,$role,$active);
                if($u['role']!=='admin'&&$id===(int)$u['id']&&!$active)fail(422,'不能禁用当前登录账户');
                query('UPDATE users SET username=?,role=?,active=?,deleted_at=? WHERE id=?',[$name,$role,$active,$method==='DELETE'?microtime(true):null,$id]);
                if($pw!==''){query('UPDATE users SET password=? WHERE id=?',[password_hash($pw,PASSWORD_DEFAULT),$id]);query('DELETE FROM sessions WHERE user_id=?',[$id]);}
                if($method==='DELETE'||!$active||$role!==$target['role']||$name!==$target['username'])query('DELETE FROM sessions WHERE user_id=?',[$id]);
                audit($u,$method==='DELETE'?'delete_user':($method==='PUT'?'edit_user':'disable_user'),$id,$pw!==''?['changes'=>[['field'=>'password_changed','before'=>false,'after'=>true]]]:[]);
                db()->commit();
            }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
            return ['ok'=>true];
        }
    }
    if(preg_match('#^/api/records/(\d+)$#D',$path,$m)){
        $id=(int)$m[1];$r=asset('record',$id);
        if($method==='GET')return $r;
        if($method==='PUT'||$method==='DELETE'){
            if($method==='PUT'){$a=amount($b);$note=stringField($b,'note',500,false);}
            db()->beginTransaction();try{
                query('SELECT id FROM sims WHERE id=? FOR UPDATE',[$r['sim_id']]);if(!ownsWholeSim($u,(int)$r['sim_id']))fail(403,'共享号码资金修改需要完整关联范围');
                if($method==='DELETE')query('UPDATE records SET deleted_at=? WHERE id=?',[microtime(true),$id]);
                else query('UPDATE records SET amount=?,note=? WHERE id=?',[$a,$note,$id]);
                if($r['kind']==='balances')refreshBalance((int)$r['sim_id']);
                db()->commit();
            }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
            audit($u,$method==='DELETE'?'delete_record':'edit_record',$id);return ['ok'=>true];
        }
    }
    if($method==='DELETE'&&preg_match('#^/api/messages/(\d+)$#D',$path,$m)){
        manageAdmin($u);asset('message',(int)$m[1]);query('UPDATE messages SET deleted_at=? WHERE id=?',[microtime(true),$m[1]]);audit($u,'delete_message',$m[1]);return ['ok'=>true];
    }
    return null;
}
