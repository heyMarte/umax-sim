import {phoneHintSuggestion,subscriptions,normalizePhone} from './ui-data.js?v=063';

/** Binding is always a user confirmation. SMS receipt tests are separate and optional. */
export function createDeviceNumberSetup(ui){
 const {state,node,button,modal,api,can,render,toast,addSim}=ui;
 const manageable=s=>s?.can_manage!==false;
 const pending=new Set();
 const countryFor=phone=>String(phone||'').startsWith('+86')?'CN':'PH';
 const sourceProof=s=>s?.device_id?{replace_binding:true,expected_device_id:Number(s.device_id),expected_subscription_id:Number(s.subscription_id),...(s.binding_version?{expected_binding_version:s.binding_version}:{})}:{};
 const phoneFor=slot=>normalizePhone(slot?.metrics?.phone_hint,countryFor(slot?.metrics?.phone_hint));
 const canonical=phone=>/^(?:\+639\d{9}|\+861[3-9]\d{9})$/.test(phone);
 const slotName=s=>'卡 '+(s.slot||'未知')+' · '+(s.carrier||'运营商未识别');
 const slotsFor=(d,sims,sim)=>subscriptions(d).filter(s=>s.sub!==null&&subscriptions(d).filter(x=>x.sub===s.sub).length===1&&!sims.some(x=>Number(x.device_id)===Number(d.id)&&Number(x.subscription_id)===s.sub&&Number(x.id)!==Number(sim?.id)));
 function permission(device){if(!can('devices.bind')||!manageable(device)||device.revoked){toast('当前手机不能绑定号码');return false}return true}
 async function load(device){const [devices,sims]=await Promise.all([api('devices'),api('sims')]);const d=devices.find(x=>Number(x.id)===Number(device.id));if(!d||!manageable(d)||d.revoked)throw Error('手机状态或管理权限已变化，请刷新后重试。');return {device:d,sims,devices}}
 function live(box,generation){return state.modal===box.d&&box.d.open&&generation===(state.authGeneration||0)}
 async function complete(box,device){box.close();toast('手机号已绑定，等待手机同步后上传新短信');state.viewKey='';if(can('devices.refresh'))api('devices/refresh','POST',{device_ids:[Number(device.id)]}).catch(()=>{});await render()}
 function prefill(device,slot){const phone=phoneFor(slot),country=countryFor(phone),names=[slot.metrics?.sim_operator,slot.metrics?.carrier,slot.carrier].filter(Boolean).join(' ');let carrier='';if(country==='PH')carrier=/globe/i.test(names)?'Globe':/smart/i.test(names)?'Smart':'';else carrier=['中国移动','中国联通','中国电信','中国广电'].find(c=>names.includes(c))||'';return {phone:canonical(phone)?phone:'',country,carrier,location:['中国','菲律宾'].includes(device.location)?device.location:''}}
 function createForSlot(box,device,slot,confirmed=false){
  if(!can('sims.create')){box.body.append(node('p','号码尚未建档，请让有新增号码权限的成员添加后绑定。','form-error'));return}
  const initial=prefill(device,slot);box.close();return addSim({prefill:initial,binding:{device_id:Number(device.id),subscription_id:slot.sub,...(confirmed?{phone_hint_confirmation:initial.phone}:{manual_confirmation:true})},deviceName:device.name,onSaved:async()=>{if(can('devices.refresh'))api('devices/refresh','POST',{device_ids:[Number(device.id)]}).catch(()=>{})}});
 }
 function existingConfirmation(box,device,slot,sim,confirmed){
  if(!manageable(sim)){box.body.append(node('p','当前账户没有该号码的完整管理权限，请联系管理员。','form-error'));return}
  const message=node('p',sim.device_id?'号码已绑定其他手机，确认后将切换到当前手机，历史短信归属保持原记录。':'请核对号码属于当前这张 SIM，无需发送验证短信。','note'),error=node('p','','form-error');box.body.append(node('strong',sim.phone,'phone-prominent'),message,error);const action=button(sim.device_id?'确认换绑':'确认绑定',async()=>{action.disabled=true;error.textContent='';try{await api('admin/bind','POST',{device_id:Number(device.id),sim_id:Number(sim.id),subscription_id:slot.sub,...(confirmed?{phone_hint_confirmation:sim.phone}:{manual_confirmation:true}),...sourceProof(sim)});await complete(box,device)}catch(e){if(state.modal===box.d){error.textContent=e.message;action.disabled=false}}},'primary');box.footer.replaceChildren(button('取消',box.close),action);return action;
 }
 async function confirmDetected(device,reported){
  if(!permission(device))return;const key=device.id+':'+reported.sub;if(pending.has(key))return;pending.add(key);const generation=state.authGeneration||0,box=modal('确认手机号',device.name),status=node('p','正在核对手机上报…','note');box.body.append(status);box.footer.append(button('取消',box.close));
  try{const data=await load(device);if(!live(box,generation))return box;const phone=phoneFor(reported),slot=subscriptions(data.device).find(s=>s.sub===reported.sub);if(!slot||!canonical(phone))throw Error('这张卡的识别信息已变化，请刷新设备后重试。');const found=data.sims.filter(s=>normalizePhone(s.phone,s.country)===phone);if(found.length>1)throw Error('此号码存在多条资料，请在号码管理核对后选择。');const sim=found[0];
   if(sim?.device_id&&Number(sim.device_id)===Number(device.id)&&Number(sim.subscription_id)===slot.sub){box.close();toast('此号码已经绑定到这张卡');await render();return box}
   if(sim&&!manageable(sim))throw Error('当前账户没有该号码的完整管理权限，请联系管理员。');
   const hint=phoneHintSuggestion(data.device,{phone,country:countryFor(phone)},data.sims),fresh=hint?.subscription_id===reported.sub&&phoneFor(slot)===phone;
   if(!fresh){box.close();return manualSlot(data.device,slot,sim,'识别信息已变化或过期，可人工核对手机号后绑定，不用发短信。')}
   if(!sim)return createForSlot(box,data.device,slot,true);
   if(sim.device_id){box.body.replaceChildren();existingConfirmation(box,data.device,slot,sim,true);return box}
   await api('admin/bind','POST',{device_id:Number(device.id),sim_id:Number(sim.id),subscription_id:slot.sub,phone_hint_confirmation:sim.phone});if(live(box,generation))await complete(box,data.device);
  }catch(e){if(live(box,generation)){status.textContent=e.message;status.className='form-error'}}finally{pending.delete(key)}return box;
 }
 async function manualSlot(device,slot,selectedSim=null,notice=''){
  if(!permission(device))return;const box=modal('绑定手机号',device.name+' · '+slotName(slot)),generation=state.authGeneration||0,error=node('p','','form-error');box.body.append(node('p',notice||'确认这个手机号属于当前 SIM 后即可绑定，不需要发送短信。','note'));box.footer.append(button('关闭',box.close));
  try{const data=await load(device);if(!live(box,generation))return box;const target=slotsFor(data.device,data.sims,selectedSim).find(s=>s.sub===slot.sub);if(!target)throw Error('这张 SIM 已被绑定或识别信息已变化，请刷新后重试。');
   if(selectedSim){const sim=data.sims.find(s=>Number(s.id)===Number(selectedSim.id));if(!sim||!manageable(sim))throw Error('号码不存在或没有完整管理权限。');if(selectedSim.device_id&&(Number(selectedSim.device_id)!==Number(sim.device_id)||Number(selectedSim.subscription_id)!==Number(sim.subscription_id)||(selectedSim.binding_version&&selectedSim.binding_version!==sim.binding_version)))throw Error('原绑定已经变化，请关闭后重新选择。');existingConfirmation(box,data.device,target,sim,false);return box}
   const options=data.sims.filter(s=>manageable(s)&&(!s.device_id||Number(s.device_id)!==Number(device.id))),label=node('label','选择已建档手机号'),select=node('select');select.name='sim_id';select.append(new Option('请选择号码',''));for(const s of options)select.append(new Option(s.phone+' · '+s.carrier+(s.device_id?' · 从其他手机换绑':''),String(s.id)));label.append(select);box.body.append(label,error);const chosen=node('div');box.body.append(chosen);const selectAction=()=>{const sim=options.find(s=>String(s.id)===select.value);chosen.replaceChildren();box.footer.replaceChildren(button('关闭',box.close));if(sim){const temporary={...box,body:chosen};existingConfirmation(temporary,data.device,target,sim,false)}};select.addEventListener('change',selectAction);
   if(can('sims.create'))box.body.append(button('添加新号码并绑定',()=>createForSlot(box,data.device,target,false),'primary'));if(!options.length)label.hidden=true;
  }catch(e){if(live(box,generation))error.textContent=e.message;box.body.append(error)}return box;
 }
 async function open(device,selectedSim){
  if(!permission(device))return;const generation=state.authGeneration||0,box=modal('选择本机 SIM',device.name),status=node('p','正在读取本机卡片…','note');box.body.append(status);box.footer.append(button('关闭',box.close));
  try{const data=await load(device);if(!live(box,generation))return box;const slots=slotsFor(data.device,data.sims,selectedSim);if(!slots.length)throw Error('暂无可绑定的 SIM。请检查 App 的电话权限或刷新上报；不需要发短信。');let picked;
   if(selectedSim){const matches=slots.filter(s=>phoneFor(s)===normalizePhone(selectedSim.phone,selectedSim.country));if(matches.length===1)picked=matches[0]}
   if(!picked&&slots.length===1)picked=slots[0];
   if(picked){box.close();if(!selectedSim&&canonical(phoneFor(picked)))return confirmDetected(data.device,picked);return manualSlot(data.device,picked,selectedSim)}
   status.textContent='选择正在操作的这张卡，再确认手机号；无需发送短信。';for(const slot of slots){const phone=phoneFor(slot);box.body.append(button((canonical(phone)?phone:'手动填写号码')+' · '+slotName(slot),()=>{box.close();return !selectedSim&&canonical(phone)?confirmDetected(data.device,slot):manualSlot(data.device,slot,selectedSim)},'number-slot-choice'))}
  }catch(e){if(live(box,generation)){status.textContent=e.message;status.className='form-error'}}return box;
 }
 async function chooseDevice(sim){
  if(!can('devices.bind')||!manageable(sim))return;const box=modal(sim.device_id?'更换采集手机':'选择采集手机',sim.phone),generation=state.authGeneration||0,error=node('p','','form-error');box.body.append(error);box.footer.append(button('关闭',box.close));
  if(!can('devices.view')){error.textContent='请先获得采集设备查看权限，再选择手机。';return box}
  try{const devices=await api('devices');if(!live(box,generation))return box;const choices=devices.filter(d=>!d.revoked&&manageable(d)&&Number(d.id)!==Number(sim.device_id));if(!choices.length)box.body.append(node('p','暂无可管理的其他手机，请先接入手机。','note'));for(const d of choices)box.body.append(button(d.name+' · '+(d.online?'在线':'离线'),()=>open(d,sim),'number-slot-choice'))}catch(e){if(live(box,generation))error.textContent=e.message}return box;
 }
 return {open,chooseDevice,confirmDetected,manualSlot};
}
