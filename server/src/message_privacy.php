<?php
declare(strict_types=1);
/** Require authentication context; an order number or balance alone is not an OTP. */
function messageOtp(string $sender,string $body): string {
    $context='(?:验证码|校验码|动态码|验证代码|一次性密码|verification\s*(?:code|pin)|security\s*code|authentication\s*code|one[\s-]*time\s*(?:pin|password|code)|\bOTP\b|\bPIN\b)';
    // Prefer the numeric candidate immediately before/after its authentication label.
    foreach(['/'. $context .'[^\d\r\n]{0,40}(\d{4,8})(?!\d)/iu','/(?<!\d)(\d{4,8})[^\d\r\n]{0,40}'. $context .'/iu'] as $pattern){
        if(preg_match_all($pattern,$body,$m)&&count(array_unique($m[1]))===1)return $m[1][0];
    }
    if(preg_match('/'.$context.'/iu',$body.' '.$sender))return otpCandidate($body);
    if(preg_match('/\bcode\b/iu',$body)&&!preg_match('/\b(?:promo|coupon|discount|order|tracking|parcel)\b/iu',$body))return otpCandidate($body);
    return '';
}
function messageListRow(array $row,array $u): array {
    $row['code']=$row['otp_code']??'';
    $row['has_code']=$row['code']!==''&&$row['code']!==null;
    $row['content_locked']=!$row['has_code'];
    $row['can_reveal']=$row['has_code']||$u['role']==='admin';
    if($row['content_locked'])$row['body']=null;
    return $row;
}
