from __future__ import annotations

import hashlib
import json
import logging
import os
import threading
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Generator
from urllib.parse import quote_plus

from sqlalchemy import (
    MetaData,
    Table,
    create_engine,
    inspect,
    select,
    text,
)
from sqlalchemy.engine import Engine, URL, make_url
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import DeclarativeBase, Session, sessionmaker

logger = logging.getLogger("bluevpn.database")

SCHEMA_VERSION = "5"
DATA_DIR = Path(os.getenv("DATA_DIR", "/app/data"))
DATA_DIR.mkdir(parents=True, exist_ok=True)
SQLITE_PATH = DATA_DIR / "bluevpn.db"
SQLITE_URL = f"sqlite:///{SQLITE_PATH}"

DATABASE_ERROR = ""
DATABASE_READY = False
DATABASE_MODE = "uninitialized"
DATABASE_URL_SOURCE = ""
DATABASE_PERSISTENT = False
MIGRATION_REPORT: dict[str, Any] = {
    "created_tables": [],
    "added_columns": [],
    "legacy_copies": [],
    "imported_rows": 0,
    "schema_version": SCHEMA_VERSION,
}

_init_lock = threading.Lock()
_initialized = False


class Base(DeclarativeBase):
    pass


class DatabaseSetupError(RuntimeError):
    pass


def _env_bool(name: str, default: bool) -> bool:
    value = os.getenv(name)
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


def _railway_detected() -> bool:
    names = (
        "RAILWAY_PROJECT_ID",
        "RAILWAY_SERVICE_ID",
        "RAILWAY_DEPLOYMENT_ID",
        "RAILWAY_ENVIRONMENT",
        "RAILWAY_ENVIRONMENT_NAME",
        "RAILWAY_STATIC_URL",
    )
    return any(os.getenv(name, "").strip() for name in names)


def _normalize_url(value: str | None) -> str | None:
    if not value:
        return None

    value = value.strip()
    if (
        not value
        or "${{" in value
        or "}}" in value
        or value.lower() in {"null", "none"}
    ):
        return None

    if value.startswith("postgres://"):
        return "postgresql+psycopg://" + value.removeprefix("postgres://")
    if value.startswith("postgresql://"):
        return "postgresql+psycopg://" + value.removeprefix("postgresql://")
    if value.startswith(("postgresql+psycopg://", "sqlite://")):
        return value
    return None


def _url_from_pg_variables() -> str | None:
    host = (
        os.getenv("PGHOST")
        or os.getenv("POSTGRES_HOST")
        or os.getenv("POSTGRES_PRIVATE_HOST")
        or ""
    ).strip()
    port = (
        os.getenv("PGPORT")
        or os.getenv("POSTGRES_PORT")
        or "5432"
    ).strip()
    user = (
        os.getenv("PGUSER")
        or os.getenv("POSTGRES_USER")
        or ""
    ).strip()
    password = (
        os.getenv("PGPASSWORD")
        or os.getenv("POSTGRES_PASSWORD")
        or ""
    )
    database = (
        os.getenv("PGDATABASE")
        or os.getenv("POSTGRES_DB")
        or os.getenv("POSTGRES_DATABASE")
        or ""
    ).strip()

    if not host or not user or not database:
        return None

    return (
        "postgresql+psycopg://"
        f"{quote_plus(user)}:{quote_plus(password)}"
        f"@{host}:{port}/{quote_plus(database)}"
    )


def _resolve_database_url() -> tuple[str | None, str]:
    candidates = (
        "DATABASE_URL",
        "DATABASE_PRIVATE_URL",
        "POSTGRES_URL",
        "POSTGRES_PRIVATE_URL",
        "DATABASE_PUBLIC_URL",
        "PGURL",
    )

    for name in candidates:
        normalized = _normalize_url(os.getenv(name))
        if normalized:
            return normalized, name

    built = _url_from_pg_variables()
    if built:
        return built, "PGHOST/PGUSER/PGDATABASE"

    return None, ""


def _make_engine(url: str) -> Engine:
    kwargs: dict[str, Any] = {
        "pool_pre_ping": True,
        "future": True,
    }

    if url.startswith("sqlite"):
        kwargs["connect_args"] = {
            "check_same_thread": False,
            "timeout": 30,
        }
    else:
        kwargs["connect_args"] = {
            "connect_timeout": int(os.getenv("DB_CONNECT_TIMEOUT", "8")),
        }
        kwargs["pool_recycle"] = 300
        kwargs["pool_size"] = int(os.getenv("DB_POOL_SIZE", "5"))
        kwargs["max_overflow"] = int(os.getenv("DB_MAX_OVERFLOW", "10"))

    engine = create_engine(url, **kwargs)
    with engine.connect() as connection:
        connection.execute(text("SELECT 1"))
    return engine


def _try_create_postgres_database(url: str) -> None:
    try:
        parsed = make_url(url)
        target_database = parsed.database
        if not target_database or target_database == "postgres":
            return

        admin_database = os.getenv("PGADMIN_DATABASE", "postgres")
        admin_url = parsed.set(database=admin_database)

        admin_engine = create_engine(
            admin_url,
            isolation_level="AUTOCOMMIT",
            connect_args={
                "connect_timeout": int(os.getenv("DB_CONNECT_TIMEOUT", "8"))
            },
        )
        try:
            with admin_engine.connect() as connection:
                exists = connection.scalar(
                    text(
                        "SELECT 1 FROM pg_database "
                        "WHERE datname = :database"
                    ),
                    {"database": target_database},
                )
                if exists:
                    return

                quoted = target_database.replace('"', '""')
                connection.execute(
                    text(f'CREATE DATABASE "{quoted}"')
                )
                logger.warning(
                    "Created missing PostgreSQL database: %s",
                    target_database,
                )
        finally:
            admin_engine.dispose()
    except Exception:
        logger.exception(
            "Automatic PostgreSQL database creation was not possible"
        )


def _connect_with_retry(url: str) -> Engine:
    retries = max(
        1,
        int(
            os.getenv(
                "DB_CONNECT_RETRIES",
                "3",
            )
        ),
    )
    delay = max(
        1.0,
        float(os.getenv("DB_CONNECT_RETRY_SECONDS", "2")),
    )

    last_error: Exception | None = None
    create_attempted = False

    for attempt in range(1, retries + 1):
        try:
            return _make_engine(url)
        except Exception as exc:
            last_error = exc
            message = str(exc).lower()

            if (
                not create_attempted
                and url.startswith("postgresql")
                and (
                    "does not exist" in message
                    or "invalidcatalogname" in message
                )
            ):
                create_attempted = True
                _try_create_postgres_database(url)

            if attempt < retries:
                logger.warning(
                    "Database connection attempt %s/%s failed; retrying",
                    attempt,
                    retries,
                )
                time.sleep(delay)

    raise DatabaseSetupError(
        "اتصال PostgreSQL پس از چند بار تلاش ناموفق بود: "
        f"{last_error}"
    )


RESOLVED_URL, DATABASE_URL_SOURCE = _resolve_database_url()
REQUIRE_POSTGRES = _env_bool(
    "DB_REQUIRE_POSTGRES",
    _railway_detected(),
)
ALLOW_SQLITE_FALLBACK = _env_bool(
    "ALLOW_SQLITE_FALLBACK",
    not REQUIRE_POSTGRES,
)

try:
    if RESOLVED_URL:
        ENGINE = _connect_with_retry(RESOLVED_URL)
        DATABASE_MODE = (
            "postgres"
            if RESOLVED_URL.startswith("postgresql")
            else "sqlite_local"
        )
        DATABASE_PERSISTENT = DATABASE_MODE == "postgres"
    elif REQUIRE_POSTGRES:
        raise DatabaseSetupError(
            "Railway شناسایی شد اما PostgreSQL تنظیم نشده است. "
            "متغیر DATABASE_URL یا DATABASE_PRIVATE_URL یا متغیرهای "
            "PGHOST/PGUSER/PGPASSWORD/PGDATABASE باید از سرویس Postgres "
            "در دسترس باشند. برای جلوگیری از حذف اطلاعات، SQLite موقت "
            "در محیط Railway دیگر فعال نمی‌شود."
        )
    else:
        ENGINE = _connect_with_retry(SQLITE_URL)
        DATABASE_MODE = "sqlite_local"
        DATABASE_URL_SOURCE = "DATA_DIR/bluevpn.db"
        DATABASE_PERSISTENT = False
except Exception as exc:
    DATABASE_ERROR = str(exc)
    if ALLOW_SQLITE_FALLBACK and not REQUIRE_POSTGRES:
        logger.exception(
            "Configured database failed; using local SQLite for development"
        )
        ENGINE = _connect_with_retry(SQLITE_URL)
        DATABASE_MODE = "sqlite_local"
        DATABASE_URL_SOURCE = "DATA_DIR/bluevpn.db"
        DATABASE_PERSISTENT = False
    else:
        logger.exception("Persistent database initialization failed")
        raise

SessionLocal = sessionmaker(
    bind=ENGINE,
    autoflush=False,
    expire_on_commit=False,
)


LEGACY_COLUMNS: dict[str, dict[str, tuple[str, bool]]] = {
    "pasarguard_panels": {
        "api_key_enc": ("api_key_encrypted", True),
        "username_enc": ("username_encrypted", True),
        "password_enc": ("password_encrypted", True),
    },
    "payment_settings": {
        "api_key_enc": ("api_key_encrypted", True),
        "callback_secret_enc": ("callback_secret_encrypted", True),
    },
    "customers": {
        "pg_username": ("pasarguard_username", True),
        "pg_user_id": ("pasarguard_user_id", False),
    },
    "orders": {
        "gateway_json": ("gateway_payload_json", True),
    },
}


def _quoted(name: str) -> str:
    return ENGINE.dialect.identifier_preparer.quote(name)


def _default_for_column(column: Any) -> Any:
    if column.default is not None:
        default = column.default.arg
        if not callable(default):
            return default

    python_type = None
    try:
        python_type = column.type.python_type
    except Exception:
        pass

    if python_type is bool:
        return False
    if python_type is int:
        return 0
    if python_type is str:
        return ""
    if python_type is datetime:
        return datetime.now(timezone.utc)
    return None


def _ensure_schema_meta() -> None:
    with ENGINE.begin() as connection:
        connection.execute(
            text(
                "CREATE TABLE IF NOT EXISTS bluevpn_schema_meta ("
                "key VARCHAR(100) PRIMARY KEY,"
                "value TEXT NOT NULL,"
                "updated_at TIMESTAMP"
                ")"
            )
        )


def _set_meta(key: str, value: str) -> None:
    with ENGINE.begin() as connection:
        if DATABASE_MODE == "postgres":
            connection.execute(
                text(
                    "INSERT INTO bluevpn_schema_meta "
                    "(key,value,updated_at) "
                    "VALUES (:key,:value,:updated_at) "
                    "ON CONFLICT (key) DO UPDATE SET "
                    "value=EXCLUDED.value,"
                    "updated_at=EXCLUDED.updated_at"
                ),
                {
                    "key": key,
                    "value": value,
                    "updated_at": datetime.now(timezone.utc),
                },
            )
        else:
            connection.execute(
                text(
                    "INSERT INTO bluevpn_schema_meta "
                    "(key,value,updated_at) "
                    "VALUES (:key,:value,:updated_at) "
                    "ON CONFLICT(key) DO UPDATE SET "
                    "value=excluded.value,"
                    "updated_at=excluded.updated_at"
                ),
                {
                    "key": key,
                    "value": value,
                    "updated_at": datetime.now(timezone.utc),
                },
            )


def _get_meta(key: str) -> str:
    try:
        with ENGINE.connect() as connection:
            return (
                connection.scalar(
                    text(
                        "SELECT value FROM bluevpn_schema_meta "
                        "WHERE key=:key"
                    ),
                    {"key": key},
                )
                or ""
            )
    except Exception:
        return ""


def _migrate_missing_columns() -> None:
    inspector = inspect(ENGINE)

    for table in Base.metadata.sorted_tables:
        if not inspector.has_table(table.name):
            continue

        existing = {
            item["name"]
            for item in inspect(ENGINE).get_columns(table.name)
        }

        for column in table.columns:
            if column.name in existing or column.primary_key:
                continue

            type_sql = column.type.compile(
                dialect=ENGINE.dialect
            )
            sql = (
                f"ALTER TABLE {_quoted(table.name)} "
                f"ADD COLUMN {_quoted(column.name)} {type_sql}"
            )

            with ENGINE.begin() as connection:
                connection.execute(text(sql))

            MIGRATION_REPORT["added_columns"].append(
                f"{table.name}.{column.name}"
            )
            existing.add(column.name)

    # Copy values from older column names into current columns.
    for table_name, mappings in LEGACY_COLUMNS.items():
        if not inspect(ENGINE).has_table(table_name):
            continue

        columns = {
            item["name"]
            for item in inspect(ENGINE).get_columns(table_name)
        }

        for target, (legacy, is_text) in mappings.items():
            if target not in columns or legacy not in columns:
                continue

            target_q = _quoted(target)
            legacy_q = _quoted(legacy)
            table_q = _quoted(table_name)

            where = (
                f"({target_q} IS NULL OR {target_q} = '')"
                if is_text
                else f"{target_q} IS NULL"
            )

            with ENGINE.begin() as connection:
                result = connection.execute(
                    text(
                        f"UPDATE {table_q} "
                        f"SET {target_q} = {legacy_q} "
                        f"WHERE {where} AND {legacy_q} IS NOT NULL"
                    )
                )

            if result.rowcount:
                MIGRATION_REPORT["legacy_copies"].append(
                    f"{table_name}.{legacy}->{target}:"
                    f"{result.rowcount}"
                )

    # Populate safe defaults for newly added nullable columns.
    for table in Base.metadata.sorted_tables:
        if not inspect(ENGINE).has_table(table.name):
            continue

        columns = {
            item["name"]
            for item in inspect(ENGINE).get_columns(table.name)
        }

        for column in table.columns:
            if column.name not in columns or column.primary_key:
                continue

            default = _default_for_column(column)
            if default is None:
                continue

            with ENGINE.begin() as connection:
                connection.execute(
                    text(
                        f"UPDATE {_quoted(table.name)} "
                        f"SET {_quoted(column.name)} = :value "
                        f"WHERE {_quoted(column.name)} IS NULL"
                    ),
                    {"value": default},
                )

    # Create any missing indexes after column reconciliation.
    for table in Base.metadata.sorted_tables:
        for index in table.indexes:
            try:
                index.create(bind=ENGINE, checkfirst=True)
            except Exception:
                logger.exception(
                    "Could not create index %s",
                    index.name,
                )


def _legacy_sqlite_path() -> Path:
    value = os.getenv("LEGACY_SQLITE_PATH", "").strip()
    return Path(value) if value else SQLITE_PATH


def _import_legacy_sqlite_if_present() -> int:
    if DATABASE_MODE != "postgres":
        return 0
    if not _env_bool("MIGRATE_SQLITE_TO_POSTGRES", True):
        return 0

    legacy_path = _legacy_sqlite_path()
    if not legacy_path.exists() or legacy_path.stat().st_size <= 0:
        return 0

    marker = hashlib.sha256(
        (
            str(legacy_path.resolve())
            + ":"
            + str(legacy_path.stat().st_size)
            + ":"
            + str(int(legacy_path.stat().st_mtime))
        ).encode("utf-8")
    ).hexdigest()

    if _get_meta("legacy_sqlite_import") == marker:
        return 0

    source_engine = create_engine(
        f"sqlite:///{legacy_path}",
        connect_args={"check_same_thread": False},
    )
    imported = 0

    try:
        source_metadata = MetaData()
        source_metadata.reflect(bind=source_engine)

        from sqlalchemy.dialects.postgresql import insert as pg_insert

        with source_engine.connect() as source_connection:
            with ENGINE.begin() as target_connection:
                for target_table in Base.metadata.sorted_tables:
                    source_table = source_metadata.tables.get(
                        target_table.name
                    )
                    if source_table is None:
                        continue

                    rows = source_connection.execute(
                        select(source_table)
                    ).mappings()

                    aliases = {
                        target: legacy
                        for target, (legacy, _) in LEGACY_COLUMNS.get(
                            target_table.name,
                            {},
                        ).items()
                    }

                    for row in rows:
                        values: dict[str, Any] = {}

                        for column in target_table.columns:
                            if column.name in row:
                                values[column.name] = row[column.name]
                                continue

                            legacy_name = aliases.get(column.name)
                            if legacy_name and legacy_name in row:
                                values[column.name] = row[legacy_name]

                        if not values:
                            continue

                        statement = (
                            pg_insert(target_table)
                            .values(**values)
                            .on_conflict_do_nothing()
                        )
                        result = target_connection.execute(statement)
                        imported += max(0, result.rowcount or 0)

        # Reset PostgreSQL integer sequences after explicit ID imports.
        with ENGINE.begin() as connection:
            for table in Base.metadata.sorted_tables:
                for column in table.primary_key.columns:
                    try:
                        if column.type.python_type is not int:
                            continue
                    except Exception:
                        continue

                    connection.execute(
                        text(
                            "SELECT setval("
                            "pg_get_serial_sequence(:table_name,:column_name),"
                            f"COALESCE((SELECT MAX({_quoted(column.name)}) "
                            f"FROM {_quoted(table.name)}),1),true)"
                        ),
                        {
                            "table_name": table.name,
                            "column_name": column.name,
                        },
                    )
    finally:
        source_engine.dispose()

    _set_meta("legacy_sqlite_import", marker)
    return imported


def initialize_database(force: bool = False) -> dict[str, Any]:
    global _initialized
    global DATABASE_READY
    global DATABASE_ERROR

    with _init_lock:
        if _initialized and not force:
            return database_status()

        try:
            from . import models  # noqa: F401

            existing_before = set(inspect(ENGINE).get_table_names())
            Base.metadata.create_all(ENGINE)

            existing_after = set(inspect(ENGINE).get_table_names())
            MIGRATION_REPORT["created_tables"] = sorted(
                existing_after - existing_before
            )

            _ensure_schema_meta()
            _migrate_missing_columns()

            imported = _import_legacy_sqlite_if_present()
            MIGRATION_REPORT["imported_rows"] = imported

            _set_meta("schema_version", SCHEMA_VERSION)
            _set_meta(
                "database_mode",
                DATABASE_MODE,
            )
            _set_meta(
                "last_initialized_at",
                datetime.now(timezone.utc).isoformat(),
            )

            DATABASE_READY = True
            DATABASE_ERROR = ""
            _initialized = True

            logger.info(
                "Database ready: mode=%s schema=%s created=%s "
                "columns=%s imported=%s",
                DATABASE_MODE,
                SCHEMA_VERSION,
                len(MIGRATION_REPORT["created_tables"]),
                len(MIGRATION_REPORT["added_columns"]),
                imported,
            )
            return database_status()
        except Exception as exc:
            DATABASE_READY = False
            DATABASE_ERROR = str(exc)
            logger.exception("Automatic database setup failed")
            raise DatabaseSetupError(
                "ساخت یا ارتقای خودکار دیتابیس ناموفق بود: "
                f"{exc}"
            ) from exc


def create_schema() -> None:
    initialize_database()


def database_table_counts() -> dict[str, int]:
    if not DATABASE_READY:
        return {}

    counts: dict[str, int] = {}
    for table_name in (
        "customers",
        "plans",
        "pasarguard_panels",
        "orders",
        "customer_devices",
        "customer_sessions",
        "webhook_deliveries",
    ):
        if not inspect(ENGINE).has_table(table_name):
            counts[table_name] = 0
            continue
        with ENGINE.connect() as connection:
            counts[table_name] = int(
                connection.scalar(
                    text(
                        f"SELECT COUNT(*) "
                        f"FROM {_quoted(table_name)}"
                    )
                )
                or 0
            )
    return counts


def _safe_target() -> str:
    if not RESOLVED_URL:
        return str(SQLITE_PATH)

    try:
        parsed = make_url(RESOLVED_URL)
        host = parsed.host or "local"
        database = parsed.database or ""
        return f"{host}/{database}"
    except Exception:
        return "configured"


def database_status() -> dict[str, Any]:
    return {
        "ready": DATABASE_READY,
        "mode": DATABASE_MODE,
        "persistent": DATABASE_PERSISTENT,
        "url_source": DATABASE_URL_SOURCE or "not_configured",
        "target": _safe_target(),
        "schema_version": (
            _get_meta("schema_version")
            if DATABASE_READY
            else SCHEMA_VERSION
        ),
        "require_postgres": REQUIRE_POSTGRES,
        "sqlite_fallback_allowed": ALLOW_SQLITE_FALLBACK,
        "migration": dict(MIGRATION_REPORT),
        "error": DATABASE_ERROR[:1000],
    }


def get_db() -> Generator[Session, None, None]:
    db = SessionLocal()
    try:
        yield db
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()
