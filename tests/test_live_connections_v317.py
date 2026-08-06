from datetime import datetime, timedelta, timezone

from sqlalchemy import create_engine, select
from sqlalchemy.orm import Session

from server.blueai import admin_overview, submit_event
from server.database import Base
from server.models import AiLiveConnection, Customer


def seed(db: Session) -> Customer:
    customer = Customer(email="live@example.com", password_hash="x")
    db.add(customer)
    db.commit()
    db.refresh(customer)
    return customer


def heartbeat(**overrides):
    payload = {
        "config_key": "route-live-12345678",
        "device_id": "device-live-1",
        "session_id": "session-live-12345678",
        "operator": "MCI",
        "network_type": "mobile",
        "mode": "balanced",
        "event_type": "heartbeat",
        "success": True,
        "connected": True,
        "tunnel_running": True,
        "vpn_transport": True,
        "internet_verified": True,
        "verification_source": "bluevpn-health",
        "probe_age_ms": 0,
        "heartbeat_seq": 1,
        "started_at": int(datetime.now(timezone.utc).timestamp() * 1000),
        "location_key": "de",
        "location_title": "Germany",
        "ping_ms": 80,
        "health_score": 90,
        "traffic_active": True,
        "download_bytes": 1000,
        "upload_bytes": 200,
    }
    payload.update(overrides)
    return payload


def test_core_running_without_remote_proof_is_not_live():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        customer = seed(db)
        result = submit_event(
            db,
            customer,
            heartbeat(
                internet_verified=False,
                verification_source="",
            ),
        )
        assert result["live"] is False
        assert admin_overview(db)["live_sessions"] == 0


def test_verified_vpn_transport_and_xray_proof_is_live():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        customer = seed(db)
        result = submit_event(db, customer, heartbeat())
        assert result["live"] is True
        overview = admin_overview(db)
        assert overview["live_sessions"] == 1
        assert overview["live_active_traffic"] == 1
        assert overview["live_connections"][0]["verification_source"] == "bluevpn-health"


def test_expired_heartbeat_is_not_counted_live():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        customer = seed(db)
        submit_event(db, customer, heartbeat())
        row = db.scalar(select(AiLiveConnection))
        row.expires_at = datetime.now(timezone.utc) - timedelta(seconds=1)
        db.commit()
        assert admin_overview(db)["live_sessions"] == 0


def test_old_session_disconnect_cannot_kill_new_session():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        customer = seed(db)
        submit_event(db, customer, heartbeat(session_id="new-session-123", heartbeat_seq=2))
        submit_event(
            db,
            customer,
            {
                **heartbeat(session_id="old-session-123"),
                "event_type": "session",
                "connected": False,
                "live_state": "disconnected",
                "success": True,
                "failure_reason": "user_disconnect",
            },
        )
        assert admin_overview(db)["live_sessions"] == 1
