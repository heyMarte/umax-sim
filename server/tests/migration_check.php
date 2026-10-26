<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/migrations.php';
if(!str_ends_with(settings()['db_name'],'_legacy_test'))throw new RuntimeException('只允许独立旧版测试库');
$sql=file_get_contents(dirname(__DIR__).'/database/schema.sql');foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')db()->exec($statement);
$original=hash('sha256','legacy-device-secret');query('INSERT INTO devices(name,token) VALUES(?,?)',['legacy phone',$original]);
query('INSERT INTO sims(phone,carrier,location,shop) VALUES(?,?,?,?)',['+639555555555','Globe','菲律宾','legacy shop']);
migrate();$keyHash=hash_file('sha256',keyringPath());migrate();
if(hash_file('sha256',keyringPath())!==$keyHash)throw new RuntimeException('重复升级改写主密钥');
$r=query('SELECT token,secret_cipher FROM devices WHERE name=?',['legacy phone'])[0];
if($r['token']!==$original||$r['secret_cipher']!==null)throw new RuntimeException('旧密钥数据未保留');
if(count(query('SELECT id FROM sims'))!==1)throw new RuntimeException('旧号码未保留');
$cipher=sealSecret('secret-for-test');if(openSecret($cipher)!=='secret-for-test')throw new RuntimeException('密钥加密回读失败');
echo "PASS: old schema migration, repeat safety, data preservation and encryption roundtrip\n";
