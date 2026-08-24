(function(){
'use strict';
const bvMon=window.BlueVPNAdmin||{},bvMonSeen=new Map();
function bvAdminReport(kind,message,file='',line=0,column=0,stack=''){
  if(!bvMon.monitorEndpoint||!bvMon.monitorToken)return;const key=[kind,message,file,line,column].join('|'),now=Date.now();
  if(now-(bvMonSeen.get(key)||0)<120000)return;bvMonSeen.set(key,now);
  fetch(bvMon.monitorEndpoint,{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/json'},body:JSON.stringify({token:bvMon.monitorToken,kind,message:String(message||'Admin JavaScript error').slice(0,1200),file:String(file||'').slice(0,900),line:Number(line||0),column:Number(column||0),stack:String(stack||'').slice(0,1800),page:location.href.split('#')[0]})}).catch(()=>{});
}
window.addEventListener('error',e=>bvAdminReport('js_error',e.message,e.filename,e.lineno,e.colno,e.error?.stack||''));
function bvIsBenignBrowserCancellation(reason){
  const name=String(reason?.name||'').toLowerCase(),message=String(reason?.message||reason||'').trim().toLowerCase(),stack=String(reason?.stack||'').trim();
  if(name==='aborterror')return true;
  if(stack)return false;
  return message==='transition was aborted because of invalid state' ||
    /^(view |navigation )?transition (was )?aborted( because of invalid state)?$/.test(message);
}
window.addEventListener('unhandledrejection',e=>{const r=e.reason;if(bvIsBenignBrowserCancellation(r)){e.preventDefault();return}bvAdminReport('unhandledrejection',r?.message||String(r||'Unhandled promise rejection'),'',0,0,r?.stack||'')});
document.documentElement.classList.add('bluevpn-standalone-html');
const q=(s)=>document.querySelector(s);
const sidebar=q('#bluevpnSidebar'), overlay=q('#bluevpnSidebarOverlay');
function setOpen(v){
  if(!sidebar)return;
  sidebar.classList.toggle('is-open',v);
  overlay?.classList.toggle('is-open',v);
  document.documentElement.classList.toggle('bluevpn-menu-open',v);
}
// Never restore a stale open drawer from browser back/forward cache on mobile.
if(window.matchMedia?.('(max-width: 782px)').matches)setOpen(false);
window.addEventListener('pageshow',()=>{if(window.matchMedia?.('(max-width: 782px)').matches)setOpen(false)});
window.addEventListener('resize',()=>{if(window.innerWidth>782)setOpen(false)});
q('#bluevpnMenuToggle')?.addEventListener('click',()=>setOpen(true));
q('#bluevpnSidebarClose')?.addEventListener('click',()=>setOpen(false));
overlay?.addEventListener('click',()=>setOpen(false));
document.addEventListener('keydown',e=>{if(e.key==='Escape')setOpen(false)});
document.querySelectorAll('.bluevpn-nav-item').forEach(a=>a.addEventListener('click',()=>setOpen(false)));
const clock=q('#bluevpnLiveClock');
if(clock){
  const tick=()=>{try{clock.textContent=new Intl.DateTimeFormat('fa-IR-u-ca-persian',{calendar:'persian',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false,timeZone:'Asia/Tehran'}).format(new Date())}catch(_){}};
  tick(); setInterval(tick,1000);
}
// Convert data tables to scroll-safe desktop tables + labeled mobile cards without changing PHP markup.
function wrapTable(table){
  if(!table || table.closest('.bvc-table-scroll')) return;
  const wrap=document.createElement('div');
  wrap.className='bvc-table-scroll';
  table.parentNode?.insertBefore(wrap,table);
  wrap.appendChild(table);
}
function tableHeaderLabels(table){
  const headerRow=table.querySelector('thead tr:last-child') || Array.from(table.querySelectorAll('tr')).find(row=>row.querySelectorAll('th').length>0);
  if(!headerRow) return [];
  return Array.from(headerRow.querySelectorAll('th')).map(th=>(th.textContent||'').replace(/\s+/g,' ').trim());
}
function enhanceResponsiveTable(table){
  if(!table) return;
  wrapTable(table);
  const labels=tableHeaderLabels(table);
  if(labels.length<1) return;
  table.classList.add('bvc-responsive-table');
  // Never force a global pixel width: compact tables stay fluid, while only
  // genuinely wide datasets get a scroll-safe max-content table on desktop.
  table.style.removeProperty('min-width');
  table.style.removeProperty('width');
  table.classList.toggle('bvc-table-wide',labels.length>=7);
  Array.from(table.querySelectorAll('tr')).forEach(row=>{
    if(row.querySelectorAll('th').length) return;
    Array.from(row.children).forEach((cell,index)=>{
      if(cell.tagName==='TD' && labels[index] && !cell.dataset.label) cell.dataset.label=labels[index];
      if(cell.tagName==='TD') cell.style.unicodeBidi='plaintext';
    });
  });
}
document.querySelectorAll('table.bvc-table, table.bvp-table, table.widefat, table.wp-list-table').forEach(enhanceResponsiveTable);
document.querySelectorAll('.tablenav, .tablenav .actions, .search-box, .subsubsub').forEach(el=>el.classList.add('bluevpn-toolbar-ready'));

// Better file name feedback for ad image uploads.
document.querySelectorAll('.bluevpn-file-input input[type=file]').forEach(input=>{
  input.addEventListener('change',()=>{
    const label=input.closest('.bluevpn-file-input')?.querySelector('[data-file-name]');
    if(label) label.textContent=input.files?.[0]?.name || 'هیچ فایلی انتخاب نشده';
  });
});
})();

// Live PasarGuard group / Marzban inbound selector for plan routing.
document.querySelectorAll('[data-bluevpn-access-picker]').forEach(picker=>{
  const form=picker.closest('form');
  const provider=picker.dataset.provider||'';
  const panelSelect=form?.querySelector(`select[name="${picker.dataset.panelSelect||''}"]`);
  const loadBtn=picker.querySelector('[data-access-load]');
  const status=picker.querySelector('[data-access-status]');
  const itemsBox=picker.querySelector('[data-access-items]');
  let saved=[];
  try{saved=JSON.parse(picker.dataset.selected||'[]')||[]}catch(_){saved=[]}
  const fieldName=provider==='pasarguard'?'group_ids_selected[]':'marzban_inbounds_selected[]';
  const selectedValues=()=>Array.from(itemsBox?.querySelectorAll('input[type=checkbox]:checked')||[]).map(x=>x.value);
  const render=(items)=>{
    if(!itemsBox)return;
    const chosen=new Set(selectedValues().concat(saved));
    itemsBox.innerHTML='';
    if(!items.length){itemsBox.innerHTML='<div class="bvc-access-empty">مورد فعالی پیدا نشد.</div>';return;}
    items.forEach(item=>{
      const label=document.createElement('label');label.className='bvc-access-chip';
      const input=document.createElement('input');input.type='checkbox';input.name=fieldName;input.value=String(item.value||'');input.checked=chosen.has(input.value);
      const span=document.createElement('span');span.textContent=String(item.label||item.value||'');
      const small=document.createElement('small');small.textContent=String(item.meta||'');
      label.append(input,span,small);itemsBox.append(label);
    });
    status.textContent=`${items.length} مورد فعال دریافت شد؛ ${itemsBox.querySelectorAll('input:checked').length} مورد انتخاب شده.`;
  };
  const load=async()=>{
    const panelId=Number(panelSelect?.value||0);
    if(!panelId){status.textContent='ابتدا پنل را انتخاب کن.';itemsBox.innerHTML='';return;}
    if(!window.BlueVPNAdmin?.ajaxUrl){status.textContent='تنظیمات AJAX در دسترس نیست.';return;}
    loadBtn.disabled=true;status.textContent='در حال دریافت لیست زنده از پنل…';
    try{
      const body=new URLSearchParams({action:'bluevpn_cc_provider_access_catalog',nonce:BlueVPNAdmin.providerCatalogNonce||'',provider,panel_id:String(panelId)});
      const res=await fetch(BlueVPNAdmin.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});
      const json=await res.json();if(!json?.success)throw new Error(json?.data?.message||'دریافت لیست ناموفق بود.');
      render(Array.isArray(json.data?.items)?json.data.items:[]);
    }catch(err){status.textContent=`خطا: ${err?.message||'دریافت لیست ناموفق بود.'}`;}
    finally{loadBtn.disabled=false;}
  };
  loadBtn?.addEventListener('click',load);
  panelSelect?.addEventListener('change',()=>{saved=[];itemsBox.innerHTML='';status.textContent=panelSelect.value?'پنل تغییر کرد؛ برای دریافت گروه‌ها/Inboundها روی «دریافت لیست» بزن.':'حالت خودکار بدون پنل مشخص.';});
});
