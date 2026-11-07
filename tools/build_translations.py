"""Compile Persian runtime catalogs and their editable source."""

from pathlib import Path
import json
import re
import struct

ROOT = Path(__file__).resolve().parents[1]
VERSION = re.search(r'(?m)^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$', (ROOT / 'anar-login.php').read_text(encoding='utf-8-sig')).group(1)
LANG = ROOT / 'languages'
source = LANG / 'anar-toolkit-fa_IR.po'
if not source.exists():
    source = LANG / 'anar-login-fa_IR.po'
entries = dict((json.loads(a), json.loads(b)) for a, b in re.findall(r'^msgid (".*")\nmsgstr (".*")$', source.read_text(encoding='utf-8-sig'), re.M))
entries.update(json.loads((ROOT / 'tools/commerce-fa.json').read_text(encoding='utf-8')))
entries.update(json.loads((ROOT / 'tools/balance-fa.json').read_text(encoding='utf-8')))
entries[''] = f'Project-Id-Version: Anar Toolkit {VERSION}\nLanguage: fa_IR\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\nPlural-Forms: nplurals=2; plural=(n > 1);\nX-Domain: anar-toolkit\n'
ordered = sorted(entries.items())
(LANG / 'anar-toolkit-fa_IR.po').write_text('\n\n'.join('msgid ' + json.dumps(k, ensure_ascii=False) + '\nmsgstr ' + json.dumps(v, ensure_ascii=False) for k, v in ordered) + '\n', encoding='utf-8')
ids, values = b'', b''
id_table, value_table = [], []
n = len(ordered)
offset = 28 + 16 * n
for key, value in ordered:
    raw = key.encode('utf-8')
    id_table.append((len(raw), offset + len(ids)))
    ids += raw + b'\0'
for key, value in ordered:
    raw = value.encode('utf-8')
    value_table.append((len(raw), offset + len(ids) + len(values)))
    values += raw + b'\0'
catalog = struct.pack('<7I', 0x950412de, 0, n, 28, 28 + 8 * n, 0, 0)
catalog += b''.join(struct.pack('<2I', *item) for item in id_table + value_table) + ids + values
(LANG / 'anar-toolkit-fa_IR.mo').write_bytes(catalog)
def php(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"
body = "<?php\nreturn array(\n'project-id-version' => 'Anar Toolkit " + VERSION + "',\n'language' => 'fa_IR',\n'plural-forms' => 'nplurals=2; plural=(n > 1);',\n'messages' => array(\n"
body += ''.join(php(k) + ' => ' + php(v) + ',\n' for k, v in ordered if k)
body += ")\n);\n"
(LANG / 'anar-toolkit-fa_IR.l10n.php').write_text(body, encoding='utf-8', newline='\n')
print(str(n - 1) + ' Persian translations compiled; runtime catalogs are included in the plugin ZIP.')
