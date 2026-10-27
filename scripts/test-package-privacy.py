#!/usr/bin/env python3
from pathlib import Path
import subprocess,sys
root=Path(__file__).resolve().parents[1]
for name in ['server/preview.png','server/database/export.sql','server/releases/old.zip']:
 p=root/name
 assert not p.exists(),name
 p.parent.mkdir(exist_ok=True);p.write_bytes(b'private fixture - never publish')
 try:
  r=subprocess.run([sys.executable,str(root/'scripts/check-source.py')],capture_output=True,text=True)
  assert r.returncode!=0, 'Private unreviewed path accepted: '+name
  r=subprocess.run([sys.executable,str(root/'scripts/package.py')],capture_output=True,text=True)
  assert r.returncode!=0,'Package accepted private path: '+name
 finally:p.unlink()
print('PASS package rejects unreviewed screenshot, database export and old release ZIP')
