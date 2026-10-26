import test from 'node:test';
import assert from 'node:assert/strict';
import {simAssociation,deviceReadiness} from '../public/static/ui-data.js';
const slot=(state,reason)=>({metrics:{association_state:state,association_reason:reason,phone_hint:'+639171234567'}});
test('automatic association and manual association have clear distinct feedback',()=>{
 assert.deepEqual(simAssociation(slot('associated','matched'),{phone:'+639171234567'}),{label:'已自动关联',tone:'good',detail:'已匹配号码档案，后续短信按此号码归属'});
 assert.equal(simAssociation(slot('associated','manual'),{phone:'+639171234567'}).label,'已绑定');
});
test('card reading failure does not present the retained binding as healthy',()=>{
 const result=simAssociation(slot('suspended','not_ready'),{phone:'+639171234567'});
 assert.equal(result.tone,'warn');assert.equal(result.label,'等待 SIM 就绪');assert.match(result.detail,/暂停|未就绪/);
 assert.equal(simAssociation(slot('conflict','target_bound'),null).label,'需核对绑定');
 assert.match(simAssociation(slot('conflict','target_bound'),null).detail,/另一台手机/);
 assert.match(simAssociation(slot('pending','phone_unavailable'),null).detail,/人工确认.*无需发短信/);
});
test('unavailable permissions are not displayed as zero SIMs or complete binding',()=>{
 const d={id:1,online:true,subscriptions:[],telemetry:{fresh:true,data:{subscriptions_status:'permission_denied'}}};
 const state=deviceReadiness(d,[])[2];assert.equal(state.tone,'warn');assert.match(state.value,/电话权限/);assert.doesNotMatch(state.value,/识别 0/);
 d.telemetry.data.subscriptions_status='unavailable';assert.match(deviceReadiness(d,[])[2].value,/未能读取/);
});
test('a suspended current SIM cannot make readiness green even if the old binding remains',()=>{
 const d={id:1,online:true,subscriptions:[{subscription_id:7,slot:1,association_state:'suspended',association_reason:'not_ready'}],telemetry:{fresh:true,data:{subscriptions_status:'ok'}}};
 assert.equal(deviceReadiness(d,[{device_id:1,subscription_id:7}])[2].tone,'warn');
});
