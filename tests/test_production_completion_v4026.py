from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def txt(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_version_and_schema():
    p=txt('bluevpn-manager/bluevpn-manager.php')
    assert "BLUEVPN_MANAGER_VERSION', '4.0.27'" in p
    assert "BLUEVPN_MANAGER_SCHEMA_VERSION', '1.5.0'" in p

def test_backup_restore_is_transactional():
    p=txt('bluevpn-manager/includes/class-bluevpn-production.php')
    assert 'START TRANSACTION' in p and 'ROLLBACK' in p and 'COMMIT' in p
    assert "create_backup('pre-restore')" in p
    assert 'validate_backup_json' in p
    assert 'bluevpn-wordpress-backup-v3' in p
    assert 'option_names' in p and "'restored_options'" in p

def test_private_backup_and_health():
    p=txt('bluevpn-manager/includes/class-bluevpn-production.php')
    assert 'bluevpn-private-backups' in p
    assert 'BACKUP_RETENTION = 7' in p
    assert 'health_summary' in p and 'finalize_cutover' in p

def test_admin_plan_and_device_controls():
    p=txt('bluevpn-manager/includes/class-bluevpn-control-center.php')
    for token in ['bluevpn_cc_save_plan','bluevpn_cc_delete_plan','bluevpn_cc_restore_plan','bluevpn_cc_revoke_device','bluevpn_cc_revoke_session','bluevpn_cc_logout_customer']:
        assert token in p

def test_auth_rate_limit_and_revocation():
    a=txt('bluevpn-manager/includes/class-bluevpn-auth.php')
    api=txt('bluevpn-manager/includes/class-bluevpn-api.php')
    assert 'enforce_rate_limit' in a and 'revoke_all_sessions' in a
    assert "enforce_rate_limit('login'" in api
    assert "enforce_rate_limit('register'" in api

def test_sms_provider_state_and_runtime_coverage():
    db=txt('bluevpn-manager/includes/class-bluevpn-db.php')
    sms=txt('bluevpn-manager/includes/class-bluevpn-sms-notifications.php')
    assert 'provider_delivery_status varchar(40)' in db
    assert "'provider_delivery_status'=>'provider_accepted'" in sms
    assert 'runtime_supported_events' in sms
    assert "array_key_exists('data',$response)" in sms
