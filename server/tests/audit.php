<?php
declare(strict_types=1);
require __DIR__.'/../src/audit.php';
function check(bool $b,string $message):void {if(!$b)throw new RuntimeException($message);}
function requirePermission(array $u,string $p):void {check($p==='audit.view','wrong permission');}
function can(array $u,string $p):bool{return in_array($p,$u['permissions']??[],true);}
function globalScope(array $u):bool{return (bool)($u['all_shops']??false);}
function scopeSql(array $u,string $column):string{return $column.' IN (3,7)';}
function listPage():array{return [50,0];}
function query(string $sql,array $args=[]):array{$GLOBALS['queries'][]=[$sql,$args];return str_contains($sql,'COUNT(*)')?[['n'=>0]]:[];}
$changes=auditSafeChanges([['field'=>'password','before'=>'secret','after'=>'secret2'],['field'=>'body','after'=>'private SMS'],['field'=>'token','after'=>'secret'],['field'=>'amount','before'=>3,'after'=>4],['field'=>'permissions','before'=>[],'after'=>['sims.view']]]);
check(count($changes)===2,'secret fields escaped allowlist');
auditRemember('user',1,['username'=>'first','password'=>'secret']);auditRemember('user',1,['username'=>'second']);
check($GLOBALS['_audit_before']['user:1']===['username'=>'first'],'snapshot must preserve first safe state');
$_GET=[];scopedAuditRows(['id'=>8,'role'=>'operator','permissions'=>['audit.view']]);
$sql=$GLOBALS['queries'][0][0];
check(str_contains($sql,'NOT EXISTS (SELECT 1 FROM audit_scope ax'),'missing all-store exclusion');
check(str_contains($sql,'a.actor_id IS NOT NULL AND a.is_detailed=1'),'legacy rows leaked');
check(str_contains($sql,'a.shop_id IS NULL AND a.actor_id=?'),'actor bypass must apply only unscoped logs');
$GLOBALS['queries']=[];scopedAuditRows(['id'=>8,'role'=>'operator','permissions'=>['audit.view','audit.all']]);
check(str_contains($GLOBALS['queries'][0][0],'NOT EXISTS'),'scoped audit.all escaped store boundaries');
$GLOBALS['queries']=[];scopedAuditRows(['id'=>8,'role'=>'operator','all_shops'=>true,'permissions'=>['audit.view','audit.all']]);
check(!str_contains($GLOBALS['queries'][0][0],'NOT EXISTS'),'explicit global audit.all not honored');
echo "audit safety assertions passed\n";
