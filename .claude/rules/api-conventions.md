---
paths:
  - "includes/class-ajax.php"
  - "includes/class-form-handlers.php"
  - "includes/class-api-license.php"
  - "includes/class-github-updater.php"
  - "includes/class-csv-place-importer.php"
  - "includes/class-legacy-import-service.php"
  - "includes/class-deploy-service.php"
---

# API and request conventions

Admin AJAX lives in `Radius_Ajax` (`wp_ajax_radius_*`). Form POSTs live in `Radius_Form_Handlers`. Both follow the same order:

1. `check_ajax_referer()` or `wp_verify_nonce()` with the existing action name for that screen (`radius_admin`, `radius_deploy_batch`, and the other actions already in those classes).
2. `current_user_can()`. `manage_options` for settings, import, purge, and maintenance. `edit_posts` only where deploy already uses it. Do not weaken an existing check.
3. Sanitize every `$_POST` / `$_GET` value. Reply with `wp_send_json_success()` or `wp_send_json_error()` and a translated `message`. Use a 4xx status for forbidden or bad input.
4. No `wp_ajax_nopriv_*` for admin operations. Public analytics beacons that already exist stay nonce-checked and do not gain new write powers.

SQL goes through `$wpdb->prepare()`. Table names come from trusted prefixes, not request data.

The license client (`Radius_API_License`) and the GitHub updater (`Radius_GitHub_Updater`) must keep talking to the configured endpoints only. Do not log license keys or authorization headers.

CSV and legacy import accept uploaded files through WordPress upload APIs, then read them server-side. Do not pass a client-supplied path to `fopen`.

Deploy (`Radius_Deploy_Service::deploy()`) creates or updates `radius_landing` or `radius_service_area` from a template id and place term ids. Token replacement is `Radius_Template_Tokens::build_map()` then `Radius_Token_Engine::render()`. Do not write a second renderer.
