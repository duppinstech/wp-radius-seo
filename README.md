# Radius SEO

Blueprint-first local SEO for WordPress: templates, CSV-backed place library, batch deploy, tokens, spintax, Markdown slots, and optional Elementor support.

**Plugin URI:** [github.com/oduppinsjr/wp-radius-seo](https://github.com/oduppinsjr/wp-radius-seo)  
**Requires:** WordPress 6.0+, PHP 7.4+  
**Current version:** see `radius.php` header or `readme.txt` stable tag.

---

## Install

1. Download a release ZIP from [Releases](https://github.com/oduppinsjr/wp-radius-seo/releases) (use an asset whose root folder is `radius/`, containing `radius.php`).
2. In wp-admin: **Plugins → Add New → Upload Plugin**, choose the ZIP, install, and activate.

Or clone this repository into `wp-content/plugins/radius/` (same folder layout).

---

## Quick start

1. **Radius → Location library** — upload a CSV (`name`, `slug`, `country`, `region`, `state`, `zip`, `lat`, `lng`).
2. **Templates** — create a blueprint using tokens in title and body.
3. **Deploy** — pick the template, supply place term IDs, run the batch deploy.
4. **Settings** — enable Elementor if you want Elementor-editable landings.

---

## Updates

This plugin does **not** bundle a custom WordPress.org–disallowed update client. To stay current from GitHub:

- Upload a newer release ZIP via **Plugins → Add New → Upload Plugin**, or  
- Use a Git-based updater (for example [Git Updater](https://git-updater.com/)) pointed at this repository.

WordPress.org–style metadata and hooks are documented in **`readme.txt`** (used for directory submissions and translators).

---

## Repository layout

| Path | Purpose |
|------|--------|
| `radius.php` | Bootstrap and constants |
| `includes/` | PHP classes (`Radius_*`) |
| `assets/` | Admin CSS/JS; bundled vendor scripts where needed |
| `readme.txt` | WordPress plugin readme (stable tag, tested-up-to, changelog) |
| `languages/` | Optional `.mo` / `.po` translations |

---

## License

GPL-2.0-or-later. See `readme.txt` and the plugin file header.

---

## Support & contributing

Issues and pull requests: [oduppinsjr/wp-radius-seo](https://github.com/oduppinsjr/wp-radius-seo).
