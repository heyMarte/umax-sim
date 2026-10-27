<?php
declare(strict_types=1);
require __DIR__.'/../src/api.php';
if(!str_ends_with(settings()['db_name'],'_test'))throw new RuntimeException('Disposable _test database required');
function rpcheck(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
$u=loadUserAccess(query("SELECT * FROM users WHERE role='admin' AND active=1 AND deleted_at IS NULL ORDER BY id LIMIT 1")[0]);
if(($argv[1]??'')==='--child'){
 [$sid,$shop,$other]=array_map('intval',array_slice($argv,2));
 rpcheck(!in_array('amazon',platform_keys(),true),'removed config fixture');
 $phone=query('SELECT phone FROM sims WHERE id=?',[$sid])[0]['phone'];
 simLinkRoute($u,'PUT','/api/sims/'.$sid.'/shops',['number'=>['phone'=>$phone,'country'=>'PH','carrier'=>'Smart'],'shops'=>[['shop_id'=>$shop,'platform'=>'amazon']]]);
 rpcheck(query('SELECT carrier FROM sims WHERE id=?',[$sid])[0]['carrier']==='Smart'&&simShopIds($sid)===[$shop],'preserve historical link while editing carrier');
 try{simLinkRoute($u,'PUT','/api/sims/'.$other.'/shops',['shops'=>[['shop_id'=>$shop,'platform'=>'amazon']]]);throw new LogicException('new retired association accepted');}catch(ApiError $e){rpcheck($e->status===422,'reject new retired link');}
 try{release053Route($u,'POST','/api/sims/'.$sid.'/transfer-shops',['target_sim_id'=>$other,'shop_ids'=>[$shop]]);throw new LogicException('retired transfer accepted');}catch(ApiError $e){rpcheck($e->status===422,'reject retired transfer');}
 echo "PASS retired platform preserves existing number edits but forbids new associations\n";exit;
}
$tag=bin2hex(random_bytes(5));$sid=0;$other=0;$shop=0;$config=null;
try{
 $s=simLinkRoute($u,'POST','/api/sims/onboard',['phone'=>'+639'.random_int(100000000,999999999),'carrier'=>'Globe','country'=>'PH','shops'=>[['platform'=>'amazon','name'=>'Retired fixture '.$tag]]]);$sid=$s['id'];$shop=$s['shops'][0]['id'];
 $other=simLinkRoute($u,'POST','/api/sims/onboard',['phone'=>'+639'.random_int(100000000,999999999),'carrier'=>'Globe','country'=>'PH','shops'=>[]])['id'];
 $c=settings();$c['platform_catalog']=[];$config=tempnam(sys_get_temp_dir(),'umax-retired-');chmod($config,0600);file_put_contents($config,'<?php return '.var_export($c,true).';');
 $proc=proc_open([PHP_BINARY,__FILE__,'--child',(string)$sid,(string)$shop,(string)$other],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['SIM_CONFIG'=>$config]));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$rc=proc_close($proc);echo $out;rpcheck($rc===0,'retired platform regression failed: '.$err);
}finally{if($config)unlink($config);foreach([$sid,$other] as $id)if($id){query('DELETE FROM sim_shops WHERE sim_id=?',[$id]);query('DELETE FROM sims WHERE id=?',[$id]);}if($shop)query('DELETE FROM shops WHERE id=?',[$shop]);}
