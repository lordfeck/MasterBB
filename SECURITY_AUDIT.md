# MasterBB security audit

## Status and scope

This audit tracks the security hardening of the PHP 8.4 and MariaDB 11.4
port. Its starting point is commit `efd9c34`, preserved by the
`PortToPhp8Complete` branch. Only fresh installations are supported, so schema
and credential changes do not require migration compatibility.

The intended destination is a conventional HTTPS deployment, including
operation behind an explicitly configured trusted reverse proxy. The original
HTML layout, navigation, URLs, BBCode, and deliberately simple user experience
remain in scope. Raw user-authored HTML is not required and will be removed.

The audit covers the application source, installer, database schema, Docker
defaults, authentication and authorization, sessions and cookies, rendered
output, and HTTP behavior. Host operating-system, reverse-proxy, DNS, and mail
server configuration are outside the repository and will instead receive
documented deployment requirements.

## Method

Findings combine:

- manual source review from named request inputs to database, HTML, header,
  mail, and file-system sinks;
- an inventory of public, authenticated, moderation, and administration
  operations;
- black-box reproduction inside disposable Docker installations; and
- regression tests that distinguish already-blocked attacks from known-open
  vulnerabilities.

The baseline contains 235 `db_query()` call sites and 63 HTML forms. Some
queries are static or installer DDL, but application queries currently share
an API that executes complete SQL strings, so each call site must be classified
and converted or explicitly justified.

Severity means:

- **Critical:** credible account, database, or site compromise, or a systemic
  control missing from most state-changing operations.
- **High:** serious confidentiality or integrity loss requiring a particular
  user interaction, role, or application state.
- **Medium:** meaningful defense-in-depth, abuse, disclosure, or validation
  weakness that amplifies other findings.
- **Low:** limited direct impact or deployment hygiene.

## Standards basis

The remediation approach follows the OWASP guidance for
[parameterized queries](https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html),
[contextual output encoding](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html),
[password storage](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html),
[session management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html),
[CSRF protection](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html),
and [security response headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html).
PHP's current APIs provide
[adaptive password hashing](https://www.php.net/manual/en/function.password-hash.php)
and [modern cookie attributes](https://www.php.net/manual/en/function.setcookie.php).

## Attack-surface inventory

| Area | Entry points | Current mutations | Required authority |
| --- | --- | --- | --- |
| Public browsing | `index`, `viewforum`, `viewtopic`, `search`, member list, profile view, FAQ, online list | Topic view count and online-presence records are updated during reads | Public, subject to forum visibility |
| Authentication and accounts | `login`, `logout`, registration, profile, preferences, password reset | Users, credentials, sessions, profile fields, cookies | Anonymous for registration/reset; owner for profile/preferences |
| Posts and topics | `newtopic`, `reply`, `editpost`, `topicadmin` | Create/edit/delete posts; create/move/lock/delete topics | Forum posting rules, owner edit window, moderator/admin operations |
| Private messages | `sendpmsg`, `viewpmsg`, `replypmsg`, `delpmsg` | Send/read-state/reply/delete messages | Authenticated sender or recipient as appropriate |
| Administration | forum, board, theme, user, private-forum, and smile administration | Site configuration, display-only header/footer text, roles, bans, forums, access lists, themes, ranks, words, smiles | Administrator only |
| Installation | `install.php` | Database/schema creation, administrator creation, config append, board configuration | Unauthenticated before one-time installation only |

Several historical links performed mutations with GET, including theme
default/delete, private-forum access changes, PM deletion, and smile deletion.
Slice 5 converted them to either GET confirmation pages followed by protected
POST operations or POST-only controls. Topic moderation retains its historical
confirmation flow and now shares the same CSRF boundary.

## Findings

### SEC-001 — SQL strings interpolate data

**Severity:** Critical

**Status:** Closed in Slice 2

`database.php::db_query_params()` now uses native PDO prepares and binds every
value separately with an appropriate string, integer, or null type. All
request-, session-, stored-, and derived-value queries in public, account, PM,
moderation, administration, and installation paths use that boundary. Dynamic
lists generate placeholders; pagination limits are integer-bound; search and
member-list ordering retain explicit allowlists. Direct execution remains only
for constant statements, installer schema/seed constants, and the installer's
database identifier after a strict identifier check.

The conversion also removed the scattered SQL `addslashes()` calls and the
historical mutating SQL regex in `fix.php`. Fresh-install smoke tests pass on
PHP 8.4/MariaDB 11.4, the negative SQL-shaped login and search-sort tests pass,
and the security fixture now registers and authenticates a username containing
an apostrophe to verify that legitimate data survives the parameter boundary.

### SEC-002 — Password and password-reset primitives are obsolete

**Severity:** Critical

**Status:** Closed in Slice 4

Before Slice 4, the fresh schema limited password fields to 32 characters and
registration, login, posting fallbacks, profiles, and installation used
unsalted MD5. Password reset created an eight-character value with `rand()`,
stored its MD5, and derived the activation key deterministically from that
hash. Reset activation was a state-changing GET.

Fresh installs now use Argon2id when available, with `PASSWORD_DEFAULT` as the
runtime fallback, through centralized hashing and verification helpers. The
schema allows 255-character hashes and all credential creation and comparison
paths use the shared primitives. Password creation enforces a 12-to-255-byte
length boundary.

Password reset now returns the same response for matching and non-matching
account details, generates a 256-bit random one-time token, stores only its
SHA-256 digest and expiry, and changes the password only through POST. Viewing
the link is non-destructive. Successful reset atomically consumes the token and
revokes all sessions for the account; successful profile password changes also
revoke existing sessions. Registration email no longer contains the supplied
password.

### SEC-003 — CSRF protection and safe-method discipline are absent

**Severity:** Critical

**Status:** Closed in Slice 5

Every POST is now checked at the shared authentication boundary before the
requested operation runs. The installer initializes and checks the same
boundary independently because it runs before application configuration is
available. A 256-bit random token is carried by a scoped, HttpOnly,
`SameSite=Lax` cookie and by a hidden field automatically added to every POST
form; comparison uses `hash_equals()`. Tokens rotate when authentication or
credential re-authentication creates a replacement session, and logout clears
the browser token.

Logout, private-message deletion, theme deletion/default selection, smile
deletion, and private-forum permission changes no longer mutate in response to
GET. Existing confirmation pages were retained where they fit the original
workflow; direct controls submit POST. Read requests still update topic view
counts, message read-state, session activity, online-presence records, and
expired housekeeping rows. These are intentional display/telemetry side
effects and do not grant authority or change user-authored content.

The black-box suite confirms that a tokenless authenticated reply receives
HTTP 403 and is not persisted, installer POSTs reject a missing token, tokens
rotate at login, and the former GET mutation routes either render confirmation
or return HTTP 405 without changing data. The complete fresh-install and
security suites pass.

### SEC-004 — User content can execute as stored HTML

**Severity:** Critical

**Status:** Closed in Slice 3

Raw HTML is now permanently disabled in both fresh installation and runtime
configuration, including when an old client forges the historical HTML form
field. A shared renderer first encodes user-authored source and then expands
the retained BBCode and smilie subset. BBCode links, images, automatic links,
profile websites, and email links are emitted through scheme-aware URL
helpers; only HTTP(S) web URLs and validated mail addresses become active.
Obsolete ICQ/AIM/Yahoo active integrations have been removed.

Text and quoted-attribute sinks across topics, posts, profiles, member lists,
private messages, search, forums, themes, ranks, smilies, and administration
now use explicit contextual encoding. Signatures use the same constrained
renderer as posts. The administrator header/footer facility is display text
only, while its former arbitrary meta field is a safely encoded description.
The default-language FAQ and installation/board settings now state that raw
HTML is unavailable.

Black-box tests confirm that stored script markup in posts, PMs, profiles,
signatures, and site-wide header/footer text is inert; reflected administrator
login input is encoded; unsafe profile and BBCode URL schemes are not active;
and ordinary BBCode, safe links, and smilies still render. Slice 7 completed
validation of theme field formats and restriction of theme/rank assets to
approved local paths; output remains attribute-encoded and rejects active
non-web schemes.

### SEC-005 — Session identifiers and cookies are not modern

**Severity:** High

**Status:** Closed in Slice 4

Before Slice 4, `new_session()` reseeded and called `mt_rand()`, producing a
small numeric token stored in plaintext. Session cookies lacked `HttpOnly` and
`SameSite`; `Secure` was a static configuration boolean. Sessions were bound
to the apparent client IP, which is unreliable behind proxies and can harm
users whose address changes. Authentication logic was duplicated across many
pages, and logging out deleted every session for the user rather than the
current session.

Sessions now use 256-bit `random_bytes()` tokens while only SHA-256 digests are
stored. Authentication and credential re-authentication rotate the current
token; sessions enforce configurable idle and absolute lifetimes; logout
revokes only the presented session. Password changes and reset remain the
intentional account-wide revocation cases. Session identity is no longer bound
to a changing client IP.

All application cookies use `HttpOnly`, `SameSite=Lax`, a scoped path, and
`Secure` whenever the trusted request scheme is HTTPS. Direct TLS is detected
without forwarding headers. `X-Forwarded-Proto` and `X-Forwarded-For` are used
only when the immediate peer matches an explicitly configured IP or CIDR. The
negative suite confirms token shape, cookie attributes, fixation resistance,
rotation, current-session logout, idle expiry, and rejection of forwarding
headers from an untrusted peer.

### SEC-006 — Authorization is distributed and incomplete as a system

**Severity:** High

**Status:** Closed in Slice 6

Role, forum-scope, and ownership decisions now live in shared predicates in
`functions.php`. Page scripts still load their own legacy records, but pass the
loaded object—not a caller-supplied owner or forum ID—to that policy boundary.
Denied object operations return HTTP 403. Administration pages use the same
exact administrator predicate, and every administration surface rejects
members and both moderator classes.

The enforced operation matrix is:

| Operation | Anonymous | Member | Forum moderator | Global moderator | Administrator |
| --- | --- | --- | --- | --- | --- |
| Read public forum | Yes | Yes | Yes | Yes | Yes |
| Post to public forum | Only where anonymous posting is configured | Where members may post | Where members may post | Where members may post | Yes |
| Read private forum | No | Explicit grant | Assigned forum | All forums | All forums |
| Post to private forum | No | Explicit posting grant | Assigned forum | All forums | All forums |
| Edit/delete a post | No | Own post, subject to delete window | Own or assigned forum | All forums | All forums |
| Moderate topic/view poster IP | No | No | Assigned forum | All forums | All forums |
| Move topic between forums | No | No | Must moderate source and destination | Yes | Yes |
| Read/reply/delete private message | No | Recipient only | Recipient only | Recipient only | Recipient only |
| Edit profile/preferences | No | Self only | Self only | Self only | Self; separate admin user tools for others |
| Administration | No | No | No | No | Yes |

Private forums are omitted from the index for unauthorized viewers. Their
topic bodies and posting routes independently enforce access, while assigned
forum moderators and global staff retain the intended oversight. PM reply and
delete routes authorize the recipient before rendering source or confirmation
data. Profile saves require the active session to own the target user ID.
Post editing no longer exposes source to anonymous visitors or accepts inline
credentials as a substitute for object ownership.

The expanded black-box suite provisions private and moderator-only forums and
tests anonymous, member, assigned moderator, global moderator, and
administrator behavior. It covers cross-user post/profile/PM attempts,
private-forum reads and writes, all administration entry points, legitimate
scoped moderation, cross-forum moves, and forged forum IDs on topic moderation.
Fresh-install smoke and full security suites pass.

### SEC-007 — The installer is reusable and handles secrets unsafely

**Severity:** High

**Status:** Closed in Slice 7

The historical web flow remains, but database credentials now stay in a
short-lived server-side installation session after the first form. They are
not emitted in later hidden fields or database error responses. Configuration
values are serialized safely rather than interpolated into PHP source.

Successful setup writes a permanent installation marker, flushes the file,
changes it to mode `0400`, destroys the installation session, and causes every
later installer GET or POST to return HTTP 403 before processing input. The
container initially exposes only that configuration file to its web-service
owner at mode `0600`; a dedicated `phpbb-config` volume preserves the locked
result across container replacement. Compose credentials remain explicit
local-development defaults, with production overrides and bootstrap/backup
requirements documented in `README.md`.

### SEC-008 — Error handling can disclose internals

**Severity:** Medium

**Status:** Closed in Slice 7

The database adapter now retains only a stable public failure message and logs
a structured event containing the operation stage, numeric driver code, and
sanitized SQL state. It never logs SQL values, credentials, passwords, session
identifiers, reset tokens, or CSRF tokens. Browser paths that formerly appended
SQL text or installer credentials now use generic operation-specific messages.
PHP display errors remain disabled while server-side error logging remains on.

### SEC-009 — Baseline browser security headers are absent

**Severity:** Medium

**Status:** Closed in Slice 7

All PHP entry points now emit an enforced CSP, `nosniff`, framing denial, a
strict-origin referrer policy, and a minimal permissions policy. The CSP denies
scripts, objects, framing, foreign form targets, and foreign base URLs. It
retains inline style attributes and HTTP(S)/data images because the historical
markup and BBCode image feature require them. HSTS is deliberately opt-in and
is emitted only when a trusted request is HTTPS. Apache hides version details,
disables indexes, caps request bodies, and denies direct HTTP access to
configuration/bootstrap includes.

### SEC-010 — Validation and URL handling are inconsistent

**Severity:** Medium

**Status:** Closed in Slice 7

Email addresses, websites, languages, theme paths/colors, IP addresses,
pagination, and several administrative numeric ranges still lack consistent
allowlists and length/range checks. Slice 3 restricts active web URLs to HTTP
and HTTPS, validates active email links, safely encodes URL attributes, and
removes obsolete ICQ/AIM/Yahoo active resources.

Slice 7 added field-specific length, range, enum, email, HTTP(S) URL, language,
IP-address, pagination, forum-access, role, theme-colour/font/dimension, and
local-asset checks at their mutation boundaries. Theme and rank images must be
ordinary image files below `images/`, with traversal and active schemes
rejected. Stored themes are validated again when loaded and fail to a safe
palette. Contextual output encoding remains in place as defense in depth.

### SEC-011 — Authentication abuse controls are absent

**Severity:** Medium

**Status:** Closed in Slice 7

Password reset responses remain uniform and password creation enforces a
12-to-255-byte boundary. Login and password-reset requests now share a
configurable sliding-window control keyed by SHA-256 digests of normalized
account and trusted client-network values. Defaults allow five failures per
account and thirty per network in fifteen minutes, return HTTP 429 with
`Retry-After`, delete expired rows, and cap the table at 10,000 entries.
Structured audit events contain only truncated key digests and outcomes.

## Residual risk review

No known-open condition remains in the repository's executable security
characterization, but that is not a certification. Important residual risks
are operational or inherent in retaining this codebase:

- the application is a compact legacy architecture without framework-level
  routing, dependency isolation, second-factor authentication, or account
  email verification;
- the CSP must permit inline styles and remote HTTP(S) BBCode images, so remote
  image hosts can observe reader network metadata and mixed-content policy is
  left to an HTTPS edge/browser;
- database-backed throttling is per installation and is not a substitute for
  edge rate limits, bot controls, or distributed abuse detection;
- the built-in mail path depends on external transport configuration and its
  delivery, reputation, and monitoring controls;
- container, proxy, database, host, backup, and dependency patching remain
  operator responsibilities; and
- only clean installations are supported. There is no migration or legacy-data
  compatibility promise.

## Existing automated evidence

The security characterization suite currently verifies these blocked cases:

- SQL-injection-shaped login input does not authenticate;
- administration login failures do not reflect supplied account input;
- cross-user post edit and delete attempts fail;
- a member cannot quote another recipient's private message;
- a forged PM HTML option cannot store executable markup;
- raw HTML in posts, profiles, signatures, and site-wide administrator text is
  encoded while BBCode and smilies remain functional;
- unsafe BBCode image/link and profile website schemes do not become active;
- arbitrary search sort expressions are rejected;
- a member cannot enter administration pages;
- anonymous users cannot retrieve post-edit source;
- profile updates require current-session ownership;
- PM reply and deletion require recipient ownership;
- private-forum reads and posts require the appropriate grant;
- forum moderators cannot act outside their assigned forum or move topics to
  a forum they do not moderate;
- global moderators can moderate all forums without entering administration;
- every administration entry point requires the administrator role;
- missing CSRF tokens are rejected and former GET mutations are read-only;
- passwords use the shared modern hashing and verification path;
- password-reset tokens are expiring, one-time, non-destructive on GET, and
  revoke existing sessions when consumed;
- sessions resist fixation, rotate on credential authentication, expire when
  idle, isolate per-browser logout, and carry modern cookie attributes;
- successful installation permanently locks GET and POST, makes configuration
  read-only, and survives web-container replacement;
- central security headers, server-version suppression, direct internal-file
  denial, and request-size limits are active;
- invalid language choices, unsafe profile URLs, and traversing theme assets
  are rejected; and
- repeated login and password-reset requests receive HTTP 429 with bounded
  per-account and per-network state.

The suite now reports zero known-open findings. It remains regression evidence,
not a security certification; the residual risks above and deployment controls
remain relevant.

## Remediation order

1. Parameterized database access and complete query conversion.
2. Contextual encoding, removal of raw HTML, and constrained BBCode rendering.
3. Password, reset-token, session, cookie, HTTPS, and proxy hardening.
4. CSRF tokens and POST-only mutation endpoints.
5. Central authorization rules and complete role/ownership test coverage.
6. Installer lock, safe errors, security headers, validation, and abuse
   controls.
7. Final clean-install verification and residual-risk review.

Each numbered slice receives its own commit and must preserve the smoke suite.
