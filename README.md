# Radius SEO

Blueprint-first local SEO for WordPress: templates, CSV-backed place library, batch deploy, tokens, spintax, Markdown slots, and optional Elementor or Beaver Builder support.

**Product of:** [Duppins Technology](https://duppinstech.com/)  
**Plugin URI:** [github.com/duppinstech/wp-radius-seo](https://github.com/duppinstech/wp-radius-seo)  
**Requires:** WordPress 6.0+, PHP 7.4+  
**Current version:** see `radius.php` header or `readme.txt` stable tag.

---

## Install

1. Download a release ZIP from [Releases](https://github.com/duppinstech/wp-radius-seo/releases) (ZIP root must be a single folder containing `radius.php`, e.g. `wp-radius-seo/radius.php`).
2. In wp-admin: **Plugins → Add New → Upload Plugin**, choose the ZIP, install, and activate.

Or clone this repository into `wp-content/plugins/wp-radius-seo/` (or any folder name; the main file remains `radius.php` in that folder).

---

## Quick start

1. **Radius → Settings → License** — an administrator pastes a signed Radius license from Duppins Technology and clicks Save. A key already saved on this site keeps working until it is replaced. Until a key is saved, the library, templates, and deploy stay locked.
2. **Radius → Settings → Service areas** — add at least one place and a radius in miles, then save. Only an administrator can upload the place CSV. Include latitude and longitude, or deploy skips those rows. Rows already in the library are skipped unless **Update existing places** is checked.
3. **Templates** — create a blueprint using tokens in title and body.
4. **Deploy** — run **Deploy & update all places** for the template. If a batch stops, use **Continue deployment**. If the screen reports a server error, open **Radius → Logs**.
5. **Settings → Integrations** — if the template is built in Elementor or Beaver Builder, turn that checkbox on, save, then deploy.

---

## Help and support

Every Radius screen has a **Help** button at the bottom-right. It opens notes for that screen and a link to the user guide at https://duppinstech.com/radius-seo/guide/ , on the section for that screen. If that page does not open, read the notes in the Help panel, then use **Radius → Support** or email support@duppinstech.com. Call (240) 397-8221. The same illustrated manual is `assets/manual/radius-seo-user-manual.pdf` inside the plugin folder. Help does not download it. An administrator also gets **Contact support**, which opens **Radius → Support**. The message includes the name, email, screen, subject, both descriptions, the site address, and the WordPress and Radius versions. It is not stored on the site. Filter: `radius_user_guide_url`.

## Deactivate

**Plugins → Deactivate** asks whether to keep Radius data or delete it. Cancel, Close, and a click outside leave the plugin active. **Keep all data and deactivate** does not remove pages or settings. **Delete all Radius data** shows the counts and runs only after **Confirm delete**. Deleting the plugin files later does not remove data that was kept. See `SUPPORT.md`.

## Updates

Installed sites show an update under **Dashboard → Updates** only when the latest release includes a file named `wp-radius-seo.zip`. If that update is missing, use **Dashboard → Updates → Check again**. If the release file has another name, upload `wp-radius-seo.zip` with **Plugins → Add New → Upload Plugin**.

You can still upload a newer ZIP manually. Turn checks off with `define( 'RADIUS_DISABLE_GITHUB_UPDATER', true );`. Filters: `radius_github_updater_config`, `radius_github_release_http_args`.

**Maintainers — shipping a version:** merge your changes, bump `Version` / `RADIUS_VERSION` and `readme.txt` (stable tag + changelog), push `main`, then push a tag `vX.Y.Z` that matches that version. `.github/workflows/release.yml` checks the tag, runs lint and tests, and attaches `wp-radius-seo.zip` from `bash bin/build-zip.sh`. Without that release asset, installed sites will not see the update in **Dashboard → Updates**.

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

**GPL-2.0-or-later.** Summary in [`LICENSE.md`](LICENSE.md); full legal text in [`LICENSE`](LICENSE). The plugin header uses `GPL-2.0-or-later`. [`readme.txt`](readme.txt) uses `GPLv2 or later`, the WordPress readme wording for the same license.

---

## Community & policies

| Document | Purpose |
|----------|---------|
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | How to contribute, coding norms, release ZIP workflow |
| [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) | Contributor Covenant community expectations |
| [`SECURITY.md`](SECURITY.md) | Supported versions, **private** vulnerability reporting |
| [`SUPPORT.md`](SUPPORT.md) | Where to get help, documentation pointers |
| [`CHANGELOG.md`](CHANGELOG.md) | Points to `readme.txt` + GitHub Releases (single changelog source) |

**Issues & PRs:** [github.com/duppinstech/wp-radius-seo](https://github.com/duppinstech/wp-radius-seo). Use the issue templates under **.github/ISSUE_TEMPLATE/** when reporting bugs or requesting features.

## Local development

From the repo root:

```bash
docker compose up -d
composer install
```

The local site is http://127.0.0.1:8088. Stop it with `docker compose down`.

## Tests

```bash
composer lint
composer test
```

## Release

Maintainers ship from the **Maintainers — shipping a version** steps under [Updates](#updates).
