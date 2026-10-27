#!/usr/bin/env python3
"""Make explicit source, fresh-deploy and release.json update archives; no private data."""
from pathlib import Path
import hashlib,json,subprocess,zipfile
root=Path(__file__).resolve().parents[1];out=root/'dist';out.mkdir(exist_ok=True)
subprocess.run(['python3',str(root/'scripts/check-source.py')],check=True)
files=json.loads((root/'source-manifest.json').read_text())['files']
def content(path):
 f=root/path
 if f.is_symlink():raise RuntimeError('Symlink in package')
 return f.read_bytes()
def entry(z,name,data,executable=False):
 i=zipfile.ZipInfo(name);i.create_system=3;i.external_attr=(0o100755 if executable else 0o100644)<<16;i.compress_type=zipfile.ZIP_DEFLATED;z.writestr(i,data)
def archive(name,mapping):
 with zipfile.ZipFile(out/name,'w') as z:
  for path,raw in sorted(mapping.items()):entry(z,path,raw,path.endswith(('.sh','gradlew')))
 with zipfile.ZipFile(out/name) as z:
  assert z.testzip() is None
  for path in z.namelist():
   assert not set(Path(path).parts)&{'var','backups','build','.gradle','.kotlin','node_modules','__pycache__'},path
   assert Path(path).name not in {'config.php','keyring.php','local.properties'},path
source={f'umax-sim/{p}':content(p) for p in files};archive('umax-sim-source-0.6.0.zip',source)
deploy={p:raw for p,raw in source.items() if not p.startswith(('umax-sim/android/','umax-sim/.github/','umax-sim/scripts/')) and '/tests/' not in p and not p.endswith('/package.json')};archive('umax-sim-deploy-0.6.0.zip',deploy)
updates={}
for p in files:
 rel=p.removeprefix('server/')
 if not p.startswith('server/'):continue
 if rel.startswith(('src/','bin/','public/','docs/')) and Path(rel).suffix in {'.php','.sh','.js','.css','.html','.svg','.png','.woff','.woff2','.md'}:updates[rel]=content(p)
updates['README.md']=content('README.md')
manifest={'format':1,'version':'0.6.0','min_version':'0.4.3','notes':'Open-source self-hosted configuration and platform catalog; App published separately.','files':{p:{'size':len(raw),'sha256':hashlib.sha256(raw).hexdigest()} for p,raw in updates.items()}}
archive('umax-sim-online-release-0.6.0.zip',updates|{'release.json':json.dumps(manifest,ensure_ascii=False,indent=2).encode()})
(out/'SHA256SUMS').write_text(''.join(hashlib.sha256(p.read_bytes()).hexdigest()+'  '+p.name+'\n' for p in sorted(out.glob('*.zip'))))
print('PASS three allowlist archives and SHA256SUMS in dist/')
