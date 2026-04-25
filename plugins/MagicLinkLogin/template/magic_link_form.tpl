{* MagicLinkLogin — Email form block.
 *
 * JavaScript moves this block to the top of the login form (above the
 * password form) and hides the password form, making magic link the primary
 * login method. A "Sign in with password" toggle reveals the password form.
 *
 * Supports both Piwigo themes:
 *   • standard_pages (Piwigo 16+): reuses column-flex / input-container /
 *     btn btn-main / gallery-icon-at classes so it looks completely native.
 *   • default theme (classic): same classes are given fallback CSS rules
 *     scoped to #mll-block:not(.mll-sp) so they don't interfere when
 *     standard_pages classes do exist.
 *}

<style id="mll-styles">

/* ── Fallback rules for the default Piwigo theme ───────────────────────────
   Only applied when standard_pages is NOT active (JS adds .mll-sp to
   #mll-block when it detects the standard_pages theme). */

#mll-block:not(.mll-sp) .column-flex {
    display: flex;
    flex-direction: column;
    position: relative;
    margin-bottom: .75em;
}
#mll-block:not(.mll-sp) label {
    margin-bottom: .3em;
    font-weight: bold;
    font-size: .9em;
}
#mll-block:not(.mll-sp) .row-flex {
    display: flex;
    flex-direction: row;
    align-items: center;
}
#mll-block:not(.mll-sp) .input-container {
    border: 1px solid #ccc;
    border-radius: 3px;
    padding: 4px 8px;
}
#mll-block:not(.mll-sp) .input-container input {
    flex: 1;
    border: none;
    background: transparent;
    padding: .35em .25em;
    font-size: 1em;
    outline: none;
    width: 100%;
}
#mll-block:not(.mll-sp) .input-container i {
    margin-right: 5px;
    color: #888;
    font-size: 14px;
}
#mll-block:not(.mll-sp) .btn.btn-main {
    display: block;
    width: 100%;
    padding: .6em 1em;
    margin-top: .5em;
    background: #ff7700;
    color: #fff;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-size: 1em;
    text-align: center;
}
#mll-block:not(.mll-sp) .btn.btn-main:disabled {
    background: #aaa;
    cursor: not-allowed;
}
#mll-block:not(.mll-sp) #mll-sent {
    padding: .75em 1em;
    border: 1px solid #6c6;
    border-radius: 3px;
    background: #f0fff0;
}
#mll-block:not(.mll-sp) .error-message {
    color: #c00;
    font-size: .85em;
    margin-top: .25em;
}
/* ── Shared rules (both themes) ────────────────────────────────────────── */

#mll-sent {
    display: none;
    text-align: center;
    line-height: 1.6;
}
.error-message#mll-error {
    display: none;
}
.mll-divider {
    display: flex;
    align-items: center;
    gap: .6em;
    margin: 1.2em 0 .8em;
    font-size: .82em;
    color: #aaa;
}
.mll-divider::before,
.mll-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: currentColor;
}
#mll-password-link {
    text-align: center;
    margin: 0;
    font-size: .88em;
}
/* Hide the password form without a flash — we set display:none via JS after
   moving our block into place, but this transition avoids a jump if JS is
   slow. We use opacity so the layout doesn't shift before JS runs. */
.mll-hiding {
    visibility: hidden;
    position: absolute;
}

</style>

{* ── Block injected at the bottom of the page, moved to position by JS ── *}
<div id="mll-block" style="display:none" aria-hidden="true">

    {* Success state — replaces the form after the email is sent *}
    <div id="mll-sent" role="status">
        ✉️ <strong>{'Check your email!'|translate}</strong><br>
        {'A login link is on its way. It expires in 15 minutes.'|translate}
    </div>

    {* Form state *}
    <div id="mll-form-wrap">
        <form id="mll-form" action="{$MLL_HANDLER_URL}" method="post" novalidate>
            <input type="hidden" name="csrf_token" value="{$MLL_CSRF_TOKEN}">

            <div class="column-flex">
                <label for="mll-email">{'Email address'|translate}</label>
                <div class="row-flex input-container">
                    <i class="gallery-icon-at" aria-hidden="true"></i>
                    <input
                        type="email"
                        id="mll-email"
                        name="email"
                        placeholder="{'your@email.com'|translate}"
                        autocomplete="email"
                        data-required="true"
                    >
                </div>
                <p class="error-message" id="mll-error" role="alert">
                    <i class="gallery-icon-attention-circled" aria-hidden="true"></i>
                    {'Something went wrong. Please try again.'|translate}
                </p>
            </div>

            <div class="column-flex">
                <button
                    type="submit"
                    id="mll-submit"
                    class="btn btn-main"
                    data-label="{'Send magic link'|translate}"
                >
                    {'Send magic link'|translate}
                </button>
            </div>
        </form>

        <div class="mll-divider" aria-hidden="true">
            <span>{'or'|translate}</span>
        </div>

        <p id="mll-password-link">
            <a href="#" id="mll-use-password">{'Sign in with password'|translate}</a>
        </p>
    </div>

</div>

<script>
(function () {
  'use strict';

  var mll     = document.getElementById('mll-block');
  var form    = document.getElementById('mll-form');
  var sentBox = document.getElementById('mll-sent');
  var errBox  = document.getElementById('mll-error');
  var btn     = document.getElementById('mll-submit');
  var fwrap   = document.getElementById('mll-form-wrap');

  if (!mll) return;

  // ── 1. Detect theme & locate password form ────────────────────────────
  // standard_pages wraps the login form in <section id="login-form">.
  // The classic default theme uses <form name="login_form">.
  var loginSection = document.querySelector('section#login-form');
  var pwdForm = document.querySelector('form[name="login_form"]');

  if (!pwdForm) {
    // No recognisable login form — just show block wherever it is.
    mll.style.display = 'block';
    mll.removeAttribute('aria-hidden');
    return;
  }

  // ── 2. Move block into position ───────────────────────────────────────
  if (loginSection) {
    // standard_pages: insert inside the inner wrapper <div>, after <h1>
    var innerDiv = loginSection.querySelector('div') || loginSection;
    var h1 = innerDiv.querySelector('h1');
    var refNode = (h1 && h1.nextSibling) ? h1.nextSibling : innerDiv.querySelector('form');
    innerDiv.insertBefore(mll, refNode);
    mll.classList.add('mll-sp'); // signals: standard_pages CSS handles styling
  } else {
    // default theme: insert before the password form
    pwdForm.parentNode.insertBefore(mll, pwdForm);
  }

  // ── 3. Show our block, hide password form ─────────────────────────────
  mll.style.display = 'block';
  mll.removeAttribute('aria-hidden');

  // Hide password form cleanly (no layout jump)
  pwdForm.classList.add('mll-hiding');
  pwdForm.setAttribute('aria-hidden', 'true');

  // ── 4. "Sign in with password" toggle ────────────────────────────────
  var pwdLink   = document.getElementById('mll-use-password');
  var backLink  = null; // "Use magic link instead" — created when needed

  if (pwdLink) {
    pwdLink.addEventListener('click', function (e) {
      e.preventDefault();
      mll.style.display = 'none';
      mll.setAttribute('aria-hidden', 'true');

      pwdForm.classList.remove('mll-hiding');
      pwdForm.removeAttribute('aria-hidden');

      // Focus the username field
      var uname = pwdForm.querySelector('[name="username"]');
      if (uname) uname.focus();

      // Add "Use magic link instead" below the password form (once)
      if (!backLink) {
        backLink = document.createElement('p');
        backLink.style.cssText = 'text-align:center; margin-top:1em; font-size:.9em;';
        backLink.innerHTML = '<a href="#" id="mll-back-link">{'Use magic link instead'|translate}</a>';
        pwdForm.parentNode.insertBefore(backLink, pwdForm.nextSibling);

        document.getElementById('mll-back-link').addEventListener('click', function (e2) {
          e2.preventDefault();
          pwdForm.classList.add('mll-hiding');
          pwdForm.setAttribute('aria-hidden', 'true');
          backLink.style.display = 'none';
          mll.style.display = 'block';
          mll.removeAttribute('aria-hidden');
          document.getElementById('mll-email').focus();
        });
      } else {
        backLink.style.display = '';
      }
    });
  }

  // ── 5. AJAX submission ────────────────────────────────────────────────
  form.addEventListener('submit', function (e) {
    e.preventDefault();

    btn.disabled = true;
    btn.textContent = '…';
    errBox.style.display = 'none';

    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.status === 'ok') {
        fwrap.style.display = 'none';
        sentBox.style.display = 'block';
      } else {
        showErr();
      }
    })
    .catch(showErr);

    function showErr() {
      btn.disabled = false;
      btn.textContent = btn.getAttribute('data-label') || 'Send magic link';
      errBox.style.display = 'block';
    }
  });

}());
</script>
