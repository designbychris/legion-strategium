# The Legion Strategium

A public, edition-aware army builder and battle companion, implemented as a WordPress plugin.

## Phase I.1 — The Strategium Awakens

This is an **installable foundation**, not a completed army builder. It provides:

- Plugin activation and eight versioned custom database tables.
- Separate editions and ruleset releases; armies pin to a release.
- WordPress admin status page (Strategium).
- `[legion_strategium]` shortcode for a responsive public/sign-in shell.
- Read-only REST endpoints: `/wp-json/legion-strategium/v1/status` (public), `/me` (authenticated).
- Standalone PHPUnit schema tests.
- Data preservation on uninstall.

## Installation

Upload the ZIP through WordPress Plugins > Add New > Upload Plugin, or copy the `legion-strategium-main` directory into `wp-content/plugins` and activate. Add `[legion_strategium]` to a page. For production, configure WordPress user registration, HTTPS, anti-abuse controls and email verification before opening public signups. **No public registration flow is implemented yet.**

## Development

Requires PHP 8.1+ and WordPress with a supported MySQL/MariaDB version. Run `composer install` then `vendor/bin/phpunit`. The schema tests run without WordPress. Run WordPress integration tests separately before production deployment.

## Roadmap

I.2 account registration/login and ownership; II edition-aware catalogue admin; III army editor; IV validation engine; V battle tracking; VI offline-capable PWA. Future ruleset editions can be added without modifying existing army snapshots.

## Intellectual property

No Games Workshop rule text, artwork, stats or points data are bundled. Confirm permissions and content rights before publicly distributing third-party game data. This project is independent and not affiliated with Games Workshop.
