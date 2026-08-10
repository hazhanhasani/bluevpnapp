from pathlib import Path

path = Path('server/main.py')
text = path.read_text(encoding='utf-8')
changed = False

import_line = 'from .wordpress_migration_bridge import register_wordpress_migration_bridge'
if import_line not in text:
    anchor = 'from .time_locale import TEHRAN_ZONE_NAME, format_jalali'
    if anchor not in text:
        raise SystemExit('Could not find time_locale import anchor in server/main.py')
    text = text.replace(anchor, anchor + '\n' + import_line, 1)
    changed = True

call_line = 'register_wordpress_migration_bridge(app)'
if call_line not in text:
    anchor = "app.add_middleware(\n"
    if anchor not in text:
        raise SystemExit('Could not find app.add_middleware anchor in server/main.py')
    text = text.replace(anchor, call_line + '\n' + anchor, 1)
    changed = True

if changed:
    path.write_text(text, encoding='utf-8')
    print('server/main.py updated for WordPress Migration Bridge')
else:
    print('WordPress Migration Bridge already enabled')
