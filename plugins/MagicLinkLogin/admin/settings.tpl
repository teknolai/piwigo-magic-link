{* MagicLinkLogin — admin settings page *}

<div class="titrePage">
  <h2>{'Magic Link Login'|translate}</h2>
</div>

<form method="post" action="{$MLL_FORM_ACTION}" class="properties">
  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">

  <fieldset>
    <legend>{'Security'|translate}</legend>

    <ul>
      <li>
        <label>
          <input type="checkbox" name="mll_verify_ua" value="1"{if $MLL_VERIFY_UA} checked="checked"{/if}>
          {'Bind each login link to the browser that requested it'|translate}
        </label>
        <p class="mll-hint">
          {'When enabled, a magic link only works in the same browser it was requested from. This is a little more secure, but breaks the common case where a user requests the link on their computer and opens the email on their phone. Turn it off if your users check email on a different device.'|translate}
        </p>
        {if $MLL_UA_OVERRIDDEN}
          <p class="mll-warning">
            {'Note: a MLL_VERIFY_UA constant in your config.inc.php is currently overriding this setting. Remove it to control the option from here.'|translate}
          </p>
        {/if}
      </li>
    </ul>
  </fieldset>

  <p class="mll-actions">
    <input class="submit" type="submit" name="mll_submit" value="{'Save Settings'|translate}">
  </p>
</form>

<fieldset>
  <legend>{'Email delivery'|translate}</legend>

  <p>
    {'Magic links are sent using Piwigo\'s own mail configuration. They are sent in English only.'|translate}
  </p>

  <p>
    <strong>{'Heads up about spam filters.'|translate}</strong>
    {'Login emails are time-critical, so it matters that they reach the inbox. Without proper SPF, DKIM and DMARC records on your sending domain, providers like Gmail and Outlook often route them to spam or drop them silently. Plain PHP mail() on shared hosting is especially unreliable.'|translate}
  </p>

  <p>
    {'Recommendation: send through an authenticated SMTP relay (e.g. a transactional email provider) on a domain where you control the DNS, and add the SPF/DKIM/DMARC records that provider gives you. SMTP is configured in local/config/config.inc.php (smtp_host, smtp_user, smtp_password, smtp_secure) — there is no admin screen for it.'|translate}
  </p>

  <ul class="mll-status">
    <li>
      {'SMTP relay configured:'|translate}
      {if $MLL_SMTP_OK}
        <strong style="color:#137333;">{'yes'|translate}</strong>
      {else}
        <strong style="color:#c00;">{'no — using PHP mail(), deliverability may be poor'|translate}</strong>
      {/if}
    </li>
    <li>{'Sender address:'|translate} <code>{$MLL_SENDER_EMAIL}</code></li>
  </ul>
</fieldset>

<fieldset>
  <legend>{'How it works'|translate}</legend>
  <ul>
    <li>{'Login links are single-use and expire after %d minutes.'|translate:$MLL_TOKEN_MINUTES}</li>
    <li>{'Only the SHA-256 hash of each link is stored — never the link itself.'|translate}</li>
    <li>{'Unknown email addresses are auto-registered as new gallery users on first successful login.'|translate}</li>
    <li>{'The standard username/password form is left untouched; the email form is simply added above it.'|translate}</li>
  </ul>
</fieldset>

<style>
  .mll-hint    { color:#666; font-size:.9em; margin:.3em 0 0; }
  .mll-warning { color:#b25000; font-size:.9em; margin:.4em 0 0; }
  .mll-actions { margin:1em 0; }
  .mll-status li { margin:.25em 0; }
</style>
