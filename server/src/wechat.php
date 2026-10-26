<?php
declare(strict_types=1);
final class WxRejected extends RuntimeException {public function __construct(public int $wxCode){parent::__construct('WeChat API rejected');}}
/** Direct certified service-account integration. No full SMS body enters this module's payloads/logs. */
function wxMigrate():void {
 $ddl=[
 "CREATE TABLE IF NOT EXISTS wx_config(id INT PRIMARY KEY,enabled TINYINT NOT NULL DEFAULT 0,app_id VARCHAR(64) NOT NULL DEFAULT '',secret_cipher TEXT NULL,template_id VARCHAR(128) NOT NULL,origin VARCHAR(255) NOT NULL,revision INT NOT NULL DEFAULT 1,tested_revision INT NULL,token_cipher TEXT NULL,token_until DOUBLE NULL,worker_at DOUBLE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
 "CREATE TABLE IF NOT EXISTS wx_bindings(user_id BIGINT UNSIGNED PRIMARY KEY,app_id VARCHAR(64) NOT NULL,openid VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,version CHAR(48) NOT NULL,follower TINYINT NOT NULL DEFAULT 1,confirmed_at DOUBLE NOT NULL,UNIQUE KEY identity(app_id,openid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
 "CREATE TABLE IF NOT EXISTS wx_pending(token CHAR(64) PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,app_id VARCHAR(64) NOT NULL,revision INT NOT NULL,state CHAR(64) NULL UNIQUE,openid VARCHAR(128) NULL,status VARCHAR(20) NOT NULL DEFAULT 'waiting',expires_at DOUBLE NOT NULL,INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
 "CREATE TABLE IF NOT EXISTS wx_outbox(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,message_id BIGINT UNSIGNED NULL,user_id BIGINT UNSIGNED NOT NULL,binding_version CHAR(48) NOT NULL,revision INT NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'queued',attempts INT NOT NULL DEFAULT 0,next_at DOUBLE NOT NULL,expires_at DOUBLE NOT NULL,error_code VARCHAR(40) NULL,created_at DOUBLE NOT NULL,updated_at DOUBLE NOT NULL,UNIQUE KEY message_recipient(message_id,user_id),INDEX(status,next_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
 ];foreach($ddl as $sql)db()->exec($sql);
 query('INSERT IGNORE INTO wx_config(id,template_id,origin) VALUES(1,?,?)',['','']);
}
function wxConfig():array{return query('SELECT * FROM wx_config WHERE id=1')[0];}
function wxFresh(array $m):bool{$age=microtime(true)-(float)$m['received_at'];return $age>=-60&&$age<300&&is_string($m['otp_code']??null)&&preg_match('/^[A-Za-z0-9]{4,32}$/D',$m['otp_code'])===1;}
function wxPayload(array $c,string $openid,string $device,string $code,float $at):array {
 return ['touser'=>$openid,'template_id'=>$c['template_id'],'url'=>$c['origin'].'/#messages','data'=>['thing1'=>['value'=>mb_substr($device,0,20)],'character_string15'=>['value'=>$code],'time4'=>['value'=>(new DateTimeImmutable('@'.(int)$at))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s')]]];
}
function wxOutcome(?array $r):array {
 if($r===null||!array_key_exists('errcode',$r)||!is_int($r['errcode']))return ['status'=>'uncertain','error_code'=>'ambiguous'];
 $code=$r['errcode'];if($code===0&&!isset($r['msgid']))return ['status'=>'uncertain','error_code'=>'invalid_response'];
 return ['status'=>$code===0?'accepted':(in_array($code,[-1,45009,45011],true)?'retry':'failed'),'error_code'=>$code===0?null:(string)$code];
}
/** Optional callable is only a code-level test dependency, never HTTP configuration. */
function wxHttp(string $endpoint,array $params,?callable $transport=null):array {
 if(!in_array($endpoint,['stable_token','oauth','follower','send'],true))throw new LogicException('Unknown WeChat endpoint');
 if($transport!==null){$r=$transport($endpoint,$params);if(!is_array($r))throw new RuntimeException('wechat_transport');return $r;}
 $paths=['stable_token'=>'/cgi-bin/stable_token','oauth'=>'/sns/oauth2/access_token','follower'=>'/cgi-bin/user/info','send'=>'/cgi-bin/message/template/send'];
 $post=in_array($endpoint,['stable_token','send'],true);$url='https://api.weixin.qq.com'.$paths[$endpoint];
 if($endpoint==='send'){$url.='?access_token='.rawurlencode($params['access_token']);unset($params['access_token']);}
 elseif(!$post)$url.='?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
 if($post)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($params,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
 $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 // Never propagate cURL diagnostics/URLs, API errmsg or raw responses (may contain credentials).
 if($raw===false||$status!==200)throw new RuntimeException('wechat_transport');
 try{$r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('wechat_json');}
 if(!is_array($r)||array_is_list($r))throw new RuntimeException('wechat_json');return $r;
}
function wxLock(string $name,int $wait=0):bool{return (int)(query('SELECT GET_LOCK(?,?) AS locked',['sim-wx-'.substr(hash('sha256',settings()['db_name']),0,12).'-'.$name,$wait])[0]['locked']??0)===1;}
function wxUnlock(string $name):void{query('SELECT RELEASE_LOCK(?)',['sim-wx-'.substr(hash('sha256',settings()['db_name']),0,12).'-'.$name]);}
function wxToken(array $c,?callable $transport=null):string {
 if(!wxLock('token',5))throw new RuntimeException('wechat_token_busy');
 try{$live=wxConfig();if((int)$live['revision']!==(int)$c['revision'])throw new RuntimeException('wechat_changed');
 if($live['token_cipher']&&(float)$live['token_until']>microtime(true)+60)return openSecret($live['token_cipher']);
 $r=wxHttp('stable_token',['grant_type'=>'client_credential','appid'=>$c['app_id'],'secret'=>openSecret($c['secret_cipher']),'force_refresh'=>false],$transport);
 if(isset($r['errcode'])&&is_int($r['errcode'])&&$r['errcode']!==0)throw new WxRejected($r['errcode']);
 if(!is_string($r['access_token']??null)||!is_numeric($r['expires_in']??null))throw new RuntimeException('wechat_token');
 query('UPDATE wx_config SET token_cipher=?,token_until=? WHERE id=1 AND revision=?',[sealSecret($r['access_token']),microtime(true)+min(7200,max(0,(int)$r['expires_in'])),$c['revision']]);return $r['access_token'];
 }finally{wxUnlock('token');}
}
function wxApi(array $c,string $endpoint,array $p,?callable $transport=null):array {
 for($i=0;$i<2;$i++){$p['access_token']=wxToken($c,$transport);$r=wxHttp($endpoint,$p,$transport);
 if(!in_array($r['errcode']??0,[40001,40014,42001],true)||$i===1)return $r;
 query('UPDATE wx_config SET token_cipher=NULL,token_until=NULL WHERE id=1 AND revision=?',[$c['revision']]);}
 throw new LogicException('unreachable');
}
function wxFollower(array $c,string $openid,?callable $transport=null):bool {
 $r=wxApi($c,'follower',['openid'=>$openid,'lang'=>'zh_CN'],$transport);
 if(isset($r['errcode'])&&is_int($r['errcode'])&&$r['errcode']!==0)throw new WxRejected($r['errcode']);
 if(!isset($r['subscribe'])||!in_array($r['subscribe'],[0,1],true))throw new RuntimeException('wechat_follower');if($r['subscribe']===1)query('UPDATE wx_bindings SET follower=1 WHERE app_id=? AND openid=?',[$c['app_id'],$openid]);return $r['subscribe']===1;
}
function wxCanReceive(array $u):bool{return can($u,'wechat.receive')&&can($u,'messages.view')&&can($u,'messages.copy');}
function wxReceiver(array $u):void{foreach(['wechat.receive','messages.view','messages.copy'] as $p)requirePermission($u,$p);}
function wxUser(int $id):?array{$r=query('SELECT * FROM users WHERE id=? AND active=1 AND deleted_at IS NULL',[$id]);if(!$r)return null;$u=loadUserAccess($r[0]);try{wxReceiver($u);return $u;}catch(ApiError){return null;}}
function wxBinding(array $u,array $c):?array{return query('SELECT * FROM wx_bindings WHERE user_id=? AND app_id=?',[$u['id'],$c['app_id']])[0]??null;}
function wxHint(string $openid):string{return '微信身份 '.substr(hash('sha256',$openid),0,8);}
function wxBindingView(array $u,array $c):array{$b=wxBinding($u,$c);return ['bound'=>$b!==null,'follower'=>$b?(bool)$b['follower']:null,'identity_hint'=>$b?wxHint($b['openid']):null,'version'=>$b['version']??null];}
function wxRedacted(array $c):array{return ['enabled'=>(bool)$c['enabled'],'app_id'=>$c['app_id'],'secret_set'=>(bool)$c['secret_cipher'],'template_id'=>$c['template_id'],'origin'=>$c['origin'],'revision'=>(int)$c['revision'],'tested'=>(int)($c['tested_revision']??0)===(int)$c['revision'],'worker_at'=>$c['worker_at']===null?null:(float)$c['worker_at']];}
function wxCancel(?int $uid=null):void{query("UPDATE wx_outbox SET status='cancelled',error_code='changed',updated_at=? WHERE status IN ('queued','retry')".($uid===null?'':' AND user_id=?'),$uid===null?[microtime(true)]:[microtime(true),$uid]);}
function wxEnqueue(int $messageId):void {
 if(!db()->inTransaction())throw new LogicException('wxEnqueue requires SMS transaction');
 $c=wxConfig();if(!$c['enabled']||(int)$c['tested_revision']!==(int)$c['revision'])return;
 $m=query('SELECT * FROM messages WHERE id=? AND deleted_at IS NULL',[$messageId])[0]??null;if(!$m||!wxFresh($m))return;
 foreach(query('SELECT * FROM wx_bindings WHERE app_id=? AND follower=1',[$c['app_id']]) as $b){$u=wxUser((int)$b['user_id']);if(!$u)continue;try{requireDataScope($u,'message',$messageId);}catch(ApiError){continue;}
 $now=microtime(true);query('INSERT IGNORE INTO wx_outbox(message_id,user_id,binding_version,revision,next_at,expires_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)',[$messageId,$u['id'],$b['version'],$c['revision'],$now,(float)$m['received_at']+300,$now,$now]);}
}
function wxPending(array $u,string $token,array $c):array {
 if(!preg_match('/^[a-f0-9]{64}$/D',$token))fail(404,'绑定链接不存在');
 $p=query('SELECT * FROM wx_pending WHERE token=? AND user_id=? AND app_id=? AND revision=?',[$token,$u['id'],$c['app_id'],$c['revision']])[0]??null;if(!$p)fail(404,'绑定链接不存在');
 if((float)$p['expires_at']<microtime(true)&&$p['status']!=='confirmed')$p['status']='expired';return $p;
}
function wxRoute(array $u,string $method,string $path,array $body,?callable $transport=null):?array {
 if(!str_starts_with($path,'/api/wechat/'))return null;
 $c=wxConfig();
 if($path==='/api/wechat/status'&&$method==='GET'){if(!can($u,'wechat.manage'))wxReceiver($u);return ['config'=>wxRedacted($c),'binding'=>can($u,'wechat.receive')?wxBindingView($u,$c):['bound'=>false],'can_manage'=>can($u,'wechat.manage'),'can_receive'=>wxCanReceive($u),'worker_at'=>$c['worker_at']===null?null:(float)$c['worker_at'],'last_error_code'=>query('SELECT error_code FROM wx_outbox WHERE user_id=? AND error_code IS NOT NULL ORDER BY id DESC LIMIT 1',[$u['id']])[0]['error_code']??null];}
 if($path==='/api/wechat/config'&&$method==='PUT'){
 requirePermission($u,'wechat.manage');$n=$c;
 foreach(['app_id'=>64,'template_id'=>128,'origin'=>255] as $k=>$len)if(array_key_exists($k,$body))$n[$k]=trim(stringField($body,$k,$len));
 if($n['app_id']!==''&&!preg_match('/^wx[a-fA-F0-9]{16}$/D',$n['app_id']))fail(422,'AppID 格式错误');
 if(!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$n['template_id']))fail(422,'模板 ID 格式错误');
 $o=parse_url($n['origin']);if(!$o||($o['scheme']??'')!=='https'||empty($o['host'])||isset($o['user'])||isset($o['pass'])||isset($o['query'])||isset($o['fragment'])||(!empty($o['path'])&&$o['path']!=='/')||isset($o['port']))fail(422,'授权站点必须为 HTTPS 根域名');$n['origin']=rtrim($n['origin'],'/');
 if(array_key_exists('app_secret',$body)){$s=stringField($body,'app_secret',256,false);if($s!=='')$n['secret_cipher']=sealSecret($s);}
 $changed=$n['app_id']!==$c['app_id']||$n['template_id']!==$c['template_id']||$n['origin']!==$c['origin']||$n['secret_cipher']!==$c['secret_cipher'];
 if(array_key_exists('enabled',$body)&&!is_bool($body['enabled']))fail(422,'开关格式错误');$enabled=$body['enabled']??(bool)$c['enabled'];
 if($enabled&&($changed||!$c['secret_cipher']||$c['app_id']===''||(int)$c['tested_revision']!==(int)$c['revision']))fail(422,'请先保存配置、绑定本人微信并测试成功后开启');
 if(!wxLock('worker',5))fail(409,'微信任务处理中，请重试');
 try{db()->beginTransaction();$live=wxConfig();if((int)$live['revision']!==(int)$c['revision'])fail(409,'配置已变化，请刷新');
 query('UPDATE wx_config SET enabled=?,app_id=?,secret_cipher=?,template_id=?,origin=?,revision=?,tested_revision=?,token_cipher=?,token_until=? WHERE id=1',[$changed?0:(int)$enabled,$n['app_id'],$n['secret_cipher'],$n['template_id'],$n['origin'],(int)$c['revision']+($changed?1:0),$changed?null:$c['tested_revision'],$changed?null:$c['token_cipher'],$changed?null:$c['token_until']]);
 if($n['app_id']!==$c['app_id'])query('DELETE FROM wx_bindings');
 if($changed||!$enabled){wxCancel();query("UPDATE wx_pending SET status='expired',state=NULL WHERE status IN ('waiting','pending','authorizing','exchanging')");}db()->commit();
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}finally{wxUnlock('worker');}
 audit($u,'wechat_config',1,['summary'=>'修改微信推送配置']);return ['config'=>wxRedacted(wxConfig())];
 }
 wxReceiver($u);
 if($path==='/api/wechat/binding'){
 if($method==='POST'){
 if(!$c['app_id']||!$c['secret_cipher'])fail(422,'请管理员先配置公众号');
 query("UPDATE wx_pending SET status='expired',state=NULL WHERE user_id=? AND status IN ('waiting','pending','authorizing','exchanging')",[$u['id']]);$token=bin2hex(random_bytes(32));$exp=microtime(true)+600;
 query('INSERT INTO wx_pending(token,user_id,app_id,revision,expires_at) VALUES(?,?,?,?,?)',[$token,$u['id'],$c['app_id'],$c['revision'],$exp]);audit($u,'wechat_binding_start',$u['id'],['summary'=>'生成本人微信绑定码']);return ['token'=>$token,'url'=>$c['origin'].'/wx/bind?t='.$token,'status'=>'waiting','expires_at'=>$exp];
 }
 if($method==='GET'){$token=(string)($_GET['token']??'');if($token==='')return wxBindingView($u,$c);$p=wxPending($u,$token,$c);return ['token'=>$token,'status'=>$p['status'],'identity_hint'=>$p['openid']?wxHint($p['openid']):null,'expires_at'=>(float)$p['expires_at']];}
 if($method==='DELETE'){if(!wxLock('worker',5))fail(409,'任务处理中，请重试');try{query('DELETE FROM wx_bindings WHERE user_id=?',[$u['id']]);query("UPDATE wx_pending SET status='expired',state=NULL WHERE user_id=?",[$u['id']]);wxCancel((int)$u['id']);}finally{wxUnlock('worker');}audit($u,'wechat_unbind',$u['id'],['summary'=>'解除本人微信绑定']);return ['ok'=>true];}
 }
 if($path==='/api/wechat/binding/confirm'&&$method==='POST'){
 $p=wxPending($u,stringField($body,'token',64),$c);if($p['status']!=='pending'||!$p['openid'])fail(409,'请先扫码授权，链接只能确认一次');
 if(!wxLock('worker',5))fail(409,'任务处理中，请重试');
 try{if(!wxFollower($c,$p['openid'],$transport))fail(422,'请先关注公众号再绑定');db()->beginTransaction();$p=wxPending($u,$p['token'],wxConfig());$locked=query('SELECT status FROM wx_pending WHERE token=? FOR UPDATE',[$p['token']])[0];if($p['status']!=='pending'||$locked['status']!=='pending')fail(409,'链接已使用或过期');
 if(query('SELECT user_id FROM wx_bindings WHERE app_id=? AND openid=? AND user_id<>?',[$c['app_id'],$p['openid'],$u['id']]))fail(409,'此微信已绑定其他账户，请先解绑');
 query('DELETE FROM wx_bindings WHERE user_id=?',[$u['id']]);query('INSERT INTO wx_bindings(user_id,app_id,openid,version,confirmed_at) VALUES(?,?,?,?,?)',[$u['id'],$c['app_id'],$p['openid'],bin2hex(random_bytes(24)),microtime(true)]);query("UPDATE wx_pending SET status='confirmed',state=NULL WHERE token=?",[$p['token']]);wxCancel((int)$u['id']);audit($u,'wechat_binding_confirm',$u['id'],['summary'=>'确认本人微信绑定']);db()->commit();return ['ok'=>true];
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if($e instanceof ApiError)throw $e;fail(502,'微信身份确认失败，请稍后重试');}finally{wxUnlock('worker');}
 }
 if($path==='/api/wechat/test'&&$method==='POST'){
 $code=stringField($body,'code',6);if(!preg_match('/^\d{6}$/D',$code))fail(422,'请输入六位测试验证码');
 if(!wxLock('worker',5))fail(409,'任务处理中，请重试');try{$c=wxConfig();$b=wxBinding($u,$c);if(!$b)fail(422,'请先绑定本人微信');$now=microtime(true);$id=insert("INSERT INTO wx_outbox(message_id,user_id,binding_version,revision,status,next_at,expires_at,created_at,updated_at) VALUES(NULL,?,?,?,'sending',?,?,?,?)",[$u['id'],$b['version'],$c['revision'],$now,$now+300,$now,$now]);
 try{if(!wxFollower($c,$b['openid'],$transport)){$r=['status'=>'failed','error_code'=>'unsubscribed'];query('UPDATE wx_bindings SET follower=0 WHERE user_id=?',[$u['id']]);}else $r=wxOutcome(wxApi($c,'send',wxPayload($c,$b['openid'],'测试设备',$code,$now),$transport));}catch(WxRejected $e){$r=['status'=>'failed','error_code'=>(string)$e->wxCode];}catch(Throwable){$r=['status'=>'uncertain','error_code'=>'transport'];}
 if($r['status']==='retry')$r['status']='failed';wxFinish($id,$r,1);if($r['status']==='accepted')query('UPDATE wx_config SET tested_revision=revision WHERE id=1 AND revision=?',[$c['revision']]);audit($u,'wechat_test',$u['id'],['summary'=>'本人微信测试：'.$r['status']]);return $r;
 }finally{wxUnlock('worker');}
 }
 if($path==='/api/wechat/logs'&&$method==='GET'){$items=[];foreach(query('SELECT id,message_id,status,attempts,error_code,created_at,updated_at FROM wx_outbox WHERE user_id=? ORDER BY id DESC LIMIT 100',[$u['id']]) as $row){if($row['message_id']!==null){try{requireDataScope($u,'message',(int)$row['message_id']);}catch(ApiError){continue;}}$items[]=$row;}return ['items'=>$items,'worker_at'=>$c['worker_at']];}
 fail(404,'接口不存在');
}
function wxPublicRoute(string $method,string $path,?callable $transport=null):bool {
 if($method!=='GET'||!in_array($path,['/wx/bind','/wx/callback'],true))return false;
 header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'");header('Content-Type: text/html; charset=utf-8');
 try{$c=wxConfig();
 if($path==='/wx/bind'){
 $token=(string)($_GET['t']??'');$p=query("SELECT * FROM wx_pending WHERE token=? AND status='waiting' AND expires_at>? AND app_id=? AND revision=?",[$token,microtime(true),$c['app_id'],$c['revision']])[0]??null;if(!$p)throw new RuntimeException('expired');
 $state=bin2hex(random_bytes(32));query("UPDATE wx_pending SET state=?,status='authorizing' WHERE token=? AND status='waiting'",[$state,$token]);
 $url='https://open.weixin.qq.com/connect/oauth2/authorize?'.http_build_query(['appid'=>$c['app_id'],'redirect_uri'=>$c['origin'].'/wx/callback','response_type'=>'code','scope'=>'snsapi_base','state'=>$state],'','&',PHP_QUERY_RFC3986).'#wechat_redirect';header('Location: '.$url, true,302);return true;
 }
 $state=(string)($_GET['state']??'');$code=(string)($_GET['code']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$state)||$code===''||strlen($code)>512)throw new RuntimeException('invalid');
 db()->beginTransaction();$p=query("SELECT * FROM wx_pending WHERE state=? AND status='authorizing' AND expires_at>? AND app_id=? AND revision=? FOR UPDATE",[$state,microtime(true),$c['app_id'],$c['revision']])[0]??null;if(!$p)throw new RuntimeException('expired');query("UPDATE wx_pending SET state=NULL,status='exchanging' WHERE token=?",[$p['token']]);db()->commit();
 $r=wxHttp('oauth',['appid'=>$c['app_id'],'secret'=>openSecret($c['secret_cipher']),'code'=>$code,'grant_type'=>'authorization_code'],$transport);$openid=$r['openid']??null;if(!is_string($openid)||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$openid))throw new RuntimeException('oauth');
 query("UPDATE wx_pending SET openid=?,status='pending' WHERE token=? AND status='exchanging' AND revision=? AND expires_at>?",[$openid,$p['token'],$c['revision'],microtime(true)]);
 echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>微信授权</title><main><h1>授权已收到</h1><p>微信身份标识：'.htmlspecialchars(wxHint($openid),ENT_QUOTES,'UTF-8').'</p><p>请回到发起绑定的后台账户，核对微信身份标识并点击确认。扫码本身不会完成绑定。</p></main>';
 }catch(Throwable){if(db()->inTransaction())db()->rollBack();http_response_code(400);echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>绑定链接无效</title><h1>授权链接已使用、过期或不可用</h1><p>请回到后台重新生成绑定二维码。</p>';}
 return true;
}
function wxFinish(int $id,array $r,int $attempts):void {
 $status=$r['status'];if($status==='retry'&&$attempts>=3)$status='failed';query('UPDATE wx_outbox SET status=?,attempts=?,error_code=?,next_at=?,updated_at=? WHERE id=?',[$status,$attempts,$r['error_code'],microtime(true)+min(120,15*(2**max(0,$attempts-1))),microtime(true),$id]);
}
function wxRun(int $limit=5,?callable $transport=null):array {
 if(!wxLock('worker'))return ['processed'=>0,'busy'=>true];$done=0;
 try{query('UPDATE wx_config SET worker_at=? WHERE id=1',[microtime(true)]);query("UPDATE wx_outbox SET status='uncertain',error_code='worker_interrupted',updated_at=? WHERE status='sending'",[microtime(true)]);
 foreach(query("SELECT * FROM wx_outbox WHERE status IN ('queued','retry') AND next_at<=? ORDER BY id LIMIT ".max(1,min(50,$limit)),[microtime(true)]) as $o){$id=(int)$o['id'];$c=wxConfig();$u=wxUser((int)$o['user_id']);$b=$u?wxBinding($u,$c):null;$status=null;
 if((float)$o['expires_at']<=microtime(true))$status='expired';elseif(!$c['enabled']||(int)$c['tested_revision']!==(int)$c['revision']||(int)$o['revision']!==(int)$c['revision']||!$b||$b['version']!==$o['binding_version']||!$b['follower'])$status='cancelled';
 $m=null;if(!$status){try{$m=requireDataScope($u,'message',(int)$o['message_id']);if(!wxFresh($m))$status='expired';}catch(ApiError){$status='cancelled';}}
 if($status){wxFinish($id,['status'=>$status,'error_code'=>$status],(int)$o['attempts']);$done++;continue;}
 try{if(!wxFollower($c,$b['openid'],$transport)){query('UPDATE wx_bindings SET follower=0 WHERE user_id=?',[$u['id']]);wxFinish($id,['status'=>'cancelled','error_code'=>'unsubscribed'],(int)$o['attempts']);$done++;continue;}}catch(Throwable){wxFinish($id,['status'=>'retry','error_code'=>'follower_unavailable'],(int)$o['attempts']+1);$done++;continue;}
 // API call may take seconds: recheck scope, freshness and active account after follower lookup.
 $u=wxUser((int)$o['user_id']);try{if(!$u)throw new RuntimeException('revoked');$m=requireDataScope($u,'message',(int)$o['message_id']);}catch(Throwable){wxFinish($id,['status'=>'cancelled','error_code'=>'scope_changed'],(int)$o['attempts']);$done++;continue;}
 if(!wxFresh($m)){wxFinish($id,['status'=>'expired','error_code'=>'expired'],(int)$o['attempts']);$done++;continue;}
 $d=query('SELECT name FROM devices WHERE id=?',[$m['device_id']])[0]??['name'=>'采集设备'];query("UPDATE wx_outbox SET status='sending',updated_at=? WHERE id=?",[microtime(true),$id]);
 try{$r=wxOutcome(wxApi($c,'send',wxPayload($c,$b['openid'],$d['name'],$m['otp_code'],(float)$m['received_at']),$transport));}catch(WxRejected $e){$r=['status'=>'failed','error_code'=>(string)$e->wxCode];}catch(Throwable){$r=['status'=>'uncertain','error_code'=>'transport'];}wxFinish($id,$r,(int)$o['attempts']+1);$done++;
 }return ['processed'=>$done,'busy'=>false];
 }finally{wxUnlock('worker');}
}
