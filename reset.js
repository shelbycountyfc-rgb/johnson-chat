const form = document.getElementById('reset-form');
const pw = document.getElementById('pw');
const pw2 = document.getElementById('pw2');
const err = document.getElementById('err');
const btn = document.getElementById('submit');
const TOKEN = form.dataset.token || '';

function fail(msg) {
  err.textContent = msg;
  err.style.display = 'block';
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  err.style.display = 'none';
  if (pw.value.length < 6) { fail('Password must be at least 6 characters.'); return; }
  if (pw.value !== pw2.value) { fail('Passwords do not match.'); return; }
  btn.disabled = true;
  btn.textContent = 'Updating…';
  try {
    const res = await fetch('api/password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'reset', token: TOKEN, password: pw.value }),
    });
    const data = await res.json();
    if (data.success) {
      form.innerHTML = '<p class="ok">Your password has been updated.</p><a href="messnger.html"><button type="button">Log in</button></a>';
    } else {
      fail(data.error || 'This reset link is invalid or has expired.');
      btn.disabled = false;
      btn.textContent = 'Update password';
    }
  } catch (e) {
    fail('Connection error. Try again.');
    btn.disabled = false;
    btn.textContent = 'Update password';
  }
});
