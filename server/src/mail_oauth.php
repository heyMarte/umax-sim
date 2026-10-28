<?php
declare(strict_types=1);
function mailScopes():string{return 'offline_access https://graph.microsoft.com/User.Read https://graph.microsoft.com/Mail.Read';}
function mailStart(array $u,array $account):array {
 $c=mailConfig();if(!mailReady($c))fail(422,'请管理员先配置微软应用和本站回调地址');
 $state=bin2hex(random_bytes(32));$verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
 query("UPDATE mail_oauth SET status='expired' WHERE account_id=? AND initiator_id=? AND status='waiting'",[$account['id'],$u['id']]);
 query('INSERT INTO mail_oauth(state,account_id,initiator_id,session_context,revision,generation,verifier_cipher,expires_at) VALUES(?,?,?,?,?,?,?,?)',[$state,$account['id'],$u['id'],$u['access_context'],$c['revision'],$account['generation'],sealSecret($verifier),microtime(true)+600]);
 $auth='https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize?'.http_build_query(['client_id'=>$c['client_id'],'response_type'=>'code','redirect_uri'=>$c['origin'].'/oauth/outlook/callback','response_mode'=>'query','scope'=>mailScopes(),'state'=>$state,'code_challenge'=>$challenge,'code_challenge_method'=>'S256','login_hint'=>$account['email'],'prompt'=>'select_account'],'','&',PHP_QUERY_RFC3986);
 audit($u,'mail_connect_start',$account['id'],['object_type'=>'operation','object_name'=>$account['email'],'summary'=>'发起 Outlook 邮箱授权']);return ['state'=>$state,'auth_url'=>$auth,'expires_at'=>microtime(true)+600];
}
function mailActor(array $p):array {
 if(db()->inTransaction()){query('SELECT id FROM users WHERE id=? FOR UPDATE',[$p['initiator_id']]);query('SELECT token FROM sessions WHERE user_id=? AND expires>? AND SHA2(csrf,256)=? FOR UPDATE',[$p['initiator_id'],microtime(true),$p['session_context']]);}
 $r=query('SELECT u.* FROM users u WHERE u.id=? AND u.active=1 AND u.deleted_at IS NULL AND EXISTS(SELECT 1 FROM sessions s WHERE s.user_id=u.id AND s.expires>? AND SHA2(s.csrf,256)=?)',[$p['initiator_id'],microtime(true),$p['session_context']]);if(!$r)fail(403,'发起授权的登录已失效，请重新登录后连接');$u=loadUserAccess($r[0]);requirePermission($u,'mail.connect');mailAccount($u,(int)$p['account_id']);return $u;
}
function mailComplete(array $query,?callable $transport=null):array {
 $state=$query['state']??'';$code=$query['code']??'';if(!is_string($state)||!preg_match('/^[a-f0-9]{64}$/D',$state)||!is_string($code)||strlen($code)>8192)fail(422,'微软授权链接无效');
 $p=query('SELECT * FROM mail_oauth WHERE state=?',[$state])[0]??null;if(!$p)fail(404,'授权请求不存在');if($p['status']!=='waiting'||(float)$p['expires_at']<microtime(true))fail(409,'授权链接已使用或过期，请重新连接');
 $id=(int)$p['account_id'];if(!mailLock('account-'.$id,5))fail(409,'邮箱正在同步，请稍后重新连接');
 try{
  db()->beginTransaction();$live=query('SELECT * FROM mail_oauth WHERE state=? FOR UPDATE',[$state])[0]??null;if(!$live||$live['status']!=='waiting'||(float)$live['expires_at']<microtime(true))fail(409,'授权链接已使用或过期');$p=$live;$u=mailActor($p);$a=mailAccount($u,$id);$c=mailConfig();if((int)$a['generation']!==(int)$p['generation']||(int)$c['revision']!==(int)$p['revision'])fail(409,'邮箱或应用配置已变化，请重新连接');query("UPDATE mail_oauth SET status='exchanging' WHERE state=?",[$state]);db()->commit();
  if(isset($query['error'])||$code==='')fail(422,'微软授权未完成，请重新连接并同意读取邮件');
  $tokens=mailTokenFields(mailHttp('token',['client_id'=>$c['client_id'],'client_secret'=>openSecret($c['client_secret_cipher']),'grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$c['origin'].'/oauth/outlook/callback','code_verifier'=>openSecret($p['verifier_cipher']),'scope'=>mailScopes()],$transport),true);
  $profile=mailHttp('me',['access_token'=>$tokens['access']],$transport);$addresses=array_filter([$profile['mail']??null,$profile['userPrincipalName']??null],fn($x)=>is_string($x));if(!in_array(strtolower($a['email']),array_map('strtolower',$addresses),true))fail(422,'登录的微软邮箱与所选邮箱不一致，请重新选择正确的邮箱');$provider=$profile['id']??null;if(!is_string($provider)||$provider===''||strlen($provider)>200)throw new MailRemoteError(502);
  db()->beginTransaction();$current=query('SELECT * FROM mail_config WHERE id=1 FOR UPDATE')[0];$u=mailActor($p);query('SELECT id FROM mail_accounts WHERE id=? FOR UPDATE',[$id]);$a=mailAccount($u,$id);$pending=query('SELECT status FROM mail_oauth WHERE state=? FOR UPDATE',[$state])[0]??null;if(!$pending||$pending['status']!=='exchanging'||(int)$a['generation']!==(int)$p['generation']||(int)$current['revision']!==(int)$p['revision'])fail(409,'邮箱或应用配置已变化，请重新连接');
  $resume=$a['connected_at']!==null&&$a['client_id']===$c['client_id']&&$a['provider_id']===$provider;
  query("UPDATE mail_accounts SET client_id=?,provider_id=?,refresh_cipher=?,access_cipher=?,access_until=?,connected_at=?,last_sync=?,next_sync=0,sync_cursor=?,status='connected',generation=generation+1,error_code=NULL WHERE id=?",[$c['client_id'],$provider,sealSecret($tokens['refresh']),sealSecret($tokens['access']),$tokens['until'],$resume?$a['connected_at']:microtime(true),$resume?$a['last_sync']:null,$resume?$a['sync_cursor']:null,$id]);query("UPDATE mail_oauth SET status='complete',verifier_cipher='' WHERE state=?",[$state]);audit($u,'mail_connected',$id,['object_type'=>'operation','object_name'=>$a['email'],'summary'=>'Outlook 邮箱授权连接成功']);db()->commit();return ['email'=>$a['email'],'status'=>'connected'];
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$reason=$e instanceof MailRemoteError?mailRemoteReason($e):($e instanceof ApiError&&in_array($e->status,[403,404],true)?'session_changed':'wrong_account');query("UPDATE mail_oauth SET status='failed',error_code=?,verifier_cipher='' WHERE state=? AND status IN ('waiting','exchanging')",[$reason,$state]);if($e instanceof ApiError)throw $e;fail(502,mailErrorLabel($reason));}finally{mailUnlock('account-'.$id);}
}
function mailPublicRoute(string $method,string $path):bool {
 if($path!=='/oauth/outlook/callback')return false;if($method!=='GET')fail(405,'授权回调方式无效');header('Content-Type: text/html; charset=utf-8');header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');$ok=false;$origin='';
 try{$origin=mailConfig()['origin'];$r=mailComplete($_GET);$message='邮箱 '.$r['email'].' 已连接。服务器将自动收取新的验证码邮件。';$ok=true;}catch(ApiError $e){$message=$e->getMessage();http_response_code($e->status);}catch(Throwable){$message='授权暂时未完成，请返回后台重新连接。';http_response_code(502);}
 echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Outlook 邮箱授权</title><body><main><h1>'.($ok?'Outlook 已连接':'Outlook 未连接').'</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p><a href="'.htmlspecialchars($origin.'/#mail',ENT_QUOTES,'UTF-8').'">返回邮箱管理</a></main></body></html>';return true;
}
