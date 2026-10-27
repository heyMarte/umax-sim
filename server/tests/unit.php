<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function rejects(callable $fn): void {try{$fn();}catch(ApiError $e){check($e->status===422,'错误状态');return;}throw new RuntimeException('无效输入未拒绝');}
check(amount(['amount'=>'10.50'])==='10.50','保留金额小数');
foreach(['-1','1e3','1.234','99999999999','NaN'] as $bad)rejects(fn()=>amount(['amount'=>$bad]));
check(dateField(['next_check'=>''],'next_check')===null,'空日期');
check(dateField(['next_check'=>'2028-02-29'],'next_check')==='2028-02-29','闰日');
rejects(fn()=>dateField(['next_check'=>'2026-02-29'],'next_check'));
rejects(fn()=>intField(['subscription_id'=>'7'],'subscription_id'));
check(intField(['subscription_id'=>null],'subscription_id',true)===null,'未知订阅');
check(stringField(['shop'=>'测试店铺'],'shop',4)==='测试店铺','中文长度');
rejects(fn()=>stringField(['shop'=>'测试店铺'],'shop',3));
check(str_contains(duplicateWriteMessage('/api/shops/42'),'店铺'),'店铺冲突给店铺提示');
check(!str_contains(duplicateWriteMessage('/api/shops/42'),'号码'),'店铺冲突不混入号码');
check(str_contains(duplicateWriteMessage('/api/sims'),'号码'),'其他对象保留原提示');
echo "PASS: PHP field validation\n";
