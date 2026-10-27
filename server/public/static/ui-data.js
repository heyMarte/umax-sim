export function subscriptions(device) {
  let rows=device?.subscriptions;
  try { if(typeof rows==='string') rows=JSON.parse(rows); } catch { return []; }
  if(!Array.isArray(rows)) return [];
  return rows.filter(x=>x&&typeof x==='object').map(x=>({
    metrics:x,
    health:[({in_service:"有蜂窝服务",out_of_service:"无蜂窝服务",emergency_only:"仅限紧急呼叫",power_off:"无线电关闭"})[x.service_state]||"服务状态未获取",x.signal_level==null?"信号未获取":`信号 ${x.signal_level}/4`,x.signal_dbm==null?null:`${x.signal_dbm} dBm`,x.signal_age_seconds>180?"信号为旧缓存":null,x.roaming===true?"漫游中":null].filter(Boolean).join(" · "),
    sub:Number.isInteger(x.subscription_id)&&x.subscription_id>=0?x.subscription_id:null,
    slot:Number.isInteger(x.slot)&&x.slot>0?x.slot:null,
    carrier:typeof x.carrier==='string'&&x.carrier.trim()?x.carrier:'运营商未上报',
    state:x.state==='permission_missing'?'需要电话权限':x.sim_state==null&&x.subscription_active===true&&x.sim_state_reason==='unsupported_android_version'?'已识别 SIM，系统未提供单卡状态':({1:'未插卡',2:'需要 SIM PIN',3:'需要 PUK',4:'卡已锁定',5:'卡已识别',6:'卡未就绪',8:'卡片异常'})[x.sim_state]||'卡状态待确认'
  }));
}
export function sourceFor(message,devices) {
  const device=devices.find(d=>Number(d.id)===Number(message.device_id));
  const slot=subscriptions(device).find(s=>s.sub!==null&&s.sub===message.subscription_id);
  return {device,slot,label:[device?.name||'设备未记录',slot?.slot?`卡槽 ${slot.slot}`:'卡槽待确认'].join(' · ')};
}
export function boundSim(device,sub,sims) {
  if(sub===null)return null;
  return sims.find(s=>Number(s.device_id)===Number(device.id)&&Number(s.subscription_id)===sub)||null;
}
export function relativeTime(timestamp,now=Date.now()/1000) {
  if(!timestamp)return '尚未同步';
  const seconds=Math.max(0,now-Number(timestamp));
  if(seconds<60)return '刚刚';
  if(seconds<3600)return `${Math.floor(seconds/60)} 分钟前`;
  if(seconds<86400)return `${Math.floor(seconds/3600)} 小时前`;
  return `${Math.floor(seconds/86400)} 天前`;
}
export function fullTime(timestamp) {
  return timestamp?new Date(Number(timestamp)*1000).toLocaleString('zh-CN',{hour12:false}):'尚无记录';
}

export function newAlertKeys(items,seen) {
 const active=(items||[]).filter(a=>!a.muted_until);
 return active.map(a=>String(a.key)).filter(k=>!seen.has(k));
}

export function metricValue(key,value) {
 if(value===null||value===undefined)return '未获取';
 if(key==='last_error')return value||'无';
 if(key==='network_transport')return ({wifi:'Wi-Fi',cellular:'移动网络',ethernet:'有线网络',vpn:'VPN',none:'上报时未联网',other:'其他网络',unknown:'未获取'})[value]||'未获取';
 if(key==='internet_validated')return value?'系统确认可联网':'系统未确认互联网';
 if(key==='charging')return value?'正在充电':'未连接充电';
 if(key==='battery_optimization_ignored')return value?'已取消限制':'仍受系统优化（不等于故障）';
 if(key==='power_save')return value?'省电模式开启':'省电模式关闭';
 if(key==='battery_percent')return value+'%';
 if(key==='battery_temp_c')return value+' °C';
 if(key==='sync_duration_ms')return value+' ms';
 if(key==='uptime_seconds'){const h=Math.floor(value/3600);return h>=24?Math.floor(h/24)+' 天 '+h%24+' 小时':h+' 小时 '+Math.floor(value%3600/60)+' 分钟';}
 if(key==='oldest_pending_age_seconds')return value===0?'无积压':value<60?value+' 秒':Math.floor(value/60)+' 分钟';
 if(key.endsWith('_at'))return value?fullTime(value):'尚无记录';
 if(typeof value==='boolean')return value?'是':'否';
 return String(value)||'未获取';
}

export function generateDeviceKey(cryptoApi=globalThis.crypto) {
 if(!cryptoApi?.getRandomValues)throw Error('浏览器无法安全生成密钥，请使用 HTTPS 访问后台');
 const sample=new Uint32Array(1),limit=Math.floor(0x100000000/1000000)*1000000;
 do{cryptoApi.getRandomValues(sample)}while(sample[0]>=limit);
 return String(sample[0]%1000000).padStart(6,'0');
}

export function refreshLabel(request,now=Date.now()/1000){
 if(!request)return '尚未请求手机上报';
 if(request.acknowledged_at)return '手机已响应上报 · '+fullTime(request.acknowledged_at);
 return now-request.requested_at>120?'请求超时 · 手机可能离线、受系统限制或App版本较旧':'等待手机上报 · 最长约2分钟';
}
export function deviceReadiness(d,sims=[]){
 const t=d.telemetry, m=t?.data||{},fresh=!!d.online&&!!t?.fresh;
 const slotRows=subscriptions(d).filter(s=>s.sub!==null),bound=sims.filter(s=>Number(s.device_id)===Number(d.id));
 const readStatus=m.subscriptions_status,blocked=slotRows.some(s=>['suspended','conflict'].includes(s.metrics.association_state));
 const simValue=readStatus==='permission_denied'?'缺少电话权限，无法读取当前 SIM':readStatus==='unavailable'?'本次未能读取 SIM，保留原记录待同步':`识别 ${slotRows.length} 张 SIM · 已关联 ${bound.length} 个号码`;
 return [
  {label:'采集服务',tone:!fresh?'warn':m.collector_running===true&&m.permissions?.sms===true?'good':'warn',value:!fresh?'状态待确认':m.collector_running===false?'服务未运行，可能仅补传任务通信':m.collector_running===true?(m.permissions?.sms===false?'缺少短信权限':'服务运行中'):'旧版App未上报服务状态'},
  {label:'后台通信',tone:d.online?'good':'bad',value:d.revoked?'设备已停用':d.online?'最近通信正常':'尚无近期通信'},
  {label:'SIM与绑定',tone:fresh&&!blocked&&!['permission_denied','unavailable'].includes(readStatus)&&slotRows.length&&bound.length===slotRows.length?'good':'warn',value:simValue},
  {label:'收码检查（可选）',optional:true,tone:d.receive_check?.assigned?'good':'off',value:d.receive_check?.assigned?'最近收码测试通过（不代表永远可达）':d.receive_check?.received?'已有测试短信上传记录':d.receive_check?.expired?'此前测试未完成，不影响号码绑定':'可选检查，不影响绑定；可用店铺实际验证码确认'}
 ];
}

export function simAssociation(slot,bound){
 const m=slot?.metrics||{},state=m.association_state,reason=m.association_reason;
 if(state==='associated'&&bound)return reason==='matched'?{label:'已自动关联',tone:'good',detail:'已匹配号码档案，后续短信按此号码归属'}:{label:'已绑定',tone:'good',detail:bound.shop||'号码已绑定，无需短信验证'};
 if(state==='suspended')return {label:reason==='not_ready'?'等待 SIM 就绪':'等待手机同步',tone:'warn',detail:reason==='not_ready'?'SIM 尚未就绪，已暂停使用原绑定归属短信':'暂时无法确认当前 SIM，已暂停使用原绑定归属短信'};
 if(state==='conflict')return {label:'需核对绑定',tone:'warn',detail:reason==='target_bound'?'该号码已绑定另一台手机，请核对后手动换绑':reason==='ambiguous_phone'?'手机上报了重复号码，请人工确认每张 SIM 对应号码':'手机卡信息不明确，请同步后人工确认'};
 if(state==='pending')return {label:reason==='manual_unbound'?'已解除绑定':m.phone_hint?'号码待确认':'待填写号码',tone:'warn',detail:reason==='manual_unbound'?'已按你的操作暂停自动关联，确认绑定后可恢复':reason==='phone_unavailable'?'系统未读取到手机号，可人工确认绑定，无需发短信':'尚未自动匹配可用号码，请确认号码后绑定，无需发短信'};
 return {label:bound?'已绑定':m.phone_hint?'号码待确认':'待识别号码',tone:bound?'good':'warn',detail:bound?bound.shop||'号码已绑定':m.phone_hint?'系统识别的号码，确认后关联':'填写或选择手机号，确认后直接绑定'};
}

// Permission checks deliberately do not infer rights from the operator role.
export function hasPermission(me, permission) {
 return Boolean(me && (me.role === 'admin' || (Array.isArray(me.permissions) && me.permissions.includes(permission))));
}
export function permittedPages(me, pagePermissions) {
 return Object.keys(pagePermissions).filter(page => (Array.isArray(pagePermissions[page]) ? pagePermissions[page] : [pagePermissions[page]]).some(permission => hasPermission(me, permission)));
}

export const platformOptions=[{value:'tiktok',label:'TikTok',glyph:'T'},{value:'shopee',label:'Shopee',glyph:'S'},{value:'lazada',label:'Lazada',glyph:'L'}];
export function configurePlatforms(options){
 if(!Array.isArray(options)||options.length<1||options.length>16)throw Error('平台目录无效');
 const seen=new Set(),next=options.map(p=>{
  if(!p||typeof p.value!=='string'||!/^[a-z][a-z0-9_-]{0,15}$/.test(p.value)||['all','unknown'].includes(p.value)||seen.has(p.value)||typeof p.label!=='string'||!p.label.trim()||p.label.length>160)throw Error('平台目录无效');
  seen.add(p.value);return {value:p.value,label:p.label,glyph:Array.from(p.label)[0]};
 });platformOptions.splice(0,platformOptions.length,...next);
}
// Names are optional display labels; selectors must keep distinct IDs recognizable.
export function shopChoiceLabel(shop,choices=[]){
 const name=String(shop?.name??'');
 const collision=choices.some(s=>String(s.id)!==String(shop.id)&&s.name===shop.name&&String(s.platform||'')===String(shop.platform||''));
 if(!collision)return name;
 const phones=[...new Set((shop.linked_sims||[]).map(s=>s.phone).filter(Boolean))].join(' / ');
 return `${name} · ${phones?phones+' · ':''}#${shop.id}`;
}
export function normalizePlatforms(value){if(typeof value==='string'){try{value=JSON.parse(value)}catch{return []}}return platformOptions.map(p=>p.value).filter(p=>Array.isArray(value)&&value.includes(p))}
export function messagePlatform(message){const key=message.inferred_platform;return platformOptions.find(p=>p.value===key)||(typeof key==='string'&&/^[a-z][a-z0-9_-]{0,15}$/.test(key)?{value:key,label:message.platform_label||key,glyph:Array.from(message.platform_label||key)[0]}:null)}

export function pairingText(id,token,origin=location.origin){const bytes=new TextEncoder().encode(token);let raw="";for(const byte of bytes)raw+=String.fromCharCode(byte);return `SIMMANAGER2\n${origin}\n${btoa(raw)}\n${id}`;}

export function snapshotOutcome(request,now=Date.now()/1000){
 if(request?.acknowledged_at)return 'completed';
 if(request?.status==='timeout'||(Number.isFinite(Number(request?.requested_at))&&now-Number(request.requested_at)>120))return 'timeout';
 return 'pending';
}
export function snapshotCounts(requests=[],now=Date.now()/1000){const out={completed:0,pending:0,timeout:0,total:requests.length};for(const x of requests)out[snapshotOutcome(x.request,now)]++;return out;}

// Shop identity always comes from the already scope-filtered API response.
export function shopsForSim(sim){return Array.isArray(sim?.shops)?sim.shops:sim?.shop_id?[{id:sim.shop_id,name:sim.shop,platforms:normalizePlatforms(sim.platforms)}]:[]}
export function shopMatchesPlatform(shop,platform){return shop?.platform?shop.platform===platform:normalizePlatforms(shop?.platforms).includes(platform)}
export function normalizePhone(value,country='PH'){
 const phone=String(value??'').replace(/[\s()\-]/g,'');
 if(country==='PH'){
  if(/^9\d{9}$/.test(phone))return '+63'+phone;
  if(/^09\d{9}$/.test(phone))return '+63'+phone.slice(1);
  if(/^639\d{9}$/.test(phone))return '+'+phone;
 }
 if(country==='CN'){
  if(/^1[3-9]\d{9}$/.test(phone))return '+86'+phone;
  if(/^861[3-9]\d{9}$/.test(phone))return '+'+phone;
 }
 return phone;
}
// Strip a country code only after an exact, country-matching mobile number check.
export function localPhone(value,country='PH'){
 const canonical=normalizePhone(value,country);
 if(country==='PH'&&/^\+639\d{9}$/.test(canonical))return canonical.slice(3);
 if(country==='CN'&&/^\+861[3-9]\d{9}$/.test(canonical))return canonical.slice(3);
 return String(value??'');
}
export function numberMatches(sim,query){const q=String(query||'').trim().toLowerCase();if(!q)return true;const phoneQuery=q.replace(/[\s()+-]/g,'');return (/^\d+$/.test(phoneQuery)&&(String(sim.phone).replace(/\D/g,'').includes(phoneQuery)||String(sim.phone).replace(/\D/g,'')===normalizePhone(q,sim.country||'PH').replace(/\D/g,'')))||[sim.carrier,sim.location,...shopsForSim(sim).map(s=>s.name)].some(v=>String(v||'').toLowerCase().includes(q));}
export function phoneHintSuggestion(device,sim,sims=[],now=Date.now()/1000){
 const telemetry=device?.telemetry,observed=Number(telemetry?.subscriptions_observed_at||0),age=now-observed;if(!device?.online||device.revoked||device.can_manage===false||!telemetry?.fresh||telemetry?.data?.subscriptions_status!=='ok'||!observed||age<0||age>180||sim?.device_id)return null;
 const phone=normalizePhone(sim?.phone,sim?.country);if(!/^(?:\+639\d{9}|\+861[3-9]\d{9})$/.test(phone))return null;
 const matches=subscriptions(device).filter(s=>s.sub!==null&&s.slot!==null&&(s.metrics.sim_state===5||(s.metrics.sim_state==null&&s.metrics.subscription_active===true&&s.metrics.sim_state_reason==='unsupported_android_version'))&&s.metrics.phone_hint_source==='android_sim'&&normalizePhone(s.metrics.phone_hint,sim?.country)===phone);
 if(matches.length!==1||subscriptions(device).filter(s=>s.slot===matches[0].slot).length!==1||sims.some(s=>Number(s.device_id)===Number(device.id)&&Number(s.subscription_id)===matches[0].sub))return null;
 return {subscription_id:matches[0].sub,phone};
}

export function requestedPage(hash,allowed){const page=String(hash||'').replace(/^#/, '');return allowed.includes(page)?page:null;}
