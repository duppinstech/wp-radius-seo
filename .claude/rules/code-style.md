---
paths:
  - "**/*.php"
  - "assets/**/*.js"
  - "assets/**/*.css"
---

# Code style

WordPress PHP coding standards, in the shape this repo already uses.

- PHP 7.4. Tabs for indentation. `array()` for arrays. Opening braces on the next line for classes and methods.
- Every PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }` except the plugin bootstrap, which does the same after the header.
- File docblock includes `@package Radius`. Classes are `Radius_*` and match the filename: `class-deploy-service.php` → `Radius_Deploy_Service`.
- User-facing strings use the `radius` text domain and a translator function (`__()`, `esc_html__()`, `esc_attr__()`).
- Escape at output: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` when markup is intentional.
- Sanitize at input: `sanitize_text_field`, `sanitize_key`, `absint`, `esc_url_raw`, as the data type requires. Unslash request values before sanitizing.
- Prefix hooks, nonces, script handles, and CSS classes with `radius`. Do not add new `lf-` or `localeforge-` names.
- Admin JS and CSS stay `radius-*.js` and `radius-*.css`. Enqueue from the admin class that already owns that screen.
- Prefer a small change in the class that already owns the behavior. Do not rename a public method or meta key as a drive-by.
- `phpcs:ignore` is allowed only with the rule id and a short reason, on the line it covers.
