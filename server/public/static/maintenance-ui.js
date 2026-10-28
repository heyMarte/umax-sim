import {fullTime} from './ui-data.js?v=063';

export function createMaintenanceViews(ui){
 const {state,node,button,modal,api,can,render,toast,panel,table,empty,badge,stack,notificationButton,handleAlert,historyPager}=ui;
 async function refreshNotice(message){state.maintenanceNotice=message;state.maintenanceNoticePage='alerts';state.viewKey='';await render();toast(message)}
 async function dismiss(alert,restore=false){await api('alerts/'+(restore?'undismiss':'dismiss'),'POST',{key:alert.key});await refreshNotice(restore?'提醒已恢复':'已移除本次提醒；异常恢复后再次发生会重新显示。')}
 async function dismissVisible(items){
  const generation=state.authGeneration||0;let ok=0,failed=0;
  for(let i=0;i<items.length;i+=4){if(generation!==(state.authGeneration||0))return;const results=await Promise.allSettled(items.slice(i,i+4).map(a=>api('alerts/dismiss','POST',{key:a.key})));for(const r of results)r.status==='fulfilled'?ok++:failed++}
  if(generation===(state.authGeneration||0))await refreshNotice(`已移除 ${ok} 条提醒${failed?'，'+failed+' 条未完成，请刷新后重试':''}`)
 }
 function alerts(){
  const data=state.alerts||{},all=data.items||[],mode=state.alertMode||'active',items=mode==='dismissed'?data.dismissed||[]:all.filter(a=>mode==='muted'?!!a.muted_until:!a.muted_until),body=node('div',undefined,'panel-body');
  const tools=node('div',undefined,'toolbar'),tabs=node('div',undefined,'segmented');tabs.setAttribute('aria-label','提醒状态');
  for(const [key,label] of [['active','待处理 '+all.filter(a=>!a.muted_until).length],['muted','已暂缓 '+(data.muted_count||0)],['dismissed','已移除 '+(data.dismissed_count||0)]]){const b=button(label,()=>{state.alertMode=key;state.maintenanceNotice='';state.viewKey='';return render()},mode===key?'active':'');b.setAttribute('aria-pressed',String(mode===key));tabs.append(b)}
  tools.append(tabs,notificationButton());if(can('alerts.dismiss')&&mode==='active'&&items.length){const b=button('移除当前提醒',async()=>{b.disabled=true;try{await dismissVisible(items)}finally{b.disabled=false}},'quiet small');tools.append(b)}body.append(tools);if(state.maintenanceNotice&&state.maintenanceNoticePage==='alerts')body.append(node('p',state.maintenanceNotice,'callout'));
  for(const a of items){const row=node('article',undefined,'alert-item');row.append(badge(mode==='dismissed'?'已移除':a.muted_until?'已暂缓':a.severity==='critical'?'紧急':a.severity==='warning'?'注意':'提示',mode==='dismissed'?'off':a.severity==='critical'?'bad':a.severity==='warning'?'warn':'off'),stack(a.title,a.detail),node('p','建议：'+a.action,'note'),button('去处理',()=>handleAlert(a),'secondary small'));
   if(can('alerts.dismiss'))row.append(button(mode==='dismissed'?'恢复提醒':'移除提醒',()=>dismiss(a,mode==='dismissed'),'quiet small'));
   if(mode!=='dismissed'&&!a.muted_until&&can('alerts.mute'))row.append(button('1小时后再提醒',async()=>{await api('alerts/mute','POST',{key:a.key,duration:3600});await refreshNotice('该事项已暂缓1小时')},'quiet small'));else if(a.muted_until&&mode!=='dismissed')row.append(node('span','暂缓至 '+fullTime(a.muted_until),'note'));body.append(row)
  }
  if(!items.length)body.append(empty(mode==='dismissed'?'没有已移除的提醒':mode==='muted'?'没有暂缓的事项':'暂无待处理异常',mode==='dismissed'?'移除只影响当前账户的提醒显示。':'以手机最新上报和已录入记录为准。'));
  return [panel('异常提醒',`最近更新 ${fullTime(data.checked_at)} · 移除提醒不会修改实际设备状态`,body)];
 }
 function purgeDialog(item){
  const clearing=!item,kind=state.trashKind||'all',kindLabels={all:'全部类型',device:'设备',sim:'号码',user:'账户',shop:'店铺',record:'流水',message:'短信'};
  const box=modal(clearing?'清空回收站':'永久删除',clearing?'当前筛选：'+kindLabels[kind]:item.label),error=node('p','','form-error');box.body.append(node('p',state.me?.role==='admin'?(clearing?'永久清空当前筛选范围内的回收站项目，并自动处理关联。手机卡和历史记录按各自项目分别管理。':'永久删除此项目并自动解除相关绑定或关联；其他手机卡和历史记录保留。'):(clearing?'将永久删除当前筛选范围内允许删除的回收站项目。无法恢复；有关联限制的项目会保留并说明原因。':'此项目将从回收站永久删除，无法恢复。历史关联受限制时，系统会阻止删除并说明原因。'),'callout warn'),error);
  const save=button(clearing?'确认永久清空':'确认永久删除',async()=>{save.disabled=true;const generation=state.authGeneration||0;error.textContent='';try{
   const result=await api(clearing?'admin/trash/clear':'admin/trash/'+item.kind+'/'+item.id,clearing?'POST':'DELETE',clearing?{kind}:undefined);if(generation!==(state.authGeneration||0))return;
   if(clearing){state.historyPages??={};state.historyPages.trash=1}else if((state.trash||[]).length===1&&(state.historyPages?.trash||1)>1)state.historyPages.trash--;
   state.viewKey='';state.maintenanceNoticePage='trash';state.maintenanceNotice=clearing?`已永久删除 ${result.deleted??0} 项${result.skipped?.length?'，'+result.skipped.length+' 项保留':''}`:'已永久删除';await render();if(generation!==(state.authGeneration||0))return;
   if(result.skipped?.length){box.body.replaceChildren(node('p',state.maintenanceNotice,'callout'),table(['保留项目','原因'],result.skipped.map(x=>[x.label||kindLabels[x.kind]||'项目',x.detail||'存在关联限制'])));box.footer.replaceChildren(button('知道了',box.close,'primary'))}else{box.close();toast(state.maintenanceNotice)}
  }catch(e){if(generation===(state.authGeneration||0))error.textContent=e.message}finally{save.disabled=false}},'primary danger-action');box.footer.append(button('取消',box.close),save);return box;
 }
 function trash(){
  const kinds={device:'设备',sim:'号码',user:'账户',shop:'店铺',record:'流水',message:'短信'},rows=(state.trash||[]).map(t=>{const actions=node('div',undefined,'toolbar-group trash-actions');if(can('trash.restore')&&t.can_restore!==false)actions.append(button('恢复',()=>ui.confirmDialog('恢复 '+t.label,'设备恢复后保持停止，账户保持禁用。号码绑定需重新设置。',()=>api('admin/restore/'+t.kind+'/'+t.id,'POST'),'恢复'),'secondary small'));if(can('trash.purge')){const b=button('永久删除',()=>purgeDialog(t),'quiet small danger');if(t.can_purge!==true){b.disabled=true;b.title=t.purge_block_reason||'当前账户没有对应删除权限或存在关联限制'}actions.append(b);if(b.disabled)actions.append(node('span',b.title,'subline'))}return [t.label,kinds[t.kind],fullTime(t.deleted_at),actions]});
  const pager=historyPager('trash');if(can('trash.purge')&&(state.trash||[]).length)pager.append(button('清空当前筛选',()=>purgeDialog(),'quiet small danger'));
  const out=[pager];if(state.maintenanceNotice&&state.maintenanceNoticePage==='trash')out.push(node('p',state.maintenanceNotice,'callout'));out.push(panel('回收站',state.me?.role==='admin'?'管理员可恢复、永久删除或清空，关联会自动处理。':'恢复可继续使用；永久删除无法恢复，历史关联受限的项目会保留。',rows.length?table(['名称','类型','删除时间','操作'],rows):empty('回收站为空','删除后的数据会显示在这里。')));return out;
 }
 return {alerts,trash};
}
