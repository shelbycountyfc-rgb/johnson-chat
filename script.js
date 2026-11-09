const API_BASE = './api';

async function apiFetch(url, opts) {
  for (let attempt = 0; attempt < 3; attempt++) {
    let res;
    try {
      res = await fetch(url, opts);
    } catch (err) {
      throw new Error('NETWORK');
    }
    const text = await res.text();
    if (text.indexOf('slowAES') !== -1 || text.indexOf('aes.js') !== -1) {
      if (attempt < 2) { await new Promise(r => setTimeout(r, 2500)); continue; }
      throw new Error('BOTCHECK');
    }
    try {
      return { res, data: JSON.parse(text) };
    } catch (err) {
      throw new Error('BADJSON');
    }
  }
}
let token = localStorage.getItem('chat_token');
let username = localStorage.getItem('chat_username') || '';
let userRole = '';
let lastTimestamp = 0;
let pollInterval = null;

const authScreen = document.getElementById('auth-screen');
const activationScreen = document.getElementById('activation-screen');
const chatScreen = document.getElementById('chat-screen');
const displayUsername = document.getElementById('display-username');
const messagesContainer = document.getElementById('messages');
const loadOlderWrap = document.getElementById('load-older-wrap');
const loadOlderBtn = document.getElementById('load-older-btn');
const notifyBtn = document.getElementById('notify-btn');
const messageInput = document.getElementById('message-input');
const sendBtn = document.getElementById('send-btn');
const logoutBtn = document.getElementById('logout-btn');
const uploadBtn = document.getElementById('upload-btn');
const fileInput = document.getElementById('file-input');
const roomSelect = document.getElementById('room-select');
const roomCreateBtn = document.getElementById('room-create-btn');
const footerEl = document.getElementById('copyright-footer');

// CSP forbids inline handlers, so bind the header avatar here.
const headerAvatar = document.getElementById('header-avatar');
if (headerAvatar) {
  headerAvatar.addEventListener('click', () => openProfile(displayUsername.textContent));
}

// Inbox
const inboxBtn = document.getElementById('inbox-btn');
const inboxBadge = document.getElementById('inbox-badge');
const inboxPanel = document.getElementById('inbox-panel');
const inboxList = document.getElementById('inbox-list');
const inboxClose = document.getElementById('inbox-close');
const inboxClear = document.getElementById('inbox-clear');

let currentRoom = 'general';
let activeUsers = [];
let offlineMessages = [];

// Unread / appearance / messaging state
let roomsData = [];
let roomUnread = {};
let privateUnread = {};
let renderedMsgs = new Map();
let renderedPrivateMsgs = new Map();
let currentTyping = [];
let privateTyping = [];
let lastMarkRead = 0;
let myBlocked = new Set();
let friendUnread = {};
let friendPreview = {};
let privatePeer = '';
let privatePeerBlocked = false;
let lastActiveSearch = '';
let FRIEND_PREVIEWS = {};

// History pagination + desktop notifications
let historyOldestTs = 0;
let historyHasMore = false;
let historyLoading = false;
let notifyEnabled = localStorage.getItem('chat_notify') === '1';

// Tab switching
const tabLogin = document.getElementById('tab-login');
const tabSignup = document.getElementById('tab-signup');
const loginForm = document.getElementById('login-form');
const signupForm = document.getElementById('signup-form');

tabLogin.addEventListener('click', () => {
  tabLogin.classList.add('active');
  tabSignup.classList.remove('active');
  loginForm.classList.remove('hidden');
  signupForm.classList.add('hidden');
});

tabSignup.addEventListener('click', () => {
  tabSignup.classList.add('active');
  tabLogin.classList.remove('active');
  signupForm.classList.remove('hidden');
  loginForm.classList.add('hidden');
});

// Login
const loginUsername = document.getElementById('login-username');
const loginPassword = document.getElementById('login-password');
const loginBtn = document.getElementById('login-btn');
const loginError = document.getElementById('login-error');

loginBtn.addEventListener('click', login);
loginPassword.addEventListener('keydown', (e) => { if (e.key === 'Enter') login(); });

async function login() {
  if (!loginUsername || !loginPassword || !loginError) {
    console.error('Login elements not found', {loginUsername, loginPassword, loginError});
    return;
  }
  const inputUsername = loginUsername.value.trim();
  const password = loginPassword.value.trim();
  if (!inputUsername || !password) return;

  loginBtn.disabled = true;
  loginError.classList.add('hidden');
  try {
    const { res, data } = await apiFetch(`${API_BASE}/login.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username: inputUsername, password }),
    });
    if (res.ok) {
      token = data.token;
      username = data.username;
      userRole = data.role || 'user';
      localStorage.setItem('chat_token', token);
      localStorage.setItem('chat_username', username);
      offlineMessages = data.offline_messages || [];
      enterChat();
    } else {
      loginError.textContent = data.error || 'Login failed';
      loginError.classList.remove('hidden');
    }
  } catch (err) {
    console.error('Login error:', err);
    loginError.textContent = err.message === 'BOTCHECK'
      ? 'Host bot-check page is active - click Refresh once, then try logging in again.'
      : 'Connection error - check console (F12)';
    loginError.classList.remove('hidden');
  } finally {
    loginBtn.disabled = false;
  }
}

// IP detection
let userIP = '';

apiFetch(`${API_BASE}/ip.php`)
  .then(({ data }) => { userIP = data.ip; document.getElementById('signup-ip').textContent = data.display; })
  .catch(() => document.getElementById('signup-ip').textContent = 'Unknown');

// Signup
const signupName = document.getElementById('signup-name');
const signupEmail = document.getElementById('signup-email');
const signupUsername = document.getElementById('signup-username');
const signupPassword = document.getElementById('signup-password');
const signupConfirm = document.getElementById('signup-confirm');
const signupBtn = document.getElementById('signup-btn');
const signupError = document.getElementById('signup-error');
const signupSuccess = document.getElementById('signup-success');

signupBtn.addEventListener('click', signup);
signupConfirm.addEventListener('keydown', (e) => { if (e.key === 'Enter') signup(); });

async function signup() {
  const fullName = signupName.value.trim();
  const email = signupEmail.value.trim();
  const sUsername = signupUsername.value.trim();
  const password = signupPassword.value.trim();
  const confirm = signupConfirm.value.trim();

  if (!email || !sUsername || !password || !confirm) {
    signupError.textContent = 'Email, username, and password are required';
    signupError.classList.remove('hidden');
    signupSuccess.classList.add('hidden');
    return;
  }
  if (password !== confirm) {
    signupError.textContent = 'Passwords do not match';
    signupError.classList.remove('hidden');
    signupSuccess.classList.add('hidden');
    return;
  }

  signupBtn.disabled = true;
  signupError.classList.add('hidden');
  signupSuccess.classList.add('hidden');
  try {
    const { res, data } = await apiFetch(`${API_BASE}/signup.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ fullName, email, username: sUsername, password, ip: userIP }),
    });
    if (res.ok) {
      signupSuccess.textContent = 'Account created! You can now log in.';
      signupSuccess.classList.remove('hidden');

      signupName.value = '';
      signupEmail.value = '';
      signupUsername.value = '';
      signupPassword.value = '';
      signupConfirm.value = '';
      signupError.classList.add('hidden');
      tabLogin.click();
    } else {
      signupError.textContent = data.error || 'Signup failed';
      signupError.classList.remove('hidden');
    }
  } catch (err) {
    signupError.textContent = err.message === 'BOTCHECK'
      ? 'Host bot-check page is active - click Refresh once, then try again.'
      : 'Connection error - check console (F12)';
    signupError.classList.remove('hidden');
  } finally {
    signupBtn.disabled = false;
  }
}

// Activation screen
document.getElementById('back-to-login-btn').addEventListener('click', () => {
  activationScreen.classList.add('hidden');
  authScreen.classList.remove('hidden');
  loginUsername.value = '';
  loginPassword.value = '';
  loginUsername.focus();
});

// Chat
sendBtn.addEventListener('click', sendMessage);
messageInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
  setTimeout(autoGrowInput, 0);
});
logoutBtn.addEventListener('click', logout);
uploadBtn.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', uploadFile);
roomSelect.addEventListener('change', () => switchRoom(roomSelect.value));
roomCreateBtn.addEventListener('click', createRoom);

// ===== Windows-style window chrome & mobile drawers =====
const usersPanel = document.getElementById('users-panel');
const friendsPanel = document.getElementById('friends-panel');
const panelScrim = document.getElementById('panel-scrim');
const usersToggleBtn = document.getElementById('users-toggle-btn');
const friendsToggleBtn = document.getElementById('friends-toggle-btn');
const winMinBtn = document.getElementById('win-min-btn');
const winMaxBtn = document.getElementById('win-max-btn');
const winCloseBtn = document.getElementById('win-close-btn');
const winPill = document.getElementById('win-pill');

function closePanels() {
  usersPanel.classList.remove('open');
  friendsPanel.classList.remove('open');
  usersToggleBtn.classList.remove('active');
  friendsToggleBtn.classList.remove('active');
  panelScrim.classList.add('hidden');
}

if (usersToggleBtn) usersToggleBtn.addEventListener('click', () => {
  if (usersPanel.classList.contains('open')) { closePanels(); return; }
  friendsPanel.classList.remove('open');
  friendsToggleBtn.classList.remove('active');
  usersPanel.classList.add('open');
  usersToggleBtn.classList.add('active');
  panelScrim.classList.remove('hidden');
  uiOpen('users');
});
if (friendsToggleBtn) friendsToggleBtn.addEventListener('click', () => {
  if (friendsPanel.classList.contains('open')) { closePanels(); return; }
  usersPanel.classList.remove('open');
  usersToggleBtn.classList.remove('active');
  friendsPanel.classList.add('open');
  friendsToggleBtn.classList.add('active');
  panelScrim.classList.remove('hidden');
  uiOpen('friends');
});
if (panelScrim) panelScrim.addEventListener('click', closePanels);

// ===== ANDROID / MOBILE BACK BUTTON =====
// Android's hardware back key closes the topmost mobile UI layer (drawer,
// private chat, call, incoming popup) instead of exiting the page.
const uiStack = [];
function uiOpen(layer) {
  if (uiStack.indexOf(layer) === -1) {
    uiStack.push(layer);
    try { history.pushState({ ui: layer }, ''); } catch (e) {}
  }
}
function uiCloseTop() {
  if (uiStack.length === 0) return;
  const top = uiStack.pop();
  if (top === 'users' || top === 'friends') { closePanels(); return; }
  if (top === 'private') { closePrivateChat(); return; }
  if (top === 'call') { endPrivateCall(callPhase !== 'idle'); return; }
  if (top === 'incoming') { hideIncomingCall(); }
}
window.addEventListener('popstate', uiCloseTop);
if (winMinBtn) winMinBtn.addEventListener('click', () => {
  chatScreen.classList.add('minimized');
  winPill.classList.remove('hidden');
});
if (winPill) winPill.addEventListener('click', () => {
  chatScreen.classList.remove('minimized');
  winPill.classList.add('hidden');
});
if (winMaxBtn) winMaxBtn.addEventListener('click', () => {
  if (!document.fullscreenElement) {
    document.documentElement.requestFullscreen().catch(() => {});
    winMaxBtn.classList.add('maximized');
  } else {
    document.exitFullscreen().catch(() => {});
    winMaxBtn.classList.remove('maximized');
  }
});
if (winCloseBtn) winCloseBtn.addEventListener('click', () => {
  if (confirm('Close JohnsonChat? You will be logged out.')) logout();
});

inboxBtn.addEventListener('click', toggleInbox);
inboxClose.addEventListener('click', closeInbox);
inboxClear.addEventListener('click', clearInbox);
document.addEventListener('click', (e) => {
  if (!inboxPanel.classList.contains('hidden') && !e.target.closest('#inbox-panel') && e.target !== inboxBtn) {
    closeInbox();
  }
});

const usersListEl = document.getElementById('users-list');
const muteBanner = document.getElementById('mute-banner');

const ONBOARDING_KEY = 'chat_onboarding_done';

function enterChat() {
  displayUsername.textContent = username;
  authScreen.classList.add('hidden');
  activationScreen.classList.add('hidden');
  chatScreen.classList.remove('hidden');
  if (footerEl) footerEl.style.display = 'none';
  messageInput.focus();
  currentRoom = 'general';
  fetchRooms().then(applyDeepLink);
  registerServiceWorker();

  const onboardingTips = [
    '💡 Right-click on any user to private chat, send files, or moderate',
    '💡 Click the + button next to rooms to create a new chat room',
    '💡 Use the Friends panel on the right to add and track friends',
    '💡 Click the inbox icon (📫) to see offline messages',
    '💡 Play Tic-Tac-Toe, Hangman, Connect 4 & more in private chats via the 🎮 button'
  ];

  const seen = localStorage.getItem(ONBOARDING_KEY);
  if (!seen) {
    addSystemMessage(`Welcome to JohnsonChat, ${username}! 🎉`);
    onboardingTips.forEach(t => setTimeout(() => addSystemMessage(t), 300));
    localStorage.setItem(ONBOARDING_KEY, '1');
  } else {
    addSystemMessage(`Welcome back, ${username}!`);
  }

  startPolling();
  fetchActiveUsers();
  fetchFriends();
  fetchUserStatus();
  checkOfflineMessages();
  checkPrivateRequests();
  showOfflineNotification();
  fetchBlockedList();
  fetchUnreadState();
  applySavedTheme();
  syncNotifyBtn();
  if (notifyEnabled) enablePushNotifications().catch(() => {});
}

function logout() {
  if (token) {
    fetch(`${API_BASE}/logout.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token }),
    }).catch(() => {});
  }
  stopPolling();
  endPrivateChat();
  reqNotification.classList.add('hidden');
  closeInbox();
  currentRoom = 'general';
  roomSelect.innerHTML = '';
  token = null;
  username = '';
  userRole = '';
  lastTimestamp = 0;
  offlineMessages = [];
  renderedMsgs.clear();
  renderedPrivateMsgs.clear();
  roomUnread = {};
  privateUnread = {};
  friendUnread = {};
  FRIEND_PREVIEWS = {};
  myBlocked.clear();
  currentTyping = [];
  privateTyping = [];
  historyOldestTs = 0;
  historyHasMore = false;
  historyLoading = false;
  updateHistoryButton();
  document.title = 'JohnsonChat — Real-time Messaging';
  localStorage.removeItem('chat_token');
  localStorage.removeItem('chat_username');
  messagesContainer.innerHTML = '';
  chatScreen.classList.add('hidden');
  if (footerEl) footerEl.style.display = '';
  authScreen.classList.remove('hidden');
  loginUsername.value = '';
  loginPassword.value = '';
  loginUsername.focus();
}

// ===== OFFLINE / INBOX =====
function showOfflineNotification() {
  updateInboxBadge();
  if (offlineMessages.length > 0) {
    const count = offlineMessages.length;
    const names = [...new Set(offlineMessages.map(m => m.from))];
    const namesStr = names.slice(0, 3).join(', ') + (names.length > 3 ? ` +${names.length - 3}` : '');
    addSystemMessage(`You have ${count} offline message${count > 1 ? 's' : ''} from ${namesStr} (click inbox icon to view)`);
  }
}

function updateInboxBadge() {
  const count = offlineMessages.length;
  if (count > 0) {
    inboxBadge.textContent = count;
    inboxBadge.classList.remove('hidden');
  } else {
    inboxBadge.classList.add('hidden');
  }
}

function toggleInbox() {
  if (inboxPanel.classList.contains('hidden')) {
    renderInbox();
    inboxPanel.classList.remove('hidden');
  } else {
    closeInbox();
  }
}

function closeInbox() {
  inboxPanel.classList.add('hidden');
}

function renderInbox() {
  inboxList.innerHTML = '';
  if (offlineMessages.length === 0) return;

  offlineMessages.forEach((msg, idx) => {
    const item = document.createElement('div');
    item.className = 'inbox-item';
    item.innerHTML = `<div class="inbox-item-from">${escHtml(msg.from)}</div>
      <div class="inbox-item-preview">${linkify(msg.message_snippet)}</div>
      <div class="inbox-item-time">${escHtml(msg.timestamp)}</div>`;
    item.addEventListener('click', () => {
      openPrivateChat(msg.room_id, msg.from);
      removeInboxItem(idx);
      closeInbox();
    });
    inboxList.appendChild(item);
  });
}

function removeInboxItem(idx) {
  const msg = offlineMessages[idx];
  if (!msg) return;
  offlineMessages.splice(idx, 1);
  renderInbox();
  updateInboxBadge();
  fetch(`${API_BASE}/clear-offline.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, room_id: msg.room_id }),
  }).catch(() => {});
}

function clearInbox() {
  fetch(`${API_BASE}/clear-offline.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token }),
  }).catch(() => {});
  offlineMessages = [];
  renderInbox();
  updateInboxBadge();
  closeInbox();
}

async function checkOfflineMessages() {
  if (!username) return;
  try {
    const res = await fetch(`${API_BASE}/offline-check.php?username=${encodeURIComponent(username)}&token=${encodeURIComponent(token || '')}`);
    const data = await res.json();
    if (data.messages) {
      offlineMessages = data.messages;
      updateInboxBadge();
    }
  } catch (e) {}
}

async function sendOfflineMessage(target) {
  const text = prompt(`Send a private message to ${target} (they are offline):`);
  if (!text || !text.trim()) return;
  try {
    const res = await fetch(`${API_BASE}/send-offline.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, target, message: text.trim() }),
    });
    const data = await res.json();
    if (data.success) {
      openPrivateChat(data.room_id, target);
    } else {
      addSystemMessage(`Error: ${data.error}`);
    }
  } catch (err) {
    addSystemMessage('Failed to send offline message');
  }
}

function autoGrowInput() {
  messageInput.style.height = 'auto';
  messageInput.style.height = Math.min(messageInput.scrollHeight, 160) + 'px';
}

async function sendMessage() {
  const text = messageInput.value.trim();
  if (!text || !username) return;

  sendBtn.disabled = true;
  try {
    const res = await fetch(`${API_BASE}/send.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, message: text, room_id: currentRoom, token, reply_to: publicReply ? publicReply.id : '' }),
    });
    if (res.ok) {
      messageInput.value = '';
      cancelReply('public');
      autoGrowInput();
      messageInput.focus();
    } else {
      const data = await res.json();
      if (data.error === 'You are muted' || data.error === 'You are banned') {
        addSystemMessage(`!!! ${data.error} !!!`);
      }
    }
  } catch (err) {
    console.error('Send failed:', err);
  } finally {
    sendBtn.disabled = false;
  }
}

async function uploadFile() {
  const file = fileInput.files[0];
  if (!file || !username) return;

  uploadBtn.disabled = true;
  const formData = new FormData();
  formData.append('file', file);
  formData.append('username', username);
  formData.append('room_id', currentRoom);
  formData.append('token', token);

  try {
    const res = await fetch(`${API_BASE}/upload.php`, {
      method: 'POST',
      body: formData,
    });
    if (!res.ok) {
      const data = await res.json();
      addSystemMessage(`Upload failed: ${data.error || 'Unknown error'}`);
    }
  } catch (err) {
    addSystemMessage('Upload failed: Connection error');
  } finally {
    uploadBtn.disabled = false;
    fileInput.value = '';
  }
}

let tickInterval = null;
let tickCount = 0;

function startPolling() {
  lastTimestamp = 0;
  messagesContainer.innerHTML = '';
  historyOldestTs = 0;
  historyHasMore = false;
  historyLoading = false;
  updateHistoryButton();
  tickCount = 0;
  tickInterval = setInterval(mainTick, 1000);
  mainTick();
}

function stopPolling() {
  if (tickInterval) {
    clearInterval(tickInterval);
    tickInterval = null;
  }
}

async function mainTick() {
  tickCount++;
  await pollMessages();
  if (username) {
    if (tickCount % 2 === 0) fetchActiveUsers();
    if (tickCount % 5 === 0) fetchUserStatus();
    if (tickCount % 3 === 0) fetchFriends();
    if (tickCount % 5 === 1) checkOfflineMessages();
    if (tickCount % 5 === 2) checkPrivateRequests();
    if (tickCount % 5 === 3) fetchUnreadState();
  }
}

async function pollMessages() {
  try {
    const wasInitial = lastTimestamp === 0;
    const res = await fetch(`${API_BASE}/poll.php?since=${lastTimestamp}&room_id=${currentRoom}&token=${encodeURIComponent(token || '')}`);
    const data = await res.json();
    if (data.messages && data.messages.length > 0) {
      data.messages.forEach(msg => addMessage(msg));
      if (wasInitial) {
        historyOldestTs = Number(data.messages[0].timestamp) || 0;
        historyHasMore = !!data.has_more;
        updateHistoryButton();
        // Land on the newest message, not the oldest in the window.
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
      }
      // Read receipts: mark current watch room read (throttled) once poll adds messages
      maybeMarkRoomRead();
    } else if (wasInitial) {
      historyOldestTs = 0;
      historyHasMore = false;
      updateHistoryButton();
    }
    if (Array.isArray(data.typing)) {
      currentTyping = data.typing.filter(u => u.toLowerCase() !== (username || '').toLowerCase());
      renderPublicTyping();
    }
    if (Array.isArray(data.pins)) renderPinnedBar(data.pins);
    lastTimestamp = data.server_time;
  } catch (err) {
    console.error('Poll failed:', err);
  }
}

function escHtml(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function escAttr(s) {
  return String(s == null ? '' : s).replace(/"/g, '&quot;');
}

function formatText(text) {
  const html = linkify(text);
  const me = (username || '').toLowerCase();
  return html.replace(/(^|[\s(>])@([a-zA-Z0-9_]{1,20})/g, (match, lead, name) => {
    const self = name.toLowerCase() === me;
    return `${lead}<span class="mention${self ? ' mention-me' : ''}">@${escHtml(name)}</span>`;
  });
}

function linkify(text) {
  const raw = String(text == null ? '' : text);
  const urlRegex = /(https?:\/\/[^\s<>"']+|(?:www\.)[^\s<>"']+)/gi;
  let out = '';
  let last = 0;
  let m;
  while ((m = urlRegex.exec(raw)) !== null) {
    out += escHtml(raw.slice(last, m.index));
    const url = m[0];
    const href = (url.startsWith('http') ? url : 'https://' + url).replace(/[<>']/g, (c) => c === '<' ? '&lt;' : c === '>' ? '&gt;' : '&#39;');
    out += `<a href="${escAttr(href)}" target="_blank" rel="noopener" style="color:#4fc3f7;text-decoration:underline">${escHtml(url)}</a>`;
    last = m.index + m[0].length;
  }
  out += escHtml(raw.slice(last));
  return out;
}

function formatSize(bytes) {
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
  if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return bytes + ' B';
}

function addMessage(msg, prepend = false) {
  const isOwn = msg.username === username;
  const existing = renderedMsgs.get(msg.id);

  if (!msg.deleted) {
    if (existing && existing.msgTimestamp === msg.timestamp) {
      // Only refresh if this poll actually carries an edit/update
      let changed = JSON.stringify(msg) !== JSON.stringify(existing.lastRaw);
      if (changed) {
        existing.lastRaw = JSON.parse(JSON.stringify(msg));
        rebuildMessageBubble(existing.el, msg, isOwn);
      }
      return;
    }
  } else {
    if (existing) {
      existing.lastRaw = JSON.parse(JSON.stringify(msg));
      rebuildMessageBubble(existing.el, msg, isOwn);
      return;
    }
  }

  const div = document.createElement('div');
  div.dataset.chatMsg = 'public';
  div.dataset.msgId = msg.id;
  rebuildMessageBubble(div, msg, isOwn);
  if (prepend) {
    messagesContainer.insertBefore(div, messagesContainer.firstChild);
  } else {
    messagesContainer.appendChild(div);
  }
  renderedMsgs.set(msg.id, { el: div, msgTimestamp: msg.timestamp, lastRaw: JSON.parse(JSON.stringify(msg)) });
  if (!prepend) {
    const nearBottom = messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight < 80;
    if (nearBottom) messagesContainer.scrollTop = messagesContainer.scrollHeight;
    if (!isOwn && !msg.deleted) maybeNotify(msg, 'public');
  }
}

function buildContentParts(msg) {
  let content = '';
  if (msg.reply) {
    content += `<div class="reply-quote" data-jump-msg="${escAttr(msg.reply.id)}" title="Jump to replied message"><strong>${escHtml(msg.reply.username)}</strong><span>${msg.reply.deleted ? 'deleted message' : escHtml(msg.reply.message)}</span></div>`;
  }
  content += `<div class="msg-text">${formatText(msg.message)}</div>`;

  if (msg.file) {
    const isImage = msg.file.type && msg.file.type.startsWith('image/');
    const dl = `${API_BASE}/download.php?token=${escAttr(msg.file.token)}`;
    if (isImage) {
      content += `<img src="${dl}" class="file-preview" alt="${escAttr(msg.file.name)}" data-lightbox="${dl}" data-fname="${escAttr(msg.file.name)}" title="Click to enlarge">`;
    } else {
      content += `<div class="file-attach">
        <span class="file-icon">${isImage ? '🖼' : '📎'}</span>
        <div class="file-info">
          <div class="file-name">${escHtml(msg.file.name)}</div>
          <div class="file-size">${formatSize(msg.file.size)}</div>
        </div>
        <a href="${dl}" class="file-dl-btn" target="_blank">Download</a>
      </div>`;
    }
  }

  if (msg.read) {
    content += `<span class="read-tick" title="Read">✓✓</span>`;
  }

  content += `<div class="reactions" data-msg-id="${escAttr(msg.id)}">`;
  if (msg.reactions && Object.keys(msg.reactions).length) {
    for (const [emoji, users] of Object.entries(msg.reactions)) {
      const mine = users.some(u => u.toLowerCase() === (username || '').toLowerCase());
      content += `<span class="reaction-chip${mine ? ' mine' : ''}" data-msg-id="${escAttr(msg.id)}" data-emoji="${escAttr(emoji)}" title="${escAttr(users.join(', '))}">${escHtml(emoji)} ${users.length}</span>`;
    }
  }
  content += `<button class="msg-action reaction-add" data-msg-id="${escAttr(msg.id)}" title="Add reaction">➕</button>`;
  content += `</div>`;

  return content;
}

function rebuildMessageBubble(div, msg, isOwn) {
  const id = escAttr(msg.id);
  const fileActions = msg.file && isOwn
    ? ` <button class="msg-action" data-act="del-file" data-msg="${id}" title="Delete file">🗑</button>`
    : '';
  const canPin = canPinCurrentRoom();
  const actions = `<div class="msg-actions">
        <button class="msg-action" data-act="reply" data-msg="${id}" title="Reply">↩</button>
        <button class="msg-action" data-act="link" data-msg="${id}" title="Copy link">🔗</button>
        ${canPin ? `<button class="msg-action" data-act="pin" data-msg="${id}" title="Pin message">📌</button>` : ''}
        ${isOwn ? `<button class="msg-action" data-act="edit" data-msg="${id}" title="Edit">✎</button>
        <button class="msg-action" data-act="del" data-msg="${id}" title="Delete">🗑</button>${fileActions}` : ''}
      </div>`;
  if (msg.deleted) {
    div.className = 'message ' + (isOwn ? 'own' : 'other') + ' message-deleted';
    div.innerHTML = `<div class="msg-deleted-line">🕳 This message was deleted</div>`;
    return;
  }
  div.className = 'message ' + (isOwn ? 'own' : 'other');
  const senderLine = isOwn
    ? `<div class="msg-user">You</div>${actions}`
    : `<div class="msg-user" data-username="${escAttr(msg.username)}">${escHtml(msg.username)}</div>${actions}`;
  div.innerHTML = `${senderLine}${buildContentParts(msg)}<div class="msg-time">${escHtml(msg.time)}</div>`;
}

// Reaction + edit/delete delegated handlers (messages container)
messagesContainer.addEventListener('click', async (e) => {
  const quote = e.target.closest('.reply-quote');
  if (quote) {
    jumpToMessage(quote.dataset.jumpMsg);
    return;
  }
  const chip = e.target.closest('.reaction-chip');
  if (chip) {
    toggleReaction(chip.dataset.msgId, chip.dataset.emoji, 'public');
    return;
  }
  const addBtn = e.target.closest('.reaction-add');
  if (addBtn) {
    e.stopPropagation();
    toggleReactionPalette(addBtn.dataset.msgId, addBtn);
    return;
  }
  const actionBtn = e.target.closest('.msg-action');
  if (actionBtn) {
    const msgId = actionBtn.dataset.msg;
    const act = actionBtn.dataset.act;
    if (act === 'edit') startEditMessage(msgId, 'public');
    else if (act === 'del') pushMessageAction('public', msgId, act);
    else if (act === 'reply') startReply('public', msgId);
    else if (act === 'link') copyMessageLink(msgId);
    else if (act === 'pin') pinPublicMessage(msgId);
    else if (act === 'add-reaction') {
      actionBtn.classList.add('hidden');
      const target = document.querySelector(`[data-msg-id="${CSS.escape(msgId)}"] .reactions`);
      openReactionPalette(msgId, target || actionBtn.parentElement);
    }
    return;
  }
  const palette = e.target.closest('.reaction-palette .rp-emoji');
  if (palette) {
    toggleReaction(palette.dataset.msgId, palette.dataset.emoji, 'public');
    return;
  }
});

// In-place edit UI for public + private messages
function startEditMessage(msgId, kind) {
  const container = kind === 'public' ? messagesContainer : privateMessages;
  const el = (kind === 'public' ? renderedMsgs : renderedPrivateMsgs).get(msgId);
  const rec = el && el.el;
  const textEl = rec ? rec.querySelector('.msg-text') : null;
  if (!textEl) return;
  textEl.style.display = 'none';
  const wrap = document.createElement('div');
  wrap.className = 'edit-wrap';
  const ta = document.createElement('textarea');
  ta.className = 'edit-textarea';
  ta.maxLength = 500;
  ta.value = textEl.textContent;
  const save = document.createElement('button');
  save.className = 'edit-save';
  save.textContent = 'Save';
  const cancel = document.createElement('button');
  cancel.className = 'edit-cancel';
  cancel.textContent = 'Cancel';
  save.onclick = async () => {
    const text = ta.value.trim();
    if (text) pushMessageAction(kind, msgId, 'edit', text);
    else wrap.remove();
  };
  cancel.onclick = () => wrap.remove();
  ta.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); save.click(); }
    else if (e.key === 'Escape') cancel.click();
  });
  wrap.appendChild(ta);
  wrap.appendChild(save);
  wrap.appendChild(cancel);

  if (textEl.parentNode) textEl.parentNode.appendChild(wrap);
  ta.focus();
}

function toggleReactionPalette(msgId, anchorBtn) {
  const row = anchorBtn.closest ? anchorBtn.closest('.reactions') : null;
  openReactionPalette(msgId, row || anchorBtn);
}

function openReactionPalette(msgId, anchor) {
  // Anchor chooses container scope based on message dataset
  const container = anchor && anchor.closest('#messages') ? messagesContainer : privateMessages;
  const kind = container === messagesContainer ? 'public' : 'private';
  const existing = anchor.querySelector('.reaction-palette');
  if (existing) { existing.remove(); return; }
  const pal = document.createElement('div');
  pal.className = 'reaction-palette';
  ['👍','❤️','😂','😮','😢','🔥'].forEach(emoji => {
    const b = document.createElement('span');
    b.className = 'rp-emoji';
    b.textContent = emoji;
    b.dataset.msgId = msgId;
    b.dataset.emoji = emoji;
    b.dataset.kind = kind;
    pal.appendChild(b);
  });
  anchor.appendChild(pal);
  pal.style.left = Math.min(anchor.getBoundingClientRect().right - 210, window.innerWidth - 210) + 'px';
  pal.style.top = (anchor.getBoundingClientRect().bottom + 4) + 'px';
}

async function toggleReaction(msgId, emoji, kind) {
  if (!token) return;
  const roomId = kind === 'private' ? currentPrivateRoom : currentRoom;
  if (!roomId) return;
  try {
    const res = await fetch(`${API_BASE}/react.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, message_id: msgId, emoji, kind, room_id: roomId }),
    });
    const data = await res.json();
    if (!data.success && data.error) addSystemMessage('Reaction error: ' + data.error);
  } catch (err) {}
}

async function pushMessageAction(kind, msgId, act, textMsg) {
  if (!token) return;
  const action = act === 'edit' ? 'edit' : 'delete';
  const roomId = kind === 'private' ? currentPrivateRoom : currentRoom;
  try {
    const res = await fetch(`${API_BASE}/message-action.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, action, message_id: msgId, kind, room_id: roomId, message: textMsg }),
    });
    const data = await res.json();
    if (!data.success && data.error) addSystemMessage('Action error: ' + data.error);
  } catch (err) {}
}



async function fetchUserStatus() {
  if (!username) return;
  try {
    const res = await fetch(`${API_BASE}/user-status.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username }),
    });
    const data = await res.json();
    if (data.banned_until) {
      if (data.banned_until === 'indefinite') {
        addSystemMessage('!!! You have been banned indefinitely !!!');
      } else {
        addSystemMessage(`!!! You have been banned until ${data.banned_until} !!!`);
      }
      logout();
      return;
    }
    if (data.muted_until) {
      if (data.muted_until === 'indefinite') {
        muteBanner.textContent = 'You are muted indefinitely';
      } else {
        muteBanner.textContent = `You are muted until ${data.muted_until}`;
      }
      muteBanner.style.display = 'block';
    } else {
      muteBanner.style.display = 'none';
    }
  } catch (err) {
    console.error('Status check failed:', err);
  }
}

let _lastActiveUsersKey = '';

async function fetchActiveUsers() {
  try {
    const res = await fetch(`${API_BASE}/active-users.php`);
    const data = await res.json();
    if (data.users) {
      const key = data.users.join('|');
      if (key !== _lastActiveUsersKey) {
        _lastActiveUsersKey = key;
        activeUsers = data.users;
        const statuses = await Promise.all(data.users.map(u =>
          fetch(`${API_BASE}/user-status.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: u }),
          }).then(r => r.json()).catch(() => ({}))
        ));
        const profileInfos = await Promise.all(data.users.map(u =>
          fetch(`${API_BASE}/profile.php?username=${encodeURIComponent(u)}`)
            .then(r => r.json())
            .then(d => d.user || d)
            .catch(() => ({}))
        ));
        usersListEl.innerHTML = '';
        data.users.forEach((u, i) => {
          const div = document.createElement('div');
          div.className = 'user-entry';
          div.dataset.username = u;
          const dot = document.createElement('span');
          dot.className = 'dot';
          div.appendChild(dot);
          const nameSpan = document.createElement('span');
          nameSpan.className = 'user-name';
          const info = profileInfos[i] || {};
          const displayName = (info.full_name || '').trim();
          nameSpan.textContent = u;
          nameSpan.title = displayName && displayName !== u ? `${u} — ${displayName}` : u;
          div.appendChild(nameSpan);
          const role = statuses[i]?.role;
          if (role === 'admin') {
            const badge = document.createElement('span');
            badge.className = 'admin-badge';
            badge.textContent = 'ADMIN';
            div.appendChild(badge);
          } else if (role === 'moderator') {
            const badge = document.createElement('span');
            badge.className = 'mod-badge';
            badge.textContent = 'MOD';
            div.appendChild(badge);
          }
          usersListEl.appendChild(div);
        });
      }
    }
  } catch (err) {
    console.error('Active users poll failed:', err);
  }
}

// Room functions
async function fetchRooms() {
  try {
    const res = await fetch(`${API_BASE}/rooms.php`);
    const data = await res.json();
    if (data.rooms) {
      const prevVal = roomSelect.value;
      roomsData = data.rooms;
      roomSelect.innerHTML = data.rooms.map(r => {
        const un = roomUnread[r.id] || 0;
        return `<option value="${escAttr(r.id)}">${escHtml(r.name)}${un ? ' (' + un + ')' : ''}</option>`;
      }).join('');
      const keep = data.rooms.some(r => r.id === currentRoom)
        ? currentRoom
        : (data.rooms.some(r => r.id === prevVal) ? prevVal : 'general');
      currentRoom = keep;
      roomSelect.value = keep;
      refreshRoomBadge();
    }
  } catch (err) {}
}

async function switchRoom(roomId) {
  if (roomId === currentRoom) return;
  currentRoom = roomId;
  stopPolling();
  renderPinnedBar([]);
  messagesContainer.innerHTML = '';
  renderedMsgs.clear();
  startPolling();
  maybeMarkRoomRead(true);
  refreshRoomBadge();
}

async function createRoom() {
  document.getElementById('room-modal-error').classList.add('hidden');
  document.getElementById('room-name-input').value = '';
  const announceCheck = document.getElementById('room-announce-check');
  const isStaff = userRole === 'admin' || userRole === 'moderator';
  const wrap = document.getElementById('announce-check-wrap');
  if (isStaff) {
    wrap.classList.remove('hidden');
    announceCheck.checked = false;
  } else {
    wrap.classList.add('hidden');
    announceCheck.checked = false;
  }
  document.getElementById('room-modal').classList.remove('hidden');
}

function submitCreateRoom() {
  const name = document.getElementById('room-name-input').value.trim();
  if (!name) return;
  const isAnnouncement = document.getElementById('announce-check-wrap').classList.contains('hidden')
    ? false
    : document.getElementById('room-announce-check').checked;
  fetch(`${API_BASE}/rooms.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, token, read_only: isAnnouncement }),
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        addSystemMessage(`Room "${data.room.name}" created!`);
        fetchRooms().then(() => { roomSelect.value = data.room.id; switchRoom(data.room.id); });
        document.getElementById('room-modal').classList.add('hidden');
      } else {
        const err = document.getElementById('room-modal-error');
        err.textContent = data.error || 'Failed to create room';
        err.classList.remove('hidden');
      }
    })
    .catch(() => {
      const err = document.getElementById('room-modal-error');
      err.textContent = 'Connection error';
      err.classList.remove('hidden');
    });
}

document.getElementById('room-modal-close').addEventListener('click', () => document.getElementById('room-modal').classList.add('hidden'));
document.getElementById('room-modal-create').addEventListener('click', submitCreateRoom);
document.getElementById('room-name-input').addEventListener('keydown', (e) => { if (e.key === 'Enter') submitCreateRoom(); });

function addSystemMessage(text) {
  const div = document.createElement('div');
  div.className = 'system-msg';
  div.textContent = text;
  messagesContainer.appendChild(div);
  messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

// Auto-login if token exists
if (token && username) {
  verifyToken();
}

async function verifyToken() {
  try {
    const res = await fetch(`${API_BASE}/verify.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token }),
    });
    const data = await res.json();
    if (data.valid) {
      username = data.username;
      userRole = data.role || 'user';
      offlineMessages = data.offline_messages || [];
      localStorage.setItem('chat_username', username);
      enterChat();
    } else {
      localStorage.removeItem('chat_token');
      localStorage.removeItem('chat_username');
    }
  } catch {
    localStorage.removeItem('chat_token');
    localStorage.removeItem('chat_username');
  }
}

// Context menu
const ctxMenu = document.getElementById('ctx-menu');
let ctxTargetUser = '';

function buildCtxMenu(targetUser) {
  const isAdmin = userRole === 'admin';
  const isMod = userRole === 'moderator';
  const canModerate = isAdmin || isMod;
  const isTargetSelf = targetUser === username;
  let items = [];

  items.push({ label: 'Private Chat', action: 'private' });
  items.push({ label: 'Send File', action: 'sendfile' });
  items.push(null);
  items.push({ label: 'Copy Username', action: 'copy' });
  if (!isTargetSelf) {
    items.push(null);
    items.push({ label: 'Add Friend', action: 'friend' });
    const blocked = myBlocked.has(targetUser);
    items.push(null);
    items.push({ label: blocked ? `Unblock ${targetUser}` : 'Block User', action: 'block', cls: blocked ? 'admin-only' : 'danger' });
  }

  if (canModerate && !isTargetSelf) {
    items.push(null);
    items.push({ label: 'Mute (1h)', action: 'admin', sub: 'mute', dur: '1h', cls: 'admin-only' });
    items.push({ label: 'Mute (24h)', action: 'admin', sub: 'mute', dur: '24h', cls: 'admin-only' });
    items.push({ label: 'Unmute', action: 'admin', sub: 'unmute', cls: 'admin-only' });
    if (isAdmin) {
      items.push(null);
      items.push({ label: 'Temp Ban (1d)', action: 'admin', sub: 'ban', dur: '1d', cls: 'danger' });
      items.push({ label: 'Temp Ban (7d)', action: 'admin', sub: 'ban', dur: '7d', cls: 'danger' });
      items.push({ label: 'Ban Indefinitely', action: 'admin', sub: 'ban', dur: 'indefinite', cls: 'danger' });
      items.push({ label: 'Lift Ban', action: 'admin', sub: 'lift_ban', cls: 'admin-only' });
      items.push(null);
      items.push({ label: 'Promote to Moderator', action: 'admin', sub: 'promote', cls: 'admin-only' });
      items.push({ label: 'Demote to User', action: 'admin', sub: 'demote', cls: 'danger' });
    }
  }

  ctxMenu.innerHTML = '';
  items.forEach(item => {
    if (item === null) {
      const sep = document.createElement('div');
      sep.className = 'ctx-separator';
      ctxMenu.appendChild(sep);
    } else {
      const el = document.createElement('div');
      el.className = 'ctx-item' + (item.cls ? ' ' + item.cls : '');
      el.textContent = item.label;
      el.dataset.action = item.action;
      if (item.sub) el.dataset.sub = item.sub;
      if (item.dur) el.dataset.dur = item.dur;
      ctxMenu.appendChild(el);
    }
  });
}

document.addEventListener('contextmenu', (e) => {
  const entry = e.target.closest('.user-entry, .msg-user');
  if (entry) {
    e.preventDefault();
    ctxTargetUser = entry.dataset.username || entry.textContent.trim();
    buildCtxMenu(ctxTargetUser);
    ctxMenu.style.left = e.clientX + 'px';
    ctxMenu.style.top = e.clientY + 'px';
    ctxMenu.classList.remove('hidden');
  }
});

document.addEventListener('click', () => {
  ctxMenu.classList.add('hidden');
});

ctxMenu.addEventListener('click', (e) => {
  const item = e.target.closest('.ctx-item');
  if (!item) return;
  const action = item.dataset.action;

  if (action === 'private') {
    if (activeUsers.includes(ctxTargetUser)) {
      startPrivateChat(ctxTargetUser);
    } else {
      sendOfflineMessage(ctxTargetUser);
    }
  } else if (action === 'sendfile') {
    sendPrivateFile(ctxTargetUser);
  } else if (action === 'copy') {
    navigator.clipboard.writeText(ctxTargetUser).catch(() => {});
  } else if (action === 'friend') {
    addFriendByName(ctxTargetUser);
  } else if (action === 'block') {
    const blocking = !myBlocked.has(ctxTargetUser);
    toggleBlockUser(ctxTargetUser, blocking);
  } else if (action === 'admin') {
    const sub = item.dataset.sub;
    const dur = item.dataset.dur;
    const body = { token, target: ctxTargetUser, action: sub };
    if (dur) body.duration = dur;
    fetch(`${API_BASE}/admin-action.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          addSystemMessage(`[Admin] ${data.message}`);
        } else {
          addSystemMessage(`[Admin] Error: ${data.error}`);
        }
      })
      .catch(() => addSystemMessage('[Admin] Request failed'));
  }

  ctxMenu.classList.add('hidden');
});

// Private chat
const reqNotification = document.getElementById('req-notification');
const reqText = document.getElementById('req-text');
const reqAccept = document.getElementById('req-accept');
const reqDecline = document.getElementById('req-decline');
const privateModal = document.getElementById('private-modal');
const privateTitle = document.getElementById('private-title');
const privateClose = document.getElementById('private-close');
const privateMessages = document.getElementById('private-messages');
const privateInput = document.getElementById('private-input');
const privateSendBtn = document.getElementById('private-send-btn');

let currentPrivateRoom = null;
let privatePollInterval = null;
let privateLastTimestamp = 0;
let pendingRequestId = null;
let pendingRequestFrom = null;
let pendingRequestTarget = null;
let pendingRequestNotice = null;
let overlay = null;
let privateFileInput = null;
let pendingSendFileTarget = null;

function showOverlay() {
  if (!overlay) { overlay = document.createElement('div'); overlay.className = 'private-overlay'; }
  document.body.appendChild(overlay);
}

function hideOverlay() {
  if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
}

async function startPrivateChat(target) {
  if (!token) return;
  try {
    const res = await fetch(`${API_BASE}/private-request.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, target }),
    });
    const data = await res.json();
    if (data.already_exists) {
      openPrivateChat(data.room_id, target);
    } else if (data.success) {
      const roomKey = [username.toLowerCase(), target.toLowerCase()].sort().join('_');
      openPrivateChat(roomKey, target);
      const notice = addPrivateNotice(`Request sent to ${target}. Waiting for them to accept...`);
      pendingRequestTarget = target;
      pendingRequestNotice = notice;
      addSystemMessage(`Private chat request sent to ${target}`);
    } else {
      addSystemMessage(`Error: ${data.error}`);
    }
  } catch (err) {
    addSystemMessage('Failed to send private request');
  }
}

function sendPrivateFile(target) {
  if (!privateFileInput) {
    privateFileInput = document.createElement('input');
    privateFileInput.type = 'file';
    privateFileInput.className = 'hidden';
    privateFileInput.addEventListener('change', async () => {
      const file = privateFileInput.files[0];
      const tgt = pendingSendFileTarget;
      privateFileInput.value = '';
      pendingSendFileTarget = null;
      if (!file || !tgt || !token) return;

      const targetLower = tgt.toLowerCase();
      const myLower = username.toLowerCase();
      const roomKey = [myLower, targetLower].sort()[0] + '_' + [myLower, targetLower].sort()[1];

      try {
        const checkRes = await fetch(`${API_BASE}/private-request.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token, target: tgt }),
        });
        const checkData = await checkRes.json();
        let rid = checkData.room_id || roomKey;

        const formData = new FormData();
        formData.append('file', file);
        formData.append('username', username);
        formData.append('room_id', rid);
        formData.append('token', token);
        const upRes = await fetch(`${API_BASE}/private-upload.php`, {
          method: 'POST',
          body: formData,
        });
        if (upRes.ok) {
          openPrivateChat(rid, tgt);
        } else {
          const e = await upRes.json();
          addSystemMessage(`File send failed: ${e.error}`);
        }
      } catch (err) {
        addSystemMessage('Failed to send file');
      }
    });
    document.body.appendChild(privateFileInput);
  }
  pendingSendFileTarget = target;
  privateFileInput.click();
}

function checkPrivateRequests() {
  if (!username) return;
  fetch(`${API_BASE}/private-check.php?username=${encodeURIComponent(username)}&token=${encodeURIComponent(token || '')}`)
    .then(r => r.json())
    .then(data => {
      if (data.requests && data.requests.length > 0) {
        const req = data.requests[0];
        pendingRequestId = req.id;
        pendingRequestFrom = req.from;
        reqAccept.textContent = 'Accept';
        reqDecline.style.display = 'inline-block';
        reqDecline.textContent = 'Decline';
        reqAccept.onclick = acceptRequest;
        reqDecline.onclick = declineRequest;
        reqText.textContent = `${req.from} wants to private chat`;
        reqNotification.dataset.type = 'request';
        reqNotification.classList.remove('hidden');
      } else if (data.file_notify && data.file_notify.length > 0) {
        const n = data.file_notify[0];
        pendingRequestId = null;
        reqAccept.textContent = 'Open';
        reqDecline.style.display = 'inline-block';
        reqDecline.textContent = 'Dismiss';
        reqText.textContent = `Incoming file from ${n.from}: ${n.file}`;
        reqAccept.onclick = () => {
          reqNotification.classList.add('hidden');
          const otherUser = n.room_id.replace(username + '_', '').replace('_' + username, '');
          openPrivateChat(n.room_id, otherUser);
        };
        reqDecline.onclick = () => { reqNotification.classList.add('hidden'); };
        reqNotification.dataset.type = 'file';
        reqNotification.classList.remove('hidden');
      } else if (data.calls && data.calls.length > 0) {
        const c = data.calls[data.calls.length - 1];
        handleIncomingRing(c.room_id, c.from, c.id, c.call_id);
      }
      // If the recipient declined, close the waiting chat and report it.
      // Checked BEFORE the outgoing/accept logic because a declined request
      // also leaves the outgoing queue.
      if (pendingRequestTarget && Array.isArray(data.denied_from)) {
        const declinedBy = data.denied_from.find(d =>
          String(d || '').toLowerCase() === String(pendingRequestTarget).toLowerCase());
        if (declinedBy) {
          if (pendingRequestNotice && pendingRequestNotice.parentNode) {
            pendingRequestNotice.textContent = `❌ ${declinedBy} declined your chat request`;
            pendingRequestNotice.classList.add('denied');
          }
          pendingRequestTarget = null;
          pendingRequestNotice = null;
          closePrivateChat();
          addSystemMessage(`${declinedBy} declined your private chat request`);
        }
      }
      // Requester side: if we sent a request and it's no longer pending, it was accepted
      if (pendingRequestTarget && Array.isArray(data.outgoing)) {
        const stillPending = data.outgoing.some(p =>
          String(p.to || '').toLowerCase() === String(pendingRequestTarget).toLowerCase());
        if (!stillPending) {
          if (pendingRequestNotice && pendingRequestNotice.parentNode) {
            pendingRequestNotice.textContent = `✅ ${pendingRequestTarget} accepted your chat request`;
            pendingRequestNotice.classList.add('accepted');
          }
          pendingRequestTarget = null;
          pendingRequestNotice = null;
        }
      }
    })
    .catch(() => {});
}

function acceptRequest() {
  if (!pendingRequestId || !token) return;
  const fromUser = pendingRequestFrom;
  reqNotification.classList.add('hidden');
  fetch(`${API_BASE}/private-respond.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, request_id: pendingRequestId, accept: true }),
  })
    .then(r => r.json())
    .then(data => {
      if (data.room_id) {
        const otherUser = data.room_id.replace(username + '_', '').replace('_' + username, '');
        openPrivateChat(data.room_id, otherUser);
      } else if (fromUser) {
        const roomKey = [username.toLowerCase(), fromUser.toLowerCase()].sort();
        openPrivateChat(roomKey[0] + '_' + roomKey[1], fromUser);
      } else {
        addSystemMessage('Request accepted but could not open the chat window.');
      }
    })
    .catch(() => {});
  pendingRequestId = null;
  pendingRequestFrom = null;
}

function declineRequest() {
  if (!pendingRequestId || !token) return;
  reqNotification.classList.add('hidden');
  fetch(`${API_BASE}/private-respond.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, request_id: pendingRequestId, accept: false }),
  }).catch(() => {});
  pendingRequestId = null;
  pendingRequestFrom = null;
}



function openPrivateChat(roomId, otherUser) {
  if (currentPrivateRoom === roomId) { privateModal.classList.remove('hidden'); return; }
  closePrivateChat();
  currentPrivateRoom = roomId;
  privatePeer = otherUser;
  privatePeerBlocked = myBlocked.has(otherUser);
  privateLastTimestamp = 0;
  privateMessages.innerHTML = '';
  renderedPrivateMsgs.clear();
  privateTitle.textContent = `Private Chat with ${otherUser}`;
  privateGamesBtn.classList.remove('hidden');
  privateCallBtn.classList.remove('hidden');
  showOverlay();
  privateModal.classList.remove('hidden');
  privateInput.focus();
  refreshBlockedBanner();
  markPrivateRead(roomId);
  privatePollInterval = setInterval(pollPrivateMessages, 500);
  if (callPhase === 'idle') { ensureCallPolling(); }
  uiOpen('private');
}

function addPrivateNotice(text) {
  const div = document.createElement('div');
  div.className = 'system-msg';
  div.textContent = text;
  privateMessages.appendChild(div);
  privateMessages.scrollTop = privateMessages.scrollHeight;
  return div;
}

function endPrivateChat() {
  const room = currentPrivateRoom;
  const tkn = token;
  if (room && tkn) {
    fetch(`${API_BASE}/private-end.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: tkn, room_id: room }),
    }).catch(() => {});
  }
  closePrivateChat();
}

function closePrivateChat() {
  if (privatePollInterval) { clearInterval(privatePollInterval); privatePollInterval = null; }
  endPrivateCall(true);
  stopDnD();
  stopGamePolling();
  currentGameType = null;
  currentPrivateRoom = null;
  privateLastTimestamp = 0;
  pendingRequestTarget = null;
  pendingRequestNotice = null;
  privateMessages.innerHTML = '';
  renderedPrivateMsgs.clear();
  privateTyping = [];
  renderPrivateTyping();
  privateModal.classList.add('hidden');
  privateGamesBtn.classList.add('hidden');
  privateCallBtn.classList.add('hidden');
  hideOverlay();
  if (!gamesModal.classList.contains('hidden')) {
    gamesModal.classList.add('hidden');
    hideGameOverlay();
  }
}

privateClose.addEventListener('click', endPrivateChat);

privateSendBtn.addEventListener('click', sendPrivateMessage);
privateInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') sendPrivateMessage(); });

async function sendPrivateMessage() {
  const text = privateInput.value.trim();
  if (!text || !currentPrivateRoom || !username) return;
  if (text.charAt(0) === '!') {
    if (dndDispatchCommand(text)) {
      privateInput.value = '';
      privateInput.focus();
      return;
    }
  }
  privateSendBtn.disabled = true;
  try {
    await fetch(`${API_BASE}/private-send.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ room_id: currentPrivateRoom, username, message: text, token, reply_to: privateReply ? privateReply.id : '' }),
    });
    privateInput.value = '';
    cancelReply('private');
    privateInput.focus();
  } catch (err) {}
  privateSendBtn.disabled = false;
}

async function pollPrivateMessages() {
  if (!currentPrivateRoom) return;
  try {
    const res = await fetch(`${API_BASE}/private-poll.php?room_id=${encodeURIComponent(currentPrivateRoom)}&since=${privateLastTimestamp}&token=${encodeURIComponent(token || '')}`);
    const data = await res.json();
    if (data.messages && data.messages.length > 0) {
      data.messages.forEach(addPrivateMessage);
      markPrivateRead(currentPrivateRoom);
    }
    if (data.blocked === true) {
      privatePeerBlocked = true;
      refreshBlockedBanner();
    }
    if (Array.isArray(data.typing)) {
      privateTyping = data.typing.filter(u => u.toLowerCase() !== (username || '').toLowerCase());
      renderPrivateTyping();
    }
    privateLastTimestamp = data.server_time;
  } catch (err) {}
}

function refreshBlockedBanner() {
  const banner = document.getElementById('private-blocked');
  if (privatePeerBlocked && currentPrivateRoom) {
    banner.textContent = `You have blocked ${privatePeer}. Their messages are hidden.`;
    banner.classList.remove('hidden');
  } else {
    banner.classList.add('hidden');
  }
}

function renderPublicTyping() {
  renderTypingIndicators(currentTyping, document.getElementById('typing-indicator'));
}

function renderPrivateTyping() {
  renderTypingIndicators(privateTyping, document.getElementById('private-typing'));
}

function addPrivateMessage(msg) {
  const isOwn = msg.username === username;
  const existing = renderedPrivateMsgs.get(msg.id);

  if (existing && !msg.deleted) {
    if (JSON.stringify(msg) !== JSON.stringify(existing.lastRaw)) {
      existing.lastRaw = JSON.parse(JSON.stringify(msg));
      rebuildPrivateBubble(existing.el, msg, isOwn);
    }
    return;
  }
  if (existing && msg.deleted) {
    existing.lastRaw = JSON.parse(JSON.stringify(msg));
    rebuildPrivateBubble(existing.el, msg, isOwn);
    return;
  }

  const div = document.createElement('div');
  div.dataset.msgId = msg.id;
  rebuildPrivateBubble(div, msg, isOwn);
  privateMessages.appendChild(div);
  renderedPrivateMsgs.set(msg.id, { el: div, lastRaw: JSON.parse(JSON.stringify(msg)) });
  privateMessages.scrollTop = privateMessages.scrollHeight;
  if (!isOwn && !msg.deleted) maybeNotify(msg, 'private');
}

function rebuildPrivateBubble(div, msg, isOwn) {
  div.className = 'private-msg ' + (isOwn ? 'own' : 'other');
  if (msg.deleted) {
    div.classList.add('message-deleted');
    div.innerHTML = `<div class="msg-deleted-line">🕳 This message was deleted</div>`;
    return;
  }
  div.classList.remove('message-deleted');
  const id = escAttr(msg.id);
  const actions = `<div class="msg-actions">
    <button class="msg-action" data-act="reply" data-msg="${id}" title="Reply">↩</button>
    ${isOwn ? `<button class="msg-action" data-act="edit" data-msg="${id}" title="Edit">✎</button>
       <button class="msg-action" data-act="del" data-msg="${id}" title="Delete">🗑</button>` : ''}</div>`;

  let content = '';
  if (msg.reply) {
    content += `<div class="reply-quote" data-jump-private-msg="${escAttr(msg.reply.id)}" title="Jump to replied message"><strong>${escHtml(msg.reply.username)}</strong><span>${msg.reply.deleted ? 'deleted message' : escHtml(msg.reply.message)}</span></div>`;
  }
  content += `<div class="msg-text">${formatText(msg.message)}</div>`;
  if (msg.file) {
    const isImage = msg.file.type && msg.file.type.startsWith('image/');
    if (isImage) {
      const dl = `${API_BASE}/download.php?token=${escAttr(msg.file.token)}`;
      content += `<img src="${dl}" class="file-preview" alt="${escAttr(msg.file.name)}" data-lightbox="${dl}" data-fname="${escAttr(msg.file.name)}" title="Click to enlarge">`;
    } else {
      const dl = `${API_BASE}/download.php?token=${escAttr(msg.file.token)}`;
      content += `<div class="file-attach" style="margin-top:4px">
        <span class="file-icon">📎</span>
        <div class="file-info">
          <div class="file-name">${escHtml(msg.file.name)}</div>
          <div class="file-size">${formatSize(msg.file.size)}</div>
        </div>
        <a href="${dl}" class="file-dl-btn" target="_blank">Download</a>
      </div>`;
    }
  }
  if (msg.read) {
    content += `<span class="read-tick" title="Read">✓✓</span>`;
  }
  content += `<div class="reactions" data-msg-id="${id}">`;
  if (msg.reactions && Object.keys(msg.reactions).length) {
    for (const [emoji, users] of Object.entries(msg.reactions)) {
      const mine = users.some(u => u.toLowerCase() === (username || '').toLowerCase());
      content += `<span class="reaction-chip${mine ? ' mine' : ''}" data-msg-id="${id}" data-emoji="${escAttr(emoji)}" title="${escAttr(users.join(', '))}">${escHtml(emoji)} ${users.length}</span>`;
    }
  }
  content += `<button class="msg-action reaction-add" data-msg-id="${id}" title="Add reaction">➕</button>`;
  content += `</div>`;

  div.innerHTML = `${content}${actions}<div class="p-time">${escHtml(msg.time)}</div>`;
}

// Private reactions + edit/delete + reaction palette handlers
privateMessages.addEventListener('click', async (e) => {
  const quote = e.target.closest('.reply-quote');
  if (quote) {
    jumpToPrivateMessage(quote.dataset.jumpPrivateMsg);
    return;
  }
  const chip = e.target.closest('.reaction-chip');
  if (chip) {
    toggleReaction(chip.dataset.msgId, chip.dataset.emoji, 'private');
    return;
  }
  const addBtn = e.target.closest('.reaction-add');
  if (addBtn) {
    e.stopPropagation();
    toggleReactionPalette(addBtn.dataset.msgId, addBtn);
    return;
  }
  const actionBtn = e.target.closest('.msg-action');
  if (actionBtn) {
    const msgId = actionBtn.dataset.msg;
    const act = actionBtn.dataset.act;
    if (act === 'edit') startEditMessage(msgId, 'private');
    else if (act === 'del') pushMessageAction('private', msgId, act);
    else if (act === 'reply') startReply('private', msgId);
    else if (act === 'add-reaction') {
      actionBtn.classList.add('hidden');
      const target = document.querySelector(`[data-msg-id="${CSS.escape(msgId)}"] .reactions`);
      openReactionPalette(msgId, target || actionBtn.parentElement);
    }
    return;
  }
  const palette = e.target.closest('.reaction-palette .rp-emoji');
  if (palette) {
    toggleReaction(palette.dataset.msgId, palette.dataset.emoji, 'private');
    return;
  }
});

// ===== VIDEO CALL =====
const privateCallBtn = document.getElementById('private-call-btn');
const callModal = document.getElementById('call-modal');
const callStatusEl = document.getElementById('call-status');
const callEndBtn = document.getElementById('call-end-btn');
const callMuteBtn = document.getElementById('call-mute-btn');
const callCamBtn = document.getElementById('call-cam-btn');
const remoteVideo = document.getElementById('remote-video');
const localVideo = document.getElementById('local-video');
const incomingCallEl = document.getElementById('incoming-call');
const incomingText = document.getElementById('incoming-text');
const incomingAccept = document.getElementById('incoming-accept');
const incomingDecline = document.getElementById('incoming-decline');

const CALL_ICE = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };

let callPC = null;
let callLocalStream = null;
let callPollInt = null;
let callRingInt = null;
let callTimeoutId = null;
let callPhase = 'idle';
let callSince = 0;
let callPendingIce = [];
let callLastRingTs = 0;
let incomingCallRoom = null;
let incomingCallFrom = null;
let incomingRingId = null;
let incomingCallId = null;
let currentCallId = '';
const handledRingIds = new Set();
const deadCallIds = new Set();

function markCallDead(callId) {
  if (!callId) return;
  deadCallIds.add(callId);
  if (deadCallIds.size > 40) {
    const first = deadCallIds.values().next().value;
    if (first) deadCallIds.delete(first);
  }
}

function mediaAvailable() {
  return typeof navigator !== 'undefined'
    && typeof navigator.mediaDevices !== 'undefined'
    && typeof navigator.mediaDevices.getUserMedia === 'function';
}

function describeMediaError(err) {
  if (!window.isSecureContext) {
    return 'Video calls need HTTPS. Open the site via https:// (or http://localhost) to use the camera.';
  }
  const name = String((err && err.name) || '');
  if (!name) return 'Your camera or microphone is unavailable.';
  if (name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError') {
    const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent || '');
    if (isIOS) {
      return 'Camera/microphone permission was blocked. Open iOS Settings > Safari > Camera & Microphone (or Settings > [site] if installed) and allow access for this site, then try again.';
    }
    const isAndroid = /Android/.test(navigator.userAgent || '');
    if (isAndroid) {
      return 'Camera/microphone permission was blocked. Tap the lock/globe icon in the address bar, choose "Permissions" (or Site settings) and allow Camera and Microphone, then try again.';
    }
    return 'Camera/microphone permission was blocked. Click the lock/globe icon in the address bar, allow Camera and Microphone for this site, then try again.';
  }
  if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
    return 'No camera or microphone was found on this device.';
  }
  if (name === 'NotReadableError' || name === 'TrackStartError') {
    return 'Your camera or microphone is already in use by another app. Close it and try again.';
  }
  if (name === 'OverconstrainedError') {
    return 'No camera matches the requested settings.';
  }
  return 'Unable to access the camera/microphone: ' + (err.message || name);
}

function ensureCallPolling() {
  if (callPollInt) return;
  callPollInt = setInterval(pollCallSignaling, 500);
}

function stopCallPollingWhenIdle() {
  if (callPhase === 'idle' && callPollInt) {
    clearInterval(callPollInt);
    callPollInt = null;
  }
}

function sendCallSignaling(type, payloadObj, roomOverride) {
  if (!token || !username) return;
  const room = roomOverride || currentPrivateRoom;
  if (!room) return;
  const payload = typeof payloadObj === 'string' ? payloadObj : JSON.stringify(payloadObj || {});
  const other = room.replace(username + '_', '').replace('_' + username, '');
  fetch(`${API_BASE}/call.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, type, room_id: room, from: username, to: other, payload, call_id: currentCallId }),
  }).catch(() => {});
}

async function pollCallSignaling() {
  if (!currentPrivateRoom) return;
  try {
    const res = await fetch(`${API_BASE}/call.php?room_id=${currentPrivateRoom}&username=${encodeURIComponent(username)}&since=${callSince}&token=${encodeURIComponent(token || '')}`);
    const data = await res.json();
    if (data.events && data.events.length > 0) {
      data.events.forEach(handleCallEvent);
    }
    if (data.server_time) { callSince = data.server_time; }
  } catch (err) {}
}

function handleCallEvent(ev) {
  const t = ev.type;
  if (t === 'ring') {
    if (ev.call_id && deadCallIds.has(ev.call_id)) return;
    const now = Date.now();
    if (callPhase === 'idle' && now - callLastRingTs > 2500) {
      callLastRingTs = now;
      callPhase = 'incoming';
      handleIncomingRing(ev.room_id || currentPrivateRoom, ev.from, ev.id, ev.call_id);
      ensureCallPolling();
      callTimeoutId = setTimeout(() => { endPrivateCall(true); }, 45000);
    }
    return;
  }
  if (t === 'offer') {
    if (ev.call_id && currentCallId && ev.call_id !== currentCallId) return;
    if (callPhase === 'incoming' && callPC && !callPC.remoteDescription) {
      callPC.setRemoteDescription(new RTCSessionDescription({ type: 'offer', sdp: ev.payload }))
        .then(() => callPC.createAnswer())
        .then(ans => callPC.setLocalDescription(ans))
        .then(() => {
          sendCallSignaling('answer', callPC.localDescription.sdp);
          callPhase = 'answering';
          callStatusEl.textContent = 'Connecting...';
          drainPendingIce();
        })
        .catch(() => {});
    }
    return;
  }
  if (t === 'answer') {
    if (ev.call_id && currentCallId && ev.call_id !== currentCallId) return;
    if (callPC && callPC.signalingState === 'have-local-offer') {
      callPC.setRemoteDescription(new RTCSessionDescription({ type: 'answer', sdp: ev.payload }))
        .then(() => {
          callPhase = 'answering';
          callStatusEl.textContent = 'Connecting...';
          drainPendingIce();
        })
        .catch(() => {});
    }
    return;
  }
  if (t === 'candidate') {
    if (ev.call_id && currentCallId && ev.call_id !== currentCallId) return;
    let cand = null;
    try { cand = typeof ev.payload === 'string' ? JSON.parse(ev.payload) : ev.payload; } catch (e) { return; }
    if (!cand || !cand.candidate) return;
    if (callPC && callPC.remoteDescription) {
      callPC.addIceCandidate(new RTCIceCandidate(cand.candidate)).catch(() => {});
    } else {
      callPendingIce.push(cand.candidate);
    }
    return;
  }
  if (t === 'hangup') {
    if (ev.call_id && currentCallId && ev.call_id !== currentCallId) return;
    if (incomingRingId) { sendCallSignaling('ring_clear', { ring_id: incomingRingId }, currentPrivateRoom); }
    endPrivateCall(false);
  }
}

function drainPendingIce() {
  const buffered = callPendingIce.slice();
  callPendingIce = [];
  buffered.forEach(c => {
    if (callPC && callPC.remoteDescription) {
      callPC.addIceCandidate(new RTCIceCandidate(c)).catch(() => {});
    }
  });
}

async function setupCallPeer() {
  const pc = new RTCPeerConnection(CALL_ICE);
  callPC = pc;
  pc.onicecandidate = (e) => {
    if (e.candidate) sendCallSignaling('candidate', JSON.stringify({ candidate: e.candidate }));
  };
  pc.onconnectionstatechange = () => {
    if (callPC !== pc) return;
    const s = pc.connectionState;
    if (s === 'connected') {
      if (callPhase !== 'idle') { callPhase = 'connected'; callStatusEl.textContent = 'Connected'; }
    } else if (s === 'failed') {
      callStatusEl.textContent = 'Connection failed';
    } else if (s === 'disconnected' && callPhase === 'connected') {
      callStatusEl.textContent = 'Reconnecting...';
    }
  };
  pc.ontrack = (e) => {
    if (e.streams[0]) { remoteVideo.srcObject = e.streams[0]; }
  };
  if (callLocalStream) {
    callLocalStream.getTracks().forEach(t => pc.addTrack(t, callLocalStream));
    localVideo.srcObject = callLocalStream;
  }
  return pc;
}

function startVideoCall() {
  if (callPhase === 'outgoing' || callPhase === 'incoming' || callPhase === 'connected') return;
  if (!currentPrivateRoom || !username) return;
  if (!mediaAvailable()) {
    alert(describeMediaError());
    return;
  }
  callPhase = 'outgoing';
  callSince = 0;
  callPendingIce = [];
  currentCallId = 'c_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
  incomingCallId = null;
  callStatusEl.textContent = 'Calling...';
  uiOpen('call');
  openCallModal();
  showOverlay();
  ensureCallPolling();
  sendCallSignaling('ring', {});
  callRingInt = setInterval(() => {
    sendCallSignaling('ring', {});
    if (callPC && callPC.remoteDescription === null && callPhase === 'outgoing') {
      sendCallSignaling('offer', callPC.localDescription.sdp);
    }
  }, 3000);
  callTimeoutId = setTimeout(() => { endPrivateCall(true); }, 90000);
  navigator.mediaDevices.getUserMedia({ video: true, audio: true })
    .then(stream => {
      callLocalStream = stream;
      return setupCallPeer();
    })
    .then(() => callPC.createOffer())
    .then(offer => callPC.setLocalDescription(offer))
    .then(() => {
      sendCallSignaling('offer', callPC.localDescription.sdp);
    })
    .catch(err => {
      callStatusEl.textContent = describeMediaError(err);
      setTimeout(() => endPrivateCall(true), 4000);
    });
}

function acceptIncomingCall() {
  hideIncomingCall();
  if (callTimeoutId) { clearTimeout(callTimeoutId); callTimeoutId = null; }
  if (incomingRingId) { sendCallSignaling('ring_clear', { ring_id: incomingRingId }, incomingCallRoom || currentPrivateRoom); }
  const room = incomingCallRoom || currentPrivateRoom;
  const from = incomingCallFrom;
  if (!room || !from) return;
  if (currentPrivateRoom !== room) {
    // Switching into the private room must not hang up the call we're about to accept.
    suppressCallHangup = true;
    try { openPrivateChat(room, from); } finally { suppressCallHangup = false; }
  }
  currentCallId = incomingCallId || currentCallId;
  callPhase = 'incoming';
  callSince = 0;
  callPendingIce = [];
  callStatusEl.textContent = 'Connecting...';
  uiOpen('call');
  openCallModal();
  showOverlay();
  ensureCallPolling();
  if (!mediaAvailable()) {
    callStatusEl.textContent = describeMediaError();
    setTimeout(() => endPrivateCall(true), 4000);
    return;
  }
  callTimeoutId = setTimeout(() => { endPrivateCall(true); }, 60000);
  navigator.mediaDevices.getUserMedia({ video: true, audio: true })
    .then(stream => {
      callLocalStream = stream;
      return setupCallPeer();
    })
    .catch(err => {
      callStatusEl.textContent = describeMediaError(err);
      setTimeout(() => endPrivateCall(true), 4000);
    });
}

function declineIncomingCall() {
  hideIncomingCall();
  if (callTimeoutId) { clearTimeout(callTimeoutId); callTimeoutId = null; }
  sendCallSignaling('hangup', {}, incomingCallRoom);
  if (incomingRingId) { sendCallSignaling('ring_clear', { ring_id: incomingRingId }, incomingCallRoom); }
  markCallDead(currentCallId);
  markCallDead(incomingCallId);
  callPhase = 'idle';
  incomingCallRoom = null;
  incomingCallFrom = null;
  incomingRingId = null;
  incomingCallId = null;
  currentCallId = '';
  stopCallPollingWhenIdle();
}

function handleIncomingRing(roomId, from, ringId, callId) {
  if (callId && deadCallIds.has(callId)) return;
  if (ringId) {
    if (handledRingIds.has(ringId)) return;
    handledRingIds.add(ringId);
    if (handledRingIds.size > 10) {
      const first = handledRingIds.values().next().value;
      if (first) handledRingIds.delete(first);
    }
  }
  if (callPhase === 'incoming') {
    if (incomingCallRoom === roomId && (!callId || incomingCallId === callId)) return;
  } else if (callPhase !== 'idle') {
    return;
  }
  incomingRingId = ringId || null;
  incomingCallRoom = roomId;
  incomingCallFrom = from;
  incomingCallId = callId || null;
  if (callId) currentCallId = currentCallId || callId;
  incomingText.textContent = `Incoming video call from ${from}`;
  incomingCallEl.classList.remove('hidden');
  uiOpen('incoming');
}

function hideIncomingCall() {
  incomingCallEl.classList.add('hidden');
}

function openCallModal() {
  callModal.classList.remove('hidden');
}

function hideCallModal() {
  callModal.classList.add('hidden');
}

let suppressCallHangup = false;

function endPrivateCall(notifyRemote) {
  if (callRingInt) { clearInterval(callRingInt); callRingInt = null; }
  if (callTimeoutId) { clearTimeout(callTimeoutId); callTimeoutId = null; }
  if (notifyRemote && !suppressCallHangup) { sendCallSignaling('hangup', {}); }
  if (callPC) { try { callPC.close(); } catch (e) {} callPC = null; }
  if (callLocalStream) {
    callLocalStream.getTracks().forEach(t => t.stop());
    callLocalStream = null;
  }
  localVideo.srcObject = null;
  remoteVideo.srcObject = null;
  hideIncomingCall();
  hideCallModal();
  if (privateModal && !privateModal.classList.contains('hidden')) { showOverlay(); } else { hideOverlay(); }
  callPhase = 'idle';
  callPendingIce = [];
  callLastRingTs = 0;
  markCallDead(currentCallId);
  markCallDead(incomingCallId);
  currentCallId = '';
  incomingCallId = null;
  incomingCallRoom = null;
  incomingCallFrom = null;
  incomingRingId = null;
  if (callPollInt) { clearInterval(callPollInt); callPollInt = null; }
}

privateCallBtn.addEventListener('click', startVideoCall);
callEndBtn.addEventListener('click', () => endPrivateCall(true));
callMuteBtn.addEventListener('click', () => {
  if (!callLocalStream) return;
  const audio = callLocalStream.getAudioTracks();
  if (audio.length === 0) return;
  const on = !audio[0].enabled;
  audio.forEach(t => t.enabled = on);
  callMuteBtn.textContent = on ? '🎤' : '🔇';
});
callCamBtn.addEventListener('click', () => {
  if (!callLocalStream) return;
  const video = callLocalStream.getVideoTracks();
  if (video.length === 0) return;
  const on = !video[0].enabled;
  video.forEach(t => t.enabled = on);
  localVideo.style.visibility = on ? 'visible' : 'hidden';
  callCamBtn.textContent = on ? '🎥' : '🚫';
});
incomingAccept.addEventListener('click', acceptIncomingCall);
incomingDecline.addEventListener('click', declineIncomingCall);



// ===== FRIENDS =====
const friendsListEl = document.getElementById('friends-list');
const friendsCountEl = document.getElementById('friends-count');
const friendAddInput = document.getElementById('friend-add-input');
const friendAddBtn = document.getElementById('friend-add-btn');

async function fetchFriends() {
  if (!username) return;
  try {
    const res = await fetch(`${API_BASE}/friends.php?username=${encodeURIComponent(username)}&token=${encodeURIComponent(token || '')}`);
    const data = await res.json();
    if (data.friends) {
      const un = {};
      const prev = {};
      data.friends.forEach(f => {
        if (f.username) {
          if (f.unread) un[f.username] = f.unread;
          if (f.preview) prev[f.username] = f.preview;
        }
      });
      friendUnread = un;
      FRIEND_PREVIEWS = prev;
      renderFriends(data.friends);
      refreshRoomBadge();
    }
  } catch (err) {}
}

function renderFriends(list) {
  friendsListEl.innerHTML = '';
  friendsCountEl.textContent = list.length ? '(' + list.length + ')' : '';
  list.forEach(f => {
    const div = document.createElement('div');
    div.className = 'friend-entry';
    div.dataset.username = f.username;
    const dot = document.createElement('span');
    dot.className = 'friend-dot' + (f.online ? ' online' : '');
    const nameWrap = document.createElement('span');
    nameWrap.className = 'friend-name-wrap';
    const nameSpan = document.createElement('span');
    nameSpan.className = 'friend-name';
    nameSpan.textContent = f.username;
    const unread = friendUnread[f.username] || 0;
    let previewLine = null;
    if (unread > 0) {
      const badge = document.createElement('span');
      badge.className = 'friend-badge';
      badge.textContent = unread > 99 ? '99+' : unread;
      nameWrap.appendChild(nameSpan);
      nameWrap.appendChild(badge);
      if (FRIEND_PREVIEWS[f.username]) {
        previewLine = document.createElement('div');
        previewLine.className = 'friend-preview';
        previewLine.textContent = FRIEND_PREVIEWS[f.username];
      }
    } else {
      nameWrap.appendChild(nameSpan);
    }
    const removeBtn = document.createElement('button');
    removeBtn.className = 'friend-remove';
    removeBtn.textContent = '\u00d7';
    removeBtn.title = 'Remove friend';
    removeBtn.addEventListener('click', async (e) => {
      e.stopPropagation();
      await removeFriend(f.username);
    });
    div.appendChild(dot);
    div.appendChild(nameWrap);
    if (previewLine) div.appendChild(previewLine);
    div.appendChild(removeBtn);
    div.addEventListener('click', () => {
      if (activeUsers.includes(f.username)) {
        startPrivateChat(f.username);
      } else {
        sendOfflineMessage(f.username);
      }
    });
    div.addEventListener('contextmenu', (e) => {
      e.preventDefault();
      ctxTargetUser = f.username;
      buildCtxMenu(f.username);
      ctxMenu.style.left = e.clientX + 'px';
      ctxMenu.style.top = e.clientY + 'px';
      ctxMenu.classList.remove('hidden');
    });
    friendsListEl.appendChild(div);
  });
}

async function addFriend() {
  const name = friendAddInput.value.trim();
  if (!name || !token) return;
  friendAddBtn.disabled = true;
  try {
    const res = await fetch(`${API_BASE}/friends.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, friend: name }),
    });
    const data = await res.json();
    if (data.success) {
      friendAddInput.value = '';
      fetchFriends();
    } else {
      addSystemMessage('Friend error: ' + (data.error || data.message || 'Unknown'));
    }
  } catch (err) {
    addSystemMessage('Failed to add friend');
  }
  friendAddBtn.disabled = false;
}

async function addFriendByName(name) {
  if (!name || !token) return;
  try {
    const res = await fetch(`${API_BASE}/friends.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, friend: name }),
    });
    const data = await res.json();
    if (data.success) {
      addSystemMessage(data.already ? `${name} is already your friend` : `${name} added as friend`);
      fetchFriends();
    } else {
      addSystemMessage('Friend error: ' + (data.error || data.message || 'Unknown'));
    }
  } catch (err) {
    addSystemMessage('Failed to add friend');
  }
}

async function removeFriend(name) {
  if (!token) return;
  try {
    await fetch(`${API_BASE}/friends.php`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, friend: name }),
    });
    fetchFriends();
  } catch (err) {}
}

friendAddBtn.addEventListener('click', addFriend);
friendAddInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') addFriend(); });

// ===== GAMES =====
const privateGamesBtn = document.getElementById('private-games-btn');
const gamesModal = document.getElementById('games-modal');
const gamesClose = document.getElementById('games-close');
const gamesList = document.getElementById('games-list');
const gameBoard = document.getElementById('game-board');
const gameBoardTitle = document.getElementById('game-board-title');
const gameBoardStatus = document.getElementById('game-board-status');
const gameBoardArea = document.getElementById('game-board-area');
const gameBoardBack = document.getElementById('game-board-back');


let tttState = null;
let rpsState = null;
let gameOverlay = null;

function showGameOverlay() {
  if (!gameOverlay) { gameOverlay = document.createElement('div'); gameOverlay.className = 'private-overlay'; }
  document.body.appendChild(gameOverlay);
}

function hideGameOverlay() {
  if (gameOverlay && gameOverlay.parentNode) gameOverlay.parentNode.removeChild(gameOverlay);
}

privateGamesBtn.addEventListener('click', () => {
  gamesModal.classList.remove('hidden');
  showGameOverlay();
  showGamesList();
});

gamesClose.addEventListener('click', () => {
  gamesModal.classList.add('hidden');
  hideGameOverlay();
  stopDnD();
  stopGamePolling();
  currentGameType = null;
});

gameBoardBack.addEventListener('click', showGamesList);

function showGamesList() {
  tttState = null;
  rpsState = null;
  hmState = null;
  c4State = null;
  gtnState = null;
  memState = null;
  hlState = null;
  wsState = null;
  ddState = null;
  msState = null;
  stopDnD();
  stopGamePolling();
  currentGameType = null;
  gameBoard.classList.add('hidden');
  gamesList.classList.remove('hidden');
}

function showGameBoard(title) {
  gamesList.classList.add('hidden');
  gameBoard.classList.remove('hidden');
  gameBoardTitle.textContent = title;
  gameBoardStatus.textContent = '';
}

let hmState = null;
let hangmanPool = [];
let c4State = null;
let gtnState = null;
let memState = null;
let hlState = null;
let wsState = null;
let ddState = null;
let msState = null;
let currentGameType = null;
let gamePollTimer = null;

async function gameFetchState(gameType) {
  if (!currentPrivateRoom || !token) return null;
  try {
    const res = await fetch(`${API_BASE}/games.php?room_id=${encodeURIComponent(currentPrivateRoom)}&game=${encodeURIComponent(gameType)}&token=${encodeURIComponent(token)}`);
    const d = await res.json();
    if (d.ok) return d.state;
  } catch (e) {}
  return null;
}

async function gameSyncSave(gameType, state) {
  if (!currentPrivateRoom || !token) return;
  try {
    await fetch(`${API_BASE}/games.php`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, room_id: currentPrivateRoom, game: gameType, action: 'save', state }),
    });
  } catch (e) {}
}

async function gameSyncDelete(gameType) {
  if (!currentPrivateRoom || !token) return;
  try {
    await fetch(`${API_BASE}/games.php`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, room_id: currentPrivateRoom, game: gameType, action: 'delete' }),
    });
  } catch (e) {}
}

function gameNeedsJoin(state) {
  if (!state) return false;
  if (state._players && Array.isArray(state._players)) return !state._players.some(p => p.toLowerCase() === username.toLowerCase());
  if (state.players && Array.isArray(state.players)) return !state.players.some(p => p.toLowerCase() === username.toLowerCase());
  if (state.host) return state.host.toLowerCase() !== username.toLowerCase() && (!state.guesser || state.guesser.toLowerCase() !== username.toLowerCase());
  if (state.setter) return state.setter.toLowerCase() !== username.toLowerCase() && (!state.guesser || state.guesser.toLowerCase() !== username.toLowerCase());
  return false;
}

async function gamePollNow() {
  if (!currentPrivateRoom || !token || !currentGameType) return;
  try {
    const res = await fetch(`${API_BASE}/games.php?room_id=${encodeURIComponent(currentPrivateRoom)}&game=${encodeURIComponent(currentGameType)}&token=${encodeURIComponent(token)}`);
    const d = await res.json();
    if (d.ok && d.state) {
      if (gameNeedsJoin(d.state)) {
        if (Array.isArray(d.state._players)) d.state._players.push(username);
        if (Array.isArray(d.state.players)) d.state.players.push(username);
        if (d.state.guesser === null && currentGameType === 'hangman') { d.state.guesser = username; d.state.status = 'playing'; }
        if (d.state.guesser === null && currentGameType === 'guessthenumber') { d.state.guesser = username; d.state.status = 'playing'; }
        await gameSyncSave(currentGameType, d.state);
      }
      switch (currentGameType) {
        case 'tictactoe': tttState = d.state; renderTTT(); break;
        case 'rps': rpsState = d.state; renderRPS(); break;
        case 'hangman': hmState = d.state; hmState.guessed = new Set(d.state.guessed || []); renderHM(); break;
        case 'connect4': c4State = d.state; renderC4(); break;
        case 'guessthenumber': gtnState = d.state; renderGTN(); break;
        case 'memory': memState = d.state; memState.matched = new Set(d.state.matched || []); renderMem(); break;
        case 'higherlower': hlState = d.state; hlDeck = d.state._deck || []; renderHL(); break;
        case 'wordscramble': wsState = d.state; renderWS(); break;
        case 'diceduel': ddState = d.state; renderDD(); break;
      }
    } else if (d.ok && !d.state) {
      showGamesList();
    }
  } catch (e) {}
}

function startGamePolling() {
  stopGamePolling();
  gamePollTimer = setInterval(gamePollNow, 2000);
}

function stopGamePolling() {
  if (gamePollTimer) { clearInterval(gamePollTimer); gamePollTimer = null; }
}

// Game card click handlers
document.querySelectorAll('.game-card').forEach(card => {
  card.addEventListener('click', () => {
    const game = card.dataset.game;
    if (game === 'tictactoe') startTicTacToe();
    else if (game === 'rockpaperscissors') startRPS();
    else if (game === 'hangman') startHangman();
    else if (game === 'connect4') startConnect4();
    else if (game === 'guessthenumber') startGuessTheNumber();
    else if (game === 'dnd') startDnD();
    else if (game === 'memory') startMemory();
    else if (game === 'higherlower') startHigherLower();
    else if (game === 'wordscramble') startWordScramble();
    else if (game === 'diceduel') startDiceDuel();
    else if (game === 'minesweeper') startMinesweeper();
  });
});

// ===== TIC-TAC-TOE =====
async function startTicTacToe() {
  showGameBoard('Tic-Tac-Toe');
  currentGameType = 'tictactoe';
  let state = await gameFetchState('tictactoe');
  if (!state) {
    state = { board: Array(9).fill(null), currentPlayer: 'X', winner: null };
    await gameSyncSave('tictactoe', state);
  }
  tttState = state;
  renderTTT();
  startGamePolling();
}

function renderTTT() {
  const s = tttState;
  gameBoardArea.innerHTML = '';

  if (s.winner) {
    gameBoardStatus.textContent = s.winner === 'draw' ? "It's a draw!" : s.winner + ' wins!';
  } else {
    gameBoardStatus.textContent = s.currentPlayer + "'s turn";
  }

  const grid = document.createElement('div');
  grid.className = 'ttt-grid';

  s.board.forEach((cell, i) => {
    const div = document.createElement('div');
    div.className = 'ttt-cell';
    if (s.winner) div.classList.add('disabled');
    div.textContent = cell || '';
    div.addEventListener('click', () => tttMove(i));
    grid.appendChild(div);
  });

  gameBoardArea.appendChild(grid);

  if (s.winner) {
    const resetBtn = document.createElement('button');
    resetBtn.textContent = 'Play Again';
    resetBtn.style.cssText = 'margin-top:12px;padding:8px 20px;background:#e94560;color:white;border:none;border-radius:6px;font-weight:600;cursor:pointer;';
    resetBtn.addEventListener('click', async () => {
      const fresh = { board: Array(9).fill(null), currentPlayer: 'X', winner: null };
      await gameSyncSave('tictactoe', fresh);
      tttState = fresh;
      renderTTT();
    });
    gameBoardArea.appendChild(resetBtn);
  }
}

function tttMove(index) {
  const s = tttState;
  if (s.board[index] || s.winner) return;
  s.board[index] = s.currentPlayer;
  s.winner = checkTTTWin(s.board);
  if (!s.winner && s.board.every(c => c)) s.winner = 'draw';
  if (!s.winner) s.currentPlayer = s.currentPlayer === 'X' ? 'O' : 'X';
  gameSyncSave('tictactoe', s);
  renderTTT();
}

function checkTTTWin(board) {
  const wins = [
    [0,1,2],[3,4,5],[6,7,8],
    [0,3,6],[1,4,7],[2,5,8],
    [0,4,8],[2,4,6]
  ];
  for (const [a,b,c] of wins) {
    if (board[a] && board[a] === board[b] && board[a] === board[c]) return board[a];
  }
  return null;
}

// ===== ROCK PAPER SCISSORS =====
async function startRPS() {
  showGameBoard('Rock Paper Scissors');
  currentGameType = 'rps';
  let state = await gameFetchState('rps');
  if (!state) {
    state = {
      p1Pick: null, p2Pick: null,
      p1Score: 0, p2Score: 0,
      round: 1, maxRounds: 3,
      phase: 'pick', result: null,
      currentPicker: 1,
    };
    await gameSyncSave('rps', state);
  }
  rpsState = state;
  renderRPS();
  startGamePolling();
}

function renderRPS() {
  const s = rpsState;
  gameBoardArea.innerHTML = '';

  if (s.phase === 'done') {
    const winner = s.p1Score > s.p2Score ? 'Player 1' : (s.p2Score > s.p1Score ? 'Player 2' : 'Draw');
    gameBoardStatus.textContent = winner === 'Draw' ? "It's a draw!" : winner + ' wins the game!';
  } else {
    gameBoardStatus.textContent = 'Round ' + s.round + ' of ' + s.maxRounds + ' - Player ' + s.currentPicker + ' pick';
  }

  const container = document.createElement('div');
  container.className = 'rps-container';

  // Score
  const scoreDiv = document.createElement('div');
  scoreDiv.className = 'rps-score';
  scoreDiv.textContent = 'Player 1: ' + s.p1Score + ' | Player 2: ' + s.p2Score;
  container.appendChild(scoreDiv);

  if (s.phase === 'pick') {
    const pickDiv = document.createElement('div');
    pickDiv.className = 'rps-pick';
    ['\u270a','\u270c','\u270b'].forEach((icon, idx) => {
      const btn = document.createElement('button');
      btn.className = 'rps-pick-btn';
      btn.textContent = icon;
      btn.addEventListener('click', () => rpsPick(idx));
      pickDiv.appendChild(btn);
    });
    container.appendChild(pickDiv);

    if (s.currentPicker === 2 && s.p1Pick !== null) {
      const hint = document.createElement('div');
      hint.style.cssText = 'font-size:12px;color:#888;';
      hint.textContent = 'Player 1 has picked. Player 2\'s turn.';
      container.appendChild(hint);
    }
  }

  if (s.phase === 'result') {
    const resultDiv = document.createElement('div');
    resultDiv.className = 'rps-result';
    const emojis = ['\u270a', '\u270c', '\u270b'];
    const names = ['Rock', 'Paper', 'Scissors'];
    resultDiv.innerHTML =
      'Player 1: ' + emojis[s.p1Pick] + ' ' + names[s.p1Pick] +
      ' &nbsp;vs&nbsp; ' +
      'Player 2: ' + emojis[s.p2Pick] + ' ' + names[s.p2Pick];
    container.appendChild(resultDiv);

    if (s.result) {
      const outcome = document.createElement('div');
      outcome.style.cssText = 'font-size:16px;font-weight:700;color:#ffa500;text-align:center;';
      outcome.textContent = s.result;
      container.appendChild(outcome);
    }

    const nextBtn = document.createElement('button');
    nextBtn.style.cssText = 'margin-top:8px;padding:8px 20px;background:#e94560;color:white;border:none;border-radius:6px;font-weight:600;cursor:pointer;';
    nextBtn.textContent = s.round > s.maxRounds ? 'Game Over' : 'Next Round';
    if (s.round <= s.maxRounds) {
      nextBtn.addEventListener('click', () => {
        s.p1Pick = null;
        s.p2Pick = null;
        s.phase = 'pick';
        s.currentPicker = 1;
        s.result = null;
        gameSyncSave('rps', s);
        renderRPS();
      });
    }
    container.appendChild(nextBtn);
  }

  gameBoardArea.appendChild(container);
}

function rpsPick(choice) {
  const s = rpsState;
  if (s.phase !== 'pick') return;

  if (s.currentPicker === 1) {
    s.p1Pick = choice;
    s.currentPicker = 2;
    gameSyncSave('rps', s);
    renderRPS();
  } else if (s.currentPicker === 2 && s.p1Pick !== null) {
    s.p2Pick = choice;
    s.phase = 'result';
    const beats = { 0: 2, 1: 0, 2: 1 };
    if (s.p1Pick === s.p2Pick) {
      s.result = 'Draw!';
    } else if (beats[s.p1Pick] === s.p2Pick) {
      s.result = 'Player 1 wins this round!';
      s.p1Score++;
    } else {
      s.result = 'Player 2 wins this round!';
      s.p2Score++;
    }
    s.round++;
    if (s.round > s.maxRounds) s.phase = 'done';
    gameSyncSave('rps', s);
    renderRPS();
  }
}

// ===== HANGMAN =====
const HANGMAN_WORDS = [
  'CHAT', 'JOHNSON', 'GAME', 'CODE', 'ROCKET', 'PIXEL', 'NINJA', 'PHANTOM', 'CRYSTAL', 'THUNDER',
  'PYTHON', 'STREAM', 'CLOUD', 'NEXUS', 'EMBER', 'ABANDON', 'ABILITY', 'ABSORB', 'ABSTRACT', 'ACADEMY',
  'ACHIEVE', 'ACID', 'ACOUSTIC', 'ACROSS', 'ACTION', 'ACTIVE', 'ACTOR', 'ACTUAL', 'ADAPT', 'ADDRESS',
  'ADJUST', 'ADMIRE', 'ADMIT', 'ADOPT', 'ADVANCE', 'ADVICE', 'AFFAIR', 'AFFECT', 'AFFORD', 'AFRICA',
  'AGENCY', 'AGENDA', 'AGREEMENT', 'AHEAD', 'ALBUM', 'ALCOHOL', 'ALERT', 'ALIEN', 'ALIGN', 'ALIVE',
  'ALLERGY', 'ALLEY', 'ALLIANCE', 'ALMOND', 'ALPINE', 'ALUMNI', 'AMAZON', 'AMBITION', 'AMBULANCE', 'AMEND',
  'AMOUNT', 'AMUSE', 'ANALOG', 'ANALYSIS', 'ANCHOR', 'ANGEL', 'ANGLE', 'ANIMAL', 'ANKLE', 'ANNEX',
  'ANNUAL', 'ANSWER', 'ANTHEM', 'ANTHONY', 'ANTIQUE', 'ANXIETY', 'ANYBODY', 'ANYWAY', 'APART', 'APOLOGY',
  'APPAREL', 'APPEAL', 'APPEAR', 'APPETITE', 'APPLE', 'APPLY', 'APRIL', 'AQUATIC', 'ARCHIVE', 'ARCTIC',
  'ARENA', 'ARGUMENT', 'ARISE', 'ARMOR', 'ARRANGE', 'ARROW', 'ARSENAL', 'ARTICLE', 'ARTIST', 'ASCEND',
  'ASPECT', 'ASSAULT', 'ASSEMBLY', 'ASSERT', 'ASSIGN', 'ASSIST', 'ASSOCIATE', 'ASSUME', 'ASSURE', 'ASTHMA',
  'ASTRO', 'ATOM', 'ATTACH', 'ATTACK', 'ATTEMPT', 'ATTEND', 'ATTITUDE', 'ATTRACT', 'AUCTION', 'AUDIO',
  'AUGUST', 'AUNT', 'AUTHOR', 'AUTO', 'AUTUMN', 'AVATAR', 'AVENUE', 'AVERAGE', 'AVOID', 'AWAKEN',
  'AWARD', 'AWARE', 'BABY', 'BACKUP', 'BACTERIA', 'BALANCE', 'BALLOT', 'BAMBOO', 'BANANA', 'BANNER',
  'BARREL', 'BASEBALL', 'BASIC', 'BASKET', 'BATTERY', 'BATTLE', 'BEACH', 'BEAUTY', 'BECOME', 'BEETLE',
  'BEFORE', 'BEHAVE', 'BEHIND', 'BELIEF', 'BENCH', 'BENEFIT', 'BERRY', 'BETRAY', 'BETTER', 'BICYCLE',
  'BISCUIT', 'BISHOP', 'BITTER', 'BLACK', 'BLADE', 'BLAME', 'BLANKET', 'BLAST', 'BLAZE', 'BLEED',
  'BLEND', 'BLESS', 'BLIND', 'BLIZZARD', 'BLOCK', 'BLOOM', 'BLUE', 'BOARD', 'BOAST', 'BOAT',
  'BODY', 'BOLT', 'BOMB', 'BONUS', 'BOOK', 'BOOST', 'BORDER', 'BORING', 'BOTTLE', 'BOTTOM',
  'BOUNDARY', 'BRAIN', 'BRANCH', 'BRAND', 'BRAVE', 'BREAD', 'BREAK', 'BREATH', 'BRIDGE', 'BRIGHT',
  'BRILLIANT', 'BROAD', 'BROKEN', 'BRONZE', 'BROTHER', 'BROWN', 'BRUSH', 'BUBBLE', 'BUCKET', 'BUDGET',
  'BULLET', 'BUNDLE', 'BURDEN', 'BUREAU', 'BURST', 'BUSINESS', 'BUTTER', 'BUTTON', 'CABINET', 'CABLE',
  'CACTUS', 'CAFE', 'CAGE', 'CAKE', 'CALCULATE', 'CALL', 'CALM', 'CAMERA', 'CAMPUS', 'CANCEL',
  'CANDLE', 'CANDY', 'CANVAS', 'CAPABLE', 'CAPACITY', 'CAPITAL', 'CAPSULE', 'CAPTAIN', 'CAPTURE', 'CARBON',
  'CAREER', 'CAREFUL', 'CARGO', 'CARPENTER', 'CARRIER', 'CARROT', 'CARTOON', 'CARVE', 'CASCADE', 'CASINO',
  'CASTLE', 'CASUAL', 'CATALOG', 'CATCH', 'CATEGORY', 'CATTLE', 'CAUGHT', 'CAUSE', 'CAUTION', 'CAVE',
  'CELEBRATE', 'CELERY', 'CELLAR', 'CEMENT', 'CENSUS', 'CENTER', 'CEREMONY', 'CERTAIN', 'CHAIN', 'CHAMBER',
  'CHAMPION', 'CHANCE', 'CHANGE', 'CHAOS', 'CHAPTER', 'CHARGE', 'CHARITY', 'CHARTER', 'CHASE', 'CHEAP',
  'CHECK', 'CHEESE', 'CHERRY', 'CHESS', 'CHEST', 'CHEW', 'CHICKEN', 'CHIEF', 'CHILD', 'CHILL',
  'CHIMNEY', 'CHOICE', 'CHOKE', 'CHOOSE', 'CHRONIC', 'CHUNK', 'CIRCLE', 'CITIZEN', 'CIVIC', 'CIVIL',
  'CLAIM', 'CLAP', 'CLARIFY', 'CLASP', 'CLASS', 'CLAUSE', 'CLEAN', 'CLEAR', 'CLERK', 'CLEVER',
  'CLICK', 'CLIENT', 'CLIFF', 'CLIMB', 'CLINIC', 'CLIP', 'CLOCK', 'CLONE', 'CLOSE', 'CLOSET',
  'CLOTH', 'CLOUD', 'CLOWN', 'CLUB', 'CLUE', 'CLUMP', 'CLUSTER', 'COACH', 'COAL', 'COAST',
  'COCOA', 'CODING', 'COFFEE', 'COIN', 'COLLAPSE', 'COLUMN', 'COMBAT', 'COMEDY', 'COMFORT', 'COMMAND',
  'COMMENT', 'COMMIT', 'COMMON', 'COMPANY', 'COMPARE', 'COMPEL', 'COMPETE', 'COMPLEX', 'COMPOSE', 'CONCEPT',
  'CONCERN', 'CONDO', 'CONDUCT', 'CONFIRM', 'CONNECT', 'CONQUER', 'CONSENT', 'CONSIST', 'CONSULT', 'CONTAIN',
  'CONTENT', 'CONTEST', 'CONTEXT', 'CONTROL', 'CONVERT', 'CONVEY', 'COOKIE', 'COPPER', 'COPY', 'CORD',
  'CORE', 'CORNER', 'CORRECT', 'CORRIDOR', 'COSMIC', 'COSTUME', 'COTTON', 'COUCH', 'COUGH', 'COUNCIL',
  'COUNT', 'COUNTRY', 'COUNTY', 'COUPLE', 'COURSE', 'COURT', 'COUSIN', 'COVER', 'COYOTE', 'CRACK',
  'CRADLE', 'CRAFT', 'CRASH', 'CRATER', 'CRAWL', 'CRAZY', 'CREAM', 'CREATE', 'CREDIT', 'CREW',
  'CRIME', 'CRISIS', 'CRITTER', 'CROCODILE', 'CROOK', 'CROSS', 'CROWD', 'CRUCIAL', 'CRUEL', 'CRUISE',
  'CRUMB', 'CRUNCH', 'CRUSH', 'CRYSTAL', 'CUBE', 'CUFF', 'CULTURE', 'CUPCAKE', 'CURIOUS', 'CURRENT',
  'CURTAIN', 'CURVE', 'CUSHION', 'CUSTOM', 'CUTTER', 'CYCLE', 'DAD', 'DAIRY', 'DAMAGE', 'DANCE',
  'DANGER', 'DARK', 'DARLING', 'DASH', 'DATA', 'DAWN', 'DEADLINE', 'DEAF', 'DEAL', 'DEAR',
  'DEATH', 'DEBATE', 'DEBRIS', 'DEBT', 'DECADE', 'DECAY', 'DECIDE', 'DECK', 'DECLARE', 'DECORATE',
  'DECREASE', 'DEDICATE', 'DEEP', 'DEFEAT', 'DEFEND', 'DEFINE', 'DEFY', 'DEGREE', 'DELAY', 'DELETE',
  'DELIVER', 'DEMAND', 'DENIAL', 'DENSITY', 'DEPART', 'DEPEND', 'DEPLOY', 'DEPOSIT', 'DEPRESS', 'DEPTH',
  'DERIVE', 'DESCRIBE', 'DESERT', 'DESIGN', 'DESIRE', 'DESK', 'DESPAIR', 'DESTROY', 'DETAIL', 'DETECT',
  'DETOUR', 'DEVICE', 'DEVOTE', 'DIAGRAM', 'DIALOG', 'DIAMOND', 'DICTION', 'DIET', 'DIFFER', 'DIGEST',
  'DIGITAL', 'DIGNITY', 'DILEMMA', 'DIMENSION', 'DINE', 'DINOSAUR', 'DIPLOMA', 'DIRECT', 'DIRT', 'DISABLE',
  'DISASTER', 'DISC', 'DISCOVER', 'DISH', 'DISK', 'DISMISS', 'DISPLAY', 'DISPUTE', 'DISTANT', 'DISTINCT',
  'DIVIDE', 'DIVINE', 'DIZZY', 'DOCTOR', 'DOCUMENT', 'DODGE', 'DOLLAR', 'DOLPHIN', 'DOMAIN', 'DONATE',
  'DONKEY', 'DOOM', 'DOOR', 'DOSE', 'DOUBLE', 'DOVE', 'DRAFT', 'DRAGON', 'DRAMA', 'DRASTIC',
  'DRAW', 'DREAD', 'DREAM', 'DRESS', 'DRILL', 'DRINK', 'DRIVE', 'DROWN', 'DRUM', 'DRUNK',
  'DRYER', 'DUAL', 'DUBIOUS', 'DUCHESS', 'DUCK', 'DUKE', 'DULL', 'DUMP', 'DUO', 'DURATION',
  'DURING', 'DUST', 'DUTY', 'DWELL', 'DYNAMIC', 'EAGER', 'EAGLE', 'EARTH', 'EASE', 'EAST',
  'EASY', 'ECHO', 'ECLIPSE', 'EDGE', 'EDIT', 'EDUCATE', 'EFFECT', 'EFFORT', 'EGG', 'EIGHT',
  'EITHER', 'ELASTIC', 'ELBOW', 'ELDER', 'ELECT', 'ELEGANT', 'ELEMENT', 'ELEVATE', 'ELITE', 'ELSE',
  'EMBARK', 'EMBASSY', 'EMERALD', 'EMERGE', 'EMOTION', 'EMPEROR', 'EMPIRE', 'EMPLOY', 'ENABLE', 'ENCLOSE',
  'ENCODE', 'ENDURE', 'ENERGY', 'ENFORCE', 'ENGAGE', 'ENGINE', 'ENHANCE', 'ENJOY', 'ENLIST', 'ENOUGH',
  'ENRICH', 'ENSURE', 'ENTER', 'ENTIRE', 'ENTITY', 'ENTRY', 'ENVELOPE', 'EPISODE', 'EQUAL', 'EQUATE',
  'EQUIP', 'ERASE', 'ERECT', 'ERROR', 'ERUPT', 'ESCAPE', 'ESSAY', 'ESTATE', 'ESTIMATE', 'ETERNAL',
  'ETHICS', 'EVIDENCE', 'EVIL', 'EVOKE', 'EVOLVE', 'EXACT', 'EXAM', 'EXCEED', 'EXCEL', 'EXCEPT',
  'EXCESS', 'EXCITE', 'EXCLUDE', 'EXCUSE', 'EXECUTE', 'EXERCISE', 'EXHIBIT', 'EXILE', 'EXIST', 'EXIT',
  'EXOTIC', 'EXPAND', 'EXPECT', 'EXPENSE', 'EXPERT', 'EXPLAIN', 'EXPLORE', 'EXPORT', 'EXPOSE', 'EXTEND',
  'EXTRA', 'EXUDE', 'FABRIC', 'FACADE', 'FACIAL', 'FACTOR', 'FACTORY', 'FADE', 'FAIL', 'FAINT',
  'FAIR', 'FAITH', 'FAKE', 'FALL', 'FAMILY', 'FAMINE', 'FAMOUS', 'FANATIC', 'FANCY', 'FANG',
  'FANTASY', 'FARM', 'FASHION', 'FASTEN', 'FATAL', 'FATE', 'FATHER', 'FAULT', 'FEAR', 'FEATURE',
  'FEDERAL', 'FEEBLE', 'FEEDBACK', 'FEMALE', 'FENCE', 'FERRY', 'FESTIVAL', 'FETCH', 'FEVER', 'FEWER',
  'FIBER', 'FICTION', 'FIELD', 'FIERCE', 'FIFTEEN', 'FIGHT', 'FIGURE', 'FILE', 'FILTER', 'FINAL',
  'FINANCE', 'FIND', 'FINGER', 'FINISH', 'FIRE', 'FIRM', 'FISH', 'FIST', 'FITNESS', 'FIXED',
  'FLAG', 'FLAME', 'FLASH', 'FLAVOR', 'FLEET', 'FLESH', 'FLEX', 'FLICK', 'FLIGHT', 'FLING',
  'FLOAT', 'FLOOD', 'FLOOR', 'FLOUR', 'FLOW', 'FLOWER', 'FLUID', 'FLUSH', 'FLUTE', 'FOCUS',
  'FOGGY', 'FOLDER', 'FOLK', 'FOLLOW', 'FOND', 'FOOL', 'FOOT', 'FORBID', 'FORCE', 'FOREIGN',
  'FORGE', 'FORGET', 'FORGIVE', 'FORK', 'FORMAT', 'FORMER', 'FORTUNE', 'FORUM', 'FOSSIL', 'FOSTER',
  'FOUND', 'FOUNTAIN', 'FOX', 'FRACTURE', 'FRAME', 'FRANK', 'FRAUD', 'FREEZE', 'FRENCH', 'FREQUENT',
  'FRESH', 'FRICTION', 'FRIDAY', 'FRINGE', 'FROST', 'FROZEN', 'FRUIT', 'FULFILL', 'FUNCTION', 'FUND',
  'FUNERAL', 'FUNGUS', 'FURIOUS', 'FURNACE', 'FURNISH', 'FURROW', 'FUSION', 'FUTURE', 'FUZZY', 'GAIN',
  'GALAXY', 'GALLERY', 'GALLON', 'GAMBLE', 'GANG', 'GAP', 'GARAGE', 'GARBAGE', 'GARDEN', 'GARLIC',
  'GARRISON', 'GASOLINE', 'GATHER', 'GAUGE', 'GAZE', 'GEAR', 'GELATIN', 'GEM', 'GENE', 'GENERAL',
  'GENERATE', 'GENIUS', 'GENRE', 'GENTLE', 'GENUINE', 'GESTURE', 'GHOST', 'GIANT', 'GIFT', 'GIGANTIC',
  'GINGER', 'GIRAFFE', 'GIRL', 'GIVEN', 'GLACIER', 'GLADE', 'GLANCE', 'GLARE', 'GLASS', 'GLAZE',
  'GLEAM', 'GLIDE', 'GLIMPSE', 'GLITCH', 'GLOBAL', 'GLOOM', 'GLORY', 'GLOSS', 'GLOVE', 'GLOW',
  'GLUE', 'GOLDEN', 'GOODS', 'GORILLA', 'GOSSIP', 'GOTHIC', 'GOVERN', 'GRAB', 'GRACE', 'GRADE',
  'GRAIN', 'GRAND', 'GRANT', 'GRAPE', 'GRAPH', 'GRASP', 'GRASS', 'GRATEFUL', 'GRAVITY', 'GREAT',
  'GREED', 'GREEN', 'GREET', 'GRIEF', 'GRILL', 'GRIN', 'GRIND', 'GRIP', 'GROAN', 'GROCERY',
  'GROOM', 'GROSS', 'GROUND', 'GROWTH', 'GRUDGE', 'GRUNT', 'GUARD', 'GUESS', 'GUIDE', 'GUILT',
  'GUITAR', 'GULF', 'GUITAR', 'GUN', 'GURU', 'GUST', 'HABIT', 'HACK', 'HAIR', 'HALF',
  'HALL', 'HALT', 'HAMMER', 'HAMSTER', 'HAND', 'HANDLE', 'HAPPEN', 'HARBOR', 'HARDEN', 'HARSH',
  'HARVEST', 'HASTE', 'HATRED', 'HAUNT', 'HAVEN', 'HAVOC', 'HEAD', 'HEAL', 'HEALTH', 'HEAP',
  'HEART', 'HEAVEN', 'HEAVY', 'HEDGE', 'HEIGHT', 'HEIR', 'HELPER', 'HEN', 'HERALD', 'HERB',
  'HERO', 'HIDDEN', 'HIGHLAND', 'HIGHWAY', 'HIKE', 'HILL', 'HINT', 'HIPPO', 'HIRE', 'HISTORY',
  'HIVE', 'HOBBY', 'HOCKEY', 'HOLDER', 'HOLLOW', 'HOMAGE', 'HONEST', 'HONEY', 'HONOR', 'HOOK',
  'HORIZON', 'HORNET', 'HORROR', 'HORSE', 'HOST', 'HOTEL', 'HOUND', 'HOUR', 'HOUSE', 'HOVER',
  'HUMAN', 'HUMBLE', 'HUMOR', 'HUNGER', 'HUNTER', 'HURDLE', 'HURRICANE', 'HURRY', 'HURT', 'HUSBAND',
  'HUT', 'HYBRID', 'HYDRO', 'HYENA', 'HYGIENE', 'HYMN', 'ICEBERG', 'ICICLE', 'ICON', 'IDEAL',
  'IDENTIFY', 'IDIOM', 'IDLE', 'IGNITE', 'IGNORE', 'ILLEGAL', 'IMAGE', 'IMAGINE', 'IMMUNE', 'IMPACT',
  'IMPLANT', 'IMPLY', 'IMPORT', 'IMPOSE', 'IMPULSE', 'INCOME', 'INCREASE', 'INDEX', 'INDIAN', 'INDOOR',
  'INDUCE', 'INFANT', 'INFORM', 'INITIAL', 'INJECT', 'INJURY', 'INMATE', 'INNER', 'INPUT', 'INQUIRY',
  'INSECT', 'INSERT', 'INSIDE', 'INSIST', 'INSPECT', 'INSTALL', 'INSTANT', 'INSTINCT', 'INSULT', 'INTACT',
  'INTEND', 'INTENSE', 'INTENT', 'INTERACT', 'INTEREST', 'INTERNAL', 'INTRO', 'INVADE', 'INVENT', 'INVEST',
  'INVITE', 'INVOKE', 'INVOLVE', 'IRON', 'ISLAND', 'ISOLATE', 'ISSUE', 'ITEM', 'IVORY', 'JACKET',
  'JADE', 'JAIL', 'JAM', 'JAR', 'JAW', 'JAZZ', 'JEALOUS', 'JELLY', 'JERSEY', 'JEWEL',
  'JOIN', 'JOKE', 'JOURNAL', 'JOURNEY', 'JOY', 'JUDGE', 'JUICE', 'JUMP', 'JUNGLE', 'JURY',
  'JUSTICE', 'KANGAROO', 'KEEP', 'KERNEL', 'KETTLE', 'KEY', 'KEYBOARD', 'KIDNEY', 'KIND', 'KINGDOM',
  'KISS', 'KIT', 'KITCHEN', 'KITE', 'KITTEN', 'KNIFE', 'KNIGHT', 'KNIT', 'KNOT', 'KNOWLEDGE',
  'KOALA', 'LABEL', 'LABOR', 'LACK', 'LADDER', 'LADY', 'LAKE', 'LAMB', 'LAMP', 'LANDSCAPE',
  'LANE', 'LANGUAGE', 'LAPTOP', 'LARGE', 'LASER', 'LAST', 'LATCH', 'LATTER', 'LAUGH', 'LAUNCH',
  'LAUNDRY', 'LAVA', 'LAWYER', 'LAYER', 'LEADER', 'LEAF', 'LEAGUE', 'LEAK', 'LEARN', 'LEASE',
  'LEATHER', 'LEAVE', 'LECTURE', 'LEGACY', 'LEGAL', 'LEGEND', 'LEMON', 'LENGTH', 'LENS', 'LEOPARD',
  'LESSON', 'LETTER', 'LEVEL', 'LEVER', 'LIBRARY', 'LICENSE', 'LID', 'LIFE', 'LIFT', 'LIGHT',
  'LIMB', 'LIMIT', 'LINK', 'LION', 'LIQUID', 'LISTEN', 'LITTER', 'LITTLE', 'LIVE', 'LIZARD',
  'LOAD', 'LOAN', 'LOBSTER', 'LOCAL', 'LOCK', 'LODGE', 'LOFT', 'LOGIC', 'LONE', 'LONG',
  'LOOP', 'LORD', 'LOSS', 'LOUNGE', 'LOVE', 'LOYAL', 'LUCK', 'LUGGAGE', 'LUMBER', 'LUNAR',
  'LUNCH', 'LUNG', 'LUXURY', 'LYRIC', 'MACHINE', 'MADAM', 'MAGAZINE', 'MAGIC', 'MAIN', 'MAJOR',
  'MAKER', 'MAMMAL', 'MANAGE', 'MANDATE', 'MANGROVE', 'MANIA', 'MANNER', 'MANTLE', 'MANUAL', 'MAPLE',
  'MARBLE', 'MARCH', 'MARGIN', 'MARINE', 'MARKER', 'MARKET', 'MARROW', 'MARS', 'MASCOT', 'MASK',
  'MASTER', 'MATCH', 'MATRIX', 'MATTER', 'MATURE', 'MAXIMUM', 'MAYOR', 'MEADOW', 'MEAL', 'MEAN',
  'MEASURE', 'MEDAL', 'MEDIA', 'MEDICAL', 'MELODY', 'MELT', 'MEMBER', 'MEMORY', 'MENTOR', 'MENU'
];
const HANGMAN_ART = [
  '',
  '  +---+\n  |   |\n      |\n      |\n      |\n      |\n=========',
  '  +---+\n  |   |\n  O   |\n      |\n      |\n      |\n=========',
  '  +---+\n  |   |\n  O   |\n  |   |\n      |\n      |\n=========',
  '  +---+\n  |   |\n  O   |\n /|   |\n      |\n      |\n=========',
  '  +---+\n  |   |\n  O   |\n /|\\  |\n      |\n      |\n=========',
  '  +---+\n  |   |\n  O   |\n /|\\  |\n /    |\n      |\n=========',
  '  +---+\n  |   |\n  O   |\n /|\\  |\n / \\  |\n      |\n========='
];

async function startHangman() {
  showGameBoard('Hangman');
  currentGameType = 'hangman';
  let state = await gameFetchState('hangman');
  if (!state) {
    if (hangmanPool.length === 0) {
      hangmanPool = [...HANGMAN_WORDS].sort(() => Math.random() - 0.5);
    }
    const idx = Math.floor(Math.random() * hangmanPool.length);
    const word = hangmanPool.splice(idx, 1)[0];
    state = {
      word,
      guessed: [],
      wrongGuesses: 0,
      maxWrong: HANGMAN_ART.length - 1,
      phase: 'playing',
      remaining: hangmanPool.length
    };
    await gameSyncSave('hangman', state);
  }
  hmState = state;
  hmState.guessed = new Set(state.guessed || []);
  renderHM();
  startGamePolling();
}

function renderHM() {
  const s = hmState;
  gameBoardArea.innerHTML = '';

  const container = document.createElement('div');
  container.className = 'hm-container';

  const art = document.createElement('div');
  art.className = 'hm-art';
  art.textContent = HANGMAN_ART[s.wrongGuesses];
  container.appendChild(art);

  const displayWord = s.word.split('').map(c => s.guessed.has(c) ? c : '_').join(' ');
  const wordEl = document.createElement('div');
  wordEl.className = 'hm-word';
  wordEl.textContent = displayWord;
  container.appendChild(wordEl);

  if (s.phase === 'won') {
    gameBoardStatus.textContent = 'Player wins! 🎉';
    const btn = document.createElement('button');
    btn.textContent = 'Play Again';
    btn.style.cssText = 'margin-top:8px;padding:8px 20px;background:#e94560;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;';
    btn.addEventListener('click', startHangman);
    container.appendChild(btn);
    gameBoardArea.appendChild(container);
    return;
  }

  if (s.phase === 'lost') {
    gameBoardStatus.textContent = 'Game Over!';
    const reveal = document.createElement('div');
    reveal.style.cssText = 'font-size:16px;color:#ffa500;font-weight:700;';
    reveal.textContent = 'The word was: ' + s.word;
    container.appendChild(reveal);
    const btn = document.createElement('button');
    btn.textContent = 'Try Again';
    btn.style.cssText = 'margin-top:8px;padding:8px 20px;background:#e94560;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;';
    btn.addEventListener('click', startHangman);
    container.appendChild(btn);
    gameBoardArea.appendChild(container);
    return;
  }

  gameBoardStatus.textContent = s.wrongGuesses + '/' + s.maxWrong + ' wrong - Pick a letter';

  const guesses = document.createElement('div');
  guesses.className = 'hm-guesses';
  guesses.textContent = 'Wrong: ' + ([...s.guessed].filter(c => !s.word.includes(c)).join(', ') || 'none');
  container.appendChild(guesses);

  const remaining = document.createElement('div');
  remaining.style.cssText = 'font-size:11px;color:#666;margin-top:2px;';
  remaining.textContent = s.remaining + ' unique words remaining in pool';
  container.appendChild(remaining);

  const letters = document.createElement('div');
  letters.className = 'hm-letters';
  for (let i = 65; i <= 90; i++) {
    const c = String.fromCharCode(i);
    const btn = document.createElement('button');
    btn.className = 'hm-letter-btn';
    btn.textContent = c;
    if (s.guessed.has(c)) {
      btn.classList.add('used');
      if (s.word.includes(c)) btn.classList.add('correct');
      else btn.classList.add('wrong');
    }
    btn.addEventListener('click', () => hmGuess(c));
    letters.appendChild(btn);
  }
  container.appendChild(letters);

  gameBoardArea.appendChild(container);
}

function hmGuess(letter) {
  const s = hmState;
  if (s.phase !== 'playing' || s.guessed.has(letter)) return;
  s.guessed.add(letter);
  if (!s.word.includes(letter)) {
    s.wrongGuesses++;
    if (s.wrongGuesses >= s.maxWrong) s.phase = 'lost';
  } else {
    if (s.word.split('').every(c => s.guessed.has(c))) s.phase = 'won';
  }
  s.guessed = [...s.guessed];
  gameSyncSave('hangman', s);
  s.guessed = new Set(s.guessed);
  renderHM();
}

// ===== CONNECT 4 =====
async function startConnect4() {
  showGameBoard('Connect 4');
  currentGameType = 'connect4';
  let state = await gameFetchState('connect4');
  if (!state) {
    state = {
      board: Array(6).fill(null).map(() => Array(7).fill(null)),
      currentPlayer: 1,
      winner: null,
      phase: 'playing'
    };
    await gameSyncSave('connect4', state);
  }
  c4State = state;
  renderC4();
  startGamePolling();
}

function renderC4() {
  const s = c4State;
  gameBoardArea.innerHTML = '';

  if (s.winner) {
    gameBoardStatus.textContent = s.winner === 'draw' ? "It's a draw!" : 'Player ' + s.winner + ' wins! 🎉';
  } else {
    gameBoardStatus.textContent = "Player " + s.currentPlayer + "'s turn - click a column";
  }

  const grid = document.createElement('div');
  grid.className = 'c4-grid';

  for (let r = 0; r < 6; r++) {
    for (let c = 0; c < 7; c++) {
      const cell = document.createElement('div');
      cell.className = 'c4-cell';
      if (s.board[r][c] === 1) cell.classList.add('p1');
      else if (s.board[r][c] === 2) cell.classList.add('p2');
      if (s.winner && s.winner !== 'draw') cell.classList.add('win');
      cell.addEventListener('click', () => c4Drop(c));
      grid.appendChild(cell);
    }
  }

  gameBoardArea.appendChild(grid);

  if (s.winner) {
    const btn = document.createElement('button');
    btn.textContent = 'Play Again';
    btn.style.cssText = 'margin-top:12px;padding:8px 20px;background:#e94560;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;';
    btn.addEventListener('click', async () => {
      const fresh = { board: Array(6).fill(null).map(() => Array(7).fill(null)), currentPlayer: 1, winner: null, phase: 'playing' };
      await gameSyncSave('connect4', fresh);
      c4State = fresh;
      renderC4();
    });
    gameBoardArea.appendChild(btn);
  }
}

function c4Drop(col) {
  const s = c4State;
  if (s.phase !== 'playing' || s.winner) return;
  for (let r = 5; r >= 0; r--) {
    if (!s.board[r][col]) {
      s.board[r][col] = s.currentPlayer;
      if (checkC4Win(r, col, s.currentPlayer, s.board)) {
        s.winner = s.currentPlayer;
      } else if (s.board.every(row => row.every(c => c))) {
        s.winner = 'draw';
      }
      s.currentPlayer = s.currentPlayer === 1 ? 2 : 1;
      gameSyncSave('connect4', s);
      renderC4();
      return;
    }
  }
}

function checkC4Win(row, col, player, board) {
  const dirs = [[0,1],[1,0],[1,1],[1,-1]];
  for (const [dr, dc] of dirs) {
    let count = 1;
    for (let sign of [-1, 1]) {
      for (let i = 1; i < 4; i++) {
        const r = row + dr * i * sign;
        const c = col + dc * i * sign;
        if (r >= 0 && r < 6 && c >= 0 && c < 7 && board[r][c] === player) count++;
        else break;
      }
    }
    if (count >= 4) return true;
  }
  return false;
}

// ===== GUESS THE NUMBER =====
async function startGuessTheNumber() {
  showGameBoard('Guess the Number');
  currentGameType = 'guessthenumber';
  let state = await gameFetchState('guessthenumber');
  if (!state) {
    state = {
      number: Math.floor(Math.random() * 100) + 1,
      attempts: [],
      phase: 'p1pick',
      maxAttempts: 10
    };
    await gameSyncSave('guessthenumber', state);
  }
  gtnState = state;
  renderGTN();
  startGamePolling();
}

function renderGTN() {
  const s = gtnState;
  gameBoardArea.innerHTML = '';

  const container = document.createElement('div');
  container.className = 'gtn-container';

  if (s.phase === 'p1pick') {
    gameBoardStatus.textContent = 'Player 1: Enter a number for Player 2 to guess (1-100)';
    const inputArea = document.createElement('div');
    inputArea.className = 'gtn-input-area';
    const input = document.createElement('input');
    input.type = 'number';
    input.min = 1;
    input.max = 100;
    input.placeholder = '1-100';
    input.focus();
    const btn = document.createElement('button');
    btn.textContent = 'Set Number';
    btn.style.cssText = 'padding:8px 16px;background:#e94560;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;';
    btn.addEventListener('click', () => {
      const val = parseInt(input.value);
      if (isNaN(val) || val < 1 || val > 100) return;
      s.number = val;
      s.phase = 'guessing';
      gameSyncSave('guessthenumber', s);
      renderGTN();
    });
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') btn.click(); });
    inputArea.appendChild(input);
    inputArea.appendChild(btn);
    container.appendChild(inputArea);
    gameBoardArea.appendChild(container);
    return;
  }

  if (s.phase === 'won') {
    gameBoardStatus.textContent = 'Player 2 guessed it! 🎉';
  } else if (s.phase === 'lost') {
    gameBoardStatus.textContent = 'Out of attempts! The number was ' + s.number;
  } else {
    gameBoardStatus.textContent = 'Player 2: Guess the number (1-100) — Attempts: ' + (s.maxAttempts - s.attempts.length);
  }

  const history = document.createElement('div');
  history.className = 'gtn-history';
  for (const a of s.attempts) {
    const row = document.createElement('div');
    row.className = 'gtn-guess';
    const numSpan = document.createElement('span');
    numSpan.textContent = a.guess;
    const hintSpan = document.createElement('span');
    hintSpan.className = 'gtn-hint ' + a.hintClass;
    hintSpan.textContent = a.hint;
    row.appendChild(numSpan);
    row.appendChild(hintSpan);
    history.appendChild(row);
  }
  container.appendChild(history);

  const attemptsLabel = document.createElement('div');
  attemptsLabel.className = 'gtn-attempts';
  attemptsLabel.textContent = s.attempts.length + '/' + s.maxAttempts + ' attempts used';
  container.appendChild(attemptsLabel);

  if (s.phase === 'guessing') {
    const inputArea = document.createElement('div');
    inputArea.className = 'gtn-input-area';
    const input = document.createElement('input');
    input.type = 'number';
    input.min = 1;
    input.max = 100;
    input.placeholder = 'Guess';
    input.focus();
    const btn = document.createElement('button');
    btn.textContent = 'Guess';
    btn.style.cssText = 'padding:8px 16px;background:#e94560;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;';
    btn.addEventListener('click', () => {
      const val = parseInt(input.value);
      if (isNaN(val) || val < 1 || val > 100) return;
      gtnGuess(val);
    });
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') btn.click(); });
    inputArea.appendChild(input);
    inputArea.appendChild(btn);
    container.appendChild(inputArea);
  } else {
    const btn = document.createElement('button');
    btn.textContent = 'Play Again';
    btn.style.cssText = 'margin-top:8px;padding:8px 20px;background:#e94560;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;';
    btn.addEventListener('click', startGuessTheNumber);
    container.appendChild(btn);
  }

  gameBoardArea.appendChild(container);
}

// ===== MEMORY MATCH =====
const MEMORY_EMOJIS = ['🍎', '🍌', '🍇', '🍉', '🍒', '🍓', '🍍', '🥝'];
async function startMemory() {
  showGameBoard('Memory Match');
  currentGameType = 'memory';
  let state = await gameFetchState('memory');
  if (!state) {
    const deck = [...MEMORY_EMOJIS, ...MEMORY_EMOJIS].sort(() => Math.random() - 0.5);
    state = {
      deck,
      flipped: [],
      matched: [],
      locked: false,
      currentPlayer: 1,
      scores: [0, 0],
      winner: null,
    };
    await gameSyncSave('memory', state);
  }
  memState = state;
  memState.matched = new Set(state.matched || []);
  renderMemory();
  startGamePolling();
}

function renderMemory() {
  const s = memState;
  gameBoardArea.innerHTML = '';
  if (s.winner) {
    gameBoardStatus.textContent = s.winner === 'draw' ? "It's a draw!" : 'Player ' + s.winner + ' wins! 🎉';
  } else {
    gameBoardStatus.textContent = "Player " + s.currentPlayer + "'s turn — P1: " + s.scores[0] + " P2: " + s.scores[1];
  }
  const grid = document.createElement('div');
  grid.className = 'mm-grid';
  s.deck.forEach((emoji, i) => {
    const div = document.createElement('div');
    div.className = 'mm-card';
    if (s.matched.has(i)) {
      div.classList.add('matched');
      div.textContent = emoji;
    } else if (s.flipped.includes(i)) {
      div.classList.add('flipped');
      div.textContent = emoji;
    } else {
      div.textContent = '?';
    }
    div.addEventListener('click', () => memoryFlip(i));
    grid.appendChild(div);
  });
  gameBoardArea.appendChild(grid);
  if (s.matched.size === s.deck.length && !s.winner) {
    s.winner = s.scores[0] > s.scores[1] ? 1 : (s.scores[1] > s.scores[0] ? 2 : 'draw');
    renderMemory();
    return;
  }
  if (s.winner) {
    const btn = document.createElement('button');
    btn.textContent = 'Play Again';
    btn.style.cssText = 'margin-top:12px;padding:8px 20px;background:#e94560;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;';
    btn.addEventListener('click', startMemory);
    gameBoardArea.appendChild(btn);
  }
}

function memoryFlip(index) {
  const s = memState;
  if (s.locked || s.matched.has(index) || s.flipped.includes(index)) return;
  s.flipped.push(index);
  if (s.flipped.length === 2) {
    s.locked = true;
    const [a, b] = s.flipped;
    if (s.deck[a] === s.deck[b]) {
      s.matched.add(a);
      s.matched.add(b);
      s.scores[s.currentPlayer - 1]++;
      s.flipped = [];
      s.locked = false;
      renderMemory();
      gameSyncSave('memory', normalizeMemState(s));
    } else {
      setTimeout(() => {
        s.flipped = [];
        s.locked = false;
        s.currentPlayer = s.currentPlayer === 1 ? 2 : 1;
        renderMemory();
        gameSyncSave('memory', normalizeMemState(s));
      }, 700);
    }
  } else {
    gameSyncSave('memory', normalizeMemState(s));
  }
  renderMemory();
}

function normalizeMemState(s) {
  return { deck: s.deck, flipped: s.flipped, matched: [...s.matched], locked: s.locked, currentPlayer: s.currentPlayer, scores: s.scores, winner: s.winner };
}

// ===== HIGHER OR LOWER =====
let hlDeck = [];
async function startHigherLower() {
  showGameBoard('Higher or Lower');
  currentGameType = 'higherlower';
  let state = await gameFetchState('higherlower');
  if (!state) {
    hlDeck = [];
    for (let i = 1; i <= 13; i++) hlDeck.push(i);
    hlDeck = hlDeck.sort(() => Math.random() - 0.5);
    const current = hlDeck.pop();
    state = { current, round: 1, maxRounds: 10, guess: null, over: false, _deck: hlDeck, _turn: 0, _players: [username], _scores: [0, 0] };
    await gameSyncSave('higherlower', state);
  }
  hlState = state;
  hlDeck = state._deck || [];
  renderHL();
  startGamePolling();
}

function renderHL() {
  const s = hlState;
  gameBoardArea.innerHTML = '';
  const p1 = (s._players || [])[0] || 'P1';
  const p2 = (s._players || [])[1] || 'P2';
  if (s.over) {
    const sc = s._scores || [0, 0];
    const hlwinner = sc[0] > sc[1] ? p1 : (sc[1] > sc[0] ? p2 : 'Draw');
    gameBoardStatus.textContent = hlwinner === 'Draw' ? "It's a draw!" : hlwinner + ' wins! ' + p1 + ': ' + sc[0] + ' ' + p2 + ': ' + sc[1];
    const btn = document.createElement('button');
    btn.textContent = 'Play Again';
    btn.style.cssText = 'margin-top:12px;padding:8px 20px;background:#e94560;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;';
    btn.addEventListener('click', startHigherLower);
    gameBoardArea.appendChild(btn);
    return;
  }
  const sc = s._scores || [0, 0];
  const turnName = ((s._players || [])[s._turn || 0]) || 'Player';
  gameBoardStatus.textContent = 'Round ' + s.round + '/' + s.maxRounds + ' — ' + turnName + "'s turn — " + p1 + ': ' + sc[0] + ' | ' + p2 + ': ' + sc[1];
  const container = document.createElement('div');
  container.style.cssText = 'text-align:center;';
  const card = document.createElement('div');
  card.className = 'hl-card-display';
  card.textContent = s.current;
  container.appendChild(card);
  if (s.guess !== null) {
    const res = document.createElement('div');
    res.style.cssText = 'font-size:14px;font-weight:700;margin-bottom:8px;color:' + (s.guess.correct ? '#4caf50' : '#e94560') + ';';
    res.textContent = s.guess.correct ? 'Correct!' : 'Wrong!';
    container.appendChild(res);
  }
  const myTurn = s._players && s._players[s._turn] && s._players[s._turn].toLowerCase() === username.toLowerCase();
  if (myTurn) {
    const btns = document.createElement('div');
    btns.className = 'hl-btns';
    ['Higher', 'Lower'].forEach(label => {
      const btn = document.createElement('button');
      btn.textContent = label === 'Higher' ? 'Higher ▲' : 'Lower ▼';
      btn.style.cssText = 'padding:10px 24px;background:#e94560;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:14px;';
      btn.addEventListener('click', () => hlGuess(label.toLowerCase()));
      btns.appendChild(btn);
    });
    container.appendChild(btns);
  } else {
    const wait = document.createElement('div');
    wait.style.cssText = 'font-size:13px;color:#888;margin-top:12px;';
    wait.textContent = 'Waiting for ' + turnName + ' to guess...';
    container.appendChild(wait);
  }
  gameBoardArea.appendChild(container);
}

function hlGuess(guess) {
  const s = hlState;
  if (s.over) return;
  const myTurn = s._players && s._players[s._turn] && s._players[s._turn].toLowerCase() === username.toLowerCase();
  if (!myTurn) return;
  const next = hlDeck.pop();
  const correct = (guess === 'higher' && next > s.current) || (guess === 'lower' && next < s.current);
  s.guess = { correct };
  if (correct) s._scores[s._turn || 0]++;
  s.round++;
  if (s.round > s.maxRounds || hlDeck.length === 0) {
    s.over = true;
  }
  s.current = next;
  s._deck = hlDeck;
  s._turn = ((s._turn || 0) === 0) ? 1 : 0;
  gameSyncSave('higherlower', s);
  renderHL();
}

// ===== WORD SCRAMBLE =====
const SCRAMBLE_WORDS = ['JOHNSON', 'CHAT', 'GAME', 'ROCKET', 'PIXEL', 'NINJA', 'PHANTOM', 'CRYSTAL', 'THUNDER', 'PYTHON', 'STREAM', 'CLOUD', 'NEXUS', 'EMBER', 'DRAGON', 'WIZARD', 'KINGDOM', 'ADVENTURE'];
async function startWordScramble() {
  showGameBoard('Word Scramble');
  currentGameType = 'wordscramble';
  let state = await gameFetchState('wordscramble');
  if (!state || state.phase === 'revealed' || state._win !== undefined && state._win !== null) {
    const word = SCRAMBLE_WORDS[Math.floor(Math.random() * SCRAMBLE_WORDS.length)];
    const scrambled = word.split('').sort(() => Math.random() - 0.5).join('');
    state = { word, scrambled, attempts: 0, phase: 'playing', lastMsg: null, _turn: 0, _players: [username], _scores: [0, 0], _win: null };
    await gameSyncSave('wordscramble', state);
  }
  wsState = state;
  renderWS();
  startGamePolling();
}

function renderWS() {
  const s = wsState;
  gameBoardArea.innerHTML = '';
  const container = document.createElement('div');
  container.style.cssText = 'text-align:center;';
  const w = document.createElement('div');
  w.className = 'ws-word';
  if (s.phase === 'revealed') {
    w.textContent = s.word;
  } else {
    w.textContent = s.scrambled;
  }
  container.appendChild(w);
  if (s.lastMsg) {
    const m = document.createElement('div');
    m.style.cssText = 'font-size:14px;font-weight:700;margin-bottom:8px;color:' + (s.lastMsg.ok ? '#4caf50' : '#e94560') + ';';
    m.textContent = s.lastMsg.text;
    container.appendChild(m);
  }
  const p1 = (s._players || [])[0] || 'P1';
  const p2 = (s._players || [])[1] || 'P2';
  const sc = s._scores || [0, 0];
  const turnName = ((s._players || [])[s._turn || 0]) || 'Player';
  if ((s._win || s.phase) === 'revealed') {
    const winnerName = s._win !== undefined && s._win !== null ? turnName : 'Player';
    const resultSpan = document.createElement('div');
    resultSpan.style.cssText = 'font-size:16px;font-weight:700;margin:8px 0;color:#ffa500;';
    resultSpan.textContent = s.lastMsg && s.lastMsg.ok ? (winnerName + ' guessed it! ' + p1 + ': ' + sc[0] + ' | ' + p2 + ': ' + sc[1]) : 'The word was ' + s.word;
    container.appendChild(resultSpan);
    const btn = document.createElement('button');
    btn.textContent = 'Play Again';
    btn.style.cssText = 'margin-top:12px;padding:8px 20px;background:#e94560;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;';
    btn.addEventListener('click', startWordScramble);
    container.appendChild(btn);
    gameBoardArea.appendChild(container);
    gameBoardStatus.textContent = 'Word Scramble';
    return;
  }
  if (s.phase === 'playing') {
    const myTurn = s._players && s._players[s._turn] && s._players[s._turn].toLowerCase() === username.toLowerCase();
    if (myTurn) {
      const row = document.createElement('div');
      row.className = 'ws-input-row';
      const input = document.createElement('input');
      input.placeholder = 'Your guess';
      input.maxLength = 20;
      input.style.cssText = 'padding:8px 12px;border:1px solid rgba(255,165,0,0.4);border-radius:8px;background:rgba(15,52,96,0.5);color:#fff;width:160px;';
      const btn = document.createElement('button');
      btn.textContent = 'Guess';
      btn.style.cssText = 'padding:8px 16px;background:#e94560;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;';
      const doGuess = () => {
        if (input.value.toUpperCase() === s.word) {
          s.phase = 'revealed';
          s.lastMsg = { ok: true, text: 'Correct!' };
          s._win = s._turn;
          s._scores[s._turn]++;
        } else {
          s.attempts++;
          s.lastMsg = { ok: false, text: 'Wrong! Try again (' + s.attempts + ' attempts)' };
          if ((s._players || []).length > 1) s._turn = 1 - (s._turn || 0);
        }
        gameSyncSave('wordscramble', s);
        renderWS();
      };
      btn.addEventListener('click', doGuess);
      input.addEventListener('keydown', (e) => { if (e.key === 'Enter') doGuess(); });
      row.appendChild(input);
      row.appendChild(btn);
      container.appendChild(row);
    } else {
      const wait = document.createElement('div');
      wait.style.cssText = 'font-size:13px;color:#888;margin-top:12px;';
      wait.textContent = 'Waiting for ' + turnName + ' to guess...';
      container.appendChild(wait);
    }
    gameBoardStatus.textContent = turnName + "'s turn — " + p1 + ': ' + sc[0] + ' | ' + p2 + ': ' + sc[1] + ' — Unscramble the word';
  }
  gameBoardArea.appendChild(container);
}

// ===== DICE DUEL =====
async function startDiceDuel() {
  showGameBoard('Dice Duel');
  currentGameType = 'diceduel';
  let state = await gameFetchState('diceduel');
  if (!state || state.round > state.maxRounds) {
    const prevPlayers = state && state._players && state._players.length > 0 ? state._players : [username];
    state = { p1: null, p2: null, round: 1, maxRounds: 5, p1Score: 0, p2Score: 0, result: null, _players: prevPlayers };
    await gameSyncSave('diceduel', state);
  }
  ddState = state;
  renderDD();
  startGamePolling();
}

function renderDD() {
  const s = ddState;
  gameBoardArea.innerHTML = '';
  const container = document.createElement('div');
  container.style.cssText = 'text-align:center;';
  const p1n = (s._players || [])[0] || 'P1';
  const p2n = (s._players || [])[1] || 'P2';
  if (s.p1 !== null && s.p2 !== null) {
    gameBoardStatus.textContent = 'Round ' + s.round + ' of ' + s.maxRounds + ' — ' + p1n + ': ' + s.p1Score + ' ' + p2n + ': ' + s.p2Score;
    const row = document.createElement('div');
    row.className = 'dd-dice-row';
    const d1 = document.createElement('div');
    d1.className = 'dd-die p1';
    d1.textContent = '🎲' + s.p1;
    const d2 = document.createElement('div');
    d2.className = 'dd-die p2';
    d2.textContent = '🎲' + s.p2;
    row.appendChild(d1);
    row.appendChild(d2);
    container.appendChild(row);
    if (s.result) {
      const r = document.createElement('div');
      r.style.cssText = 'font-size:16px;font-weight:700;margin:8px 0;color:#ffa500;';
      r.textContent = s.result;
      container.appendChild(r);
    }
  } else {
    if (s.round > s.maxRounds) {
      gameBoardStatus.textContent = 'Final — ' + p1n + ': ' + s.p1Score + ' ' + p2n + ': ' + s.p2Score;
    } else {
      gameBoardStatus.textContent = 'Round ' + s.round + ' of ' + s.maxRounds + ' — Click to roll';
    }
  }
  const btn = document.createElement('button');
  const myIndex = (s._players || []).findIndex(p => p.toLowerCase() === username.toLowerCase());
  const wantSlot = myIndex === 0 ? 'p1' : (myIndex === 1 ? 'p2' : null);
  const canRoll = (wantSlot === 'p1' && s.p1 === null) || (wantSlot === 'p2' && s.p1 !== null && s.p2 === null);
  const waitingOn = s.p1 === null ? (s._players && s._players[0] ? s._players[0] + ' to roll' : 'a player to join') : (s.p2 === null ? 'other player to roll' : '');
  if (s.round > s.maxRounds) {
    btn.textContent = 'Play Again';
    btn.addEventListener('click', startDiceDuel);
  } else if (canRoll) {
    btn.textContent = 'Player ' + (wantSlot === 'p1' ? '1' : '2') + ' Roll';
    btn.addEventListener('click', () => ddRoll(wantSlot));
  } else {
    btn.textContent = 'Waiting for ' + waitingOn + '...';
    btn.disabled = true;
  }
  btn.style.cssText = 'margin-top:12px;padding:10px 24px;background:#e94560;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:14px;';
  container.appendChild(btn);
  gameBoardArea.appendChild(container);
}

function ddRoll(slot) {
  const s = ddState;
  if (s.round > s.maxRounds) return;
  if (slot === 'p1' && s.p1 === null) {
    s.p1 = Math.floor(Math.random() * 6) + 1;
    s.result = null;
    gameSyncSave('diceduel', s);
    renderDD();
  } else if (slot === 'p2' && s.p2 === null) {
    s.p2 = Math.floor(Math.random() * 6) + 1;
    const p1n = (s._players || [])[0] || 'P1';
    const p2n = (s._players || [])[1] || 'P2';
    if (s.p1 > s.p2) { s.p1Score++; s.result = p1n + ' wins the round!'; }
    else if (s.p2 > s.p1) { s.p2Score++; s.result = p2n + ' wins the round!'; }
    else { s.result = 'Tie!'; }
    gameSyncSave('diceduel', s);
    renderDD();
    setTimeout(() => {
      s.p1 = null;
      s.p2 = null;
      s.round++;
      s.result = null;
      gameSyncSave('diceduel', s);
      renderDD();
    }, 700);
  }
}

// ===== MINESWEEPER =====
function startMinesweeper() {
  showGameBoard('Minesweeper');
  const grid = Array.from({ length: 64 }, () => ({ mine: false, revealed: false, flagged: false, adjacent: 0 }));
  let placed = 0;
  while (placed < 10) {
    const idx = Math.floor(Math.random() * 64);
    if (!grid[idx].mine) { grid[idx].mine = true; placed++; }
  }
  for (let i = 0; i < 64; i++) {
    const r = Math.floor(i / 8);
    const c = i % 8;
    let adj = 0;
    for (let dr = -1; dr <= 1; dr++) {
      for (let dc = -1; dc <= 1; dc++) {
        if (dr === 0 && dc === 0) continue;
        const nr = r + dr;
        const nc = c + dc;
        if (nr >= 0 && nr < 8 && nc >= 0 && nc < 8 && grid[nr * 8 + nc].mine) adj++;
      }
    }
    grid[i].adjacent = adj;
  }
  msState = { grid, phase: 'playing', flagsLeft: 10 };
  renderMS();
}

function msAdjacentReveal(grid, idx) {
  const r = Math.floor(idx / 8);
  const c = idx % 8;
  for (let dr = -1; dr <= 1; dr++) {
    for (let dc = -1; dc <= 1; dc++) {
      if (dr === 0 && dc === 0) continue;
      const nr = r + dr;
      const nc = c + dc;
      if (nr >= 0 && nr < 8 && nc >= 0 && nc < 8) {
        const n = nr * 8 + nc;
        const cell = grid[n];
        if (!cell.revealed && !cell.flagged) {
          cell.revealed = true;
          if (cell.adjacent === 0 && !cell.mine) msAdjacentReveal(grid, n);
        }
      }
    }
  }
}

function renderMS() {
  const s = msState;
  gameBoardArea.innerHTML = '';
  if (s.phase === 'won') {
    gameBoardStatus.textContent = 'You cleared the mines! 🎉';
  } else if (s.phase === 'lost') {
    gameBoardStatus.textContent = 'BOOM! You hit a mine 💥';
  } else {
    gameBoardStatus.textContent = 'Mines left: ' + s.flagsLeft;
  }
  const grid = document.createElement('div');
  grid.className = 'ms-grid';
  s.grid.forEach((cell, i) => {
    const div = document.createElement('div');
    div.className = 'ms-cell';
    if (cell.revealed) {
      div.classList.add('revealed');
      if (cell.mine) {
        div.classList.add('mine-hit');
        div.textContent = '💣';
      } else if (cell.adjacent > 0) {
        div.textContent = cell.adjacent;
      }
    } else if (cell.flagged) {
      div.classList.add('flagged');
      div.textContent = '🚩';
    }
    div.addEventListener('click', () => msReveal(i));
    div.addEventListener('contextmenu', (e) => { e.preventDefault(); msFlag(i); });
    grid.appendChild(div);
  });
  gameBoardArea.appendChild(grid);
  if (s.phase !== 'playing') {
    const btn = document.createElement('button');
    btn.textContent = 'Play Again';
    btn.style.cssText = 'margin-top:12px;padding:8px 20px;background:#e94560;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;';
    btn.addEventListener('click', startMinesweeper);
    gameBoardArea.appendChild(btn);
  }
}

function msReveal(index) {
  const s = msState;
  if (s.phase !== 'playing') return;
  const cell = s.grid[index];
  if (cell.revealed || cell.flagged) return;
  if (cell.mine) {
    cell.revealed = true;
    s.phase = 'lost';
    renderMS();
    return;
  }
  cell.revealed = true;
  if (cell.adjacent === 0) msAdjacentReveal(s.grid, index);
  const safe = s.grid.filter(c => !c.mine).every(c => c.revealed);
  if (safe) {
    s.phase = 'won';
  }
  renderMS();
}

function msFlag(index) {
  const s = msState;
  if (s.phase !== 'playing') return;
  const cell = s.grid[index];
  if (cell.revealed) return;
  cell.flagged = !cell.flagged;
  s.flagsLeft += cell.flagged ? -1 : 1;
  renderMS();
}

function openProfile(targetUser) {
  const modal = document.getElementById('profile-modal');
  const usernameEl = document.getElementById('profile-username');
  const avatar = document.getElementById('profile-avatar');
  const uploadSection = document.getElementById('profile-upload-section');
  const editSection = document.getElementById('profile-edit-section');
  usernameEl.textContent = targetUser;
  uploadSection.classList.add('hidden');
  editSection.classList.add('hidden');
  document.getElementById('profile-delete-btn')?.classList.add('hidden');
  if (targetUser === username) {
    uploadSection.classList.remove('hidden');
    editSection.classList.remove('hidden');
    document.getElementById('profile-delete-btn')?.classList.remove('hidden');
  }
  fetch(`${API_BASE}/profile.php?username=${encodeURIComponent(targetUser)}`)
    .then(r => r.json())
    .then(data => {
      const u = data.user || data;
      document.getElementById('profile-name').textContent = u.full_name || '';
      document.getElementById('profile-role').textContent = u.role || 'user';
      document.getElementById('profile-joined').textContent = u.created_at ? 'Joined ' + u.created_at : '';
      if (u.avatar_url) avatar.src = u.avatar_url;
      if (targetUser === username) {
        document.getElementById('edit-name').value = u.full_name || '';
        document.getElementById('edit-age').value = u.age || '';
        document.getElementById('edit-state').value = u.state || '';
        document.getElementById('edit-city').value = u.city || '';
        document.getElementById('edit-desc').value = u.description || '';
      }
    })
    .catch(() => {});
  modal.classList.remove('hidden');
}

document.getElementById('profile-close').addEventListener('click', () => {
  document.getElementById('profile-modal').classList.add('hidden');
});

document.getElementById('profile-save-btn').addEventListener('click', () => {
  const errEl = document.getElementById('profile-edit-error');
  errEl.classList.add('hidden');
  const payload = {
    token,
    full_name: document.getElementById('edit-name').value.trim(),
    age: document.getElementById('edit-age').value.trim(),
    state: document.getElementById('edit-state').value.trim(),
    city: document.getElementById('edit-city').value.trim(),
    description: document.getElementById('edit-desc').value.trim(),
  };
  fetch(`${API_BASE}/profile.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        _lastActiveUsersKey = '';
        fetchActiveUsers();
        fetchFriends();
        document.getElementById('profile-modal').classList.add('hidden');
      } else {
        errEl.textContent = data.error || 'Failed to save profile';
        errEl.classList.remove('hidden');
      }
    })
    .catch(() => {
      errEl.textContent = 'Failed to save profile';
      errEl.classList.remove('hidden');
    });
});

document.getElementById('profile-upload-btn')?.addEventListener('click', () => {
  document.getElementById('profile-upload-input')?.click();
});

document.getElementById('profile-upload-input')?.addEventListener('change', async (e) => {
  const file = e.target.files[0];
  if (!file || !token) return;
  const fd = new FormData();
  fd.append('avatar', file);
  fd.append('token', token);
  try {
    const res = await fetch(`${API_BASE}/profile.php`, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success && data.avatar_url) {
      document.getElementById('profile-avatar').src = data.avatar_url;
      document.getElementById('header-avatar').src = data.avatar_url;
    }
  } catch (e) {}
});

function gtnGuess(val) {
  const s = gtnState;
  if (s.phase !== 'guessing') return;
  const diff = Math.abs(val - s.number);
  let hint, hintClass;
  if (val === s.number) {
    hint = 'Correct!';
    hintClass = 'gtn-hint-correct';
    s.phase = 'won';
    s.attempts.push({ guess: val, hint, hintClass });
    gameSyncSave('guessthenumber', s);
    renderGTN();
    return;
  } else if (diff <= 5) {
    hint = '🔥 Hot!';
    hintClass = 'gtn-hint-hot';
  } else if (diff <= 15) {
    hint = '🔥 Warm';
    hintClass = 'gtn-hint-warm';
  } else if (diff <= 30) {
    hint = '❄️ Cool';
    hintClass = 'gtn-hint-warm';
  } else {
    hint = '🥶 Cold!';
    hintClass = 'gtn-hint-cold';
  }
  s.attempts.push({ guess: val, hint, hintClass });
  if (s.attempts.length >= s.maxAttempts) s.phase = 'lost';
  gameSyncSave('guessthenumber', s);
  renderGTN();
}

// ===== D&D ENDLESS DUNGEON =====
let dndState = null;
let dndLastSeq = 0;
let dndLog = [];
let dndTimer = null;
let dndActiveBoard = false;

function startDnD() {
  showGameBoard('D&D Endless Dungeon');
  dndActiveBoard = true;
  dndState = null;
  dndLastSeq = 0;
  dndLog = [];
  renderDnD();
  dndPoll();
  if (dndTimer) clearInterval(dndTimer);
  dndTimer = setInterval(dndPoll, 2000);
}

function stopDnD() {
  dndActiveBoard = false;
  if (dndTimer) { clearInterval(dndTimer); dndTimer = null; }
}

function ensureDnDActive() {
  if (gamesModal.classList.contains('hidden')) {
    privateGamesBtn.click();
  }
  if (!dndActiveBoard) {
    startDnD();
  } else {
    showGameBoard('D&D Endless Dungeon');
    renderDnD();
  }
}

async function dndPoll() {
  if (!currentPrivateRoom || !token) { renderDnD(); return; }
  try {
    const res = await fetch(`${API_BASE}/dnd.php?room_id=${encodeURIComponent(currentPrivateRoom)}&token=${encodeURIComponent(token)}&username=${encodeURIComponent(username)}&since=${dndLastSeq}`);
    const data = await res.json();
    if (data.ok) {
      dndState = data;
      if (Array.isArray(data.log)) {
        for (const e of data.log) {
          if ((e.seq || 0) > dndLastSeq) dndLog.push(e);
        }
      }
      dndLastSeq = data.seq;
      renderDnD();
    }
  } catch (e) {}
}

async function dndAction(action, arg) {
  if (!currentPrivateRoom || !token) return;
  try {
    const res = await fetch(`${API_BASE}/dnd.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, arg, room_id: currentPrivateRoom, token, username }),
    });
    const data = await res.json();
    if (data && data.seq !== undefined) {
      dndState = data;
      if (Array.isArray(data.log)) {
        dndLog = data.log.slice(-200);
      }
      dndLastSeq = data.seq;
      renderDnD();
    }
  } catch (e) {}
}

function dndHeroCard(u, h) {
  const div = document.createElement('div');
  div.className = 'dnd-hero' + (h.alive ? '' : ' dnd-hero-down');
  if (u.toLowerCase() === (username || '').toLowerCase()) div.classList.add('dnd-hero-me');
  const hpPct = Math.max(0, Math.min(100, Math.round(h.hp / Math.max(1, h.max_hp) * 100)));
  div.innerHTML =
    `<div class="dnd-hero-top"><span class="dnd-hero-name">${escHtml(u)}</span>` +
    `<span class="dnd-hero-class">${escHtml(h.class)} Lv${h.level}</span></div>` +
    `<div class="dnd-hp-bar"><div class="dnd-hp-fill" style="width:${hpPct}%"></div></div>` +
    `<div class="dnd-hero-stats">HP ${Math.max(0, h.hp)}/${h.max_hp} · Gold ${h.gold} · Potions ${h.potions}</div>` +
    (h.alive ? '' : '<div class="dnd-down-label">Fallen</div>');
  return div;
}

function dndMonsterPanel() {
  const div = document.createElement('div');
  if (!dndState || !dndState.monster) {
    div.className = 'dnd-monster dnd-empty';
    div.textContent = dndState ? 'No monster nearby. Camp is safe — explore to descend.' : 'Loading…';
    return div;
  }
  const m = dndState.monster;
  div.className = 'dnd-monster';
  const hpPct = Math.max(0, Math.min(100, Math.round(m.hp / Math.max(1, m.max_hp) * 100)));
  div.innerHTML =
    `<div class="dnd-hero-top"><span class="dnd-monster-name">${escHtml(m.name)}</span></div>` +
    `<div class="dnd-hp-bar dnd-hp-striped"><div class="dnd-hp-fill" style="width:${hpPct}%"></div></div>` +
    `<div class="dnd-hero-stats">HP ${Math.max(0, m.hp)}/${m.max_hp} · ATK ${m.atk}</div>`;
  return div;
}

function dndStatusText() {
  if (!dndState) return 'Loading...';
  const s = dndState;
  if (s.status === 'combat') {
    const your = s.turn && s.turn.toLowerCase() === (username || '').toLowerCase();
    return 'Combat — Depth ' + s.depth + ' — ' + (your ? '⭐ Your turn! (get moving)' : s.turn + "'s turn (auto-skips in 30s)");
  }
  return 'Camp — Depth ' + s.depth + ' — Explore to adventure';
}

function renderDnD() {
  const area = gameBoardArea;
  area.innerHTML = '';
  const wrap = document.createElement('div');
  wrap.className = 'dnd-wrap';

  if (dndState) {
    gameBoardStatus.textContent = dndStatusText();
  }

  // Party row
  const party = document.createElement('div');
  party.className = 'dnd-party';
  const heroes = (dndState && dndState.heroes) || {};
  const heroNames = Object.keys(heroes);
  if (heroNames.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'dnd-hero dnd-empty';
    empty.innerHTML = 'No heroes yet.<br>Type <b>!new fighter</b>, <b>!new mage</b>, <b>!new rogue</b>, or <b>!new cleric</b> in the chat.';
    party.appendChild(empty);
  } else {
    heroNames.forEach(u => party.appendChild(dndHeroCard(u, heroes[u])));
  }
  wrap.appendChild(party);

  // Monster / battlefield
  wrap.appendChild(dndMonsterPanel());

  // Action buttons
  const btns = document.createElement('div');
  btns.className = 'dnd-btns';
  const s = dndState;
  const isCombat = s && s.status === 'combat';
  const yourTurn = s && isCombat && s.turn && s.turn.toLowerCase() === (username || '').toLowerCase();
  const hasHero = s && s.heroes && s.heroes[username];

  if (!hasHero) {
    const cls = ['fighter', 'mage', 'rogue', 'cleric'];
    cls.forEach(c => {
      const b = document.createElement('button');
      b.className = 'dnd-btn';
      b.textContent = 'New ' + c;
      b.addEventListener('click', () => dndAction('create', c));
      btns.appendChild(b);
    });
  } else if (isCombat) {
    const enabled = (u => dndState && dndState.heroes && dndState.heroes[u] && dndState.heroes[u].alive)(username);
    const mk = (label, act, allow) => {
      const b = document.createElement('button');
      b.className = 'dnd-btn' + ((!allow || !enabled) ? ' dnd-btn-off' : '');
      b.textContent = label;
      b.addEventListener('click', () => dndAction(act));
      btns.appendChild(b);
    };
    mk('⚔️ Attack', 'attack', yourTurn);
    mk('✨ Spell', 'cast', yourTurn && ['Mage', 'Cleric'].includes(dndState.heroes[username].class));
    mk('🛡️ Defend', 'defend', yourTurn);
    mk('🧪 Potion', 'potion', yourTurn && dndState.heroes[username].potions > 0);
    mk('💨 Flee', 'flee', yourTurn);
  } else {
    const mk = (label, act) => {
      const b = document.createElement('button');
      b.className = 'dnd-btn';
      b.textContent = label;
      b.addEventListener('click', () => dndAction(act));
      btns.appendChild(b);
    };
    mk('⬇️ Explore', 'explore');
    mk('⛺ Rest', 'rest');
    mk('🧪 Buy Potion (15g)', 'buy');
    mk('📜 Status', 'status');
  }
  wrap.appendChild(btns);

  // Log
  const log = document.createElement('div');
  log.className = 'dnd-log';
  const lines = dndLog.slice(-80);
  lines.forEach(e => {
    const line = document.createElement('div');
    line.className = 'dnd-line';
    line.textContent = e.t;
    log.appendChild(line);
  });
  if (log.scrollHeight) log.scrollTop = log.scrollHeight;
  wrap.appendChild(log);

  area.appendChild(wrap);
}

function dndDispatchCommand(raw) {
  const txt = (raw || '').trim().replace(/^!/, '').trim();
  if (!txt || !currentPrivateRoom) return;
  const parts = txt.split(/\s+/);
  const cmd = (parts[0] || '').toLowerCase();
  const arg = (parts[1] || '').toLowerCase();
  const map = {
    new: ['create', arg],
    explore: ['explore'],
    attack: ['attack'],
    cast: ['cast'],
    defend: ['defend'],
    potion: ['potion'],
    flee: ['flee'],
    rest: ['rest'],
    buy: ['buy'],
    status: ['status'],
    help: ['help'],
    reset: ['reset'],
  };
  if (!map[cmd]) {
    // unrecognized command: send as a normal private message
    return false;
  }
  if (cmd === 'reset' && !confirm('Reset the entire dungeon campaign for this room?')) {
    return true;
  }
  dndAction(map[cmd][0], map[cmd][1] || '');
  // Make sure the board is visible to see results
  ensureDnDActive();
  return true;
}

// ===== READ STATE / UNREAD =====
function maybeMarkRoomRead(force) {
  if (!token || !currentRoom) return;
  if (document.hidden) return;
  const now = Date.now();
  if (!force && now - lastMarkRead < 4000) return;
  lastMarkRead = now;
  markRoomRead();
}

async function markRoomRead() {
  if (!token) return;
  try {
    await fetch(`${API_BASE}/read-state.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, scope: 'room', room_id: currentRoom }),
    });
    roomUnread[currentRoom] = 0;
    refreshRoomBadge();
  } catch (err) {}
}

function refreshRoomBadge() {
  const badge = document.getElementById('room-unread-badge');
  if (!badge) return;
  let total = 0;
  for (const roomId in roomUnread) {
    if (roomId !== currentRoom) total += roomUnread[roomId] || 0;
  }
  for (const name in friendUnread) total += friendUnread[name] || 0;
  if (total > 0) {
    badge.textContent = total > 99 ? '99+' : total;
    badge.classList.remove('hidden');
  } else {
    badge.classList.add('hidden');
  }
  updateDocumentTitle(total);
  if (document.activeElement === roomSelect) return;
  const sel = roomSelect.value;
  for (const opt of roomSelect.options) {
    const room = roomsData.find(r => r.id === opt.value);
    if (!room) continue;
    const un = roomUnread[opt.value] || 0;
    opt.textContent = room.name + (un ? ' (' + un + ')' : '');
  }
  roomSelect.value = sel;
}

function markPrivateRead(roomId) {
  if (!token || !roomId) return;
  fetch(`${API_BASE}/read-state.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, scope: 'private', room_id: roomId }),
  }).catch(() => {});
  privateUnread[roomId] = 0;
  for (const name in friendUnread) {
    if (privatePeer && name.toLowerCase() === privatePeer.toLowerCase()) friendUnread[name] = 0;
  }
  refreshRoomBadge();
}

async function fetchUnreadState() {
  if (!token) return;
  try {
    const res = await fetch(`${API_BASE}/read-state.php?token=${encodeURIComponent(token)}`);
    const data = await res.json();
    const list = Array.isArray(data) ? data : (Array.isArray(data.rooms) ? data.rooms : null);
    if (list) {
      const map = {};
      list.forEach(r => { map[r.room_id] = r.unread || 0; });
      roomUnread = map;
    }
    refreshRoomBadge();
  } catch (err) {}
}

// ===== BLOCKING =====
async function fetchBlockedList() {
  if (!token) return;
  try {
    const res = await fetch(`${API_BASE}/blocks.php?token=${encodeURIComponent(token)}`);
    const data = await res.json();
    if (data.blocked && Array.isArray(data.blocked)) {
      myBlocked = new Set(data.blocked);
    }
    if (privatePeer && currentPrivateRoom) {
      privatePeerBlocked = myBlocked.has(privatePeer);
      refreshBlockedBanner();
    }
  } catch (err) {}
}

async function toggleBlockUser(target, blocking) {
  if (!token) return;
  try {
    const res = await fetch(`${API_BASE}/blocks.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, target, action: blocking ? 'block' : 'unblock' }),
    });
    const data = await res.json();
    if (data.success) {
      addSystemMessage(blocking
        ? `You blocked ${target}. Their messages are hidden.`
        : `You unblocked ${target}.`);
      fetchBlockedList();
    } else {
      addSystemMessage('Error: ' + (data.error || 'Failed to update block list'));
    }
  } catch (err) {
    addSystemMessage('Failed to update block list');
  }
}

// ===== THEME / ACCENT =====
const THEME_KEY = 'chat_theme';
const ACCENT_KEY = 'chat_accent';
const ACCENTS = {
  rose: '#e94560',
  blue: '#4fc3f7',
  green: '#4ade80',
  orange: '#fb923c',
  violet: '#a78bfa'
};

function applySavedTheme() {
  if (localStorage.getItem(THEME_KEY) === 'light') document.body.classList.add('light');
  const accent = localStorage.getItem(ACCENT_KEY);
  if (accent && ACCENTS[accent]) document.body.dataset.accent = accent;
  syncThemeIcon();
  syncAccentBtn();
}

function syncThemeIcon() {
  const btn = document.getElementById('theme-btn');
  if (btn) btn.textContent = document.body.classList.contains('light') ? '☀️' : '🌙';
}

function syncAccentBtn() {
  const btn = document.getElementById('accent-btn');
  if (!btn) return;
  const name = document.body.dataset.accent || 'rose';
  btn.style.background = ACCENTS[name] || ACCENTS.rose;
  btn.style.boxShadow = '0 0 0 2px ' + (ACCENTS[name] || ACCENTS.rose) + '66';
}

document.addEventListener('DOMContentLoaded', applySavedTheme);
applySavedTheme();

document.getElementById('theme-btn')?.addEventListener('click', () => {
  document.body.classList.toggle('light');
  localStorage.setItem(THEME_KEY, document.body.classList.contains('light') ? 'light' : 'dark');
  syncThemeIcon();
});

let accentPaletteEl = null;
document.getElementById('accent-btn')?.addEventListener('click', (e) => {
  e.stopPropagation();
  if (accentPaletteEl) { accentPaletteEl.remove(); accentPaletteEl = null; return; }
  const pal = document.createElement('div');
  pal.className = 'accent-palette';
  for (const name in ACCENTS) {
    const sw = document.createElement('span');
    sw.className = 'accent-swatch' + (document.body.dataset.accent === name ? ' active' : '');
    sw.style.background = ACCENTS[name];
    sw.title = name;
    sw.addEventListener('click', () => {
      document.body.dataset.accent = name;
      localStorage.setItem(ACCENT_KEY, name);
      syncAccentBtn();
      pal.remove();
      accentPaletteEl = null;
    });
    pal.appendChild(sw);
  }
  document.body.appendChild(pal);
  accentPaletteEl = pal;
  const r = document.getElementById('accent-btn').getBoundingClientRect();
  pal.style.top = (r.bottom + 6) + 'px';
  pal.style.left = r.left + 'px';
});
document.addEventListener('click', () => {
  if (accentPaletteEl) { accentPaletteEl.remove(); accentPaletteEl = null; }
});

// ===== SEARCH =====
let searchDebounce = null;
document.getElementById('msg-search-btn')?.addEventListener('click', () => {
  document.getElementById('search-input').value = '';
  document.getElementById('search-results').innerHTML = '';
  document.getElementById('search-modal').classList.remove('hidden');
  setTimeout(() => document.getElementById('search-input').focus(), 50);
});
document.getElementById('search-close')?.addEventListener('click', () => {
  document.getElementById('search-modal').classList.add('hidden');
});
document.getElementById('search-input')?.addEventListener('input', () => {
  clearTimeout(searchDebounce);
  const q = document.getElementById('search-input').value.trim();
  const box = document.getElementById('search-results');
  if (q.length < 2) { box.innerHTML = ''; return; }
  searchDebounce = setTimeout(() => runSearch(q), 300);
});
document.getElementById('search-input')?.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') document.getElementById('search-modal').classList.add('hidden');
});

async function runSearch(q) {
  const box = document.getElementById('search-results');
  try {
    const res = await fetch(`${API_BASE}/search.php?q=${encodeURIComponent(q)}&token=${encodeURIComponent(token || '')}`);
    const data = await res.json();
    const publicItems = Array.isArray(data.public) ? data.public : [];
    const privateItems = Array.isArray(data.private) ? data.private : [];
    const combined = [
      ...publicItems.map(m => ({ m, type: 'public', roomId: m.room_id })),
      ...privateItems.map(m => ({ m, type: 'private', roomId: m.room_id })),
    ];
    if (combined.length === 0) {
      box.innerHTML = '<div class="search-empty">No results found</div>';
      return;
    }
    box.innerHTML = '';
    combined.forEach(({ m, type, roomId }) => {
      const el = document.createElement('div');
      el.className = 'search-item';
      const roomName = type === 'public'
        ? (roomsData.find(r => r.id === roomId)?.name || roomId)
        : (function () {
            const parts = (roomId || '').split('_');
            const un = username || '';
            const peer = parts.find(p => p && p.toLowerCase() !== un.toLowerCase()) || roomId;
            return peer;
          })();
      const snippet = String(m.message || '').replace(/\s+/g, ' ');
      const shown = snippet.length > 100 ? snippet.slice(0, 100) + '…' : snippet;
      el.innerHTML = `<span class="search-scope">${type === 'private' ? '💬' : '🗨'} ${escHtml(roomName)}</span>
        <div class="search-snippet">${linkify(shown)}</div>
        <div class="search-time">${escHtml(m.time || '')}</div>`;
      el.addEventListener('click', () => {
        document.getElementById('search-modal').classList.add('hidden');
        if (type === 'private') {
          const parts = (roomId || '').split('_');
          const un = username || '';
          const peer = parts.find(p => p && p.toLowerCase() !== un.toLowerCase()) || roomId;
          openPrivateChat(roomId, peer);
        } else {
          switchRoom(roomId);
        }
      });
      box.appendChild(el);
    });
  } catch (err) {
    box.innerHTML = '<div class="search-empty">Search failed</div>';
  }
}

// ===== LIGHTBOX =====
const lightboxEl = document.getElementById('lightbox');
const lightboxImgEl = document.getElementById('lightbox-img');
function closeLightbox() {
  lightboxEl.classList.add('hidden');
  lightboxImgEl.src = '';
  document.body.style.overflow = '';
}
document.getElementById('lightbox-close')?.addEventListener('click', closeLightbox);
lightboxEl?.addEventListener('click', (e) => { if (e.target === lightboxEl) closeLightbox(); });

document.addEventListener('click', (e) => {
  const img = e.target.closest('.file-preview[data-lightbox]');
  if (img) {
    e.preventDefault();
    lightboxImgEl.src = img.dataset.lightbox;
    lightboxEl.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  }
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && lightboxEl && !lightboxEl.classList.contains('hidden')) closeLightbox();
});

// ===== PASTE TO UPLOAD =====
document.addEventListener('paste', (e) => {
  if (!username) return;
  const items = e.clipboardData && e.clipboardData.items;
  if (!items) return;
  let blob = null, fname = '';
  for (const it of items) {
    if (it.kind === 'file' && it.type.startsWith('image/')) {
      const f = it.getAsFile();
      if (f) { blob = f; fname = f.name || 'pasted-image.png'; break; }
    }
  }
  if (!blob) return;
  e.preventDefault();
  if (currentPrivateRoom && !privateModal.classList.contains('hidden')) {
    uploadBlobPrivate(blob, fname);
  } else {
    uploadBlobPublic(blob, fname);
  }
});

async function uploadBlobPublic(blob, fname) {
  if (!username || !token) return;
  const fd = new FormData();
  fd.append('file', blob, fname);
  fd.append('username', username);
  fd.append('room_id', currentRoom);
  fd.append('token', token);
  try {
    const res = await fetch(`${API_BASE}/upload.php`, { method: 'POST', body: fd });
    if (!res.ok) {
      const d = await res.json().catch(() => ({}));
      addSystemMessage(`Upload failed: ${d.error || 'Unknown error'}`);
    }
  } catch (err) {
    addSystemMessage('Upload failed: Connection error');
  }
}

async function uploadBlobPrivate(blob, fname) {
  if (!username || !token || !currentPrivateRoom) return;
  const fd = new FormData();
  fd.append('file', blob, fname);
  fd.append('username', username);
  fd.append('room_id', currentPrivateRoom);
  fd.append('token', token);
  try {
    const res = await fetch(`${API_BASE}/private-upload.php`, { method: 'POST', body: fd });
    if (!res.ok) {
      const d = await res.json().catch(() => ({}));
      addSystemMessage(`Upload failed: ${d.error || 'Unknown error'}`);
    }
  } catch (err) {}
}

// ===== TYPING INDICATORS =====
let typingSentAt = 0;
messageInput.addEventListener('input', () => {
  const now = Date.now();
  if (now - typingSentAt > 2500) {
    typingSentAt = now;
    sendTypingSignal();
  }
});
privateInput.addEventListener('input', () => {
  const now = Date.now();
  if (now - typingSentAt > 2400) {
    typingSentAt = now;
    sendTypingSignal(true);
  }
});

function sendTypingSignal(isPrivate) {
  if (!token || !username) return;
  const roomId = isPrivate ? currentPrivateRoom : currentRoom;
  if (!roomId) return;
  fetch(`${API_BASE}/typing.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, kind: isPrivate ? 'private' : 'public', room_id: roomId }),
  }).catch(() => {});
}

function renderTypingIndicators(list, el) {
  if (!el) return;
  if (!list || list.length === 0) {
    el.classList.add('hidden');
    el.textContent = '';
    return;
  }
  const shown = list.slice(0, 2);
  el.textContent = shown.join(', ') + (list.length > 2 ? ' +' + (list.length - 2) : '') + (list.length > 1 ? ' are typing…' : ' is typing…');
  el.classList.remove('hidden');
}

// ===== FORGOT PASSWORD =====
let resetFlowToken = '';
let resetFlowID = '';

document.getElementById('forgot-btn')?.addEventListener('click', () => {
  loginForm.classList.add('hidden');
  document.getElementById('forgot-panel').classList.remove('hidden');
  document.getElementById('forgot-step1').classList.remove('hidden');
  document.getElementById('forgot-step2').classList.add('hidden');
  document.getElementById('forgot-step3').classList.add('hidden');
  document.getElementById('forgot-error').classList.add('hidden');
  document.getElementById('forgot-id').value = '';
});

document.getElementById('forgot-back')?.addEventListener('click', () => {
  document.getElementById('forgot-panel').classList.add('hidden');
  loginForm.classList.remove('hidden');
});

document.getElementById('forgot-send-btn')?.addEventListener('click', async () => {
  const err = document.getElementById('forgot-error');
  err.classList.add('hidden');
  resetFlowID = document.getElementById('forgot-id').value.trim();
  if (!resetFlowID) { err.textContent = 'Enter your username or email'; err.classList.remove('hidden'); return; }
  const btn = document.getElementById('forgot-send-btn');
  btn.disabled = true;
  try {
    const res = await fetch(`${API_BASE}/password.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'request', id: resetFlowID }),
    });
    const data = await res.json();
    if (data.success && data.reset_link) {
      resetFlowToken = decodeURIComponent((data.reset_link.match(/[?&]token=([^&]+)/) || ['', ''])[1]);
      document.getElementById('forgot-link-box').classList.remove('hidden');
      document.getElementById('forgot-link').textContent = data.reset_link;
      document.getElementById('forgot-step2').classList.remove('hidden');
      document.getElementById('forgot-step1').classList.add('hidden');
    } else if (data.message) {
      err.textContent = data.message;
      err.classList.remove('hidden');
    } else {
      err.textContent = data.error || 'Reset request failed';
      err.classList.remove('hidden');
    }
  } catch (e) {
    err.textContent = 'Connection error';
    err.classList.remove('hidden');
  } finally {
    btn.disabled = false;
  }
});

document.getElementById('forgot-use-link')?.addEventListener('click', () => {
  document.getElementById('forgot-step2').classList.add('hidden');
  document.getElementById('forgot-step3').classList.remove('hidden');
});

document.getElementById('forgot-reset-btn')?.addEventListener('click', async () => {
  const err = document.getElementById('forgot-reset-error');
  err.classList.add('hidden');
  const pw = document.getElementById('forgot-password').value.trim();
  const confirm = document.getElementById('forgot-confirm').value.trim();
  if (!pw || pw.length < 6) { err.textContent = 'Password must be at least 6 characters'; err.classList.remove('hidden'); return; }
  if (pw !== confirm) { err.textContent = 'Passwords do not match'; err.classList.remove('hidden'); return; }
  const btn = document.getElementById('forgot-reset-btn');
  btn.disabled = true;
  try {
    const res = await fetch(`${API_BASE}/password.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'reset', token: resetFlowToken, password: pw }),
    });
    const data = await res.json();
    if (data.success) {
      document.getElementById('forgot-panel').classList.add('hidden');
      loginForm.classList.remove('hidden');
      document.getElementById('login-error').textContent = 'Password updated. Log in with your new password.';
      document.getElementById('login-error').classList.remove('hidden');
      loginPassword.value = '';
      loginUsername.focus();
    } else {
      err.textContent = data.error || 'Reset failed. Request a new link.';
      err.classList.remove('hidden');
    }
  } catch (e) {
    err.textContent = 'Connection error';
    err.classList.remove('hidden');
  } finally {
    btn.disabled = false;
  }
});

// ===== RESEND ACTIVATION =====
document.getElementById('resend-activation-btn')?.addEventListener('click', async () => {
  const err = document.getElementById('resend-error');
  err.classList.add('hidden');
  const id = document.getElementById('resend-id').value.trim();
  if (!id) { err.textContent = 'Enter your username or email'; err.classList.remove('hidden'); return; }
  try {
    const res = await fetch(`${API_BASE}/password.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'resend_activation', id }),
    });
    const data = await res.json();
    if (data.success) {
      document.getElementById('activation-link-box').classList.remove('hidden');
      document.getElementById('activation-link').textContent = data.activation_link || 'Activation link generated.';
      document.getElementById('resend-id').value = '';
    } else {
      err.textContent = data.message || data.error || 'Failed to resend activation';
      err.classList.remove('hidden');
    }
  } catch (e) {
    err.textContent = 'Connection error';
    err.classList.remove('hidden');
  }
});

// ===== DELETE ACCOUNT =====
document.getElementById('profile-delete-btn')?.addEventListener('click', () => {
  if (!token) return;
  if (!confirm('This permanently deletes your account, all your messages, and uploads. Continue?')) return;
  const pw = prompt('Enter your password to confirm account deletion:');
  if (!pw) return;
  fetch(`${API_BASE}/delete-account.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, password: pw }),
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        logout();
      } else {
        alert(data.error || 'Failed to delete account');
      }
    })
    .catch(() => alert('Connection error'));
});

// ===== MESSAGE HISTORY PAGINATION =====
function updateHistoryButton() {
  if (!loadOlderWrap) return;
  loadOlderWrap.classList.toggle('hidden', !(historyHasMore && historyOldestTs > 0));
}

async function loadOlderMessages() {
  if (!token || !currentRoom || historyLoading || !historyOldestTs) return;
  historyLoading = true;
  if (loadOlderBtn) {
    loadOlderBtn.disabled = true;
    loadOlderBtn.textContent = 'Loading…';
  }
  const prevHeight = messagesContainer.scrollHeight;
  const prevTop = messagesContainer.scrollTop;
  try {
    const res = await fetch(`${API_BASE}/poll.php?before=${encodeURIComponent(historyOldestTs)}&room_id=${encodeURIComponent(currentRoom)}&token=${encodeURIComponent(token || '')}`);
    const data = await res.json();
    const msgs = Array.isArray(data.messages) ? data.messages : [];
    // Prepending in reverse keeps the batch in chronological order.
    for (let i = msgs.length - 1; i >= 0; i--) addMessage(msgs[i], true);
    if (msgs.length) historyOldestTs = Number(msgs[0].timestamp) || historyOldestTs;
    historyHasMore = !!data.has_more && msgs.length > 0;
    messagesContainer.scrollTop = messagesContainer.scrollHeight - prevHeight + prevTop;
  } catch (err) {
    addSystemMessage('Could not load older messages.');
  } finally {
    historyLoading = false;
    if (loadOlderBtn) {
      loadOlderBtn.disabled = false;
      loadOlderBtn.textContent = '↑ Load older messages';
    }
    updateHistoryButton();
  }
}

// ===== DESKTOP NOTIFICATIONS =====
function syncNotifyBtn() {
  if (!notifyBtn) return;
  const supported = typeof Notification !== 'undefined';
  notifyBtn.classList.toggle('hidden', !supported);
  const on = supported && notifyEnabled && Notification.permission === 'granted';
  notifyBtn.classList.toggle('notify-on', on);
  notifyBtn.title = on ? 'Desktop notifications on' : 'Enable desktop notifications';
  notifyBtn.textContent = on ? '🔔' : '🔕';
}

async function toggleNotifications() {
  if (typeof Notification === 'undefined') {
    addSystemMessage('This browser does not support desktop notifications.');
    return;
  }
  if (notifyEnabled && Notification.permission === 'granted') {
    notifyEnabled = false;
    localStorage.setItem('chat_notify', '0');
    await disablePushNotifications();
    syncNotifyBtn();
    addSystemMessage('Desktop and push notifications turned off.');
    return;
  }
  const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
  notifyEnabled = permission === 'granted';
  localStorage.setItem('chat_notify', notifyEnabled ? '1' : '0');
  const pushReady = notifyEnabled ? await enablePushNotifications() : false;
  syncNotifyBtn();
  addSystemMessage(notifyEnabled
    ? (pushReady
      ? 'Notifications on — mentions and private messages will also arrive when the tab is closed.'
      : 'Desktop notifications on — mentions and private messages will pop up while the tab is open.')
    : 'Notification permission was not granted.');
}

function maybeNotify(msg, kind) {
  if (!notifyEnabled || typeof Notification === 'undefined' || Notification.permission !== 'granted') return;
  if (!document.hidden) return;
  if (!msg || !msg.username || msg.username === username || msg.deleted) return;
  const text = String(msg.message || '').replace(/\s+/g, ' ').trim();
  let body = text.length > 120 ? text.slice(0, 120) + '…' : text;
  if (kind === 'public') {
    const safeName = String(username || '').replace(/[^a-zA-Z0-9_]/g, '');
    if (!safeName) return;
    const mention = new RegExp('@' + safeName + '\\b', 'i');
    if (!mention.test(text)) return;
    body = `${msg.username} mentioned you: ${body}`;
  } else {
    body = `${msg.username}: ${body}`;
  }
  try {
    const n = new Notification('JohnsonChat', { body, tag: msg.id || undefined, icon: 'default-avatar.svg' });
    n.onclick = () => { window.focus(); n.close(); };
  } catch (e) {}
}

function updateDocumentTitle(total) {
  let count = total;
  if (typeof count !== 'number') {
    count = 0;
    for (const roomId in roomUnread) {
      if (roomId !== currentRoom) count += roomUnread[roomId] || 0;
    }
    for (const name in friendUnread) count += friendUnread[name] || 0;
  }
  document.title = count > 0
    ? `(${count > 99 ? '99+' : count}) JohnsonChat`
    : 'JohnsonChat — Real-time Messaging';
}

loadOlderBtn?.addEventListener('click', loadOlderMessages);
notifyBtn?.addEventListener('click', toggleNotifications);
syncNotifyBtn();
updateHistoryButton();

// ===== REPLIES, PINS, DEEP LINKS =====
let publicReply = null;
let privateReply = null;

function canPinCurrentRoom() {
  if (userRole === 'admin' || userRole === 'moderator') return true;
  const room = roomsData.find(r => r.id === currentRoom);
  return !!(room && room.created_by === username);
}

function replyPreview(text) {
  const clean = String(text || '').replace(/\s+/g, ' ').trim();
  return clean.length > 90 ? clean.slice(0, 90) + '…' : clean;
}

function startReply(kind, msgId) {
  const map = kind === 'public' ? renderedMsgs : renderedPrivateMsgs;
  let rec = map.get(msgId);
  if (!rec) {
    for (const item of map.values()) {
      if (String(item.lastRaw?.id) === String(msgId)) { rec = item; break; }
    }
  }
  if (!rec || !rec.lastRaw || rec.lastRaw.deleted) return;
  const msg = rec.lastRaw;
  const bar = document.getElementById(kind === 'public' ? 'reply-bar' : 'private-reply-bar');
  const name = document.getElementById(kind === 'public' ? 'reply-bar-name' : 'private-reply-name');
  const preview = document.getElementById(kind === 'public' ? 'reply-bar-preview' : 'private-reply-preview');
  if (!bar || !name || !preview) return;
  name.textContent = msg.username;
  preview.textContent = replyPreview(msg.message);
  bar.classList.remove('hidden');
  if (kind === 'public') publicReply = { id: msg.id, username: msg.username, message: msg.message };
  else privateReply = { id: msg.id, username: msg.username, message: msg.message };
  (kind === 'public' ? messageInput : privateInput).focus();
}

function cancelReply(kind) {
  if (kind === 'public') {
    publicReply = null;
    document.getElementById('reply-bar')?.classList.add('hidden');
  } else {
    privateReply = null;
    document.getElementById('private-reply-bar')?.classList.add('hidden');
  }
}

function renderPinnedBar(pins) {
  const bar = document.getElementById('pinned-bar');
  if (!bar) return;
  if (!Array.isArray(pins) || pins.length === 0) {
    bar.classList.add('hidden');
    bar.innerHTML = '';
    return;
  }
  const pin = pins[0];
  bar.innerHTML = `<span class="pinned-icon" title="Pinned message">📌</span>
    <button class="pinned-jump" data-msg="${escAttr(pin.message_id)}" title="Jump to pinned message">${escHtml(pin.username)}: ${escHtml(replyPreview(pin.message))}</button>
    ${pins.length > 1 ? `<span class="pinned-count">+${pins.length - 1}</span>` : ''}
    ${canPinCurrentRoom() ? `<button class="pinned-unpin" data-msg="${escAttr(pin.message_id)}" title="Unpin">×</button>` : ''}`;
  bar.classList.remove('hidden');
}

async function pinPublicMessage(msgId) {
  if (!token) return;
  try {
    const res = await fetch(`${API_BASE}/pins.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, action: 'pin', room_id: currentRoom, message_id: msgId }),
    });
    const data = await res.json();
    if (data.success) {
      renderPinnedBar(data.pins || []);
      addSystemMessage('Message pinned.');
    } else {
      addSystemMessage(`Pin failed: ${data.error || 'Unknown error'}`);
    }
  } catch (err) {
    addSystemMessage('Pin failed: connection error.');
  }
}

async function unpinPublicMessage(msgId) {
  if (!token) return;
  try {
    const res = await fetch(`${API_BASE}/pins.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, action: 'unpin', room_id: currentRoom, message_id: msgId }),
    });
    const data = await res.json();
    if (data.success) renderPinnedBar(data.pins || []);
  } catch (err) {}
}

function copyMessageLink(msgId) {
  const url = new URL(window.location.href);
  url.search = '';
  url.searchParams.set('room', currentRoom);
  url.hash = 'msg=' + encodeURIComponent(msgId);
  const link = url.toString();
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(link).then(() => addSystemMessage('Message link copied.')).catch(() => window.prompt('Copy this message link:', link));
  } else {
    window.prompt('Copy this message link:', link);
  }
}

async function jumpToMessage(msgId, attempt = 0) {
  const target = [...messagesContainer.querySelectorAll('.message')].find(el => el.dataset.msgId === msgId);
  if (!target) {
    if (attempt < 8 && historyHasMore) {
      await loadOlderMessages();
      return jumpToMessage(msgId, attempt + 1);
    }
    if (attempt < 14) {
      await new Promise(resolve => setTimeout(resolve, 250));
      return jumpToMessage(msgId, attempt + 1);
    }
    addSystemMessage('That message is not in the loaded history.');
    return;
  }
  target.scrollIntoView({ block: 'center', behavior: 'smooth' });
  target.classList.add('msg-highlight');
  setTimeout(() => target.classList.remove('msg-highlight'), 2200);
}

function jumpToPrivateMessage(msgId) {
  const target = [...privateMessages.querySelectorAll('.private-msg')].find(el => el.dataset.msgId === msgId);
  if (!target) return;
  target.scrollIntoView({ block: 'center', behavior: 'smooth' });
  target.classList.add('msg-highlight');
  setTimeout(() => target.classList.remove('msg-highlight'), 2200);
}

async function applyDeepLink() {
  const params = new URLSearchParams(window.location.search);
  const hash = new URLSearchParams(window.location.hash.replace(/^#/, ''));
  const room = params.get('room');
  const msg = params.get('msg') || hash.get('msg');
  if (!room && !msg) return;
  if (room && room !== currentRoom) {
    if (!roomsData.some(r => r.id === room)) {
      addSystemMessage('That room is not available.');
      return;
    }
    roomSelect.value = room;
    await switchRoom(room);
  }
  if (msg) setTimeout(() => jumpToMessage(msg), 1200);
}

document.getElementById('reply-cancel')?.addEventListener('click', () => cancelReply('public'));
document.getElementById('private-reply-cancel')?.addEventListener('click', () => cancelReply('private'));
document.getElementById('pinned-bar')?.addEventListener('click', (e) => {
  const jump = e.target.closest('.pinned-jump');
  if (jump) { jumpToMessage(jump.dataset.msg); return; }
  const unpin = e.target.closest('.pinned-unpin');
  if (unpin) unpinPublicMessage(unpin.dataset.msg);
});

// ===== PWA / WEB PUSH =====
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = window.atob(base64);
  const output = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);
  return output;
}

let swRegistrationPromise = null;
function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return Promise.resolve(null);
  if (!swRegistrationPromise) {
    swRegistrationPromise = navigator.serviceWorker.register('service-worker.js', { scope: './' }).catch(() => null);
  }
  return swRegistrationPromise;
}

function serviceWorkerReady(timeoutMs = 5000) {
  return Promise.race([
    navigator.serviceWorker.ready,
    new Promise((resolve, reject) => setTimeout(() => reject(new Error('Service worker not ready')), timeoutMs)),
  ]);
}

async function enablePushNotifications() {
  if (!token || !('serviceWorker' in navigator) || !('PushManager' in window)) return false;
  try {
    await registerServiceWorker();
    const registration = await serviceWorkerReady();
    const response = await fetch(`${API_BASE}/push.php?action=key`);
    const data = await response.json();
    if (!data.publicKey) return false;
    let subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(data.publicKey),
      });
    }
    const saved = await fetch(`${API_BASE}/push.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'subscribe', token, subscription: subscription.toJSON() }),
    });
    return saved.ok;
  } catch (err) {
    return false;
  }
}

async function disablePushNotifications() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
  try {
    const registration = await serviceWorkerReady();
    const subscription = await registration.pushManager.getSubscription();
    if (!subscription) return;
    await fetch(`${API_BASE}/push.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'unsubscribe', token, endpoint: subscription.endpoint }),
    });
    await subscription.unsubscribe();
  } catch (err) {}
}

registerServiceWorker();
