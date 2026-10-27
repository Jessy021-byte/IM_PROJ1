document.addEventListener('DOMContentLoaded', () => {
  if (window.lucide) window.lucide.createIcons();
  initCanvasBackground();
  initAuthInteractions();
});

function initCanvasBackground() {
  const canvas = document.getElementById('bg-canvas');
  if (!canvas) return;

  const ctx = canvas.getContext('2d');
  if (!ctx || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  let width = (canvas.width = window.innerWidth);
  let height = (canvas.height = window.innerHeight);

  const resizeCanvas = () => {
    width = canvas.width = window.innerWidth;
    height = canvas.height = window.innerHeight;
  };
  window.addEventListener('resize', resizeCanvas);
  let animationFrame = null;

  const particleCount = 45;
  const particles = [];

  class Particle {
    constructor() {
      this.reset();
    }

    reset() {
      this.x = Math.random() * width;
      this.y = Math.random() * height;
      this.radius = Math.random() * 2.5 + 0.5;
      this.vx = (Math.random() - 0.5) * 0.6;
      this.vy = (Math.random() - 0.5) * 0.6;
      this.alpha = Math.random() * 0.5 + 0.2;
    }

    update() {
      this.x += this.vx;
      this.y += this.vy;

      if (this.x < 0 || this.x > width) this.vx *= -1;
      if (this.y < 0 || this.y > height) this.vy *= -1;
    }

    draw() {
      ctx.beginPath();
      ctx.arc(this.x, this.y, this.radius, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(255, 0, 85, ${this.alpha})`;
      ctx.shadowBlur = 10;
      ctx.shadowColor = '#ff0055';
      ctx.fill();
    }
  }

  for (let i = 0; i < particleCount; i++) {
    particles.push(new Particle());
  }

  function animate() {
    animationFrame = null;
    if (document.hidden) return;

    ctx.clearRect(0, 0, width, height);

    // Draw lines between nearby particles
    for (let i = 0; i < particles.length; i++) {
      for (let j = i + 1; j < particles.length; j++) {
        const dx = particles[i].x - particles[j].x;
        const dy = particles[i].y - particles[j].y;
        const dist = Math.sqrt(dx * dx + dy * dy);

        if (dist < 120) {
          ctx.beginPath();
          ctx.moveTo(particles[i].x, particles[i].y);
          ctx.lineTo(particles[j].x, particles[j].y);
          ctx.strokeStyle = `rgba(255, 0, 85, ${0.15 * (1 - dist / 120)})`;
          ctx.lineWidth = 0.8;
          ctx.stroke();
        }
      }
    }

    // Render particles
    particles.forEach(p => {
      p.update();
      p.draw();
    });

    animationFrame = requestAnimationFrame(animate);
  }

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && animationFrame === null) {
      animationFrame = requestAnimationFrame(animate);
    }
  });
  animationFrame = requestAnimationFrame(animate);
}

function switchTab(tab) {
  const loginForm = document.getElementById('login-form');
  const registerForm = document.getElementById('register-form');
  const tabLogin = document.getElementById('tab-login');
  const tabRegister = document.getElementById('tab-register');
  const tabIndicator = document.getElementById('tab-indicator');
  if (!loginForm || !registerForm || !tabLogin || !tabRegister || !tabIndicator) return;

  const showLogin = tab === 'login';
  loginForm.classList.toggle('active', showLogin);
  registerForm.classList.toggle('active', !showLogin);
  tabLogin.classList.toggle('active', showLogin);
  tabRegister.classList.toggle('active', !showLogin);
  tabLogin.setAttribute('aria-selected', String(showLogin));
  tabRegister.setAttribute('aria-selected', String(!showLogin));
  tabIndicator.style.transform = showLogin ? 'translateX(0)' : 'translateX(100%)';
}

function togglePasswordVisibility(inputId, buttonEl) {
  const input = document.getElementById(inputId);
  if (!input) return;

  const isPassword = input.type === 'password';
  input.type = isPassword ? 'text' : 'password';
  const iconName = isPassword ? 'eye-off' : 'eye';
  if (buttonEl) {
    buttonEl.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    buttonEl.setAttribute('aria-pressed', String(isPassword));
    buttonEl.innerHTML = `<i data-lucide="${iconName}"></i>`;
    if (window.lucide) window.lucide.createIcons();
  }
}

function checkPasswordStrength(password) {
  const bar = document.getElementById('strength-bar');
  const text = document.getElementById('strength-text');
  if (!bar || !text) return;

  let score = 0;

  if (password.length >= 8) score += 1;
  if (/[A-Z]/.test(password)) score += 1;
  if (/[0-9]/.test(password)) score += 1;
  if (/[^A-Za-z0-9]/.test(password)) score += 1;

  const levels = [
    { width: '0%', color: 'transparent', label: 'Password strength' },
    { width: '25%', color: '#ff3333', label: 'Weak' },
    { width: '50%', color: '#ffaa00', label: 'Fair' },
    { width: '75%', color: '#33cc33', label: 'Good' },
    { width: '100%', color: '#ff0055', label: 'Strong' },
  ];
  const level = levels[score];
  bar.style.width = level.width;
  bar.style.backgroundColor = level.color;
  text.textContent = level.label;
  text.style.color = level.color;
}

function handleLogin() {
  const email = document.getElementById('login-email')?.value.trim();
  if (!email) return;

  const rememberMe = document.getElementById('remember-me');
  if (rememberMe?.checked) localStorage.setItem('jlp-demo-email', email);
  else localStorage.removeItem('jlp-demo-email');
}

function handleRegister(event) {
  event.preventDefault();
  const pass = document.getElementById('reg-password')?.value;
  const confirmPass = document.getElementById('reg-confirm-password')?.value;

  if (pass !== confirmPass) {
    showToast('Passwords do not match!', 'error');
    return;
  }

  const email = document.getElementById('reg-email')?.value.trim();
  const emailField = document.getElementById('login-email');
  if (emailField && email) emailField.value = email;
  switchTab('login');
  showToast('Registration is a demo only; no account was saved. You can try the sign-in flow.');
}

function handleSocialAuth(provider) {
  showToast(`${provider} sign-in is not configured. Use the demo email form instead.`, 'error');
}

function openForgotModal(event) {
  if (event) event.preventDefault();
  const modal = document.getElementById('forgot-modal');
  if (!modal) return;
  const resetEmail = document.getElementById('reset-email');
  const loginEmail = document.getElementById('login-email');
  if (resetEmail && loginEmail?.value) resetEmail.value = loginEmail.value;
  modal.classList.add('active');
  resetEmail?.focus();
}

function closeForgotModal() {
  document.getElementById('forgot-modal')?.classList.remove('active');
}

function handleResetPassword(event) {
  event.preventDefault();
  showToast('Password reset is a demo; no email was sent.', 'error');
  event.currentTarget.reset();
  closeForgotModal();
}

function showToast(message, type = 'success') {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast ${type === 'error' ? 'toast-error' : ''}`;
  toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
  const text = document.createElement('span');
  text.textContent = message;
  toast.append(text);

  container.appendChild(toast);
  setTimeout(() => {
    toast.remove();
  }, 3500);
}

function initAuthInteractions() {
  document.getElementById('tab-login')?.addEventListener('click', () => switchTab('login'));
  document.getElementById('tab-register')?.addEventListener('click', () => switchTab('register'));
  document.getElementById('login-form')?.addEventListener('submit', handleLogin);
  document.getElementById('register-form')?.addEventListener('submit', handleRegister);
  document.getElementById('reset-form')?.addEventListener('submit', handleResetPassword);
  document.getElementById('forgot-link')?.addEventListener('click', openForgotModal);
  document.querySelectorAll('[data-password-toggle]').forEach(button => {
    button.addEventListener('click', () => togglePasswordVisibility(button.dataset.passwordToggle, button));
  });
  document.getElementById('reg-password')?.addEventListener('input', event => {
    checkPasswordStrength(event.currentTarget.value);
  });
  document.querySelectorAll('[data-social-provider]').forEach(button => {
    button.addEventListener('click', () => handleSocialAuth(button.dataset.socialProvider));
  });
  document.querySelectorAll('.inline-link').forEach(link => {
    link.addEventListener('click', event => {
      event.preventDefault();
      showToast('Terms and conditions are not configured for this demo.', 'error');
    });
  });
  document.querySelector('.modal-close')?.addEventListener('click', closeForgotModal);
  document.getElementById('forgot-modal')?.addEventListener('click', event => {
    if (event.target === event.currentTarget) closeForgotModal();
  });
  const rememberMe = document.getElementById('remember-me');
  const loginEmail = document.getElementById('login-email');
  if (rememberMe && loginEmail) {
    const savedEmail = localStorage.getItem('jlp-demo-email');
    if (savedEmail) {
      loginEmail.value = savedEmail;
      rememberMe.checked = true;
    }
    rememberMe.addEventListener('change', () => {
      if (!rememberMe.checked) localStorage.removeItem('jlp-demo-email');
    });
  }
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeForgotModal();
  });
}