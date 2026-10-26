<?php
declare(strict_types=1);
// Read-only polling never advances heartbeat: only a new phone snapshot can do that.
function refreshState(int $id): ?array {
 $r=query('SELECT id,requested_at,acknowledged_at FROM device_refresh_requests WHERE device_id=? ORDER BY id DESC LIMIT 1',[$id]);
 if(!$r)return null;$r=$r[0];return ['id'=>(int)$r['id'],'requested_at'=>(float)$r['requested_at'],'acknowledged_at'=>$r['acknowledged_at']===null?null:(float)$r['acknowledged_at'],'status'=>$r['acknowledged_at']!==null?'completed':(microtime(true)-(float)$r['requested_at']>120?'timeout':'pending')];
}
function refreshPoll(int $id): array {
 $r=refreshState($id);return ['ok'=>true,'refresh_request_id'=>$r&&$r['status']==='pending'?$r['id']:null,'refresh_requested_at'=>$r&&$r['status']==='pending'?$r['requested_at']:null];
}
function requestSnapshots(array $u,array $body): array {
 $ids=$body['device_ids']??null;if($ids!==null&&(!is_array($ids)||!array_is_list($ids)||count($ids)>200))fail(422,'设备列表格式无效');
 if($ids!==null)foreach($ids as $id)if(!is_int($id)||$id<1)fail(422,'设备编号格式无效');
 $devices=query('SELECT id,name FROM devices WHERE deleted_at IS NULL AND revoked=0 ORDER BY id');$out=[];
 foreach($devices as $d){$id=(int)$d['id'];if(!seesDevice($u,$id))continue;if(!ownsWholeDevice($u,$id))$d['name']='共享采集设备 #'.$id;if($ids!==null&&!in_array($id,$ids,true))continue;
  db()->beginTransaction();try{
   if(!query('SELECT id FROM devices WHERE id=? AND deleted_at IS NULL AND revoked=0 FOR UPDATE',[$id])){db()->commit();continue;}
   $r=refreshState($id);if(!$r||$r['status']!=='pending'){insert('INSERT INTO device_refresh_requests(device_id,requested_at) VALUES(?,?)',[$id,microtime(true)]);$r=refreshState($id);}
   db()->commit();$out[]=['device_id'=>$id,'name'=>$d['name'],'request'=>$r];
  }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 }
 audit($u,'request_phone_refresh',count($out),['object_type'=>'operation','object_name'=>count($out).'台设备','summary'=>'请求手机上报：'.implode('、',array_column($out,'name')),'shop_ids'=>$u['all_shops']?array_map('intval',array_column(query('SELECT id FROM shops WHERE deleted_at IS NULL'),'id')):$u['shop_ids']]);return ['ok'=>true,'requests'=>$out,'requested_at'=>microtime(true),'notice'=>'已请求手机上报，需新版App运行且联网；离线手机不能被网页唤醒'];
}
function acknowledgeSnapshot(int $id,array $body): void {
 if(!isset($body['refresh_request_id']))return;$request=intField($body,'refresh_request_id');
 query('UPDATE device_refresh_requests SET acknowledged_at=? WHERE id=? AND device_id=? AND acknowledged_at IS NULL AND requested_at>=?',[microtime(true),$request,$id,microtime(true)-120]);
}
function startReceiveCheck(array $device,array $b): array {
 $sub=intField($b,'subscription_id');$code=strtoupper(stringField($b,'code',6));if(!preg_match('/^[A-F0-9]{6}$/D',$code))fail(422,'测试标记为6位十六进制字符');
 $binding=query('SELECT b.sim_id,s.phone,s.shop FROM bindings b JOIN sims s ON b.sim_id=s.id WHERE b.device_id=? AND b.subscription_id=? AND s.deleted_at IS NULL',[$device['id'],$sub]);$s=$binding[0]??[];if(isset($s['sim_id']))$s['shop']=linkedShopDisplay((int)$s['sim_id']);$now=microtime(true);
 // Repeated network submissions reuse the original time window, never erase a completed test.
 query('INSERT INTO receive_checks(device_id,code,subscription_id,started_at,expires_at,sim_id) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE code=VALUES(code)',[$device['id'],$code,$sub,$now,$now+600,$s['sim_id']??null]);
 $r=query('SELECT * FROM receive_checks WHERE device_id=? AND code=?',[$device['id'],$code])[0];if((int)$r['subscription_id']!==$sub)fail(409,'测试标记已用于其他卡槽，请重新生成');
 return ['ok'=>true,'started_at'=>(float)$r['started_at'],'expires_at'=>(float)$r['expires_at'],'phone'=>$s['phone']??null,'shop'=>$s['shop']??null];
}
function markReceiveCheck(array $device,int $messageId): void {
 $rows=query('SELECT * FROM messages WHERE id=? AND device_id=?',[$messageId,$device['id']]);if(!$rows)return;$m=$rows[0];
 preg_match_all('/(?<![A-Za-z0-9])SIMTEST-([A-Fa-f0-9]{6})(?![A-Za-z0-9])/',$m['body'],$matches);
 foreach(array_unique($matches[1]) as $code)query('UPDATE receive_checks SET message_id=? WHERE device_id=? AND code=? AND subscription_id=? AND message_id IS NULL AND started_at<=? AND expires_at>=?',[$messageId,$device['id'],strtoupper($code),$m['subscription_id'],$m['uploaded_at'],$m['uploaded_at']]);
}
function receiveCheckStatus(array $device,array $b): array {
 $code=strtoupper(stringField($b,'code',6));$r=query('SELECT c.*,m.id mid,m.sim_id actual_sim_id,m.received_at,m.uploaded_at,s.phone,s.shop FROM receive_checks c LEFT JOIN messages m ON c.message_id=m.id AND m.deleted_at IS NULL LEFT JOIN sims s ON m.sim_id=s.id WHERE c.device_id=? AND c.code=?',[$device['id'],$code]);if(!$r)fail(404,'测试尚未创建或已清理');$r=$r[0];
 if($r['actual_sim_id']!==null)$r['shop']=linkedShopDisplay((int)$r['actual_sim_id']);
 $received=$r['mid']!==null;$assigned=$received&&$r['sim_id']!==null&&$r['actual_sim_id']!==null&&(int)$r['sim_id']===(int)$r['actual_sim_id'];
 return ['ok'=>true,'received'=>$received,'assigned'=>$assigned,'phone'=>$r['phone'],'shop'=>$r['shop'],'received_at'=>$r['received_at']===null?null:(float)$r['received_at'],'uploaded_at'=>$r['uploaded_at']===null?null:(float)$r['uploaded_at'],'expired'=>!$received&&microtime(true)>(float)$r['expires_at']];
}
function latestReceiveCheck(int $device): ?array {
 $r=query('SELECT code FROM receive_checks WHERE device_id=? ORDER BY started_at DESC LIMIT 1',[$device]);if(!$r)return null;
 return receiveCheckStatus(['id'=>$device],['code'=>$r[0]['code']]);
}
function snapshotRequestStatuses(array $u,array $body):array {
 $ids=$body['request_ids']??null;if(!is_array($ids)||!array_is_list($ids)||count($ids)>200)fail(422,'请求编号格式无效');foreach($ids as $id)if(!is_int($id)||$id<1)fail(422,'请求编号必须为正整数');$out=[];$now=microtime(true);
 foreach(array_unique($ids) as $id){$rows=query('SELECT id,device_id,requested_at,acknowledged_at FROM device_refresh_requests WHERE id=?',[$id]);if(!$rows)fail(404,'上报请求不存在或已清理');$r=$rows[0];$d=requireDeviceScope($u,(int)$r['device_id']);$out[]=['device_id'=>(int)$r['device_id'],'name'=>ownsWholeDevice($u,(int)$r['device_id'])?$d['name']:'共享采集设备 #'.$r['device_id'],'request'=>['id'=>(int)$r['id'],'requested_at'=>(float)$r['requested_at'],'acknowledged_at'=>$r['acknowledged_at']===null?null:(float)$r['acknowledged_at'],'status'=>$r['acknowledged_at']!==null?'completed':($now-(float)$r['requested_at']>120?'timeout':'pending')]];}
 return ['ok'=>true,'requests'=>$out,'server_at'=>$now];
}
