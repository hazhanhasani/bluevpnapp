from __future__ import annotations

from sqlalchemy import create_engine, select
from sqlalchemy.orm import Session

import server.database as database
from server.models import Customer


def test_nullable_foreign_keys_are_not_synthesized_as_zero(monkeypatch):
    engine = create_engine("sqlite+pysqlite:///:memory:")
    database.Base.metadata.create_all(engine)
    monkeypatch.setattr(database, "ENGINE", engine)

    with Session(engine) as session:
        customer = Customer(
            email="fk-migration-regression@example.com",
            password_hash="test",
        )
        session.add(customer)
        session.commit()
        customer_id = customer.id

    database._migrate_missing_columns()

    with engine.connect() as connection:
        row = connection.execute(
            select(
                Customer.plan_id,
                Customer.panel_id,
                Customer.marzban_panel_id,
                Customer.guardcore_panel_id,
                Customer.phone,
                Customer.phone_verified_at,
            ).where(Customer.id == customer_id)
        ).one()

    assert tuple(row) == (None, None, None, None, None, None)


def test_default_helper_never_invents_foreign_key_parent():
    assert database._default_for_column(Customer.__table__.c.plan_id) is None
    assert database._default_for_column(Customer.__table__.c.panel_id) is None
    assert database._default_for_column(Customer.__table__.c.marzban_panel_id) is None
    assert database._default_for_column(Customer.__table__.c.guardcore_panel_id) is None
