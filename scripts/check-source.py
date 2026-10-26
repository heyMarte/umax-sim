#!/usr/bin/env python3
"""Check the exact tracked and nonignored candidate source tree, never runtime data."""
from pathlib import Path
import re,subprocess,sys
root=Path(sys.argv[1] if len(sys.argv)>1 else Path(__file__).resolve().parents[1]).resolve()
paths=subprocess.check_output(['git','-C',str(root),'ls-files','--cached','--others','--exclude-standard','-z']).decode().split('\0')
forbidden_parts={'var','backups','.gradle','.kotlin','node_modules','__pycache__','.superpowers'}
original_origin='sms.'+'umaxapi.com'
original_template='dMBpROw7D7T7NDFkP3peTqet49Ry0Qqf1dy31_'+'pZ8Rk'
failures=[]
for name in sorted(set(filter(None,paths))):
 p=root/name
 if p.is_symlink():failures.append((name,'symlink not allowed'));continue
 if set(Path(name).parts)&forbidden_parts or p.name in {'config.php','keyring.php','local.properties','.DS_Store'} or p.suffix in {'.apk','.keystore','.jks','.db','.sqlite','.log'}:
  failures.append((name,'private/runtime/build artifact'));continue
 if not p.is_file():failures.append((name,'missing staged source'));continue
 raw=p.read_bytes()
 try:text=raw.decode('utf-8')
 except UnicodeDecodeError:continue
 for marker in [original_origin,original_template,'/Users/'+'marte/']:
  if marker in text:failures.append((name,'original site or personal path'))
 if re.search(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',text):failures.append((name,'private key'))
if failures:
 for name,reason in failures:print(f'FAIL {name}: {reason}')
 sys.exit(1)
print(f'PASS source privacy: {len(set(filter(None,paths)))} candidate files')
