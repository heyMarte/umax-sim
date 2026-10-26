<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/secrets.php';
require_once dirname(__DIR__).'/src/wechat.php';
function wxAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
wxAssert(str_contains(settings()['db_name'],'test'),'isolated DB required');wxMigrate();$saved=wxConfig();$uid=null;$calls=0;
$fake=function(string $endpoint,array $p)use(&$calls):array{wxAssert($endpoint==='oauth','callback only exchanges OAuth code');$calls++;return ['openid'=>'oauth-fixture','scope'=>'snsapi_base'];};
function wxPage(string $path,array $args,callable $transport):string{$_GET=$args;ob_start();try{wxAssert(wxPublicRoute('GET',$path,$transport),'public route consumed');return ob_get_contents();}finally{ob_end_clean();}}
try{
 $uid=insert("INSERT INTO users(username,password,role) VALUES(?,?,'admin')",['wx-oauth-'.bin2hex(random_bytes(6)),'unused']);$u=loadUserAccess(query('SELECT * FROM users WHERE id=?',[$uid])[0]);
 query('UPDATE wx_config SET enabled=0,app_id=?,secret_cipher=?,revision=revision+1,token_cipher=NULL,token_until=NULL WHERE id=1',['wx3333333333333333',sealSecret('OAUTH_FAKE_SECRET')]);
 $p=wxRoute($u,'POST','/api/wechat/binding',[],$fake);wxPage('/wx/bind',['t'=>$p['token']],$fake);$row=query('SELECT * FROM wx_pending WHERE token=?',[$p['token']])[0];wxAssert($row['status']==='authorizing'&&strlen($row['state'])===64,'opaque state distinct from browser owner token');
 wxAssert(!wxBinding($u,wxConfig()),'visiting QR does not bind');
 $html=wxPage('/wx/callback',['state'=>$row['state'],'code'=>'FAKE_OAUTH_CODE'],$fake);wxAssert(str_contains($html,wxHint('oauth-fixture')),'phone displays identity fingerprint for backend comparison');wxAssert(!str_contains($html,'OAUTH_FAKE_SECRET')&&!str_contains($html,'FAKE_OAUTH_CODE'),'public HTML secret/code redacted');
 wxAssert(query('SELECT status FROM wx_pending WHERE token=?',[$p['token']])[0]['status']==='pending','callback only creates pending confirmation');wxAssert(!wxBinding($u,wxConfig()),'OAuth alone does not bind');
 wxPage('/wx/callback',['state'=>$row['state'],'code'=>'FAKE_OAUTH_CODE'],$fake);wxAssert($calls===1,'state replay never calls OAuth again');
 $p=wxRoute($u,'POST','/api/wechat/binding',[],$fake);query('UPDATE wx_pending SET expires_at=0 WHERE token=?',[$p['token']]);wxPage('/wx/bind',['t'=>$p['token']],$fake);wxAssert(query('SELECT state FROM wx_pending WHERE token=?',[$p['token']])[0]['state']===null,'expired link never starts OAuth');
 $p=wxRoute($u,'POST','/api/wechat/binding',[],$fake);wxPage('/wx/bind',['t'=>$p['token']],$fake);$state=query('SELECT state FROM wx_pending WHERE token=?',[$p['token']])[0]['state'];query('UPDATE wx_config SET revision=revision+1 WHERE id=1');wxPage('/wx/callback',['state'=>$state,'code'=>'FAKE_CODE'],$fake);wxAssert($calls===1,'config revision mismatch rejects OAuth before exchange');
 $cfg=settings();$other=new PDO('mysql:host='.$cfg['db_host'].';port='.(int)$cfg['db_port'].';dbname='.$cfg['db_name'].';charset=utf8mb4',$cfg['db_user'],$cfg['db_password']);$lock='sim-wx-'.substr(hash('sha256',$cfg['db_name']),0,12).'-worker';$stmt=$other->prepare('SELECT GET_LOCK(?,0)');$stmt->execute([$lock]);wxAssert((int)$stmt->fetchColumn()===1,'external worker lock held');$r=wxRun(5,$fake);wxAssert($r['busy']&&$r['processed']===0,'second worker never sends concurrently');$stmt=$other->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([$lock]);
}finally{if(db()->inTransaction())db()->rollBack();if($uid!==null){query('DELETE FROM wx_pending WHERE user_id=?',[$uid]);query('DELETE FROM audit_scope WHERE audit_id IN (SELECT id FROM audit WHERE actor_id=?)',[$uid]);query('DELETE FROM audit WHERE actor_id=?',[$uid]);query('DELETE FROM users WHERE id=?',[$uid]);}$keys=array_keys($saved);query('UPDATE wx_config SET '.implode(',',array_map(fn($k)=>"$k=?",$keys)).' WHERE id=1',array_values($saved));}
echo "PASS WeChat OAuth: pending only, identity comparison, one-time state, expiry, config isolation, external worker lock\n";
