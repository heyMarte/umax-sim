<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
function expect(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
expect(messageOtp('TikTok','123456 is your verification code')==='123456','explicit code');
expect(messageOtp('GLOBE_OTP','Your One-Time PIN is 654321')==='654321','one time pin');
expect(messageOtp('SHOP','Your order 123456 is shipped')==='','order number is private');
expect(messageOtp('bank','Your balance is 1234 pesos')==='','balance is private');
expect(messageOtp('shop','Promo code 123456 for your order')==='','promo is private');
expect(messageOtp('shop','验证码：123456，5分钟有效')==='123456','Chinese');
expect(messageOtp('shop','Verification code: 123456, order 998877')==='123456','prefer context over unrelated digits');
$row=['id'=>1,'sender'=>'private','body'=>'SECRET_ORDER_123456','otp_code'=>null];
$safe=messageListRow($row,['role'=>'operator']);expect($safe['body']===null&&$safe['content_locked']===true,'operator redaction');
$safe=messageListRow($row,['role'=>'admin']);expect($safe['body']===null&&$safe['content_locked']===true&&$safe['can_reveal']===true,'admin must explicitly reveal');
echo "PASS SMS classification and response redaction\n";
