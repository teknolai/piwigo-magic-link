{* MagicLinkLogin — Email body template (HTML) *}
{* Piwigo wraps this in its standard email chrome (header/footer/theme CSS). *}
{* The gallery name and "Your magic login link" subtitle come from the wrapper, *}
{* so this template only contains the body content. *}

<p>
  Click the button below to sign in to your photo gallery.
  This link is <strong>single-use</strong> and expires in <strong>{$EXPIRES_MIN} minutes</strong>.{if $UA_BOUND} For your security, it only works in the same browser you used to request it.{/if}
</p>

<p style="text-align: center; margin: 1.5em 0;">
  <a href="{$VERIFY_URL|escape:'html'}"
     style="display: inline-block;
            padding: 0.6em 2em;
            background-color: #ff7700;
            color: #ffffff;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;">
    Sign in to gallery
  </a>
</p>

<p style="font-size: 0.85em; color: #666;">
  If the button doesn't work, copy and paste this URL into your browser:<br>
  <a href="{$VERIFY_URL|escape:'html'}" style="color: #ff7700; word-break: break-all;">
    {$VERIFY_URL|escape:'html'}
  </a>
</p>
