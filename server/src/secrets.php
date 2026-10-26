<?php
declare(strict_types=1);
function keyringPath(): string { return getenv('SIM_KEYRING') ?: dirname(__DIR__).'/config/keyring.php'; }
function keyringInit(): void {
    $path=keyringPath();
    if(is_file($path)){masterKey();return;}
    if(query('SELECT id FROM devices WHERE secret_cipher IS NOT NULL LIMIT 1') || (query("SHOW TABLES LIKE 'wx_config'") && query('SELECT id FROM wx_config WHERE secret_cipher IS NOT NULL OR token_cipher IS NOT NULL LIMIT 1')))fail(503,'数据库已有加密密钥但keyring.php缺失，请恢复原文件，不要重新生成');
    $content="<?php\nreturn ['master_key' => '".base64_encode(random_bytes(32))."'];\n";
    $oldMask=umask(0077);$h=@fopen($path,'x');umask($oldMask);if(!$h)fail(503,'无法创建密钥文件，请检查config目录权限');
    try{if(fwrite($h,$content)!==strlen($content))fail(503,'密钥文件未完整写入，请检查磁盘空间');}finally{fclose($h);}
    if(function_exists('chmod'))chmod($path,0640);
    $config=getenv('SIM_CONFIG')?:dirname(__DIR__).'/config/config.php';
    if(is_file($config)){if(function_exists('chown'))@chown($path,fileowner($config));if(function_exists('chgrp'))@chgrp($path,filegroup($config));}
}
function masterKey(): string {
    $path=keyringPath();if(!is_file($path))fail(503,'密钥保管尚未初始化，请先执行升级命令 migrate');if(!is_readable($path))fail(503,'密钥文件无法读取，请检查keyring.php所有者及权限');
    $ring=require $path;$key=is_array($ring)?base64_decode($ring['master_key']??'',true):false;
    if($key===false||strlen($key)!==32)fail(503,'密钥文件无效，请从备份恢复，勿覆盖原文件');
    return $key;
}
function sealSecret(string $value): string {
    if(!function_exists('openssl_encrypt'))fail(503,'PHP缺少OpenSSL支持');
    $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',masterKey(),OPENSSL_RAW_DATA,$iv,$tag,'sim-device-key',16);
    if($cipher===false)fail(503,'密钥加密失败');
    return base64_encode($iv.$tag.$cipher);
}
function openSecret(string $sealed): string {
    $raw=base64_decode($sealed,true);if($raw===false||strlen($raw)<29)fail(503,'密钥密文损坏');
    $value=openssl_decrypt(substr($raw,28),'aes-256-gcm',masterKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'sim-device-key');
    if($value===false)fail(503,'密钥文件与密文不匹配，请恢复原keyring.php');
    return $value;
}
