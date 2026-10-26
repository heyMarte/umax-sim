<?php
declare(strict_types=1);
require_once __DIR__.'/apk_metadata.php';
function appShareToken():string {
    $lock=fopen(onlineDir().'/app-share.lock','ce');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('无法读取分享链接');
    try{$file=onlineDir().'/app-share.json';$token=onlineJson($file)['token']??null;if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)){$token=bin2hex(random_bytes(32));onlineSave($file,['token'=>$token]);}return $token;}finally{flock($lock,LOCK_UN);fclose($lock);}
}

function appSharePath():?string{return onlineJson(onlineDir().'/app.json')?'/a':null;}
function appStagedSummary(array $a):array{return array_intersect_key($a,array_flip(['id','version_code','version_name','package_name','size','notes']));}
function appUpload(array $u,array $file,string $notes):array {
    onlineAdmin($u);$gate=onlineGate();try{onlineBusy();if(($file['error']??-1)!==UPLOAD_ERR_OK)fail(422,'APK 上传失败，请检查宝塔上传大小限制');if(!isset($file['tmp_name'])||!is_uploaded_file($file['tmp_name'])||($file['size']??0)<1||$file['size']>ONLINE_MAX_BYTES)fail(422,'请选择不超过64MB的 APK 文件');if(mb_strlen($notes)>5000)fail(422,'更新说明过长');
        $meta=appApkMetadata($file['tmp_name']);$current=onlineJson(onlineDir().'/app.json');if($current&&$meta['version_code']<$current['version_code'])fail(422,'APK 版本号低于已发布版本，不能降级');
        $id=bin2hex(random_bytes(16));$dir=onlineJobDir($id);mkdir($dir.'/files/app',0700,true);$dest=$dir.'/files/app/collector.apk';if(!move_uploaded_file($file['tmp_name'],$dest))fail(503,'无法保存 APK，请检查目录权限和磁盘');chmod($dest,0600);
        $a=$meta+['id'=>$id,'size'=>filesize($dest),'sha256'=>hash_file('sha256',$dest),'notes'=>$notes];onlineSave($dir.'/app-release.json',$a);onlineSave(onlineDir().'/app-staged.json',$a);audit($u,'stage_app_update',$id,['object_type'=>'operation','object_name'=>'App '.$a['version_name'],'summary'=>'独立上传并识别 APK '.$a['version_name'].'（'.$a['version_code'].'）']);return ['app_staged'=>appStagedSummary($a)];
    }finally{flock($gate,LOCK_UN);fclose($gate);}
}
/** Caller owns onlineGate and has checked admin + no active system job. */
function appPublishFile(array $u,string $id,array $app,string $file,string $notes):array {
    if(!is_file($file)||!hash_equals($app['sha256'],hash_file('sha256',$file)))fail(422,'APK 文件已变化，请重新上传');
    $actual=appApkMetadata($file);if($actual['version_code']!==$app['version_code']||$actual['version_name']!==$app['version_name'])fail(422,'APK 实际版本与发布信息不一致');
    $current=onlineJson(onlineDir().'/app.json');if($current&&$current['version_code']>$app['version_code'])fail(422,'不允许发布旧 App 版本');if($current&&$current['version_code']===$app['version_code']&&!hash_equals($current['sha256'],$app['sha256']))fail(422,'相同版本号对应不同 APK，请增加版本号后重新发布');
    $dest=onlineDir().'/app-'.$app['sha256'].'.apk';if(!is_file($dest)||!hash_equals($app['sha256'],hash_file('sha256',$dest)))onlineWrite($dest,file_get_contents($file));
    $published=['version_code'=>$app['version_code'],'version_name'=>$app['version_name'],'package_name'=>$actual['package_name'],'notes'=>$notes,'sha256'=>$app['sha256'],'size'=>$app['size'],'published_at'=>microtime(true)];onlineSave(onlineDir().'/app.json',$published);appShareToken();$path=appSharePath();audit($u,'publish_app_update',$id,['object_type'=>'operation','object_name'=>'App '.$app['version_name'],'summary'=>'发布 App '.$app['version_name'].'（'.$app['version_code'].'），提供自动更新和分享下载']);return ['ok'=>true,'published_app'=>$published,'share_path'=>$path];
}
function appPublishStaged(array $u,string $id):array {
    onlineAdmin($u);$gate=onlineGate();try{onlineBusy();$dir=onlineJobDir($id);$a=onlineJson($dir.'/app-release.json');if(!$a)fail(404,'未找到待发布 APK，请先上传');return appPublishFile($u,$id,$a,$dir.'/files/app/collector.apk',$a['notes']);}finally{flock($gate,LOCK_UN);fclose($gate);}
}
function appPublicDownload(?string $token,bool $binary):never {
    if($token!==null){$stored=onlineJson(onlineDir().'/app-share.json')['token']??'';if(!is_string($stored)||!preg_match('/^[a-f0-9]{64}$/D',$token)||!hash_equals($stored,$token))fail(404,'下载链接不存在');}
    $app=onlineJson(onlineDir().'/app.json');if(!$app)fail(404,'尚未发布 App');
    if($binary){onlineStreamApk($app);}
    $escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    header('Content-Type: text/html; charset=utf-8');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');$version=$escape($app['version_name']);$size=number_format($app['size']/1048576,1);$notes=$escape($app['notes']?:'Umax SIM 工作台更新');$href='/a.apk';
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>下载 Umax SIM 工作台</title><link rel="icon" href="/static/umax-logo.png"><link rel="stylesheet" href="/static/download.css?v=053"><script defer src="/static/vendor/qrcodegen-1.8.0.js"></script><script defer src="/static/download-qr.js?v=053"></script></head><body><main><img class="logo" src="/static/umax-logo.png" alt="Umax"><h1>Umax SIM 工作台</h1><p class="meta">Android · v'.$version.' · '.$size.' MB</p><a class="download" href="'.$href.'">下载安卓安装包</a><section class="download-qr-section"><h2>扫码下载</h2><div id="download-qr" class="download-qr" aria-busy="false"></div><p id="download-qr-status" class="hint">正在生成二维码…</p><a id="download-share-url" class="share-link" href="/a">/a</a><button id="copy-download-link" type="button" class="copy-link">复制下载链接</button><noscript><p class="hint">开启浏览器 JavaScript 后可显示二维码，也可直接点击上方下载按钮。</p></noscript></section><section><h2>更新说明</h2><p class="notes">'.$notes.'</p></section><p class="hint">下载后打开 APK，选择更新或覆盖安装。已有用户不要卸载，以保留本机配置与待上传短信。首次安装后请向管理员获取设备连接配置。</p><p class="hint">如在微信等应用内无法下载，请用手机浏览器打开此页面。</p></main></body></html>';exit;
}
