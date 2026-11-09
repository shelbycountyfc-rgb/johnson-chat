const API_BASE = '../api';

// DOM refs
const progress = document.getElementById('setup-progress');
const progressFill = document.getElementById('progress-fill');
const psteps = document.querySelectorAll('.pstep');

const stepEls = {
  1: document.getElementById('step-1'),
  2: document.getElementById('step-2'),
  3: document.getElementById('step-3'),
  4: document.getElementById('step-4'),
};

const reqGrid = document.getElementById('req-grid');
const reqCheckBtn = document.getElementById('req-check-btn');
const reqNextBtn = document.getElementById('req-next-btn');

const dbStatus = document.getElementById('db-status');
const dbInitBtn = document.getElementById('db-init-btn');
const dbNextBtn = document.getElementById('db-next-btn');

const setupUsername = document.getElementById('setup-username');
const setupPassword = document.getElementById('setup-password');
const setupConfirm = document.getElementById('setup-confirm');
const setupError = document.getElementById('setup-error');
const setupSuccess = document.getElementById('setup-success');
const setupCreateBtn = document.getElementById('setup-create-btn');
const setupBackBtn = document.getElementById('setup-back-btn');

const completeMsg = document.getElementById('complete-msg');
const completeSummary = document.getElementById('complete-summary');

// State
let currentStep = 1;
let checksPassed = false;
let dbReady = false;
let adminCreated = false;
let setupData = {};

// --- Navigation ---
function showStep(n) {
  Object.values(stepEls).forEach(el => el.classList.add('hidden'));
  stepEls[n].classList.remove('hidden');
  currentStep = n;
  updateProgress();
}

function updateProgress() {
  progress.classList.remove('hidden');
  const pct = ((currentStep - 1) / 3) * 100;
  progressFill.style.width = Math.min(pct, 100) + '%';
  psteps.forEach((ps, i) => {
    const s = i + 1;
    ps.classList.remove('active', 'done');
    if (s < currentStep) ps.classList.add('done');
    else if (s === currentStep) ps.classList.add('active');
  });
}

function goToStep(n) {
  showStep(n);
}

// --- Step 1: Requirements ---
reqCheckBtn.addEventListener('click', checkRequirements);

async function checkRequirements() {
  reqCheckBtn.disabled = true;
  reqCheckBtn.textContent = 'Checking...';
  reqGrid.innerHTML = '';

  try {
    const res = await fetch(`${API_BASE}/setup.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'requirements' }),
    });
    const data = await res.json();

    reqGrid.innerHTML = '';
    let allPass = true;
    (data.checks || []).forEach(c => {
      const div = document.createElement('div');
      div.className = 'req-item ' + (c.pass ? 'pass' : 'fail');
      div.innerHTML = `
        <span class="req-icon">${c.pass ? '\u2713' : '\u2717'}</span>
        <span class="req-name">${c.name}</span>
        <span class="req-note">${c.note}</span>
      `;
      reqGrid.appendChild(div);
      if (!c.pass) allPass = false;
    });

    checksPassed = allPass;
    if (allPass) {
      reqNextBtn.classList.remove('hidden');
    } else {
      reqNextBtn.classList.add('hidden');
    }
  } catch (err) {
    reqGrid.innerHTML = '<div class="req-item fail"><span class="req-icon">\u2717</span><span class="req-name">Connection Error</span><span class="req-note">' + err.message + '</span></div>';
    reqNextBtn.classList.add('hidden');
  } finally {
    reqCheckBtn.disabled = false;
    reqCheckBtn.textContent = 'Check Requirements';
  }
}

reqNextBtn.addEventListener('click', () => goToStep(2));

// --- Step 2: Database ---
dbInitBtn.addEventListener('click', initDatabase);

async function initDatabase() {
  dbInitBtn.disabled = true;
  dbInitBtn.textContent = 'Initializing...';
  dbStatus.className = 'setup-msg';
  dbStatus.textContent = '';

  try {
    const res = await fetch(`${API_BASE}/setup.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'init_database' }),
    });
    const data = await res.json();

    if (data.success) {
      dbReady = true;
      dbStatus.className = 'setup-msg success';
      dbStatus.textContent = data.message + '. Tables: ' + (data.tables || []).join(', ');
      dbNextBtn.classList.remove('hidden');
      dbInitBtn.textContent = 'Re-initialize';
      dbInitBtn.disabled = false;
    } else {
      dbStatus.className = 'setup-msg error';
      dbStatus.textContent = data.message || 'Database initialization failed';
      dbNextBtn.classList.add('hidden');
      dbInitBtn.disabled = false;
      dbInitBtn.textContent = 'Initialize Database';
    }
  } catch (err) {
    dbStatus.className = 'setup-msg error';
    dbStatus.textContent = 'Connection error: ' + err.message;
    dbInitBtn.disabled = false;
    dbInitBtn.textContent = 'Initialize Database';
  }
}

dbNextBtn.addEventListener('click', () => goToStep(3));

// --- Step 3: Admin ---
setupCreateBtn.addEventListener('click', createAdmin);
setupConfirm.addEventListener('keydown', (e) => { if (e.key === 'Enter') createAdmin(); });
setupBackBtn.addEventListener('click', () => goToStep(2));

async function createAdmin() {
  const username = setupUsername.value.trim();
  const password = setupPassword.value.trim();
  const confirm = setupConfirm.value.trim();

  setupError.classList.add('hidden');
  setupSuccess.classList.add('hidden');

  if (!username || !password || !confirm) {
    setupError.textContent = 'All fields are required';
    setupError.classList.remove('hidden');
    return;
  }
  if (password !== confirm) {
    setupError.textContent = 'Passwords do not match';
    setupError.classList.remove('hidden');
    return;
  }
  if (password.length < 4) {
    setupError.textContent = 'Password must be at least 4 characters';
    setupError.classList.remove('hidden');
    return;
  }

  setupCreateBtn.disabled = true;
  setupCreateBtn.textContent = 'Creating...';

  try {
    const res = await fetch(`${API_BASE}/setup.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'create_admin', username, password }),
    });
    const data = await res.json();

    if (data.success) {
      adminCreated = true;
      setupSuccess.textContent = 'Admin account created!';
      setupSuccess.classList.remove('hidden');
      setupData.admin = { username };
      setTimeout(() => goToStep(4), 800);
    } else {
      setupError.textContent = data.message || 'Failed to create admin';
      setupError.classList.remove('hidden');
    }
  } catch (err) {
    setupError.textContent = 'Connection error: ' + err.message;
    setupError.classList.remove('hidden');
  } finally {
    setupCreateBtn.disabled = false;
    setupCreateBtn.textContent = 'Create Admin Account';
  }
}

// --- Step 4: Complete ---
function buildSummary() {
  completeSummary.innerHTML = '';
  const items = [
    { label: 'Requirements', ok: checksPassed, detail: checksPassed ? 'All passed' : 'Some failed' },
    { label: 'Database', ok: dbReady, detail: dbReady ? 'Initialized' : 'Not ready' },
    { label: 'Admin Account', ok: adminCreated, detail: adminCreated ? setupData.admin?.username || 'Created' : 'Skipped' },
  ];
  items.forEach(item => {
    const div = document.createElement('div');
    div.className = 'summary-item ' + (item.ok ? 'ok' : 'fail');
    div.innerHTML = `
      <span class="s-icon">${item.ok ? '\u2713' : '\u2717'}</span>
      <span class="s-label">${item.label}</span>
      <span class="s-detail">${item.detail}</span>
    `;
    completeSummary.appendChild(div);
  });
}

// Override showStep to build summary on step 4
const origShowStep = showStep;
showStep = function(n) {
  origShowStep(n);
  if (n === 4) buildSummary();
};

// --- Auto-check on load ---
(async function init() {
  // Check current state
  try {
    const res = await fetch(`${API_BASE}/setup.php`);
    const data = await res.json();
    if (data.admin_exists) {
      // Already fully set up
      completeMsg.textContent = 'Chat application is already configured.';
      document.querySelector('.setup-head p').textContent = 'Everything is ready to go.';
      goToStep(4);
      checksPassed = true;
      dbReady = true;
      adminCreated = true;
      return;
    }
    if (data.db_exists) {
      dbReady = true;
      dbStatus.className = 'setup-msg success';
      dbStatus.textContent = 'Database exists. You can continue.';
      dbNextBtn.classList.remove('hidden');
    }
  } catch (e) {
    // Will show step 1
  }

  // If admin already exists via file-based users.json
  try {
    const ures = await fetch(`${API_BASE}/users.json`);
    const users = await ures.json();
    const hasAdmin = users.some(u => u.role === 'admin');
    if (hasAdmin && !adminCreated) {
      // File-based admin exists - database setup needed
    }
  } catch (e) {}

  goToStep(1);
})();
