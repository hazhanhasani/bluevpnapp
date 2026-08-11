(function(){
'use strict';
document.documentElement.classList.add('bluevpn-standalone-html');
const q=(s)=>document.querySelector(s);
const sidebar=q('#bluevpnSidebar'), overlay=q('#bluevpnSidebarOverlay');
function setOpen(v){
  if(!sidebar)return;
  sidebar.classList.toggle('is-open',v);
  overlay?.classList.toggle('is-open',v);
  document.documentElement.classList.toggle('bluevpn-menu-open',v);
}
q('#bluevpnMenuToggle')?.addEventListener('click',()=>setOpen(true));
q('#bluevpnSidebarClose')?.addEventListener('click',()=>setOpen(false));
overlay?.addEventListener('click',()=>setOpen(false));
document.addEventListener('keydown',e=>{if(e.key==='Escape')setOpen(false)});
document.querySelectorAll('.bluevpn-nav-item').forEach(a=>a.addEventListener('click',()=>setOpen(false)));
const clock=q('#bluevpnLiveClock');
if(clock){
  const tick=()=>{try{clock.textContent=new Intl.DateTimeFormat('fa-IR-u-ca-persian',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Tehran'}).format(new Date())}catch(_){}};
  tick(); setInterval(tick,30000);
}
// Better file name feedback for ad image uploads.
document.querySelectorAll('.bluevpn-file-input input[type=file]').forEach(input=>{
  input.addEventListener('change',()=>{
    const label=input.closest('.bluevpn-file-input')?.querySelector('[data-file-name]');
    if(label) label.textContent=input.files?.[0]?.name || 'هیچ فایلی انتخاب نشده';
  });
});
})();
