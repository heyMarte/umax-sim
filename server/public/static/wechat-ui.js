import {renderPairingQr} from './pairing-qr.js?v=070';
import {fullTime} from './ui-data.js?v=070';
export function pushOutcome(status){return ({queued:{label:'等待发送',tone:'warn'},sending:{label:'发送处理中',tone:'warn'},retry:{label:'等待重试',tone:'warn'},accepted:{label:'微信已受理',tone:'ok'},failed:{label:'发送失败',tone:'bad'},uncertain:{label:'发送结果未确认',tone:'warn'},expired:{label:'验证码已过期',tone:'off'},cancelled:{label:'已取消发送',tone:'off'}})[status]||{label:'状态待确认',tone:'off'};}
const errorHints={'40164':'服务器公网 IP 不在公众号 API 白名单','40125':'AppSecret 不正确，请重新核对','40013':'AppID 不正确，请重新核对','43004':'接收人尚未关注此服务号','47003':'模板字段或内容格式不符合要求','40037':'模板 ID 不可用，请检查是否属于此公众号','40001':'微信令牌无效，请检查公众号凭据','40014':'微信令牌失效','42001':'微信令牌过期','45009':'微信接口调用达到限额','45047':'此接收人的模板消息额度受限'};
export function createWechatViews(ui){
 const {state,node,button,modal,api,can,render,toast,panel,table,empty,badge,stack,copyText}=ui;
 const personal=()=>!!state.wechat?.can_receive;
 const change=()=>{state.wechatDirty=true};
 async function reload(){state.viewKey='';return render()}
 function field(form,name,title,value='',help='',type='text'){
  const label=node('label',title),input=node('input');input.name=name;input.type=type;input.value=value??'';input.autocomplete=type==='password'?'new-password':'off';input.addEventListener('input',change);label.append(input);if(help)label.append(node('span',help,'field-help'));form.append(label);return input;
 }
 function accountCard(){
  const a=state.wechat?.account||{},card=node('section',undefined,'wechat-account');
  card.append(node('h3',a.name?'先关注「'+a.name+'」':'尚未设置公众号名称，请联系管理员'),node('p','关注后，再绑定我的微信，才能接收此服务号的验证码通知。','note'));
  if(a.qr_url){const link=node('a',undefined,'wechat-follow-image'),img=node('img');img.src=a.qr_url;img.alt=(a.name||'公众号')+'关注二维码';img.width=200;img.height=200;link.href=a.qr_url;link.target='_blank';link.rel='noopener noreferrer';link.append(img);card.append(link,node('p','公众号关注二维码 · 微信中长按识别，或用另一台手机扫描；点击可放大。','field-help'))}
  else card.append(node('p','管理员尚未上传关注二维码，请联系管理员补充。','callout'));
  if(a.name)card.append(button('复制公众号名称',()=>copyText(a.name),'quiet small'));return card;
 }
 function settings(){const data=state.wechat,c=data.config,manage=data.can_manage,keys=data.can_manage_secret,body=node('div',undefined,'panel-body'),form=node('form',undefined,'wechat-config'),grid=node('div',undefined,'wechat-form-grid');let app,secret,origin,template,name,qrFile,removeQr=false;
  if(manage){
   name=field(grid,'account_name','公众号名称',data.account?.name||'','填写微信中显示的公众号名称，所有接收人都能看到。');name.maxLength=100;
   const label=node('label','公众号关注二维码');qrFile=node('input');qrFile.type='file';qrFile.name='follow_qr';qrFile.accept='image/png,image/jpeg,image/webp';qrFile.addEventListener('change',()=>{removeQr=false;change()});label.append(qrFile,node('span','上传公众号官方提供的关注二维码，支持 PNG、JPG、WebP，最大512KB。与下方的个人绑定码用途不同。','field-help'));if(data.account?.qr_url){const preview=node('img');preview.src=data.account.qr_url;preview.alt='当前公众号关注二维码';preview.className='wechat-config-qr';label.append(preview,button('移除关注二维码',()=>{removeQr=true;qrFile.value='';preview.hidden=true;change()},'quiet small'))}grid.append(label);
   app=field(grid,'app_id','服务号 AppID',c.app_id,'填写自己的认证服务号 AppID；更换已配置的 AppID 会解除所有微信绑定并暂停推送');
   origin=field(grid,'origin','本站 HTTPS 地址',c.origin||globalThis.location?.origin||'','用于微信授权回调和消息详情链接');
   template=field(grid,'template_id','模板 ID',c.template_id,'填写与本站实际场景匹配的模板ID');
  }
  if(keys){secret=field(grid,'app_secret','公众号 AppSecret','','仅有“设置和更换公众号密钥”权限的账户可以设置；已保存的密钥不回显。','password');secret.placeholder=c.secret_set?'已保存，留空继续使用':'填写服务号 AppSecret'}form.append(grid);
  if(manage){const details=node('details',undefined,'wechat-help');details.append(node('summary','微信侧需要配置什么？'));const list=node('ol');for(const text of ['在微信开发者平台为此服务号的 API IP 白名单加入服务器实际公网出口 IP。','在服务号网页授权域名中配置本站域名，并将微信校验文件放入网站运行目录 public。','保留服务号已有的服务器配置与第三方授权；此绑定流程使用网页授权，不要求替换现有消息回调。','微信卡片的固定标题、字段和展示方式由模板决定。本版本使用设备名称、校验码、通知时间三个字段。'])list.append(node('li',text));details.append(list);body.append(details)}
  const error=node('p','','form-error');error.setAttribute('role','alert');const save=button(manage?'保存微信配置':'保存公众号密钥',()=>form.requestSubmit(),'primary');
  form.onsubmit=async e=>{e.preventDefault();save.disabled=true;error.textContent='';try{const payload=manage?{app_id:app.value.trim(),origin:origin.value.trim(),template_id:template.value.trim(),account_name:name.value.trim()}:{};if(secret)payload.app_secret=secret.value;
   const file=qrFile?.files?.[0];if(file){if(file.size>524288)throw Error('请选择不超过512KB的二维码图片');payload.follow_qr=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(reader.result);reader.onerror=()=>reject(Error('二维码图片读取失败，请重试'));reader.readAsDataURL(file)})}else if(removeQr)payload.follow_qr='';
   await api('wechat/config','PUT',payload);if(secret)secret.value='';state.wechatDirty=false;toast('已保存公众号配置');await reload()}catch(e){error.textContent=e.message}finally{save.disabled=false}};
  const cancel=button('放弃未保存修改',()=>{state.wechatDirty=false;return reload()},'quiet');const actions=node('div',undefined,'toolbar-group');actions.append(save,cancel);form.append(error,actions);body.append(form);
  if(manage){const delivery=node('div',undefined,'wechat-enable');delivery.append(stack(c.enabled?'自动推送已开启':'自动推送尚未开启',c.tested?'当前配置已有微信受理的测试记录':'先绑定本人微信并发送测试，确认接收效果后再开启'));
   const toggle=button(c.enabled?'暂停自动推送':'开启自动推送',async()=>{if(state.wechatDirty){toast('请先保存或放弃配置修改');return}const box=modal(c.enabled?'暂停自动推送？':'开启自动推送？',c.enabled?'暂停后不再发送新的验证码。':'请先在普通微信确认测试卡片和校验码显示正常。');const error=node('p','','form-error');box.body.append(error);const ok=button('确认'+(c.enabled?'暂停':'开启'),async()=>{ok.disabled=true;try{await api('wechat/config','PUT',{enabled:!c.enabled});box.close();await reload()}catch(e){error.textContent=e.message;ok.disabled=false}},'primary');box.footer.append(button('取消',box.close),ok)},c.enabled?'secondary':'primary');toggle.disabled=!c.enabled&&!c.tested;delivery.append(toggle);body.append(delivery)}
  return panel(manage?'公众号配置':'公众号密钥',manage?'设置公众号资料，接收人可直接关注和绑定':'此授权仅用于设置或更换密钥，原密钥不回显',body);
 }
 async function binding(){const issued=await api('wechat/binding','POST'),generation=state.authGeneration||0,box=modal('绑定我的微信','用普通微信扫码；授权后，请回到本窗口核对并确认。'),qr=node('div',undefined,'wechat-qr'),status=node('p','等待微信扫码…','callout'),error=node('p','','form-error');box.body.append(accountCard(),node('h3','再绑定我的微信'),node('p','下方是个人绑定二维码，用于关联当前后台账户，不是公众号关注码。','note'),qr,status,error);const link=node('a','在手机微信中打开绑定页');link.href=issued.url;link.target='_blank';link.rel='noopener noreferrer';box.body.append(link);
  box.footer.append(button('关闭',box.close),button('复制绑定链接',()=>copyText(issued.url)));
  let timer=null,stopped=false;box.d.addEventListener('close',()=>{stopped=true;clearTimeout(timer)});
  const alive=()=>!stopped&&box.d.isConnected&&generation===(state.authGeneration||0)&&state.modal===box.d;
  const check=async()=>{if(!alive())return;try{const result=await api('wechat/binding?token='+encodeURIComponent(issued.token));if(!alive())return;
   if(result.status==='pending'){status.textContent='微信已授权：'+(result.identity_hint||'已识别微信身份')+'。请确认刚才扫码的是你自己的微信。';const confirm=button('确认绑定此微信',async()=>{confirm.disabled=true;try{await api('wechat/binding/confirm','POST',{token:issued.token});if(!alive())return;box.close();toast('本人微信已绑定');await reload()}catch(e){if(alive()){error.textContent=e.message;confirm.disabled=false}}},'primary');box.footer.replaceChildren(button('取消',box.close),confirm);return}
   if(result.status==='confirmed'){box.close();await reload();return}
   if(result.status==='expired'){status.textContent='绑定链接已过期，请关闭后重新生成。';qr.replaceChildren();return}
   timer=setTimeout(check,2000);
  }catch(e){if(alive()){error.textContent=e.message;timer=setTimeout(check,4000)}}};
  const made=await (ui.qr||renderPairingQr)(qr,issued.url);if(alive()){qr.querySelector?.('svg')?.setAttribute('aria-label','使用普通微信扫描，绑定本人接收账户');if(!made)qr.textContent='二维码未生成，可复制绑定链接在普通微信打开。';check()}
 }
 function testMessage(){const box=modal('发送测试消息','向当前账户已绑定的微信发送一条演示验证码，不读取或转发真实短信。'),out=node('p','演示校验码：123456','callout'),error=node('p','','form-error');box.body.append(out,error);const send=button('确认发送测试',async()=>{send.disabled=true;error.textContent='';try{const result=await api('wechat/test','POST',{code:'123456'}),s=pushOutcome(result.status);state.viewKey='';if(result.status==='accepted'){box.close();toast('微信已受理测试消息，请在微信查看');await reload();return}out.textContent=s.label+(result.status==='uncertain'?'。无法确认微信是否已经受理，请先查看微信，避免重复发送。':'。请检查下方原因后重试。');if(result.error_code)error.textContent=errorHints[result.error_code]||('微信错误码：'+result.error_code);send.disabled=result.status==='uncertain'}catch(e){error.textContent=e.message;send.disabled=false}},'primary');box.footer.append(button('关闭',async()=>{box.close();await reload()}),send);return box}
 function myBinding(){const body=node('div',undefined,'panel-body'),b=state.wechat.binding||{};
  if(!personal()){body.append(empty('当前账户未获微信收码权限','需要「微信接收验证码」「查看短信」「复制验证码」权限；推送范围沿用本人获授权的店铺。'));return panel('我的微信接收','每个运营绑定自己的微信',body)}
  body.append(accountCard(),stack(b.bound?'已绑定本人微信':'尚未绑定微信',b.bound?(b.identity_hint||'微信身份已确认'):'首次扫码绑定后，可自动接收之后的新验证码。'));if(b.bound&&b.follower===false)body.append(node('p','请先关注此服务号，否则微信可能拒绝接收模板消息。','callout'));
  const actions=node('div',undefined,'toolbar-group');actions.append(button(b.bound?'重新绑定我的微信':'绑定我的微信',binding,'primary'));if(b.bound){actions.append(button('发送测试消息',testMessage),button('解除本人绑定',()=>{const box=modal('解除微信绑定？','解除后停止向此微信发送验证码，待发任务会失效。');box.footer.append(button('取消',box.close),button('确认解除',async()=>{await api('wechat/binding','DELETE');box.close();await reload()},'quiet danger'))},'quiet danger'))}body.append(actions,node('p','只推送新收到且未过期的验证码；无验证码短信不会推送，权限或绑定改变后待发任务会重新核对。','note'));return panel('我的微信接收','独立绑定 · 按店铺权限发送',body);
 }
 function records(){const body=node('div',undefined,'panel-body'),data=state.wechatLogs||{},items=data.items||[];body.append(node('p','“微信已受理”表示微信接口接受请求，不表示用户已读；网络结果不明时不会自动重复发送。','note'));
  if(items.length)body.append(table(['时间','短信 / 测试','发送状态','尝试次数','原因'],items.map(i=>{const s=pushOutcome(i.status);return [fullTime(i.created_at),i.message_id?'短信 #'+i.message_id:'测试消息',badge(s.label,s.tone),String(i.attempts??0),errorHints[i.error_code]||i.error_code||'—']}),'wechat-log-table'));else body.append(empty('还没有推送记录','绑定后可先发送一条测试消息。不会补发历史验证码。'));
  const tools=node('div',undefined,'toolbar-group');tools.append(button('刷新推送状态',async()=>{if(state.wechatDirty){toast('请先保存或放弃配置修改');return}return reload()}));body.append(tools);return panel('推送记录','仅展示当前账户可查看的通知记录',body);
 }
 function view(){const data=state.wechat;if(!data)return [empty('正在读取微信配置','请稍候')];const worker=data.config?.worker_at||state.wechatLogs?.worker_at,late=!worker||Date.now()/1000-worker>90;const banner=node('section',undefined,'wechat-intro');banner.append(badge(data.config.enabled?'自动推送已开启':'自动推送未开启',data.config.enabled?'ok':'off'),node('p','验证码直接发到绑定的普通微信，无需每次打开后台。'));if(data.can_manage){banner.append(node('p',worker?'推送进程最近运行：'+fullTime(worker):'推送进程尚未运行。按部署说明配置 PHP 推送进程后才能自动发送。',late?'callout':'note'));if(late&&worker)banner.append(node('p','推送进程可能已停止，请检查宝塔进程守护。','callout'))}return [banner,...(data.can_manage||data.can_manage_secret?[settings()]:[]),myBinding(),records()]}
 return {view,binding,testMessage};
}
