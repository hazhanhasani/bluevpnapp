from sqlalchemy import create_engine
from sqlalchemy.orm import Session

from server.blueai import recommendations, submit_event
from server.database import Base
from server.models import Customer


def payload(operator: str, *, success: bool, jitter: int, ping: int = 90) -> dict:
    return {
        "config_key": "route-12345678",
        "operator": operator,
        "network_type": "4g",
        "mode": "balanced",
        "event_type": "session",
        "success": success,
        "ping_ms": ping,
        "jitter_ms": jitter,
        "packet_loss_x100": 0 if success else 900,
        "duration_seconds": 600 if success else 0,
        "location_key": "de",
        "location_title": "Germany",
    }


def test_operator_circuit_breaker_does_not_block_other_operator():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)

    with Session(engine) as db:
        customer = Customer(email="blueai@example.com", password_hash="x")
        db.add(customer)
        db.commit()
        db.refresh(customer)

        result = None
        for index in range(12):
            result = submit_event(
                db,
                customer,
                payload("MCI", success=index < 2, jitter=80, ping=260),
            )

        assert result is not None
        assert result["blocked_for_operator"] is True
        assert recommendations(db, operator="MCI", network_type="4g", mode="balanced") == []

        for _ in range(15):
            submit_event(
                db,
                customer,
                payload("MTN Irancell", success=True, jitter=7, ping=80),
            )

        irancell_routes = recommendations(
            db,
            operator="Irancell",
            network_type="4g",
            mode="balanced",
        )
        assert irancell_routes
        assert irancell_routes[0]["config_key"] == "route-12345678"
