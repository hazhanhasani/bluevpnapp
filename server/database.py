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

SCHEMA_VERSION = "17"
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

    value = value.strip().strip('"').strip("'")
    if (
        not value
        or "${{" in value
        or "}}" in value
        or value.lower() in {"null", "none"}
    ):
        return None

    lowered = value.lower()

    if lowered.startswith("postgres://"):
        return "postgresql+psycopg://" + value[len("postgres://"):]
    if lowered.startswith("postgresql://"):
        return "postgresql+psycopg://" + value[len("postgresql://"):]
    if lowered.startswith("postgresql+psycopg://"):
        return value
    if lowered.startswith("sqlite://"):
        return value
    return None


def _database_environment_diagnostics() -> dict[str, Any]:
    relevant_names: list[str] = []
    url_candidate_names: list[str] = []
    unresolved_reference_names: list[str] = []
    component_names: list[str] = []

    keywords = (
        "DATABASE",
        "POSTGRES",
        "POSTGRESQL",
        "PGHOST",
        "PGPORT",
        "PGUSER",
        "PGPASSWORD",
        "PGDATABASE",
        "DB_",
    )

    for name, raw_value in sorted(os.environ.items()):
        upper = name.upper()
        value = str(raw_value or "").strip()

        if any(keyword in upper for keyword in keywords):
            relevant_names.append(name)

        if "${{" in value or "}}" in value:
            if any(keyword in upper for keyword in keywords):
                unresolved_reference_names.append(name)

        lowered = value.lower()
        if lowered.startswith(
            (
                "postgres://",
                "postgresql://",
                "postgresql+psycopg://",
            )
        ):
            url_candidate_names.append(name)

        if upper.endswith(
            (
                "_HOST",
                "_PORT",
                "_USER",
                "_USERNAME",
                "_PASSWORD",
                "_DATABASE",
                "_DB",
            )
        ):
            component_names.append(name)

    return {
        "relevant_names": sorted(set(relevant_names)),
        "url_candidate_names": sorted(set(url_candidate_names)),
        "unresolved_reference_names": sorted(
            set(unresolved_reference_names)
        ),
        "component_names": sorted(set(component_names)),
    }


def _first_env(*names: str) -> tuple[str, str]:
    for name in names:
        value = os.getenv(name, "").strip()
        if value:
            return value, name
    return "", ""


def _url_from_pg_variables() -> tuple[str | None, str]:
    host, host_name = _first_env(
        "PGHOST",
        "POSTGRES_HOST",
        "POSTGRES_PRIVATE_HOST",
        "POSTGRESHOST",
        "POSTGRESQL_HOST",
        "DATABASE_HOST",
        "DB_HOST",
    )
    port, port_name = _first_env(
        "PGPORT",
        "POSTGRES_PORT",
        "POSTGRESPORT",
        "POSTGRESQL_PORT",
        "DATABASE_PORT",
        "DB_PORT",
    )
    user, user_name = _first_env(
        "PGUSER",
        "POSTGRES_USER",
        "POSTGRES_USERNAME",
        "POSTGRESUSER",
        "POSTGRESQL_USER",
        "DATABASE_USER",
        "DATABASE_USERNAME",
        "DB_USER",
    )
    password, password_name = _first_env(
        "PGPASSWORD",
        "POSTGRES_PASSWORD",
        "POSTGRESPASSWORD",
        "POSTGRESQL_PASSWORD",
        "DATABASE_PASSWORD",
        "DB_PASSWORD",
    )
    database, database_name = _first_env(
        "PGDATABASE",
        "POSTGRES_DB",
        "POSTGRES_DATABASE",
        "POSTGRESDATABASE",
        "POSTGRESQL_DATABASE",
        "DATABASE_NAME",
        "DB_NAME",
    )

    if not port:
        port = "5432"

    if not host or not user or not database:
        return None, ""

    source_names = "/".join(
        item
        for item in (
            host_name,
            port_name,
            user_name,
            password_name,
            database_name,
        )
        if item
    )

    return (
        "postgresql+psycopg://"
        f"{quote_plus(user)}:{quote_plus(password)}"
        f"@{host}:{port}/{quote_plus(database)}",
        source_names or "PostgreSQL components",
    )


def _scan_all_environment_urls() -> tuple[str | None, str]:
    """
    Railway lets the user choose the destination variable name when linking a
    database. Accept any environment variable whose value is a PostgreSQL URL,
    so a harmless custom name cannot make BlueVPN miss the connection.
    """
    ignored_names = {
        "DATABASE_PUBLIC_URL",
    }

    candidates: list[tuple[int, str, str]] = []

    for name, raw_value in os.environ.items():
        if name in ignored_names:
            continue

        normalized = _normalize_url(raw_value)
        if not normalized or not normalized.startswith("postgresql"):
            continue

        upper = name.upper()
        priority = 100

        if upper == "DATABASE_URL":
            priority = 0
        elif upper == "DATABASE_PRIVATE_URL":
            priority = 1
        elif "PRIVATE" in upper:
            priority = 5
        elif "DATABASE" in upper:
            priority = 10
        elif "POSTGRES" in upper or "POSTGRESQL" in upper:
            priority = 20
        elif upper.startswith("PG"):
            priority = 30

        candidates.append((priority, name, normalized))

    if not candidates:
        return None, ""

    candidates.sort(key=lambda item: (item[0], item[1]))
    _, name, normalized = candidates[0]
    return normalized, f"auto-discovered:{name}"


def _scan_prefixed_components() -> tuple[str | None, str]:
    """
    Also understand custom component groups such as:
      MYDB_HOST, MYDB_PORT, MYDB_USER, MYDB_PASSWORD, MYDB_DATABASE
    """
    groups: dict[str, dict[str, tuple[str, str]]] = {}

    suffixes = {
        "_HOST": "host",
        "_PORT": "port",
        "_USER": "user",
        "_USERNAME": "user",
        "_PASSWORD": "password",
        "_DATABASE": "database",
        "_DB": "database",
    }

    for name, raw_value in os.environ.items():
        value = str(raw_value or "").strip()
        if not value:
            continue

        upper = name.upper()
        for suffix, field in suffixes.items():
            if not upper.endswith(suffix):
                continue

            prefix = upper[: -len(suffix)]
            if not prefix:
                continue

            groups.setdefault(prefix, {})[field] = (value, name)
            break

    ranked: list[tuple[int, str, str]] = []

    for prefix, fields in groups.items():
        if not all(key in fields for key in ("host", "user", "database")):
            continue

        host = fields["host"][0]
        user = fields["user"][0]
        password = fields.get("password", ("", ""))[0]
        port = fields.get("port", ("5432", ""))[0] or "5432"
        database = fields["database"][0]

        # Avoid accidentally treating unrelated service variables as a DB
        # unless the prefix or host clearly looks database-related.
        identity = f"{prefix} {host}".upper()
        if not any(
            token in identity
            for token in (
                "POSTGRES",
                "DATABASE",
                "PG",
                "RAILWAY.INTERNAL",
            )
        ):
            continue

        url = (
            "postgresql+psycopg://"
            f"{quote_plus(user)}:{quote_plus(password)}"
            f"@{host}:{port}/{quote_plus(database)}"
        )
        priority = 0 if "POSTGRES" in prefix else 10
        ranked.append((priority, prefix, url))

    if not ranked:
        return None, ""

    ranked.sort(key=lambda item: (item[0], item[1]))
    _, prefix, url = ranked[0]
    return url, f"auto-components:{prefix}"


def _resolve_database_url() -> tuple[str | None, str]:
    candidates = (
        "DATABASE_URL",
        "DATABASE_PRIVATE_URL",
        "POSTGRES_URL",
        "POSTGRES_PRIVATE_URL",
        "POSTGRESQL_URL",
        "POSTGRESQL_PRIVATE_URL",
        "DATABASE_INTERNAL_URL",
        "PGURL",
    )

    for name in candidates:
        normalized = _normalize_url(os.getenv(name))
        if normalized and normalized.startswith("postgresql"):
            return normalized, name

    discovered_url, discovered_source = _scan_all_environment_urls()
    if discovered_url:
        return discovered_url, discovered_source

    built, built_source = _url_from_pg_variables()
    if built:
        return built, built_source

    prefixed, prefixed_source = _scan_prefixed_components()
    if prefixed:
        return prefixed, prefixed_source

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
        diagnostics = _database_environment_diagnostics()
        relevant = ", ".join(
            diagnostics["relevant_names"][:30]
        ) or "هیچ‌کدام"
        urls = ", ".join(
            diagnostics["url_candidate_names"][:20]
        ) or "هیچ‌کدام"
        unresolved = ", ".join(
            diagnostics["unresolved_reference_names"][:20]
        ) or "هیچ‌کدام"

        raise DatabaseSetupError(
            "Railway شناسایی شد اما هیچ آدرس PostgreSQL قابل استفاده‌ای "
            "داخل محیط سرویس BlueVPN دریافت نشد. "
            "متغیرهای مرتبط دیده‌شده: "
            f"{relevant}. "
            "متغیرهای دارای URL واقعی PostgreSQL: "
            f"{urls}. "
            "ارجاع‌های Railway که هنوز به مقدار واقعی تبدیل نشده‌اند: "
            f"{unresolved}. "
            "در سرویس bluevpnapp یک متغیر با نام DATABASE_URL و مقدار "
            "${{Postgres.DATABASE_PRIVATE_URL}} بسازید و تغییرات را Deploy "
            "کنید. SQLite موقت عمداً غیرفعال است تا اطلاعات حذف نشوند."
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
        "marzban_panels",
        "guardcore_panels",
        "orders",
        "customer_devices",
        "customer_sessions",
        "otp_challenges",
        "sms_settings",
        "sms_templates",
        "sms_deliveries",
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
        "environment_diagnostics": (
            _database_environment_diagnostics()
        ),
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
