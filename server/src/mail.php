<?php
declare(strict_types=1);

function mailMigrate():void {
 foreach([
  "CREATE TABLE IF NOT EXISTS mail_config(id INT PRIMARY KEY,client_id VARCHAR(64) NOT NULL DEFAULT '',client_secret_cipher TEXT NULL,origin VARCHAR(255) NOT NULL DEFAULT '',revision INT NOT NULL DEFAULT 1,worker_at DOUBLE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  "CREATE TABLE IF NOT EXISTS mail_accounts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,label VARCHAR(100) NOT NULL DEFAULT '',notes VARCHAR(500) NOT NULL DEFAULT '',shop_id BIGINT UNSIGNED NULL,created_by BIGINT UNSIGNED NULL,status VARCHAR(20) NOT NULL DEFAULT 'disconnected',generation INT NOT NULL DEFAULT 1,client_id VARCHAR(64) NULL,provider_id VARCHAR(200) NULL,refresh_cipher MEDIUMTEXT NULL,access_cipher MEDIUMTEXT NULL,access_until DOUBLE NULL,connected_at DOUBLE NULL,last_sync DOUBLE NULL,next_sync DOUBLE NOT NULL DEFAULT 0,sync_cursor MEDIUMTEXT NULL,error_code VARCHAR(50) NULL,INDEX(status,next_sync)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  'CREATE TABLE IF NOT EXISTS mail_grants(account_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(account_id,user_id),INDEX(user_id,account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
  "CREATE TABLE IF NOT EXISTS mail_oauth(state CHAR(64) PRIMARY KEY,account_id BIGINT UNSIGNED NOT NULL,initiator_id BIGINT UNSIGNED NOT NULL,session_context CHAR(64) NOT NULL,revision INT NOT NULL,generation INT NOT NULL,verifier_cipher TEXT NOT NULL,expires_at DOUBLE NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'waiting',error_code VARCHAR(50) NULL,INDEX(account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  "CREATE TABLE IF NOT EXISTS mail_codes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_id BIGINT UNSIGNED NOT NULL,message_key CHAR(64) NOT NULL,email_snapshot VARCHAR(254) NOT NULL,sender VARCHAR(254) NOT NULL,subject VARCHAR(300) NOT NULL,code VARCHAR(16) NOT NULL,platform VARCHAR(16) NULL,shop_snapshot VARCHAR(200) NOT NULL DEFAULT '',received_at DOUBLE NOT NULL,created_at DOUBLE NOT NULL,UNIQUE KEY message(account_id,message_key),INDEX(received_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  'CREATE TABLE IF NOT EXISTS mail_seen(account_id BIGINT UNSIGNED NOT NULL,message_key CHAR(64) NOT NULL,seen_at DOUBLE NOT NULL,PRIMARY KEY(account_id,message_key),INDEX(seen_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
 ] as $sql)db()->exec($sql);
 query('INSERT IGNORE INTO mail_config(id) VALUES(1)');
}
function mailConfig():array{return query('SELECT * FROM mail_config WHERE id=1')[0];}
function mailReady(array $c):bool{return $c['client_id']!==''&&!empty($c['client_secret_cipher'])&&$c['origin']!=='';}
function mailLock(string $key,int $wait=0):bool{return (int)query('SELECT GET_LOCK(?,?) AS locked',['mail-'.substr(hash('sha256',settings()['db_name']),0,12).'-'.$key,$wait])[0]['locked']===1;}
function mailUnlock(string $key):void{query('SELECT RELEASE_LOCK(?)',['mail-'.substr(hash('sha256',settings()['db_name']),0,12).'-'.$key]);}
function mailScope(array $u,string $column='id',string $owner='created_by'):string {
 if(!preg_match('/^[a-zA-Z0-9_.]+$/D',$column)||!preg_match('/^[a-zA-Z0-9_.]+$/D',$owner))throw new LogicException('mail scope column');
 return $u['role']==='admin'?'1=1':'('.$owner.'='.(int)$u['id'].' OR EXISTS(SELECT 1 FROM mail_grants mg WHERE mg.account_id='.$column.' AND mg.user_id='.(int)$u['id'].'))';
}
function mailAccount(array $u,int $id):array{$r=query('SELECT * FROM mail_accounts WHERE id=? AND '.mailScope($u),[$id]);if(!$r)fail(404,'邮箱不存在或未授权');return $r[0];}
function mailErrorLabel(?string $code):string{return match($code){null,''=>'', 'needs_auth'=>'授权已失效，请重新连接 Outlook','wrong_account'=>'登录的微软邮箱与所选邮箱不一致','session_changed'=>'发起授权的登录或权限已失效，请重新连接','configuration_changed'=>'配置或邮箱已变化，请重新连接','invalid_client'=>'微软应用配置无效，请管理员核对应用密钥','throttled'=>'微软暂时限制访问，将按要求自动重试','backlog'=>'正在补收积压邮件','transport'=>'微软连接暂时失败，稍后自动重试','forbidden'=>'缺少微软邮件读取授权，请重新连接','provider'=>'微软收件暂时失败，稍后自动重试',default=>'收件尚未完成，请稍后重试'};}
function mailDto(array $r):array {$out=array_intersect_key($r,array_flip(['id','email','label','notes','shop_id','status','connected_at','last_sync','next_sync']));$out['error']=mailErrorLabel($r['error_code']??null);$out['shop']=$r['shop_id']?query('SELECT name FROM shops WHERE id=?',[$r['shop_id']])[0]['name']??'':'';return $out;}
function mailAddress(string $email):string{$email=strtolower(trim($email));if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[^\x21-\x7e]/',$email))fail(422,'请填写有效邮箱地址');return $email;}
function mailImportRows(string $text):array {$emails=[];$invalid=0;foreach(preg_split('/\R/u',$text) as $line){$line=trim($line);if($line==='')continue;$first=preg_split('/----|[,;\s]+/u',$line)[0];try{$email=mailAddress($first);$emails[$email]=true;}catch(ApiError){$invalid++;}}return ['emails'=>array_keys($emails),'invalid'=>$invalid];}
function mailBodyText(string $body):string{$body=preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is',' ',$body);$body=preg_replace('#<(?:br\s*/?|/p|/div|/tr)>#i',"\n",$body);return html_entity_decode(strip_tags($body),ENT_QUOTES|ENT_HTML5,'UTF-8');}
function mailExtractCode(string $subject,string $body):string {
 $text=mb_substr($subject."\n".mailBodyText($body),0,100000);$context='verification\s*code|security\s*code|authentication\s*code|one[ -]*time\s*(?:password|code)|验证码|校验码|动态码|\bOTP\b|\bPIN\b';
 if(preg_match('/\bcode\b/i',$text)&&!preg_match('/\b(?:promo|coupon|discount|order|tracking|parcel)\b/i',$text))$context.='|\bcode\b';
 $candidate='(?<![A-Za-z0-9])((?=[A-Za-z0-9]{0,11}[0-9])[A-Za-z0-9]{4,12})(?![A-Za-z0-9])';$found=[];
 foreach(['/(?i:'.$context.')[^\r\n]{0,40}?'.$candidate.'/u','/'.$candidate.'[^\r\n]{0,40}?(?i:'.$context.')/u'] as $pattern)if(preg_match_all($pattern,$text,$m))foreach($m[1] as $code)$found[$code]=true;
 if(count($found)===1)return (string)array_key_first($found);if(count($found)>1)return '';
 $numeric=preg_replace('/\b(?=[A-Za-z0-9]*[A-Za-z])(?=[A-Za-z0-9]*[0-9])[A-Za-z0-9]+\b/',' ',$text);return messageOtp('',$numeric);
}
function mailDisconnectRow(int $id):void {query("UPDATE mail_accounts SET status='disconnected',generation=generation+1,refresh_cipher=NULL,access_cipher=NULL,access_until=NULL,client_id=NULL,provider_id=NULL,connected_at=NULL,sync_cursor=NULL,error_code=NULL WHERE id=?",[$id]);query("UPDATE mail_oauth SET status='expired' WHERE account_id=? AND status IN ('waiting','exchanging')",[$id]);}
function mailCreate(array $u,array $b):int {$email=mailAddress(stringField($b,'email',254));$label=trim(stringField($b,'label',100,false));$notes=stringField($b,'notes',500,false);$shop=isset($b['shop_id'])&&$b['shop_id']!==null?intField($b,'shop_id'):null;if($shop&&(!canShop($u,$shop)||!query('SELECT id FROM shops WHERE id=? AND deleted_at IS NULL',[$shop])))fail(404,'店铺不在授权范围');$id=insert('INSERT INTO mail_accounts(email,label,notes,shop_id,created_by) VALUES(?,?,?,?,?)',[$email,$label,$notes,$shop?:null,$u['id']]);audit($u,'mail_account_add',$id,['object_type'=>'operation','object_name'=>$email,'summary'=>'添加 Outlook 邮箱']);return $id;}
function mailSettingsView(array $c):array{return ['client_id'=>$c['client_id'],'secret_set'=>(bool)$c['client_secret_cipher'],'origin'=>$c['origin'],'redirect_uri'=>$c['origin']!==''?$c['origin'].'/oauth/outlook/callback':'','ready'=>mailReady($c)];}
function mailRoute(array $u,string $method,string $path,array $b):?array {
 if(!str_starts_with($path,'/api/mail/'))return null;
 if($path==='/api/mail/status'&&$method==='GET'){requireAnyPermission($u,['mail.view','mail.create','mail.settings','mail.codes.view']);$c=mailConfig();return ['ready'=>mailReady($c),'worker_at'=>$c['worker_at'],'poll_seconds'=>30];}
 if($path==='/api/mail/settings'){
  requirePermission($u,'mail.settings');$c=mailConfig();if($method==='GET')return mailSettingsView($c);
  if($method==='PUT'){
   $client=trim(stringField($b,'client_id',64));if(!preg_match('/^[a-fA-F0-9-]{36}$/D',$client))fail(422,'请填写微软应用的客户端 ID');$origin=rtrim(trim(stringField($b,'origin',255)),'/');$url=parse_url($origin);if(!$url||($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['port'])||isset($url['query'])||isset($url['fragment'])||(!empty($url['path'])&&$url['path']!=='/'))fail(422,'请填写本站 HTTPS 根地址');$secret=stringField($b,'client_secret',4096,false);
   if(!mailLock('config',5))fail(409,'应用配置正在更新，请重试');try{db()->beginTransaction();$c=mailConfig();$sealed=$secret!==''?sealSecret($secret):$c['client_secret_cipher'];$changed=$c['client_id']!==$client||$c['origin']!==$origin||$secret!=='';query('UPDATE mail_config SET client_id=?,origin=?,client_secret_cipher=?,revision=revision+? WHERE id=1',[$client,$origin,$sealed,$changed?1:0]);if($c['client_id']!==$client){query("UPDATE mail_accounts SET status='disconnected',generation=generation+1,client_id=NULL,refresh_cipher=NULL,access_cipher=NULL,access_until=NULL,connected_at=NULL,sync_cursor=NULL,error_code=NULL");}if($changed)query("UPDATE mail_oauth SET status='expired' WHERE status IN ('waiting','exchanging')");db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}finally{mailUnlock('config');}audit($u,'mail_settings',1,['object_type'=>'operation','summary'=>'更新 Outlook 应用配置（不记录密钥）']);return mailSettingsView(mailConfig());
  }
 }
 if($path==='/api/mail/accounts'){
  if($method==='GET'){requireAnyPermission($u,['mail.view','mail.create','mail.codes.view','mail.connect','mail.settings']);return array_map('mailDto',query('SELECT * FROM mail_accounts WHERE '.mailScope($u).' ORDER BY id'));}
  if($method==='POST'){requirePermission($u,'mail.create');return ['id'=>mailCreate($u,$b)];}
 }
 if($path==='/api/mail/import'&&$method==='POST'){requirePermission($u,'mail.create');$parsed=mailImportRows(stringField($b,'text',40000));$made=0;$skipped=0;foreach($parsed['emails'] as $email){if(query('SELECT id FROM mail_accounts WHERE email=?',[$email])){$skipped++;continue;}try{mailCreate($u,['email'=>$email]);$made++;}catch(PDOException $e){if(($e->errorInfo[1]??null)===1062)$skipped++;else throw $e;}}return ['created'=>$made,'skipped'=>$skipped,'invalid'=>$parsed['invalid']];}
 if(preg_match('#^/api/mail/accounts/(\d+)(?:/(connect|connect-status|disconnect|sync|grants))?$#D',$path,$m)){
  $id=(int)$m[1];$op=$m[2]??'';$permission=match($op){'connect','connect-status','disconnect'=>'mail.connect','sync'=>'mail.sync','grants'=>'mail.permissions',default=>$method==='DELETE'?'mail.delete':($method==='PUT'?'mail.edit':'mail.view')};requirePermission($u,$permission);$account=mailAccount($u,$id);
  if($op==='connect'&&$method==='POST')return mailStart($u,$account);
  if($op==='connect-status'&&$method==='GET'){$state=(string)($_GET['state']??'');$r=query('SELECT status,error_code,expires_at FROM mail_oauth WHERE state=? AND account_id=? AND initiator_id=? AND session_context=?',[$state,$id,$u['id'],$u['access_context']]);if(!$r)fail(404,'授权请求不存在');return ['status'=>(float)$r[0]['expires_at']<microtime(true)&&$r[0]['status']==='waiting'?'expired':$r[0]['status'],'error'=>mailErrorLabel($r[0]['error_code'])];}
  if($op==='grants'){
   $users=array_values(array_filter(query('SELECT * FROM users WHERE role=? AND active=1 AND deleted_at IS NULL',['operator']),fn($target)=>canManageAccount($u,$target)));$allowed=array_map('intval',array_column($users,'id'));
   if($method==='GET')return ['user_ids'=>array_map('intval',array_column(query('SELECT user_id FROM mail_grants WHERE account_id=?',[$id]),'user_id')),'users'=>array_map(fn($v)=>['id'=>$v['id'],'username'=>$v['username']],$users)];
   if($method==='PUT'){
    $ids=$b['user_ids']??null;if(!is_array($ids)||!array_is_list($ids))fail(422,'运营账户列表无效');foreach($ids as $uid)if(!is_int($uid)||!in_array($uid,$allowed,true))fail(404,'运营不在可管理范围');
    foreach(['mail.view','mail.codes.view','mail.codes.copy'] as $p)requirePermission($u,$p);
    if(!mailLock('account-'.$id,3))fail(409,'邮箱正在授权或同步，请稍后重试');
    try{db()->beginTransaction();$ordered=array_values(array_unique($ids));sort($ordered);foreach($ordered as $uid){$target=query('SELECT * FROM users WHERE id=? AND active=1 AND deleted_at IS NULL FOR UPDATE',[$uid]);if(!$target||!canManageAccount($u,$target[0]))fail(404,'运营不在可管理范围');}query('SELECT id FROM mail_accounts WHERE id=? FOR UPDATE',[$id]);mailAccount($u,$id);
     $old=array_map('intval',array_column(query('SELECT user_id FROM mail_grants WHERE account_id=? FOR UPDATE',[$id]),'user_id'));foreach(array_diff($old,$ids) as $uid){if(in_array($uid,$allowed,true))query('DELETE FROM mail_grants WHERE account_id=? AND user_id=?',[$id,$uid]);}
     foreach($ordered as $uid){query('INSERT IGNORE INTO mail_grants(account_id,user_id) VALUES(?,?)',[$id,$uid]);foreach(['mail.view','mail.codes.view','mail.codes.copy'] as $p)query('INSERT IGNORE INTO user_permissions(user_id,permission) VALUES(?,?)',[$uid,$p]);}db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}finally{mailUnlock('account-'.$id);}
    audit($u,'mail_grants',$id,['object_type'=>'operation','object_name'=>$account['email'],'summary'=>'分配邮箱访问及收码权限']);return ['ok'=>true];
   }
  }
  if(!mailLock('account-'.$id,3))fail(409,'邮箱正在同步，请稍后重试');try{
   $account=mailAccount($u,$id);
   if($op==='sync'&&$method==='POST'){query('UPDATE mail_accounts SET next_sync=0 WHERE id=?',[$id]);return ['queued'=>true];}
   if($op==='disconnect'&&$method==='POST'){mailDisconnectRow($id);audit($u,'mail_disconnect',$id,['object_type'=>'operation','object_name'=>$account['email'],'summary'=>'断开 Outlook 邮箱']);return ['ok'=>true];}
   if($op===''&&$method==='PUT'){$email=isset($b['email'])?mailAddress(stringField($b,'email',254)):$account['email'];$label=stringField($b,'label',100,false);$notes=stringField($b,'notes',500,false);db()->beginTransaction();try{query('UPDATE mail_accounts SET email=?,label=?,notes=? WHERE id=?',[$email,$label,$notes,$id]);if($email!==$account['email'])mailDisconnectRow($id);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}audit($u,'mail_edit',$id,['object_type'=>'operation','object_name'=>$email,'summary'=>'编辑邮箱资料']);return ['ok'=>true];}
   if($op===''&&$method==='DELETE'){db()->beginTransaction();try{foreach(['mail_codes','mail_seen','mail_grants','mail_oauth'] as $table)query("DELETE FROM `$table` WHERE account_id=?",[$id]);query('DELETE FROM mail_accounts WHERE id=?',[$id]);audit($u,'mail_delete',$id,['object_type'=>'operation','object_name'=>$account['email'],'summary'=>'删除本站邮箱连接与已同步验证码']);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}return ['ok'=>true];}
  }finally{mailUnlock('account-'.$id);}
 }
 if($path==='/api/mail/codes'&&$method==='GET'){requirePermission($u,'mail.codes.view');[$limit,$offset]=listPage();$params=[];$filter='';if(isset($_GET['account_id'])&&$_GET['account_id']!==''){$id=filter_var($_GET['account_id'],FILTER_VALIDATE_INT);if(!$id)fail(422,'邮箱编号无效');mailAccount($u,(int)$id);$filter=' AND c.account_id=?';$params[]=(int)$id;}$where=mailScope($u,'a.id','a.created_by').$filter;header('X-Total-Count: '.query('SELECT COUNT(*) n FROM mail_codes c JOIN mail_accounts a ON a.id=c.account_id WHERE '.$where,$params)[0]['n']);return query('SELECT c.*,a.label FROM mail_codes c JOIN mail_accounts a ON a.id=c.account_id WHERE '.$where.' ORDER BY c.received_at DESC,c.id DESC LIMIT '.$limit.' OFFSET '.$offset,$params);}
 if(preg_match('#^/api/mail/codes/(\d+)(/copy)?$#D',$path,$m)){$copy=isset($m[2])&&$method==='POST';requirePermission($u,$copy?'mail.codes.copy':'mail.codes.delete');if($copy)requirePermission($u,'mail.codes.view');$rows=query('SELECT c.* FROM mail_codes c JOIN mail_accounts a ON a.id=c.account_id WHERE c.id=? AND '.mailScope($u,'a.id','a.created_by'),[(int)$m[1]]);if(!$rows)fail(404,'验证码不存在或未授权');if($copy){audit($u,'mail_copy',$m[1],['object_type'=>'operation','summary'=>'复制邮件验证码（不记录验证码）']);return ['code'=>$rows[0]['code']];}if($method==='DELETE'){query('DELETE FROM mail_codes WHERE id=?',[(int)$m[1]]);return ['ok'=>true];}}
 fail(404,'邮箱接口不存在');
}
require_once __DIR__.'/mail_transport.php';
require_once __DIR__.'/mail_oauth.php';
require_once __DIR__.'/mail_worker.php';
