<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
function verify(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function invalid(callable $fn):void{try{$fn();}catch(ApiError $e){verify($e->status===422,'invalid status');return;}throw new RuntimeException('invalid input accepted');}
$now=1700000000.0;
$base=['id'=>1,'name'=>'测试手机','revoked'=>0,'heartbeat'=>$now-10,'subscriptions'=>'[]','telemetry'=>['observed_at'=>$now-10,'fresh'=>true,'data'=>normalizeTelemetry(['app_version'=>'0.3.0','permissions'=>['phone'=>true,'sms'=>true]]),'events'=>[]]];
verify(count(buildAlerts([$base],[],$now))===0,'unknown telemetry fabricated alarm');
$d=$base;$d['telemetry']['data']['battery_percent']=0;$d['telemetry']['data']['charging']=false;
verify(buildAlerts([$d],[],$now)[0]['severity']==='critical','zero battery treated as unknown');
$d['telemetry']['fresh']=false;$a=buildAlerts([$d],[],$now);verify(count($a)===1&&str_starts_with($a[0]['key'],'stale:'),'stale battery should not be realtime');
$d['heartbeat']=$now-181;$a=buildAlerts([$d],[],$now);verify(count($a)===1&&str_starts_with($a[0]['key'],'offline:'),'offline should supersede stale snapshot');
$d=$base;$d['telemetry']['data']['battery_percent']=10;$d['telemetry']['data']['charging']=true;verify(count(buildAlerts([$d],[],$now))===0,'charging low battery noisy');
$d=$base;$d['subscriptions']=json_encode([['slot'=>1,'subscription_id'=>7,'service_state'=>'unknown','signal_level'=>null,'roaming'=>true]]);verify(count(buildAlerts([$d],[],$now))===0,'unknown signal or normal roaming alerted');
$d['subscriptions']=json_encode([['slot'=>1,'subscription_id'=>7,'service_state'=>'in_service','signal_level'=>0,'signal_age_seconds'=>999]]);verify(count(buildAlerts([$d],[],$now))===0,'old signal cached as current');
$d=$base;$d['subscriptions']=json_encode([['slot'=>1,'subscription_id'=>7,'service_state'=>'in_service','signal_level'=>0,'signal_age_seconds'=>null]]);verify(count(buildAlerts([$d],[],$now))===0,'unknown signal age assumed fresh');
$d=$base;$d['telemetry']['data']['pending_count']=2;$d['telemetry']['data']['oldest_pending_age_seconds']=20;verify(count(buildAlerts([$d],[],$now))===0,'new queue incorrectly alarming');
$d['telemetry']['data']['oldest_pending_age_seconds']=121;verify(count(buildAlerts([$d],[],$now))===1,'old backlog not detected');
$d=$base;$d['revoked']=1;$d['heartbeat']=$now-1000;verify(count(buildAlerts([$d],[],$now))===0,'revoked device should not alarm');
invalid(fn()=>normalizeTelemetry(['battery_percent'=>101]));invalid(fn()=>normalizeTelemetry(['charging'=>'false']));invalid(fn()=>normalizeTelemetry(['pending_count'=>-1]));
invalid(fn()=>normalizeSubscriptions([['subscription_id'=>7],['subscription_id'=>7]]));
verify(normalizeTelemetry(['battery_percent'=>0])['battery_percent']===0,'normalization lost zero');
verify(normalizeTelemetry([])['battery_percent']===null,'missing value defaulted to zero');
$d=$base;$d['telemetry']['data']['quarantined_count']=2;verify(count(buildAlerts([$d],[],$now))===1,'isolated messages must be visible in alerts');
verify(normalizeTelemetry(['quarantined_count'=>2])['quarantined_count']===2,'quarantine count was dropped');
invalid(fn()=>normalizeTelemetry(['quarantined_count'=>-1]));
echo "PASS: telemetry validation, stale/unknown safety, alert priority and noise control\n";
