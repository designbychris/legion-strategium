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

## Phase I.2 — The Gates of the Strategium

- Branded sign-in and registration forms inside `[legion_strategium]`.
- Registration respects **Settings → General → Anyone can register**. Set **New User Default Role** to Subscriber; the plugin always explicitly creates subscribers.
- 12-character minimum password, WordPress nonces, safe redirects, escaped output, and core authentication.
- Authenticated command dashboard (placeholder counts until army features arrive), logout, and WordPress password reset link.
- No email verification or anti-bot challenge is included yet. **Before opening public registration**, add verified-email and abuse protection, configure reliable transactional email, and review site privacy/terms. WordPress core's new-user email notification is not an email-verification gate.
- If your WordPress security configuration blocks `admin-post.php`, allow the `ls_login`, `ls_register`, and `ls_logout` handlers.


## Phase I.3 — Commander Identity

New registrations require email verification. Verification links expire after 24 hours; resend is available on the login screen. Pre-existing WordPress accounts remain eligible to sign in. Registration and resend requests are throttled per IP using WordPress transients; add an edge/WAF anti-bot solution before high-traffic public launch. Logged-in users can set a public commander name. Email and password management remain in WordPress for now. Ensure WordPress transactional email delivery works before enabling public registrations.

## Phase I.4 — The Commander's Seal

The dashboard greeting now uses the public commander name (falling back to the WordPress display name). Commander account settings include a discoverability opt-in (off by default), verified email changes (24-hour token delivered to the new address), and password changes requiring the existing password and at least 12 characters. Changing a password ends the current login session. Email-change tokens are single-use; the current address remains in effect until confirmation. Public opponent search is not yet implemented. Before opening public registration broadly, add stronger anti-bot controls, email-delivery monitoring, and WordPress integration tests.

## Phase II.1 — The Armoury Opens

The WordPress **Strategium** admin screen now provides draft edition, release, faction,
and unit creation. Add records in that order. Unit records include a sample battlefield
role and nonnegative base points. The frontend `[legion_strategium]` shortcode displays
a read-only, release-grouped catalogue to signed-in commanders.

This first pass is deliberately **create-only**. Editing, publishing releases, deletion,
full statlines, wargear and detachment validation are planned for later phases. Do not
enter copyrighted official game data without appropriate rights. All newly created
releases are drafts and are not yet a formally published rules catalogue.


## Phase II.2 — Draft catalogue corrections
Draft factions and units now have inline editing and deletion controls in the WordPress Armoury. Units can be reassigned to another faction in the same release. Published releases and releases already referenced by saved armies are locked against these edits/deletions. Factions with units cannot be deleted. This is an initial management pass; structured characteristics, release publication and full archive workflows remain future work.
