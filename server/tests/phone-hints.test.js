import test from 'node:test';
import assert from 'node:assert/strict';
import {phoneHintSuggestion} from '../public/static/ui-data.js';
const device={id:1,online:true,heartbeat:1000,can_manage:true,telemetry:{fresh:true,subscriptions_observed_at:1000,data:{subscriptions_status:'ok'}},subscriptions:[{subscription_id:7,slot:1,sim_state:5,phone_hint:'+639171234567',phone_hint_source:'android_sim'}]};
test('only a fresh unique real SIM number is a suggestion, never a guessed binding',()=>{
 assert.deepEqual(phoneHintSuggestion(device,{phone:'09171234567',country:'PH'},[],1010),{subscription_id:7,phone:'+639171234567'});
 assert.equal(phoneHintSuggestion({...device,online:false},{phone:'+639171234567'},[],1010),null);
 assert.equal(phoneHintSuggestion(device,{phone:'+639171234567'},[],1300),null);
 assert.equal(phoneHintSuggestion({...device,can_manage:false},{phone:'+639171234567'},[],1010),null);
 assert.equal(phoneHintSuggestion(device,{phone:'+639171234567'},[{device_id:1,subscription_id:7}],1010),null);
 assert.equal(phoneHintSuggestion({...device,subscriptions:[...device.subscriptions,{...device.subscriptions[0],subscription_id:8}]},{phone:'+639171234567'},[],1010),null);
 assert.equal(phoneHintSuggestion({...device,subscriptions:[{subscription_id:null,phone_hint:'+639171234567',phone_hint_source:'android_sim'}]},{phone:'+639171234567'},[],1010),null);
 assert.equal(phoneHintSuggestion({...device,subscriptions:[{subscription_id:7,phone_hint:'+639171234567',phone_hint_source:'guess'}]},{phone:'+639171234567'},[],1010),null);
});

test('SMS activity cannot make stale, nonready or ambiguous SIM hints fresh',()=>{
 const s={phone:'+639171234567'};
 assert.equal(phoneHintSuggestion({...device,heartbeat:1300,telemetry:{...device.telemetry,subscriptions_observed_at:1}},s,[],1301),null);
 assert.equal(phoneHintSuggestion({...device,telemetry:{...device.telemetry,fresh:false}},s,[],1010),null);
 assert.equal(phoneHintSuggestion({...device,subscriptions:[{...device.subscriptions[0],sim_state:1}]},s,[],1010),null);
 assert.equal(phoneHintSuggestion({...device,subscriptions:[...device.subscriptions,{subscription_id:8,slot:1,sim_state:5}]},s,[],1010),null);
});

test('not having sent a test SMS is optional and never a required binding issue',async()=>{const {deviceReadiness}=await import('../public/static/ui-data.js');const check=deviceReadiness({id:1,online:true,subscriptions:[]})[3];assert.equal(check.optional,true);assert.equal(check.tone,'off');assert.match(check.value,/可选|不影响绑定/)});

test('legacy Android active subscription permits a scoped hint without claiming physical readiness',async()=>{
 const {subscriptions}=await import('../public/static/ui-data.js');const d={...device,subscriptions:[{...device.subscriptions[0],sim_state:null,subscription_active:true,sim_state_reason:'unsupported_android_version'}]};
 assert.deepEqual(phoneHintSuggestion(d,{phone:'+639171234567'},[],1010),{subscription_id:7,phone:'+639171234567'});
 assert.match(subscriptions(d)[0].state,/系统未提供.*状态/);
 assert.equal(phoneHintSuggestion({...d,subscriptions:[{...d.subscriptions[0],subscription_active:false}]},{phone:'+639171234567'},[],1010),null);
 assert.equal(phoneHintSuggestion({...d,subscriptions:[{...d.subscriptions[0],sim_state:2}]},{phone:'+639171234567'},[],1010),null);
});
