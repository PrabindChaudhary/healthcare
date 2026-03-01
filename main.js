// ============================================================
//  MEDICARE AI - Main JavaScript  |  Project By Prabind
//  Fixed bugs:
//   1. navbar null-guard (pages without #navbar won't crash)
//   2. counter: step=0 guard (target=0 caused infinite loop)
//   3. ECG: animation ID stored so it can be cancelled on hide
//   4. chatInput null-guard (pages without chatbot won't crash)
//   5. smooth-scroll: href="#" (empty hash) no longer throws
//   6. urgency-fill: stored width before zeroing (style.width
//      was set to '0%' first, so stored value was already '0%')
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

  /* ===== 1. NAVBAR SCROLL EFFECT ===== */
  const navbar = document.getElementById('navbar');
  if (navbar) {
    // Trigger once on load in case page starts scrolled
    if (window.scrollY > 50) navbar.classList.add('scrolled');

    window.addEventListener('scroll', () => {
      navbar.classList.toggle('scrolled', window.scrollY > 50);
    });
  }

  /* ===== 2. HAMBURGER MENU ===== */
  const hamburger = document.getElementById('hamburger');
  const navLinks = document.getElementById('navLinks');
  if (hamburger && navLinks) {
    hamburger.addEventListener('click', () => {
      navLinks.classList.toggle('open');
      // Animate hamburger spans into X
      hamburger.classList.toggle('active');
    });
    navLinks.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        navLinks.classList.remove('open');
        hamburger.classList.remove('active');
      });
    });
  }

  /* ===== 3. COUNTER ANIMATION ===== */
  const counters = document.querySelectorAll('.stat-num');
  if (counters.length > 0) {
    const observerCounts = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const target = parseInt(entry.target.dataset.count, 10) || 0;
          animateCounter(entry.target, target);
          observerCounts.unobserve(entry.target);
        }
      });
    }, { threshold: 0.5 });

    counters.forEach(counter => observerCounts.observe(counter));
  }

  function animateCounter(el, target) {
    // BUG FIX: target=0 made step=0, causing infinite loop
    if (target === 0) { el.textContent = '0'; return; }
    let current = 0;
    const step = target / 80;
    const timer = setInterval(() => {
      current += step;
      if (current >= target) {
        current = target;
        clearInterval(timer);
      }
      el.textContent = Math.floor(current).toLocaleString();
    }, 20);
  }

  /* ===== 4. FADE-IN ON SCROLL ===== */
  const fadeEls = document.querySelectorAll(
    '.feature-card, .step-card, .doctor-card, .testimonial-card, .urgency-card'
  );
  fadeEls.forEach(el => el.classList.add('fade-in'));

  const fadeObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        fadeObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

  fadeEls.forEach(el => fadeObserver.observe(el));

  /* ===== 5. ECG CANVAS ANIMATION ===== */
  const canvas = document.getElementById('ecgCanvas');
  if (canvas) {
    const ctx = canvas.getContext('2d');
    let offset = 0;
    let animId = null; // BUG FIX: store ID so we can cancel if needed

    function drawECG() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.beginPath();
      ctx.strokeStyle = '#10b981';
      ctx.lineWidth = 2;
      ctx.shadowColor = '#10b981';
      ctx.shadowBlur = 8;

      const w = canvas.width;
      const h = canvas.height;
      const mid = h / 2;
      ctx.moveTo(0, mid);

      for (let x = 0; x < w; x++) {
        const t = ((x + offset) / w) * Math.PI * 4;
        const phase = (x + offset) % 120;
        let y = mid;

        if (phase < 10) { y = mid - phase * 0.5; }
        else if (phase < 15) { y = mid - 40; }
        else if (phase < 20) { y = mid + 20; }
        else if (phase < 30) { y = mid - 15; }
        else if (phase < 50) { y = mid + Math.sin(t) * 3; }
        else { y = mid + Math.sin(t) * 2; }

        ctx.lineTo(x, y);
      }
      ctx.stroke();
      offset += 2;
      animId = requestAnimationFrame(drawECG);
    }
    drawECG();
  }

  /* ===== 6. PARTICLES ===== */
  const particlesContainer = document.getElementById('particles');
  if (particlesContainer) {
    // Inject keyframes once
    const styleTag = document.createElement('style');
    styleTag.textContent = `
      @keyframes particleRise {
        0%   { transform: translateY(0) scale(1); opacity: 0.3; }
        100% { transform: translateY(-100vh) scale(0); opacity: 0; }
      }
    `;
    document.head.appendChild(styleTag);

    for (let i = 0; i < 40; i++) {
      const particle = document.createElement('div');
      const size = Math.random() * 4 + 1;
      const x = Math.random() * 100;
      const delay = Math.random() * 10;
      const duration = Math.random() * 15 + 10;
      const opacity = (Math.random() * 0.4 + 0.1).toFixed(2);
      const color = Math.random() > 0.5 ? '#3b82f6' : '#06b6d4';

      particle.style.cssText = [
        'position:absolute',
        `width:${size}px`,
        `height:${size}px`,
        `background:${color}`,
        'border-radius:50%',
        `left:${x}%`,
        'bottom:-10px',
        `opacity:${opacity}`,
        `animation:particleRise ${duration}s ${delay}s linear infinite`,
        'pointer-events:none'
      ].join(';');

      particlesContainer.appendChild(particle);
    }
  }

  /* ===== 7. CHATBOT DEMO ===== */
  const chatInput = document.getElementById('chatInput');
  const chatSend = document.getElementById('chatSend');
  const chatMsgs = document.getElementById('chatMessages');
  const botTyping = document.getElementById('botTyping');

  // Only wire up chatbot if ALL elements exist on this page
  if (chatInput && chatSend && chatMsgs && botTyping) {
    const botResponses = [
      "Based on your symptoms, this could be viral meningitis. I strongly recommend seeking emergency care immediately. Would you like me to find the nearest hospital?",
      "High fever with light sensitivity is a concerning combination. Your urgency level has been set to HIGH. I recommend an appointment within the next 2 hours.",
      "I understand. Let me help prioritize your care. Based on these symptoms, I'd recommend a Neurologist. Shall I book an appointment?",
      "Your symptoms suggest you need medical attention. I'm scheduling a consultation with Dr. Anjali Sharma — she's available now.",
      "Your case has been logged with HIGH priority. A doctor will contact you shortly. In the meantime, rest in a dark room and stay hydrated.",
      "I've analyzed your symptoms. The AI suggests possible viral infection. Urgency: Medium. Click 'Book Appointment' to see a doctor within 24 hours."
    ];

    let responseIndex = 0;

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.appendChild(document.createTextNode(text));
      return div.innerHTML;
    }

    function sendMessage() {
      const text = chatInput.value.trim();
      if (!text) return;

      // User bubble
      const userDiv = document.createElement('div');
      userDiv.className = 'message user-msg';
      userDiv.innerHTML = `<div class="msg-bubble">${escapeHtml(text)}</div>`;
      chatMsgs.insertBefore(userDiv, botTyping);
      chatInput.value = '';
      chatMsgs.scrollTop = chatMsgs.scrollHeight;

      // Show typing indicator
      botTyping.style.display = 'flex';
      chatMsgs.scrollTop = chatMsgs.scrollHeight;

      // Bot reply after 1.5 s
      setTimeout(() => {
        botTyping.style.display = 'none';

        const botDiv = document.createElement('div');
        botDiv.className = 'message bot-msg';
        const response = botResponses[responseIndex % botResponses.length];
        botDiv.innerHTML = `<div class="msg-bubble">${response}</div>`;
        chatMsgs.insertBefore(botDiv, botTyping);
        responseIndex++;
        chatMsgs.scrollTop = chatMsgs.scrollHeight;
      }, 1500);
    }

    chatSend.addEventListener('click', sendMessage);
    chatInput.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') sendMessage();
    });
  }

  /* ===== 8. SMOOTH SCROLL FOR ANCHORS ===== */
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', (e) => {
      const href = anchor.getAttribute('href');
      // BUG FIX: skip bare '#' (no target id) — would throw querySelector error
      if (!href || href === '#') return;
      const target = document.querySelector(href);
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  /* ===== 9. ACTIVE NAV LINK ===== */
  const currentPage = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-links a').forEach(link => {
    const linkHref = link.getAttribute('href');
    link.classList.toggle('active', linkHref === currentPage);
  });

  /* ===== 10. URGENCY FILL ANIMATION ===== */
  const urgencyFills = document.querySelectorAll('.urgency-fill');
  if (urgencyFills.length > 0) {
    const urgencyObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const fill = entry.target;
          // BUG FIX: read target width BEFORE zeroing it out
          // Previously: set to 0% first, then stored that 0% as target
          const targetWidth = fill.getAttribute('data-width') || fill.style.width;
          // Store on data-attribute so reset is idempotent
          fill.setAttribute('data-width', targetWidth);
          fill.style.width = '0%';
          setTimeout(() => { fill.style.width = targetWidth; }, 200);
          urgencyObserver.unobserve(fill);
        }
      });
    }, { threshold: 0.3 });

    urgencyFills.forEach(fill => urgencyObserver.observe(fill));
  }

  console.log('%c✅ MediCare AI Initialized | Project By Prabind',
    'color:#3b82f6; font-weight:bold; font-size:13px;');
});
