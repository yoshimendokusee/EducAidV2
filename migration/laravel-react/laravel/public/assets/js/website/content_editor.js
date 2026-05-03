/* Shared inline content editor (landing & about)
 * Usage: ContentEditor.init({ page:'landing'|'about', saveEndpoint, resetAllEndpoint?, history:{fetchEndpoint,rollbackEndpoint?}, refreshAfterSave?(keys) })
 */
(function(global){
  const CE={};
  const qs=(s,r=document)=>r.querySelector(s);
  const qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const rgbToHex=rgb=>{ if(!rgb) return null; const m=rgb.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i); if(!m) return null; return '#'+[m[1],m[2],m[3]].map(v=>('0'+parseInt(v,10).toString(16)).slice(-2)).join(''); };
  const esc=s=>(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
  const humanize=s=>{
    if(!s) return '';
    return String(s).replace(/[-_]+/g,' ').replace(/\b\w/g,ch=>ch.toUpperCase());
  };
  
  // CSRF token helper function
  const getCSRFToken=()=>{
    const meta=document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  };
  
  // Enhanced fetch with CSRF token
  const fetchWithCSRF=(url,options={})=>{
    const token=getCSRFToken();
    const headers=options.headers || {};
    headers['X-CSRF-Token']=token;
    
    // If body is JSON, also include token in POST data
    if(options.body && typeof options.body === 'string'){
      try{
        const bodyData=JSON.parse(options.body);
        bodyData.csrf_token=token;
        options.body=JSON.stringify(bodyData);
      }catch(e){
        // If not valid JSON, skip
      }
    }
    
    return fetch(url,{...options,headers});
  };

  CE.init=function(cfg){
    cfg=Object.assign({page:'generic'},cfg||{});
  const tb=qs('#lp-edit-toolbar');
    if(!tb) return; // toolbar not present -> not in edit mode
    document.body.classList.add('lp-editing');
    const els=qsa('[data-lp-key]');
    els.forEach(el=>{
      el.classList.add('lp-edit-highlight');
      const noText = el.classList.contains('lp-no-text-edit');
      if (!noText) {
        el.setAttribute('contenteditable', 'true');
      } else {
        el.setAttribute('contenteditable', 'false');
      }
      el.setAttribute('spellcheck', 'false');
    });

  const state={target:null,saving:false,original:new Map()};
  const pageLabel = cfg.pageTitle || humanize(cfg.page) || 'Page';
  const historyHeading = cfg.historyTitle || (pageLabel + ' History');
    const txt=qs('#lp-edit-text'), label=qs('#lp-current-target'), tc=qs('#lp-text-color'), bc=qs('#lp-bg-color'), saveBtn=qs('#lp-save-btn'), saveAllBtn=qs('#lp-save-all-btn'), resetBtn=qs('#lp-reset-btn'), resetAllBtn=qs('#lp-reset-all'), hiBtn=qs('#lp-highlight-toggle'), status=qs('#lp-status'), histBtn=qs('#lp-history-btn'), changesCount=qs('#lp-changes-count');

    const updateChangesCount=()=>{ const count=qsa('[data-lp-dirty="1"]').length; if(changesCount) changesCount.textContent=count+' change'+(count!==1?'s':''); };
    const setStatus=(msg,type='muted')=>{ if(status){ status.textContent=msg; status.className='text-'+(type==='error'?'danger': type==='success'?'success':'muted'); }};
    const select=el=>{ state.target=el; label.textContent=el.dataset.lpKey; txt.value=el.innerText.trim(); const cs=getComputedStyle(el); tc.value=rgbToHex(cs.color)||'#000000'; bc.value=rgbToHex(cs.backgroundColor)||'#ffffff'; };
    const dirty=el=>{ el.dataset.lpDirty='1'; if(saveAllBtn) saveAllBtn.disabled=false; if(saveBtn) saveBtn.disabled=false; updateChangesCount(); };

    els.forEach(el=>{
      if(!state.original.has(el.dataset.lpKey)) state.original.set(el.dataset.lpKey,el.innerHTML);
      el.addEventListener('click',e=>{ if(!tb.contains(e.target)){ e.preventDefault(); e.stopPropagation(); select(el);} });
      el.addEventListener('input',()=>{
        dirty(el);
        if(state.target===el && txt){ txt.value=el.innerText.trim(); }
      });
      el.addEventListener('blur',()=>{
        if(state.target===el && txt){ txt.value=el.innerText.trim(); }
      });
    });
    if(txt) txt.addEventListener('input',()=>{ if(!state.target) return; state.target.innerText=txt.value; dirty(state.target); });
    if(tc) tc.addEventListener('input',()=>{ if(state.target){ state.target.style.color=tc.value; dirty(state.target);} });
    if(bc) bc.addEventListener('input',()=>{ if(state.target){ state.target.style.backgroundColor=bc.value; dirty(state.target);} });
    if(resetBtn) resetBtn.addEventListener('click',()=>{ if(!state.target) return; const k=state.target.dataset.lpKey; const orig=state.original.get(k); if(orig!==undefined){ state.target.innerHTML=orig; txt.value=state.target.innerText.trim(); } state.target.style.color=''; state.target.style.backgroundColor=''; state.target.removeAttribute('data-lp-dirty'); const hasDirty=qsa('[data-lp-dirty="1"]').length>0; if(saveBtn) saveBtn.disabled=!hasDirty; if(saveAllBtn) saveAllBtn.disabled=!hasDirty; updateChangesCount(); setStatus('Block reset'); });
    if(resetAllBtn && cfg.resetAllEndpoint) resetAllBtn.addEventListener('click',async()=>{ if(!confirm('Reset ALL blocks?')) return; setStatus('Resetting...'); try{ const r=await fetchWithCSRF(cfg.resetAllEndpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'reset_all'})}); const d=await r.json(); if(d.success){ els.forEach(el=>{ const o=state.original.get(el.dataset.lpKey); if(o!==undefined) el.innerHTML=o; el.style.color=''; el.style.backgroundColor=''; el.removeAttribute('data-lp-dirty'); }); if(saveBtn) saveBtn.disabled=true; if(saveAllBtn) saveAllBtn.disabled=true; updateChangesCount(); setStatus('All reset','success'); } else setStatus(d.message||'Reset failed','error'); }catch(e){ setStatus(e.message,'error'); }});
    if(hiBtn) hiBtn.addEventListener('click',()=>{ const on=hiBtn.getAttribute('data-active')==='1'; document.body.classList.toggle('lp-hide-outlines', on); hiBtn.setAttribute('data-active',on?'0':'1'); hiBtn.innerHTML= on?'<i class="bi bi-bounding-box"></i> Show Boxes':'<i class="bi bi-bounding-box-circles"></i> Hide Boxes'; });

    const save=async(dirtyOnly)=>{ if(state.saving) return; const list=dirtyOnly? qsa('[data-lp-dirty="1"]'): qsa('[data-lp-key]'); if(!list.length){ setStatus('Nothing to save'); return; } const payload=list.map(el=>({key:el.dataset.lpKey,html:el.innerHTML,styles:{color:el.style.color||'',backgroundColor:el.style.backgroundColor||''}})); state.saving=true; setStatus('Saving...'); if(saveBtn) saveBtn.disabled=true; if(saveAllBtn) saveAllBtn.disabled=true; try{ const res=await fetchWithCSRF(cfg.saveEndpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({blocks:payload})}); const data=await res.json(); if(!data.success) throw new Error(data.message||'Save failed'); list.forEach(el=>el.removeAttribute('data-lp-dirty')); updateChangesCount(); if(cfg.refreshAfterSave) await cfg.refreshAfterSave(payload.map(p=>p.key)); setStatus('Saved','success'); }catch(e){ setStatus(e.message,'error'); if(saveBtn) saveBtn.disabled=false; if(saveAllBtn) saveAllBtn.disabled=false; } finally { state.saving=false; } };
    if(saveBtn) saveBtn.addEventListener('click',()=>save(true));
    if(saveAllBtn) saveAllBtn.addEventListener('click',()=>save(false));
    window.addEventListener('beforeunload',e=>{ if(qsa('[data-lp-dirty="1"]').length){ e.preventDefault(); e.returnValue=''; }});

    // History modal module
    const Hist=(function(){
      let modal,listEl,closeBtn,loadBtn;
      function ensure(){
        if(modal) return;
  modal=document.createElement('div');
  modal.className='lp-history-modal';
  modal.innerHTML=`<div class="lp-hist-backdrop"></div><div class="lp-hist-dialog"><div class="lp-hist-header d-flex justify-content-between align-items-center"><strong class="small mb-0">${esc(historyHeading)}</strong><div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-primary" data-load><i class="bi bi-arrow-repeat"></i></button><button type="button" class="btn btn-sm btn-outline-secondary" data-close><i class="bi bi-x"></i></button></div></div><div class="lp-hist-body"><div class="accordion" data-list></div></div><div class="lp-hist-footer small text-muted"><i class="bi bi-info-circle"></i> Expand each section to see what changes were made</div></div>`;
        document.body.appendChild(modal);
        listEl=qs('[data-list]',modal); closeBtn=qs('[data-close]',modal); loadBtn=qs('[data-load]',modal);
        closeBtn.addEventListener('click',hide); modal.querySelector('.lp-hist-backdrop').addEventListener('click',hide); loadBtn.addEventListener('click',load);
      }
      async function load(){
        listEl.innerHTML='<div class="text-center text-muted p-3"><div class="spinner-border spinner-border-sm me-2"></div>Loading history...</div>';
        try{
          const r=await fetchWithCSRF(cfg.history.fetchEndpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({})});
          const d=await r.json();
          if(!d.success){ listEl.innerHTML='<div class="alert alert-danger small">Failed to load history</div>'; return; }
          const recs=d.records||[];
          if(!recs.length){ listEl.innerHTML='<div class="alert alert-info small">No edit history found</div>'; return; }
          
          // Group by user and date
          const byUserDate={};
          recs.forEach(r=>{
            const user=r.admin_username||'System';
            const dateTime=r.created_at.split(' ');
            const date=dateTime[0]; // YYYY-MM-DD
            const key=`${user}|${date}`;
            if(!byUserDate[key]) byUserDate[key]={username:user,date:date,edits:[],blocks:new Set(),actions:{}};
            byUserDate[key].edits.push(r);
            byUserDate[key].blocks.add(r.block_key);
            byUserDate[key].actions[r.action_type]=(byUserDate[key].actions[r.action_type]||0)+1;
          });
          
          // Sort by most recent first
          const sortedGroups=Object.values(byUserDate).sort((a,b)=>b.edits[0].created_at.localeCompare(a.edits[0].created_at));
          
          // Format date nicely
          const formatDate=(dateStr)=>{
            const d=new Date(dateStr);
            const today=new Date();
            const yesterday=new Date(today);
            yesterday.setDate(yesterday.getDate()-1);
            if(d.toDateString()===today.toDateString()) return 'Today';
            if(d.toDateString()===yesterday.toDateString()) return 'Yesterday';
            return d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
          };
          
          // Build accordion
          listEl.innerHTML='';
          let idx=0;
          sortedGroups.forEach(userData=>{
            const totalEdits=userData.edits.length;
            const uniqueBlocks=userData.blocks.size;
            const actionSummary=Object.entries(userData.actions).map(([action,count])=>`${count} ${action}`).join(', ');
            const dateFormatted=formatDate(userData.date);
            const firstTime=userData.edits[0].created_at.split(' ')[1].slice(0,5);
            const lastTime=userData.edits[userData.edits.length-1].created_at.split(' ')[1].slice(0,5);
            const timeRange=userData.edits.length>1?`${lastTime} - ${firstTime}`:`${firstTime}`;
            
            const accordionItem=document.createElement('div');
            accordionItem.className='accordion-item';
            accordionItem.innerHTML=`
              <h2 class="accordion-header">
                <button class="accordion-button ${idx>0?'collapsed':''}" type="button" data-bs-toggle="collapse" data-bs-target="#histUser${idx}">
                  <div class="w-100">
                    <div class="d-flex justify-content-between align-items-center">
                      <strong><i class="bi bi-person-circle me-2"></i>${esc(userData.username)}</strong>
                      <span class="badge bg-primary">${totalEdits} ${totalEdits===1?'edit':'edits'}</span>
                    </div>
                    <small class="text-muted d-block mt-1">
                      <i class="bi bi-calendar-event me-1"></i>${dateFormatted} <i class="bi bi-clock ms-2 me-1"></i>${timeRange} • ${uniqueBlocks} ${uniqueBlocks===1?'block':'blocks'} • ${actionSummary}
                    </small>
                  </div>
                </button>
              </h2>
              <div id="histUser${idx}" class="accordion-collapse collapse ${idx===0?'show':''}" data-bs-parent="[data-list]">
                <div class="accordion-body">
                  <ul class="list-group list-group-flush">
                    ${userData.edits.map(edit=>`
                      <li class="list-group-item px-0 py-2">
                        <div class="d-flex justify-content-between">
                          <span class="fw-semibold text-primary">${esc(edit.block_key)}</span>
                          <small class="text-muted">${esc(edit.created_at)}</small>
                        </div>
                        <small class="text-muted">${esc(edit.summary||'Modified content')}</small>
                      </li>
                    `).join('')}
                  </ul>
                </div>
              </div>
            `;
            listEl.appendChild(accordionItem);
            idx++;
          });
        }catch(err){ listEl.innerHTML='<div class="alert alert-danger small">Error loading history</div>'; console.error(err); }
      }
      function show(){ ensure(); modal.classList.add('show'); load(); }
      function hide(){ if(modal) modal.classList.remove('show'); }
      return { open: show, close: hide };
    })();

    if(histBtn && cfg.history){ histBtn.addEventListener('click',()=>Hist.open()); }
  };

  global.ContentEditor=CE;
})(window);
