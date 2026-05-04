# Contributing to Radius SEO

Thanks for helping improve Radius SEO. This project is a WordPress plugin distributed under the **GPL-2.0-or-later** (see [`LICENSE`](LICENSE) and [`LICENSE.md`](LICENSE.md)).

## Code of conduct

Participation is governed by our [Code of Conduct](CODE_OF_CONDUCT.md). Be respectful and constructive.

## How to contribute

1. **Issues first** — For non-trivial changes, open an issue (or discuss in an existing one) so direction aligns before large PRs.
2. **Fork & branch** — Create a branch from `main` (`feature/…`, `fix/…`, etc.).
3. **Pull request** — One focused PR per concern; reference related issues.

## Development expectations

- **PHP:** 7.4+ (match `readme.txt`).
- **WordPress:** 6.0+.
- **Standards:** Follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/) where practical: escaping output (`esc_html`, `esc_attr`, `esc_url`), capability checks, nonces for form POSTs, prepared SQL (`$wpdb->prepare`).
- **Scope:** Keep changes minimal and focused; avoid unrelated refactors in the same PR.
- **Plugin Check:** Aim for compatibility with [Plugin Check](https://wordpress.org/plugins/plugin-check/) guidelines when touching code paths it flags.

## Project conventions

- Class prefix **`Radius_`**, option/meta **`radius_*`** / **`_radius_*`** (see `includes/class-data-registry.php`).
- Text domain: **`radius`** for user-facing strings.
- Admin assets: **`radius-*.js`** / **`radius-*.css`** (not legacy `lf-*`).

## Versioning & releases (maintainers)

- **Patch** (e.g. `1.6.x`): small fixes, incremental UI tweaks.
- **Minor** (e.g. `1.7.0`): larger features or meaningful behavior changes.
- Bump **`Version`** and **`RADIUS_VERSION`** in `radius.php`, **`Stable tag`** + changelog in **`readme.txt`**, push `main`, then **[publish a GitHub Release](https://github.com/oduppinsjr/wp-radius-seo/releases/new)** with tag `vX.Y.Z` and attach **`wp-radius-seo.zip`** built with:

  ```bash
  git archive --format=zip --prefix=wp-radius-seo/ -o wp-radius-seo.zip HEAD
  ```

  Without a release ZIP, sites using the GitHub updater will not see updates under **Dashboard → Updates**.

## Translations

Optional `.mo` / `.po` files can live under `languages/`. String changes should keep `readme.txt` and user-facing copy consistent.

## Questions

Open a [discussion-style issue](https://github.com/oduppinsjr/wp-radius-seo/issues) or refer to [`README.md`](README.md) and [`readme.txt`](readme.txt).
