from __future__ import annotations
import base64, hashlib, hmac, os, secrets
from datetime import datetime,timedelta,timezone
from cryptography.fernet import Fernet,InvalidToken

def utcnow(): return datetime.now(timezone.utc)
def password_hash(password:str)->str:
    salt=secrets.token_bytes(18); rounds=390000
    digest=hashlib.pbkdf2_hmac("sha256",password.encode(),salt,rounds)
    return f"pbkdf2_sha256${rounds}${base64.urlsafe_b64encode(salt).decode()}${base64.urlsafe_b64encode(digest).decode()}"
def password_ok(password:str,stored:str)->bool:
    try:
        alg,rounds,salt,digest=stored.split("$",3)
        if alg!="pbkdf2_sha256": return False
        actual=hashlib.pbkdf2_hmac("sha256",password.encode(),base64.urlsafe_b64decode(salt),int(rounds))
        return hmac.compare_digest(actual,base64.urlsafe_b64decode(digest))
    except Exception: return False
def token_hash(raw:str)->str: return hashlib.sha256(raw.encode()).hexdigest()
def new_token()->tuple[str,str]:
    raw=secrets.token_urlsafe(48); return raw,token_hash(raw)
def session_expiry(): return utcnow()+timedelta(days=120)
def _fernet_for_secret(secret:str)->Fernet:
    return Fernet(base64.urlsafe_b64encode(hashlib.sha256(secret.encode()).digest()))

def _encryption_secrets()->list[str]:
    # Keep encrypted panel/API credentials readable when a deployment migrates
    # from SESSION_SECRET to a dedicated DATA_ENCRYPTION_KEY. Extra historical
    # keys can be supplied comma-separated through DATA_ENCRYPTION_KEY_PREVIOUS.
    values=[
        os.getenv("DATA_ENCRYPTION_KEY") or "",
        os.getenv("SESSION_SECRET") or "",
        *(os.getenv("DATA_ENCRYPTION_KEY_PREVIOUS") or "").split(","),
        "change-me-bluevpn",
    ]
    result=[]
    for value in values:
        value=str(value or "").strip()
        if value and value not in result:
            result.append(value)
    return result

def _fernet():
    return _fernet_for_secret(_encryption_secrets()[0])

def encrypt(value:str)->str:
    return _fernet().encrypt(value.encode()).decode() if value else ""

def decrypt(value:str)->str:
    if not value:return ""
    for secret in _encryption_secrets():
        try:
            return _fernet_for_secret(secret).decrypt(value.encode()).decode()
        except InvalidToken:
            continue
    return ""
def mask(value:str)->str:
    if not value:return "تنظیم نشده"
    return value[:5]+"••••"+value[-4:] if len(value)>10 else "••••••••"
