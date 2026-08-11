#!/usr/bin/env python3
from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]

def read(rel):
    return (ROOT/rel).read_text(encoding='utf-8')

def require(cond,msg):
    if not cond:
        raise SystemExit('PRODUCTION_COMPLETION_FAIL: '+msg)

plugin=read('bluevpn-manager/bluevpn-manager.php')
prod=read('bluevpn-manager/includes/class-bluevpn-production.php')
auth=read('bluevpn-manager/includes/class-bluevpn-auth.php')
api=read('bluevpn-manager/includes/class-bluevpn-api.php')
cc=read('bluevpn-manager/includes/class-bluevpn-control-center.php')
db=read('bluevpn-manager/includes/class-bluevpn-db.php')
sms=read('bluevpn-manager/includes/class-bluevpn-sms-notifications.php')

m=re.search(r"BLUEVPN_MANAGER_VERSION'\s*,\s*'(\d+)\.(\d+)\.(\d+)'",plugin)
require(bool(m),'plugin version missing')
require(tuple(map(int,m.groups())) >= (4,0,26),'plugin version must be >= 4.0.26')
require("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.5.0'" in plugin,'schema must be 1.5.0')
require("class-bluevpn-production.php" in plugin and 'BlueVPN_Production::init()' in plugin,'production module not wired')
for token in ['create_backup','restore_from_json','validate_backup_json','health_summary','finalize_cutover']:
    require(token in prod,'missing production capability: '+token)
require("START TRANSACTION" in prod and "ROLLBACK" in prod and "pre-restore" in prod,'restore must be transactional with pre-restore backup')
require("bluevpn-private-backups" in prod and 'BACKUP_RETENTION = 7' in prod,'private backup retention missing')
require('bluevpn-wordpress-backup-v3' in prod and 'option_names' in prod and "restored_options" in prod,'backup must include control-plane options')
for token in ['enforce_rate_limit','revoke_device','revoke_session','revoke_all_sessions']:
    require(token in auth,'auth production control missing: '+token)
require("enforce_rate_limit('login'" in api and "enforce_rate_limit('register'" in api,'password endpoint rate limiting missing')
for token in ['bluevpn_cc_save_plan','bluevpn_cc_delete_plan','bluevpn_cc_restore_plan','bluevpn_cc_revoke_device','bluevpn_cc_revoke_session','bluevpn_cc_logout_customer','bluevpn_cc_restore_backup','bluevpn_cc_finalize_cutover']:
    require(token in cc,'admin action missing: '+token)
require("provider_delivery_status varchar(40)" in db,'provider delivery state column missing')
require("'provider_delivery_status'=>'provider_accepted'" in sms,'provider accepted status not persisted')
require("array_key_exists('data',$response)" in sms,'scalar provider reference is not persisted')
require('runtime_supported_events' in sms and 'runtime_supports' in cc,'SMS runtime coverage marker missing')
print('PRODUCTION_COMPLETION_OK')
