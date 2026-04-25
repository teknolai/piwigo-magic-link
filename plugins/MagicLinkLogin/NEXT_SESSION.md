# Next session — UI fixes (magic link form)

## What the screenshots show

### Screenshot 1 — Default theme (old Piwigo login page)
Dark inputs, white text labels, dark background form area.
The theme is dark-ish (inputs ~#2a2a2a, labels near-white).

### Screenshot 2 — Default theme (our new form)
Magic link form IS at the top and password is hidden — JS placement
WORKS. But the form looks wrong:
- Uses white/light gray input border (#ccc) — should match the dark theme
- Form spans full page width — not contained/centred the way the password
  form was (which uses a fixed-width fieldset)
- Mostly fine otherwise

### Screenshot 3 — Standard pages theme
Our form IS NOT VISIBLE AT ALL. The password form shows unchanged.
Root cause: `footer_elements` is not rendered by the standard_pages
theme (it has its own header/footer, bypassing Piwigo's page_tail.php).
Our HTML block is injected into `footer_elements` but that variable
never appears in the standard_pages page, so the JS never runs.

---

## Bugs to fix

### BUG-A: Standard pages — form not injected at all
`mll_inject_form()` in `main.inc.php` appends to `footer_elements`.
Standard_pages theme uses its own template structure that does NOT
include `{foreach from=$footer_elements}` (or equivalent). HTML never
reaches the browser.

**Fix approaches to investigate:**
1. Detect standard_pages active in PHP (`$conf['use_standard_pages']` or
   check active theme name) and use a different injection variable.
2. Use PHP output buffering in `loc_end_identification` hook to inject
   directly into the rendered HTML (fragile but reliable).
3. Look for a variable that IS rendered in standard_pages template —
   e.g. something in header.tpl or a shared footer variable.
4. Best bet: check standard_pages/template/identification.tpl for
   variables we can assign to — it renders `{$HELP_LINK}` and language
   options. We could add a new block by assigning to a custom variable
   and patching the template... but that requires editing core files.
5. Cleanest: in `mll_inject_form()`, check if `section#login-form`
   page structure exists (via $conf or theme check) and if so, append
   to the `{combine_script}` output or use a dedicated Smarty variable.

Actually, the simplest fix: inject the block via `loc_end_identification`
using output buffering:
```php
add_event_handler('loc_end_identification', function() {
    echo '<div id="mll-block" ...' // our HTML
});
```
This fires after the template is parsed and output, so it appends to
the raw output. The JS then moves it into position regardless of theme.
This sidesteps the footer_elements issue entirely.

### BUG-B: Default theme — form too wide and colors wrong
The default theme uses a dark color scheme (dark inputs). Our fallback
CSS uses #ccc border and transparent background which looks out of place.
The form is also full-width; the native form used a narrower fieldset.

**Fix:** Add max-width constraint and check what CSS the default theme
uses for form elements. Look at:
  /app/www/public/themes/default/css/...
  /app/www/public/themes/modus/css/...  (if that's the active theme)

---

## UX change requested
**Show BOTH login options at the same time** instead of hiding one
and toggling. Simpler layout:
  1. [Magic link form — email + send button]
  2. ─── or ───
  3. [Password form — username + password + submit]

Eliminates the hide/show toggle complexity entirely. Much simpler JS.
The divider between them makes it clear there are two options.

Suggested layout for default theme: stacked vertically, centred,
same width as password form.
Suggested layout for standard_pages: magic link section inside
#login-form card above the password section, same native styling.

---

## Files to touch next session
- `plugins/MagicLinkLogin/main.inc.php` — change injection hook/method
- `plugins/MagicLinkLogin/template/magic_link_form.tpl` — simplify UX
  (no more hide/show toggle), fix default theme width/colors

## Reference commands for debugging
```bash
# Check standard_pages footer template for footer_elements variable
docker compose exec piwigo bash -c "grep -r 'footer_elements' /app/www/public/themes/standard_pages/"

# Check what theme is currently active
docker compose -f docker-compose.yaml exec db mysql -u piwigo -ppiwigo piwigo \
  -e "SELECT value FROM piwigo_config WHERE param='default_theme';"

# Check use_standard_pages config
docker compose -f docker-compose.yaml exec db mysql -u piwigo -ppiwigo piwigo \
  -e "SELECT value FROM piwigo_config WHERE param='use_standard_pages';"

# Tail PHP error log
docker compose exec piwigo bash -c "tail -f /config/log/php/error.log"
```
