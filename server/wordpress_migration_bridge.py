from __future__ import annotations

import base64
import hmac
import os
from datetime import date, datetime
from decimal import Decimal
from typing import Any

from fastapi import APIRouter, FastAPI, Header, HTTPException, Query
from sqlalchemy import func, select
from sqlalchemy.sql.schema import Table

from .database import Base, DATABASE_MODE, SessionLocal, database_status
from .security import decrypt
from .version import VERSION, VERSION_CODE

MIGRATION_TABLES = (
    "app_settings",
    "ad_assets",
    "server_locations",
    "pasarguard_panels",
    "marzban_panels",
    "guardcore_panels",
    "plans",
    "customers",
    "otp_challenges",
    "customer_sessions",
    "customer_devices",
    "sms_settings",
    "sms_templates",
    "payment_settings",
    "orders",
    "sms_deliveries",
    "webhook_deliveries",
    "ai_connection_events",
    "ai_live_connections",
    "ai_route_aggregates",
    "ai_feedback",
)

SECRET_COLUMNS: dict[str, set[str]] = {
    "pasarguard_panels": {"api_key_enc", "username_enc", "password_enc"},
    "marzban_panels": {"username_enc", "password_enc"},
    "guardcore_panels": {"api_key_enc", "username_enc", "password_enc"},
    "sms_settings": {"api_key_enc"},
    "payment_settings": {"api_key_enc", "callback_secret_enc"},
}


def _configured_token() -> str:
    return str(os.getenv("WORDPRESS_MIGRATION_TOKEN") or "").strip()


def _require_token(x_bluevpn_migration_token: str | None) -> None:
    expected = _configured_token()
    if len(expected) < 32:
        raise HTTPException(503, "WORDPRESS_MIGRATION_TOKEN is not configured securely")
    submitted = str(x_bluevpn_migration_token or "").strip()
    if not submitted or not hmac.compare_digest(expected, submitted):
        raise HTTPException(401, "Migration token is invalid")


def _table(name: str) -> Table:
    if name not in MIGRATION_TABLES:
        raise HTTPException(404, "Migration table is not allowed")
    table = Base.metadata.tables.get(name)
    if table is None:
        raise HTTPException(404, "Migration table does not exist in this backend")
    return table


def _primary_key(table: Table):
    columns = list(table.primary_key.columns)
    if len(columns) != 1:
        raise HTTPException(409, f"Table {table.name} does not have a single primary key")
    return columns[0]


def _cursor_value(column, raw: str) -> Any:
    if raw == "":
        return None
    try:
        python_type = column.type.python_type
    except Exception:
        python_type = str
    try:
        if python_type is int:
            return int(raw)
        if python_type is float:
            return float(raw)
    except (TypeError, ValueError):
        raise HTTPException(422, "Migration cursor is invalid")
    return raw


def _serialize_value(table_name: str, column_name: str, value: Any) -> Any:
    if column_name in SECRET_COLUMNS.get(table_name, set()):
        # Re-encrypt on the WordPress side. Never log this payload.
        return {"__secret_plain": decrypt(str(value or ""))}
    if value is None or isinstance(value, (str, int, float, bool)):
        return value
    if isinstance(value, (datetime, date)):
        return {"__datetime": value.isoformat()}
    if isinstance(value, (bytes, bytearray, memoryview)):
        return {"__bytes_b64": base64.b64encode(bytes(value)).decode("ascii")}
    if isinstance(value, Decimal):
        return float(value)
    return str(value)


def _row_payload(table_name: str, mapping: Any) -> dict[str, Any]:
    return {
        str(key): _serialize_value(table_name, str(key), value)
        for key, value in dict(mapping).items()
    }


def register_wordpress_migration_bridge(app: FastAPI) -> None:
    router = APIRouter(prefix="/internal/migration/v1", tags=["internal-migration"])

    @router.get("/health")
    def migration_health(x_bluevpn_migration_token: str | None = Header(default=None)):
        _require_token(x_bluevpn_migration_token)
        status = database_status()
        return {
            "success": True,
            "service": "bluevpn-wordpress-migration-bridge",
            "backend_version": VERSION,
            "version_code": VERSION_CODE,
            "database_mode": DATABASE_MODE,
            "schema_version": status.get("schema_version", ""),
            "table_count": len(MIGRATION_TABLES),
        }

    @router.get("/manifest")
    def migration_manifest(x_bluevpn_migration_token: str | None = Header(default=None)):
        _require_token(x_bluevpn_migration_token)
        db = SessionLocal()
        try:
            counts: dict[str, int] = {}
            primary_keys: dict[str, str] = {}
            for name in MIGRATION_TABLES:
                table = _table(name)
                counts[name] = int(db.scalar(select(func.count()).select_from(table)) or 0)
                primary_keys[name] = _primary_key(table).name
            status = database_status()
            return {
                "success": True,
                "backend_version": VERSION,
                "version_code": VERSION_CODE,
                "database_mode": DATABASE_MODE,
                "schema_version": status.get("schema_version", ""),
                "tables": list(MIGRATION_TABLES),
                "table_counts": counts,
                "primary_keys": primary_keys,
            }
        finally:
            db.close()

    @router.get("/export/{table_name}")
    def migration_export(
        table_name: str,
        limit: int = Query(default=250, ge=1, le=1000),
        after: str = Query(default=""),
        x_bluevpn_migration_token: str | None = Header(default=None),
    ):
        _require_token(x_bluevpn_migration_token)
        table = _table(table_name)
        pk = _primary_key(table)
        after_value = _cursor_value(pk, after)
        db = SessionLocal()
        try:
            total = int(db.scalar(select(func.count()).select_from(table)) or 0)
            statement = select(table).order_by(pk.asc()).limit(limit + 1)
            if after_value is not None:
                statement = statement.where(pk > after_value)
            rows = list(db.execute(statement).mappings().all())
            has_more = len(rows) > limit
            page = rows[:limit]
            payload = [_row_payload(table_name, row) for row in page]
            next_cursor = ""
            if page:
                next_cursor = str(page[-1][pk.name])
            return {
                "success": True,
                "table": table_name,
                "primary_key": pk.name,
                "rows": payload,
                "returned": len(payload),
                "total": total,
                "next_cursor": next_cursor if has_more else "",
                "done": not has_more,
            }
        finally:
            db.close()

    app.include_router(router)
