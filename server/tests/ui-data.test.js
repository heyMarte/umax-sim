import test from 'node:test';
import assert from 'node:assert/strict';
import {subscriptions,sourceFor,boundSim,relativeTime,newAlertKeys,metricValue} from '../public/static/ui-data.js';
test('invalid SIM status does not render raw JSON or invent a slot',()=>{assert.deepEqual(subscriptions({subscriptions:'broken'}),[]);assert.deepEqual(subscriptions({subscriptions:'{"error":true}'}),[]);const s=subscriptions({subscriptions:'[{"state":"permission_missing"}]'})[0];assert.equal(s.sub,null);assert.equal(s.slot,null);assert.equal(s.state,'需要电话权限')});
test('same subscription IDs on two phones keep distinct identity',()=>{const ds=[{id:1,name:'菲律宾 vivo',subscriptions:'[{"subscription_id":7,"slot":1}]'},{id:2,name:'中国 Redmi',subscriptions:'[{"subscription_id":7,"slot":2}]'}];assert.equal(sourceFor({device_id:2,subscription_id:7},ds).label,'中国 Redmi · 卡槽 2');assert.equal(sourceFor({device_id:1,subscription_id:7},ds).label,'菲律宾 vivo · 卡槽 1');const sims=[{id:3,device_id:1,subscription_id:7}];assert.equal(boundSim(ds[1],7,sims),null);assert.equal(boundSim(ds[0],7,sims).id,3)});
test('unknown subscription is never guessed from an empty value',()=>{assert.equal(boundSim({id:1},null,[{device_id:1,subscription_id:0}]),null);assert.equal(sourceFor({device_id:1,subscription_id:null},[{id:1,name:'vivo',subscriptions:'[{"subscription_id":0,"slot":1}]'}]).label,'vivo · 卡槽待确认')});
test('relative sync times distinguish missing, current and stale data',()=>{assert.equal(relativeTime(null,1000),'尚未同步');assert.equal(relativeTime(990,1000),'刚刚');assert.equal(relativeTime(800,1000),'3 分钟前');assert.equal(relativeTime(2000,1000),'刚刚')});

// Unknown cellular readings must never be interpreted as a zero-strength signal.
assert.match(subscriptions({subscriptions:[{subscription_id:1,service_state:'out_of_service',signal_level:null}]})[0].health,/无蜂窝服务.*信号未获取/);
assert.match(subscriptions({subscriptions:[{subscription_id:1,service_state:'in_service',signal_level:0,signal_dbm:-115,roaming:true}]})[0].health,/信号 0\/4.*-115 dBm.*漫游中/);

test('notification dedupe uses stable keys and excludes muted alerts',()=>{const seen=new Set(['device:offline:1']);const items=[{key:'device:offline:1'},{key:'device:offline:2'},{key:'device:low:3',muted_until:99999}];assert.deepEqual(newAlertKeys(items,seen),['device:offline:2']);seen.add('device:offline:2');assert.deepEqual(newAlertKeys(items,seen),[]);assert.deepEqual(newAlertKeys([],seen),[])});

test('telemetry distinguishes unknown, false, zero, and old snapshots',()=>{assert.equal(metricValue('battery_percent',null),'未获取');assert.equal(metricValue('battery_percent',0),'0%');assert.equal(metricValue('charging',false),'未连接充电');assert.equal(metricValue('internet_validated',false),'系统未确认互联网');assert.equal(metricValue('last_error',''),'无');assert.equal(metricValue('network_transport','wifi'),'Wi-Fi')});

test('device key drafts are six secure decimal digits, preserve zeros and reject biased tail',async()=>{
 const {generateDeviceKey}=await import('../public/static/ui-data.js');
 const {webcrypto}=await import('node:crypto');
 assert.match(generateDeviceKey(webcrypto),/^[0-9]{6}$/);
 assert.equal(generateDeviceKey({getRandomValues:a=>{a[0]=0;return a}}),'000000');
 const draws=[4294967295,999999];let calls=0;
 assert.equal(generateDeviceKey({getRandomValues:a=>{a[0]=draws[calls++];return a}}),'999999');assert.equal(calls,2);
 assert.throws(()=>generateDeviceKey({}),/安全生成/);
});
test('remote refresh only claims completion after explicit acknowledgement',async()=>{
 const {refreshLabel}=await import('../public/static/ui-data.js');
 assert.match(refreshLabel({requested_at:900,acknowledged_at:null},950),/等待手机/);
 assert.match(refreshLabel({requested_at:900,acknowledged_at:null},1100),/超时/);
 assert.match(refreshLabel({requested_at:900,acknowledged_at:930},950),/已响应/);
});
test('communication is not proof the collector or actual SMS chain is healthy',async()=>{
 const {deviceReadiness}=await import('../public/static/ui-data.js');
 let rows=deviceReadiness({id:1,online:true,telemetry:{fresh:true,data:{collector_running:false}},subscriptions:[]});
 assert.match(rows[0].value,/未运行/);assert.notEqual(rows[3].tone,'good');
 rows=deviceReadiness({id:1,online:false,telemetry:{fresh:false,data:{collector_running:true}},subscriptions:[]});
 assert.equal(rows[0].value,'状态待确认');
});

test('same-name shop choices remain distinguishable without forbidding names',async()=>{
 const {shopChoiceLabel}=await import('../public/static/ui-data.js');
 const shops=[{id:11,name:'Same',platform:'tiktok',linked_sims:[{phone:'+639171234567'}]},{id:12,name:'Same',platform:'tiktok'},{id:13,name:'Other',platform:'tiktok'}];
 assert.equal(shopChoiceLabel(shops[2],shops),'Other');
 assert.match(shopChoiceLabel(shops[0],shops),/Same.*\+639171234567.*#11/);
 assert.match(shopChoiceLabel(shops[1],shops),/Same.*#12/);
 assert.notEqual(shopChoiceLabel(shops[0],shops),shopChoiceLabel(shops[1],shops));
 assert.equal(shopChoiceLabel({id:11,name:'<b>Label</b>',platform:'tiktok'},[]),'<b>Label</b>');
});
