<?php
declare(strict_types=1);
/** Default keys remain stable for existing rows; deployments may append labels. */
function platform_catalog():array {
 $catalog=['tiktok'=>['label'=>'TikTok','keywords'=>['tiktok']],'shopee'=>['label'=>'Shopee','keywords'=>['shopee']],'lazada'=>['label'=>'Lazada','keywords'=>['lazada']]];
 $custom=function_exists('settings')?(settings()['platform_catalog']??[]):[];
 if(!is_array($custom)||($custom!==[]&&array_is_list($custom)))throw new RuntimeException('platform_catalog必须是以平台标识为键的配置');
 foreach($custom as $key=>$entry){
  if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_-]{0,15}$/D',$key)||in_array($key,['all','unknown'],true)||!is_array($entry))throw new RuntimeException('平台标识必须为1至16位小写字母数字、下划线或短横线');
  $label=$entry['label']??null;$keywords=$entry['keywords']??($catalog[$key]['keywords']??[]);
  if(!is_string($label)||trim($label)===''||mb_strlen($label)>80||str_contains($label,"\0")||!is_array($keywords)||!array_is_list($keywords)||count($keywords)>8)throw new RuntimeException('平台名称或来源关键词配置无效');
  foreach($keywords as $word)if(!is_string($word)||trim($word)===''||mb_strlen($word)>64||str_contains($word,"\0"))throw new RuntimeException('平台来源关键词配置无效');
  $catalog[$key]=['label'=>trim($label),'keywords'=>$keywords];
 }
 if(count($catalog)>16)throw new RuntimeException('平台目录最多16项');
 return $catalog;
}
function platform_keys():array{return array_keys(platform_catalog());}
function platform_options():array{$out=[];foreach(platform_catalog() as $key=>$entry)$out[]=['value'=>$key,'label'=>$entry['label'],'glyph'=>mb_substr($entry['label'],0,1)];return $out;}
function platform_stored_key(mixed $key):bool{return is_string($key)&&preg_match('/^[a-z][a-z0-9_-]{0,15}$/D',$key)===1&&!in_array($key,['all','unknown'],true);}
