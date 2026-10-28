<?php
declare(strict_types=1);
final class MailRemoteError extends RuntimeException {
 public function __construct(public int $httpStatus,public string $providerCode='provider',public int $retryAfter=30){parent::__construct('Microsoft request failed');}
}
function mailGraphUrl(string $url):bool {
 $p=parse_url($url);return $p!==false&&($p['scheme']??'')==='https'&&strtolower($p['host']??'')==='graph.microsoft.com'&&!isset($p['port'])&&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['fragment'])&&preg_match("#^/v1\\.0/me/(?:messages(?:/[^/]+)?|mailFolders(?:/[^/]+|\\('[^']+'\\))/messages)$#D",$p['path']??'')===1;
}
function mailHttp(string $kind,array $params,?callable $transport=null):array {
 if(!in_array($kind,['token','me','messages','message'],true))throw new LogicException('mail endpoint');
 if(in_array($kind,['messages','message'],true)&&!mailGraphUrl($params['url']??''))throw new MailRemoteError(502,'provider');
 if($transport){$result=$transport($kind,$params);if(!is_array($result))throw new MailRemoteError(502);return $result;}
 $url=match($kind){'token'=>'https://login.microsoftonline.com/consumers/oauth2/v2.0/token','me'=>'https://graph.microsoft.com/v1.0/me?$select=id,mail,userPrincipalName',default=>$params['url']};
 $headers=['Accept: application/json'];if($kind!=='token'){$token=$params['access_token']??'';if(!is_string($token)||$token===''||preg_match('/[\r\n]/',$token))throw new MailRemoteError(401,'needs_auth');$headers[]='Authorization: Bearer '.$token;$headers[]='Prefer: outlook.body-content-type="text", IdType="ImmutableId"';}
 $body='';$tooLarge=false;$retry=30;$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>$headers,CURLOPT_WRITEFUNCTION=>static function($h,string $part)use(&$body,&$tooLarge):int{if(strlen($body)+strlen($part)>3000000){$tooLarge=true;return 0;}$body.=$part;return strlen($part);},CURLOPT_HEADERFUNCTION=>static function($h,string $line)use(&$retry):int{if(preg_match('/^Retry-After:\s*(\d+)/i',$line,$m))$retry=max(1,min(3600,(int)$m[1]));return strlen($line);}]);
 if($kind==='token')curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params,'','&',PHP_QUERY_RFC3986)]);
 $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($ok===false)throw new MailRemoteError(0,$tooLarge?'response_too_large':'transport');
 try{$data=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(Throwable){throw new MailRemoteError($status,'provider',$retry);}
 if(!is_array($data))throw new MailRemoteError($status,'provider',$retry);
 if($status<200||$status>=300){$code=is_string($data['error']??null)?$data['error']:($data['error']['code']??'provider');if(!is_string($code)||!preg_match('/^[A-Za-z0-9_]{1,80}$/D',$code))$code='provider';throw new MailRemoteError($status,$code,$retry);}return $data;
}
function mailTokenFields(array $response,bool $initial=false):array {
 $access=$response['access_token']??null;$refresh=$response['refresh_token']??null;$expires=$response['expires_in']??null;
 if(!is_string($access)||$access===''||strlen($access)>65536||preg_match('/[\r\n]/',$access)||strtolower((string)($response['token_type']??''))!=='bearer'||!is_numeric($expires)||(int)$expires<60||($initial&&(!is_string($refresh)||$refresh===''))||($refresh!==null&&(!is_string($refresh)||strlen($refresh)>65536)))throw new MailRemoteError(502,'provider');
 if(isset($response['scope'])){$scopes=array_map(fn($s)=>strtolower(substr($s,strrpos($s,'/')!==false?strrpos($s,'/')+1:0)),explode(' ',(string)$response['scope']));if(!in_array('mail.read',$scopes,true))throw new MailRemoteError(403,'forbidden');}
 return ['access'=>$access,'refresh'=>$refresh,'until'=>microtime(true)+min(86400,(int)$expires)];
}
function mailRemoteReason(MailRemoteError $e):string {
 if(in_array($e->providerCode,['invalid_grant','interaction_required','consent_required','needs_auth'],true))return 'needs_auth';
 if($e->providerCode==='invalid_client')return 'invalid_client';if($e->httpStatus===401)return 'needs_auth';if($e->providerCode==='configuration_changed')return 'configuration_changed';if($e->httpStatus===429)return 'throttled';if($e->httpStatus===403)return 'forbidden';return $e->httpStatus===0?'transport':'provider';
}
