from __future__ import annotations

import json
import os
import secrets
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib.parse import quote

from fastapi import Depends, FastAPI, Form, HTTPException, Request
from fastapi.responses import HTMLResponse, JSONResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from sqlalchemy import DateTime, Integer, Text, create_engine
from sqlalchemy.orm import DeclarativeBase, Mapped, Session, mapped_column, sessionmaker
from starlette.middleware.sessions import SessionMiddleware

BASE_DIR = Path(__file__).resolve().parent
DATA_DIR = Path(os.getenv("DATA_DIR", "/app/data"))
DATA_DIR.mkdir(parents=True, exist_ok=True)

def normalize_database_url(value: str | None) -> str:
    if not value:
        return f"sqlite:///{DATA_DIR / 'bluevpn.db'}"
    value = value.strip()
    if value.startswith("postgres://"):
        return "postgresql+psycopg://" + value.removeprefix("postgres://")
    if value.startswith("postgresql://"):
        return "postgresql+psycopg://" + value.removeprefix("postgresql://")
    return value

DATABASE_URL = normalize_database_url(os.getenv("DATABASE_URL"))
connect_args = {"check_same_thread": False} if DATABASE_URL.startswith("sqlite") else {}
engine = create_engine(DATABASE_URL, pool_pre_ping=True, connect_args=connect_args)
SessionLocal = sessionmaker(bind=engine, expire_on_commit=False)

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
    "latest_version": "0.1.0",
    "minimum_version": "0.1.0",
    "force_update": False,
    "apk_url": os.getenv("APK_URL", ""),
    "announcement_enabled": True,
    "announcement_id": "welcome-001",
    "announcement_title": "به BlueVPN خوش آمدید",
    "announcement_message": "برای افزودن اشتراک، لینک اختصاصی پاسارگارد خود را وارد کنید.",
    "updated_at": datetime.now(timezone.utc).isoformat(),
}

def db_session():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()

def read_settings(db: Session) -> dict[str, Any]:
    record = db.get(SettingsRecord, 1)
    if not record:
        record = SettingsRecord(id=1, payload=json.dumps(DEFAULT_SETTINGS, ensure_ascii=False))
        db.add(record)
        db.commit()
        db.refresh(record)
    try:
        data = json.loads(record.payload)
    except json.JSONDecodeError:
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

Base.metadata.create_all(engine)

app = FastAPI(title="BlueVPN Control Panel", version="0.1.0")
app.add_middleware(
    SessionMiddleware,
    secret_key=os.getenv("SESSION_SECRET") or secrets.token_urlsafe(48),
    https_only=False,
    same_site="lax",
)
app.mount("/static", StaticFiles(directory=BASE_DIR / "static"), name="static")
templates = Jinja2Templates(directory=BASE_DIR / "templates")

@app.get("/health")
def health():
    return {"status": "ok", "service": "bluevpn"}

@app.get("/")
def home():
    return RedirectResponse("/admin", status_code=302)

@app.get("/api/v1/mobile/config")
def mobile_config(db: Session = Depends(db_session)):
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
        "updated_at": data["updated_at"],
    }
    return JSONResponse(result, headers={"Cache-Control": "public, max-age=60"})

@app.get("/api/v1/deeplink")
def create_deeplink(url: str):
    if not url or not valid_url(url):
        raise HTTPException(status_code=422, detail="A valid subscription URL is required")
    return {"deep_link": f"bluevpn://install-sub?url={quote(url, safe='')}"}

@app.get("/open-sub", response_class=HTMLResponse)
def open_subscription(request: Request, url: str):
    if not url or not valid_url(url):
        raise HTTPException(status_code=422, detail="Invalid subscription URL")
    return templates.TemplateResponse(
        request=request,
        name="open_sub.html",
        context={"deep_link": f"bluevpn://install-sub?url={quote(url, safe='')}"},
    )

@app.get("/admin/login", response_class=HTMLResponse)
def login_page(request: Request):
    if request.session.get("admin"):
        return RedirectResponse("/admin", status_code=302)
    return templates.TemplateResponse(request=request, name="login.html", context={"error": ""})

@app.post("/admin/login", response_class=HTMLResponse)
def login(request: Request, username: str = Form(...), password: str = Form(...)):
    expected_user = os.getenv("ADMIN_USERNAME", "admin")
    expected_pass = os.getenv("ADMIN_PASSWORD", "CHANGE_THIS_PASSWORD")
    valid = secrets.compare_digest(username, expected_user) and secrets.compare_digest(password, expected_pass)
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
def logout(request: Request):
    request.session.clear()
    return RedirectResponse("/admin/login", status_code=303)

@app.get("/admin", response_class=HTMLResponse)
def admin_page(request: Request, db: Session = Depends(db_session)):
    if not request.session.get("admin"):
        return RedirectResponse("/admin/login", status_code=302)
    return templates.TemplateResponse(
        request=request,
        name="admin.html",
        context={
            "settings": read_settings(db),
            "saved": request.query_params.get("saved") == "1",
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
    values = [support_url, renew_url, default_subscription_url, apk_url]
    if any(not valid_url(value.strip()) for value in values):
        raise HTTPException(status_code=422, detail="URLs must start with http:// or https://")

    data = read_settings(db)
    data.update({
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
    })
    save_settings(db, data)
    return RedirectResponse("/admin?saved=1", status_code=303)
