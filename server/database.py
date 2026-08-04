from __future__ import annotations
import logging, os
from pathlib import Path
from typing import Generator
from sqlalchemy import create_engine, text
from sqlalchemy.engine import Engine
from sqlalchemy.orm import DeclarativeBase, Session, sessionmaker
logger=logging.getLogger("bluevpn.database")
DATA_DIR=Path(os.getenv("DATA_DIR","/app/data")); DATA_DIR.mkdir(parents=True,exist_ok=True)
SQLITE_URL=f"sqlite:///{DATA_DIR/'bluevpn.db'}"
class Base(DeclarativeBase): pass

def normalize_url(value:str|None)->str|None:
    if not value: return None
    value=value.strip()
    if not value or "${{" in value: return None
    if value.startswith("postgres://"): return "postgresql+psycopg://"+value[11:]
    if value.startswith("postgresql://"): return "postgresql+psycopg://"+value[13:]
    if value.startswith(("postgresql+psycopg://","sqlite://")): return value
    return None

def make_engine(url:str)->Engine:
    kw={"pool_pre_ping":True}
    if url.startswith("sqlite"):
        kw["connect_args"]={"check_same_thread":False,"timeout":30}
    else:
        kw["connect_args"]={"connect_timeout":8}; kw["pool_recycle"]=300
    e=create_engine(url,**kw)
    with e.connect() as c: c.execute(text("SELECT 1"))
    return e
PRIMARY=normalize_url(os.getenv("DATABASE_URL")); DATABASE_ERROR=""
try:
    ENGINE=make_engine(PRIMARY) if PRIMARY else make_engine(SQLITE_URL)
    DATABASE_MODE="postgres" if PRIMARY else "sqlite_fallback"
except Exception as exc:
    DATABASE_ERROR=str(exc); logger.exception("Postgres unavailable; SQLite fallback")
    ENGINE=make_engine(SQLITE_URL); DATABASE_MODE="sqlite_fallback"
SessionLocal=sessionmaker(bind=ENGINE,autoflush=False,expire_on_commit=False)
def create_schema()->None:
    from . import models  # noqa
    Base.metadata.create_all(ENGINE)
def get_db()->Generator[Session,None,None]:
    db=SessionLocal()
    try: yield db
    except Exception: db.rollback(); raise
    finally: db.close()
