<?php
declare(strict_types=1);
// Loaded BEFORE application code. Keep this entry point compatible across releases.
$updateGuardRoot=dirname(__DIR__);$updateGuardPath=$updateGuardRoot.'/config/.online-update.lock';
$updateGuardMaintenance=$updateGuardRoot.'/config/.online-update-maintenance.json';
$updateGuardStop=static function():never{http_response_code(503);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('Retry-After: 3');echo json_encode(['updating'=>true,'detail'=>'系统正在升级或等待恢复，请稍后刷新；持续未恢复时请按升级教程检查任务。'],JSON_UNESCAPED_UNICODE);exit;};
if(is_link($updateGuardPath)||is_link($updateGuardMaintenance))$updateGuardStop();
$updateGuardHandle=@fopen($updateGuardPath,is_file($updateGuardPath)?'re':'ce');
if(!$updateGuardHandle)$updateGuardStop();
@chmod($updateGuardPath,0600);
if(!flock($updateGuardHandle,LOCK_SH|LOCK_NB))$updateGuardStop();
$GLOBALS['_update_guard_lock']=$updateGuardHandle;
if(is_file($updateGuardMaintenance))$updateGuardStop();
$updateInvalidate=$updateGuardRoot.'/config/.online-update-invalidate.json';
if(is_file($updateInvalidate)&&!is_link($updateInvalidate)){
    // One FPM request invalidates shared opcache after the CLI has finished replacing files.
    $updateInvalidateHandle=@fopen($updateInvalidate,'r+');
    if($updateInvalidateHandle&&flock($updateInvalidateHandle,LOCK_EX|LOCK_NB)){
        $updateInvalidateData=json_decode(stream_get_contents($updateInvalidateHandle),true);
        if(function_exists('opcache_invalidate')&&is_array($updateInvalidateData))foreach($updateInvalidateData as $updateRelative){
            if(is_string($updateRelative)&&!str_contains($updateRelative,'..')&&!str_contains($updateRelative,'\\')&&preg_match('#^(src|public|bin)/#D',$updateRelative))@opcache_invalidate($updateGuardRoot.'/'.$updateRelative,true);
        }
        @unlink($updateInvalidate);flock($updateInvalidateHandle,LOCK_UN);
    }
    if($updateInvalidateHandle)fclose($updateInvalidateHandle);
}
