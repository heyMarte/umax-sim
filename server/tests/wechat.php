<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/secrets.php';
if(is_file(dirname(__DIR__).'/src/wechat.php'))require_once dirname(__DIR__).'/src/wechat.php';
function check(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
check(function_exists('wxMigrate'),'WeChat core must expose migration and enqueue service');
wxMigrate();
check(!wxFresh(['received_at'=>time()-301,'otp_code'=>'123456']),'expired OTP excluded');
check(!wxFresh(['received_at'=>time()+62,'otp_code'=>'123456']),'future OTP excluded');
check(!wxFresh(['received_at'=>time(),'otp_code'=>'']),'non OTP excluded');
check(wxFresh(['received_at'=>time(),'otp_code'=>'123456']),'fresh OTP accepted');
$c=['template_id'=>'tpl','origin'=>'https://sms.example.test'];$payload=wxPayload($c,'openid','实际手机名称','001234',time());
check(($payload['url']??null)==='https://sms.example.test/#messages','authenticated SMS detail link contains no secret');
check($payload['data']['character_string15']['value']==='001234','OTP leading zeroes preserved');
check(array_keys($payload['data'])===['thing1','character_string15','time4'],'actual template fields');
check(!str_contains(json_encode($payload),'body'),'no SMS body');
check(wxOutcome(['errcode'=>0,'msgid'=>123])['status']==='accepted','acceptance never means delivery');
check(wxOutcome(['errcode'=>-1])['status']==='retry','known transient rejection retry');
check(wxOutcome(['errcode'=>43004])['status']==='failed','unfollow rejected');
check(wxOutcome(null)['status']==='uncertain','ambiguous send never retried');
echo "PASS WeChat pure behavior\n";
