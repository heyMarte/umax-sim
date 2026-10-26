<?php
declare(strict_types=1);
// Exercise the real initialization guard with a database surface containing only encrypted WeChat state.
require __DIR__.'/../src/secrets.php';
function query(string $sql,array $params=[]):array {
 if(str_contains($sql,'FROM devices'))return [];
 if(str_contains($sql,'SHOW TABLES')&&str_contains($sql,'wx_config'))return [['table'=>'wx_config']];
 if(str_contains($sql,'FROM wx_config'))return [['id'=>1]];
 throw new RuntimeException('Unexpected query in keyring guard');
}
function fail(int $status,string $message):never {throw new RuntimeException($message,$status);}
$path=sys_get_temp_dir().'/umax-keyring-'.bin2hex(random_bytes(6)).'.php';putenv('SIM_KEYRING='.$path);
try {try{keyringInit();throw new LogicException('Lost WeChat keyring must not be regenerated');}catch(RuntimeException $e){if($e->getCode()!==503)throw $e;}if(file_exists($path))throw new LogicException('Guard created a destructive replacement keyring');echo "PASS missing keyring refuses to replace existing encrypted WeChat configuration\n";}finally{if(file_exists($path))unlink($path);}
