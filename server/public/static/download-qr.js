/* QR is rendered locally: no external QR service sees the download URL. */
(function(){
 const box=document.getElementById('download-qr');if(!box)return;
 const url=location.origin+'/a',link=document.getElementById('download-share-url'),status=document.getElementById('download-qr-status');
 if(link){link.textContent=url;link.href=url}
 try{
  const qr=qrcodegen.QrCode.encodeText(url,qrcodegen.QrCode.Ecc.MEDIUM),border=4,size=qr.size+border*2,ns='http://www.w3.org/2000/svg';
  const svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox',`0 0 ${size} ${size}`);svg.setAttribute('width','220');svg.setAttribute('height','220');svg.setAttribute('role','img');svg.setAttribute('aria-label','扫码打开 Umax SIM 工作台下载页');svg.setAttribute('shape-rendering','crispEdges');
  const bg=document.createElementNS(ns,'rect');bg.setAttribute('width',String(size));bg.setAttribute('height',String(size));bg.setAttribute('fill','#ffffff');svg.append(bg);
  const pixels=[];for(let y=0;y<qr.size;y++)for(let x=0;x<qr.size;x++)if(qr.getModule(x,y))pixels.push(`M${x+border},${y+border}h1v1h-1z`);
  const path=document.createElementNS(ns,'path');path.setAttribute('d',pixels.join(' '));path.setAttribute('fill','#102c3b');svg.append(path);box.replaceChildren(svg);box.dataset.downloadUrl=url;if(status)status.textContent='手机扫一扫，打开下载页';
 }catch{box.replaceChildren();if(status)status.textContent='二维码暂时无法显示，请使用下方短链接或下载按钮。'}
 const copy=document.getElementById('copy-download-link');if(copy)copy.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(url);copy.textContent='已复制';setTimeout(()=>copy.textContent='复制下载链接',2000)}catch{if(status)status.textContent='请长按或选中下方短链接复制。'}});
})();
