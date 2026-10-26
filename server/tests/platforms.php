<?php
require_once __DIR__.'/../src/platforms.php';
function check($condition){if(!$condition)throw new RuntimeException('platform assertion failed');}
check(platform_normalize(null)===[]);
check(platform_normalize('["lazada","tiktok","tiktok","fake"]')===['tiktok','lazada']);
check(platform_matches('["tiktok","shopee","lazada"]','lazada'));
check(platform_matches(null,'unknown'));
check(!platform_matches(null,'tiktok'));
check(platform_message_infer('Shopee','OTP 123456')==='shopee');
check(platform_message_infer('12345','Your TikTok code is 123456')==='tiktok');
check(platform_message_infer('OTP','123456')===null);
check(platform_message_infer('Shopee','TikTok code 123456')===null);
check(platform_message_infer('notshopee','123456')===null);
$m=platform_enrich_message(['platforms'=>'["tiktok","shopee","lazada"]','body'=>'OTP 123456']);
check(count($m['platforms'])===3&&$m['inferred_platform']===null&&$m['platform_label']==='平台待确认');
echo "platform unit tests passed\n";
