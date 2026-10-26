<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
if(!function_exists('deviceAuthLimitCheck'))throw new RuntimeException('Missing bounded device authentication');
$_SERVER['REMOTE_ADDR']='qa055-'.bin2hex(random_bytes(5));$_SERVER['HTTP_X_DEVICE_ID']='991234';
$bucket=deviceAuthLimitBucket();
try{
 for($i=0;$i<12;$i++){deviceAuthLimitCheck();deviceAuthLimitFailure();}
 try{deviceAuthLimitCheck();throw new RuntimeException('Unlimited guessing');}catch(ApiError $e){if($e->status!==429)throw $e;}
 $_SERVER['HTTP_X_DEVICE_ID']='000991234';try{deviceAuthLimitCheck();throw new RuntimeException('Leading zeros bypassed the same-device limit');}catch(ApiError $e){if($e->status!==429)throw $e;}
 $_SERVER['HTTP_X_DEVICE_ID']='991235';deviceAuthLimitCheck(); // One mistyped phone must not block another phone on the same network.
 $_SERVER['HTTP_X_DEVICE_ID']='991234';query('UPDATE attempts SET since=? WHERE ip=?',[microtime(true)-61,$bucket]);deviceAuthLimitCheck();
 deviceAuthLimitSuccess();if(query('SELECT ip FROM attempts WHERE ip=?',[$bucket]))throw new RuntimeException('Counter not reset');
 unset($_SERVER['HTTP_X_DEVICE_ID']);$anonymous=deviceAuthLimitBucket();if($anonymous===$bucket)throw new RuntimeException('Missing scoped bucket');
 echo "PASS bounded failed device auth, separate devices on shared network, recovery window and successful reset\n";
}finally{query('DELETE FROM attempts WHERE ip IN (?,?,?)',[$bucket,$anonymous??'',deviceAuthIpBucket()]);}
