<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
function reviewCheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);}
function reviewConflict(callable $fn,string $label):void {try{$fn();}catch(ApiError $e){reviewCheck($e->status===409,$label.' must return 409');return;}throw new RuntimeException($label.' accepted stale/incomplete original binding');}
if(($argv[1]??'all')!=='binding'){
$now=(float)(new DateTimeImmutable('2026-10-24T01:00:00+08:00'))->getTimestamp();
$sim=['id'=>900001,'balance'=>null,'threshold'=>20,'next_check'=>'2026-10-24','package_expires'=>'2026-10-23'];
reviewCheck(!alertConditionRecovered('check_due:0:900001:info',[],[$sim],$now),'Shanghai same-day due reminder prematurely recovered');
reviewCheck(!alertConditionRecovered('package_due:0:900001:info',[],[$sim],$now),'Shanghai expired package reminder prematurely recovered');
$sim['next_check']='2026-10-25';$sim['package_expires']='2026-10-24';
reviewCheck(alertConditionRecovered('check_due:0:900001:info',[],[$sim],$now),'tomorrow check should recover');
reviewCheck(alertConditionRecovered('package_due:0:900001:info',[],[$sim],$now),'today package should recover');
echo "PASS Shanghai midnight date recovery boundaries\n";
}
// Isolated transaction: original binding A -> B -> A must still reject stale A version.
db()->beginTransaction();try{
 $tag='review053-'.bin2hex(random_bytes(6));$d=insert('INSERT INTO devices(name,token) VALUES(?,?)',[$tag,hash('sha256',$tag)]);
 $s=insert('INSERT INTO sims(phone,carrier,location,shop) VALUES(?,?,?,?)',['+639'.random_int(100000000,999999999),'Smart','','']);
 $first=str_repeat('a',48);$second=str_repeat('b',48);query('INSERT INTO bindings(device_id,subscription_id,sim_id,version) VALUES(?,?,?,?)',[$d,7,$s,$first]);
 $u=['role'=>'admin','all_shops'=>true,'shop_ids'=>[]];$tuple=['replace_binding'=>true,'expected_device_id'=>$d,'expected_subscription_id'=>7,'expected_binding_version'=>$first];
 foreach(array_keys($tuple) as $key){if($key==='replace_binding')continue;$partial=$tuple;unset($partial[$key]);reviewConflict(fn()=>assertBindingSource($u,$s,$partial),'missing '.$key);}
 reviewCheck(assertBindingSource($u,$s,$tuple)['version']===$first,'complete matching original tuple');
 query('UPDATE bindings SET subscription_id=9,version=? WHERE sim_id=?',[$second,$s]);query('UPDATE bindings SET subscription_id=7,version=? WHERE sim_id=?',[str_repeat('c',48),$s]);
 reviewConflict(fn()=>assertBindingSource($u,$s,$tuple),'same device/subscription ABA with stale original version');
 $tuple['expected_binding_version']=str_repeat('c',48);reviewCheck(assertBindingSource($u,$s,$tuple)['version']===str_repeat('c',48),'current ABA tuple should succeed');
 echo "PASS complete source tuple required and original version prevents same-device/subscription ABA\n";
}finally{db()->rollBack();}
