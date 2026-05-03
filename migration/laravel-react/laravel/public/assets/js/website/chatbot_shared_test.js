// TEST VERSION - Chatbot logic with FIXED typing indicator positioning
(function(){
  document.addEventListener('DOMContentLoaded', function(){
    const apiUrl = (window.location.pathname.indexOf('/website/')!==-1)? '../chatbot/gemini_chat.php' : 'chatbot/gemini_chat.php';
    const toggle = document.getElementById('eaToggle');
    const panel  = document.getElementById('eaPanel');
    const close  = document.getElementById('eaClose');
    const body   = document.getElementById('eaBody');
    const input  = document.getElementById('eaInput');
    const send   = document.getElementById('eaSend');
    if(!toggle||!panel) return;
    let isOpen=false; let busy=false;
    let typingElement = null; // Will be created dynamically

    function toggleChat(){ 
      isOpen=!isOpen; 
      panel.style.display = isOpen?'block':'none'; 
      if(isOpen){ 
        input&&input.focus(); 
        scrollToBottom();
      }
    }

    toggle.addEventListener('click',toggleChat);
    close && close.addEventListener('click',toggleChat);

    function scrollToBottom(){
      if(body){
        // Use setTimeout to ensure DOM has updated
        setTimeout(() => {
          body.scrollTop = body.scrollHeight;
        }, 10);
      }
    }

    function createTypingIndicator(){
      if(!typingElement){
        typingElement = document.createElement('div');
        typingElement.className = 'ea-typing';
        typingElement.innerHTML = 'EducAid Assistant is typing...';
        typingElement.style.display = 'none';
      }
      return typingElement;
    }

    function showTyping(){
      const typing = createTypingIndicator();
      // CRITICAL: Remove from current position first
      if(typing.parentNode){
        typing.parentNode.removeChild(typing);
      }
      // Now append to the very END of the body
      body.appendChild(typing);
      typing.style.display = 'block';
      // Force scroll to bottom immediately
      scrollToBottom();
      console.log('Typing indicator shown at position:', Array.from(body.children).indexOf(typing), 'of', body.children.length);
    }

    function hideTyping(){
      if(typingElement && typingElement.parentNode){
        typingElement.style.display = 'none';
        // Optionally remove it entirely
        typingElement.parentNode.removeChild(typingElement);
        typingElement = null; // Reset so it can be recreated next time
      }
    }

    async function sendMsg(){
      if(!input||busy) return; 
      const text = input.value.trim(); 
      if(!text) return; 
      input.value='';
      
      // Add user message
      addUser(text);
      
      // Show typing indicator at the BOTTOM
      showTyping();
      
      busy=true; 
      input.disabled=true;
      
      try {
        const res = await fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:text})});
        const payload = await res.json().catch(()=>null);
        if(res.ok && payload && payload.reply){
          addBot(formatChatbotResponse(payload.reply)+ diagnosticTag(payload));
        } else {
          let msg = 'Sorry, the assistant is temporarily unavailable.';
          if(payload && payload.error){
            msg = '⚠️ '+payload.error;
            if(payload.detail) msg += '<br><small>'+escapeHtml(payload.detail)+'</small>';
            if(payload.available_models && payload.available_models.length){
              msg += '<br><small>Available models:<br>'+payload.available_models.map(m=>escapeHtml(m)).join('<br>')+'</small>';
            }
          }
          addBot(msg);
        }
      } catch(e){ 
        console.error('Chatbot error',e); 
        addBot('Network error. Please retry.'); 
      }
      finally { 
        hideTyping(); 
        busy=false; 
        input.disabled=false; 
        input.focus(); 
      }
    }

    function diagnosticTag(p){
      if(!p.model_used) return '';
      return `<div class="cb-diag">`+
        `<small class="text-muted">Model: ${escapeHtml(p.model_used)} (${escapeHtml(p.api_version||'')})</small>`+
      `</div>`;
    }

    function escapeHtml(s){ 
      return s.replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c])); 
    }

    function addUser(text){ 
      const d=document.createElement('div'); 
      d.className='ea-chat__msg ea-chat__msg--user'; 
      d.innerHTML='<div class="ea-chat__bubble ea-chat__bubble--user"></div>'; 
      d.querySelector('.ea-chat__bubble').textContent=text; 
      body.appendChild(d); 
      scrollToBottom();
    }
    
    function addBot(html){ 
      // Hide typing first
      hideTyping();
      
      const d=document.createElement('div'); 
      d.className='ea-chat__msg'; 
      d.innerHTML='<div class="ea-chat__bubble"></div>'; 
      d.querySelector('.ea-chat__bubble').innerHTML=html; 
      body.appendChild(d); 
      scrollToBottom();
    }

    send && send.addEventListener('click',sendMsg);
    input && input.addEventListener('keydown',e=>{ 
      if(e.key==='Enter'&&!e.shiftKey){ 
        e.preventDefault(); 
        sendMsg(); 
      }
    });
    
    document.addEventListener('click',e=>{ 
      if(!e.target.closest('.ea-chat')&&isOpen){ 
        toggleChat(); 
      }
    });
  });
})();

function formatChatbotResponse(text){
  return text
    .replace(/(?<!\*)\*(?!\*)/g,'')
    .replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>')
    .replace(/^[-•]\s*(.+)$/gm,'<div class="cb-item">• $1</div>')
    .replace(/\n\n+/g,'<div class="cb-gap"></div>')
    .replace(/\n/g,'<br>');
}
