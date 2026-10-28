<?php
declare(strict_types=1);
function mailAccessToken(array &$account,array $config,?callable $transport=null,bool $force=false):string {
 if(!$force&&!empty($account['access_cipher'])&&(float)$account['access_until']>microtime(true)+60)return openSecret($account['access_cipher']);
 if(empty($account['refresh_cipher'])||$account['client_id']!==$config['client_id'])throw new MailRemoteError(401,'needs_auth');
 $fields=mailTokenFields(mailHttp('token',['client_id'=>$config['client_id'],'client_secret'=>openSecret($config['client_secret_cipher']),'grant_type'=>'refresh_token','refresh_token'=>openSecret($account['refresh_cipher']),'scope'=>mailScopes()],$transport));
 $refresh=$fields['refresh']!==null?sealSecret($fields['refresh']):$account['refresh_cipher'];$access=sealSecret($fields['access']);
 db()->beginTransaction();try{$liveConfig=query('SELECT client_id FROM mail_config WHERE id=1 FOR UPDATE')[0];$live=query('SELECT generation,status FROM mail_accounts WHERE id=? FOR UPDATE',[$account['id']])[0]??null;if(!$live||(int)$live['generation']!==(int)$account['generation']||$liveConfig['client_id']!==$config['client_id']||!in_array($live['status'],['connected','retry'],true))throw new MailRemoteError(409,'configuration_changed');query('UPDATE mail_accounts SET refresh_cipher=?,access_cipher=?,access_until=? WHERE id=?',[$refresh,$access,$fields['until'],$account['id']]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 $account['refresh_cipher']=$refresh;$account['access_cipher']=$access;$account['access_until']=$fields['until'];return $fields['access'];
}
function mailGetPage(string $url,array &$account,array $config,?callable $transport=null,string $kind='messages'):array {
 $token=mailAccessToken($account,$config,$transport);try{return mailHttp($kind,['url'=>$url,'access_token'=>$token],$transport);}catch(MailRemoteError $e){if($e->httpStatus!==401)throw $e;return mailHttp($kind,['url'=>$url,'access_token'=>mailAccessToken($account,$config,$transport,true)],$transport);}
}
function mailFirstPage(string $folder,float $from):string {
 return 'https://graph.microsoft.com/v1.0/me/mailFolders/'.$folder.'/messages?'.http_build_query(['$select'=>'id,subject,from,receivedDateTime,bodyPreview','$filter'=>'receivedDateTime ge '.gmdate('Y-m-d\TH:i:s\Z',(int)ceil($from)),'$orderby'=>'receivedDateTime desc','$top'=>50],'','&',PHP_QUERY_RFC3986);
}
function mailStorePage(array $account,array $items,float $from):int {
 $saved=0;foreach($items as $item){if(!is_array($item)||!is_string($item['id']??null)||strlen($item['id'])>4096||!is_string($item['receivedDateTime']??null))continue;
  try{$received=(float)(new DateTimeImmutable($item['receivedDateTime']))->getTimestamp();}catch(Throwable){continue;}if($received<$from||$received>microtime(true)+60)continue;
  $key=hash('sha256',$item['id']);if(query('SELECT message_key FROM mail_seen WHERE account_id=? AND message_key=?',[$account['id'],$key]))continue;
  $subject=is_string($item['subject']??null)?mb_substr($item['subject'],0,300):'';$body=is_string($item['body']['content']??null)?$item['body']['content']:'';$sender=is_string($item['from']['emailAddress']['address']??null)?mb_substr($item['from']['emailAddress']['address'],0,254):'';$code=mailExtractCode($subject,$body);
  query('INSERT IGNORE INTO mail_seen(account_id,message_key,seen_at) VALUES(?,?,?)',[$account['id'],$key,microtime(true)]);if($code==='')continue;
  $shop=$account['shop_id']?query('SELECT name FROM shops WHERE id=?',[$account['shop_id']])[0]['name']??'':'';
  query('INSERT IGNORE INTO mail_codes(account_id,message_key,email_snapshot,sender,subject,code,platform,shop_snapshot,received_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$account['id'],$key,$account['email'],$sender,$subject,$code,platform_message_infer($sender,$subject.' '.mailBodyText($body)),$shop,$received,microtime(true)]);$saved++;
 }return $saved;
}
function mailPollAccount(array $account,?callable $transport=null):int {
 $config=mailConfig();if(!mailReady($config))return 0;if($account['client_id']!==$config['client_id'])throw new MailRemoteError(401,'needs_auth');
 $cursor=json_decode($account['sync_cursor']??'{}',true);if(!is_array($cursor))$cursor=[];$saved=0;$backlog=false;
 foreach(['inbox','junkemail'] as $folder){$state=$cursor[$folder]??[];$watermark=(float)($state['watermark']??$account['connected_at']);$from=max((float)$account['connected_at'],$watermark-120);$started=microtime(true);$next=$state['url']??null;if(is_string($next)&&mailGraphUrl($next)){$url=$next;$from=max((float)$account['connected_at'],(float)($state['from']??$from));$started=(float)($state['started']??$started);}else $url=mailFirstPage($folder,$from);
  for($page=0;$page<2;$page++){
   $response=mailGetPage($url,$account,$config,$transport);if(!isset($response['value'])||!is_array($response['value'])||!array_is_list($response['value']))throw new MailRemoteError(502);$next=$response['@odata.nextLink']??null;if($next!==null&&(!is_string($next)||!mailGraphUrl($next)))throw new MailRemoteError(502);
   // Keep list pages small. Expand only new authentication candidates, never newsletter bodies.
   foreach($response['value'] as &$item){if(!is_array($item)||isset($item['body'])||!is_string($item['id']??null)||strlen($item['id'])>4096)continue;$preview=is_string($item['bodyPreview']??null)?$item['bodyPreview']:'';$subject=is_string($item['subject']??null)?$item['subject']:'';$item['body']=['content'=>$preview];if(mailExtractCode($subject,$preview)!==''||!preg_match('/code|verif|security|sign.?in|log.?in|otp|验证|校验|登录/iu',$subject.' '.$preview)||query('SELECT message_key FROM mail_seen WHERE account_id=? AND message_key=?',[$account['id'],hash('sha256',$item['id'])]))continue;
    try{$detail=mailGetPage('https://graph.microsoft.com/v1.0/me/messages/'.rawurlencode($item['id']).'?$select=body',$account,$config,$transport,'message');if(is_string($detail['body']['content']??null))$item['body']=$detail['body'];}catch(MailRemoteError $e){if($e->providerCode!=='response_too_large')throw $e;}
   }unset($item);
   db()->beginTransaction();try{query('SELECT client_id FROM mail_config WHERE id=1 FOR UPDATE');$live=query('SELECT generation,status,client_id FROM mail_accounts WHERE id=? FOR UPDATE',[$account['id']])[0]??null;if(!$live||(int)$live['generation']!==(int)$account['generation']||$live['client_id']!==$config['client_id']||!in_array($live['status'],['connected','retry'],true))throw new MailRemoteError(409,'configuration_changed');$saved+=mailStorePage($account,$response['value'],$from);if($next)$cursor[$folder]=['url'=>$next,'from'=>$from,'started'=>$started,'watermark'=>$watermark];else $cursor[$folder]=['watermark'=>$started];query('UPDATE mail_accounts SET sync_cursor=? WHERE id=?',[json_encode($cursor),$account['id']]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
   if(!$next)break;$url=$next;
  }
  if(isset($cursor[$folder]['url']))$backlog=true;
 }
 query("UPDATE mail_accounts SET status='connected',last_sync=IF(?,last_sync,?),next_sync=?,error_code=? WHERE id=? AND generation=?",[$backlog?1:0,microtime(true),microtime(true)+($backlog?1:30),$backlog?'backlog':null,$account['id'],$account['generation']]);return $saved;
}
function mailRun(int $limit=5,?callable $transport=null):array {
 query('UPDATE mail_config SET worker_at=? WHERE id=1',[microtime(true)]);$processed=0;$saved=0;$failed=0;if(!mailReady(mailConfig()))return ['processed'=>0,'saved'=>0,'failed'=>0];
 $rows=query("SELECT id FROM mail_accounts WHERE status IN ('connected','retry') AND refresh_cipher IS NOT NULL AND next_sync<=? ORDER BY next_sync,id LIMIT ".max(1,min(250,$limit)),[microtime(true)]);
 foreach($rows as $row){$id=(int)$row['id'];if(!mailLock('account-'.$id))continue;
  try{$account=query("SELECT * FROM mail_accounts WHERE id=? AND status IN ('connected','retry') AND next_sync<=?",[$id,microtime(true)])[0]??null;if(!$account)continue;$processed++;try{$saved+=mailPollAccount($account,$transport);}catch(MailRemoteError $e){$reason=mailRemoteReason($e);$auth=$reason==='needs_auth'||$reason==='forbidden';query('UPDATE mail_accounts SET status=?,error_code=?,next_sync=?,access_cipher=IF(?,NULL,access_cipher),refresh_cipher=IF(?,NULL,refresh_cipher) WHERE id=? AND generation=?',[$auth?'needs_auth':'retry',$reason,microtime(true)+($e->httpStatus===429?max(1,$e->retryAfter):60),$auth?1:0,$auth?1:0,$id,$account['generation']]);$failed++;}catch(Throwable){query("UPDATE mail_accounts SET status='retry',error_code='provider',next_sync=? WHERE id=? AND generation=?",[microtime(true)+60,$id,$account['generation']]);$failed++;}
  }finally{mailUnlock('account-'.$id);}
 }return ['processed'=>$processed,'saved'=>$saved,'failed'=>$failed];
}
