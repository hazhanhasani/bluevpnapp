from __future__ import annotations

import secrets
import uuid
from datetime import datetime, timezone

from sqlalchemy import (
    BigInteger,
    Boolean,
    DateTime,
    ForeignKey,
    Integer,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from .database import Base


def utcnow():
    return datetime.now(timezone.utc)


def subscription_token():
    return secrets.token_urlsafe(32)


class AppSetting(Base):
    __tablename__ = "app_settings"
    id: Mapped[int] = mapped_column(primary_key=True, default=1)
    payload: Mapped[str] = mapped_column(Text, default="{}")
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
        onupdate=utcnow,
    )


class PasarGuardPanel(Base):
    __tablename__ = "pasarguard_panels"
    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(120))
    base_url: Mapped[str] = mapped_column(String(500))
    auth_mode: Mapped[str] = mapped_column(
        String(20),
        default="api_key",
    )
    api_key_enc: Mapped[str] = mapped_column(Text, default="")
    username_enc: Mapped[str] = mapped_column(Text, default="")
    password_enc: Mapped[str] = mapped_column(Text, default="")
    proxy_settings_json: Mapped[str] = mapped_column(
        Text,
        default='{"vless":{}}',
    )
    verify_tls: Mapped[bool] = mapped_column(Boolean, default=True)
    active: Mapped[bool] = mapped_column(Boolean, default=True)
    last_test_ok: Mapped[bool] = mapped_column(Boolean, default=False)
    last_test_message: Mapped[str] = mapped_column(Text, default="")
    last_test_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
    )


class MarzbanPanel(Base):
    __tablename__ = "marzban_panels"
    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(120))
    base_url: Mapped[str] = mapped_column(String(500))
    username_enc: Mapped[str] = mapped_column(Text, default="")
    password_enc: Mapped[str] = mapped_column(Text, default="")
    proxies_json: Mapped[str] = mapped_column(Text, default="{}")
    inbounds_json: Mapped[str] = mapped_column(Text, default="{}")
    verify_tls: Mapped[bool] = mapped_column(Boolean, default=True)
    active: Mapped[bool] = mapped_column(Boolean, default=True)
    last_test_ok: Mapped[bool] = mapped_column(Boolean, default=False)
    last_test_message: Mapped[str] = mapped_column(Text, default="")
    last_test_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
    )


class Plan(Base):
    __tablename__ = "plans"
    id: Mapped[int] = mapped_column(primary_key=True)
    title: Mapped[str] = mapped_column(String(150))
    description: Mapped[str] = mapped_column(Text, default="")
    price_toman: Mapped[int] = mapped_column(Integer)
    duration_days: Mapped[int] = mapped_column(Integer)
    data_limit_gb: Mapped[int] = mapped_column(Integer, default=0)
    device_limit: Mapped[int] = mapped_column(Integer, default=1)
    group_ids_json: Mapped[str] = mapped_column(Text, default="[]")
    active: Mapped[bool] = mapped_column(Boolean, default=True)
    sort_order: Mapped[int] = mapped_column(Integer, default=0)

    panel_id: Mapped[int] = mapped_column(
        ForeignKey("pasarguard_panels.id")
    )
    panel: Mapped[PasarGuardPanel] = relationship()

    marzban_panel_id: Mapped[int | None] = mapped_column(
        ForeignKey("marzban_panels.id")
    )
    marzban_panel: Mapped[MarzbanPanel | None] = relationship()
    marzban_quota_mode: Mapped[str] = mapped_column(
        String(20),
        default="split",
    )

    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
    )


class Customer(Base):
    __tablename__ = "customers"
    id: Mapped[int] = mapped_column(primary_key=True)
    email: Mapped[str] = mapped_column(
        String(255),
        unique=True,
        index=True,
    )
    password_hash: Mapped[str] = mapped_column(Text)
    active: Mapped[bool] = mapped_column(Boolean, default=True)
    plan_id: Mapped[int | None] = mapped_column(ForeignKey("plans.id"))

    panel_id: Mapped[int | None] = mapped_column(
        ForeignKey("pasarguard_panels.id")
    )
    pg_username: Mapped[str] = mapped_column(String(64), default="")
    pg_user_id: Mapped[int | None] = mapped_column(Integer)
    pasarguard_subscription_url: Mapped[str] = mapped_column(
        Text,
        default="",
    )

    marzban_panel_id: Mapped[int | None] = mapped_column(
        ForeignKey("marzban_panels.id")
    )
    marzban_username: Mapped[str] = mapped_column(
        String(64),
        default="",
    )
    marzban_user_id: Mapped[int | None] = mapped_column(Integer)
    marzban_subscription_url: Mapped[str] = mapped_column(
        Text,
        default="",
    )
    marzban_status: Mapped[str] = mapped_column(
        String(40),
        default="inactive",
    )
    marzban_expire: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    marzban_data_limit_bytes: Mapped[int] = mapped_column(
        BigInteger,
        default=0,
    )
    marzban_used_traffic_bytes: Mapped[int] = mapped_column(
        BigInteger,
        default=0,
    )
    marzban_last_error: Mapped[str] = mapped_column(Text, default="")

    subscription_token: Mapped[str] = mapped_column(
        String(100),
        unique=True,
        index=True,
        default=subscription_token,
    )
    subscription_url: Mapped[str] = mapped_column(Text, default="")
    subscription_status: Mapped[str] = mapped_column(
        String(40),
        default="inactive",
    )
    subscription_expire: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    data_limit_bytes: Mapped[int] = mapped_column(
        BigInteger,
        default=0,
    )
    used_traffic_bytes: Mapped[int] = mapped_column(
        BigInteger,
        default=0,
    )
    device_limit: Mapped[int] = mapped_column(Integer, default=1)
    last_sync_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    last_sync_error: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
    )


class CustomerSession(Base):
    __tablename__ = "customer_sessions"
    id: Mapped[int] = mapped_column(primary_key=True)
    customer_id: Mapped[int] = mapped_column(
        ForeignKey("customers.id"),
        index=True,
    )
    token_hash: Mapped[str] = mapped_column(
        String(64),
        unique=True,
        index=True,
    )
    device_id: Mapped[str] = mapped_column(String(180))
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    revoked_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    last_seen_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
    )
    customer: Mapped[Customer] = relationship()


class CustomerDevice(Base):
    __tablename__ = "customer_devices"
    __table_args__ = (
        UniqueConstraint("customer_id", "device_id"),
    )
    id: Mapped[int] = mapped_column(primary_key=True)
    customer_id: Mapped[int] = mapped_column(
        ForeignKey("customers.id"),
        index=True,
    )
    device_id: Mapped[str] = mapped_column(String(180))
    device_name: Mapped[str] = mapped_column(String(180), default="")
    active: Mapped[bool] = mapped_column(Boolean, default=True)
    refresh_token_hash: Mapped[str] = mapped_column(
        String(64),
        default="",
    )
    refresh_expires_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    first_seen_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
    )
    last_seen_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
    )


class PaymentSetting(Base):
    __tablename__ = "payment_settings"
    id: Mapped[int] = mapped_column(primary_key=True, default=1)
    base_url: Mapped[str] = mapped_column(
        String(500),
        default="https://bluepay-production.up.railway.app",
    )
    api_key_enc: Mapped[str] = mapped_column(Text, default="")
    callback_secret_enc: Mapped[str] = mapped_column(Text, default="")
    fee_mode: Mapped[str] = mapped_column(String(30), default="default")
    ttl_minutes: Mapped[int] = mapped_column(Integer, default=30)
    active: Mapped[bool] = mapped_column(Boolean, default=False)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
        onupdate=utcnow,
    )


class Order(Base):
    __tablename__ = "orders"
    id: Mapped[str] = mapped_column(
        String(36),
        primary_key=True,
        default=lambda: str(uuid.uuid4()),
    )
    order_code: Mapped[str] = mapped_column(
        String(100),
        unique=True,
        index=True,
    )
    customer_id: Mapped[int] = mapped_column(ForeignKey("customers.id"))
    plan_id: Mapped[int] = mapped_column(ForeignKey("plans.id"))
    amount_toman: Mapped[int] = mapped_column(Integer)
    payment_id: Mapped[str] = mapped_column(
        String(180),
        default="",
        index=True,
    )
    payment_url: Mapped[str] = mapped_column(Text, default="")
    status: Mapped[str] = mapped_column(String(40), default="created")
    gateway_json: Mapped[str] = mapped_column(Text, default="{}")
    activation_error: Mapped[str] = mapped_column(Text, default="")
    paid_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    activated_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
    )
    customer: Mapped[Customer] = relationship()
    plan: Mapped[Plan] = relationship()


class WebhookDelivery(Base):
    __tablename__ = "webhook_deliveries"
    id: Mapped[int] = mapped_column(primary_key=True)
    delivery_id: Mapped[str] = mapped_column(
        String(180),
        unique=True,
        index=True,
    )
    payment_id: Mapped[str] = mapped_column(String(180), default="")
    event: Mapped[str] = mapped_column(String(80), default="")
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
    )
