<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
function mu(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
mu(function_exists('mailImportRows'),'Mailbox integration missing');
$rows=mailImportRows("one@outlook.com----SECRET_PASSWORD\none@outlook.com\nTwo@Hotmail.com,ANOTHER_SECRET\ninvalid");
mu($rows['emails']===['one@outlook.com','two@hotmail.com']&&$rows['invalid']===1,'email import strips password columns and duplicates');
mu(!str_contains(json_encode($rows),'SECRET'),'import result must not contain passwords');
mu(mailExtractCode('Verification code','Your verification code is <b>001234</b>')==='001234','HTML OTP and leading zeroes');
mu(mailExtractCode('Security code','Your security code: A1B2C3')==='A1B2C3','alphanumeric OTP');
mu(mailExtractCode('Security code','Your security code: AB1234')==='AB1234','letter prefix is not truncated');
mu(mailExtractCode('Verification code','Your verification code:1234AB')==='1234AB','letter suffix is not truncated');
mu(mailExtractCode('Order123456','Your parcel number is123456')==='','ordinary mail excluded');
mu(mailExtractCode('验证码','<script>验证码999999</script><p>验证码为123456</p>')==='123456','scripts excluded from extraction');
mu(mailGraphUrl('https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages?$skiptoken=x'),'valid Graph paging URL');
foreach(['https://evil.test/v1.0/me/messages','http://graph.microsoft.com/v1.0/me/messages','https://graph.microsoft.com@evil.test/v1.0/me/messages','https://graph.microsoft.com:444/v1.0/me/messages'] as $url)mu(!mailGraphUrl($url),'unsafe Graph continuation rejected');
echo "PASS mail import, OTP extraction and Microsoft endpoint validation\n";
