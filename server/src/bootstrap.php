<?php
declare(strict_types=1);
require_once __DIR__.'/version.php';

final class ApiError extends RuntimeException {
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}
function fail(int $status, string $message): never { throw new ApiError($status, $message); }
function settings(): array {
    static $config;
    if ($config !== null) return $config;
    $path = getenv('SIM_CONFIG') ?: dirname(__DIR__).'/config/config.php';
    if (!is_file($path)) fail(503, '尚未配置数据库，请按部署说明配置');
    $config = require $path;
    if (!is_array($config)) fail(503, '配置格式错误');
    return $config;
}
function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $c = settings();
    $pdo = new PDO('mysql:host='.$c['db_host'].';port='.(int)$c['db_port'].';dbname='.$c['db_name'].';charset=utf8mb4', $c['db_user'], $c['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    return $pdo;
}
function query(string $sql, array $params = []): array {
    $s = db()->prepare($sql); $s->execute($params);
    return $s->columnCount() ? $s->fetchAll() : [];
}
function insert(string $sql, array $params): int { query($sql,$params); return (int)db()->lastInsertId(); }
function fieldLabel(string $name): string {
    return ['username'=>'账户名','password'=>'密码','phone'=>'手机号','carrier'=>'运营商','location'=>'位置','shop'=>'店铺','name'=>'名称','notes'=>'备注','note'=>'备注','threshold'=>'余额阈值','amount'=>'金额','role'=>'角色','token'=>'密钥','sim_id'=>'号码','device_id'=>'设备','subscription_id'=>'卡槽','duration'=>'暂缓时长','key'=>'提醒项','package_expires'=>'套餐到期日','next_check'=>'下次检查日期','preservation_status'=>'保号说明'][$name]??$name;
}
function stringField(array $data,string $name,int $max,bool $required=true): string {
    $label=fieldLabel($name);
    if (!array_key_exists($name,$data)) { if (!$required) return ''; fail(422, "请填写$label"); }
    if (!is_string($data[$name])) fail(422,"$label 格式不正确");
    $value=$data[$name];
    if (mb_strlen($value)>$max || ($required && $value==='')) fail(422,"$label 过长或未填写，请检查后重试");
    return $value;
}
function intField(array $data,string $name,bool $nullable=false): ?int {
    $v=$data[$name]??null;
    if ($nullable && $v===null) return null;
    if (!is_int($v) || $v<0 || $v>2147483647) fail(422,"字段必须为非负整数：$name");
    return $v;
}
function amount(array $data,string $key='amount'): string {
    $v=$data[$key]??null;
    if ((!is_string($v)&&!is_int($v)&&!is_float($v)) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',(string)$v)) fail(422,'金额必须为非负数，最多两位小数');
    return (string)$v;
}
function dateField(array $data,string $key): ?string {
    $v=stringField($data,$key,10,false);
    if ($v==='') return null;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    if (!$d || $d->format('Y-m-d')!==$v) fail(422,'日期格式必须为有效的 YYYY-MM-DD');
    return $v;
}
function user(bool $admin=false): array {
    $rows=query('SELECT u.*,s.csrf FROM users u JOIN sessions s ON u.id=s.user_id WHERE s.token=? AND s.expires>? AND u.active=1 AND u.deleted_at IS NULL',[hash('sha256',$_COOKIE['session']??''),microtime(true)]);
    if (!$rows) fail(401,'请登录');
    $u=$rows[0];
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true) && !hash_equals($u['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail(403,'CSRF 校验失败');
    if ($admin && $u['role']!=='admin') fail(403,'需要管理员权限');
    return loadUserAccess($u);
}
function randomDeviceKey(): string {
    for($attempt=0;$attempt<128;$attempt++){
        $key=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
        if(!query('SELECT id FROM devices WHERE token=? LIMIT 1',[hash('sha256',$key)]))return $key;
    }
    fail(503,'暂时无法生成连接密钥，请稍后重试');
}
function deviceAuthLimitBucket(): string {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');$raw=$_SERVER['HTTP_X_DEVICE_ID']??null;
    $id=$raw===null?'none':(ctype_digit((string)$raw)&&(int)$raw>0?(string)(int)$raw:'invalid');
    return 'dv:'.substr(hash('sha256',$ip."\0".$id),0,60);
}
function deviceAuthIpBucket(): string {return 'dg:'.substr(hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown')),0,60);}
function deviceAuthLimitCheck(): void {
    foreach([[deviceAuthLimitBucket(),12],[deviceAuthIpBucket(),120]] as [$bucket,$limit]){
        $row=query('SELECT count,since FROM attempts WHERE ip=?',[$bucket])[0]??null;
        if($row&&(int)$row['count']>=$limit&&microtime(true)-(float)$row['since']<60)fail(429,'连接验证尝试过多，请稍后重试或重新核对接入码');
    }
}
function deviceAuthLimitFailure(): void {
    $now=microtime(true);
    foreach([deviceAuthLimitBucket(),deviceAuthIpBucket()] as $bucket)query('INSERT INTO attempts(ip,count,since) VALUES(?,1,?) ON DUPLICATE KEY UPDATE count=IF(since<? ,1,count+1),since=IF(since<? ,VALUES(since),since)',[$bucket,$now,$now-60,$now-60]);
}
function deviceAuthLimitSuccess(): void {query('DELETE FROM attempts WHERE ip=?',[deviceAuthLimitBucket()]);}
function device(): array {
    deviceAuthLimitCheck();
    $auth=$_SERVER['HTTP_AUTHORIZATION']??'';
    if(str_starts_with($auth,'DeviceKey ')){$token=base64_decode(substr($auth,10),true);if($token===false){deviceAuthLimitFailure();fail(401,'设备未授权');}}
    elseif(str_starts_with($auth,'Bearer '))$token=substr($auth,7);else{deviceAuthLimitFailure();fail(401,'设备未授权');}
    $id=$_SERVER['HTTP_X_DEVICE_ID']??null;
    if($id!==null&&(!ctype_digit($id)||(int)$id<1)){deviceAuthLimitFailure();fail(401,'连接配置无效，请重新扫码或粘贴完整配置');}
    $rows=query('SELECT * FROM devices WHERE token=? AND revoked=0 AND deleted_at IS NULL'.($id!==null?' AND id=?':''),$id!==null?[hash('sha256',$token),(int)$id]:[hash('sha256',$token)]);
    if(count($rows)!==1){deviceAuthLimitFailure();fail(401,count($rows)>1?'密钥对应多台手机，请重新扫码或粘贴完整连接配置':'连接配置或密钥不正确，或手机已停用，请重新扫码核对');}
    deviceAuthLimitSuccess();
    return $rows[0];
}
function createUser(string $username,string $password,string $role): void {
    if ($username==='' || mb_strlen($username)>100 || strlen($password)<6 || strlen($password)>72 || !in_array($role,['admin','operator'],true)) fail(422,'账户、密码或角色不合要求；密码为6至72字节');
    query('INSERT INTO users(username,password,role) VALUES(?,?,?)',[$username,password_hash($password,PASSWORD_DEFAULT),$role]);
}
function clean(): void {
    query('DELETE FROM receive_checks WHERE expires_at<?',[microtime(true)-86400]);
    query('DELETE FROM device_refresh_requests WHERE requested_at<?',[microtime(true)-604800]);
    query('DELETE FROM messages WHERE uploaded_at<?',[microtime(true)-604800]);
    query('DELETE FROM sessions WHERE expires<?',[microtime(true)]);
    query('DELETE FROM attempts WHERE since<?',[microtime(true)-86400]);
    query('DELETE FROM device_events WHERE at<?',[microtime(true)-7776000]);
    query('DELETE FROM alert_mutes WHERE until_at<?',[microtime(true)]);
}
function simRows(?array $u=null): array {
    $u??=$GLOBALS['request_user']??null;
    $rows=query('SELECT s.*,b.device_id,b.subscription_id,b.version AS binding_version FROM sims s LEFT JOIN bindings b ON s.id=b.sim_id WHERE s.deleted_at IS NULL ORDER BY s.id');
    $activity=messageActivity();foreach($rows as &$r)$r['activity']=$activity[(int)$r['id']]??['sms_count'=>0,'otp_count'=>0,'last_received_at'=>null,'last_uploaded_at'=>null];return $u?scopeSimRows($u,$rows):$rows;
}
function deviceRows(?array $u=null): array {
    $u??=$GLOBALS['request_user']??null;
    $rows=query('SELECT id,name,revoked,heartbeat,subscriptions,location,notes,(secret_cipher IS NOT NULL) AS key_available FROM devices WHERE deleted_at IS NULL ORDER BY id');
    $telemetry=telemetryByDevice();
    foreach($rows as &$row){$row['online']=!$row['revoked']&&$row['heartbeat']!==null&&microtime(true)-(float)$row['heartbeat']<=180;$row['key_available']=(bool)$row['key_available'];$row['telemetry']=$telemetry[(int)$row['id']]??null;$row['refresh']=refreshState((int)$row['id']);$row['receive_check']=latestReceiveCheck((int)$row['id']);}
    return $u?scopeDeviceRows($u,$rows):$rows;
}
require_once __DIR__.'/platforms.php';
require_once __DIR__.'/message_privacy.php';
require_once __DIR__.'/telemetry.php';

require_once __DIR__.'/workflows.php';

require_once __DIR__.'/rbac.php';
require_once __DIR__.'/audit.php';

require_once __DIR__.'/sim_shops.php';

require_once __DIR__.'/number_pairing.php';
require_once __DIR__.'/sim_lifecycle.php';
require_once __DIR__.'/release053.php';

require_once __DIR__.'/wechat.php';
