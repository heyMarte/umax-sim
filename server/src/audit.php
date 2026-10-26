<?php
declare(strict_types=1);

function auditFields(string $kind): array {
    return match($kind){
        'sim'=>['phone','carrier','country','currency','location','shop_id','shop','threshold','package_expires','preservation_status','next_check','balance','checked_at','deleted_at'],
        'device'=>['name','location','revoked','deleted_at'],
        'user'=>['username','role','active','all_shops','deleted_at'],
        'record'=>['shop_id','phone_snapshot','shop_snapshot','sim_id','kind','amount','at','deleted_at'],
        'message'=>['shop_id','phone_snapshot','shop_snapshot','sim_id','device_id','deleted_at'],
        'shop'=>['name','platform','platforms','deleted_at'],default=>[]};
}
function auditRemember(string $kind,int|string $id,array $row): void {
    $key=$kind.':'.$id;
    if(!array_key_exists($key,$GLOBALS['_audit_before']??[]))$GLOBALS['_audit_before'][$key]=array_intersect_key($row,array_flip(auditFields($kind)));
}
function auditEntity(string $kind,int|string $id): array {
    $tables=['sim'=>'sims','device'=>'devices','user'=>'users','record'=>'records','message'=>'messages','shop'=>'shops'];
    if(!isset($tables[$kind])||!ctype_digit((string)$id))return [];
    $fields=auditFields($kind);if(!$fields)return [];
    return query('SELECT '.implode(',',$fields).' FROM '.$tables[$kind].' WHERE id=?',[(int)$id])[0]??[];
}
function auditPrepare(string $kind,int|string $id): void {auditRemember($kind,$id,auditEntity($kind,$id));}
function auditActionLabel(string $action): string {
    $labels=['auto_bind_sim'=>'自动关联SIM号码','auto_unbind_sim'=>'自动解除失效SIM关联','wechat_config'=>'修改微信推送配置','wechat_bind'=>'绑定本人微信','wechat_unbind'=>'解除本人微信绑定','wechat_test'=>'发送微信测试消息','wechat_binding_start'=>'生成本人微信绑定码','wechat_binding_confirm'=>'确认本人微信绑定','dismiss_alert'=>'删除本人异常提醒','undismiss_alert'=>'恢复本人异常提醒','transfer_sim_shops'=>'转移号码店铺关联','stage_app_update'=>'独立上传 APK','stage_system_update'=>'上传并校验更新包','apply_system_update'=>'启动后台升级','publish_app_update'=>'发布 App 更新','system_update_success'=>'后台升级完成','system_update_failed'=>'后台升级失败','assign_permissions'=>'分配店铺与操作权限','request_phone_refresh'=>'请求手机上报','operation_denied'=>'操作被拒绝','login'=>'登录','logout'=>'退出登录','create_user'=>'创建账户','edit_user'=>'修改账户','delete_user'=>'删除账户','disable_user'=>'禁用账户','user_permissions'=>'修改账户授权','set_user_permissions'=>'修改账户授权','update_permissions'=>'修改账户授权','grant_permissions'=>'修改账户授权','create_shop'=>'创建店铺','edit_shop'=>'修改店铺','delete_shop'=>'删除店铺','create_sim'=>'新增号码','edit_sim'=>'修改号码','delete_sim'=>'删除号码','settings'=>'修改号码设置','balances'=>'登记余额','recharges'=>'登记充值','bind_sim'=>'绑定号码','unbind_sim'=>'解绑号码','create_device'=>'新增设备','edit_device'=>'修改设备','delete_device'=>'删除设备','revoke_device'=>'停用设备','rotate_device_key'=>'更换设备密钥','reveal_device_key'=>'查看设备密钥','request_snapshots'=>'请求设备刷新','refresh_devices'=>'请求设备刷新','delete_message'=>'删除短信','copy_code'=>'复制验证码','view_messages'=>'读取短信列表','view_message'=>'查看短信全文','view_private_message'=>'管理员查看无验证码短信原文','edit_record'=>'修改流水','delete_record'=>'删除流水','mute_alert'=>'暂缓异常提醒'];
    if(isset($labels[$action]))return $labels[$action];
    if(str_starts_with($action,'purge_'))return '永久删除'.(['sim'=>'号码','device'=>'设备','user'=>'账户','message'=>'短信','record'=>'流水','shop'=>'店铺'][substr($action,6)]??'对象');
    if(str_starts_with($action,'restore_'))return '恢复'.(['sim'=>'号码','device'=>'设备','user'=>'账户','message'=>'短信','record'=>'流水','shop'=>'店铺'][substr($action,8)]??'对象');
    return $action;
}
function auditKind(string $action): string {
    if(in_array($action,['settings','balances','recharges','bind_sim','unbind_sim'],true))return 'sim';
    if(in_array($action,['copy_code','view_messages','view_message'],true))return 'message';
    if($action==='login'||$action==='logout'||str_contains($action,'permission'))return 'user';
    foreach(['device','record','message','shop','user','sim'] as $kind)if(str_contains($action,$kind))return $kind;
    return 'operation';
}
function auditSafeChanges(array $changes): array {
    $allowed=array_merge(...array_map('auditFields',['sim','device','user','record','message','shop']));
    $allowed=array_merge($allowed,['permissions','shop_ids','password_changed','binding','subscription_id']);
    $result=[];
    foreach($changes as $c){
        if(!is_array($c)||!in_array($c['field']??null,$allowed,true))continue;
        $entry=['field'=>$c['field']];
        foreach(['before','after'] as $side){$v=$c[$side]??null;$entry[$side]=is_array($v)?array_slice(array_values(array_filter($v,fn($x)=>is_scalar($x)||$x===null)),0,500):(is_scalar($v)||$v===null?$v:null);}
        $result[]=$entry;
    }
    return $result;
}
function audit(array $u,string $action,int|string $id,array $context=[]): void {
    $kind=$context['object_type']??auditKind($action);
    $before=$GLOBALS['_audit_before'][$kind.':'.$id]??[];$after=auditEntity($kind,$id);$entity=$after?:$before;
    $changes=[];
    if($before)foreach(auditFields($kind) as $field)if(array_key_exists($field,$after)&&($before[$field]??null)!=$after[$field])$changes[]=['field'=>$field,'before'=>$before[$field]??null,'after'=>$after[$field]];
    $changes=auditSafeChanges(array_merge($changes,$context['changes']??[]));
    $name=$context['object_name']??($context['name']??($entity['phone_snapshot']??$entity['phone']??$entity['username']??$entity['name']??($kind==='record'?($entity['kind']??'流水').' '.($entity['amount']??''):$kind.' #'.$id)));
    if(!isset($context['object_name'])&&in_array($kind,['record','message'],true))$name=($kind==='record'?(($entity['kind']??'')==='balances'?'余额流水':'充值流水'):'短信').' #'.$id.' / '.($entity['phone_snapshot']??'');
    $shopIds=[];
    foreach($context['shop_ids']??[] as $sid)if((int)$sid>0)$shopIds[]=(int)$sid;
    $sid=$context['shop_id']??($entity['shop_id']??null);
    if($kind==='shop'&&ctype_digit((string)$id))$sid=(int)$id;
    if($sid)$shopIds[]=(int)$sid;
    foreach([$before,$after] as $state)if(!empty($state['shop_id']))$shopIds[]=(int)$state['shop_id'];
    if($kind==='record'&&empty($entity['shop_id'])&&!empty($entity['sim_id'])){foreach(query('SELECT shop_id FROM sims WHERE id=?',[$entity['sim_id']]) as $s)if($s['shop_id'])$shopIds[]=(int)$s['shop_id'];}
    if($kind==='sim'&&ctype_digit((string)$id))$shopIds=array_merge($shopIds,simShopIds((int)$id));
    if($kind==='device'&&ctype_digit((string)$id)){foreach(query('SELECT shop_id FROM device_scopes WHERE device_id=?',[(int)$id]) as $s)$shopIds[]=(int)$s['shop_id'];}
    $shopIds=array_values(array_unique($shopIds));
    $shopName=$context['shop_name']??($entity['shop_snapshot']??$entity['shop']??null);
    if(!$shopName&&$sid)$shopName=query('SELECT name FROM shops WHERE id=?',[(int)$sid])[0]['name']??null;
    $summary=$context['summary']??(auditActionLabel($action).'：'.$name);
    if($action==='view_messages'){
      $last=query('SELECT id,summary,shop_name FROM audit WHERE actor_id=? AND action=? AND object_id=? AND at>=? ORDER BY id DESC LIMIT 1',[$u['id'],$action,(string)$id,microtime(true)-60]);
      if($last&&$last[0]['summary']===$summary&&$last[0]['shop_name']===($shopName??'')){$prior=array_map('intval',array_column(query('SELECT shop_id FROM audit_scope WHERE audit_id=?',[$last[0]['id']]),'shop_id'));$ordered=$shopIds;sort($prior);sort($ordered);if($prior===$ordered)return;}
    }
    $ownTransaction=!db()->inTransaction();if($ownTransaction)db()->beginTransaction();
    try {
    $log=insert('INSERT INTO audit(actor,action,object_id,at,actor_id,object_type,object_name,shop_id,shop_name,summary,changes_json,status,ip,is_detailed) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,1)',[
        mb_substr((string)($u['username']??'未知账户'),0,100),mb_substr($action,0,100),(string)$id,microtime(true),$u['id']??null,mb_substr($kind,0,40),mb_substr((string)$name,0,300),$sid?:null,$shopName===null?'':mb_substr((string)$shopName,0,200),mb_substr((string)$summary,0,1000),json_encode($changes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),mb_substr((string)($context['status']??'success'),0,20),substr((string)($_SERVER['REMOTE_ADDR']??''),0,64)]);
    foreach($shopIds as $s)query('INSERT INTO audit_scope(audit_id,shop_id) VALUES(?,?)',[$log,$s]);
    if($ownTransaction)db()->commit();
    }catch(Throwable $e){if($ownTransaction&&db()->inTransaction())db()->rollBack();throw $e;}
}
function scopedAuditRows(array $u): array {
    requirePermission($u,'audit.view');
    $where=['1=1'];$params=[];
    if(($u['role']??'')!=='admin'&&!(can($u,'audit.all')&&globalScope($u))){
        // Actor ownership never overrides the permanent store snapshot on a scoped log.
        $where[]='a.actor_id IS NOT NULL AND a.is_detailed=1';
        if(!can($u,'audit.all')){$where[]='a.actor_id=?';$params[]=(int)$u['id'];}
        $where[]='NOT EXISTS (SELECT 1 FROM audit_scope ax WHERE ax.audit_id=a.id AND NOT ('.scopeSql($u,'ax.shop_id').'))';
        $where[]='(EXISTS (SELECT 1 FROM audit_scope ay WHERE ay.audit_id=a.id) OR (a.shop_id IS NOT NULL AND '.scopeSql($u,'a.shop_id').') OR (a.shop_id IS NULL AND a.actor_id=?))';$params[]=(int)$u['id'];
        $where[]='(a.shop_id IS NULL OR '.scopeSql($u,'a.shop_id').')';
    }
    foreach(['actor'=>'a.actor','action'=>'a.action'] as $key=>$column){if(isset($_GET[$key])&&$_GET[$key]!==''){if(!is_string($_GET[$key])||mb_strlen($_GET[$key])>100)fail(422,'日志筛选格式错误');$where[]=$column.'=?';$params[]=$_GET[$key];}}
    if(isset($_GET['shop_id'])&&$_GET['shop_id']!==''){$shop=filter_var($_GET['shop_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($shop===false)fail(422,'店铺筛选无效');$where[]='(a.shop_id=? OR EXISTS (SELECT 1 FROM audit_scope az WHERE az.audit_id=a.id AND az.shop_id=?))';$params[]=$shop;$params[]=$shop;}
    if(isset($_GET['q'])&&$_GET['q']!==''){if(!is_string($_GET['q'])||mb_strlen($_GET['q'])>200)fail(422,'搜索格式错误');$where[]='(a.actor LIKE ? OR a.object_name LIKE ? OR a.shop_name LIKE ? OR a.summary LIKE ? OR a.action LIKE ?)';for($i=0;$i<5;$i++)$params[]='%'.$_GET['q'].'%';}
    [$limit,$offset]=listPage();$sql=implode(' AND ',$where);
    header('X-Total-Count: '.(int)query('SELECT COUNT(*) n FROM audit a WHERE '.$sql,$params)[0]['n']);
    $rows=query('SELECT a.* FROM audit a WHERE '.$sql.' ORDER BY a.id DESC LIMIT '.(int)$limit.' OFFSET '.(int)$offset,$params);
    foreach($rows as &$row){$row['legacy']=!(bool)$row['is_detailed'];$row['action_label']=auditActionLabel($row['action']);$row['changes']=auditSafeChanges(json_decode($row['changes_json']??'[]',true)?:[]);unset($row['changes_json']);if($row['legacy'])$row['summary']='历史日志：详情未记录';}
    return $rows;
}
