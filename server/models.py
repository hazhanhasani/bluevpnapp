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
    Float,
    Index,
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


class GuardCorePanel(Base):
    __tablename__ = "guardcore_panels"
    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(120))
    base_url: Mapped[str] = mapped_column(String(500))
    global_subscription_url: Mapped[str] = mapped_column(
        Text,
        default="",
    )
    auth_mode: Mapped[str] = mapped_column(
        String(20),
        default="manual",
    )
    # manual: admin pastes the generated subscription link through Telegram.
    # api_key/password: optional automatic GuardCore integration.
    api_key_enc: Mapped[str] = mapped_column(Text, default="")
    username_enc: Mapped[str] = mapped_column(Text, default="")
    password_enc: Mapped[str] = mapped_column(Text, default="")
    usage_unit: Mapped[str] = mapped_column(
        String(20),
        default="bytes",
    )
    expire_mode: Mapped[str] = mapped_column(
        String(20),
        default="days",
    )
    services_json: Mapped[str] = mapped_column(Text, default="[]")
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
    deleted: Mapped[bool] = mapped_column(Boolean, default=False)
    deleted_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
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

    guardcore_panel_id: Mapped[int | None] = mapped_column(
        ForeignKey("guardcore_panels.id")
    )
    guardcore_panel: Mapped[GuardCorePanel | None] = relationship()
    guardcore_service_ids_json: Mapped[str] = mapped_column(
        Text,
        default="[]",
    )
    multi_provider_quota_mode: Mapped[str] = mapped_column(
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
    phone: Mapped[str | None] = mapped_column(
        String(20),
        unique=True,
        index=True,
        nullable=True,
    )
    phone_verified_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    auth_method: Mapped[str | None] = mapped_column(
        String(24),
        default="legacy_email",
        nullable=True,
    )
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

    guardcore_panel_id: Mapped[int | None] = mapped_column(
        ForeignKey("guardcore_panels.id")
    )
    guardcore_username: Mapped[str] = mapped_column(
        String(64),
        default="",
    )
    guardcore_subscription_id: Mapped[int | None] = mapped_column(Integer)
    guardcore_subscription_url: Mapped[str] = mapped_column(
        Text,
        default="",
    )
    guardcore_status: Mapped[str] = mapped_column(
        String(40),
        default="inactive",
    )
    guardcore_expire: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    guardcore_data_limit_bytes: Mapped[int] = mapped_column(
        BigInteger,
        default=0,
    )
    guardcore_used_traffic_bytes: Mapped[int] = mapped_column(
        BigInteger,
        default=0,
    )
    guardcore_last_error: Mapped[str] = mapped_column(Text, default="")

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


class OtpChallenge(Base):
    __tablename__ = "otp_challenges"
    __table_args__ = (
        Index("ix_otp_phone_purpose_created", "phone", "purpose", "created_at"),
    )

    id: Mapped[str] = mapped_column(
        String(36),
        primary_key=True,
        default=lambda: str(uuid.uuid4()),
    )
    phone: Mapped[str] = mapped_column(String(20), index=True)
    purpose: Mapped[str] = mapped_column(String(24), default="auth", index=True)
    customer_id: Mapped[int | None] = mapped_column(
        ForeignKey("customers.id"),
        nullable=True,
        index=True,
    )
    device_id: Mapped[str] = mapped_column(String(180), default="")
    code_hash: Mapped[str] = mapped_column(Text)
    attempts: Mapped[int] = mapped_column(Integer, default=0)
    max_attempts: Mapped[int] = mapped_column(Integer, default=5)
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), index=True)
    consumed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
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
    previous_refresh_token_hash: Mapped[str] = mapped_column(
        String(64),
        default="",
    )
    previous_refresh_expires_at: Mapped[datetime | None] = mapped_column(
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


class SmsSetting(Base):
    __tablename__ = "sms_settings"
    id: Mapped[int] = mapped_column(primary_key=True, default=1)
    provider: Mapped[str] = mapped_column(String(40), default="ippanel")
    base_url: Mapped[str] = mapped_column(
        String(500),
        default="https://edge.ippanel.com/v1",
    )
    api_key_enc: Mapped[str] = mapped_column(Text, default="")
    from_number: Mapped[str] = mapped_column(String(32), default="")
    pattern_code: Mapped[str] = mapped_column(String(120), default="")
    parameter_name: Mapped[str] = mapped_column(String(80), default="code")
    otp_length: Mapped[int] = mapped_column(Integer, default=5)
    otp_ttl_seconds: Mapped[int] = mapped_column(Integer, default=120)
    resend_seconds: Mapped[int] = mapped_column(Integer, default=60)
    active: Mapped[bool] = mapped_column(Boolean, default=False)
    notification_active: Mapped[bool] = mapped_column(Boolean, default=False)
    reminder_days_json: Mapped[str] = mapped_column(Text, default="[3,2,1]")
    low_volume_threshold_gb: Mapped[int] = mapped_column(Integer, default=5)
    retry_max_attempts: Mapped[int] = mapped_column(Integer, default=3)
    verify_tls: Mapped[bool] = mapped_column(Boolean, default=True)
    last_test_ok: Mapped[bool] = mapped_column(Boolean, default=False)
    last_test_message: Mapped[str] = mapped_column(Text, default="")
    last_test_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
        onupdate=utcnow,
    )


class SmsTemplate(Base):
    __tablename__ = "sms_templates"
    key: Mapped[str] = mapped_column(String(80), primary_key=True)
    title: Mapped[str] = mapped_column(String(160))
    category: Mapped[str] = mapped_column(String(80), default="")
    body: Mapped[str] = mapped_column(Text, default="")
    variables_json: Mapped[str] = mapped_column(Text, default="[]")
    pattern_code: Mapped[str] = mapped_column(String(160), default="")
    enabled: Mapped[bool] = mapped_column(Boolean, default=False)
    broadcast: Mapped[bool] = mapped_column(Boolean, default=False)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
        onupdate=utcnow,
    )


class SmsDelivery(Base):
    __tablename__ = "sms_deliveries"
    __table_args__ = (
        Index("ix_sms_delivery_status_next", "status", "next_attempt_at"),
        Index("ix_sms_delivery_customer_created", "customer_id", "created_at"),
        UniqueConstraint("dedupe_key"),
    )
    id: Mapped[str] = mapped_column(
        String(36),
        primary_key=True,
        default=lambda: str(uuid.uuid4()),
    )
    event_key: Mapped[str] = mapped_column(String(80), index=True)
    customer_id: Mapped[int | None] = mapped_column(
        ForeignKey("customers.id"), nullable=True, index=True
    )
    order_id: Mapped[str | None] = mapped_column(
        ForeignKey("orders.id"), nullable=True, index=True
    )
    phone: Mapped[str] = mapped_column(String(20), index=True)
    params_json: Mapped[str] = mapped_column(Text, default="{}")
    dedupe_key: Mapped[str] = mapped_column(String(200), unique=True)
    status: Mapped[str] = mapped_column(String(30), default="pending", index=True)
    attempts: Mapped[int] = mapped_column(Integer, default=0)
    max_attempts: Mapped[int] = mapped_column(Integer, default=3)
    provider_message_id: Mapped[str] = mapped_column(String(180), default="")
    response_json: Mapped[str] = mapped_column(Text, default="")
    last_error: Mapped[str] = mapped_column(Text, default="")
    next_attempt_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), index=True
    )
    sent_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow
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
    expires_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True),
        index=True,
    )
    checkout_opened_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True),
    )
    checkout_last_seen_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True),
    )
    checkout_closed_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True),
        index=True,
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


class AiConnectionEvent(Base):
    __tablename__ = "ai_connection_events"
    __table_args__ = (
        Index("ix_ai_event_context", "operator", "network_type", "created_at"),
        Index("ix_ai_event_route", "config_key", "created_at"),
    )

    id: Mapped[int] = mapped_column(primary_key=True)
    customer_id: Mapped[int] = mapped_column(ForeignKey("customers.id"), index=True)
    device_id: Mapped[str] = mapped_column(String(80), default="", index=True)
    config_key: Mapped[str] = mapped_column(String(80), default="", index=True)
    location_key: Mapped[str] = mapped_column(String(24), default="unknown", index=True)
    location_title: Mapped[str] = mapped_column(String(100), default="نامشخص")
    operator: Mapped[str] = mapped_column(String(100), default="unknown", index=True)
    network_type: Mapped[str] = mapped_column(String(30), default="unknown", index=True)
    mode: Mapped[str] = mapped_column(String(30), default="balanced", index=True)
    event_type: Mapped[str] = mapped_column(String(30), default="session")
    success: Mapped[bool] = mapped_column(Boolean, default=False, index=True)
    ping_ms: Mapped[int] = mapped_column(Integer, default=0)
    jitter_ms: Mapped[int] = mapped_column(Integer, default=0)
    packet_loss_x100: Mapped[int] = mapped_column(Integer, default=0)
    duration_seconds: Mapped[int] = mapped_column(Integer, default=0)
    health_score: Mapped[int] = mapped_column(Integer, default=0)
    download_bytes: Mapped[int] = mapped_column(BigInteger, default=0)
    upload_bytes: Mapped[int] = mapped_column(BigInteger, default=0)
    failure_reason: Mapped[str] = mapped_column(Text, default="")
    app_version: Mapped[str] = mapped_column(String(40), default="")
    android_version: Mapped[str] = mapped_column(String(40), default="")
    device_model: Mapped[str] = mapped_column(String(160), default="")
    hour_bucket: Mapped[int] = mapped_column(Integer, default=0, index=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, index=True)


class AiLiveConnection(Base):
    """Current verified VPN session for one customer device.

    This is deliberately separate from the append-only AI event stream.  A
    heartbeat is considered live only when the Android client proves all three
    conditions: the core is running, an Android VPN transport exists, and a
    fresh HTTP request succeeds through the local Xray proxy.
    """

    __tablename__ = "ai_live_connections"
    __table_args__ = (
        UniqueConstraint(
            "customer_id",
            "device_id",
            name="uq_ai_live_customer_device",
        ),
        Index(
            "ix_ai_live_verified_expiry",
            "connected",
            "verified",
            "expires_at",
        ),
        Index("ix_ai_live_operator", "operator", "expires_at"),
    )

    id: Mapped[int] = mapped_column(primary_key=True)
    customer_id: Mapped[int] = mapped_column(
        ForeignKey("customers.id"),
        index=True,
    )
    device_id: Mapped[str] = mapped_column(String(80), default="", index=True)
    session_id: Mapped[str] = mapped_column(String(80), default="", index=True)
    config_key: Mapped[str] = mapped_column(String(80), default="", index=True)
    location_key: Mapped[str] = mapped_column(
        String(24),
        default="unknown",
        index=True,
    )
    location_title: Mapped[str] = mapped_column(
        String(100),
        default="نامشخص",
    )
    operator: Mapped[str] = mapped_column(
        String(100),
        default="unknown",
        index=True,
    )
    network_type: Mapped[str] = mapped_column(
        String(30),
        default="unknown",
        index=True,
    )
    mode: Mapped[str] = mapped_column(
        String(30),
        default="balanced",
        index=True,
    )
    connected: Mapped[bool] = mapped_column(
        Boolean,
        default=False,
        index=True,
    )
    verified: Mapped[bool] = mapped_column(
        Boolean,
        default=False,
        index=True,
    )
    tunnel_running: Mapped[bool] = mapped_column(Boolean, default=False)
    vpn_transport: Mapped[bool] = mapped_column(Boolean, default=False)
    verification_source: Mapped[str] = mapped_column(
        String(80),
        default="",
    )
    ping_ms: Mapped[int] = mapped_column(Integer, default=0)
    health_score: Mapped[int] = mapped_column(Integer, default=0)
    download_bytes: Mapped[int] = mapped_column(BigInteger, default=0)
    upload_bytes: Mapped[int] = mapped_column(BigInteger, default=0)
    traffic_active: Mapped[bool] = mapped_column(Boolean, default=False)
    last_traffic_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    heartbeat_seq: Mapped[int] = mapped_column(BigInteger, default=0)
    started_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    last_verified_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True),
        index=True,
    )
    last_seen_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
        index=True,
    )
    expires_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=utcnow,
        index=True,
    )
    disconnected_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True)
    )
    disconnect_reason: Mapped[str] = mapped_column(Text, default="")
    app_version: Mapped[str] = mapped_column(String(40), default="")
    android_version: Mapped[str] = mapped_column(String(40), default="")
    device_model: Mapped[str] = mapped_column(String(160), default="")




class AiRouteAggregate(Base):
    __tablename__ = "ai_route_aggregates"
    __table_args__ = (
        UniqueConstraint(
            "config_key", "operator", "network_type", "mode", "hour_bucket",
            name="uq_ai_route_context",
        ),
        Index("ix_ai_route_rank", "operator", "network_type", "mode", "score"),
    )

    id: Mapped[int] = mapped_column(primary_key=True)
    config_key: Mapped[str] = mapped_column(String(80), index=True)
    location_key: Mapped[str] = mapped_column(String(24), default="unknown", index=True)
    location_title: Mapped[str] = mapped_column(String(100), default="نامشخص")
    operator: Mapped[str] = mapped_column(String(100), default="unknown", index=True)
    network_type: Mapped[str] = mapped_column(String(30), default="unknown", index=True)
    mode: Mapped[str] = mapped_column(String(30), default="balanced", index=True)
    hour_bucket: Mapped[int] = mapped_column(Integer, default=0, index=True)
    sample_count: Mapped[int] = mapped_column(Integer, default=0)
    success_count: Mapped[int] = mapped_column(Integer, default=0)
    failure_count: Mapped[int] = mapped_column(Integer, default=0)
    total_duration_seconds: Mapped[int] = mapped_column(BigInteger, default=0)
    total_ping_ms: Mapped[int] = mapped_column(BigInteger, default=0)
    ping_samples: Mapped[int] = mapped_column(Integer, default=0)
    total_jitter_ms: Mapped[int] = mapped_column(BigInteger, default=0)
    jitter_samples: Mapped[int] = mapped_column(Integer, default=0)
    total_packet_loss_x100: Mapped[int] = mapped_column(BigInteger, default=0)
    score: Mapped[int] = mapped_column(Integer, default=50, index=True)
    success_rate: Mapped[float] = mapped_column(Float, default=0.0)
    average_ping_ms: Mapped[float] = mapped_column(Float, default=0.0)
    average_duration_seconds: Mapped[float] = mapped_column(Float, default=0.0)
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, onupdate=utcnow, index=True)


class AiFeedback(Base):
    __tablename__ = "ai_feedback"
    id: Mapped[int] = mapped_column(primary_key=True)
    customer_id: Mapped[int] = mapped_column(ForeignKey("customers.id"), index=True)
    rating: Mapped[int] = mapped_column(Integer, default=5)
    category: Mapped[str] = mapped_column(String(50), default="general")
    message: Mapped[str] = mapped_column(Text, default="")
    diagnostics_json: Mapped[str] = mapped_column(Text, default="{}")
    app_version: Mapped[str] = mapped_column(String(40), default="")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, index=True)
