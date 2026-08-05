from __future__ import annotations
import json,os,re,secrets,uuid
from urllib.parse import quote_plus
from datetime import datetime,timedelta,timezone
from pathlib import Path
from typing import Any
from fastapi import Depends,FastAPI,Form,Header,HTTPException,Request
from fastapi.responses import HTMLResponse,JSONResponse,RedirectResponse,Response
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from sqlalchemy import func,select
from sqlalchemy.orm import Session,selectinload
from starlette.middleware.sessions import SessionMiddleware
from .database import DATABASE_ERROR,DATABASE_MODE,SessionLocal,database_status,database_table_counts,initialize_database,get_db
from .integrations import IntegrationError,combined_subscription,create_invoice,get_invoice,provision,sync_customer,test_marzban_panel,test_panel,verify_webhook
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
BASE=Path(__file__).resolve().parent; templates=Jinja2Templates(directory=BASE/'templates')
DEFAULT={'app_name':'BlueVPN','public_base_url':os.getenv('PUBLIC_BASE_URL','https://bluevpnapp-production.up.railway.app'),'maintenance':False,'support_url':os.getenv('SUPPORT_URL',''),'minimum_version':'0.4.9','force_update':False,'auto_update':True,'announcement_enabled':True,'announcement_id':'platform-100','announcement_title':'حساب یکپارچه BlueVPN','announcement_message':'خرید، تمدید و اشتراک شما به‌صورت خودکار مدیریت می‌شود.','blueai_enabled':True,'blueai_collective':True,'blueai_auto_heal':True,'blueai_min_samples':3,'blueai_privacy_message':'فقط شاخص‌های فنی اتصال و بدون محتوای ترافیک جمع‌آوری می‌شود.','updated_at':utcnow().isoformat()}
app=FastAPI(title='BlueVPN Ultimate AI Platform',version='3.0.0'); app.add_middleware(SessionMiddleware,secret_key=os.getenv('SESSION_SECRET') or secrets.token_urlsafe(48),same_site='lax',https_only=False); app.mount('/static',StaticFiles(directory=BASE/'static'),name='static')
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
    data['updated_at']=utcnow().isoformat();row=db.get(AppSetting,1) or AppSetting(id=1,payload='{}');row.payload=json.dumps(data,ensure_ascii=False);row.updated_at=utcnow();db.add(row);db.commit()
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
def account_json(c:Customer)->dict:
    expiry=aware(c.subscription_expire); active=c.subscription_status=='active' and bool(c.subscription_url) and (not expiry or expiry>utcnow()) and (not c.data_limit_bytes or c.used_traffic_bytes<c.data_limit_bytes)
    return {'id':c.id,'email':c.email,'active':c.active,'plan_id':c.plan_id,'subscription':{'active':active,'status':c.subscription_status,'url':c.subscription_url,'expire':expiry.isoformat() if expiry else None,'data_limit_bytes':c.data_limit_bytes,'used_traffic_bytes':c.used_traffic_bytes,'remaining_bytes':max(0,c.data_limit_bytes-c.used_traffic_bytes) if c.data_limit_bytes else 0,'device_limit':c.device_limit,'last_sync_at':aware(c.last_sync_at).isoformat() if c.last_sync_at else None,'sync_error':c.last_sync_error}}
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
    if order.status=='activated':
        try:
            ensure_manual_request_for_order(db,order)
            await notify_manual_request(db,order)
        except Exception:
            pass
        return
    order.status='paid';order.paid_at=order.paid_at or utcnow();db.commit()
    try:
        await provision(
            db,
            order.customer,
            order.plan,
            order,
            settings(db)['public_base_url'],
        )
        # Manual GuardCore is optional. Even when the selected plan has no
        # GuardCore field, an active manual panel becomes the fallback and
        # the admin receives a Yes/No request after automatic activation.
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
                'created_at':utcnow().isoformat(),
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
        'version':'3.0.0',
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
    b=await request.json();email=email_ok(str(b.get('email','')));password=str(b.get('password',''))
    if len(password)<8:raise HTTPException(422,detail={'code':'WEAK_PASSWORD','message':'رمز عبور حداقل ۸ نویسه باشد'})
    if db.scalar(select(Customer).where(Customer.email==email)):raise HTTPException(409,detail={'code':'EMAIL_EXISTS','message':'این ایمیل قبلاً ثبت شده است'})
    c=Customer(email=email,password_hash=password_hash(password),device_limit=1);db.add(c);db.flush();token,refresh_token=issue_session(db,c,str(b.get('device_id','')),str(b.get('device_name','')));return {'success':True,'token':token,'refresh_token':refresh_token,'account':account_json(c)}
@app.post('/api/v1/auth/login')
async def login(request:Request,db:Session=Depends(get_db)):
    b=await request.json();email=email_ok(str(b.get('email','')));c=db.scalar(select(Customer).where(Customer.email==email))
    if not c or not password_ok(str(b.get('password','')),c.password_hash):raise HTTPException(401,detail={'code':'INVALID_CREDENTIALS','message':'ایمیل یا رمز نادرست است'})
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
    return {'success':True,'enabled':bool(s.get('blueai_enabled',True)),'collective':bool(s.get('blueai_collective',True)),'recommendations':rows,'generated_at':utcnow().isoformat()}

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
    b=await request.json();plan=db.get(Plan,int(b.get('plan_id',0)))
    if not plan or not plan.active or plan.deleted:raise HTTPException(404,detail={'code':'PLAN_NOT_FOUND','message':'پلن پیدا نشد'})
    c=db.get(Customer,c.id);order=Order(order_code=f'BV-{c.id}-{uuid.uuid4().hex[:13].upper()}',customer_id=c.id,plan_id=plan.id,amount_toman=plan.price_toman,status='creating_invoice');db.add(order);db.commit();order=db.scalar(select(Order).options(selectinload(Order.customer),selectinload(Order.plan)).where(Order.id==order.id));pay=db.get(PaymentSetting,1);base=settings(db)['public_base_url'].rstrip('/')
    try:invoice=await create_invoice(pay,order,base+'/webhooks/bluepay')
    except IntegrationError as exc:order.status='invoice_failed';order.activation_error=str(exc);db.commit();raise HTTPException(502,detail={'code':'INVOICE_CREATE_FAILED','message':str(exc)})
    order.payment_id=str(invoice.get('payment_id',''));order.payment_url=str(invoice.get('payment_url',''));order.status=str(invoice.get('status','pending'));order.gateway_json=json.dumps(invoice,ensure_ascii=False);db.commit();return {'success':True,'order':{'id':order.id,'payment_id':order.payment_id,'status':order.status,'payment_url':order.payment_url,'amount_toman':order.amount_toman}}
@app.get('/api/v1/orders/{order_id}')
async def order_status(order_id:str,c:Customer=Depends(current_customer),db:Session=Depends(get_db)):
    order=db.scalar(select(Order).options(selectinload(Order.customer),selectinload(Order.plan)).where(Order.id==order_id,Order.customer_id==c.id))
    if not order:raise HTTPException(404,'Order not found')
    if order.payment_id and order.status in {'pending','created','creating_invoice'}:
        try:
            remote=await get_invoice(db.get(PaymentSetting,1),order.payment_id);order.gateway_json=json.dumps(remote,ensure_ascii=False);order.status=str(remote.get('status',order.status));db.commit()
            if order.status=='paid':await activate(db,order)
        except Exception as exc:order.activation_error=str(exc)[:1000];db.commit()
    c=db.get(Customer,c.id);return {'success':True,'order':{'id':order.id,'payment_id':order.payment_id,'status':order.status,'payment_url':order.payment_url,'activation_error':order.activation_error,'account':account_json(c)}}
@app.post('/webhooks/bluepay')
async def bluepay_webhook(request:Request,x_gateway_signature:str|None=Header(None),x_gateway_delivery:str|None=Header(None),x_gateway_event:str|None=Header(None),db:Session=Depends(get_db)):
    pay=db.get(PaymentSetting,1);secret=decrypt(pay.callback_secret_enc) if pay else '';raw=await request.body();valid,payload=verify_webhook(raw,x_gateway_signature or '',secret)
    if not secret or not valid:return JSONResponse({'success':False},status_code=401)
    delivery=x_gateway_delivery or f"payment:{payload.get('payment_id','')}"
    if db.scalar(select(WebhookDelivery).where(WebhookDelivery.delivery_id==delivery)):return {'success':True,'duplicate':True}
    db.add(WebhookDelivery(delivery_id=delivery,payment_id=str(payload.get('payment_id','')),event=x_gateway_event or str(payload.get('event',''))));order=db.scalar(select(Order).options(selectinload(Order.customer),selectinload(Order.plan)).where(Order.payment_id==str(payload.get('payment_id',''))))
    if order:order.gateway_json=json.dumps(payload,ensure_ascii=False);order.status=str(payload.get('status',order.status));order.paid_at=utcnow() if order.status=='paid' else order.paid_at;db.commit();await activate(db,order) if order.status=='paid' else None
    else:db.commit()
    return {'success':True}

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
    if expiry and expiry<=utcnow():
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
    if not (secrets.compare_digest(username,os.getenv('ADMIN_USERNAME','admin')) and secrets.compare_digest(password,os.getenv('ADMIN_PASSWORD','CHANGE_THIS_PASSWORD'))):return templates.TemplateResponse(request=request,name='login.html',context={'error':'نام کاربری یا رمز نادرست است'},status_code=401)
    request.session['admin']=True;return RedirectResponse('/admin',303)
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
            'stats':stats,
            'database_mode':DATABASE_MODE,
            'database_info':database_status(),
            'database_counts':database_table_counts(),
            'saved':request.query_params.get('saved')=='1',
            'manual_message':request.query_params.get('manual',''),
            'error':request.query_params.get('error',''),
            'github_repository':github_repository(),
        },
    )
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
