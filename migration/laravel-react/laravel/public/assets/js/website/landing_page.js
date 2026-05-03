/* Consolidated landing page JavaScript */

// Smooth anchor highlighting
(function(){
  const links = document.querySelectorAll('.nav-link');
  if (!links.length) return;
  const sections = [...links]
    .map(a => {
      const h = a.getAttribute('href');
      if (!h || !h.startsWith('#')) return null; return document.querySelector(h);
    })
    .filter(Boolean);
  if (!sections.length) return;
  const obs = new IntersectionObserver((entries)=>{
    entries.forEach(e=>{
      const id = '#'+e.target.id;
      const link = document.querySelector(`.nav-link[href="${id}"]`);
      if(link){ link.classList.toggle('active', e.isIntersecting && e.intersectionRatio > .5); }
    });
  }, {threshold:[.6]});
  sections.forEach(s=>obs.observe(s));
})();

// Current year
(function(){
  const y = document.getElementById('year');
  if (y) y.textContent = new Date().getFullYear();
})();

// Newsletter form handler
(function(){
  const form = document.getElementById('newsletterForm');
  if(!form) return;
  const msg = document.getElementById('newsletterMessage');
  const btn = document.getElementById('subscribeBtn');
  const emailInput = document.getElementById('emailInput');

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const email = emailInput.value.trim();
    msg.style.display='none';
    msg.className = 'small text-center mt-2';
    if(!email || !email.includes('@')) { show('Please enter a valid email address','error'); return; }
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Subscribing...';
    try {
      const fd = new FormData(); fd.append('email', email);
      const res = await fetch('newsletter_subscribe.php',{method:'POST',body:fd});
      const data = await res.json().catch(()=>({success:false,message:'Invalid server response'}));
      if (data.success) { show(data.message,'success'); form.reset(); }
      else { show(data.message||'Subscription failed','error'); }
    } catch(err){ console.error('Newsletter error',err); show('Network error. Please try again later.','error'); }
    finally { btn.disabled=false; btn.innerHTML='Subscribe'; }
  });

  function show(message,type){
    msg.textContent = message; msg.className = 'small text-center ' + (type==='success'?'text-success':'text-danger'); msg.style.display='block';
    if(type==='success'){ setTimeout(()=>{ msg.style.display='none'; },5000); }
  }
})();

// Chatbot
(function(){
  document.addEventListener('DOMContentLoaded', function(){
    const apiUrl = '../chatbot/gemini_chat.php';
    const toggle = document.getElementById('eaToggle');
    const panel  = document.getElementById('eaPanel');
    const close  = document.getElementById('eaClose');
    const body   = document.getElementById('eaBody');
    const input  = document.getElementById('eaInput');
    const send   = document.getElementById('eaSend');
    const typing = document.getElementById('eaTyping');
    if(!toggle||!panel) return;
    let isOpen=false;

    function toggleChat(){
      isOpen=!isOpen; panel.style.display = isOpen?'block':'none'; if(isOpen) input&&input.focus(); }

    toggle.addEventListener('click',toggleChat);
    close && close.addEventListener('click',toggleChat);

    async function sendMsg(){
      if(!input) return; const text = input.value.trim(); if(!text) return; input.value=''; input.disabled=true;
      addUser(text); typing.style.display='block';
      try {
        const res = await fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:text})});
        if(!res.ok) throw new Error('HTTP '+res.status);
        const data = await res.json();
        addBot(formatChatbotResponse(data.reply||'Sorry, I could not understand that.'));
      } catch(err){ console.error('Chatbot error',err); addBot('Sorry, I\'m having trouble connecting. Please try again later or contact support at educaid@generaltrias.gov.ph'); }
      finally { typing.style.display='none'; input.disabled=false; input.focus(); body.scrollTop=body.scrollHeight; }
    }

    function addUser(text){
      const d=document.createElement('div'); d.className='ea-chat__msg ea-chat__msg--user'; d.innerHTML='<div class="ea-chat__bubble ea-chat__bubble--user"></div>'; d.querySelector('.ea-chat__bubble').textContent=text; body.appendChild(d); body.scrollTop=body.scrollHeight; }
    function addBot(html){ const d=document.createElement('div'); d.className='ea-chat__msg'; d.innerHTML='<div class="ea-chat__bubble"></div>'; d.querySelector('.ea-chat__bubble').innerHTML=html; body.appendChild(d); body.scrollTop=body.scrollHeight; }

    send && send.addEventListener('click',sendMsg);
    input && input.addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); sendMsg(); }});
    document.addEventListener('click',e=>{ if(!e.target.closest('.ea-chat')&&isOpen){ toggleChat(); }});
  });
})();

function formatChatbotResponse(text){
  return text
    .replace(/(?<!\*)\*(?!\*)/g,'')
    .replace(/📋\s*\*\*(.*?)\*\*/g,'<div class="req-header-emoji">📋 <strong>$1</strong></div>')
    .replace(/(\d+)\.\s*\*\*(.*?)\*\*/g,'<div class="req-header-numbered"><strong>$1. $2</strong></div>')
    .replace(/\*\*([^:]+):\*\*/g,'<div class="req-header-spaced"><strong>$1:</strong></div>')
    .replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>')
    .replace(/^[-•]\s*(.+)$/gm,'<div class="req-item">• $1</div>')
    .replace(/\n\n+/g,'<div class="req-spacer"></div>')
    .replace(/\n/g,'<br>')
    .replace(/\*/g,'');
}

// Scroll animations
(function(){
  class ScrollAnimations {
    constructor(){
      this.observerOptions={threshold:0.1,rootMargin:'0px 0px -10% 0px'}; this.init();
    }
    init(){ this.createObserver(); this.observeElements(); }
    createObserver(){ this.observer=new IntersectionObserver(entries=>{ entries.forEach(entry=>{ if(entry.isIntersecting){ this.animateElement(entry.target); this.observer.unobserve(entry.target);} }); }, this.observerOptions); }
    observeElements(){ document.querySelectorAll('.fade-in, .fade-in-left, .fade-in-right, .fade-in-scale').forEach(el=>this.observer.observe(el)); }
    animateElement(el){ el.classList.add('visible'); if(el.classList.contains('fade-in-stagger')){ const children=el.querySelectorAll('.fade-in'); children.forEach((c,i)=>{ setTimeout(()=>c.classList.add('visible'), i*100); }); } }
  }
  document.addEventListener('DOMContentLoaded',()=> new ScrollAnimations());
  
  // Live distribution status section (dedicated section like announcements)
  (function(){
    const section = document.getElementById('distribution-status');
    const statusBadge = document.getElementById('distStatusBadge');
    const period = document.getElementById('distPeriod');
    const slotsLeft = document.getElementById('distSlotsLeft');
    const slotsTotal = document.getElementById('distSlotsTotal');
    const progressBar = document.getElementById('distProgressBar');
    const progressPercent = document.getElementById('distProgressPercent');
    const lastUpdated = document.getElementById('distLastUpdated');
    const applyBtn = document.getElementById('distApplyBtn');
    const closedNote = document.getElementById('distClosedNote');
    
    if (!section || !statusBadge || !period || !slotsLeft) return;

    let lastSlots = null;
    let timer = null;

    async function fetchSummary(){
      try {
        const res = await fetch('../api/public/distribution_summary.php', { cache: 'no-store' });
        const data = await res.json();
        
        if (!data.success) {
          hideSection();
          return;
        }

        // Always show the section once we have data
        showSection();

        // Update last updated timestamp
        if (lastUpdated) {
          const now = new Date();
          lastUpdated.innerHTML = `<i class="bi bi-clock-history me-1"></i>Updated ${now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}`;
          lastUpdated.className = 'badge bg-success-subtle text-success small';
        }

        if (data.status !== 'open') {
          // Distribution is closed
          statusBadge.textContent = 'Closed';
          statusBadge.className = 'badge text-bg-secondary status-closed';
          period.textContent = 'No active distribution period';
          slotsLeft.textContent = '0';
          slotsLeft.className = 'display-3 fw-bold mb-2 no-slots';
          if (slotsTotal) slotsTotal.innerHTML = 'out of <span class="fw-bold">0</span> total slots';
          if (progressBar) {
            progressBar.style.width = '0%';
            progressBar.setAttribute('aria-valuenow', '0');
            progressBar.className = 'progress-bar bg-gradient';
          }
          if (progressPercent) {
            progressPercent.textContent = '0%';
            progressPercent.className = 'badge bg-secondary-subtle text-secondary';
          }
          if (applyBtn) {
            applyBtn.classList.add('disabled');
            applyBtn.innerHTML = '<i class="bi bi-lock me-2"></i>Applications Closed';
          }
          if (closedNote) {
            closedNote.classList.remove('d-none');
          }
          return;
        }

        // Distribution is open
        statusBadge.textContent = 'Open';
        statusBadge.className = 'badge text-bg-success status-open';
        
        const periodText = [data.academic_year, data.semester].filter(Boolean).join(' • ');
        period.textContent = periodText || 'Current Distribution Period';

        const slots = parseInt(data.slots_left || 0, 10);
        const total = parseInt(data.slot_count || 0, 10);
        const used = total - slots;
        const percent = total > 0 ? Math.round((used / total) * 100) : 0;

        // Update slots with color coding
        slotsLeft.textContent = slots.toLocaleString();
        if (slots === 0) {
          slotsLeft.className = 'display-3 fw-bold mb-2 no-slots';
        } else if (slots < total * 0.2) {
          slotsLeft.className = 'display-3 fw-bold mb-2 low-slots';
        } else {
          slotsLeft.className = 'display-3 fw-bold mb-2';
        }

        if (slotsTotal) {
          slotsTotal.innerHTML = `out of <span class="fw-bold">${total.toLocaleString()}</span> total slots`;
        }

        // Update progress bar with color coding
        if (progressBar) {
          progressBar.style.width = percent + '%';
          progressBar.setAttribute('aria-valuenow', percent);
          
          // Color code the progress bar based on fill percentage
          if (percent >= 80) {
            progressBar.className = 'progress-bar bg-gradient bg-success';
          } else if (percent >= 50) {
            progressBar.className = 'progress-bar bg-gradient bg-warning';
          } else {
            progressBar.className = 'progress-bar bg-gradient bg-info';
          }
        }

        if (progressPercent) {
          progressPercent.textContent = percent + '%';
          // Color code percentage badge
          if (percent >= 80) {
            progressPercent.className = 'badge bg-success-subtle text-success';
          } else if (percent >= 50) {
            progressPercent.className = 'badge bg-warning-subtle text-warning';
          } else {
            progressPercent.className = 'badge bg-primary-subtle text-primary';
          }
        }

        // Enable/disable apply button
        if (applyBtn) {
          if (slots > 0) {
            applyBtn.classList.remove('disabled');
            applyBtn.innerHTML = '<i class="bi bi-rocket-takeoff me-2"></i>Apply Now';
          } else {
            applyBtn.classList.add('disabled');
            applyBtn.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>Slots Full';
          }
        }

        if (closedNote) {
          closedNote.classList.add('d-none');
        }

        // Pulse animation on slot change
        if (lastSlots !== null && lastSlots !== slots) {
          const card = section.querySelector('.dist-main-card');
          if (card) {
            card.classList.remove('pulse');
            // Force reflow
            void card.offsetWidth;
            card.classList.add('pulse');
          }
        }
        lastSlots = slots;

      } catch (e) {
        console.error('Distribution fetch error:', e);
        hideSection();
      }
    }

    function showSection(){
      section.style.display = 'block';
      section.setAttribute('aria-hidden', 'false');
    }

    function hideSection(){
      section.style.display = 'none';
      section.setAttribute('aria-hidden', 'true');
    }

    // Initial fetch and poll every 15s
    fetchSummary();
    timer = setInterval(fetchSummary, 15000);

    // Cleanup
    window.addEventListener('beforeunload', ()=>{ if (timer) clearInterval(timer); });
  })();
})();
