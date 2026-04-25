{* MagicLinkLogin — Email body template (HTML) *}
{* Piwigo wraps this in its standard email chrome (header/footer/theme). *}

<p style="font-size: 1rem; color: #333; margin: 0 0 1.2em;">
  Hi there,
</p>

<p style="font-size: 1rem; color: #333; margin: 0 0 1.5em;">
  Click the button below to sign in to your photo gallery.
  This link is <strong>single-use</strong> and expires in <strong>{$EXPIRES_MIN} minutes</strong>.
</p>

<p style="text-align: center; margin: 2em 0;">
  <a href="{$VERIFY_URL|escape:'html'}"
     style="display: inline-block;
            padding: 0.75em 2em;
            background-color: #0070f3;
            color: #ffffff;
            text-decoration: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: bold;">
    Sign in to gallery
  </a>
</p>

<p style="font-size: 0.85rem; color: #666; margin: 1.5em 0 0;">
  If the button doesn't work, copy and paste this URL into your browser:<br>
  <a href="{$VERIFY_URL|escape:'html'}" style="color: #0070f3; word-break: break-all;">
    {$VERIFY_URL|escape:'html'}
  </a>
</p>

<hr style="border: none; border-top: 1px solid #eee; margin: 1.5em 0;">

<p style="font-size: 0.8rem; color: #999; margin: 0;">
  If you didn't request this link, you can safely ignore this email.
  Someone may have entered your address by mistake.
</p>
