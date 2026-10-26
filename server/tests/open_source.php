<?php
declare(strict_types=1);
$GLOBALS['catalog_config']=[];
function settings():array{return ['platform_catalog'=>$GLOBALS['catalog_config']];}
function fail(int $status,string $message):never{throw new RuntimeException($message,$status);}
require __DIR__.'/../src/platforms.php';
function expect(bool $v,string $msg):void{if(!$v)throw new RuntimeException($msg);}
$GLOBALS['catalog_config']=['amazon'=>['label'=>'Amazon','keywords'=>['amazon']],'internal'=>['label'=>'内部用途','keywords'=>[]]];
expect(platform_normalize(['amazon','tiktok','amazon'])===['tiktok','amazon'],'custom platform normalization');
expect(platform_message_infer('Amazon','OTP 123456')==='amazon','explicit keyword inference');
expect(platform_message_infer('OTP','内部用途 123456')===null,'labels do not infer sources');
expect(platform_message_infer('Amazon','TikTok OTP 123456')===null,'ambiguous source remains unknown');
expect(platform_enrich_message(['platform'=>'retired_tag','body'=>'Shopee 123456'])['inferred_platform']==='retired_tag','stored historical platform cannot be recomputed');
expect(platform_enrich_shop(['platform'=>'retired_tag','name'=>'Archive'])['platforms']===['retired_tag'],'historical shop remains represented');
$_GET['platform']='amazon';expect(platform_filter()==='amazon','custom filter');
expect(json_decode(platform_input(['platforms'=>['amazon']]),true)===['amazon'],'custom input');
expect(platform_options()[3]['label']==='Amazon','public catalog labels');
foreach([
 ['unknown'=>['label'=>'Invalid slug reserved','keywords'=>[]]],
 ['bad/key'=>['label'=>'Bad','keywords'=>[]]],
 ['x'=>['label'=>'','keywords'=>[]]],
 ['x'=>['label'=>'X','keywords'=>['']]],
] as $bad){$GLOBALS['catalog_config']=$bad;try{platform_keys();throw new LogicException('invalid catalog accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}}
$GLOBALS['catalog_config']=['amazon'=>['label'=>'<b>Amazon</b>','keywords'=>['a.+']]];
expect(platform_options()[3]['label']==='<b>Amazon</b>','label stays plain data');
expect(platform_message_infer('aaaa','OTP')===null,'keyword regex is escaped');
expect(platform_message_infer('a.+','OTP')==='amazon','literal punctuation keyword');
echo "PASS configurable platforms, input validation and immutable historical identity\n";
