{* MagicLinkLogin — Email form block injected into the identification page *}

<div id="mll-block" style="margin-top: 2em; padding-top: 1.5em; border-top: 1px solid #ddd;">
  <h3 style="margin-bottom: 0.5em;">{'Passwordless login'|translate}</h3>
  <p style="margin-bottom: 1em; color: #555; font-size: 0.9em;">
    {'Enter your email and we\'ll send you a one-time login link — no password needed.'|translate}
  </p>

  {* Step 1: email input form (shown by default) *}
  <form id="mll-form" action="{$MLL_HANDLER_URL}" method="post">
    <input type="hidden" name="csrf_token" value="{$MLL_CSRF_TOKEN}">

    <div style="display: flex; gap: 0.5em; flex-wrap: wrap;">
      <input
        type="email"
        id="mll-email"
        name="email"
        placeholder="{'your@email.com'|translate}"
        required
        autocomplete="email"
        style="flex: 1; min-width: 200px; padding: 0.5em 0.75em; border: 1px solid #ccc; border-radius: 4px;"
      >
      <button
        type="submit"
        id="mll-submit"
        style="padding: 0.5em 1.2em; background: #0070f3; color: #fff; border: none; border-radius: 4px; cursor: pointer; white-space: nowrap;"
      >
        {'Send login link'|translate}
      </button>
    </div>

    <p id="mll-error" role="alert" style="display: none; color: #c00; margin-top: 0.5em; font-size: 0.85em;"></p>
  </form>

  {* Step 2: confirmation message (shown after successful submission) *}
  <div id="mll-sent" role="status" style="display: none; padding: 1em; background: #f0fff0; border: 1px solid #6c6; border-radius: 4px; color: #363;">
    ✉️ {'Check your email — a login link is on its way. It expires in 15 minutes.'|translate}
  </div>
</div>

<script>
(function () {
  'use strict';

  var form    = document.getElementById('mll-form');
  var sent    = document.getElementById('mll-sent');
  var errBox  = document.getElementById('mll-error');
  var btn     = document.getElementById('mll-submit');

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    btn.disabled = true;
    btn.textContent = '...';
    errBox.style.display = 'none';

    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (data.status === 'ok') {
        form.style.display = 'none';
        sent.style.display = 'block';
      } else {
        showError('Something went wrong. Please try again.');
      }
    })
    .catch(function () {
      showError('Could not reach the server. Please check your connection and try again.');
    });
  });

  function showError(msg) {
    btn.disabled = false;
    btn.textContent = '{$smarty.const.l10n["Send login link"]|default:"Send login link"}';
    errBox.textContent = msg;
    errBox.style.display = 'block';
  }
}());
</script>
