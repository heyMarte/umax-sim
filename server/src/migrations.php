<?php
declare(strict_types=1);
require_once __DIR__.'/secrets.php';
function migrate(): void {
    // 固定白名单DDL，可重入；MySQL DDL自动提交，按实际列存在性恢复中断升级。
    $columns=[
      'bindings'=>['sim_token'=>'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL'],
      'devices'=>['created_by'=>'BIGINT UNSIGNED NULL','deleted_at'=>'DOUBLE NULL','secret_cipher'=>'TEXT NULL','location'=>"VARCHAR(50) NOT NULL DEFAULT ''",'notes'=>"VARCHAR(500) NOT NULL DEFAULT ''"],
      'sims'=>['created_by'=>'BIGINT UNSIGNED NULL','shop_id'=>'BIGINT UNSIGNED NULL','deleted_at'=>'DOUBLE NULL','country'=>"CHAR(2) NOT NULL DEFAULT 'PH'",'currency'=>"CHAR(3) NOT NULL DEFAULT 'PHP'"],
      'users'=>['all_shops'=>'TINYINT NOT NULL DEFAULT 0','deleted_at'=>'DOUBLE NULL'],
      'records'=>['owner_id'=>'BIGINT UNSIGNED NULL','currency_snapshot'=>"CHAR(3) NOT NULL DEFAULT 'PHP'",'shop_id'=>'BIGINT UNSIGNED NULL','phone_snapshot'=>"VARCHAR(32) NOT NULL DEFAULT ''",'shop_snapshot'=>"VARCHAR(200) NOT NULL DEFAULT ''",'deleted_at'=>'DOUBLE NULL'],
      'messages'=>['owner_id'=>'BIGINT UNSIGNED NULL','otp_code'=>'VARCHAR(8) NULL','platform'=>'VARCHAR(16) NULL','shop_id'=>'BIGINT UNSIGNED NULL','phone_snapshot'=>"VARCHAR(32) NOT NULL DEFAULT ''",'shop_snapshot'=>"VARCHAR(200) NOT NULL DEFAULT ''",'deleted_at'=>'DOUBLE NULL'],
      'audit'=>['actor_id'=>'BIGINT UNSIGNED NULL','object_type'=>"VARCHAR(40) NOT NULL DEFAULT 'legacy'",'object_name'=>"VARCHAR(300) NOT NULL DEFAULT ''",'shop_id'=>'BIGINT UNSIGNED NULL','shop_name'=>"VARCHAR(200) NOT NULL DEFAULT ''",'summary'=>"VARCHAR(1000) NOT NULL DEFAULT ''",'changes_json'=>'MEDIUMTEXT NULL','status'=>"VARCHAR(20) NOT NULL DEFAULT 'success'",'ip'=>"VARCHAR(64) NOT NULL DEFAULT ''",'is_detailed'=>'TINYINT NOT NULL DEFAULT 0'],
    ];
    foreach($columns as $table=>$defs){
      $existing=array_column(query("SHOW COLUMNS FROM `$table`"),'Field');
      foreach($defs as $name=>$ddl)if(!in_array($name,$existing,true))db()->exec("ALTER TABLE `$table` ADD COLUMN `$name` $ddl");
    }
    db()->exec('CREATE TABLE IF NOT EXISTS sim_auto_pauses(device_id BIGINT UNSIGNED NOT NULL,sim_id BIGINT UNSIGNED NOT NULL,paused_at DOUBLE NOT NULL,PRIMARY KEY(device_id,sim_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->exec('CREATE TABLE IF NOT EXISTS device_telemetry(device_id BIGINT UNSIGNED PRIMARY KEY,observed_at DOUBLE NOT NULL,payload MEDIUMTEXT NOT NULL,topology TEXT NULL,topology_at DOUBLE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->exec('CREATE TABLE IF NOT EXISTS device_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,device_id BIGINT UNSIGNED NOT NULL,kind VARCHAR(50) NOT NULL,summary VARCHAR(500) NOT NULL,at DOUBLE NOT NULL,INDEX(device_id,at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->exec('CREATE TABLE IF NOT EXISTS alert_mutes(user_id BIGINT UNSIGNED NOT NULL,alert_key VARCHAR(150) NOT NULL,until_at DOUBLE NOT NULL,PRIMARY KEY(user_id,alert_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->exec('CREATE TABLE IF NOT EXISTS schema_versions(version VARCHAR(30) PRIMARY KEY,applied_at DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    query('DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE active=0 OR deleted_at IS NOT NULL)');
    db()->exec('CREATE TABLE IF NOT EXISTS device_refresh_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,device_id BIGINT UNSIGNED NOT NULL,requested_at DOUBLE NOT NULL,acknowledged_at DOUBLE NULL,INDEX(device_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->exec('CREATE TABLE IF NOT EXISTS receive_checks(device_id BIGINT UNSIGNED NOT NULL,code CHAR(6) NOT NULL,subscription_id INT NOT NULL,started_at DOUBLE NOT NULL,expires_at DOUBLE NOT NULL,sim_id BIGINT UNSIGNED NULL,message_id BIGINT UNSIGNED NULL,PRIMARY KEY(device_id,code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->exec('CREATE TABLE IF NOT EXISTS shops(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(200) NOT NULL UNIQUE,deleted_at DOUBLE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    if(!in_array('platforms',array_column(query('SHOW COLUMNS FROM shops'),'Field'),true))db()->exec('ALTER TABLE shops ADD COLUMN platforms TEXT NULL');
    db()->exec('CREATE TABLE IF NOT EXISTS user_permissions(user_id BIGINT UNSIGNED NOT NULL,permission VARCHAR(80) NOT NULL,PRIMARY KEY(user_id,permission)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->exec('CREATE TABLE IF NOT EXISTS user_shops(user_id BIGINT UNSIGNED NOT NULL,shop_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(user_id,shop_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->exec('CREATE TABLE IF NOT EXISTS device_scopes(device_id BIGINT UNSIGNED NOT NULL,shop_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(device_id,shop_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->exec('CREATE TABLE IF NOT EXISTS audit_scope(audit_id BIGINT UNSIGNED NOT NULL,shop_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(audit_id,shop_id),INDEX(shop_id,audit_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $applied=query('SELECT version FROM schema_versions WHERE version=?',['0.4.0']);
    if(!$applied){
      query('INSERT IGNORE INTO shops(name) SELECT DISTINCT TRIM(shop) COLLATE utf8mb4_bin FROM sims WHERE TRIM(shop)<>?', ['']);
      if(query('SELECT id FROM sims WHERE TRIM(shop)=? LIMIT 1',['']))query('INSERT IGNORE INTO shops(name) VALUES(?)',['未命名店铺（历史数据）']);
      query('UPDATE sims s JOIN shops sh ON BINARY TRIM(s.shop)=BINARY sh.name SET s.shop_id=sh.id WHERE s.shop_id IS NULL AND TRIM(s.shop)<>?',['']);
      query('UPDATE sims SET shop_id=(SELECT id FROM shops WHERE name=?) WHERE shop_id IS NULL AND TRIM(shop)=?',['未命名店铺（历史数据）','']);
      query('UPDATE messages m JOIN sims s ON m.sim_id=s.id SET m.shop_id=s.shop_id,m.phone_snapshot=s.phone,m.shop_snapshot=s.shop WHERE m.shop_id IS NULL');
      query('UPDATE records r JOIN sims s ON r.sim_id=s.id SET r.shop_id=s.shop_id,r.phone_snapshot=s.phone,r.shop_snapshot=s.shop WHERE r.shop_id IS NULL');
      query('DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE role<>?)',['admin']);
    }
    query('INSERT IGNORE INTO device_scopes(device_id,shop_id) SELECT b.device_id,s.shop_id FROM bindings b JOIN sims s ON b.sim_id=s.id JOIN devices d ON d.id=b.device_id WHERE s.shop_id IS NOT NULL AND d.created_by IS NULL');
    query('INSERT IGNORE INTO device_scopes(device_id,shop_id) SELECT DISTINCT m.device_id,s.shop_id FROM messages m JOIN sims s ON m.sim_id=s.id JOIN devices d ON d.id=m.device_id WHERE s.shop_id IS NOT NULL AND d.created_by IS NULL');
    query('INSERT INTO schema_versions(version,applied_at) VALUES(?,?) ON DUPLICATE KEY UPDATE version=VALUES(version)',['0.4.0',microtime(true)]);
    if(!query("SHOW INDEX FROM audit WHERE Key_name='audit_actor_read'"))db()->exec('ALTER TABLE audit ADD INDEX audit_actor_read(actor_id,action,object_id,at)');
    foreach(query("SHOW INDEX FROM devices WHERE Column_name='token' AND Non_unique=0") as $index){$name=$index['Key_name'];if($name!=='PRIMARY'&&preg_match('/^[A-Za-z0-9_]+$/D',$name))db()->exec("ALTER TABLE devices DROP INDEX `$name`");}
    if(!query("SHOW INDEX FROM devices WHERE Key_name='device_token_lookup'"))db()->exec('ALTER TABLE devices ADD INDEX device_token_lookup(token)');
    db()->exec('ALTER TABLE devices MODIFY secret_cipher MEDIUMTEXT NULL');
    if(!query('SELECT version FROM schema_versions WHERE version=?',['0.4.1']))foreach(query('SELECT id,sender,body FROM messages') as $message)query('UPDATE messages SET platform=? WHERE id=?',[platform_message_infer($message['sender'],$message['body']),$message['id']]);
    query('INSERT INTO schema_versions(version,applied_at) VALUES(?,?) ON DUPLICATE KEY UPDATE version=VALUES(version)',['0.4.1',microtime(true)]);
    if(!query('SELECT version FROM schema_versions WHERE version=?',['0.4.2'])){
      $last=0;do{$batch=query('SELECT id,sender,body FROM messages WHERE id>? ORDER BY id LIMIT 200',[$last]);foreach($batch as $message){$last=(int)$message['id'];$code=messageOtp($message['sender'],$message['body']);query('UPDATE messages SET otp_code=? WHERE id=?',[$code!==''?$code:null,$last]);}}while(count($batch)===200);
    }
    query('INSERT INTO schema_versions(version,applied_at) VALUES(?,?) ON DUPLICATE KEY UPDATE version=VALUES(version)',['0.4.2',microtime(true)]);
    if(!in_array('platform',array_column(query('SHOW COLUMNS FROM shops'),'Field'),true))db()->exec("ALTER TABLE shops ADD COLUMN platform VARCHAR(16) NOT NULL DEFAULT ''");
    // Names are display labels. Identity, permissions and links use the shop ID.
    foreach(query('SHOW INDEX FROM shops') as $idx)if($idx['Key_name']!=='PRIMARY'&&(int)$idx['Non_unique']===0&&$idx['Column_name']==='name'){
      $key=$idx['Key_name'];if(preg_match('/^[A-Za-z0-9_]+$/D',$key))db()->exec("ALTER TABLE shops DROP INDEX `$key`");
    }
    if(!query("SHOW INDEX FROM shops WHERE Key_name='shop_platform_name'"))db()->exec('ALTER TABLE shops ADD INDEX shop_platform_name(platform,name)');
    db()->exec('CREATE TABLE IF NOT EXISTS sim_shops(sim_id BIGINT UNSIGNED NOT NULL,shop_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(sim_id,shop_id),INDEX(shop_id,sim_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if(!query('SELECT version FROM schema_versions WHERE version=?',['sim-shops-0.5.0'])){
      query('INSERT IGNORE INTO sim_shops(sim_id,shop_id) SELECT id,shop_id FROM sims WHERE shop_id IS NOT NULL');
      query('INSERT IGNORE INTO device_scopes(device_id,shop_id) SELECT b.device_id,ss.shop_id FROM bindings b JOIN sim_shops ss ON ss.sim_id=b.sim_id JOIN devices d ON d.id=b.device_id WHERE d.created_by IS NULL');
      query('INSERT IGNORE INTO schema_versions(version,applied_at) VALUES(?,?)',['sim-shops-0.5.0',microtime(true)]);
    }
    query('INSERT INTO schema_versions(version,applied_at) VALUES(?,?) ON DUPLICATE KEY UPDATE version=VALUES(version)',[SIM_VERSION,microtime(true)]);
    db()->exec("CREATE TABLE IF NOT EXISTS number_pairings(id CHAR(32) PRIMARY KEY,device_id BIGINT UNSIGNED NOT NULL,sim_id BIGINT UNSIGNED NOT NULL,initiator_id BIGINT UNSIGNED NOT NULL,code VARCHAR(80) NOT NULL UNIQUE,phone VARCHAR(32) NOT NULL,started_at DOUBLE NOT NULL,expires_at DOUBLE NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'pending',detail VARCHAR(300) NULL,subscription_id INT NULL,slot INT NULL,message_event_id VARCHAR(100) NULL,INDEX(device_id,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec('CREATE TABLE IF NOT EXISTS alert_dismissals(user_id BIGINT UNSIGNED NOT NULL,alert_key VARCHAR(150) NOT NULL,PRIMARY KEY(user_id,alert_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->exec('CREATE TABLE IF NOT EXISTS message_tombstones(device_id BIGINT UNSIGNED NOT NULL,event_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,PRIMARY KEY(device_id,event_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if(!in_array('old_binding',array_column(query('SHOW COLUMNS FROM number_pairings'),'Field'),true))db()->exec('ALTER TABLE number_pairings ADD COLUMN old_binding TEXT NULL');
    db()->exec('ALTER TABLE records MODIFY sim_id BIGINT UNSIGNED NULL');
    if(!query("SELECT version FROM schema_versions WHERE version='owner-snapshots-053'")){query('UPDATE messages m JOIN sims s ON s.id=m.sim_id SET m.owner_id=s.created_by WHERE m.shop_id IS NULL AND NOT EXISTS(SELECT 1 FROM sim_shops ms WHERE ms.sim_id=s.id)');query('UPDATE records r JOIN sims s ON s.id=r.sim_id SET r.owner_id=s.created_by,r.currency_snapshot=s.currency');query("INSERT INTO schema_versions(version,applied_at) VALUES('owner-snapshots-053',?)",[microtime(true)]);}
    keyringInit();
    wxMigrate();
    mailMigrate();
    query('INSERT INTO schema_versions(version,applied_at) VALUES(?,?) ON DUPLICATE KEY UPDATE version=VALUES(version)',['0.3.3',microtime(true)]);
    query('INSERT INTO schema_versions(version,applied_at) VALUES(?,?) ON DUPLICATE KEY UPDATE version=VALUES(version)',['0.3.2',microtime(true)]);
    query('INSERT INTO schema_versions(version,applied_at) VALUES(?,?) ON DUPLICATE KEY UPDATE version=VALUES(version)',['0.3.0',microtime(true)]);
}
