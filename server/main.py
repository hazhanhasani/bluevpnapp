from __future__ import annotations

import asyncio
import json
import logging
import os
import secrets
import threading
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Generator
from urllib.parse import quote

from fastapi import Depends, FastAPI, Form, HTTPException, Request
from fastapi.responses import HTMLResponse, JSONResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from sqlalchemy import DateTime, Integer, Text, create_engine, text
from sqlalchemy.engine import Engine
from sqlalchemy.exc import SQLAlchemyError
from sqlalchemy.orm import (
    DeclarativeBase,
    Mapped,
    Session,
    mapped_column,
    sessionmaker,
)
from starlette.middleware.sessions import SessionMiddleware

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("bluevpn-panel")

BASE_DIR = Path(__file__).resolve().parent
DATA_DIR = Path(os.getenv("DATA_DIR", "/app/data"))
DATA_DIR.mkdir(parents=True, exist_ok=True)

SQLITE_URL = f"sqlite:///{DATA_DIR / 'bluevpn.db'}"


def normalize_database_url(value: str | None) -> str | None:
    """Return a SQLAlchemy URL or None when Railway reference is unresolved."""
    if not value:
        return None

    value = value.strip()

    # Railway references must be resolved before reaching the application.
    # If the literal reference arrives here, do not crash the entire web panel.
    if not value or "${{" in value or "}}" in value:
        return None

    if value.startswith("postgres://"):
        return "postgresql+psycopg://" + value.removeprefix("postgres://")

    if value.startswith("postgresql://"):
        return "postgresql+psycopg://" + value.removeprefix("postgresql://")

    if value.startswith("postgresql+psycopg://") or value.startswith("sqlite://"):
        return value

    logger.error("Unsupported DATABASE_URL format; using SQLite fallback.")
    return None


PRIMARY_DATABASE_URL = normalize_database_url(os.getenv("DATABASE_URL"))


class Base(DeclarativeBase):
    pass


class SettingsRecord(Base):
    __tablename__ = "app_settings"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, default=1)
    payload: Mapped[str] = mapped_column(Text, nullable=False)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
    )


DEFAULT_SETTINGS: dict[str, Any] = {
    "app_name": "BlueVPN",
    "maintenance": False,
    "support_url": os.getenv("SUPPORT_URL", ""),
    "renew_url": os.getenv("RENEW_URL", ""),
    "default_subscription_url": os.getenv("DEFAULT_SUBSCRIPTION_URL", ""),
    "latest_version": "0.4.2",
    "minimum_version": "0.4.0",
    "force_update": False,
    "apk_url": os.getenv("APK_URL", ""),
    "announcement_enabled": True,
    "announcement_id": "welcome-042",
    "announcement_title": "به BlueVPN خوش آمدید",
    "announcement_message": (
        "برای افزودن اشتراک، لینک اختصاصی پاسارگارد خود را وارد کنید."
    ),
    "updated_at": datetime.now(timezone.utc).isoformat(),
}


_database_lock = threading.RLock()
_active_engine: Engine | None = None
_session_factory: sessionmaker[Session] | None = None
_database_mode = "initializing"
_database_error = ""
_last_primary_attempt = 0.0


def create_database_engine(url: str) -> Engine:
    kwargs: dict[str, Any] = {
        "pool_pre_ping": True,
    }

    if url.startswith("sqlite"):
        kwargs["connect_args"] = {
            "check_same_thread": False,
            "timeout": 20,
        }
    else:
        kwargs["connect_args"] = {
            "connect_timeout": 8,
        }
        kwargs["pool_recycle"] = 300

    return create_engine(url, **kwargs)


def verify_and_prepare(engine: Engine) -> None:
    with engine.connect() as connection:
        connection.execute(text("SELECT 1"))
    Base.metadata.create_all(engine)


def activate_database(url: str, mode: str) -> None:
    global _active_engine
    global _session_factory
    global _database_mode
    global _database_error

    candidate = create_database_engine(url)
    verify_and_prepare(candidate)

    with _database_lock:
        previous = _active_engine
        _active_engine = candidate
        _session_factory = sessionmaker(
            bind=candidate,
            expire_on_commit=False,
            autoflush=False,
        )
        _database_mode = mode
        _database_error = ""

    if previous is not None and previous is not candidate:
        previous.dispose()


def activate_sqlite_fallback(error: str = "") -> None:
    global _database_error
    activate_database(SQLITE_URL, "sqlite_fallback")
    _database_error = error


# Always make the panel importable and reachable.
try:
    activate_sqlite_fallback()
except Exception:
    logger.exception("Could not initialize SQLite fallback database.")
    raise


def try_activate_primary() -> bool:
    global _last_primary_attempt
    global _database_error

    if not PRIMARY_DATABASE_URL:
        _database_error = (
            "DATABASE_URL is empty, invalid, or contains an unresolved Railway reference."
        )
        return False

    _last_primary_attempt = time.monotonic()

    try:
        activate_database(PRIMARY_DATABASE_URL, "postgres")
        logger.info("BlueVPN panel connected to PostgreSQL.")
        return True
    except Exception as exc:
        _database_error = str(exc)
        logger.exception(
            "PostgreSQL connection failed; panel remains available on SQLite fallback."
        )
        return False


async def database_reconnect_loop() -> None:
    # PostgreSQL may start a little later than the application container.
    while True:
        if _database_mode != "postgres":
            await asyncio.to_thread(try_activate_primary)
        await asyncio.sleep(60)


def db_session() -> Generator[Session, None, None]:
    with _database_lock:
        factory = _session_factory

    if factory is None:
        raise HTTPException(status_code=503, detail="Database is not initialized")

    db = factory()
    try:
        yield db
    except SQLAlchemyError:
        db.rollback()
        raise
    finally:
        db.close()


def read_settings(db: Session) -> dict[str, Any]:
    record = db.get(SettingsRecord, 1)

    if not record:
        record = SettingsRecord(
            id=1,
            payload=json.dumps(DEFAULT_SETTINGS, ensure_ascii=False),
        )
        db.add(record)
        db.commit()
        db.refresh(record)

    try:
        data = json.loads(record.payload)
    except (json.JSONDecodeError, TypeError):
        data = {}

    return {**DEFAULT_SETTINGS, **data}


def save_settings(db: Session, data: dict[str, Any]) -> None:
    data["updated_at"] = datetime.now(timezone.utc).isoformat()
    record = db.get(SettingsRecord, 1)

    if not record:
        record = SettingsRecord(id=1, payload="{}")
        db.add(record)

    record.payload = json.dumps(data, ensure_ascii=False)
    record.updated_at = datetime.now(timezone.utc)
    db.commit()


def valid_url(value: str) -> bool:
    return not value or value.startswith("https://") or value.startswith("http://")


def require_admin(request: Request) -> None:
    if not request.session.get("admin"):
        raise HTTPException(status_code=401, detail="Unauthorized")


app = FastAPI(title="BlueVPN Control Panel", version="0.4.2")
app.add_middleware(
    SessionMiddleware,
    secret_key=os.getenv("SESSION_SECRET") or secrets.token_urlsafe(48),
    https_only=False,
    same_site="lax",
)
app.mount("/static", StaticFiles(directory=BASE_DIR / "static"), name="static")
templates = Jinja2Templates(directory=BASE_DIR / "templates")


@app.on_event("startup")
async def startup_database_tasks() -> None:
    # Do not block web startup. The panel is already available through SQLite.
    asyncio.create_task(asyncio.to_thread(try_activate_primary))
    asyncio.create_task(database_reconnect_loop())


@app.get("/health")
def health() -> dict[str, Any]:
    return {
        "status": "ok",
        "service": "bluevpn",
        "database_mode": _database_mode,
        "database_error": _database_error[:300] if _database_error else "",
    }


@app.get("/")
def home() -> RedirectResponse:
    return RedirectResponse("/admin", status_code=302)


@app.get("/api/v1/mobile/config")
def mobile_config(db: Session = Depends(db_session)) -> JSONResponse:
    data = read_settings(db)
    result = {
        "app_name": data["app_name"],
        "maintenance": bool(data["maintenance"]),
        "support_url": data["support_url"],
        "renew_url": data["renew_url"],
        "default_subscription_url": data["default_subscription_url"],
        "latest_version": data["latest_version"],
        "minimum_version": data["minimum_version"],
        "force_update": bool(data["force_update"]),
        "apk_url": data["apk_url"],
        "announcement": {
            "enabled": bool(data["announcement_enabled"]),
            "id": data["announcement_id"],
            "title": data["announcement_title"],
            "message": data["announcement_message"],
        },
        "database_mode": _database_mode,
        "updated_at": data["updated_at"],
    }
    return JSONResponse(
        result,
        headers={"Cache-Control": "public, max-age=60"},
    )


@app.get("/api/v1/deeplink")
def create_deeplink(url: str) -> dict[str, str]:
    if not url or not valid_url(url):
        raise HTTPException(
            status_code=422,
            detail="A valid subscription URL is required",
        )
    return {"deep_link": f"bluevpn://install-sub?url={quote(url, safe='')}"}


@app.get("/open-sub", response_class=HTMLResponse)
def open_subscription(request: Request, url: str):
    if not url or not valid_url(url):
        raise HTTPException(status_code=422, detail="Invalid subscription URL")

    return templates.TemplateResponse(
        request=request,
        name="open_sub.html",
        context={
            "deep_link": f"bluevpn://install-sub?url={quote(url, safe='')}",
        },
    )


@app.get("/admin/login", response_class=HTMLResponse)
def login_page(request: Request):
    if request.session.get("admin"):
        return RedirectResponse("/admin", status_code=302)

    return templates.TemplateResponse(
        request=request,
        name="login.html",
        context={"error": ""},
    )


@app.post("/admin/login", response_class=HTMLResponse)
def login(
    request: Request,
    username: str = Form(...),
    password: str = Form(...),
):
    expected_user = os.getenv("ADMIN_USERNAME", "admin")
    expected_pass = os.getenv("ADMIN_PASSWORD", "CHANGE_THIS_PASSWORD")

    valid = secrets.compare_digest(
        username,
        expected_user,
    ) and secrets.compare_digest(
        password,
        expected_pass,
    )

    if not valid:
        return templates.TemplateResponse(
            request=request,
            name="login.html",
            context={"error": "نام کاربری یا رمز عبور نادرست است."},
            status_code=401,
        )

    request.session["admin"] = True
    return RedirectResponse("/admin", status_code=303)


@app.post("/admin/logout")
def logout(request: Request) -> RedirectResponse:
    request.session.clear()
    return RedirectResponse("/admin/login", status_code=303)


@app.get("/admin", response_class=HTMLResponse)
def admin_page(
    request: Request,
    db: Session = Depends(db_session),
):
    if not request.session.get("admin"):
        return RedirectResponse("/admin/login", status_code=302)

    return templates.TemplateResponse(
        request=request,
        name="admin.html",
        context={
            "settings": read_settings(db),
            "saved": request.query_params.get("saved") == "1",
            "database_mode": _database_mode,
            "database_error": _database_error,
        },
    )


@app.post("/admin/settings")
def update_settings(
    request: Request,
    app_name: str = Form(...),
    support_url: str = Form(""),
    renew_url: str = Form(""),
    default_subscription_url: str = Form(""),
    latest_version: str = Form(...),
    minimum_version: str = Form(...),
    apk_url: str = Form(""),
    announcement_id: str = Form("notice"),
    announcement_title: str = Form(""),
    announcement_message: str = Form(""),
    maintenance: str | None = Form(None),
    force_update: str | None = Form(None),
    announcement_enabled: str | None = Form(None),
    db: Session = Depends(db_session),
):
    require_admin(request)

    values = [
        support_url,
        renew_url,
        default_subscription_url,
        apk_url,
    ]
    if any(not valid_url(value.strip()) for value in values):
        raise HTTPException(
            status_code=422,
            detail="URLs must start with http:// or https://",
        )

    data = read_settings(db)
    data.update(
        {
            "app_name": app_name.strip() or "BlueVPN",
            "support_url": support_url.strip(),
            "renew_url": renew_url.strip(),
            "default_subscription_url": default_subscription_url.strip(),
            "latest_version": latest_version.strip(),
            "minimum_version": minimum_version.strip(),
            "apk_url": apk_url.strip(),
            "announcement_id": announcement_id.strip() or "notice",
            "announcement_title": announcement_title.strip(),
            "announcement_message": announcement_message.strip(),
            "maintenance": maintenance == "on",
            "force_update": force_update == "on",
            "announcement_enabled": announcement_enabled == "on",
        }
    )
    save_settings(db, data)
    return RedirectResponse("/admin?saved=1", status_code=303)
