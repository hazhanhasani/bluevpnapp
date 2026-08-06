from __future__ import annotations
import asyncio,hashlib,ipaddress,json,logging,os,re,secrets,shutil,sqlite3,subprocess,tempfile,threading,time,uuid,zipfile
from collections import deque
from urllib.parse import quote_plus
from datetime import datetime,timedelta,timezone
from pathlib import Path
from typing import Any
from fastapi import Depends,FastAPI,Form,Header,HTTPException,Request
from fastapi.responses import FileResponse,HTMLResponse,JSONResponse,RedirectResponse,Response
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from sqlalchemy import func,select
from sqlalchemy.orm import Session,selectinload
from starlette.background import BackgroundTask
from starlette.middleware.sessions import SessionMiddleware
from .database import DATABASE_ERROR,DATABASE_MODE,ENGINE,SQLITE_PATH,SessionLocal,database_status,database_table_counts,initialize_database,get_db
from .integrations import IntegrationError,combined_subscription,create_invoice,get_invoice,iso_z,log_bluepay_error,merge_order_metadata,normalize_gateway_amount_toman,parse_remote_date,provision,recent_bluepay_errors,sync_customer,test_marzban_panel,test_panel,verify_webhook
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
from .models import AppSetting,Customer,CustomerDevice,CustomerSession,GuardCorePanel,MarzbanPanel,Order,PasarGuardPanel,PaymentSetting,Plan,WebhookDelivery,AiConnectionEvent,AiRouteAggregate,AiFeedback
from .blueai import admin_overview as blueai_admin_overview, customer_dashboard as blueai_customer_dashboard, recommendations as blueai_recommendations, submit_event as blueai_submit_event, submit_feedback as blueai_submit_feedback
from .security import decrypt,encrypt,mask,new_token,password_hash,password_ok,session_expiry,token_hash,utcnow
from .version import VERSION, VERSION_CODE
BASE=Path(__file__).resolve().parent
logger=logging.getLogger('bluevpn.main')
templates=Jinja2Templates(directory=BASE/'templates')
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
@app.on_event('startup')
def startup():
    initialize_database(); db=SessionLocal()
    try:
        if not db.get(AppSetting,1):db.add(AppSetting(id=1,payload=json.dumps(DEFAULT,ensure_ascii=False)))
        if not db.get(PaymentSetting,1):db.add(PaymentSetting(id=1))
        db.commit()
    finally:db.close()
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
LOCAL_RECOVERABLE_STATUSES = {'expired_local', 'superseded'}
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
    raw=(payment.ttl_minutes if payment else 30) or 30
    return max(5,min(1440,int(raw)))

def computed_order_expiry(
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
        ttl=max(5,min(1440,int(metadata.get('_bluevpn_invoice_ttl_minutes'))))
    except (TypeError,ValueError):
        ttl=payment_ttl_minutes(payment)
    return (aware(order.created_at) or current)+timedelta(minutes=ttl)

def ensure_order_expiry(
    order:Order,
    payment:PaymentSetting|None=None,
    *,
    now:datetime|None=None,
)->datetime:
    now=aware(now or utcnow()) or datetime.now(timezone.utc)
    existing=aware(order.expires_at)
    metadata=_order_metadata(order)
    if existing is not None:
        if not metadata.get('_bluevpn_invoice_expires_at'):
            metadata['_bluevpn_invoice_expires_at']=iso_z(existing)
            _set_order_metadata(order,metadata)
        return existing

    created=aware(order.created_at) or now
    try:
        ttl=max(5,min(1440,int(metadata.get('_bluevpn_invoice_ttl_minutes'))))
    except (TypeError,ValueError):
        ttl=payment_ttl_minutes(payment)
    expires=computed_order_expiry(order,payment,now=now)
    order.expires_at=expires
    metadata['_bluevpn_invoice_ttl_minutes']=ttl
    metadata['_bluevpn_invoice_expires_at']=iso_z(expires)
    metadata.setdefault('_bluevpn_invoice_created_at',iso_z(created))
    _set_order_metadata(order,metadata)
    return expires

def order_is_locally_expired(
    order:Order,
    payment:PaymentSetting|None=None,
    *,
    now:datetime|None=None,
)->bool:
    current=aware(now or utcnow()) or datetime.now(timezone.utc)
    return ensure_order_expiry(order,payment,now=current)<=current

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
    expired=0
    initialized=0
    for order in rows:
        had_expiry=order.expires_at is not None
        expires=ensure_order_expiry(order,payment,now=current)
        if not had_expiry:
            initialized+=1
        if expires<=current:
            _mark_order_status(
                order,
                'expired_local',
                'مهلت پرداخت این فاکتور پایان یافته است؛ در صورت پرداخت دیرهنگام، تأیید BluePay همچنان پردازش می‌شود.',
                now=current,
            )
            expired+=1
    if commit and (expired or initialized):
        db.commit()
    return {'expired':expired,'initialized':initialized,'scanned':len(rows)}

def pending_order_counts(db:Session)->dict[str,int]:
    now=aware(utcnow()) or datetime.now(timezone.utc)
    payment=db.get(PaymentSetting,1)
    rows=list(db.scalars(
        select(Order).where(
            Order.status.in_(tuple(PENDING_GATEWAY_STATUSES|LOCAL_RECOVERABLE_STATUSES))
        )
    ).all())
    active=expired=local_expired=superseded=0
    for order in rows:
        if order.status=='expired_local':
            local_expired+=1
        elif order.status=='superseded':
            superseded+=1
        elif computed_order_expiry(order,payment,now=now)<=now:
            expired+=1
        else:
            active+=1
    return {
        'active':active,
        'stale_pending':expired,
        'expired_local':local_expired,
        'superseded':superseded,
    }

async def _payment_cleanup_loop()->None:
    while True:
        await asyncio.sleep(ORDER_CLEANUP_INTERVAL_SECONDS)
        db=SessionLocal()
        try:
            result=expire_stale_orders(db)
            if result['expired']:
                logger.info(
                    'BluePay cleanup expired %s stale orders',
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
                'BluePay startup cleanup expired %s stale orders',
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

def account_json(c:Customer)->dict:
    now=aware(utcnow()) or datetime.now(timezone.utc)
    expiry=aware(c.subscription_expire)
    unlimited=(
        expiry is None
        and c.subscription_status=='active'
        and bool(c.subscription_url)
    )
    within_expiry=(
        unlimited
        or (expiry is not None and expiry>now-timedelta(seconds=EXPIRY_CLOCK_SKEW_SECONDS))
    )
    within_traffic=(
        not c.data_limit_bytes
        or c.used_traffic_bytes<c.data_limit_bytes
    )
    active=(
        c.subscription_status=='active'
        and bool(c.subscription_url)
        and within_expiry
        and within_traffic
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
    return {
        'id':c.id,
        'email':c.email,
        'active':c.active,
        'plan_id':c.plan_id,
        'server_time':iso_z(now),
        'subscription':{
            'active':active,
            'status':c.subscription_status,
            'url':c.subscription_url,
            'expire':expire_value,
            'expires_at':expire_value,
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
    expires=computed_order_expiry(order)
    now=aware(utcnow()) or datetime.now(timezone.utc)
    locally_expired=(
        order.status in (LOCAL_RECOVERABLE_STATUSES|{'expired'})
        or (order.status in PENDING_GATEWAY_STATUSES and expires<=now)
    )
    return {
        'id':order.id,
        'payment_id':order.payment_id,
        'status':order.status,
        'payment_url':order.payment_url,
        'amount_toman':order.amount_toman,
        'activation_error':order.activation_error,
        'created_at':iso_z(aware(order.created_at)),
        'expires_at':iso_z(expires),
        'expired':locally_expired,
        'replaced_by_order_id':str(metadata.get('_bluevpn_replacement_order_id') or ''),
        'paid_at':iso_z(aware(order.paid_at)),
        'activated_at':iso_z(aware(order.activated_at)),
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
            _mark_order_status(
                order,
                'expired_local',
                'مهلت پرداخت این فاکتور پایان یافته است؛ تأیید دیرهنگام BluePay همچنان پذیرفته می‌شود.',
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
    """Return the newest usable invoice and collapse older duplicates.

    The customer row is locked by the caller before this function runs, so two
    simultaneous taps cannot create two BluePay invoices for the same plan.
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

    completed: list[Order] = []
    creating: list[Order] = []
    for candidate in rows:
        if order_is_locally_expired(candidate,payment,now=now):
            _mark_order_status(
                candidate,
                'expired_local',
                'مهلت پرداخت این فاکتور پایان یافته است.',
                now=now,
            )
            continue
        if candidate.payment_id and candidate.payment_url:
            completed.append(candidate)
            continue
        age_seconds=max(0.0,(now-(aware(candidate.created_at) or now)).total_seconds())
        if candidate.status=='creating_invoice' and age_seconds<=ORDER_CREATING_GRACE_SECONDS:
            creating.append(candidate)
        elif candidate.status=='creating_invoice':
            _mark_order_status(
                candidate,
                'invoice_failed',
                'ساخت فاکتور قبلی کامل نشد و امکان تلاش مجدد فراهم شد.',
                now=now,
            )

    usable=(completed[0] if completed else creating[0] if creating else None)
    in_progress=bool(usable is not None and usable in creating)
    if usable is not None:
        for candidate in rows:
            if candidate.id==usable.id or candidate.status not in PENDING_GATEWAY_STATUSES:
                continue
            _mark_order_status(
                candidate,
                'superseded',
                'این فاکتور با فاکتور جدیدتر جایگزین شد؛ پرداخت دیرهنگام همچنان قابل بازیابی است.',
                now=now,
                replacement_order_id=usable.id,
            )
    db.flush()
    return usable,in_progress

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
    return {
        'status':'ok' if info['ready'] else 'error',
        'service':'bluevpn-platform',
        'version':VERSION,
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
            'release_build_number':int(release.get('build_number') or 0),
            'release_commit':release.get('commit',''),
            'update_source':'github_release',
            'github_repository':github_repository(),
            'github_error':github_error,
            'release_cache_seconds':15,
            'release_refresh_forced':bool(refresh),
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
        },
        headers={
            'Cache-Control':'no-store',
            'X-BlueVPN-Update-Source':'github-release',
        },
    )
@app.post('/api/v1/auth/register')
async def register(request:Request,db:Session=Depends(get_db)):
    retry=rate_limit_retry(
        request,'api-register',
        int(os.getenv('AUTH_REGISTER_RATE_LIMIT','20')),
        int(os.getenv('AUTH_REGISTER_WINDOW_SECONDS','3600')),
    )
    if retry:raise rate_limit_exception(retry)
    b=await request.json();email=email_ok(str(b.get('email','')));password=str(b.get('password',''))
    if len(password)<8:raise HTTPException(422,detail={'code':'WEAK_PASSWORD','message':'رمز عبور حداقل ۸ نویسه باشد'})
    if db.scalar(select(Customer).where(Customer.email==email)):raise HTTPException(409,detail={'code':'EMAIL_EXISTS','message':'این ایمیل قبلاً ثبت شده است'})
    c=Customer(email=email,password_hash=password_hash(password),device_limit=1);db.add(c);db.flush();token,refresh_token=issue_session(db,c,str(b.get('device_id','')),str(b.get('device_name','')));return {'success':True,'token':token,'refresh_token':refresh_token,'account':account_json(c)}
@app.post('/api/v1/auth/login')
async def login(request:Request,db:Session=Depends(get_db)):
    ip=client_ip(request)
    b=await request.json();email=email_ok(str(b.get('email','')))
    account_key=hashlib.sha256(email.encode()).hexdigest()[:20]
    window=int(os.getenv('AUTH_LOGIN_WINDOW_SECONDS','600'))
    global_retry=AUTH_LIMITER.hit(
        f'api-login-ip:{ip}',
        int(os.getenv('AUTH_LOGIN_IP_RATE_LIMIT','120')),
        window,
    )
    target_key=f'api-login:{ip}:{account_key}'
    target_retry=AUTH_LIMITER.hit(
        target_key,
        int(os.getenv('AUTH_LOGIN_RATE_LIMIT','12')),
        window,
    )
    retry=max(global_retry,target_retry)
    if retry:raise rate_limit_exception(retry)
    c=db.scalar(select(Customer).where(Customer.email==email))
    if not c or not password_ok(str(b.get('password','')),c.password_hash):raise HTTPException(401,detail={'code':'INVALID_CREDENTIALS','message':'ایمیل یا رمز نادرست است'})
    AUTH_LIMITER.reset(target_key)
    token,refresh_token=issue_session(db,c,str(b.get('device_id','')),str(b.get('device_name','')));return {'success':True,'token':token,'refresh_token':refresh_token,'account':account_json(c)}
@app.post('/api/v1/auth/refresh')
async def refresh_login(request:Request,db:Session=Depends(get_db)):
    b=await request.json();email=email_ok(str(b.get('email','')));device_id=str(b.get('device_id','')).strip()[:180];refresh_token=str(b.get('refresh_token',''))
    if not device_id or not refresh_token:raise HTTPException(401,detail={'code':'REFRESH_REQUIRED','message':'اطلاعات تمدید ورود کامل نیست'})
    c=db.scalar(select(Customer).where(Customer.email==email))
    if not c or not c.active:raise HTTPException(401,detail={'code':'ACCOUNT_DISABLED','message':'حساب در دسترس نیست'})
    device=db.scalar(select(CustomerDevice).where(CustomerDevice.customer_id==c.id,CustomerDevice.device_id==device_id))
    if not device or not device.active:raise HTTPException(401,detail={'code':'DEVICE_DISABLED','message':'این دستگاه غیرفعال شده است'})

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
        c,
        device_id,
        str(b.get('device_name','')),
        rotate_refresh=True,
    )
    return {
        'success':True,
        'token':token,
        'refresh_token':new_refresh_token,
        'account':account_json(c),
    }
@app.post('/api/v1/auth/logout')
def logout(authorization:str|None=Header(None),x_device_id:str|None=Header(None),db:Session=Depends(get_db)):
    raw=bearer(authorization);s=db.scalar(select(CustomerSession).where(CustomerSession.token_hash==token_hash(raw))) if raw else None
    if s:
        s.revoked_at=utcnow()
        device=db.scalar(select(CustomerDevice).where(CustomerDevice.customer_id==s.customer_id,CustomerDevice.device_id==(x_device_id or s.device_id)))
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
    return {'success':True,'enabled':bool(s.get('blueai_enabled',True)),'collective':bool(s.get('blueai_collective',True)),'recommendations':rows,'generated_at':iso_z(utcnow())}

@app.get('/api/v1/ai/dashboard')
async def ai_dashboard(c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    return {'success':True,'dashboard':blueai_customer_dashboard(db,c)}

@app.post('/api/v1/feedback')
async def ai_feedback(request:Request,c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    payload=await request.json()
    return {'success':True,**blueai_submit_feedback(db,c,payload)}

@app.get('/api/v1/account')
async def account(c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    c=db.get(Customer,c.id);await sync_customer(db,c,settings(db)['public_base_url']);return {'success':True,'account':account_json(c)}
@app.post('/api/v1/account/sync')
async def account_sync(c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    c=db.get(Customer,c.id);await sync_customer(db,c,settings(db)['public_base_url']);return {'success':True,'account':account_json(c)}
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

    # Serialise invoice creation per customer at the database level. This
    # prevents double invoices when the app retries or the user taps twice.
    c=db.scalar(select(Customer).where(Customer.id==c.id).with_for_update())
    if not c:
        raise HTTPException(404,detail={'code':'ACCOUNT_NOT_FOUND','message':'حساب کاربر پیدا نشد'})

    existing,in_progress=reusable_pending_order(db,c,plan,pay)
    if existing is not None:
        db.commit()
        if in_progress and not existing.payment_url:
            raise HTTPException(
                409,
                detail={
                    'code':'INVOICE_CREATION_IN_PROGRESS',
                    'message':'فاکتور قبلی هنوز در حال ساخته‌شدن است؛ چند ثانیه دیگر دوباره بررسی کنید.',
                    'order_id':existing.id,
                },
                headers={'Retry-After':'3'},
            )
        return {
            'success':True,
            'reused':True,
            'order':order_response(existing,c),
            'check_after_success_url':f'/api/v1/orders/{existing.id}/check-after-success',
            'poll_interval_seconds':5,
            'poll_timeout_seconds':30,
        }

    now=aware(utcnow()) or datetime.now(timezone.utc)
    ttl=payment_ttl_minutes(pay)
    expires=now+timedelta(minutes=ttl)
    order=Order(
        order_code=f'BV-{c.id}-{uuid.uuid4().hex[:13].upper()}',
        customer_id=c.id,
        plan_id=plan.id,
        amount_toman=int(plan.price_toman),
        status='creating_invoice',
        expires_at=expires,
        gateway_json=json.dumps(
            {
                '_bluevpn_invoice_created_at':iso_z(now),
                '_bluevpn_invoice_ttl_minutes':ttl,
                '_bluevpn_invoice_expires_at':iso_z(expires),
                '_bluevpn_source':'android',
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

    base=settings(db)['public_base_url'].rstrip('/')
    try:
        invoice=await create_invoice(pay,order,base+'/webhooks/bluepay')
    except IntegrationError as exc:
        order.status='invoice_failed'
        order.activation_error=str(exc)
        db.commit()
        raise HTTPException(502,detail={'code':'INVOICE_CREATE_FAILED','message':str(exc)})

    invoice_amount,currency=normalize_gateway_amount_toman(invoice,order.amount_toman)
    if invoice_amount is not None and invoice_amount!=order.amount_toman:
        order.status='amount_mismatch'
        order.activation_error=(
            f'مبلغ فاکتور BluePay {invoice_amount} تومان ({currency}) است، '
            f'اما مبلغ سفارش {order.amount_toman} تومان است'
        )
        log_bluepay_error(
            'create_invoice_amount_mismatch',
            order_code=order.order_code,
            payment_id=str(invoice.get('payment_id','')),
            error=order.activation_error,
            response_body=invoice,
        )
        merge_order_metadata(db,order,'bluepay_create',invoice)
        db.commit()
        raise HTTPException(502,detail={'code':'INVOICE_AMOUNT_MISMATCH','message':order.activation_error})

    order.payment_id=str(invoice.get('payment_id') or invoice.get('id') or '')
    order.payment_url=str(invoice.get('payment_url') or invoice.get('url') or '')
    order.status=normalize_gateway_status(invoice.get('status'))
    order.activation_error=''
    remote_expiry=parse_remote_date(
        invoice.get('expires_at')
        or invoice.get('expire_at')
        or invoice.get('expiration_at')
    )
    if remote_expiry is not None and remote_expiry>now:
        # Keep a sane upper bound. BluePay receives the same TTL, but a bad
        # response must not leave an invoice pending forever.
        order.expires_at=min(remote_expiry,now+timedelta(minutes=1440))
    merge_order_metadata(db,order,'bluepay_create',invoice)
    metadata=_order_metadata(order)
    metadata['_bluevpn_invoice_expires_at']=iso_z(aware(order.expires_at))
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

@app.get('/api/v1/orders/{order_id}')
async def order_status(order_id:str,c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    order=db.scalar(
        select(Order)
        .options(selectinload(Order.customer),selectinload(Order.plan))
        .where(Order.id==order_id,Order.customer_id==c.id)
    )
    if not order:raise HTTPException(404,'Order not found')
    pay=db.get(PaymentSetting,1)
    if order.payment_id and order.status in (PENDING_GATEWAY_STATUSES|LOCAL_RECOVERABLE_STATUSES):
        try:
            await refresh_order_from_bluepay(db,order)
        except Exception as exc:
            order.activation_error=str(exc)[:1000]
            if order.status in PENDING_GATEWAY_STATUSES and order_is_locally_expired(order,pay):
                _mark_order_status(
                    order,
                    'expired_local',
                    'مهلت پرداخت این فاکتور پایان یافته است؛ وضعیت پرداخت دیرهنگام بعداً هم قابل بازیابی است.',
                )
            db.commit()
    elif order.status in PENDING_GATEWAY_STATUSES and order_is_locally_expired(order,pay):
        _mark_order_status(order,'expired_local','مهلت پرداخت این فاکتور پایان یافته است.')
        db.commit()
    elif order.status in {'paid','paid_needs_sync','partial_needs_sync'}:
        await activate(db,order)
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
        if not order:raise HTTPException(404,'Order not found')
        attempts+=1
        try:
            if order.payment_id and order.status in (PENDING_GATEWAY_STATUSES|LOCAL_RECOVERABLE_STATUSES):
                await refresh_order_from_bluepay(db,order)
            elif order.status in {'paid','paid_needs_sync','partial_needs_sync'}:
                await activate(db,order)
            elif order.status in PENDING_GATEWAY_STATUSES and order_is_locally_expired(order,db.get(PaymentSetting,1)):
                _mark_order_status(order,'expired_local','مهلت پرداخت این فاکتور پایان یافته است.')
                db.commit()
        except Exception as exc:
            last_error=str(exc)[:1000]
            order.activation_error=last_error
            if order.status in PENDING_GATEWAY_STATUSES and order_is_locally_expired(order,db.get(PaymentSetting,1)):
                _mark_order_status(
                    order,
                    'expired_local',
                    'مهلت پرداخت این فاکتور پایان یافته است؛ وضعیت پرداخت دیرهنگام بعداً هم قابل بازیابی است.',
                )
            db.commit()

        db.refresh(order)
        terminal=(
            order.status in {'activated','amount_mismatch'}
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
        f"پاک‌سازی انجام شد: {result['expired']} فاکتور منقضی و "
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
    pay=db.get(PaymentSetting,1)
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
    admin_required(request);p=db.get(PaymentSetting,1) or PaymentSetting(id=1);p.base_url=base_url.rstrip('/');p.api_key_enc=encrypt(api_key.strip()) if api_key.strip() else p.api_key_enc;p.callback_secret_enc=encrypt(callback_secret.strip()) if callback_secret.strip() else p.callback_secret_enc;p.fee_mode=fee_mode;p.ttl_minutes=max(5,min(1440,ttl_minutes));p.active=active=='on';db.add(p);db.commit();return RedirectResponse('/admin?saved=1#bluepay',303)
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
@app.post('/admin/manual-activation')
async def manual_activation_by_email(
    request:Request,
    email:str=Form(...),
    plan_id:int=Form(...),
    note:str=Form(''),
    db:Session=Depends(get_db),
):
    admin_required(request)
    try:
        normalized=email_ok(email)
        customer=db.scalar(select(Customer).where(Customer.email==normalized))
        if not customer:
            return admin_redirect(
                'manual',
                error='کاربری با این ایمیل ثبت نشده است',
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
                f'اشتراک {customer.email} با پلن «{plan.title}» '
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
                f'اشتراک {customer.email} با پلن «{plan.title}» '
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
