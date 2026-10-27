import test from 'node:test';
import assert from 'node:assert/strict';
import {createNumberManagement} from '../public/static/number-management.js';

// Minimal form surface for exercising payloads and permission decisions without a browser.
class Element {
 constructor(tag,text='',cls=''){this.tagName=tag;this.textContent=text??'';this.className=cls;this.children=[];this.dataset={};this.value='';this.open=false;this.isConnected=true;this.disabled=false;this.classList={add(){},toggle(){}};this.listeners={};}
 append(...items){this.children.push(...items);}
 replaceChildren(...items){this.children=items;}
 setAttribute(){}
 addEventListener(name,fn){this.listeners[name]=fn;}
 focus(){}
 get options(){return this.children;}
 get lastChild(){return this.children.at(-1);}
}
globalThis.Option=class extends Element {constructor(label,value){super('option',label);this.value=value}};
function descendants(element){return [element,...element.children.flatMap(descendants)];}
function harness({role='operator',permissions=['sims.create','sims.view','sims.edit','shops.create','shops.edit'],sims=[],shops=[],users=[],failShops=false,failSims=false}={}){
 const state={me:{role},sims,shops,devices:[],authGeneration:0},calls=[],boxes=[];
 const node=(...args)=>new Element(...args),button=(label,action,cls)=>{const out=node('button','',cls);out.append(node('span',label));out.action=action;return out};
 const modal=(title)=>{const box={title,d:node('dialog'),body:node('div'),footer:node('footer'),close(){state.modal=null}};state.modal=box.d;boxes.push(box);return box};
 const api=async(path,method='GET',payload)=>{calls.push({path,method,payload});if(method==='GET'){if(path==='shops'){if(failShops)throw Error('shops unavailable');return shops}if(path==='admin/users')return users;if(path==='sims'){if(failSims)throw Error('sims unavailable');return sims}return []}return {id:99}};
 const ui={state,node,button,modal,api,can:p=>permissions.includes(p),render:async()=>{},toast(){},platformFilter:()=>node('div'),localSearch:()=>node('form'),panel:(a,b,c)=>c,table:()=>node('table'),empty:()=>node('div'),platformBadges:values=>node('span',values?.join(',')),carriers:[{value:'Globe',label:'Globe'},{value:'中国移动',label:'中国移动'}],stack:(a,b)=>node('span',a+b),badge:node,money:()=>'',bindNumber:s=>calls.push({bind:s.id})};
 const manager=createNumberManagement(ui);
 const nodes=box=>[...descendants(box.body),...descendants(box.footer)],field=(box,name)=>nodes(box).find(e=>e.name===name),save=box=>box.footer.children.at(-1),submit=box=>nodes(box).find(e=>e.tagName==='form').onsubmit({preventDefault(){}});
 return {manager,state,calls,boxes,nodes,field,save,submit};
}

test('ordinary add starts with optional shops collapsed and saves a local phone without stores even if shops fail',async()=>{
 const h=harness({permissions:['sims.create','sims.view'],failShops:true}),box=await h.manager.dialog();
 assert.equal(h.nodes(box).find(e=>e.tagName==='details').open,false);
 assert.equal(h.nodes(box).filter(e=>e.name==='platform').every(e=>!e.checked),true);
 assert.equal(h.save(box).disabled,false);
 h.field(box,'phone').value='9171234567';h.field(box,'carrier').value='Globe';await h.submit(box);
 const request=h.calls.find(c=>c.method==='POST');assert.equal(request.path,'sims/onboard');assert.equal(request.payload.phone,'+639171234567');assert.deepEqual(request.payload.shops,[]);
});

test('missing essential phone candidates keeps save blocked',async()=>{
 const h=harness({failSims:true}),box=await h.manager.dialog();assert.equal(h.save(box).disabled,true);
 h.field(box,'phone').value='9171234567';await h.submit(box);assert.equal(h.calls.some(c=>c.method==='POST'),false);
});

test('edit displays local phone, preserves linked names, and permits clearing every shop',async()=>{
 const shops=[{id:8,name:'真实店铺',platform:'tiktok'}],sim={id:3,country:'PH',phone:'+639171234567',carrier:'Globe',shops};
 const h=harness({shops,sims:[sim]}),box=await h.manager.dialog({sim});
 assert.equal(h.field(box,'phone').value,'9171234567');assert.equal(h.field(box,'name_tiktok').value,'真实店铺');assert.equal(h.nodes(box).find(e=>e.tagName==='details').open,true);
 await h.submit(box);assert.deepEqual(h.calls.find(c=>c.method==='PUT').payload.shops,[{platform:'tiktok',shop_id:8}]);
 const box2=await h.manager.dialog({sim});for(const checkbox of h.nodes(box2).filter(e=>e.name==='platform')){checkbox.checked=false;checkbox.onchange()}
 await h.submit(box2);assert.deepEqual(h.calls.filter(c=>c.method==='PUT').at(-1).payload.shops,[]);
});

test('pasting another country prefix never silently changes selected country or saves it',async()=>{
 const h=harness(),box=await h.manager.dialog();h.field(box,'phone').value='+8613812345678';h.field(box,'phone').listeners.input();assert.equal(h.field(box,'country').value,'PH');await h.submit(box);assert.equal(h.calls.some(c=>c.method==='POST'),false);
 h.field(box,'country').value='CN';h.field(box,'country').onchange();assert.match(h.field(box,'phone').placeholder,/11位，不含\+86/);await h.submit(box);assert.equal(h.calls.find(c=>c.method==='POST').payload.phone,'+8613812345678');
});

test('transfer moves selected shops atomically and rejects platform conflicts inline',async()=>{
 const shops=[{id:8,name:'TikTok店铺',platform:'tiktok'},{id:9,name:'Shopee店铺',platform:'shopee'}],source={id:1,phone:'+639171234567',shops},conflict={id:2,phone:'+639181234567',shops:[{id:10,name:'已有TikTok店铺',platform:'tiktok'}]},target={id:3,phone:'+639191234567',device_id:4,shops:[]};
 const h=harness({sims:[source,conflict,target]}),box=await h.manager.transferShops(source);const select=h.field(box,'target_sim_id');
 assert.equal(h.nodes(box).filter(e=>e.name==='transfer_shop').every(e=>e.checked),true);
 select.value='2';select.onchange();assert.equal(h.save(box).disabled,true);assert.match(h.nodes(box).find(e=>e.className==='form-error').textContent,/已有TikTok/);
 const first=h.nodes(box).find(e=>e.name==='transfer_shop');first.checked=false;first.onchange();assert.equal(h.save(box).disabled,false);
 select.value='3';select.onchange();await h.submit(box);assert.deepEqual(h.calls.filter(c=>c.method!=='GET'),[{path:'sims/1/transfer-shops',method:'POST',payload:{target_sim_id:3,shop_ids:[9]}}]);
});

test('shared partial numbers expose neither edit nor rebind nor shop transfer',()=>{
 const h=harness({permissions:['sims.edit','devices.bind']}),box=h.manager.details({id:1,phone:'+639171234567',device_id:5,can_manage:false,financial_restricted:true,shops:[{id:8,name:'Shared',platform:'tiktok'}]});
 const captions=h.nodes(box).filter(e=>e.tagName==='button').map(e=>e.lastChild.textContent);
 for(const caption of ['编辑号码','更换采集手机','店铺更换用号'])assert.equal(captions.includes(caption),false);
});

test('add-new-number continuation resumes a chosen transfer without moving any shops until confirmation',async()=>{
 const source={id:1,phone:'+639171234567',shops:[{id:8,name:'店铺A',platform:'tiktok'}]},target={id:99,phone:'+639181234567',shops:[]};
 const h=harness({sims:[source,target]}),box=await h.manager.transferShops(source);
 await h.nodes(box).find(e=>e.tagName==='button'&&e.lastChild.textContent==='添加新号码').action();
 const addBox=h.boxes.at(-1);assert.equal(addBox.title,'添加号码');h.field(addBox,'phone').value='9191234567';h.field(addBox,'carrier').value='Globe';await h.submit(addBox);
 const resumed=h.boxes.at(-1);assert.equal(resumed.title,'店铺更换用号');assert.equal(h.field(resumed,'target_sim_id').value,'99');
 assert.deepEqual(h.calls.filter(c=>c.method==='POST').map(c=>c.path),['sims/onboard']);
 await h.submit(resumed);assert.equal(h.calls.filter(c=>c.method==='POST').at(-1).path,'sims/1/transfer-shops');
});

test('recognized phone is prefilled and immutable while saving its confirmed binding',async()=>{const h=harness(),proof={device_id:12,subscription_id:7,phone_hint_confirmation:'+8613812345678'},box=await h.manager.dialog({prefill:{phone:'+8613812345678',country:'CN',carrier:'中国移动',location:'中国'},binding:proof});assert.equal(h.field(box,'phone').value,'13812345678');assert.equal(h.field(box,'country').value,'CN');assert.equal(h.field(box,'carrier').value,'中国移动');assert.equal(h.field(box,'phone').readOnly,true);await h.submit(box);const post=h.calls.find(c=>c.method==='POST');assert.equal(post.payload.phone,'+8613812345678');assert.deepEqual(post.payload.binding,proof);assert.deepEqual(post.payload.shops,[])});
test('manual binding keeps phone entry editable and requires no SMS operation',async()=>{const h=harness(),box=await h.manager.dialog({prefill:{phone:'+639171234567',country:'PH',carrier:'Globe'},binding:{device_id:12,subscription_id:7,manual_confirmation:true}});assert.equal(h.field(box,'phone').value,'9171234567');assert.notEqual(h.field(box,'phone').readOnly,true);h.field(box,'phone').value='9181234567';await h.submit(box);assert.equal(h.calls.find(c=>c.method==='POST').payload.phone,'+639181234567');assert.equal(h.calls.some(c=>c.path.includes('pair-number')),false)});


test('unlinked shops retain open and closed choices across refreshed data',()=>{
 const h=harness({shops:[{id:11,name:'店铺A',platform:'tiktok',linked_sims:[]}]});
 const first=h.manager.view().at(-1);assert.equal(first.open,false);first.open=true;first.listeners.toggle?.();
 h.state.shops.push({id:12,name:'店铺B',platform:'shopee',linked_sims:[]});
 const refreshed=h.manager.view().at(-1);assert.equal(refreshed.open,true);
 refreshed.open=false;refreshed.listeners.toggle?.();assert.equal(h.manager.view().at(-1).open,false);
});

test('refresh before the asynchronous toggle event preserves the live disclosure choice',()=>{
 const h=harness({shops:[{id:11,name:'店铺A',platform:'tiktok',linked_sims:[]}]});
 const first=h.manager.view().at(-1);first.open=true;
 const refreshed=h.manager.view().at(-1);assert.equal(refreshed.open,true);first.isConnected=false;first.open=false;first.listeners.toggle?.();
 assert.equal(h.manager.view().at(-1).open,true);
});

test('unlinked shop disclosure state resets with the account and rejects old events',()=>{
 const h=harness({shops:[{id:11,name:'店铺A',platform:'tiktok',linked_sims:[]}]});
 const previous=h.manager.view().at(-1);previous.open=true;previous.listeners.toggle?.();
 h.state.authGeneration++;assert.equal(h.manager.view().at(-1).open,false);
 previous.listeners.toggle?.();assert.equal(h.manager.view().at(-1).open,false);
});


test('administrator can transfer shops onto a number already using the same platform',async()=>{
 const source={id:1,phone:'+639171234567',shops:[{id:8,name:'店铺A',platform:'tiktok'}]},target={id:2,phone:'+639181234567',shops:[{id:9,name:'店铺B',platform:'tiktok'}]};
 const h=harness({role:'admin',sims:[source,target]}),box=await h.manager.transferShops(source);h.field(box,'target_sim_id').value='2';h.field(box,'target_sim_id').onchange();
 assert.equal(h.save(box).disabled,false);await h.submit(box);assert.equal(h.calls.at(-1).path,'sims/1/transfer-shops');
});
test('administrator can add another shop on one platform and retains both on next edit',async()=>{
 const shops=[{id:8,name:'店铺A',platform:'tiktok'},{id:9,name:'店铺B',platform:'tiktok'}],sim={id:1,phone:'+639171234567',country:'PH',carrier:'Globe',shops};
 const h=harness({role:'admin',sims:[sim],shops}),box=await h.manager.dialog({sim});
 assert.equal(h.nodes(box).filter(e=>e.name==='shop_tiktok').length,2);
 const add=h.nodes(box).find(e=>e.tagName==='button'&&e.lastChild.textContent==='再加一家 TikTok 店铺');assert.ok(add);await add.action();
 const newName=h.nodes(box).filter(e=>e.name==='name_tiktok').at(-1);newName.value='新店铺';await h.submit(box);
 const entries=h.calls.find(c=>c.method==='PUT').payload.shops;assert.deepEqual(entries.map(e=>e.shop_id||e.name),[8,9,'新店铺']);
});


test('administrator can leave number reuse after adding another same-platform row',async()=>{
 const shops=[{id:8,name:'店铺A',platform:'tiktok'}],sim={id:1,phone:'+639171234567',country:'PH',carrier:'Globe',shops};
 const h=harness({role:'admin',sims:[sim],shops}),box=await h.manager.dialog();h.field(box,'phone').value='9171234567';h.field(box,'phone').listeners.input();const reuse=h.field(box,'reuse_sim');reuse.checked=true;reuse.onchange();
 await h.nodes(box).find(e=>e.tagName==='button'&&e.lastChild.textContent==='再加一家 TikTok 店铺').action();const newName=h.nodes(box).filter(e=>e.name==='name_tiktok').at(-1);newName.value='草稿店铺';
 reuse.checked=false;assert.doesNotThrow(()=>reuse.onchange());assert.equal(newName.value,'草稿店铺');h.field(box,'phone').value='9181234567';h.field(box,'phone').listeners.input();await h.submit(box);assert.equal(h.calls.find(c=>c.method==='POST').payload.phone,'+639181234567');
});
test('new same-platform rows can assign operators loaded before the row existed',async()=>{
 const h=harness({role:'admin',permissions:['sims.create','sims.view','shops.create','users.permissions'],users:[{id:6,role:'operator',active:1,username:'Operator X'}]}),box=await h.manager.dialog();
 await h.nodes(box).find(e=>e.tagName==='button'&&e.lastChild.textContent==='再加一家 TikTok 店铺').action();const operator=h.nodes(box).filter(e=>e.name==='operator_tiktok').at(-1);assert.equal(operator.options.some(o=>o.value==='6'),true);
});
