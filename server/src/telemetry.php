<?php
declare(strict_types=1);
function metricNumber(array $b,string $key,float $min,float $max,bool $integer=false): int|float|null {
    $v=$b[$key]??null;if($v===null)return null;
    if(($integer&&!is_int($v))||(!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v<$min||$v>$max)fail(422,"状态数值无效：$key");return $v;
}
function metricBool(array $b,string $key): ?bool {
    $v=$b[$key]??null;if($v!==null&&!is_bool($v))fail(422,"状态开关无效：$key");return $v;
}
function metricString(array $b,string $key,int $max): ?string {
    $v=$b[$key]??null;if($v===null)return null;if(!is_string($v)||mb_strlen($v)>$max)fail(422,"状态文字无效：$key");return $v;
}
function normalizeTelemetry(array $b): array {
    $out=[];
    foreach(['battery_percent'=>[0,100,true],'battery_temp_c'=>[-50,120,false],'android_sdk'=>[1,100,true],'uptime_seconds'=>[0,3153600000,false],'quarantined_count'=>[0,10000000,true],'pending_count'=>[0,10000000,true],'oldest_pending_age_seconds'=>[0,3153600000,false],'last_success_at'=>[0,253402300799,false],'last_received_at'=>[0,253402300799,false],'last_sms_upload_at'=>[0,253402300799,false],'sync_duration_ms'=>[0,3600000,true],'consecutive_failures'=>[0,10000000,true]] as $key=>$spec)$out[$key]=metricNumber($b,$key,...$spec);
    foreach(['collector_running','charging','internet_validated','battery_optimization_ignored','power_save'] as $key)$out[$key]=metricBool($b,$key);
    foreach(['manufacturer'=>100,'model'=>150,'android_version'=>50,'app_version'=>30,'last_error'=>300] as $key=>$max)$out[$key]=metricString($b,$key,$max);
    $transport=metricString($b,'network_transport',20)??'unknown';if(!in_array($transport,['wifi','cellular','ethernet','vpn','none','other','unknown'],true))fail(422,'网络类型无效');$out['network_transport']=$transport;
    $permissions=$b['permissions']??[];if(!is_array($permissions))fail(422,'权限状态无效');$out['permissions']=[];
    foreach(['sms','phone','notifications','location'] as $key)$out['permissions'][$key]=metricBool($permissions,$key);
    return $out;
}
function normalizeSubscriptions(array $rows): array {
    if(!array_is_list($rows)||count($rows)>10)fail(422,'subscriptions 必须为最多10项的数组');
    $result=[];$seen=[];
    foreach($rows as $r){
        if(!is_array($r))fail(422,'卡槽状态无效');
        $sub=metricNumber($r,'subscription_id',0,2147483647,true);
        if($sub!==null){if(isset($seen[$sub]))fail(422,'订阅编号重复');$seen[$sub]=true;}
        $item=['subscription_id'=>$sub,'slot'=>metricNumber($r,'slot',1,10,true),'carrier'=>metricString($r,'carrier',100),'sim_state'=>metricNumber($r,'sim_state',0,20,true)];
        if(isset($r['state']))$item['state']=metricString($r,'state',50);
        $item['sim_state_reason']=metricString($r,'sim_state_reason',50);
        $item['subscription_active']=metricBool($r,'subscription_active')??false;
        $service=metricString($r,'service_state',30)??'unknown';if(!in_array($service,['in_service','out_of_service','emergency_only','power_off','unknown'],true))fail(422,'SIM服务状态无效');$item['service_state']=$service;
        $item['service_state_reason']=metricString($r,'service_state_reason',50);
        $item['signal_level']=metricNumber($r,'signal_level',0,4,true);$item['signal_dbm']=metricNumber($r,'signal_dbm',-160,-20,true);$item['signal_age_seconds']=metricNumber($r,'signal_age_seconds',0,3153600000,false);$item['roaming']=metricBool($r,'roaming');
        foreach(['network_operator'=>100,'network_country'=>5,'sim_operator'=>100,'radio_type'=>30] as $k=>$max)$item[$k]=metricString($r,$k,$max);
        $item['sim_token']=simToken($r['sim_token']??null);
        $item['phone_hint']=normalizePhoneHint($r['phone_hint']??null);
        $source=$r['phone_hint_source']??null;$item['phone_hint_source']=$item['phone_hint']!==null&&is_string($source)&&in_array($source,['android_sim','subscription','subscription_info','telephony','telephony_manager','line1','android'],true)?$source:null;
        $result[]=$item;
    }
    return $result;
}
function acceptHeartbeat(array $device,array $body): array {
    // Concurrent cards and grant changes may deadlock InnoDB; retry the complete rolled-back decision.
    for($attempt=0;;$attempt++)try{return acceptHeartbeatAttempt($device,$body);}catch(PDOException $e){
        if($attempt>=4||!in_array((int)($e->errorInfo[1]??0),[1020,1205,1213],true))throw $e;
        usleep(random_int(10000,30000));
    }
}
function acceptHeartbeatAttempt(array $device,array $body): array {
    $rows=$body['subscriptions']??null;if(!is_array($rows))fail(422,'subscriptions 必须为数组');$subs=normalizeSubscriptions($rows);$protocol=simObservationVersion($body);
    $hasTelemetry=array_key_exists('telemetry',$body);$telemetry=null;
    if($hasTelemetry){if(!is_array($body['telemetry']))fail(422,'telemetry 必须为对象');$telemetry=normalizeTelemetry($body['telemetry']);}
    $status=$body['subscriptions_status']??'legacy';if(!is_string($status)||!in_array($status,['ok','permission_denied','unavailable','legacy'],true))fail(422,'订阅读取状态无效');
    // Only the explicit pre-26 capability can replace a missing physical-state reading.
    $sdk=$telemetry['android_sdk']??null;
    foreach($subs as &$s)$s['subscription_active']=$protocol===1&&is_int($sdk)&&$sdk>=23&&$sdk<=25&&$s['sim_state']===null&&$s['sim_state_reason']==='unsupported_android_version'&&$s['subscription_active'];unset($s);
    $now=microtime(true);$topology=null;if($telemetry!==null)$telemetry['subscriptions_status']=$status;
    if($status==='ok'){
        $top=[];foreach($subs as $s)if($s['subscription_id']!==null&&$s['slot']!==null)$top[]=['slot'=>$s['slot'],'sub'=>$s['subscription_id']];
        usort($top,fn($a,$b)=>[$a['slot'],$a['sub']]<=>[$b['slot'],$b['sub']]);$topology=json_encode($top,JSON_THROW_ON_ERROR);
    }
    db()->beginTransaction();try{
        $locked=query('SELECT * FROM devices WHERE id=? AND deleted_at IS NULL AND revoked=0 FOR UPDATE',[$device['id']]);if(!$locked)fail(401,'设备已停用');$device=$locked[0];
        $old=query('SELECT * FROM device_telemetry WHERE device_id=? FOR UPDATE',[$device['id']]);$prior=$old[0]??null;
        $priorPayload=json_decode($prior['payload']??'{}',true)?:[];$retired=$priorPayload['retired_sim_tokens']??[];
        $stale=($priorPayload['sim_observation_version']??null)===1&&$protocol!==1;
        if($protocol===1)foreach($subs as $r)if(($r['sim_token']??null)!==null&&in_array($r['sim_token'],$retired,true))$stale=true;
        if($stale){query('UPDATE devices SET heartbeat=? WHERE id=?',[$now,$device['id']]);db()->commit();return ['ok'=>true,'bindings'=>[],'snapshot_accepted'=>false,'server_time'=>$now];}
        $priorSubs=json_decode($device['subscriptions']??'[]',true)?:[];
        if($protocol===1&&$status==='ok'){
            foreach($priorSubs as $p){$token=$p['sim_token']??null;if($token===null)continue;
                $current=array_values(array_filter($subs,fn($r)=>($r['subscription_id']??null)===($p['subscription_id']??null)));
                if(!$current||($current[0]['sim_token']??null)!==$token||(simIdentityKnown($p)&&!simIdentityKnown($current[0])))$retired[]=$token;
            }
            $retired=array_values(array_unique($retired));
        }
        if(in_array($status,['permission_denied','unavailable'],true))$subs=$priorSubs;
        $subs=reconcileSimObservation($device,$subs,$status,$protocol,$priorSubs);
        if($status==='ok'&&$topology!==null&&$prior&&$prior['topology']!==null&&$prior['topology']!==$topology){
            query('INSERT INTO device_events(device_id,kind,summary,at) VALUES(?,?,?,?)',[$device['id'],'subscription_change','卡槽订阅信息发生变化，请核对手机号绑定；这不等于确认更换了实体卡',$now]);
        }
        query('UPDATE devices SET heartbeat=?,subscriptions=? WHERE id=?',[$now,json_encode($subs,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$device['id']]);
        if($hasTelemetry||$topology!==null||$protocol===1||$status!=='legacy'){
            $storedPayload=$hasTelemetry?$telemetry:json_decode($prior['payload']??'{}',true);if(!is_array($storedPayload))$storedPayload=[];$storedPayload['subscriptions_status']=$status;$storedPayload['sim_observation_version']=$protocol;$storedPayload['retired_sim_tokens']=$retired;
            query('INSERT INTO device_telemetry(device_id,observed_at,payload,topology,topology_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE observed_at=VALUES(observed_at),payload=VALUES(payload),topology=VALUES(topology),topology_at=VALUES(topology_at)',[
              $device['id'],$hasTelemetry?$now:($prior['observed_at']??0),json_encode($storedPayload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$topology??($prior['topology']??null),$topology!==null?$now:($prior['topology_at']??null)]);
        }
        acknowledgeSnapshot((int)$device['id'],$body);
        $bindings=query('SELECT b.sim_id,b.subscription_id,b.version,b.sim_token,s.phone,s.shop FROM bindings b JOIN sims s ON b.sim_id=s.id WHERE b.device_id=? AND s.deleted_at IS NULL',[$device['id']]);
        $bindings=array_values(array_filter($bindings,fn($r)=>simBindingActionable($r,$subs,$status,$protocol)));
        foreach($bindings as &$r){$r['subscription_id']=(int)$r['subscription_id'];$r['shop']=linkedShopDisplay((int)$r['sim_id']);unset($r['sim_id']);}unset($r);
        db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    observeAlertRecovery((int)$device['id']);
    return ['ok'=>true,'bindings'=>$bindings,'snapshot_accepted'=>true,'server_time'=>$now];
}
function otpCandidate(string $body): string {
    preg_match_all('/(?<!\d)\d{4,8}(?!\d)/',$body,$m);return count($m[0])===1?$m[0][0]:'';
}
function messageActivity(): array {
    $out=[];
    $scope=isset($GLOBALS['request_user'])?' AND '.dataScopeSql($GLOBALS['request_user']):'';
    foreach(query('SELECT sim_id,otp_code,received_at,uploaded_at FROM messages WHERE sim_id IS NOT NULL AND deleted_at IS NULL AND uploaded_at>=?'.$scope,[microtime(true)-604800]) as $r){
        $id=(int)$r['sim_id'];$out[$id]??=['sms_count'=>0,'otp_count'=>0,'last_received_at'=>null,'last_uploaded_at'=>null];$a=&$out[$id];$a['sms_count']++;if(($r['otp_code']??null)!==null)$a['otp_count']++;
        $a['last_received_at']=max($a['last_received_at']??0,(float)$r['received_at']);$a['last_uploaded_at']=max($a['last_uploaded_at']??0,(float)$r['uploaded_at']);unset($a);
    }
    return $out;
}
function telemetryByDevice(): array {
    $out=[];$now=microtime(true);
    foreach(query('SELECT device_id,observed_at,payload,topology_at FROM device_telemetry') as $r){
        if((float)$r['observed_at']<=0)continue;
        $data=json_decode($r['payload'],true);if(!is_array($data))continue;unset($data['retired_sim_tokens']);
        $out[(int)$r['device_id']]=['observed_at'=>(float)$r['observed_at'],'subscriptions_observed_at'=>$r['topology_at']===null?null:(float)$r['topology_at'],'fresh'=>$now-(float)$r['observed_at']<=180,'data'=>$data,'events'=>[]];
    }
    foreach(query('SELECT id,device_id,kind,summary,at FROM device_events WHERE at>=? ORDER BY id DESC LIMIT 500',[$now-7776000]) as $e){
        $id=(int)$e['device_id'];if(isset($out[$id])&&count($out[$id]['events'])<10)$out[$id]['events'][]=$e;
    }
    return $out;
}
function buildAlerts(array $devices,array $sims,float $now): array {
    $items=[];$push=function(string $kind,array $d,?int $sim,string $severity,string $title,string $detail,string $action,string $page='devices')use(&$items){
        $items[]=['key'=>$kind.':'.($d['id']??0).':'.($sim??0).':'.$severity,'device_id'=>isset($d['id'])?(int)$d['id']:null,'sim_id'=>$sim,'severity'=>$severity,'title'=>$title,'detail'=>$detail,'action'=>$action,'page'=>$page,'muted_until'=>null];
    };
    foreach($devices as $d){
        if($d['revoked']??false)continue;$name=$d['name'];
        if(!$d['heartbeat']){$push('waiting',$d,null,'info',$name.' 等待接入','尚未收到手机首次通信','打开 App，填写密钥并启动采集');continue;}
        if($now-(float)$d['heartbeat']>180){$push('offline',$d,null,'critical',$name.' 已离线','超过3分钟没有联系，无法确认当前电量、网络和收码状态','检查手机是否开机、联网，以及 App 是否运行');continue;}
        $t=$d['telemetry']??null;
        if(!$t){$push('upgrade',$d,null,'info',$name.' 可升级状态采集','旧版 App 未提供电量、信号等数据','覆盖安装新版 App，再启动采集');continue;}
        if(!$t['fresh']){$push('stale',$d,null,'warning',$name.' 状态数据未更新','手机有通信，但详细状态超过3分钟未更新','打开 App 点立即同步，检查版本和权限');continue;}
        $m=$t['data'];if(($m['battery_percent']??null)===null&&($m['app_version']??null)===null&&($m['permissions']['phone']??null)===null){$push('unavailable',$d,null,'info',$name.' 详细状态未获取','手机仍可通信，但系统没有提供这次运行状态快照','打开 App 检查权限和版本，再点立即同步');continue;}$battery=$m['battery_percent']??null;
        if($battery!==null&&$battery<=20&&($m['charging']??null)===false)$push('battery',$d,null,$battery<=10?'critical':'warning',$name.' 电量偏低','上报电量 '.$battery.'%，未连接充电','接上电源并确认正在充电');
        $temp=$m['battery_temp_c']??null;if($temp!==null&&$temp>=45)$push('temperature',$d,null,$temp>=50?'critical':'warning',$name.' 电池温度偏高','系统上报电池温度 '.$temp.'°C','检查通风、充电器和手机发热情况');
        if(($m['permissions']['sms']??null)===false)$push('sms_permission',$d,null,'critical',$name.' 缺少短信权限','App 无法接收新短信','在 App 运行权限中允许短信权限');
        if(($m['permissions']['phone']??null)===false)$push('phone_permission',$d,null,'warning',$name.' 缺少电话权限','可能无法区分两张卡的状态和短信归属','在 App 运行权限中允许电话权限');
        if(($m['quarantined_count']??0)>0)$push('quarantine',$d,null,'warning',$name.' 有异常短信隔离保留',$m['quarantined_count'].'条短信未上传，正常短信不受影响','打开 App 查看异常隔离短信，修复原因后重试');
        $pending=$m['pending_count']??null;$age=$m['oldest_pending_age_seconds']??null;$fail=$m['consecutive_failures']??0;
        if($pending!==null&&$pending>0&&(($age!==null&&$age>=120)||$fail>=2))$push('backlog',$d,null,'warning',$name.' 有短信积压',$pending.'条短信尚未上传；'.($m['last_error']??'请检查同步结果'),'打开 App 查看连接结果，恢复网络后点立即同步');
        elseif($fail>=3)$push('sync_failure',$d,null,'warning',$name.' 连续同步失败','最近上报的连续失败次数：'.$fail,'查看 App 最近结果，检查服务器地址、密钥和网络');
        if(($m['network_transport']??null)==='none')$push('network',$d,null,'warning',$name.' 上报时未联网','这是手机上次采样时的网络状态，不代表每张 SIM 的信号','检查 Wi-Fi 或移动网络连接');
        $subs=json_decode($d['subscriptions']??'[]',true)??[];
        foreach($subs as $s){
            if(($s['subscription_id']??null)===null)continue;$slot=isset($s['slot'])?'卡槽 '.$s['slot']:'SIM卡';$service=$s['service_state']??'unknown';
            if(in_array($service,['out_of_service','emergency_only','power_off'],true)){
                $label=['out_of_service'=>'无服务','emergency_only'=>'仅紧急呼叫','power_off'=>'无线电已关闭'][$service];
                $push('sim_service_'.$s['subscription_id'],$d,null,'warning',$name.' '.$slot.' '.$label,'系统服务状态异常，不能据此判断欠费或注销','检查飞行模式、卡片开关、覆盖范围和漫游设置');
            }elseif($service==='in_service'&&($s['signal_level']??null)!==null&&$s['signal_level']<=1&&($s['signal_age_seconds']??null)!==null&&$s['signal_age_seconds']<=180){
                $push('signal_'.$s['subscription_id'],$d,null,'warning',$name.' '.$slot.' 最近信号弱','系统上报信号等级 '.$s['signal_level'].'/4','调整手机摆放位置并观察信号是否恢复');
            }
        }
        foreach($t['events']??[] as $event)if($event['kind']==='subscription_change'&&$now-(float)$event['at']<86400){$push('topology_'.$event['id'],$d,null,'info',$name.' 卡槽信息有变化',$event['summary'],'在设备详情核对卡槽与号码绑定');break;}
    }
    $today=(new DateTimeImmutable('@'.(int)$now))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
    foreach($sims as $s){
        $name=$s['shop'].' · '.$s['phone'];$d=['id'=>$s['device_id']??null];$id=(int)$s['id'];
        if($s['balance']!==null&&(float)$s['balance']<(float)$s['threshold'])$push('balance',$d,$id,'warning',$name.' 余额记录偏低','最近记录为 '.($s['currency']??'PHP').' '.$s['balance'].'，不代表运营商实时余额','查询实际余额，按需充值后更新记录','sims');
        if(($s['next_check']??null)&&$s['next_check']<=$today)$push('check_due',$d,$id,'info',$name.' 到检查日期','计划检查日期：'.$s['next_check'],'检查卡片状态和余额，更新下次检查日期','sims');
        if(($s['package_expires']??null)&&$s['package_expires']<$today)$push('package_due',$d,$id,'info',$name.' 套餐记录已到期','仅依据人工录入日期，不代表 SIM 已失效','向运营商确认套餐和保号状态','sims');
    }
    $ranks=['critical'=>0,'warning'=>1,'info'=>2];usort($items,fn($a,$b)=>$ranks[$a['severity']]<=>$ranks[$b['severity']]);return $items;
}
function alertResponse(array $u): array {
    $now=microtime(true);$items=buildAlerts(deviceRows(),simRows(),$now);$mutes=[];
    foreach(query('SELECT alert_key,until_at FROM alert_mutes WHERE user_id=? AND until_at>?',[$u['id'],$now]) as $m)$mutes[$m['alert_key']]=(float)$m['until_at'];
    foreach($items as &$item)if(isset($mutes[$item['key']]))$item['muted_until']=$mutes[$item['key']];
    observeAlertRecovery(null,(int)$u['id']);
    $dismissals=array_column(query('SELECT alert_key FROM alert_dismissals WHERE user_id=?',[$u['id']]),'alert_key');
    $dismissed=array_values(array_filter($items,fn($a)=>in_array($a['key'],$dismissals,true)));$items=array_values(array_filter($items,fn($a)=>!in_array($a['key'],$dismissals,true)));
    return ['dismissed'=>$dismissed,'dismissed_count'=>count($dismissed),'items'=>$items,'muted_count'=>count(array_filter($items,fn($a)=>$a['muted_until']!==null)),'checked_at'=>$now];
}
