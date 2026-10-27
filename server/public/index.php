<?php
declare(strict_types=1);
ini_set('display_errors','0');
require __DIR__.'/update-guard.php';
require_once dirname(__DIR__).'/src/api.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; frame-ancestors 'none'");
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';
try{if(wxPublicRoute($_SERVER['REQUEST_METHOD'],$path))exit;}catch(ApiError $e){http_response_code($e->status);header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>微信绑定</title><body><h1>暂时无法完成绑定</h1><p>'.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8').'</p><p>请返回管理后台，重新生成绑定二维码。</p></body></html>';exit;}
if($path==='/') { header('Content-Type: text/html; charset=utf-8');readfile(__DIR__.'/static/index.html');exit; }
header('Content-Type: application/json; charset=utf-8');
try {
    if($_SERVER['REQUEST_METHOD']==='GET'&&$path==='/api/device/app-update/download'){require_once dirname(__DIR__).'/src/online_updates.php';onlineDownload();}
    if($_SERVER['REQUEST_METHOD']==='POST'&&$path==='/api/admin/updates/upload'){require_once dirname(__DIR__).'/src/online_updates.php';$updateUser=user(true);onlineAdmin($updateUser);header('X-Access-Context: '.$updateUser['access_context']);$file=$_FILES['package']??null;if(!is_array($file))fail(422,'请选择专用 ZIP 更新包；如文件过大请检查宝塔上传限制');echo json_encode(onlineUpload($updateUser,$file),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
    if($_SERVER['REQUEST_METHOD']==='POST'&&$path==='/api/admin/updates/upload-app'){require_once dirname(__DIR__).'/src/online_updates.php';require_once dirname(__DIR__).'/src/app_release.php';$appUser=user(true);onlineAdmin($appUser);header('X-Access-Context: '.$appUser['access_context']);$file=$_FILES['apk']??null;if(!is_array($file))fail(422,'请选择 APK 文件；如文件过大请检查上传限制');$notes=$_POST['notes']??'';if(!is_string($notes))fail(422,'更新说明格式错误');echo json_encode(appUpload($appUser,$file,$notes),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
    if($_SERVER['REQUEST_METHOD']==='GET'&&preg_match('#^/download/app/([a-f0-9]{64})(/apk)?$#D',$path,$downloadMatch)){require_once dirname(__DIR__).'/src/online_updates.php';require_once dirname(__DIR__).'/src/app_release.php';appPublicDownload($downloadMatch[1],isset($downloadMatch[2]));}
    if($_SERVER['REQUEST_METHOD']==='GET'&&in_array($path,['/a','/a/','/app','/app/','/a.apk','/app.apk'],true)){require_once dirname(__DIR__).'/src/online_updates.php';require_once dirname(__DIR__).'/src/app_release.php';appPublicDownload(null,str_ends_with($path,'.apk'));}
    $data=[];
    if((int)($_SERVER['CONTENT_LENGTH']??0)>2000000)fail(413,'请求过大');
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)) {
        if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))fail(415,'请使用 application/json');
        $stream=fopen('php://input','rb');$raw=stream_get_contents($stream,2000001);fclose($stream);
        if(strlen($raw)>2000000)fail(413,'请求过大');
        if($raw!=='') {
            $object=json_decode($raw,false,32,JSON_THROW_ON_ERROR);
            if(!is_object($object))fail(422,'请求必须为 JSON 对象');
            $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        }
    }
    $result=route($_SERVER['REQUEST_METHOD'],$path,$data);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(ApiError $e) {
    http_response_code($e->status);echo json_encode(['detail'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
} catch(JsonException $e) {
    http_response_code(422);echo json_encode(['detail'=>'JSON 格式错误'],JSON_UNESCAPED_UNICODE);
} catch(PDOException $e) {
    $duplicate=($e->errorInfo[1]??null)===1062;
    http_response_code($duplicate?409:503);
    // 不输出数据库配置、SQL、正文或令牌。
    echo json_encode(['detail'=>$duplicate?duplicateWriteMessage($path):'数据库不可用，请检查配置、导入表结构及数据库服务'],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
    http_response_code(500);echo json_encode(['detail'=>'服务器处理失败，请检查 PHP 环境和配置'],JSON_UNESCAPED_UNICODE);
}
