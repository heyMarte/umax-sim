<?php
declare(strict_types=1);
require_once __DIR__.'/platform_catalog.php';
/** Canonical, ordered membership tags. NULL/legacy shops have no declared platforms. */
function platform_normalize(mixed $value): array {
    if(is_string($value))$value=json_decode($value,true);
    if(!is_array($value))return [];
    return array_values(array_filter(platform_keys(),fn($key)=>in_array($key,$value,true)));
}
function platform_matches(mixed $value,string $filter='all'): bool {
    $tags=platform_normalize($value);
    return $filter==='all'||($filter==='unknown'?!$tags:in_array($filter,$tags,true));
}
/** Identification is independent of number membership: ambiguous or generic OTP stays unknown. */
function platform_message_infer(string $sender,string $body): ?string {
    $matches=[];
    foreach(platform_catalog() as $key=>$entry){
        foreach($entry['keywords'] as $word)if(preg_match('/(?<![a-z0-9])'.preg_quote($word,'/').'(?![a-z0-9])/iu',$sender.' '.$body)){$matches[]=$key;break;}
    }
    return count($matches)===1?$matches[0]:null;
}
function platform_enrich_shop(array $row): array {$row['platforms']=isset($row['name'])&&($row['platform']??'')!==''&&platform_stored_key($row['platform'])?[$row['platform']]:platform_normalize($row['platforms']??null);return $row;}
function platform_enrich_sim(array $row): array {return platform_enrich_shop($row);}
function platform_enrich_message(array $row): array {
    $row=platform_enrich_shop($row);
    $row['inferred_platform']=platform_stored_key($row['platform']??null)?$row['platform']:platform_message_infer((string)($row['sender']??''),(string)($row['body']??''));
    $row['platform_label']=platform_catalog()[$row['inferred_platform']]['label']??($row['inferred_platform']?:'平台待确认');
    return $row;
}

function platform_filter(): string {
    $filter=$_GET['platform']??'all';
    if(!is_string($filter)||!in_array($filter,array_merge(['all','unknown'],platform_keys()),true))fail(422,'平台筛选条件无效');
    return $filter;
}
function platform_input(array $body, mixed $fallback=null): ?string {
    if(!array_key_exists('platforms',$body))return $fallback;
    $tags=$body['platforms'];
    if(!is_array($tags)||!array_is_list($tags)||count($tags)>count(platform_keys()))fail(422,'请选择有效的平台');
    foreach($tags as $tag)if(!is_string($tag)||!in_array($tag,platform_keys(),true))fail(422,'请选择有效的平台');
    return json_encode(platform_normalize($tags));
}
function platform_attach(array $row): array {
    $shop=$row['shop_id']??null;
    $row['platforms']=$shop?platform_normalize(query('SELECT platforms FROM shops WHERE id=?',[$shop])[0]['platforms']??null):[];
    return $row;
}
