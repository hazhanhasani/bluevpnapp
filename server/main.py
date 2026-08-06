from __future__ import annotations
import asyncio,hashlib,ipaddress,json,logging,os,re,secrets,shutil,sqlite3,subprocess,tempfile,threading,time,uuid,zipfile
from collections import deque
from urllib.parse import quote_plus,urlparse
from datetime import datetime,timedelta,timezone
from pathlib import Path
from typing import Any
from fastapi import Depends,FastAPI,Form,Header,HTTPException,Request
from fastapi.responses import FileResponse,HTMLResponse,JSONResponse,RedirectResponse,Response
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from sqlalchemy import delete,func,select
from sqlalchemy.orm import Session,selectinload
from starlette.background import BackgroundTask
from starlette.middleware.sessions import SessionMiddleware
from .database import DATABASE_ERROR,DATABASE_MODE,ENGINE,SQLITE_PATH,SessionLocal,database_status,database_table_counts,initialize_database,get_db
from .integrations import IntegrationError,combined_subscription,create_invoice,delete_invoice,get_invoice,iso_z,log_bluepay_error,merge_order_metadata,normalize_gateway_amount_toman,normalize_provider_status,parse_remote_date,provision,recent_bluepay_errors,repair_subscription_states,sync_customer,test_marzban_panel,test_panel,verify_webhook
from .guardcore import service_ids_from_json,test_guardcore_panel
from .manual_guardcore import (
    attach_manual_subscription,
    ensure_manual_request_for_order,
    is_manual_guardcore,
    manual_request,
    notify_manual_request,
    pending_manual_requests,
    set_manual_decision,
)
from .github_release import github_repository,latest_github_release
from .models import AppSetting,Customer,CustomerDevice,CustomerSession,GuardCorePanel,MarzbanPanel,Order,OtpChallenge,PasarGuardPanel,PaymentSetting,Plan,SmsSetting,WebhookDelivery,AiConnectionEvent,AiRouteAggregate,AiFeedback
from .blueai import admin_overview as blueai_admin_overview, customer_dashboard as blueai_customer_dashboard, recommendations as blueai_recommendations, submit_event as blueai_submit_event, submit_feedback as blueai_submit_feedback
from .security import decrypt,encrypt,mask,new_token,password_hash,password_ok,session_expiry,token_hash,utcnow
from .sms import SmsError,local_phone,normalize_iran_phone,send_pattern_otp,sms_setting_ready
from .version import VERSION, VERSION_CODE
from .time_locale import TEHRAN_ZONE_NAME, format_jalali
BASE=Path(__file__).resolve().parent
logger=logging.getLogger('bluevpn.main')
templates=Jinja2Templates(directory=BASE/'templates')
templates.env.filters['jalali']=format_jalali
DEFAULT={'app_name':'BlueVPN','public_base_url':os.getenv('PUBLIC_BASE_URL','https://bluevpnapp-production.up.railway.app'),'maintenance':False,'support_url':os.getenv('SUPPORT_URL',''),'minimum_version':'0.4.9','force_update':False,'auto_update':True,'announcement_enabled':True,'announcement_id':'platform-100','announcement_title':'حساب یکپارچه BlueVPN','announcement_message':'خرید، تمدید و اشتراک شما به‌صورت خودکار مدیریت می‌شود.','blueai_enabled':True,'blueai_collective':True,'blueai_auto_heal':True,'blueai_min_samples':3,'blueai_privacy_message':'فقط شاخص‌های فنی اتصال و بدون محتوای ترافیک جمع‌آوری می‌شود.','updated_at':iso_z(utcnow())}


def env_bool(name:str,default:bool=False)->bool:
    value=os.getenv(name)
    if value is None:return default
    return value.strip().lower() in {'1','true','yes','on'}


class SlidingWindowRateLimiter:
    """Small in-process limiter for authentication endpoints.

    It intentionally stores no password, email or raw header. Keys are a scope
    plus a validated client IP. Railway normally runs one web process; for a
    multi-replica deployment use Redis or a database-backed limiter instead.
    """
    def __init__(self,max_buckets:int=20000):
        self.max_buckets=max(1000,max_buckets)
        self._buckets:dict[str,deque[float]]={}
        self._last_seen:dict[str,float]={}
        self._lock=threading.Lock()
        self._hits=0

    def hit(self,key:str,limit:int,window_seconds:int)->int:
        now=time.monotonic();limit=max(1,int(limit));window=max(1,int(window_seconds))
        with self._lock:
            self._hits+=1
            bucket=self._buckets.setdefault(key,deque())
            cutoff=now-window
            while bucket and bucket[0]<=cutoff:bucket.popleft()
            if len(bucket)>=limit:
                return max(1,int(window-(now-bucket[0]))+1)
            bucket.append(now)
            self._last_seen[key]=now
            if self._hits%500==0 or len(self._buckets)>self.max_buckets:
                stale_before=now-86400
                for old_key,last_seen in list(self._last_seen.items()):
                    if last_seen<stale_before:
                        self._last_seen.pop(old_key,None)
                        self._buckets.pop(old_key,None)
                if len(self._buckets)>self.max_buckets:
                    overflow=len(self._buckets)-self.max_buckets
                    oldest=sorted(self._last_seen,key=self._last_seen.get)[:overflow]
                    for old_key in oldest:
                        self._last_seen.pop(old_key,None)
                        self._buckets.pop(old_key,None)
            return 0

    def reset(self,key:str)->None:
        with self._lock:
            self._buckets.pop(key,None)
            self._last_seen.pop(key,None)


AUTH_LIMITER=SlidingWindowRateLimiter()
BACKUP_LOCK=threading.Lock()


def _validated_ip(raw:str)->str:
    value=(raw or '').strip()
    if not value:return ''
    if value.startswith('[') and ']' in value:value=value[1:value.index(']')]
    elif value.count(':')==1 and '.' in value:value=value.rsplit(':',1)[0]
    try:return str(ipaddress.ip_address(value))
    except ValueError:return ''


def client_ip(request:Request)->str:
    trust_proxy=env_bool('TRUST_PROXY_HEADERS',bool(os.getenv('RAILWAY_PROJECT_ID')))
    candidates=[]
    if trust_proxy:
        candidates.extend([
            request.headers.get('cf-connecting-ip',''),
            (request.headers.get('x-forwarded-for','').split(',')[0] if request.headers.get('x-forwarded-for') else ''),
            request.headers.get('x-real-ip',''),
        ])
    if request.client:candidates.append(request.client.host)
    for candidate in candidates:
        valid=_validated_ip(candidate)
        if valid:return valid
    return 'unknown'


def rate_limit_retry(request:Request,scope:str,limit:int,window_seconds:int)->int:
    return AUTH_LIMITER.hit(f'{scope}:{client_ip(request)}',limit,window_seconds)


def rate_limit_exception(retry_after:int)->HTTPException:
    return HTTPException(
        429,
        detail={'code':'RATE_LIMITED','message':'تعداد تلاش‌ها بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.'},
        headers={'Retry-After':str(max(1,retry_after))},
    )


def csrf_token(request:Request)->str:
    token=str(request.session.get('csrf_token',''))
    if len(token)<32:
        token=secrets.token_urlsafe(32)
        request.session['csrf_token']=token
    return token


def require_admin_csrf(request:Request,submitted:str)->None:
    admin_required(request)
    expected=str(request.session.get('csrf_token',''))
    if not expected or not secrets.compare_digest(expected,str(submitted or '')):
        raise HTTPException(403,'CSRF token is invalid')


def _sha256_file(path:Path)->str:
    digest=hashlib.sha256()
    with path.open('rb') as handle:
        for chunk in iter(lambda:handle.read(1024*1024),b''):
            digest.update(chunk)
    return digest.hexdigest()


def _backup_manifest()->dict[str,Any]:
    status=database_status()
    return {
        'product':'BlueVPN',
        'backend_version':VERSION,
        'version_code':VERSION_CODE,
        'created_at':iso_z(utcnow()),
        'database_mode':DATABASE_MODE,
        'schema_version':status.get('schema_version',''),
        'table_counts':database_table_counts(),
        'restore_note':'فایل dump را فقط در محیط امن و روی دیتابیس مقصد بازیابی کنید.',
    }


def create_database_backup()->tuple[Path,str,Path]:
    if not BACKUP_LOCK.acquire(blocking=False):
        raise HTTPException(409,'یک عملیات پشتیبان‌گیری دیگر در حال اجرا است')
    temp_dir=Path(tempfile.mkdtemp(prefix='bluevpn-db-backup-'))
    try:
        stamp=utcnow().strftime('%Y%m%d-%H%M%S')
        manifest=_backup_manifest()
        if DATABASE_MODE=='postgres':
            pg_dump=shutil.which('pg_dump')
            if not pg_dump:
                raise RuntimeError('pg_dump در کانتینر نصب نیست')
            url=ENGINE.url
            dump_path=temp_dir/f'bluevpn-postgres-{stamp}.dump'
            command=[
                pg_dump,
                '--format=custom','--compress=6','--no-owner','--no-acl',
                '--file',str(dump_path),
                '--host',str(url.host or ''),
                '--port',str(url.port or 5432),
                '--username',str(url.username or ''),
                '--dbname',str(url.database or ''),
            ]
            env=os.environ.copy()
            if url.password is not None:env['PGPASSWORD']=str(url.password)
            result=subprocess.run(command,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,timeout=300,check=False)
            if result.returncode!=0:
                raise RuntimeError('pg_dump ناموفق بود: '+(result.stderr or result.stdout)[-1200:])
            payload_path=dump_path
            manifest['format']='postgresql-custom-dump'
        else:
            source=Path(str(ENGINE.url.database or SQLITE_PATH))
            if not source.exists():raise RuntimeError('فایل SQLite پیدا نشد')
            dump_path=temp_dir/f'bluevpn-sqlite-{stamp}.db'
            src=sqlite3.connect(f'file:{source}?mode=ro',uri=True,timeout=30)
            dst=sqlite3.connect(dump_path)
            try:src.backup(dst,pages=1000,sleep=0.01)
            finally:dst.close();src.close()
            payload_path=dump_path
            manifest['format']='sqlite-database'
        checksum=_sha256_file(payload_path)
        manifest['payload_file']=payload_path.name
        manifest['payload_sha256']=checksum
        manifest_path=temp_dir/'manifest.json'
        manifest_path.write_text(json.dumps(manifest,ensure_ascii=False,indent=2),encoding='utf-8')
        restore_path=temp_dir/'RESTORE_FA.txt'
        restore_path.write_text(
            "پشتیبان BlueVPN\n\n"
            "PostgreSQL: از pg_restore روی یک دیتابیس خالی استفاده کنید.\n"
            "SQLite: سرویس را متوقف و فایل دیتابیس را با نسخه پشتیبان جایگزین کنید.\n"
            "قبل از بازیابی، حتماً از دیتابیس مقصد نیز بکاپ بگیرید.\n",
            encoding='utf-8',
        )
        zip_path=temp_dir/f'bluevpn-database-backup-{stamp}.zip'
        with zipfile.ZipFile(zip_path,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as archive:
            archive.write(payload_path,payload_path.name)
            archive.write(manifest_path,manifest_path.name)
            archive.write(restore_path,restore_path.name)
        return zip_path,zip_path.name,temp_dir
    except Exception:
        shutil.rmtree(temp_dir,ignore_errors=True)
        raise
    finally:
        BACKUP_LOCK.release()


session_https_only=env_bool('SESSION_HTTPS_ONLY',bool(os.getenv('RAILWAY_PROJECT_ID')))
app=FastAPI(title='BlueVPN Ultimate AI Platform',version=VERSION)
app.add_middleware(
    SessionMiddleware,
    secret_key=os.getenv('SESSION_SECRET') or secrets.token_urlsafe(48),
    same_site='strict',
    https_only=session_https_only,
    max_age=max(900,int(os.getenv('ADMIN_SESSION_MAX_AGE','43200'))),
)
app.mount('/static',StaticFiles(directory=BASE/'static'),name='static')

SUBSCRIPTION_PROVIDER_REPAIR_TASK: asyncio.Task | None = None

async def _repair_subscription_provider_orders(order_ids:list[int])->None:
    if not order_ids:
        return
    await asyncio.sleep(1)
    db=SessionLocal()
    try:
        for order_id in order_ids[:100]:
            order=db.scalar(
                select(Order)
                .options(selectinload(Order.customer),selectinload(Order.plan))
                .where(Order.id==order_id)
            )
            if not order or not order.customer or not order.plan:
                continue
            try:
                await provision(
                    db,
                    order.customer,
                    order.plan,
                    order,
                    settings(db)['public_base_url'],
                )
                logger.warning(
                    'Subscription expiry repaired on providers for order %s',
                    order.order_code,
                )
            except Exception:
                db.rollback()
                logger.exception(
                    'Provider expiry repair failed for order id=%s',
                    order_id,
                )
    finally:
        db.close()


def _schedule_subscription_provider_repair(order_ids:list[int])->None:
    global SUBSCRIPTION_PROVIDER_REPAIR_TASK
    unique=[int(item) for item in dict.fromkeys(order_ids) if int(item)>0]
    if not unique:
        return
    if (
        SUBSCRIPTION_PROVIDER_REPAIR_TASK is not None
        and not SUBSCRIPTION_PROVIDER_REPAIR_TASK.done()
    ):
        return
    SUBSCRIPTION_PROVIDER_REPAIR_TASK=asyncio.create_task(
        _repair_subscription_provider_orders(unique),
        name='subscription-provider-expiry-repair',
    )

@app.middleware('http')
async def locale_response_headers(request:Request,call_next):
    response=await call_next(request)
    response.headers.setdefault('Content-Language','fa-IR')
    response.headers.setdefault('X-BlueVPN-Timezone',TEHRAN_ZONE_NAME)
    response.headers.setdefault('X-BlueVPN-Calendar','jalali')
    return response
@app.on_event('startup')
async def startup():
    initialize_database(); db=SessionLocal()
    provider_repair_order_ids:list[int]=[]
    try:
        if not db.get(AppSetting,1):db.add(AppSetting(id=1,payload=json.dumps(DEFAULT,ensure_ascii=False)))
        if not db.get(PaymentSetting,1):db.add(PaymentSetting(id=1))
        if not db.get(SmsSetting,1):db.add(SmsSetting(id=1))
        db.commit()
        try:
            repair=repair_subscription_states(db)
            provider_repair_order_ids=list(
                repair.get('provider_repair_order_ids') or []
            )
            if repair.get('repaired') or repair.get('expiry_repaired'):
                logger.warning(
                    'Subscription recovery: status=%s expiry=%s scanned=%s',
                    repair.get('repaired',0),
                    repair.get('expiry_repaired',0),
                    repair.get('scanned',0),
                )
        except Exception:
            db.rollback()
            logger.exception('Automatic subscription-state recovery failed')
    finally:db.close()
    _schedule_subscription_provider_repair(provider_repair_order_ids)
def settings(db:Session)->dict:
    row=db.get(AppSetting,1)
    if not row:row=AppSetting(id=1,payload=json.dumps(DEFAULT,ensure_ascii=False));db.add(row);db.commit()
    try:data=json.loads(row.payload)
    except Exception:data={}
    return {**DEFAULT,**data}
def save_settings(db:Session,data:dict):
    data['updated_at']=iso_z(utcnow());row=db.get(AppSetting,1) or AppSetting(id=1,payload='{}');row.payload=json.dumps(data,ensure_ascii=False);row.updated_at=utcnow();db.add(row);db.commit()
def admin_required(request:Request):
    if not request.session.get('admin'):raise HTTPException(401,'Unauthorized')
def email_ok(raw:str)->str:
    e=raw.strip().lower()
    if len(e)>255 or not re.fullmatch(r"[a-z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-z0-9.-]+\.[a-z]{2,}",e):raise HTTPException(422,detail={'code':'INVALID_EMAIL','message':'ایمیل معتبر نیست'})
    return e

def phone_ok(raw:str)->str:
    try:
        return normalize_iran_phone(raw)
    except ValueError as exc:
        raise HTTPException(422,detail={'code':'INVALID_PHONE','message':str(exc)}) from exc

def phone_internal_email(phone:str)->str:
    digits=re.sub(r'\D','',phone_ok(phone))
    return f'phone-{digits}@users.bluevpn.local'

def customer_identity(customer:Customer)->str:
    return local_phone(customer.phone) if customer.phone else customer.email

def customer_by_identity(db:Session,raw:str)->Customer|None:
    value=str(raw or '').strip()
    if not value:return None
    try:
        phone=normalize_iran_phone(value)
    except ValueError:
        phone=''
    if phone:
        found=db.scalar(select(Customer).where(Customer.phone==phone))
        if found:return found
    try:
        email=email_ok(value)
    except HTTPException:
        return None
    return db.scalar(select(Customer).where(Customer.email==email))
def aware(d:datetime|None):
    if not d:return None
    return d.replace(tzinfo=timezone.utc) if d.tzinfo is None else d.astimezone(timezone.utc)
def bearer(value:str|None)->str:
    if not value:return ''
    s,_,t=value.partition(' ');return t.strip() if s.lower()=='bearer' else ''
UNLIMITED_ANDROID_EXPIRY = '9999-12-31T23:59:59Z'
EXPIRY_CLOCK_SKEW_SECONDS = 120
PAID_GATEWAY_STATUSES = {'paid','success','successful','confirmed','completed'}
PENDING_GATEWAY_STATUSES = {'','pending','created','creating','creating_invoice','processing','waiting','unpaid'}
FAILED_GATEWAY_STATUSES = {'failed','canceled','cancelled','expired','rejected','refunded','amount_mismatch'}

def normalize_gateway_status(value:Any)->str:
    status=str(value or '').strip().lower().replace('-','_').replace(' ','_')
    if status in PAID_GATEWAY_STATUSES:return 'paid'
    if status in {'cancelled','canceled'}:return 'canceled'
    if status in {'successfully_paid','payment_success'}:return 'paid'
    return status or 'pending'

ORDER_CLEANUP_INTERVAL_SECONDS = max(60, int(os.getenv('BLUEPAY_CLEANUP_INTERVAL_SECONDS', '300')))
ORDER_CREATING_GRACE_SECONDS = max(30, int(os.getenv('BLUEPAY_CREATING_GRACE_SECONDS', '120')))
CHECKOUT_ABANDON_GRACE_SECONDS = max(60, min(1800, int(os.getenv('BLUEPAY_ABANDON_GRACE_SECONDS', '300'))))
CHECKOUT_MAX_TTL_MINUTES = max(5, min(30, int(os.getenv('BLUEPAY_INVOICE_TTL_MINUTES', '30'))))
LOCAL_RECOVERABLE_STATUSES = {'expired_local', 'superseded', 'abandoned'}
PURGEABLE_ORDER_STATUSES = LOCAL_RECOVERABLE_STATUSES | {
    'invoice_failed','amount_mismatch','canceled','cancelled','expired',
    'failed','rejected','refunded',
}
FRESH_INVOICE_RETRY_COUNT = max(1,min(3,int(os.getenv('BLUEPAY_FRESH_INVOICE_RETRIES','2'))))
FRESH_INVOICE_MIN_LIFETIME_SECONDS = max(15,min(300,int(os.getenv('BLUEPAY_FRESH_INVOICE_MIN_LIFETIME_SECONDS','60'))))
PAYMENT_CLEANUP_TASK: asyncio.Task | None = None

def _order_metadata(order:Order)->dict:
    try:
        parsed=json.loads(order.gateway_json or '{}')
    except Exception:
        parsed={}
    return parsed if isinstance(parsed,dict) else {}

def _set_order_metadata(order:Order,metadata:dict)->None:
    order.gateway_json=json.dumps(metadata,ensure_ascii=False)

def payment_ttl_minutes(payment:PaymentSetting|None)->int:
    # A checkout may stay open for at most 30 minutes. Administrators can
    # choose a shorter TTL, but a stale setting can never extend it beyond
    # the product requirement.
    raw=(payment.ttl_minutes if payment else CHECKOUT_MAX_TTL_MINUTES) or CHECKOUT_MAX_TTL_MINUTES
    return max(5,min(CHECKOUT_MAX_TTL_MINUTES,int(raw)))

def hard_order_expiry(
    order:Order,
    payment:PaymentSetting|None=None,
    *,
    now:datetime|None=None,
)->datetime:
    current=aware(now or utcnow()) or datetime.now(timezone.utc)
    existing=aware(order.expires_at)
    if existing is not None:
        return existing
    metadata=_order_metadata(order)
    try:
        ttl=max(5,min(CHECKOUT_MAX_TTL_MINUTES,int(metadata.get('_bluevpn_invoice_ttl_minutes'))))
    except (TypeError,ValueError):
        ttl=payment_ttl_minutes(payment)
    return (aware(order.created_at) or current)+timedelta(minutes=ttl)

def computed_order_expiry(
    order:Order,
    payment:PaymentSetting|None=None,
    *,
    now:datetime|None=None,
)->datetime:
    """Return the effective local expiry for the checkout.

    An invoice has a hard 30-minute maximum. Once the Android checkout is
    explicitly closed, it remains reusable for only five more minutes. This
    prevents the next purchase attempt from reopening an old BluePay page.
    """
    hard=hard_order_expiry(order,payment,now=now)
    closed=aware(order.checkout_closed_at)
    if closed is None:
        return hard
    return min(hard,closed+timedelta(seconds=CHECKOUT_ABANDON_GRACE_SECONDS))

def ensure_order_expiry(
    order:Order,
    payment:PaymentSetting|None=None,
    *,
    now:datetime|None=None,
)->datetime:
    current=aware(now or utcnow()) or datetime.now(timezone.utc)
    metadata=_order_metadata(order)
    hard=aware(order.expires_at)
    if hard is None:
        created=aware(order.created_at) or current
        try:
            ttl=max(5,min(CHECKOUT_MAX_TTL_MINUTES,int(metadata.get('_bluevpn_invoice_ttl_minutes'))))
        except (TypeError,ValueError):
            ttl=payment_ttl_minutes(payment)
        hard=created+timedelta(minutes=ttl)
        order.expires_at=hard
        metadata['_bluevpn_invoice_ttl_minutes']=ttl
        metadata.setdefault('_bluevpn_invoice_created_at',iso_z(created))
    metadata['_bluevpn_invoice_expires_at']=iso_z(hard)
    effective=computed_order_expiry(order,payment,now=current)
    metadata['_bluevpn_effective_expires_at']=iso_z(effective)
    if order.checkout_opened_at:
        metadata['_bluevpn_checkout_opened_at']=iso_z(aware(order.checkout_opened_at))
    if order.checkout_last_seen_at:
        metadata['_bluevpn_checkout_last_seen_at']=iso_z(aware(order.checkout_last_seen_at))
    if order.checkout_closed_at:
        metadata['_bluevpn_checkout_closed_at']=iso_z(aware(order.checkout_closed_at))
    else:
        metadata.pop('_bluevpn_checkout_closed_at',None)
    _set_order_metadata(order,metadata)
    return effective

def order_is_locally_expired(
    order:Order,
    payment:PaymentSetting|None=None,
    *,
    now:datetime|None=None,
)->bool:
    current=aware(now or utcnow()) or datetime.now(timezone.utc)
    return ensure_order_expiry(order,payment,now=current)<=current

def _local_expiry_status(order:Order,now:datetime)->tuple[str,str]:
    closed=aware(order.checkout_closed_at)
    hard=hard_order_expiry(order,now=now)
    if (
        closed is not None
        and closed+timedelta(seconds=CHECKOUT_ABANDON_GRACE_SECONDS)<=now
        and hard>now
    ):
        return (
            'abandoned',
            'کاربر از صفحه پرداخت خارج شد و مهلت پنج‌دقیقه‌ای بازگشت پایان یافت؛ فاکتور جدید قابل ساخت است.',
        )
    return (
        'expired_local',
        'مهلت ۳۰ دقیقه‌ای پرداخت این فاکتور پایان یافته است؛ پرداخت دیرهنگام BluePay همچنان قابل بازیابی است.',
    )

def mark_checkout_open(order:Order,*,now:datetime|None=None)->None:
    current=aware(now or utcnow()) or datetime.now(timezone.utc)
    order.checkout_opened_at=current
    order.checkout_last_seen_at=current
    order.checkout_closed_at=None
    metadata=_order_metadata(order)
    metadata['_bluevpn_checkout_state']='open'
    metadata['_bluevpn_checkout_opened_at']=iso_z(current)
    metadata['_bluevpn_checkout_last_seen_at']=iso_z(current)
    metadata.pop('_bluevpn_checkout_closed_at',None)
    metadata['_bluevpn_effective_expires_at']=iso_z(hard_order_expiry(order,now=current))
    _set_order_metadata(order,metadata)

def mark_checkout_heartbeat(order:Order,*,now:datetime|None=None)->None:
    current=aware(now or utcnow()) or datetime.now(timezone.utc)
    if order.checkout_opened_at is None:
        order.checkout_opened_at=current
    order.checkout_last_seen_at=current
    metadata=_order_metadata(order)
    metadata['_bluevpn_checkout_state']='open'
    metadata['_bluevpn_checkout_last_seen_at']=iso_z(current)
    _set_order_metadata(order,metadata)

def mark_checkout_closed(order:Order,*,now:datetime|None=None)->None:
    current=aware(now or utcnow()) or datetime.now(timezone.utc)
    if order.checkout_opened_at is None:
        order.checkout_opened_at=current
    order.checkout_last_seen_at=current
    order.checkout_closed_at=current
    metadata=_order_metadata(order)
    metadata['_bluevpn_checkout_state']='closed'
    metadata['_bluevpn_checkout_closed_at']=iso_z(current)
    metadata['_bluevpn_checkout_last_seen_at']=iso_z(current)
    metadata['_bluevpn_effective_expires_at']=iso_z(
        min(
            hard_order_expiry(order,now=current),
            current+timedelta(seconds=CHECKOUT_ABANDON_GRACE_SECONDS),
        )
    )
    _set_order_metadata(order,metadata)

def _mark_order_status(
    order:Order,
    status:str,
    message:str='',
    *,
    now:datetime|None=None,
    replacement_order_id:str='',
)->None:
    current=aware(now or utcnow()) or datetime.now(timezone.utc)
    order.status=status
    if message:
        order.activation_error=message[:2000]
    metadata=_order_metadata(order)
    metadata['_bluevpn_local_status']=status
    metadata['_bluevpn_local_status_at']=iso_z(current)
    if replacement_order_id:
        metadata['_bluevpn_replacement_order_id']=replacement_order_id
    _set_order_metadata(order,metadata)

def _payment_url_is_valid(value:str)->bool:
    try:
        parsed=urlparse(str(value or '').strip())
    except Exception:
        return False
    return parsed.scheme in {'http','https'} and bool(parsed.netloc)

def _invoice_fingerprints(
    db:Session,
    customer_id:int,
    plan_id:int,
)->tuple[set[str],set[str]]:
    rows=list(db.execute(
        select(Order.payment_id,Order.payment_url).where(
            Order.customer_id==customer_id,
            Order.plan_id==plan_id,
        )
    ).all())
    payment_ids={str(payment_id or '').strip() for payment_id,_ in rows if str(payment_id or '').strip()}
    payment_urls={str(payment_url or '').strip() for _,payment_url in rows if str(payment_url or '').strip()}
    return payment_ids,payment_urls

def _delete_invalid_order(
    db:Session,
    order:Order,
    reason:str,
    *,
    event:str='local_invalid_invoice_deleted',
)->None:
    """Hard-delete an unpaid unusable invoice from the local database.

    BlueVPN 3.0.23 deliberately does not retain abandoned/expired invoice
    rows, because retaining them allowed stale payment URLs to be selected on
    the next purchase. A compact redacted diagnostic is written outside the
    orders table before deletion.
    """
    log_bluepay_error(
        event,
        order_code=order.order_code,
        payment_id=order.payment_id,
        error=reason[:1000],
        response_body={
            'status':order.status,
            'created_at':iso_z(aware(order.created_at)),
            'created_at_fa':format_jalali(aware(order.created_at),fallback=''),
            'expires_at':iso_z(aware(order.expires_at)),
            'expires_at_fa':format_jalali(aware(order.expires_at),fallback=''),
        },
    )
    db.delete(order)

async def _delete_invalid_remote_and_local(
    db:Session,
    order:Order,
    payment:PaymentSetting,
    reason:str,
    *,
    event:str='remote_and_local_invoice_deleted',
)->None:
    if order.payment_id:
        try:
            await delete_invoice(payment,order.payment_id)
        except Exception as exc:
            logger.warning('BluePay remote invoice delete failed for %s: %s',order.order_code,exc)
    _delete_invalid_order(db,order,reason,event=event)
    db.commit()

def expire_stale_orders(
    db:Session,
    *,
    customer_id:int|None=None,
    now:datetime|None=None,
    commit:bool=True,
)->dict[str,int]:
    current=aware(now or utcnow()) or datetime.now(timezone.utc)
    payment=db.get(PaymentSetting,1)
    query=select(Order).where(Order.status.in_(tuple(PENDING_GATEWAY_STATUSES)))
    if customer_id is not None:
        query=query.where(Order.customer_id==customer_id)
    rows=list(db.scalars(query.order_by(Order.created_at.asc())).all())
    deleted=0
    initialized=0
    for order in rows:
        had_expiry=order.expires_at is not None
        expires=ensure_order_expiry(order,payment,now=current)
        if not had_expiry:
            initialized+=1
        if expires<=current:
            local_status,message=_local_expiry_status(order,current)
            order.status=local_status
            _delete_invalid_order(db,order,message)
            deleted+=1

    terminal=select(Order).where(Order.status.in_(tuple(PURGEABLE_ORDER_STATUSES)))
    if customer_id is not None:
        terminal=terminal.where(Order.customer_id==customer_id)
    for order in list(db.scalars(terminal).all()):
        _delete_invalid_order(
            db,
            order,
            order.activation_error or 'فاکتور باطل یا غیرقابل استفاده از دیتابیس حذف شد.',
            event='terminal_invoice_deleted',
        )
        deleted+=1

    if commit and (deleted or initialized):
        db.commit()
    else:
        db.flush()
    # Keep the legacy ``expired`` key for dashboard/startup compatibility.
    return {
        'expired':deleted,
        'deleted':deleted,
        'initialized':initialized,
        'scanned':len(rows),
    }

def pending_order_counts(db:Session)->dict[str,int]:
    now=aware(utcnow()) or datetime.now(timezone.utc)
    payment=db.get(PaymentSetting,1)
    rows=list(db.scalars(
        select(Order).where(
            Order.status.in_(tuple(PENDING_GATEWAY_STATUSES|LOCAL_RECOVERABLE_STATUSES))
        )
    ).all())
    active=expired=local_expired=superseded=abandoned=0
    for order in rows:
        if order.status=='expired_local':
            local_expired+=1
        elif order.status=='superseded':
            superseded+=1
        elif order.status=='abandoned':
            abandoned+=1
        elif computed_order_expiry(order,payment,now=now)<=now:
            expired+=1
        else:
            active+=1
    return {
        'active':active,
        'stale_pending':expired,
        'expired_local':local_expired,
        'superseded':superseded,
        'abandoned':abandoned,
    }

async def _payment_cleanup_loop()->None:
    while True:
        await asyncio.sleep(ORDER_CLEANUP_INTERVAL_SECONDS)
        db=SessionLocal()
        try:
            result=expire_stale_orders(db)
            if result['expired']:
                logger.info(
                    'BluePay cleanup deleted %s invalid orders',
                    result['expired'],
                )
        except asyncio.CancelledError:
            raise
        except Exception:
            logger.exception('BluePay pending-order cleanup failed')
            db.rollback()
        finally:
            db.close()

@app.on_event('startup')
async def start_payment_cleanup()->None:
    global PAYMENT_CLEANUP_TASK
    if not env_bool('BLUEPAY_PENDING_CLEANUP_ENABLED',True):
        return
    db=SessionLocal()
    try:
        result=expire_stale_orders(db)
        if result['expired']:
            logger.warning(
                'BluePay startup cleanup deleted %s invalid orders',
                result['expired'],
            )
    except Exception:
        logger.exception('Initial BluePay pending-order cleanup failed')
        db.rollback()
    finally:
        db.close()
    if PAYMENT_CLEANUP_TASK is None or PAYMENT_CLEANUP_TASK.done():
        PAYMENT_CLEANUP_TASK=asyncio.create_task(
            _payment_cleanup_loop(),
            name='bluepay-pending-cleanup',
        )

@app.on_event('shutdown')
async def stop_subscription_provider_repair()->None:
    global SUBSCRIPTION_PROVIDER_REPAIR_TASK
    task=SUBSCRIPTION_PROVIDER_REPAIR_TASK
    SUBSCRIPTION_PROVIDER_REPAIR_TASK=None
    if task is None or task.done():
        return
    task.cancel()
    try:
        await task
    except asyncio.CancelledError:
        pass

@app.on_event('shutdown')
async def stop_payment_cleanup()->None:
    global PAYMENT_CLEANUP_TASK
    task=PAYMENT_CLEANUP_TASK
    PAYMENT_CLEANUP_TASK=None
    if task is None:
        return
    task.cancel()
    try:
        await task
    except asyncio.CancelledError:
        pass

TERMINAL_SUBSCRIPTION_STATUSES={
    'disabled','expired','blocked','banned','deleted','limited','revoked','suspended'
}


def _latest_paid_entitlement(
    db:Session|None,
    customer:Customer,
    *,
    now:datetime,
)->dict[str,Any]:
    """Resolve the most recent activated entitlement without trusting cache flags.

    A paid/activated order is the durable source of truth for the Android account
    badge. Provider sync may temporarily leave ``subscription_status`` as
    ``inactive``; that must not hide a still-valid paid entitlement.
    """
    result={
        'active':False,
        'unlimited':False,
        'expires_at':None,
        'order_id':None,
        'plan_id':None,
    }
    if db is None or not customer.id:
        return result
    order=db.scalar(
        select(Order)
        .where(
            Order.customer_id==customer.id,
            Order.status=='activated',
        )
        .order_by(
            Order.activated_at.desc().nullslast(),
            Order.paid_at.desc().nullslast(),
            Order.created_at.desc(),
            Order.id.desc(),
        )
        .limit(1)
    )
    if not order:
        return result
    plan=db.get(Plan,order.plan_id) if order.plan_id else None
    metadata=_order_metadata(order)
    stored_target=parse_remote_date(metadata.get('_bluevpn_target_expire'))
    base=aware(order.activated_at or order.paid_at or order.created_at)
    calculated_target=None
    duration_days=int(plan.duration_days or 0) if plan else 0
    if base and duration_days>0:
        calculated_target=(base+timedelta(days=duration_days)).replace(microsecond=0)
    candidates=[item for item in (stored_target,calculated_target) if item is not None]
    target=max(candidates) if candidates else None
    unlimited=bool(plan and duration_days<=0)
    active=bool(unlimited or (target and target>now-timedelta(seconds=EXPIRY_CLOCK_SKEW_SECONDS)))
    result.update({
        'active':active,
        'unlimited':unlimited,
        'expires_at':target,
        'order_id':order.id,
        'plan_id':order.plan_id,
    })
    return result


def account_json(c:Customer,db:Session|None=None)->dict:
    now=aware(utcnow()) or datetime.now(timezone.utc)
    stored_expiry=aware(c.subscription_expire)
    entitlement=_latest_paid_entitlement(db,c,now=now)
    entitlement_expiry=aware(entitlement.get('expires_at'))
    expiry=max(
        [item for item in (stored_expiry,entitlement_expiry) if item is not None],
        default=None,
    )
    normalized_status=normalize_provider_status(
        c.subscription_status,
        default='inactive',
    )
    terminal=normalized_status in TERMINAL_SUBSCRIPTION_STATUSES
    source_present=bool(
        c.pasarguard_subscription_url
        or c.marzban_subscription_url
        or c.guardcore_subscription_url
    )
    unlimited=bool(
        expiry is None
        and bool(c.subscription_url)
        and not terminal
        and (
            normalized_status=='active'
            or bool(entitlement.get('unlimited'))
        )
    )
    within_expiry=(
        unlimited
        or (expiry is not None and expiry>now-timedelta(seconds=EXPIRY_CLOCK_SKEW_SECONDS))
    )
    within_traffic=(
        not c.data_limit_bytes
        or c.used_traffic_bytes<c.data_limit_bytes
    )
    provider_active=any(
        normalize_provider_status(value,default='unknown')=='active'
        for value in (c.marzban_status,c.guardcore_status)
    )
    recovered_finite_state=bool(
        not terminal
        and normalized_status!='active'
        and expiry is not None
        and within_expiry
        and source_present
        and bool(c.last_sync_error)
    )
    paid_entitlement_active=bool(
        not terminal
        and entitlement.get('active')
        and source_present
    )
    active_reason='inactive'
    if normalized_status=='active':
        active_reason='stored_status'
    elif provider_active:
        active_reason='provider_status'
    elif paid_entitlement_active:
        active_reason='activated_order'
    elif recovered_finite_state:
        active_reason='recovered_state'
    active=bool(
        c.active
        and bool(c.subscription_url)
        and within_expiry
        and within_traffic
        and not terminal
        and active_reason!='inactive'
    )
    expire_value=(
        UNLIMITED_ANDROID_EXPIRY
        if unlimited
        else iso_z(expiry)
    )
    expire_mode=(
        'unlimited'
        if unlimited
        else 'fixed'
        if expiry is not None
        else 'none'
    )
    remaining_seconds=(
        None
        if unlimited
        else max(0,int((expiry-now).total_seconds()))
        if expiry is not None
        else 0
    )
    return {
        'id':c.id,
        'email':(c.email if not c.phone else ''),
        'phone':c.phone or '',
        'phone_display':local_phone(c.phone) if c.phone else '',
        'phone_verified':bool(c.phone and c.phone_verified_at),
        'auth_method':c.auth_method or 'legacy_email',
        'display_identity':customer_identity(c),
        'active':c.active,
        'plan_id':c.plan_id,
        'server_time':iso_z(now),
        'server_time_fa':format_jalali(now,include_seconds=True),
        'calendar':'jalali',
        'timezone':TEHRAN_ZONE_NAME,
        'subscription':{
            'active':active,
            'status':('active' if active else normalized_status),
            'active_reason':active_reason,
            'entitlement_active':bool(entitlement.get('active')),
            'entitlement_order_id':entitlement.get('order_id'),
            'entitlement_plan_id':entitlement.get('plan_id'),
            'url':c.subscription_url,
            'expire':expire_value,
            'expires_at':expire_value,
            'expire_fa':('نامحدود' if unlimited else format_jalali(expiry) if expiry else ''),
            'expires_at_fa':('نامحدود' if unlimited else format_jalali(expiry) if expiry else ''),
            'remaining_seconds':remaining_seconds,
            'calendar':'jalali',
            'timezone':TEHRAN_ZONE_NAME,
            'expire_mode':expire_mode,
            'unlimited':unlimited,
            'clock_skew_tolerance_seconds':EXPIRY_CLOCK_SKEW_SECONDS,
            'data_limit_bytes':c.data_limit_bytes,
            'used_traffic_bytes':c.used_traffic_bytes,
            'remaining_bytes':(
                max(0,c.data_limit_bytes-c.used_traffic_bytes)
                if c.data_limit_bytes else 0
            ),
            'device_limit':c.device_limit,
            'last_sync_at':iso_z(aware(c.last_sync_at)),
            'last_sync_at_fa':format_jalali(aware(c.last_sync_at),fallback=''),
            'sync_error':c.last_sync_error,
        },
    }
def current_customer(authorization:str|None=Header(None),x_device_id:str|None=Header(None),db:Session=Depends(get_db))->Customer:
    raw=bearer(authorization)
    if not raw:raise HTTPException(401,detail={'code':'AUTH_REQUIRED','message':'ورود لازم است'})
    session=db.scalar(select(CustomerSession).options(selectinload(CustomerSession.customer)).where(CustomerSession.token_hash==token_hash(raw)))
    if not session or session.revoked_at or aware(session.expires_at)<=utcnow() or not session.customer.active:raise HTTPException(401,detail={'code':'INVALID_SESSION','message':'نشست معتبر نیست'})
    if x_device_id and session.device_id!=x_device_id:raise HTTPException(401,detail={'code':'DEVICE_MISMATCH','message':'شناسه دستگاه معتبر نیست'})
    device=db.scalar(select(CustomerDevice).where(CustomerDevice.customer_id==session.customer_id,CustomerDevice.device_id==session.device_id))
    if not device or not device.active:raise HTTPException(401,detail={'code':'DEVICE_DISABLED','message':'این دستگاه غیرفعال شده است'})
    now=utcnow();session.last_seen_at=now;session.expires_at=session_expiry();device.last_seen_at=now;db.commit();return session.customer
def issue_session(db:Session,c:Customer,device_id:str,device_name:str,rotate_refresh:bool=True)->tuple[str,str]:
    device_id=device_id.strip()[:180]
    if not device_id:raise HTTPException(422,detail={'code':'DEVICE_ID_REQUIRED','message':'شناسه دستگاه لازم است'})
    device=db.scalar(select(CustomerDevice).where(CustomerDevice.customer_id==c.id,CustomerDevice.device_id==device_id));count=db.scalar(select(func.count(CustomerDevice.id)).where(CustomerDevice.customer_id==c.id,CustomerDevice.active.is_(True))) or 0
    if not device and count>=max(1,c.device_limit):raise HTTPException(409,detail={'code':'DEVICE_LIMIT_REACHED','message':f'حداکثر {c.device_limit} دستگاه برای این حساب مجاز است'})
    if not device:
        device=CustomerDevice(customer_id=c.id,device_id=device_id,device_name=device_name[:180],active=True);db.add(device);db.flush()
    else:
        device.active=True;device.device_name=device_name[:180] or device.device_name;device.last_seen_at=utcnow()

    now=utcnow()
    if device.previous_refresh_expires_at and aware(device.previous_refresh_expires_at)<=now:
        device.previous_refresh_token_hash=''
        device.previous_refresh_expires_at=None

    raw,h=new_token()
    db.add(CustomerSession(customer_id=c.id,token_hash=h,device_id=device_id,expires_at=session_expiry()))

    refresh_raw=''
    if rotate_refresh or not device.refresh_token_hash:
        if device.refresh_token_hash:
            device.previous_refresh_token_hash=device.refresh_token_hash
            device.previous_refresh_expires_at=now+timedelta(minutes=10)

        refresh_raw,refresh_hash=new_token()
        device.refresh_token_hash=refresh_hash
        device.refresh_expires_at=now+timedelta(days=3650)

    db.commit()
    return raw,refresh_raw
async def activate(db:Session,order:Order):
    locked=db.scalar(
        select(Order)
        .options(selectinload(Order.customer),selectinload(Order.plan))
        .where(Order.id==order.id)
        .with_for_update()
    )
    if not locked:
        raise IntegrationError('سفارش پیدا نشد')
    order=locked
    if order.status=='activated':
        try:
            ensure_manual_request_for_order(db,order)
            await notify_manual_request(db,order)
        except Exception:
            pass
        return

    metadata={}
    try:metadata=json.loads(order.gateway_json or '{}')
    except Exception:metadata={}
    started_at=parse_remote_date(metadata.get('_bluevpn_activation_started_at'))
    now=aware(utcnow()) or datetime.now(timezone.utc)
    if order.status=='activating' and started_at and started_at>now-timedelta(minutes=2):
        return

    order.status='activating'
    order.paid_at=order.paid_at or now
    metadata['_bluevpn_activation_started_at']=iso_z(now)
    order.gateway_json=json.dumps(metadata,ensure_ascii=False)
    db.commit()

    try:
        await provision(
            db,
            order.customer,
            order.plan,
            order,
            settings(db)['public_base_url'],
        )
        try:
            ensure_manual_request_for_order(db,order)
            await notify_manual_request(db,order)
        except Exception as notify_exc:
            metadata={}
            try:metadata=json.loads(order.gateway_json or '{}')
            except Exception:metadata={}
            request_data=metadata.get('guardcore_manual')
            if isinstance(request_data,dict):
                request_data['notify_error']=str(notify_exc).replace(os.getenv('BOT_TOKEN',''),'***')[:500]
                metadata['guardcore_manual']=request_data
                order.gateway_json=json.dumps(metadata,ensure_ascii=False)
                db.commit()
    except Exception as exc:
        if order.status!='partial_needs_sync':
            order.status='paid_needs_sync'
        order.activation_error=str(exc)[:2000]
        db.commit()

def order_response(order:Order,customer:Customer)->dict:
    metadata=_order_metadata(order)
    now=aware(utcnow()) or datetime.now(timezone.utc)
    hard_expires=hard_order_expiry(order,now=now)
    effective_expires=computed_order_expiry(order,now=now)
    locally_expired=(
        order.status in (LOCAL_RECOVERABLE_STATUSES|{'expired'})
        or (order.status in PENDING_GATEWAY_STATUSES and effective_expires<=now)
    )
    checkout_state=(
        'closed' if order.checkout_closed_at else
        'open' if order.checkout_opened_at else
        'created'
    )
    return {
        'id':order.id,
        'payment_id':order.payment_id,
        'status':order.status,
        'payment_url':order.payment_url,
        'amount_toman':order.amount_toman,
        'activation_error':order.activation_error,
        'created_at':iso_z(aware(order.created_at)),
        'created_at_fa':format_jalali(aware(order.created_at),fallback=''),
        'expires_at':iso_z(effective_expires),
        'expires_at_fa':format_jalali(effective_expires,fallback=''),
        'hard_expires_at':iso_z(hard_expires),
        'hard_expires_at_fa':format_jalali(hard_expires,fallback=''),
        'checkout_state':checkout_state,
        'checkout_opened_at':iso_z(aware(order.checkout_opened_at)),
        'checkout_opened_at_fa':format_jalali(aware(order.checkout_opened_at),fallback=''),
        'checkout_last_seen_at':iso_z(aware(order.checkout_last_seen_at)),
        'checkout_last_seen_at_fa':format_jalali(aware(order.checkout_last_seen_at),fallback=''),
        'checkout_closed_at':iso_z(aware(order.checkout_closed_at)),
        'checkout_closed_at_fa':format_jalali(aware(order.checkout_closed_at),fallback=''),
        'abandon_grace_seconds':CHECKOUT_ABANDON_GRACE_SECONDS,
        'expired':locally_expired,
        'replaced_by_order_id':str(metadata.get('_bluevpn_replacement_order_id') or ''),
        'paid_at':iso_z(aware(order.paid_at)),
        'paid_at_fa':format_jalali(aware(order.paid_at),fallback=''),
        'activated_at':iso_z(aware(order.activated_at)),
        'activated_at_fa':format_jalali(aware(order.activated_at),fallback=''),
        'calendar':'jalali',
        'timezone':TEHRAN_ZONE_NAME,
        'account':account_json(customer),
    }

async def refresh_order_from_bluepay(db:Session,order:Order)->dict|None:
    if not order.payment_id:
        return None
    payment=db.get(PaymentSetting,1)
    if not payment:
        raise IntegrationError('تنظیمات درگاه BluePay پیدا نشد')
    remote=await get_invoice(payment,order.payment_id)
    merge_order_metadata(db,order,'bluepay_last_status',remote)
    remote_amount,currency=normalize_gateway_amount_toman(remote,order.amount_toman)
    if remote_amount is not None and remote_amount!=int(order.amount_toman):
        order.status='amount_mismatch'
        order.activation_error=(
            f'مبلغ BluePay با سفارش برابر نیست: '
            f'{remote_amount} تومان ({currency}) / '
            f'{order.amount_toman} تومان'
        )
        log_bluepay_error(
            'amount_mismatch',
            order_code=order.order_code,
            payment_id=order.payment_id,
            error=order.activation_error,
            response_body=remote,
        )
        db.commit()
        return remote

    status=normalize_gateway_status(remote.get('status'))
    now=aware(utcnow()) or datetime.now(timezone.utc)
    if status=='paid':
        order.status='paid'
        order.paid_at=order.paid_at or now
        order.activation_error=''
    elif status in PENDING_GATEWAY_STATUSES:
        if order.status in LOCAL_RECOVERABLE_STATUSES:
            # A local expiry/supersede must not be downgraded to pending. A
            # later paid webhook or status response can still revive it.
            pass
        elif order_is_locally_expired(order,payment,now=now):
            local_status,message=_local_expiry_status(order,now)
            _mark_order_status(
                order,
                local_status,
                message,
                now=now,
            )
        else:
            order.status=status
    else:
        order.status=status
        if status in FAILED_GATEWAY_STATUSES:
            order.activation_error=order.activation_error or 'پرداخت توسط درگاه نهایی نشد.'
    db.commit()
    if order.status=='paid':
        await activate(db,order)
    return remote

def find_bluepay_order(db:Session,payload:dict)->Order|None:
    payment_id=str(payload.get('payment_id') or payload.get('id') or '')
    order_code=str(payload.get('order_id') or payload.get('order_code') or '')
    query=select(Order).options(selectinload(Order.customer),selectinload(Order.plan))
    if payment_id:
        found=db.scalar(query.where(Order.payment_id==payment_id))
        if found:return found
    if order_code:
        return db.scalar(query.where(Order.order_code==order_code))
    return None

def reusable_pending_order(
    db:Session,
    customer:Customer,
    plan:Plan,
    payment:PaymentSetting,
)->tuple[Order|None,bool]:
    """Return the newest locally usable invoice and delete duplicates.

    Remote validity is checked asynchronously by ``create_order`` before a
    URL is returned. The customer row is locked by the caller, so two taps
    cannot create two invoices.
    """
    now=aware(utcnow()) or datetime.now(timezone.utc)
    expire_stale_orders(db,customer_id=customer.id,now=now,commit=False)
    rows=list(db.scalars(
        select(Order)
        .where(
            Order.customer_id==customer.id,
            Order.plan_id==plan.id,
            Order.status.in_(tuple(PENDING_GATEWAY_STATUSES)),
        )
        .order_by(Order.created_at.desc(),Order.id.desc())
    ).all())

    completed:list[Order]=[]
    creating:list[Order]=[]
    for candidate in rows:
        if order_is_locally_expired(candidate,payment,now=now):
            _,message=_local_expiry_status(candidate,now)
            _delete_invalid_order(db,candidate,message)
            continue
        if candidate.payment_id and _payment_url_is_valid(candidate.payment_url):
            completed.append(candidate)
            continue
        age_seconds=max(0.0,(now-(aware(candidate.created_at) or now)).total_seconds())
        if candidate.status=='creating_invoice' and age_seconds<=ORDER_CREATING_GRACE_SECONDS:
            creating.append(candidate)
        else:
            _delete_invalid_order(
                db,
                candidate,
                'ساخت فاکتور قبلی کامل نشد و رکورد ناقص حذف شد.',
                event='incomplete_invoice_deleted',
            )

    usable=(completed[0] if completed else creating[0] if creating else None)
    in_progress=bool(usable is not None and usable in creating)
    if usable is not None:
        for candidate in rows:
            if candidate is usable or candidate.id==usable.id:
                continue
            if candidate in db.deleted:
                continue
            _delete_invalid_order(
                db,
                candidate,
                'فاکتور تکراری قدیمی حذف شد و فقط جدیدترین فاکتور معتبر باقی ماند.',
                event='duplicate_invoice_deleted',
            )
    db.flush()
    return usable,in_progress

async def _validate_reusable_invoice(
    db:Session,
    order:Order,
    payment:PaymentSetting,
)->str:
    """Return ``usable``, ``paid`` or ``invalid`` for a stored invoice."""
    now=aware(utcnow()) or datetime.now(timezone.utc)
    if not order.payment_id or not _payment_url_is_valid(order.payment_url):
        await _delete_invalid_remote_and_local(db,order,payment,'شناسه یا آدرس فاکتور قبلی ناقص بود.')
        return 'invalid'
    try:
        remote=await get_invoice(payment,order.payment_id)
    except IntegrationError as exc:
        # A temporary status-check outage should not create duplicate payable
        # invoices. Local hard TTL is still enforced.
        logger.warning('BluePay reuse validation unavailable for %s: %s',order.order_code,exc)
        return 'usable' if not order_is_locally_expired(order,payment,now=now) else 'invalid'

    merge_order_metadata(db,order,'bluepay_reuse_validation',remote)
    status=normalize_gateway_status(remote.get('status'))
    remote_amount,_=normalize_gateway_amount_toman(remote,order.amount_toman)
    remote_expiry=parse_remote_date(
        remote.get('expires_at') or remote.get('expire_at') or remote.get('expiration_at')
    )
    remote_url=str(remote.get('payment_url') or remote.get('url') or order.payment_url or '').strip()

    if remote_amount is not None and remote_amount!=order.amount_toman:
        await _delete_invalid_remote_and_local(db,order,payment,'مبلغ فاکتور ذخیره‌شده با سفارش برابر نبود.')
        return 'invalid'
    if status=='paid':
        order.status='paid'
        order.paid_at=order.paid_at or now
        order.activation_error=''
        db.commit()
        await activate(db,order)
        return 'paid'
    if status not in PENDING_GATEWAY_STATUSES:
        await _delete_invalid_remote_and_local(db,order,payment,f'وضعیت فاکتور قبلی در BluePay قابل پرداخت نبود: {status}')
        return 'invalid'
    if remote_expiry is not None and remote_expiry<=now+timedelta(seconds=FRESH_INVOICE_MIN_LIFETIME_SECONDS):
        await _delete_invalid_remote_and_local(db,order,payment,'فاکتور قبلی در BluePay منقضی یا نزدیک به انقضا بود.')
        return 'invalid'
    if not _payment_url_is_valid(remote_url):
        await _delete_invalid_remote_and_local(db,order,payment,'BluePay برای فاکتور قبلی آدرس پرداخت معتبر برنگرداند.')
        return 'invalid'

    order.payment_url=remote_url
    if remote_expiry is not None:
        order.expires_at=min(remote_expiry,now+timedelta(minutes=CHECKOUT_MAX_TTL_MINUTES))
    order.status=status
    db.commit()
    return 'usable'


def admin_redirect(anchor:str,message:str='',error:str='')->RedirectResponse:
    query=[]
    if message:
        query.append('manual='+quote_plus(message))
    if error:
        query.append('error='+quote_plus(error))
    suffix=('?'+('&'.join(query))) if query else ''
    return RedirectResponse('/admin'+suffix+'#'+anchor,303)

def enforce_customer_device_limit(db:Session,customer:Customer)->None:
    allowed=max(1,min(2,int(customer.device_limit or 1)))
    devices=list(db.scalars(
        select(CustomerDevice)
        .where(
            CustomerDevice.customer_id==customer.id,
            CustomerDevice.active.is_(True),
        )
        .order_by(CustomerDevice.last_seen_at.desc(),CustomerDevice.id.desc())
    ).all())
    for extra in devices[allowed:]:
        extra.active=False
        for session in db.scalars(
            select(CustomerSession).where(
                CustomerSession.customer_id==customer.id,
                CustomerSession.device_id==extra.device_id,
                CustomerSession.revoked_at.is_(None),
            )
        ).all():
            session.revoked_at=utcnow()
    db.commit()

async def create_manual_activation(
    db:Session,
    customer:Customer,
    plan:Plan,
    note:str='',
)->Order:
    if not customer.active:
        raise IntegrationError('حساب کاربر غیرفعال است')
    panel=db.get(PasarGuardPanel,plan.panel_id)
    if not panel or not panel.active:
        raise IntegrationError('پنل پاسارگارد این پلن غیرفعال یا حذف شده است')

    order=Order(
        order_code=(
            f"MANUAL-{customer.id}-"
            f"{utcnow().strftime('%Y%m%d%H%M%S')}-"
            f"{uuid.uuid4().hex[:6].upper()}"
        ),
        customer_id=customer.id,
        plan_id=plan.id,
        amount_toman=0,
        payment_id='',
        payment_url='',
        status='manual_pending',
        gateway_json=json.dumps(
            {
                'source':'admin_manual_activation',
                'customer_email':customer.email,
                'plan_id':plan.id,
                'plan_title':plan.title,
                'note':note.strip()[:500],
                'created_at':iso_z(utcnow()),
            },
            ensure_ascii=False,
        ),
        paid_at=utcnow(),
    )
    order.customer=customer
    order.plan=plan
    db.add(order)
    db.commit()
    db.refresh(order)

    await activate(db,order)
    db.refresh(order)
    db.refresh(customer)

    if order.status!='activated':
        raise IntegrationError(
            order.activation_error or
            'فعال‌سازی دستی روی همه پنل‌های پلن کامل نشد'
        )

    enforce_customer_device_limit(db,customer)
    return order
@app.get('/health')
def health():
    info=database_status()
    now=utcnow()
    return {
        'status':'ok' if info['ready'] else 'error',
        'service':'bluevpn-platform',
        'version':VERSION,
        'server_time':iso_z(now),
        'server_time_fa':format_jalali(now,include_seconds=True),
        'calendar':'jalali',
        'timezone':TEHRAN_ZONE_NAME,
        'database':info,
        'counts':database_table_counts() if info['ready'] else {},
    }
@app.get('/')
def root():return RedirectResponse('/admin',302)
@app.get('/api/v1/mobile/config')
async def mobile_config(
    refresh:bool=False,
    db:Session=Depends(get_db),
):
    s=settings(db)
    release,github_error=await latest_github_release(
        force=refresh,
    )
    release=release or {}
    return JSONResponse(
        {
            'app_name':s['app_name'],
            'maintenance':bool(s['maintenance']),
            'support_url':s['support_url'],
            'minimum_version':s['minimum_version'],
            'force_update':bool(s['force_update']),
            'auto_update':bool(s.get('auto_update',True)),
            'account_required':True,
            'latest_version':release.get('version','0.0.0'),
            'latest_version_code':int(release.get('version_code') or 0),
            'apk_url':release.get('apk_url',''),
            'apk_assets':release.get('apk_assets',{}),
            'apk_asset_meta':release.get('apk_asset_meta',{}),
            'update_title':release.get('title','نسخه جدید BlueVPN'),
            'update_message':release.get('message',''),
            'release_url':release.get('release_url',''),
            'release_published_at':release.get('published_at',''),
            'release_published_at_fa':format_jalali(release.get('published_at'),fallback=''),
            'release_build_number':int(release.get('build_number') or 0),
            'release_commit':release.get('commit',''),
            'update_source':'github_release',
            'github_repository':github_repository(),
            'github_error':github_error,
            'release_cache_seconds':15,
            'release_refresh_forced':bool(refresh),
            'auth':{
                'mode':'phone_otp',
                'password_login':False,
                'sms_provider':'farazsms_ippanel',
                'sms_ready':sms_setting_ready(db.get(SmsSetting,1)),
            },
            'blueai':{
                'enabled':bool(s.get('blueai_enabled',True)),
                'collective':bool(s.get('blueai_collective',True)),
                'auto_heal':bool(s.get('blueai_auto_heal',True)),
                'min_samples':int(s.get('blueai_min_samples',3) or 3),
                'privacy_message':str(s.get('blueai_privacy_message','')),
            },
            'announcement':{
                'enabled':bool(s['announcement_enabled']),
                'id':s['announcement_id'],
                'title':s['announcement_title'],
                'message':s['announcement_message'],
            },
            'updated_at':s['updated_at'],
            'updated_at_fa':format_jalali(s['updated_at'],fallback=''),
            'calendar':'jalali',
            'timezone':TEHRAN_ZONE_NAME,
        },
        headers={
            'Cache-Control':'no-store',
            'X-BlueVPN-Update-Source':'github-release',
        },
    )
OTP_MAX_ATTEMPTS=max(3,min(10,int(os.getenv('AUTH_OTP_MAX_ATTEMPTS','5'))))


def _otp_digest_input(challenge_id:str,phone:str,code:str)->str:
    return f'{challenge_id}:{phone}:{code}'


def _otp_code(length:int)->str:
    digits=max(4,min(8,int(length or 5)))
    floor=10**(digits-1)
    return str(secrets.randbelow(9*floor)+floor)


def _otp_setting(db:Session)->SmsSetting:
    setting=db.get(SmsSetting,1)
    if not sms_setting_ready(setting):
        raise HTTPException(
            503,
            detail={
                'code':'SMS_NOT_CONFIGURED',
                'message':'سامانه پیامکی فراز اس‌ام‌اس هنوز در پنل مدیریت تنظیم یا فعال نشده است.',
            },
        )
    return setting


async def _create_otp_challenge(
    request:Request,
    db:Session,
    phone_raw:str,
    device_id:str,
    purpose:str,
    customer_id:int|None=None,
)->dict:
    phone=phone_ok(phone_raw)
    device_id=str(device_id or '').strip()[:180]
    if not device_id:
        raise HTTPException(422,detail={'code':'DEVICE_ID_REQUIRED','message':'شناسه دستگاه لازم است'})
    setting=_otp_setting(db)
    now=utcnow()
    retention_days=max(1,min(90,int(os.getenv('AUTH_OTP_RETENTION_DAYS','7'))))
    db.execute(
        delete(OtpChallenge).where(
            OtpChallenge.expires_at < now-timedelta(days=retention_days)
        )
    )
    phone_key=hashlib.sha256(phone.encode()).hexdigest()[:20]
    window=max(300,int(os.getenv('AUTH_OTP_RATE_WINDOW_SECONDS','600')))
    retry=max(
        AUTH_LIMITER.hit(
            f'otp-request-ip:{client_ip(request)}',
            int(os.getenv('AUTH_OTP_IP_RATE_LIMIT','30')),
            window,
        ),
        AUTH_LIMITER.hit(
            f'otp-request-phone:{phone_key}',
            int(os.getenv('AUTH_OTP_PHONE_RATE_LIMIT','5')),
            window,
        ),
    )
    if retry:raise rate_limit_exception(retry)

    resend=max(30,min(600,int(setting.resend_seconds or 60)))
    latest=db.scalar(
        select(OtpChallenge)
        .where(
            OtpChallenge.phone==phone,
            OtpChallenge.purpose==purpose,
            OtpChallenge.consumed_at.is_(None),
        )
        .order_by(OtpChallenge.created_at.desc())
    )
    if latest:
        age=max(0,int((now-(aware(latest.created_at) or now)).total_seconds()))
        if age<resend:
            wait=resend-age
            raise HTTPException(
                429,
                detail={
                    'code':'OTP_RESEND_WAIT',
                    'message':f'{wait} ثانیه تا ارسال دوباره کد صبر کنید.',
                    'retry_after_seconds':wait,
                },
                headers={'Retry-After':str(wait)},
            )

    for old in db.scalars(
        select(OtpChallenge).where(
            OtpChallenge.phone==phone,
            OtpChallenge.purpose==purpose,
            OtpChallenge.consumed_at.is_(None),
        )
    ).all():
        old.consumed_at=now

    challenge_id=str(uuid.uuid4())
    code=_otp_code(setting.otp_length)
    ttl=max(60,min(600,int(setting.otp_ttl_seconds or 120)))
    challenge=OtpChallenge(
        id=challenge_id,
        phone=phone,
        purpose=purpose,
        customer_id=customer_id,
        device_id=device_id,
        code_hash=password_hash(_otp_digest_input(challenge_id,phone,code)),
        attempts=0,
        max_attempts=OTP_MAX_ATTEMPTS,
        expires_at=now+timedelta(seconds=ttl),
    )
    db.add(challenge)
    try:
        await send_pattern_otp(setting,phone,code)
    except SmsError as exc:
        db.rollback()
        logger.warning('Faraz SMS OTP send failed phone_hash=%s: %s',phone_key,exc)
        raise HTTPException(
            502,
            detail={'code':'SMS_SEND_FAILED','message':str(exc)[:500]},
        ) from exc
    db.commit()
    return {
        'success':True,
        'challenge_id':challenge.id,
        'phone':local_phone(phone),
        'expires_in_seconds':ttl,
        'resend_after_seconds':resend,
        'message':'کد تأیید برای شماره شما ارسال شد.',
    }


def _consume_otp(
    db:Session,
    *,
    phone_raw:str,
    challenge_id:str,
    code:str,
    device_id:str,
    purpose:str,
    customer_id:int|None=None,
)->tuple[OtpChallenge,str]:
    phone=phone_ok(phone_raw)
    challenge=db.scalar(
        select(OtpChallenge)
        .where(
            OtpChallenge.id==str(challenge_id or '').strip(),
            OtpChallenge.phone==phone,
            OtpChallenge.purpose==purpose,
        )
        .with_for_update()
    )
    now=utcnow()
    if not challenge:
        raise HTTPException(404,detail={'code':'OTP_NOT_FOUND','message':'درخواست کد تأیید پیدا نشد.'})
    if customer_id is not None and challenge.customer_id!=customer_id:
        raise HTTPException(403,detail={'code':'OTP_ACCOUNT_MISMATCH','message':'این کد برای حساب دیگری صادر شده است.'})
    if challenge.device_id and challenge.device_id!=str(device_id or '').strip()[:180]:
        raise HTTPException(401,detail={'code':'OTP_DEVICE_MISMATCH','message':'کد باید روی همان دستگاه درخواست‌کننده تأیید شود.'})
    if challenge.consumed_at:
        raise HTTPException(410,detail={'code':'OTP_ALREADY_USED','message':'این کد قبلاً استفاده شده است.'})
    if aware(challenge.expires_at)<=now:
        challenge.consumed_at=now
        db.commit()
        raise HTTPException(410,detail={'code':'OTP_EXPIRED','message':'مهلت کد تأیید پایان یافته است؛ کد جدید بگیرید.'})
    if challenge.attempts>=challenge.max_attempts:
        challenge.consumed_at=now
        db.commit()
        raise HTTPException(429,detail={'code':'OTP_LOCKED','message':'تعداد تلاش‌های ناموفق زیاد بود؛ کد جدید بگیرید.'})
    clean_code=re.sub(r'\D','',str(code or '').translate(str.maketrans('۰۱۲۳۴۵۶۷۸۹','0123456789')))
    challenge.attempts+=1
    if not password_ok(_otp_digest_input(challenge.id,phone,clean_code),challenge.code_hash):
        if challenge.attempts>=challenge.max_attempts:
            challenge.consumed_at=now
        db.commit()
        remaining=max(0,challenge.max_attempts-challenge.attempts)
        raise HTTPException(
            401,
            detail={
                'code':'INVALID_OTP',
                'message':'کد تأیید نادرست است.',
                'remaining_attempts':remaining,
            },
        )
    challenge.consumed_at=now
    db.flush()
    return challenge,phone


@app.post('/api/v1/auth/otp/request')
async def request_auth_otp(request:Request,db:Session=Depends(get_db)):
    body=await request.json()
    return await _create_otp_challenge(
        request,
        db,
        str(body.get('phone','')),
        str(body.get('device_id','')),
        'auth',
    )


@app.post('/api/v1/auth/otp/verify')
async def verify_auth_otp(request:Request,db:Session=Depends(get_db)):
    body=await request.json()
    device_id=str(body.get('device_id','')).strip()[:180]
    challenge,phone=_consume_otp(
        db,
        phone_raw=str(body.get('phone','')),
        challenge_id=str(body.get('challenge_id','')),
        code=str(body.get('code','')),
        device_id=device_id,
        purpose='auth',
    )
    customer=db.scalar(select(Customer).where(Customer.phone==phone).with_for_update())
    is_new=False
    if customer is None:
        customer=Customer(
            email=phone_internal_email(phone),
            password_hash=password_hash(secrets.token_urlsafe(48)),
            phone=phone,
            phone_verified_at=utcnow(),
            auth_method='phone_otp',
            device_limit=1,
        )
        db.add(customer)
        db.flush()
        is_new=True
    else:
        if not customer.active:
            db.commit()
            raise HTTPException(401,detail={'code':'ACCOUNT_DISABLED','message':'این حساب غیرفعال شده است.'})
        customer.phone_verified_at=customer.phone_verified_at or utcnow()
        customer.auth_method='phone_otp'
    db.commit()
    token,refresh_token=issue_session(
        db,
        customer,
        device_id,
        str(body.get('device_name','')),
    )
    return {
        'success':True,
        'is_new_account':is_new,
        'token':token,
        'refresh_token':refresh_token,
        'account':account_json(customer,db),
    }


@app.post('/api/v1/account/phone/otp/request')
async def request_bind_phone_otp(
    request:Request,
    customer:Customer=Depends(current_customer),
    db:Session=Depends(get_db),
):
    body=await request.json()
    phone=phone_ok(str(body.get('phone','')))
    owner=db.scalar(select(Customer).where(Customer.phone==phone,Customer.id!=customer.id))
    if owner:
        raise HTTPException(409,detail={'code':'PHONE_ALREADY_USED','message':'این شماره قبلاً به حساب دیگری متصل شده است.'})
    return await _create_otp_challenge(
        request,
        db,
        phone,
        str(body.get('device_id','')),
        'bind_phone',
        customer.id,
    )


@app.post('/api/v1/account/phone/otp/verify')
async def verify_bind_phone_otp(
    request:Request,
    customer:Customer=Depends(current_customer),
    db:Session=Depends(get_db),
):
    body=await request.json()
    _,phone=_consume_otp(
        db,
        phone_raw=str(body.get('phone','')),
        challenge_id=str(body.get('challenge_id','')),
        code=str(body.get('code','')),
        device_id=str(body.get('device_id','')),
        purpose='bind_phone',
        customer_id=customer.id,
    )
    owner=db.scalar(select(Customer).where(Customer.phone==phone,Customer.id!=customer.id))
    if owner:
        db.commit()
        raise HTTPException(409,detail={'code':'PHONE_ALREADY_USED','message':'این شماره قبلاً به حساب دیگری متصل شده است.'})
    customer=db.get(Customer,customer.id)
    customer.phone=phone
    customer.phone_verified_at=utcnow()
    customer.auth_method='phone_otp'
    db.commit()
    return {'success':True,'account':account_json(customer,db)}


@app.post('/api/v1/auth/register')
@app.post('/api/v1/auth/login')
async def legacy_password_auth_disabled():
    raise HTTPException(
        410,
        detail={
            'code':'PASSWORD_AUTH_DISABLED',
            'message':'ورود با ایمیل و رمز حذف شده است؛ با شماره تماس و کد پیامکی وارد شوید.',
        },
    )


@app.post('/api/v1/auth/refresh')
async def refresh_login(request:Request,db:Session=Depends(get_db)):
    body=await request.json()
    identity=str(body.get('phone') or body.get('identity') or body.get('email') or '').strip()
    device_id=str(body.get('device_id','')).strip()[:180]
    refresh_token=str(body.get('refresh_token',''))
    if not device_id or not refresh_token:
        raise HTTPException(401,detail={'code':'REFRESH_REQUIRED','message':'اطلاعات تمدید ورود کامل نیست'})
    customer=customer_by_identity(db,identity)
    if not customer or not customer.active:
        raise HTTPException(401,detail={'code':'ACCOUNT_DISABLED','message':'حساب در دسترس نیست'})
    device=db.scalar(select(CustomerDevice).where(CustomerDevice.customer_id==customer.id,CustomerDevice.device_id==device_id))
    if not device or not device.active:
        raise HTTPException(401,detail={'code':'DEVICE_DISABLED','message':'این دستگاه غیرفعال شده است'})

    now=utcnow()
    submitted_hash=token_hash(refresh_token)
    current_valid=(
        bool(device.refresh_token_hash)
        and device.refresh_token_hash==submitted_hash
        and bool(device.refresh_expires_at)
        and aware(device.refresh_expires_at)>now
    )
    previous_valid=(
        bool(device.previous_refresh_token_hash)
        and device.previous_refresh_token_hash==submitted_hash
        and bool(device.previous_refresh_expires_at)
        and aware(device.previous_refresh_expires_at)>now
    )
    if not current_valid and not previous_valid:
        raise HTTPException(401,detail={'code':'INVALID_REFRESH','message':'مجوز تمدید ورود معتبر نیست'})

    token,new_refresh_token=issue_session(
        db,
        customer,
        device_id,
        str(body.get('device_name','')),
        rotate_refresh=True,
    )
    return {
        'success':True,
        'token':token,
        'refresh_token':new_refresh_token,
        'account':account_json(customer,db),
    }


@app.post('/api/v1/auth/logout')
def logout(authorization:str|None=Header(None),x_device_id:str|None=Header(None),db:Session=Depends(get_db)):
    raw=bearer(authorization)
    session=db.scalar(select(CustomerSession).where(CustomerSession.token_hash==token_hash(raw))) if raw else None
    if session:
        session.revoked_at=utcnow()
        device=db.scalar(select(CustomerDevice).where(CustomerDevice.customer_id==session.customer_id,CustomerDevice.device_id==(x_device_id or session.device_id)))
        if device:
            device.refresh_token_hash=''
            device.refresh_expires_at=None
            device.previous_refresh_token_hash=''
            device.previous_refresh_expires_at=None
        db.commit()
    return {'success':True}

@app.get('/api/v1/plans')
def plans(
    c:Customer=Depends(current_customer),
    db:Session=Depends(get_db),
):
    rows=db.scalars(
        select(Plan)
        .where(
            Plan.active.is_(True),
            Plan.deleted.is_(False),
        )
        .order_by(
            Plan.sort_order,
            Plan.price_toman,
        )
    ).all()
    return {
        'success':True,
        'plans':[
            {
                'id':x.id,
                'title':x.title,
                'description':x.description,
                'price_toman':x.price_toman,
                'duration_days':x.duration_days,
                'data_limit_gb':x.data_limit_gb,
                'device_limit':x.device_limit,
            }
            for x in rows
        ],
    }
@app.post('/api/v1/ai/events')
async def ai_event(request:Request,c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    s=settings(db)
    if not bool(s.get('blueai_enabled',True)):
        return {'success':True,'accepted':False,'reason':'disabled'}
    payload=await request.json()
    if payload.get('consent') is not True:
        return {'success':True,'accepted':False,'reason':'consent_required'}
    try:
        result=blueai_submit_event(db,c,payload)
    except ValueError as exc:
        raise HTTPException(422,detail={'code':'AI_EVENT_INVALID','message':str(exc)})
    return {'success':True,**result}

@app.get('/api/v1/ai/recommendations')
async def ai_recommendations(operator:str='unknown',network_type:str='unknown',mode:str='balanced',hour:int|None=None,c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    s=settings(db)
    rows=blueai_recommendations(db,operator=operator,network_type=network_type,mode=mode,bucket=hour,limit=30) if bool(s.get('blueai_enabled',True)) else []
    return {'success':True,'enabled':bool(s.get('blueai_enabled',True)),'collective':bool(s.get('blueai_collective',True)),'recommendations':rows,'generated_at':iso_z(utcnow()),'generated_at_fa':format_jalali(utcnow(),include_seconds=True),'calendar':'jalali','timezone':TEHRAN_ZONE_NAME}

@app.get('/api/v1/ai/dashboard')
async def ai_dashboard(c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    return {'success':True,'dashboard':blueai_customer_dashboard(db,c)}

@app.post('/api/v1/feedback')
async def ai_feedback(request:Request,c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    payload=await request.json()
    return {'success':True,**blueai_submit_feedback(db,c,payload)}

@app.get('/api/v1/account')
async def account(c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    c=db.get(Customer,c.id);await sync_customer(db,c,settings(db)['public_base_url']);return {'success':True,'account':account_json(c,db)}
@app.post('/api/v1/account/sync')
async def account_sync(c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    c=db.get(Customer,c.id);await sync_customer(db,c,settings(db)['public_base_url']);return {'success':True,'account':account_json(c,db)}
@app.post('/api/v1/orders')
async def create_order(request:Request,c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    b=await request.json()
    try:
        plan_id=int(b.get('plan_id',0))
    except (TypeError,ValueError):
        plan_id=0
    plan=db.get(Plan,plan_id)
    if not plan or not plan.active or plan.deleted:
        raise HTTPException(404,detail={'code':'PLAN_NOT_FOUND','message':'پلن پیدا نشد'})

    pay=db.get(PaymentSetting,1)
    if not pay or not pay.active:
        raise HTTPException(503,detail={'code':'PAYMENT_NOT_CONFIGURED','message':'درگاه BluePay فعال یا کامل نیست'})

    c=db.scalar(select(Customer).where(Customer.id==c.id).with_for_update())
    if not c:
        raise HTTPException(404,detail={'code':'ACCOUNT_NOT_FOUND','message':'حساب کاربر پیدا نشد'})

    blocked_payment_ids,blocked_payment_urls=_invoice_fingerprints(db,c.id,plan.id)
    existing,in_progress=reusable_pending_order(db,c,plan,pay)
    if existing is not None and not in_progress:
        reuse_state=await _validate_reusable_invoice(db,existing,pay)
        if reuse_state=='usable':
            mark_checkout_open(existing)
            db.commit()
            return {
                'success':True,
                'reused':True,
                'order':order_response(existing,c),
                'check_after_success_url':f'/api/v1/orders/{existing.id}/check-after-success',
                'poll_interval_seconds':5,
                'poll_timeout_seconds':30,
            }
        if reuse_state=='paid':
            raise HTTPException(
                409,
                detail={
                    'code':'PREVIOUS_ORDER_ALREADY_PAID',
                    'message':'پرداخت قبلی همین حالا تأیید و اشتراک فعال شد؛ ابتدا وضعیت حساب را تازه‌سازی کنید.',
                },
            )
        existing=None

    if existing is not None and in_progress and not existing.payment_url:
        db.commit()
        raise HTTPException(
            409,
            detail={
                'code':'INVOICE_CREATION_IN_PROGRESS',
                'message':'فاکتور قبلی هنوز در حال ساخته‌شدن است؛ چند ثانیه دیگر دوباره بررسی کنید.',
                'order_id':existing.id,
            },
            headers={'Retry-After':'3'},
        )

    base=settings(db)['public_base_url'].rstrip('/')
    last_error='BluePay فاکتور قابل پرداخت ایجاد نکرد.'
    for attempt in range(1,FRESH_INVOICE_RETRY_COUNT+1):
        now=aware(utcnow()) or datetime.now(timezone.utc)
        ttl=payment_ttl_minutes(pay)
        expires=now+timedelta(minutes=ttl)
        order=Order(
            order_code=f'BV-{c.id}-{uuid.uuid4().hex[:16].upper()}',
            customer_id=c.id,
            plan_id=plan.id,
            amount_toman=int(plan.price_toman),
            status='creating_invoice',
            expires_at=expires,
            checkout_opened_at=now,
            checkout_last_seen_at=now,
            checkout_closed_at=None,
            gateway_json=json.dumps(
                {
                    '_bluevpn_invoice_created_at':iso_z(now),
                    '_bluevpn_invoice_ttl_minutes':ttl,
                    '_bluevpn_invoice_expires_at':iso_z(expires),
                    '_bluevpn_checkout_state':'open',
                    '_bluevpn_checkout_opened_at':iso_z(now),
                    '_bluevpn_checkout_last_seen_at':iso_z(now),
                    '_bluevpn_source':'android',
                    '_bluevpn_fresh_attempt':attempt,
                    '_bluevpn_request_nonce':uuid.uuid4().hex,
                },
                ensure_ascii=False,
            ),
        )
        db.add(order)
        db.commit()
        order=db.scalar(
            select(Order)
            .options(selectinload(Order.customer),selectinload(Order.plan))
            .where(Order.id==order.id)
        )

        try:
            invoice=await create_invoice(pay,order,base+'/webhooks/bluepay')
        except IntegrationError as exc:
            last_error=str(exc)
            _delete_invalid_order(db,order,last_error,event='invoice_create_attempt_deleted')
            db.commit()
            if attempt<FRESH_INVOICE_RETRY_COUNT:
                continue
            raise HTTPException(502,detail={'code':'INVOICE_CREATE_FAILED','message':last_error})

        invoice_amount,currency=normalize_gateway_amount_toman(invoice,order.amount_toman)
        payment_id=str(invoice.get('payment_id') or invoice.get('id') or '').strip()
        payment_url=str(invoice.get('payment_url') or invoice.get('url') or '').strip()
        status=normalize_gateway_status(invoice.get('status'))
        remote_expiry=parse_remote_date(
            invoice.get('expires_at') or invoice.get('expire_at') or invoice.get('expiration_at')
        )

        invalid_reason=''
        if invoice_amount is not None and invoice_amount!=order.amount_toman:
            invalid_reason=(
                f'مبلغ فاکتور BluePay {invoice_amount} تومان ({currency}) است، '
                f'اما مبلغ سفارش {order.amount_toman} تومان است'
            )
        elif not payment_id or not _payment_url_is_valid(payment_url):
            invalid_reason='BluePay شناسه یا آدرس پرداخت معتبر برنگرداند.'
        elif status not in PENDING_GATEWAY_STATUSES and status!='paid':
            invalid_reason=f'BluePay فاکتور تازه را با وضعیت غیرقابل پرداخت {status} برگرداند.'
        elif remote_expiry is not None and remote_expiry<=now+timedelta(seconds=FRESH_INVOICE_MIN_LIFETIME_SECONDS):
            invalid_reason='BluePay فاکتور تازه‌ای برگرداند که منقضی یا نزدیک به انقضا بود.'
        elif payment_id in blocked_payment_ids or payment_url in blocked_payment_urls:
            invalid_reason='BluePay همان فاکتور یا لینک باطل قبلی را دوباره برگرداند.'

        if invalid_reason:
            last_error=invalid_reason
            log_bluepay_error(
                'stale_fresh_invoice_rejected',
                order_code=order.order_code,
                payment_id=payment_id,
                error=invalid_reason,
                response_body=invoice,
            )
            blocked_payment_ids.add(payment_id)
            blocked_payment_urls.add(payment_url)
            if payment_id:
                try:
                    await delete_invoice(pay,payment_id)
                except Exception as exc:
                    logger.warning('BluePay fresh invalid invoice delete failed for %s: %s',order.order_code,exc)
            _delete_invalid_order(db,order,invalid_reason,event='fresh_invoice_deleted')
            db.commit()
            if attempt<FRESH_INVOICE_RETRY_COUNT:
                continue
            raise HTTPException(
                502,
                detail={
                    'code':'BLUEPAY_STALE_INVOICE_RESPONSE',
                    'message':'درگاه فاکتور تازه و قابل پرداخت نساخت؛ فاکتور باطل باز نشد. چند لحظه بعد دوباره تلاش کنید.',
                },
            )

        order.payment_id=payment_id
        order.payment_url=payment_url
        order.status=status
        order.activation_error=''
        if remote_expiry is not None and remote_expiry>now:
            order.expires_at=min(remote_expiry,now+timedelta(minutes=CHECKOUT_MAX_TTL_MINUTES))
        merge_order_metadata(db,order,'bluepay_create',invoice)
        metadata=_order_metadata(order)
        metadata['_bluevpn_invoice_expires_at']=iso_z(aware(order.expires_at))
        metadata['_bluevpn_fresh_invoice_validated']=True
        _set_order_metadata(order,metadata)
        db.commit()
        return {
            'success':True,
            'reused':False,
            'order':order_response(order,c),
            'check_after_success_url':f'/api/v1/orders/{order.id}/check-after-success',
            'poll_interval_seconds':5,
            'poll_timeout_seconds':30,
        }

    raise HTTPException(502,detail={'code':'INVOICE_CREATE_FAILED','message':last_error})

def _checkout_order_for_customer(
    db:Session,
    order_id:str,
    customer_id:int,
)->Order:
    order=db.scalar(
        select(Order)
        .options(selectinload(Order.customer),selectinload(Order.plan))
        .where(Order.id==order_id,Order.customer_id==customer_id)
        .with_for_update()
    )
    if not order:
        raise HTTPException(404,detail={'code':'ORDER_NOT_FOUND','message':'فاکتور پیدا نشد'})
    return order

@app.post('/api/v1/orders/{order_id}/checkout/open')
def checkout_open(
    order_id:str,
    c:Customer=Depends(current_customer),
    db:Session=Depends(get_db),
):
    order=_checkout_order_for_customer(db,order_id,c.id)
    now=aware(utcnow()) or datetime.now(timezone.utc)
    if order.status in PENDING_GATEWAY_STATUSES:
        if hard_order_expiry(order,db.get(PaymentSetting,1),now=now)<=now:
            _delete_invalid_order(db,order,'مهلت ۳۰ دقیقه‌ای پرداخت این فاکتور پایان یافته است.')
            db.commit()
            raise HTTPException(410,detail={'code':'ORDER_GONE','message':'فاکتور منقضی حذف شد؛ پرداخت جدید بسازید'})
        if computed_order_expiry(order,db.get(PaymentSetting,1),now=now)<=now:
            _,message=_local_expiry_status(order,now)
            _delete_invalid_order(db,order,message)
            db.commit()
            raise HTTPException(410,detail={'code':'ORDER_GONE','message':'فاکتور باطل حذف شد؛ پرداخت جدید بسازید'})
        mark_checkout_open(order,now=now)
    db.commit()
    customer=db.get(Customer,c.id)
    return {'success':True,'order':order_response(order,customer)}

@app.post('/api/v1/orders/{order_id}/checkout/heartbeat')
def checkout_heartbeat(
    order_id:str,
    c:Customer=Depends(current_customer),
    db:Session=Depends(get_db),
):
    order=_checkout_order_for_customer(db,order_id,c.id)
    now=aware(utcnow()) or datetime.now(timezone.utc)
    if order.status in PENDING_GATEWAY_STATUSES:
        if computed_order_expiry(order,db.get(PaymentSetting,1),now=now)<=now:
            _,message=_local_expiry_status(order,now)
            _delete_invalid_order(db,order,message)
            db.commit()
            raise HTTPException(410,detail={'code':'ORDER_GONE','message':'فاکتور باطل حذف شد؛ پرداخت جدید بسازید'})
        mark_checkout_heartbeat(order,now=now)
    db.commit()
    customer=db.get(Customer,c.id)
    return {'success':True,'order':order_response(order,customer)}

@app.post('/api/v1/orders/{order_id}/checkout/close')
def checkout_close(
    order_id:str,
    c:Customer=Depends(current_customer),
    db:Session=Depends(get_db),
):
    order=_checkout_order_for_customer(db,order_id,c.id)
    now=aware(utcnow()) or datetime.now(timezone.utc)
    if order.status in PENDING_GATEWAY_STATUSES:
        if hard_order_expiry(order,db.get(PaymentSetting,1),now=now)<=now:
            _delete_invalid_order(db,order,'مهلت ۳۰ دقیقه‌ای پرداخت این فاکتور پایان یافته است.')
            db.commit()
            raise HTTPException(410,detail={'code':'ORDER_GONE','message':'فاکتور منقضی حذف شد؛ پرداخت جدید بسازید'})
        mark_checkout_closed(order,now=now)
    db.commit()
    customer=db.get(Customer,c.id)
    return {
        'success':True,
        'close_grace_seconds':CHECKOUT_ABANDON_GRACE_SECONDS,
        'order':order_response(order,customer),
    }

@app.get('/api/v1/orders/{order_id}')
async def order_status(order_id:str,c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    order=db.scalar(
        select(Order)
        .options(selectinload(Order.customer),selectinload(Order.plan))
        .where(Order.id==order_id,Order.customer_id==c.id)
    )
    if not order:raise HTTPException(404,detail={'code':'ORDER_NOT_FOUND','message':'فاکتور قبلی حذف شده است؛ پرداخت جدید بسازید'})
    pay=db.get(PaymentSetting,1)
    if order.payment_id and order.status in (PENDING_GATEWAY_STATUSES|LOCAL_RECOVERABLE_STATUSES):
        try:
            await refresh_order_from_bluepay(db,order)
        except Exception as exc:
            order.activation_error=str(exc)[:1000]
            if order.status in PENDING_GATEWAY_STATUSES and order_is_locally_expired(order,pay):
                now=aware(utcnow()) or datetime.now(timezone.utc)
                local_status,message=_local_expiry_status(order,now)
                _mark_order_status(order,local_status,message,now=now)
            db.commit()
    elif order.status in PENDING_GATEWAY_STATUSES and order_is_locally_expired(order,pay):
        now=aware(utcnow()) or datetime.now(timezone.utc)
        local_status,message=_local_expiry_status(order,now)
        _mark_order_status(order,local_status,message,now=now)
        db.commit()
    elif order.status in {'paid','paid_needs_sync','partial_needs_sync'}:
        await activate(db,order)
    if order.status in PURGEABLE_ORDER_STATUSES:
        _delete_invalid_order(db,order,order.activation_error or 'فاکتور باطل حذف شد.')
        db.commit()
        raise HTTPException(410,detail={'code':'ORDER_GONE','message':'فاکتور باطل حذف شد؛ پرداخت جدید بسازید'})
    c=db.get(Customer,c.id)
    db.refresh(order)
    return {'success':True,'order':order_response(order,c)}

@app.get('/api/v1/orders/{order_id}/check-after-success')
async def check_order_after_success(
    order_id:str,
    timeout_seconds:int=30,
    interval_seconds:int=5,
    c:Customer=Depends(current_customer),
    db:Session=Depends(get_db),
):
    timeout_seconds=max(0,min(30,int(timeout_seconds)))
    interval_seconds=max(1,min(5,int(interval_seconds)))
    started=time.monotonic()
    attempts=0
    last_error=''
    while True:
        order=db.scalar(
            select(Order)
            .options(selectinload(Order.customer),selectinload(Order.plan))
            .where(Order.id==order_id,Order.customer_id==c.id)
        )
        if not order:raise HTTPException(404,detail={'code':'ORDER_NOT_FOUND','message':'فاکتور قبلی حذف شده است؛ پرداخت جدید بسازید'})
        attempts+=1
        try:
            if order.payment_id and order.status in (PENDING_GATEWAY_STATUSES|LOCAL_RECOVERABLE_STATUSES):
                await refresh_order_from_bluepay(db,order)
            elif order.status in {'paid','paid_needs_sync','partial_needs_sync'}:
                await activate(db,order)
            elif order.status in PENDING_GATEWAY_STATUSES and order_is_locally_expired(order,db.get(PaymentSetting,1)):
                now=aware(utcnow()) or datetime.now(timezone.utc)
                local_status,message=_local_expiry_status(order,now)
                _mark_order_status(order,local_status,message,now=now)
                db.commit()
        except Exception as exc:
            last_error=str(exc)[:1000]
            order.activation_error=last_error
            if order.status in PENDING_GATEWAY_STATUSES and order_is_locally_expired(order,db.get(PaymentSetting,1)):
                now=aware(utcnow()) or datetime.now(timezone.utc)
                local_status,message=_local_expiry_status(order,now)
                _mark_order_status(order,local_status,message,now=now)
            db.commit()

        if order.status in PURGEABLE_ORDER_STATUSES:
            _delete_invalid_order(db,order,order.activation_error or 'فاکتور باطل حذف شد.')
            db.commit()
            raise HTTPException(410,detail={'code':'ORDER_GONE','message':'فاکتور باطل حذف شد؛ پرداخت جدید بسازید'})
        db.refresh(order)
        terminal=(
            order.status in {'activated'}
            or order.status in FAILED_GATEWAY_STATUSES
            or order.status in LOCAL_RECOVERABLE_STATUSES
        )
        if terminal:
            break
        elapsed=time.monotonic()-started
        if elapsed>=timeout_seconds:
            break
        await asyncio.sleep(min(interval_seconds,max(0.0,timeout_seconds-elapsed)))

    c=db.get(Customer,c.id)
    elapsed_seconds=round(time.monotonic()-started,2)
    pending=(
        order.status in PENDING_GATEWAY_STATUSES
        or order.status in {'paid','activating','paid_needs_sync','partial_needs_sync'}
    )
    return {
        'success':True,
        'confirmed':order.status=='activated',
        'pending':pending,
        'attempts':attempts,
        'elapsed_seconds':elapsed_seconds,
        'retry_after_seconds':interval_seconds if pending else 0,
        'last_error':last_error,
        'server_time':iso_z(utcnow()),
        'server_time_fa':format_jalali(utcnow(),include_seconds=True),
        'calendar':'jalali',
        'timezone':TEHRAN_ZONE_NAME,
        'order':order_response(order,c),
    }


@app.post('/webhooks/bluepay')
async def bluepay_webhook(request:Request,x_gateway_signature:str|None=Header(None),x_gateway_delivery:str|None=Header(None),x_gateway_event:str|None=Header(None),db:Session=Depends(get_db)):
    pay=db.get(PaymentSetting,1)
    secret=decrypt(pay.callback_secret_enc) if pay else ''
    raw=await request.body()
    valid,payload=verify_webhook(raw,x_gateway_signature or '',secret)
    if not secret or not valid:
        log_bluepay_error(
            'webhook_signature',
            status_code=401,
            error='امضای Webhook نامعتبر است',
        )
        return JSONResponse({'success':False},status_code=401)

    order=find_bluepay_order(db,payload)
    payment_id=str(payload.get('payment_id') or payload.get('id') or (order.payment_id if order else ''))
    incoming_status=normalize_gateway_status(payload.get('status'))
    delivery=x_gateway_delivery or f"payment:{payment_id}:{payload.get('status','')}"
    duplicate=db.scalar(select(WebhookDelivery).where(WebhookDelivery.delivery_id==delivery))

    if not order:
        if not duplicate:
            db.add(WebhookDelivery(
                delivery_id=delivery,
                payment_id=payment_id,
                event=x_gateway_event or str(payload.get('event','')),
            ))
        log_bluepay_error(
            'webhook_order_not_found',
            payment_id=payment_id,
            error='سفارش متناظر با Webhook پیدا نشد',
            response_body=payload,
        )
        db.commit()
        return {'success':True,'order_found':False,'duplicate':bool(duplicate)}

    merge_order_metadata(db,order,'bluepay_webhook',payload)
    remote_amount,currency=normalize_gateway_amount_toman(payload,order.amount_toman)
    if remote_amount is not None and remote_amount!=order.amount_toman:
        order.status='amount_mismatch'
        order.activation_error=(
            f'مبلغ Webhook برابر نیست: {remote_amount} تومان ({currency}) / '
            f'{order.amount_toman} تومان'
        )
        log_bluepay_error(
            'webhook_amount_mismatch',
            order_code=order.order_code,
            payment_id=payment_id,
            error=order.activation_error,
            response_body=payload,
        )
        if not duplicate:
            db.add(WebhookDelivery(
                delivery_id=delivery,
                payment_id=payment_id,
                event=x_gateway_event or str(payload.get('event','')),
            ))
        db.commit()
        return {'success':False,'code':'AMOUNT_MISMATCH'}

    if not duplicate:
        db.add(WebhookDelivery(
            delivery_id=delivery,
            payment_id=payment_id,
            event=x_gateway_event or str(payload.get('event','')),
        ))

    now=aware(utcnow()) or datetime.now(timezone.utc)
    recovered_from=order.status if order.status in LOCAL_RECOVERABLE_STATUSES else ''
    if incoming_status=='paid':
        # Local expiry and duplicate suppression never discard a real payment.
        order.status='paid'
        order.paid_at=order.paid_at or now
        order.activation_error=''
        metadata=_order_metadata(order)
        if metadata.get('_bluevpn_local_status') in LOCAL_RECOVERABLE_STATUSES:
            metadata['_bluevpn_late_payment_recovered_from']=metadata.get('_bluevpn_local_status')
            metadata['_bluevpn_late_payment_recovered_at']=iso_z(now)
            _set_order_metadata(order,metadata)
    elif incoming_status in PENDING_GATEWAY_STATUSES and order.status in LOCAL_RECOVERABLE_STATUSES:
        # Do not resurrect an expired/superseded invoice merely because the
        # gateway still reports pending. A future paid event can revive it.
        pass
    else:
        order.status=incoming_status
    db.commit()

    if order.status=='paid':
        await activate(db,order)
    return {
        'success':True,
        'status':order.status,
        'duplicate':bool(duplicate),
        'late_payment_recovered':bool(recovered_from and incoming_status=='paid'),
        'recovered_from':recovered_from,
    }

@app.get('/admin/api/bluepay/errors')
def admin_bluepay_errors(request:Request,limit:int=100):
    admin_required(request)
    items=recent_bluepay_errors(limit)
    return {
        'success':True,
        'count':len(items),
        'auth_error':any(bool(item.get('auth_error')) for item in items),
        'items':items,
    }

@app.post('/admin/bluepay/cleanup')
def admin_bluepay_cleanup(
    request:Request,
    csrf:str=Form(...),
    db:Session=Depends(get_db),
):
    require_admin_csrf(request,csrf)
    result=expire_stale_orders(db)
    counts=pending_order_counts(db)
    message=(
        f"پاک‌سازی انجام شد: {result['deleted']} فاکتور باطل حذف و "
        f"{result['initialized']} فاکتور قدیمی زمان‌دار شد. "
        f"فاکتور فعال: {counts['active']}"
    )
    return admin_redirect('bluepay',message=message)

@app.get('/admin/api/bluepay/pending')
def admin_bluepay_pending(request:Request,db:Session=Depends(get_db)):
    admin_required(request)
    return {'success':True,**pending_order_counts(db)}

@app.get('/sub/{token}')
async def public_subscription(
    token:str,
    db:Session=Depends(get_db),
):
    customer=db.scalar(
        select(Customer).where(
            Customer.subscription_token==token
        )
    )
    if not customer or not customer.active:
        raise HTTPException(404,'Subscription not found')

    repair_error=''
    try:
        await sync_customer(
            db,
            customer,
            settings(db)['public_base_url'],
        )
        customer=db.get(Customer,customer.id)
    except Exception as exc:
        repair_error=str(exc)

    expiry=aware(customer.subscription_expire)
    if expiry and expiry<=utcnow()-timedelta(seconds=EXPIRY_CLOCK_SKEW_SECONDS):
        raise HTTPException(410,'Subscription expired')

    try:
        body,headers,errors=await combined_subscription(
            db,
            customer,
        )
    except IntegrationError as exc:
        return Response(
            content=str(exc),
            media_type='text/plain',
            status_code=503,
            headers={'Cache-Control':'no-store'},
        )

    if repair_error:
        errors.append('Repair: '+repair_error)

    if errors:
        customer.last_sync_error=(
            ' | '.join(errors)
        )[:2000]
    else:
        customer.last_sync_error=''
    db.commit()

    return Response(
        content=body,
        media_type='text/plain',
        headers=headers,
    )

# Admin
@app.get('/admin/login',response_class=HTMLResponse)
def admin_login_page(request:Request):return templates.TemplateResponse(request=request,name='login.html',context={'error':''}) if not request.session.get('admin') else RedirectResponse('/admin',302)
@app.post('/admin/login',response_class=HTMLResponse)
def admin_login(request:Request,username:str=Form(...),password:str=Form(...)):
    ip=client_ip(request)
    retry=AUTH_LIMITER.hit(
        f'admin-login:{ip}',
        int(os.getenv('ADMIN_LOGIN_RATE_LIMIT','8')),
        int(os.getenv('ADMIN_LOGIN_WINDOW_SECONDS','900')),
    )
    if retry:
        return templates.TemplateResponse(
            request=request,name='login.html',
            context={'error':f'تلاش‌های ورود بیش از حد مجاز است؛ {retry} ثانیه دیگر دوباره امتحان کنید.'},
            status_code=429,headers={'Retry-After':str(retry)},
        )
    valid=(
        secrets.compare_digest(username,os.getenv('ADMIN_USERNAME','admin'))
        and secrets.compare_digest(password,os.getenv('ADMIN_PASSWORD','CHANGE_THIS_PASSWORD'))
    )
    if not valid:return templates.TemplateResponse(request=request,name='login.html',context={'error':'نام کاربری یا رمز نادرست است'},status_code=401)
    AUTH_LIMITER.reset(f'admin-login:{ip}')
    request.session.clear();request.session['admin']=True;request.session['admin_login_at']=iso_z(utcnow());csrf_token(request)
    return RedirectResponse('/admin',303)
@app.post('/admin/logout')
def admin_logout(request:Request):request.session.clear();return RedirectResponse('/admin/login',303)
@app.get('/admin',response_class=HTMLResponse)
def admin(request:Request,db:Session=Depends(get_db)):
    if not request.session.get('admin'):
        return RedirectResponse('/admin/login',302)
    s=settings(db)
    pay=db.get(PaymentSetting,1) or PaymentSetting(id=1)
    sms=db.get(SmsSetting,1) or SmsSetting(id=1)
    panels=db.scalars(select(PasarGuardPanel).order_by(PasarGuardPanel.id.desc())).all()
    marzban_panels=db.scalars(select(MarzbanPanel).order_by(MarzbanPanel.id.desc())).all()
    guardcore_panels=db.scalars(select(GuardCorePanel).order_by(GuardCorePanel.id.desc())).all()
    plans=db.scalars(
        select(Plan)
        .options(
            selectinload(Plan.panel),
            selectinload(Plan.marzban_panel),
            selectinload(Plan.guardcore_panel),
        )
        .where(Plan.deleted.is_(False))
        .order_by(Plan.sort_order,Plan.id.desc())
    ).all()
    customers=db.scalars(select(Customer).order_by(Customer.id.desc()).limit(100)).all()
    orders=db.scalars(
        select(Order)
        .options(selectinload(Order.customer),selectinload(Order.plan))
        .order_by(Order.created_at.desc())
        .limit(100)
    ).all()
    guardcore_manual_queue=pending_manual_requests(db,100)
    blueai=blueai_admin_overview(db)
    bluepay_pending=pending_order_counts(db)
    stats={
        'customers':db.scalar(select(func.count(Customer.id))) or 0,
        'active':db.scalar(select(func.count(Customer.id)).where(Customer.subscription_status=='active')) or 0,
        'paid':db.scalar(select(func.count(Order.id)).where(Order.status.in_(['paid','activated','paid_needs_sync','partial_needs_sync']),~Order.order_code.like('MANUAL-%'))) or 0,
        'manual':db.scalar(select(func.count(Order.id)).where(Order.order_code.like('MANUAL-%'))) or 0,
        'panels':len(panels)+len(marzban_panels)+len(guardcore_panels),
        'guardcore':len(guardcore_panels),
        'guardcore_pending':len(guardcore_manual_queue),
    }
    return templates.TemplateResponse(
        request=request,
        name='admin.html',
        context={
            'settings':s,
            'payment':pay,
            'payment_api_mask':mask(decrypt(pay.api_key_enc)),
            'payment_callback_mask':mask(decrypt(pay.callback_secret_enc)),
            'sms':sms,
            'sms_api_mask':mask(decrypt(sms.api_key_enc)),
            'sms_ready':sms_setting_ready(sms),
            'panels':panels,
            'panel_masks':{x.id:mask(decrypt(x.api_key_enc) or decrypt(x.username_enc)) for x in panels},
            'marzban_panels':marzban_panels,
            'marzban_masks':{x.id:mask(decrypt(x.username_enc)) for x in marzban_panels},
            'guardcore_panels':guardcore_panels,
            'guardcore_masks':{x.id:mask(decrypt(x.api_key_enc) or decrypt(x.username_enc)) for x in guardcore_panels},
            'plans':plans,
            'customers':customers,
            'orders':orders,
            'guardcore_manual_queue':guardcore_manual_queue,
            'blueai':blueai,
            'bluepay_pending':bluepay_pending,
            'stats':stats,
            'database_mode':DATABASE_MODE,
            'database_info':database_status(),
            'database_counts':database_table_counts(),
            'saved':request.query_params.get('saved')=='1',
            'manual_message':request.query_params.get('manual',''),
            'error':request.query_params.get('error',''),
            'github_repository':github_repository(),
            'csrf_token':csrf_token(request),
            'calendar':'jalali',
            'timezone':TEHRAN_ZONE_NAME,
            'now_fa':format_jalali(utcnow(),include_seconds=True),
            'customer_identity':customer_identity,
        },
    )

@app.get('/admin/api/live')
def admin_live(request: Request, db: Session = Depends(get_db)):
    admin_required(request)
    panels = db.scalar(select(func.count(PasarGuardPanel.id))) or 0
    marzban = db.scalar(select(func.count(MarzbanPanel.id))) or 0
    guardcore = db.scalar(select(func.count(GuardCorePanel.id))) or 0
    guardcore_pending = len(pending_manual_requests(db, 100))
    stats = {
        'customers': int(db.scalar(select(func.count(Customer.id))) or 0),
        'active': int(db.scalar(select(func.count(Customer.id)).where(Customer.subscription_status == 'active')) or 0),
        'paid': int(db.scalar(select(func.count(Order.id)).where(Order.status.in_(['paid','activated','paid_needs_sync','partial_needs_sync']), ~Order.order_code.like('MANUAL-%'))) or 0),
        'manual': int(db.scalar(select(func.count(Order.id)).where(Order.order_code.like('MANUAL-%'))) or 0),
        'panels': int(panels + marzban + guardcore),
        'guardcore': int(guardcore),
        'guardcore_pending': int(guardcore_pending),
    }
    return {
        'success': True,
        'server_time': iso_z(utcnow()),
        'server_time_fa': format_jalali(utcnow(),include_seconds=True),
        'calendar':'jalali',
        'timezone':TEHRAN_ZONE_NAME,
        'stats': stats,
        'blueai': blueai_admin_overview(db),
        'bluepay_pending': pending_order_counts(db),
        'database': database_status(),
    }

@app.post('/admin/database/initialize')
def admin_database_initialize(
    request:Request,
):
    admin_required(request)
    try:
        initialize_database(force=True)
        return RedirectResponse(
            '/admin?saved=1#database',
            303,
        )
    except Exception as exc:
        return RedirectResponse(
            '/admin?error='+quote_plus(
                'راه‌اندازی دیتابیس ناموفق بود: '+str(exc)[:400]
            )+'#database',
            303,
        )


@app.post('/admin/database/backup')
def admin_database_backup(
    request:Request,
    csrf:str=Form(...),
):
    require_admin_csrf(request,csrf)
    try:
        zip_path,download_name,temp_dir=create_database_backup()
    except HTTPException:
        raise
    except Exception as exc:
        logger.exception('Database backup failed for admin IP %s',client_ip(request))
        return RedirectResponse(
            '/admin?error='+quote_plus('ساخت نسخه پشتیبان ناموفق بود: '+str(exc)[:500])+'#database',
            303,
        )
    logger.info('Database backup created for admin IP %s (%s)',client_ip(request),DATABASE_MODE)
    return FileResponse(
        path=zip_path,
        filename=download_name,
        media_type='application/zip',
        headers={
            'Cache-Control':'no-store, private',
            'Pragma':'no-cache',
            'X-Content-Type-Options':'nosniff',
        },
        background=BackgroundTask(shutil.rmtree,temp_dir,ignore_errors=True),
    )

@app.post('/admin/app-settings')
def app_settings(request:Request,app_name:str=Form(...),public_base_url:str=Form(...),support_url:str=Form(''),minimum_version:str=Form(...),announcement_id:str=Form(''),announcement_title:str=Form(''),announcement_message:str=Form(''),maintenance:str|None=Form(None),force_update:str|None=Form(None),auto_update:str|None=Form(None),announcement_enabled:str|None=Form(None),blueai_enabled:str|None=Form(None),blueai_collective:str|None=Form(None),blueai_auto_heal:str|None=Form(None),blueai_min_samples:int=Form(3),blueai_privacy_message:str=Form(''),db:Session=Depends(get_db)):
    admin_required(request)
    s=settings(db)
    s.update({
        'app_name':app_name,
        'public_base_url':public_base_url.rstrip('/'),
        'support_url':support_url,
        'minimum_version':minimum_version,
        'announcement_id':announcement_id,
        'announcement_title':announcement_title,
        'announcement_message':announcement_message,
        'maintenance':maintenance=='on',
        'force_update':force_update=='on',
        'auto_update':auto_update=='on',
        'announcement_enabled':announcement_enabled=='on',
        'blueai_enabled':blueai_enabled=='on',
        'blueai_collective':blueai_collective=='on',
        'blueai_auto_heal':blueai_auto_heal=='on',
        'blueai_min_samples':max(1,min(100,int(blueai_min_samples or 3))),
        'blueai_privacy_message':blueai_privacy_message.strip()[:500],
    })
    save_settings(db,s)
    return RedirectResponse('/admin?saved=1#app',303)
@app.post('/admin/payment-settings')
def payment_settings(request:Request,base_url:str=Form(...),api_key:str=Form(''),callback_secret:str=Form(''),fee_mode:str=Form('default'),ttl_minutes:int=Form(30),active:str|None=Form(None),db:Session=Depends(get_db)):
    admin_required(request);p=db.get(PaymentSetting,1) or PaymentSetting(id=1);p.base_url=base_url.rstrip('/');p.api_key_enc=encrypt(api_key.strip()) if api_key.strip() else p.api_key_enc;p.callback_secret_enc=encrypt(callback_secret.strip()) if callback_secret.strip() else p.callback_secret_enc;p.fee_mode=fee_mode;p.ttl_minutes=max(5,min(30,ttl_minutes));p.active=active=='on';db.add(p);db.commit();return RedirectResponse('/admin?saved=1#bluepay',303)

@app.post('/admin/sms-settings')
def sms_settings(
    request:Request,
    base_url:str=Form('https://edge.ippanel.com/v1'),
    api_key:str=Form(''),
    from_number:str=Form(''),
    pattern_code:str=Form(''),
    parameter_name:str=Form('code'),
    otp_length:int=Form(5),
    otp_ttl_seconds:int=Form(120),
    resend_seconds:int=Form(60),
    active:str|None=Form(None),
    verify_tls:str|None=Form(None),
    db:Session=Depends(get_db),
):
    admin_required(request)
    setting=db.get(SmsSetting,1) or SmsSetting(id=1)
    setting.base_url=base_url.rstrip('/') or 'https://edge.ippanel.com/v1'
    if api_key.strip():setting.api_key_enc=encrypt(api_key.strip())
    setting.from_number=from_number.strip()
    setting.pattern_code=pattern_code.strip()
    setting.parameter_name=parameter_name.strip() or 'code'
    setting.otp_length=max(4,min(8,int(otp_length or 5)))
    setting.otp_ttl_seconds=max(60,min(600,int(otp_ttl_seconds or 120)))
    setting.resend_seconds=max(30,min(600,int(resend_seconds or 60)))
    setting.active=active=='on'
    setting.verify_tls=verify_tls=='on'
    db.add(setting);db.commit()
    return RedirectResponse('/admin?saved=1#sms',303)

@app.post('/admin/sms-settings/test')
async def sms_settings_test(
    request:Request,
    test_phone:str=Form(...),
    db:Session=Depends(get_db),
):
    admin_required(request)
    setting=db.get(SmsSetting,1)
    try:
        phone=phone_ok(test_phone)
        if not sms_setting_ready(setting):
            raise SmsError('تنظیمات پیامک کامل یا فعال نیست')
        await send_pattern_otp(setting,phone,'12345')
        setting.last_test_ok=True
        setting.last_test_message=f'پیام آزمایشی برای {local_phone(phone)} ارسال شد'
        setting.last_test_at=utcnow()
        db.commit()
        return RedirectResponse('/admin?saved=1#sms',303)
    except Exception as exc:
        if setting:
            setting.last_test_ok=False
            setting.last_test_message=str(exc)[:500]
            setting.last_test_at=utcnow()
            db.commit()
        return RedirectResponse('/admin?error='+quote_plus('تست فراز اس‌ام‌اس ناموفق بود: '+str(exc)[:350])+'#sms',303)
@app.post('/admin/panels')
def add_panel(request:Request,name:str=Form(...),base_url:str=Form(...),auth_mode:str=Form('api_key'),api_key:str=Form(''),username:str=Form(''),password:str=Form(''),proxy_settings_json:str=Form('{"vless":{}}'),verify_tls:str|None=Form(None),db:Session=Depends(get_db)):
    admin_required(request)
    try:json.loads(proxy_settings_json)
    except Exception:return RedirectResponse('/admin?error=Proxy+Settings+JSON+نامعتبر+است#panels',303)
    db.add(PasarGuardPanel(name=name.strip(),base_url=base_url.rstrip('/'),auth_mode=auth_mode,api_key_enc=encrypt(api_key.strip()),username_enc=encrypt(username.strip()),password_enc=encrypt(password),proxy_settings_json=proxy_settings_json,verify_tls=verify_tls=='on'));db.commit();return RedirectResponse('/admin?saved=1#panels',303)
@app.post('/admin/panels/{panel_id}/test')
async def panel_test(request:Request,panel_id:int,db:Session=Depends(get_db)):
    admin_required(request);p=db.get(PasarGuardPanel,panel_id)
    if not p:raise HTTPException(404)
    ok,msg=await test_panel(p);p.last_test_ok=ok;p.last_test_message=msg;p.last_test_at=utcnow();db.commit();return RedirectResponse('/admin?'+('saved=1' if ok else 'error='+msg[:120])+'#panels',303)
@app.post('/admin/panels/{panel_id}/toggle')
def panel_toggle(request:Request,panel_id:int,db:Session=Depends(get_db)):
    admin_required(request);p=db.get(PasarGuardPanel,panel_id)
    if p:p.active=not p.active;db.commit()
    return RedirectResponse('/admin?saved=1#panels',303)

@app.post('/admin/marzban-panels')
def add_marzban_panel(
    request:Request,
    name:str=Form(...),
    base_url:str=Form(...),
    username:str=Form(...),
    password:str=Form(...),
    proxies_json:str=Form('{}'),
    inbounds_json:str=Form('{}'),
    verify_tls:str|None=Form(None),
    db:Session=Depends(get_db),
):
    admin_required(request)
    try:
        proxies=json.loads(proxies_json or '{}')
        inbounds=json.loads(inbounds_json or '{}')
        if not isinstance(proxies,dict) or not isinstance(inbounds,dict):
            raise ValueError()
    except Exception:
        return RedirectResponse(
            '/admin?error=JSON+تنظیمات+Marzban+نامعتبر+است#marzban',
            303,
        )

    panel=MarzbanPanel(
        name=name.strip(),
        base_url=base_url.rstrip('/'),
        username_enc=encrypt(username.strip()),
        password_enc=encrypt(password),
        proxies_json=json.dumps(proxies,ensure_ascii=False),
        inbounds_json=json.dumps(inbounds,ensure_ascii=False),
        verify_tls=verify_tls=='on',
    )
    db.add(panel)
    db.commit()
    return RedirectResponse('/admin?saved=1#marzban',303)

@app.post('/admin/marzban-panels/{panel_id}/test')
async def marzban_panel_test(
    request:Request,
    panel_id:int,
    db:Session=Depends(get_db),
):
    admin_required(request)
    panel=db.get(MarzbanPanel,panel_id)
    if not panel:
        raise HTTPException(404)

    ok,message,proxies,inbounds=await test_marzban_panel(panel)
    panel.last_test_ok=ok
    panel.last_test_message=message
    panel.last_test_at=utcnow()

    if ok and proxies and inbounds:
        panel.proxies_json=json.dumps(
            proxies,
            ensure_ascii=False,
        )
        panel.inbounds_json=json.dumps(
            inbounds,
            ensure_ascii=False,
        )

    db.commit()
    return RedirectResponse(
        '/admin?'+(
            'saved=1'
            if ok
            else 'error='+quote_plus(message[:250])
        )+'#marzban',
        303,
    )

@app.post('/admin/marzban-panels/{panel_id}/toggle')
def marzban_panel_toggle(
    request:Request,
    panel_id:int,
    db:Session=Depends(get_db),
):
    admin_required(request)
    panel=db.get(MarzbanPanel,panel_id)
    if panel:
        panel.active=not panel.active
        db.commit()
    return RedirectResponse('/admin?saved=1#marzban',303)

@app.post('/admin/guardcore-panels')
def add_guardcore_panel(
    request:Request,
    name:str=Form(...),
    base_url:str=Form(...),
    auth_mode:str=Form('manual'),
    api_key:str=Form(''),
    username:str=Form(''),
    password:str=Form(''),
    usage_unit:str=Form('bytes'),
    expire_mode:str=Form('days'),
    verify_tls:str|None=Form(None),
    db:Session=Depends(get_db),
):
    admin_required(request)
    if auth_mode not in {'manual','api_key','password'}:
        auth_mode='manual'
    if usage_unit not in {'bytes','gb'}:
        usage_unit='bytes'
    if expire_mode not in {'days','seconds','timestamp'}:
        expire_mode='days'
    panel=GuardCorePanel(
        name=name.strip(),
        base_url=base_url.rstrip('/'),
        auth_mode=auth_mode,
        api_key_enc=encrypt(api_key.strip()),
        username_enc=encrypt(username.strip()),
        password_enc=encrypt(password),
        usage_unit=usage_unit,
        expire_mode=expire_mode,
        verify_tls=verify_tls=='on',
    )
    db.add(panel)
    db.commit()
    return RedirectResponse('/admin?saved=1#guardcore',303)


@app.post('/admin/guardcore-panels/{panel_id}/test')
async def guardcore_panel_test(
    request:Request,
    panel_id:int,
    db:Session=Depends(get_db),
):
    admin_required(request)
    panel=db.get(GuardCorePanel,panel_id)
    if not panel:
        raise HTTPException(404)
    if is_manual_guardcore(panel):
        ok=True
        message='حالت دستی فعال است؛ لینک ساب از طریق ربات ثبت می‌شود'
        services=[]
    else:
        ok,message,services=await test_guardcore_panel(panel)
    panel.last_test_ok=ok
    panel.last_test_message=message
    panel.last_test_at=utcnow()
    if ok:
        panel.services_json=json.dumps(services,ensure_ascii=False)
    db.commit()
    return RedirectResponse(
        '/admin?'+('saved=1' if ok else 'error='+quote_plus(message[:300]))+'#guardcore',
        303,
    )


@app.post('/admin/guardcore-panels/{panel_id}/manual')
def guardcore_panel_manual_mode(
    request:Request,
    panel_id:int,
    db:Session=Depends(get_db),
):
    admin_required(request)
    panel=db.get(GuardCorePanel,panel_id)
    if not panel:
        raise HTTPException(404)
    panel.auth_mode='manual'
    panel.last_test_ok=True
    panel.last_test_message='حالت دستی فعال است؛ لینک ساب از طریق ربات ثبت می‌شود'
    panel.services_json='[]'
    db.commit()
    return RedirectResponse('/admin?saved=1#guardcore',303)


@app.post('/admin/guardcore-panels/{panel_id}/toggle')
def guardcore_panel_toggle(
    request:Request,
    panel_id:int,
    db:Session=Depends(get_db),
):
    admin_required(request)
    panel=db.get(GuardCorePanel,panel_id)
    if panel:
        panel.active=not panel.active
        db.commit()
    return RedirectResponse('/admin?saved=1#guardcore',303)


@app.post('/admin/plans')
def add_plan(
    request:Request,
    title:str=Form(...),
    description:str=Form(''),
    price_toman:int=Form(...),
    duration_days:int=Form(...),
    data_limit_gb:int=Form(...),
    device_limit:int=Form(...),
    panel_id:int=Form(...),
    marzban_panel_id:int=Form(0),
    guardcore_panel_id:int=Form(0),
    guardcore_service_ids:str=Form(''),
    multi_provider_quota_mode:str=Form('split'),
    group_ids:str=Form(''),
    sort_order:int=Form(0),
    db:Session=Depends(get_db),
):
    admin_required(request)
    groups=[int(x.strip()) for x in group_ids.split(',') if x.strip().isdigit()]
    services=[int(x.strip()) for x in guardcore_service_ids.split(',') if x.strip().isdigit()]
    secondary=marzban_panel_id if marzban_panel_id>0 else None
    guard=guardcore_panel_id if guardcore_panel_id>0 else None
    mode=multi_provider_quota_mode if multi_provider_quota_mode in {'split','full'} else 'split'
    guard_panel=db.get(GuardCorePanel,guard) if guard else None
    if guard and not guard_panel:
        return RedirectResponse('/admin?error=پنل+GuardCore+پیدا+نشد#plans',303)
    if guard_panel and not is_manual_guardcore(guard_panel) and not services:
        return RedirectResponse('/admin?error=برای+GuardCore+خودکار+حداقل+یک+Service+ID+لازم+است#plans',303)
    db.add(Plan(
        title=title,
        description=description,
        price_toman=max(1000,price_toman),
        duration_days=max(0,duration_days),
        data_limit_gb=max(0,data_limit_gb),
        device_limit=1 if device_limit<=1 else 2,
        panel_id=panel_id,
        marzban_panel_id=secondary,
        marzban_quota_mode=mode,
        guardcore_panel_id=guard,
        guardcore_service_ids_json=json.dumps(services),
        multi_provider_quota_mode=mode,
        group_ids_json=json.dumps(groups),
        deleted=False,
        sort_order=sort_order,
    ))
    db.commit()
    return RedirectResponse('/admin?saved=1#plans',303)


@app.post('/admin/plans/{plan_id}/panel-routing')
def plan_panel_routing(
    request:Request,
    plan_id:int,
    marzban_panel_id:int=Form(0),
    guardcore_panel_id:int=Form(0),
    guardcore_service_ids:str=Form(''),
    multi_provider_quota_mode:str=Form('split'),
    db:Session=Depends(get_db),
):
    admin_required(request)
    plan=db.get(Plan,plan_id)
    if not plan or plan.deleted:
        return RedirectResponse('/admin?error=پلن+پیدا+نشد#plans',303)
    secondary=marzban_panel_id if marzban_panel_id>0 else None
    guard=guardcore_panel_id if guardcore_panel_id>0 else None
    services=[int(x.strip()) for x in guardcore_service_ids.split(',') if x.strip().isdigit()]
    if secondary and not db.get(MarzbanPanel,secondary):
        return RedirectResponse('/admin?error=پنل+Marzban+پیدا+نشد#plans',303)
    if guard and not db.get(GuardCorePanel,guard):
        return RedirectResponse('/admin?error=پنل+GuardCore+پیدا+نشد#plans',303)
    guard_panel=db.get(GuardCorePanel,guard) if guard else None
    if guard_panel and not is_manual_guardcore(guard_panel) and not services:
        return RedirectResponse('/admin?error=برای+GuardCore+خودکار+حداقل+یک+Service+ID+لازم+است#plans',303)
    mode=multi_provider_quota_mode if multi_provider_quota_mode in {'split','full'} else 'split'
    plan.marzban_panel_id=secondary
    plan.marzban_quota_mode=mode
    plan.guardcore_panel_id=guard
    plan.guardcore_service_ids_json=json.dumps(services)
    plan.multi_provider_quota_mode=mode
    db.commit()
    return RedirectResponse('/admin?saved=1#plans',303)


@app.post('/admin/plans/{plan_id}/repair-customers')
async def repair_plan_customers(
    request:Request,
    plan_id:int,
    db:Session=Depends(get_db),
):
    admin_required(request)
    plan=db.get(Plan,plan_id)

    if not plan or plan.deleted:
        return RedirectResponse(
            '/admin?error=پلن+پیدا+نشد#plans',
            303,
        )

    if not plan.marzban_panel_id and not plan.guardcore_panel_id:
        return RedirectResponse(
            '/admin?error=ابتدا+Marzban+یا+GuardCore+را+برای+پلن+انتخاب+کنید#plans',
            303,
        )

    customers=db.scalars(
        select(Customer).where(
            Customer.plan_id==plan.id,
            Customer.active.is_(True),
        )
    ).all()

    repaired=0
    failed=0
    for customer in customers:
        try:
            await sync_customer(
                db,
                customer,
                settings(db)['public_base_url'],
            )
            marzban_ok=(
                not plan.marzban_panel_id
                or bool(customer.marzban_panel_id and customer.marzban_username)
            )
            guardcore_ok=(
                not plan.guardcore_panel_id
                or bool(customer.guardcore_panel_id and customer.guardcore_username)
            )
            if marzban_ok and guardcore_ok:
                repaired+=1
            else:
                failed+=1
        except Exception:
            failed+=1

    if failed:
        return RedirectResponse(
            '/admin?error='
            +quote_plus(
                f'{repaired} کاربر همگام شد؛ {failed} کاربر خطا داشت'
            )
            +'#plans',
            303,
        )

    return RedirectResponse(
        '/admin?manual='
        +quote_plus(
            f'{repaired} کاربر فعلی روی Providerهای جدید همگام شدند'
        )
        +'#plans',
        303,
    )


@app.post('/admin/plans/{plan_id}/toggle')
def plan_toggle(request:Request,plan_id:int,db:Session=Depends(get_db)):
    admin_required(request);x=db.get(Plan,plan_id)
    if x and not x.deleted:x.active=not x.active;db.commit()
    return RedirectResponse('/admin?saved=1#plans',303)

@app.post('/admin/plans/{plan_id}/delete')
def plan_delete(request:Request,plan_id:int,db:Session=Depends(get_db)):
    admin_required(request)
    plan=db.get(Plan,plan_id)
    if not plan:
        return RedirectResponse('/admin?error=پلن+پیدا+نشد#plans',303)
    plan.active=False
    plan.deleted=True
    plan.deleted_at=utcnow()
    db.commit()
    return RedirectResponse('/admin?saved=1#plans',303)
@app.post('/admin/customers/{customer_id}/phone')
def admin_set_customer_phone(
    request:Request,
    customer_id:int,
    phone:str=Form(...),
    db:Session=Depends(get_db),
):
    admin_required(request)
    customer=db.get(Customer,customer_id)
    if not customer:return admin_redirect('customers',error='کاربر پیدا نشد')
    normalized=phone_ok(phone)
    owner=db.scalar(select(Customer).where(Customer.phone==normalized,Customer.id!=customer.id))
    if owner:return admin_redirect('customers',error='این شماره تماس قبلاً برای حساب دیگری ثبت شده است')
    customer.phone=normalized
    customer.phone_verified_at=utcnow()
    customer.auth_method='phone_otp'
    db.commit()
    return admin_redirect('customers',message=f'شماره {local_phone(normalized)} برای حساب ثبت شد')

@app.post('/admin/manual-activation')
async def manual_activation_by_phone(
    request:Request,
    identity:str=Form(...),
    plan_id:int=Form(...),
    note:str=Form(''),
    db:Session=Depends(get_db),
):
    admin_required(request)
    try:
        customer=customer_by_identity(db,identity)
        if not customer:
            return admin_redirect(
                'manual',
                error='کاربری با این شماره تماس ثبت نشده است',
            )
        plan=db.get(Plan,plan_id)
        if not plan or plan.deleted:
            return admin_redirect('manual',error='پلن انتخاب‌شده پیدا نشد')
        order=await create_manual_activation(db,customer,plan,note)
        request_data=manual_request(order)
        guard_note=(
            '؛ درخواست GuardCore برای ربات ارسال/صف شد'
            if request_data else
            '؛ پنل دستی GuardCore فعالی پیدا نشد'
        )
        return admin_redirect(
            'manual',
            message=(
                f'اشتراک {customer_identity(customer)} با پلن «{plan.title}» '
                f'فعال یا تمدید شد؛ کد {order.order_code}{guard_note}'
            ),
        )
    except Exception as exc:
        return admin_redirect(
            'manual',
            error=f'فعال‌سازی دستی ناموفق بود: {str(exc)[:450]}',
        )

@app.post('/admin/customers/{customer_id}/manual-activate')
async def manual_activation_for_customer(
    request:Request,
    customer_id:int,
    plan_id:int=Form(...),
    note:str=Form(''),
    db:Session=Depends(get_db),
):
    admin_required(request)
    try:
        customer=db.get(Customer,customer_id)
        plan=db.get(Plan,plan_id)
        if not customer:
            return admin_redirect('customers',error='کاربر پیدا نشد')
        if not plan or plan.deleted:
            return admin_redirect('customers',error='پلن پیدا نشد')
        order=await create_manual_activation(db,customer,plan,note)
        request_data=manual_request(order)
        guard_note=(
            '؛ درخواست GuardCore برای ربات ارسال/صف شد'
            if request_data else
            '؛ پنل دستی GuardCore فعالی پیدا نشد'
        )
        return admin_redirect(
            'customers',
            message=(
                f'اشتراک {customer_identity(customer)} با پلن «{plan.title}» '
                f'فعال یا تمدید شد{guard_note}'
            ),
        )
    except Exception as exc:
        return admin_redirect(
            'customers',
            error=f'فعال‌سازی دستی ناموفق بود: {str(exc)[:450]}',
        )

@app.post('/admin/orders/{order_id}/guardcore/manual-link')
async def admin_guardcore_manual_link(
    request:Request,
    order_id:str,
    subscription_url:str=Form(...),
    db:Session=Depends(get_db),
):
    admin_required(request)
    try:
        result=await attach_manual_subscription(
            db,
            order_id,
            subscription_url,
        )
        return admin_redirect(
            'guardcore-manual',
            message=(
                'ساب دستی GuardCore برای '
                +result['customer_email']
                +' ثبت شد'
            ),
        )
    except Exception as exc:
        return admin_redirect(
            'guardcore-manual',
            error='ثبت ساب GuardCore ناموفق بود: '+str(exc)[:500],
        )


@app.post('/admin/orders/{order_id}/guardcore/skip')
def admin_guardcore_manual_skip(
    request:Request,
    order_id:str,
    db:Session=Depends(get_db),
):
    admin_required(request)
    try:
        set_manual_decision(
            db,
            order_id,
            use_guardcore=False,
        )
        return admin_redirect(
            'guardcore-manual',
            message='اختصاص دستی GuardCore برای این سفارش رد شد',
        )
    except Exception as exc:
        return admin_redirect(
            'guardcore-manual',
            error=str(exc)[:500],
        )


@app.post('/admin/orders/{order_id}/guardcore/notify')
async def admin_guardcore_manual_notify(
    request:Request,
    order_id:str,
    db:Session=Depends(get_db),
):
    admin_required(request)
    order=db.scalar(
        select(Order)
        .options(selectinload(Order.customer),selectinload(Order.plan))
        .where(Order.id==order_id)
    )
    if not order:
        return admin_redirect('guardcore-manual',error='سفارش پیدا نشد')
    try:
        metadata=json.loads(order.gateway_json or '{}')
        item=metadata.get('guardcore_manual')
        if isinstance(item,dict):
            item['state']='awaiting_decision'
            item['notified_at']=None
            item.pop('notify_error',None)
            metadata['guardcore_manual']=item
            order.gateway_json=json.dumps(metadata,ensure_ascii=False)
            db.commit()
        sent=await notify_manual_request(db,order)
        return admin_redirect(
            'guardcore-manual',
            message=(
                'پیام برای ربات ارسال شد'
                if sent else
                'پیام ارسال نشد؛ BOT_TOKEN و ADMIN_IDS را بررسی کن'
            ),
        )
    except Exception as exc:
        return admin_redirect(
            'guardcore-manual',
            error='ارسال پیام ناموفق بود: '+str(exc)[:500],
        )


@app.post('/admin/customers/{customer_id}/source-check')
async def customer_source_check(
    request:Request,
    customer_id:int,
    db:Session=Depends(get_db),
):
    admin_required(request)
    customer=db.get(Customer,customer_id)
    if not customer:
        return admin_redirect(
            'customers',
            error='کاربر پیدا نشد',
        )

    try:
        await sync_customer(
            db,
            customer,
            settings(db)['public_base_url'],
        )
        customer=db.get(Customer,customer.id)
        _,headers,errors=await combined_subscription(
            db,
            customer,
        )

        pg=headers.get(
            'X-BlueVPN-Pasarguard-Count',
            '0',
        )
        mz=headers.get(
            'X-BlueVPN-Marzban-Count',
            '0',
        )
        mz_sub=headers.get(
            'X-BlueVPN-Marzban-Sub-Raw-Count',
            '0',
        )
        mz_api=headers.get(
            'X-BlueVPN-Marzban-Api-Raw-Count',
            '0',
        )
        gc=headers.get(
            'X-BlueVPN-GuardCore-Count',
            '0',
        )
        total=headers.get(
            'X-BlueVPN-Config-Count',
            '0',
        )

        message=(
            f'منابع اشتراک — PasarGuard: {pg}، '
            f'ساب واقعی Marzban: {mz_sub}، '
            f'API Marzban: {mz_api}، '
            f'Marzban نهایی: {mz}، '
            f'GuardCore: {gc}، مجموع: {total}'
        )

        if errors:
            message+=' | '+' | '.join(errors)

        return admin_redirect(
            'customers',
            message=message[:1500],
        )

    except Exception as exc:
        return admin_redirect(
            'customers',
            error=str(exc)[:1000],
        )


@app.post('/admin/subscriptions/repair')
async def admin_subscription_repair(request:Request,db:Session=Depends(get_db)):
    admin_required(request)
    result=repair_subscription_states(db)
    _schedule_subscription_provider_repair(
        list(result.get('provider_repair_order_ids') or [])
    )
    return admin_redirect(
        'customers',
        message=(
            f"بازیابی اشتراک‌ها انجام شد؛ وضعیت {result.get('repaired',0)} حساب و "
            f"تاریخ {result.get('expiry_repaired',0)} حساب از "
            f"{result.get('scanned',0)} حساب اصلاح شد. "
            "اصلاح تاریخ پنل‌ها نیز در پس‌زمینه اجرا می‌شود."
        ),
    )

@app.post('/admin/customers/{customer_id}/sync')
async def customer_sync(request:Request,customer_id:int,db:Session=Depends(get_db)):
    admin_required(request);c=db.get(Customer,customer_id)
    if c:await sync_customer(db,c,settings(db)['public_base_url'])
    return RedirectResponse('/admin?saved=1#customers',303)
@app.post('/admin/customers/{customer_id}/reset-devices')
def reset_devices(request:Request,customer_id:int,db:Session=Depends(get_db)):
    admin_required(request)
    for d in db.scalars(select(CustomerDevice).where(CustomerDevice.customer_id==customer_id)):
        d.active=False
        d.refresh_token_hash=''
        d.refresh_expires_at=None
        d.previous_refresh_token_hash=''
        d.previous_refresh_expires_at=None
    for s in db.scalars(select(CustomerSession).where(CustomerSession.customer_id==customer_id,CustomerSession.revoked_at.is_(None))):s.revoked_at=utcnow()
    db.commit();return RedirectResponse('/admin?saved=1#customers',303)
@app.post('/admin/orders/{order_id}/activate')
async def retry_activate(request:Request,order_id:str,db:Session=Depends(get_db)):
    admin_required(request);o=db.scalar(select(Order).options(selectinload(Order.customer),selectinload(Order.plan)).where(Order.id==order_id))
    if o and o.status in {'paid','paid_needs_sync','partial_needs_sync','activated'}:await activate(db,o)
    return RedirectResponse('/admin?saved=1#orders',303)
