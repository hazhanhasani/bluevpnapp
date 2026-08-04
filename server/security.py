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
def _fernet():
    secret=os.getenv("DATA_ENCRYPTION_KEY") or os.getenv("SESSION_SECRET") or "change-me-bluevpn"
    return Fernet(base64.urlsafe_b64encode(hashlib.sha256(secret.encode()).digest()))
def encrypt(value:str)->str: return _fernet().encrypt(value.encode()).decode() if value else ""
def decrypt(value:str)->str:
    if not value:return ""
    try:return _fernet().decrypt(value.encode()).decode()
    except InvalidToken:return ""
def mask(value:str)->str:
    if not value:return "تنظیم نشده"
    return value[:5]+"••••"+value[-4:] if len(value)>10 else "••••••••"
