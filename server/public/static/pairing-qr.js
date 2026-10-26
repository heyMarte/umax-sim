// Only renders authorized configuration already returned to this browser. No QR payload is fetched remotely.
let encoder;
function loadEncoder(){
 if(globalThis.qrcodegen?.QrCode)return Promise.resolve(globalThis.qrcodegen.QrCode);
 if(!encoder)encoder=new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='/static/vendor/qrcodegen-1.8.0.js';script.onload=()=>globalThis.qrcodegen?.QrCode?resolve(globalThis.qrcodegen.QrCode):reject(Error('二维码组件未能初始化'));script.onerror=()=>reject(Error('二维码组件暂时无法读取，请复制配置'));document.head.append(script)}).catch(e=>{encoder=null;throw e});
 return encoder;
}
export async function renderPairingQr(host,payload){
 host.setAttribute('aria-busy','true');host.textContent='正在生成接入二维码…';
 try{
  const QR=await loadEncoder();if(!host.isConnected)return false;const qr=QR.encodeText(payload,QR.Ecc.MEDIUM),border=4,size=qr.size+border*2,ns='http://www.w3.org/2000/svg';
  const svg=document.createElementNS(ns,'svg');for(const [key,value] of Object.entries({viewBox:`0 0 ${size} ${size}`,width:'248',height:'248',role:'img','aria-label':'用 Umax SIM 工作台扫描此码接入手机','shape-rendering':'crispEdges'}))svg.setAttribute(key,value);
  const bg=document.createElementNS(ns,'rect');bg.setAttribute('width',String(size));bg.setAttribute('height',String(size));bg.setAttribute('fill','#fff');svg.append(bg);const dark=[];for(let y=0;y<qr.size;y++)for(let x=0;x<qr.size;x++)if(qr.getModule(x,y))dark.push(`M${x+border},${y+border}h1v1h-1z`);const path=document.createElementNS(ns,'path');path.setAttribute('d',dark.join(' '));path.setAttribute('fill','#102c3b');svg.append(path);host.replaceChildren(svg);return true;
 }catch{if(host.isConnected)host.textContent='配置较长或二维码暂不可用，请使用“复制手机配置”粘贴接入。';return false}
 finally{host.setAttribute('aria-busy','false')}
}
