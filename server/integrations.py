from __future__ import annotations
import hashlib,hmac,json
from datetime import datetime,timedelta,timezone
from typing import Any
import httpx
from sqlalchemy.orm import Session
from .models import Customer,Order,PasarGuardPanel,PaymentSetting,Plan
from .security import decrypt,utcnow
class IntegrationError(RuntimeError):pass

def aware(value:datetime|None)->datetime|None:
    if value is None:return None
    return value.replace(tzinfo=timezone.utc) if value.tzinfo is None else value.astimezone(timezone.utc)
def parse_remote_date(value:Any)->datetime|None:
    if not value or value==0:return None
    if isinstance(value,(int,float)):return datetime.fromtimestamp(float(value),tz=timezone.utc)
    try:
        d=datetime.fromisoformat(str(value).replace('Z','+00:00')); return aware(d)
    except Exception:return None

def panel_url(panel:PasarGuardPanel,path:str)->str:return panel.base_url.rstrip('/')+path
async def panel_headers(panel:PasarGuardPanel)->dict[str,str]:
    common={'Accept':'application/json','Content-Type':'application/json','User-Agent':'BlueVPN-Backend/1.0'}
    if panel.auth_mode=='api_key':
        key=decrypt(panel.api_key_enc)
        if not key:raise IntegrationError('کلید API پاسارگارد تنظیم نشده است')
        common['X-Api-Key']=key; return common
    username=decrypt(panel.username_enc); password=decrypt(panel.password_enc)
    if not username or not password:raise IntegrationError('نام کاربری/رمز پاسارگارد تنظیم نشده است')
    async with httpx.AsyncClient(timeout=15,verify=True) as client:
        r=await client.post(panel_url(panel,'/api/admin/token'),data={'username':username,'password':password})
    if r.status_code>=400:raise IntegrationError(f'ورود پاسارگارد ناموفق: HTTP {r.status_code} {r.text[:300]}')
    token=r.json().get('access_token') or r.json().get('token')
    if not token:raise IntegrationError('توکن پاسارگارد دریافت نشد')
    common['Authorization']=f'Bearer {token}'; return common
async def test_panel(panel:PasarGuardPanel)->tuple[bool,str]:
    try:
        async with httpx.AsyncClient(timeout=15,verify=True) as client:
            r=await client.get(panel_url(panel,'/api/users'),headers=await panel_headers(panel),params={'limit':1,'offset':0})
        return (True,'اتصال و دسترسی کاربران موفق بود') if r.status_code==200 else (False,f'HTTP {r.status_code}: {r.text[:350]}')
    except Exception as exc:return False,str(exc)
def pg_username(customer:Customer)->str:
    if customer.pg_username:return customer.pg_username
    return f"bv_{customer.id}_{hashlib.sha1(customer.email.encode()).hexdigest()[:9]}"[:32]
def plan_groups(plan:Plan)->list[int]:
    try:return [int(x) for x in json.loads(plan.group_ids_json or '[]')]
    except Exception:return []
def proxy_settings(panel:PasarGuardPanel)->dict:
    try:
        x=json.loads(panel.proxy_settings_json or '{}'); return x if isinstance(x,dict) else {'vless':{}}
    except Exception:return {'vless':{}}
async def get_pg_user(panel:PasarGuardPanel,username:str)->dict|None:
    async with httpx.AsyncClient(timeout=20,verify=True) as client:
        r=await client.get(panel_url(panel,f'/api/user/by-username/{username}'),headers=await panel_headers(panel))
    if r.status_code==404:return None
    if r.status_code>=400:raise IntegrationError(f'خواندن کاربر پاسارگارد ناموفق: HTTP {r.status_code} {r.text[:500]}')
    return r.json()
async def provision(db:Session,customer:Customer,plan:Plan,order:Order)->Customer:
    panel=db.get(PasarGuardPanel,plan.panel_id)
    if not panel or not panel.active:raise IntegrationError('پنل پاسارگارد این پلن فعال نیست')
    username=pg_username(customer); remote=await get_pg_user(panel,username); now=utcnow()
    old_expire=parse_remote_date(remote.get('expire') if remote else None)
    start=old_expire if old_expire and old_expire>now else now
    new_expire=None if plan.duration_days==0 else start+timedelta(days=plan.duration_days)
    limit=0 if plan.data_limit_gb==0 else plan.data_limit_gb*1024*1024*1024
    payload={'status':'active','expire':0 if new_expire is None else new_expire.isoformat(),'data_limit':limit,'data_limit_reset_strategy':'no_reset','group_ids':plan_groups(plan),'hwid_limit':1 if plan.device_limit<=1 else 2,'note':f'BlueVPN {customer.email}; {order.order_code}'}
    if remote is None:
        payload.update({'username':username,'proxy_settings':proxy_settings(panel)}); method='POST'; url=panel_url(panel,'/api/user')
    else: method='PUT'; url=panel_url(panel,f'/api/user/by-username/{username}')
    async with httpx.AsyncClient(timeout=30,verify=True) as client:r=await client.request(method,url,headers=await panel_headers(panel),json=payload)
    if r.status_code>=400:raise IntegrationError(f'فعال‌سازی پاسارگارد ناموفق: HTTP {r.status_code} {r.text[:800]}')
    data=r.json(); customer.plan_id=plan.id; customer.panel_id=panel.id; customer.pg_username=username; customer.pg_user_id=data.get('id'); customer.subscription_url=data.get('subscription_url') or customer.subscription_url; customer.subscription_status=str(data.get('status') or 'active'); customer.subscription_expire=parse_remote_date(data.get('expire')) or new_expire; customer.data_limit_bytes=int(data.get('data_limit') or limit or 0); customer.used_traffic_bytes=int(data.get('used_traffic') or data.get('used_traffic_bytes') or 0); customer.device_limit=1 if plan.device_limit<=1 else 2; customer.last_sync_at=utcnow(); customer.last_sync_error=''; order.status='activated'; order.activation_error=''; order.activated_at=utcnow(); db.commit(); return customer
async def sync_customer(db:Session,customer:Customer)->Customer:
    if not customer.panel_id or not customer.pg_username:
        customer.subscription_status='inactive'; customer.last_sync_at=utcnow(); db.commit(); return customer
    panel=db.get(PasarGuardPanel,customer.panel_id)
    if not panel:customer.last_sync_error='پنل پاسارگارد حذف شده است'; customer.last_sync_at=utcnow(); db.commit(); return customer
    try:
        data=await get_pg_user(panel,customer.pg_username)
        if not data:customer.subscription_status='inactive'; customer.last_sync_error='کاربر در پاسارگارد پیدا نشد'
        else:
            customer.pg_user_id=data.get('id'); customer.subscription_url=data.get('subscription_url') or customer.subscription_url; customer.subscription_status=str(data.get('status') or 'inactive'); customer.subscription_expire=parse_remote_date(data.get('expire')); customer.data_limit_bytes=int(data.get('data_limit') or 0); customer.used_traffic_bytes=int(data.get('used_traffic') or data.get('used_traffic_bytes') or 0); customer.device_limit=max(1,min(2,int(data.get('hwid_limit') or customer.device_limit or 1))); customer.last_sync_error=''
    except Exception as exc:customer.last_sync_error=str(exc)[:1000]
    customer.last_sync_at=utcnow(); db.commit(); return customer

def payment_secret(setting:PaymentSetting)->tuple[str,str,str]:return setting.base_url.rstrip('/'),decrypt(setting.api_key_enc),decrypt(setting.callback_secret_enc)
async def create_invoice(setting:PaymentSetting,order:Order,callback_url:str)->dict:
    base,key,_=payment_secret(setting)
    if not setting.active or not key:raise IntegrationError('درگاه BluePay فعال یا کامل نیست')
    payload={'amount_toman':order.amount_toman,'order_id':order.order_code,'description':f'خرید {order.plan.title} برای {order.customer.email}','fee_mode':setting.fee_mode,'ttl_minutes':max(5,min(1440,setting.ttl_minutes)),'callback_url':callback_url}
    async with httpx.AsyncClient(timeout=25) as client:r=await client.post(base+'/api/v1/invoices',headers={'X-API-Key':key,'Idempotency-Key':f'{order.order_code}-create','Accept':'application/json','Content-Type':'application/json'},json=payload)
    if r.status_code>=400:raise IntegrationError(f'ساخت فاکتور BluePay ناموفق: HTTP {r.status_code} {r.text[:800]}')
    return r.json()
async def get_invoice(setting:PaymentSetting,payment_id:str)->dict:
    base,key,_=payment_secret(setting)
    if not key:raise IntegrationError('کلید BluePay تنظیم نشده است')
    async with httpx.AsyncClient(timeout=20) as client:r=await client.get(base+f'/api/v1/invoices/{payment_id}',headers={'X-API-Key':key,'Accept':'application/json'})
    if r.status_code>=400:raise IntegrationError(f'استعلام BluePay ناموفق: HTTP {r.status_code}')
    return r.json()
def verify_webhook(raw:bytes,signature:str,secret:str)->tuple[bool,dict]:
    try:payload=json.loads(raw)
    except Exception:return False,{}
    # BluePay docs sign the raw request body. Also accept canonical JSON for gateways that reserialize.
    raw_expected=hmac.new(secret.encode(),raw,hashlib.sha256).hexdigest()
    canonical=json.dumps(payload,ensure_ascii=False,separators=(',',':'),sort_keys=True).encode()
    canonical_expected=hmac.new(secret.encode(),canonical,hashlib.sha256).hexdigest()
    return hmac.compare_digest(raw_expected,signature) or hmac.compare_digest(canonical_expected,signature),payload
