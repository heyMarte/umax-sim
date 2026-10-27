#!/usr/bin/env python3
"""Local disposable test server only. Supply SIM_SMOKE_USER and SIM_SMOKE_PASSWORD."""
import argparse,http.cookiejar,json,os,ssl,urllib.request,urllib.error,urllib.parse,time
p=argparse.ArgumentParser();p.add_argument('--base-url',default='http://127.0.0.1:18760');p.add_argument('--ca');a=p.parse_args();u=urllib.parse.urlsplit(a.base_url)
if u.hostname not in {'127.0.0.1','localhost','::1'} or u.scheme not in {'http','https'}:raise SystemExit('Only an isolated localhost test server is allowed')
jar=http.cookiejar.CookieJar();handlers=[urllib.request.HTTPCookieProcessor(jar)]
if a.ca:handlers.append(urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=a.ca)))
client=urllib.request.build_opener(*handlers);csrf=''
def api(path,method='GET',body=None,expected=200,device=None):
 h={'Content-Type':'application/json'}
 if csrf:h['X-CSRF-Token']=csrf
 if device:h['Authorization']='Bearer '+device
 r=urllib.request.Request(a.base_url+path,data=None if body is None else json.dumps(body).encode(),headers=h,method=method)
 try:
  with client.open(r,timeout=15) as response:code=response.status;value=json.load(response)
 except urllib.error.HTTPError as e:code=e.code;value=json.load(e)
 assert code==expected,(path,code,value)
 return value
assert api('/health')['version']=='0.6.1'
login=api('/api/login','POST',{'username':os.environ['SIM_SMOKE_USER'],'password':os.environ['SIM_SMOKE_PASSWORD']});csrf=login['csrf']
me=api('/api/me');assert any(x['value']=='amazon' for x in me['platform_catalog']),'Set test platform_catalog amazon before running'
wx=api('/api/wechat/status');assert not wx['config']['enabled'] and wx['config']['template_id']=='' and wx['config']['origin']=='','fresh optional channel must be blank and disabled'
tag=str(time.time_ns())
sim=api('/api/sims/onboard','POST',{'phone':'+639'+str(int(tag[-9:])) .zfill(9),'carrier':'Globe','country':'PH','shops':[]});sid=sim['id']
rows=api('/api/sims');assert any(x['id']==sid and x['shops']==[] for x in rows)
linked=api('/api/sims/'+str(sid)+'/shops','PUT',{'shops':[{'name':'Demo Amazon '+tag,'platform':'amazon'}]});shop=linked['shops'][0]
filtered=api('/api/shops?platform=amazon');assert any(x['id']==shop['id'] and x['name'].startswith('Demo Amazon ') for x in filtered)
api('/api/shops?platform=unconfigured',expected=422)
operator=api('/api/admin/users','POST',{'username':'demo-'+tag,'password':'OnlyLocal-'+tag,'role':'operator'})
api('/api/logout','POST',{});csrf=''
csrf=api('/api/login','POST',{'username':'demo-'+tag,'password':'OnlyLocal-'+tag})['csrf']
api('/api/sims',expected=403);api('/api/messages',expected=403)
print('PASS local HTTP API: health, login, optional disabled WeChat, no-shop number, custom platform CRUD/filter and unassigned operator denial')
