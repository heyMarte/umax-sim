#!/usr/bin/env python3
from pathlib import Path
import re
root=Path(__file__).resolve().parents[1];errors=[]
for file in [*root.glob('*.md'),*root.joinpath('docs').rglob('*.md')]:
 for href in re.findall(r'\]\(([^)]+)\)',file.read_text()):
  if href.startswith(('http:','https:','mailto:','#')):continue
  if not (file.parent/href.split('#')[0]).exists():errors.append((str(file.relative_to(root)),href))
if errors:raise SystemExit(str(errors))
print('PASS documentation relative links')
