# phpBB 1.4.4 modernisation audit

## Executive summary

Yes: this codebase can run on modern PHP and MariaDB while retaining its
simple, period presentation and operating model. The first compatibility
milestone is now implemented and verified on PHP 8.4 and MariaDB 11.4.

Security hardening is also feasible without redesigning the visible product,
but it is a larger and more delicate body of work than the runtime port. The
application currently must be treated as local-only and unsafe for exposure to
untrusted users.

SQLite is no longer in scope. Only fresh MariaDB installations are supported;
the historical database upgrade scripts have been removed.

## Compatibility milestone: complete

The port now:

- uses the official PHP 8.4 Apache image and MariaDB 11.4;
- replaces the removed `mysql_*` extension with a small PDO adapter;
- normalises PHP opening tags so `short_open_tag` can remain disabled;
- replaces removed PHP APIs including `each()`, `split()`, `ereg()`, and
  `eregi()`;
- supplies a contained compatibility boundary for the long request arrays,
  server globals, and bare array keys expected by PHP 3/4-era code;
- replaces `register_globals` emulation with named, typed GET/POST access in
  each application entry point;
- removes obsolete migration scripts under the agreed fresh-install-only
  policy; and
- adjusts fresh-install schema and insert behaviour for MariaDB's modern
  strict defaults.

This deliberately is not yet a wholesale rewrite. The small database and
request compatibility layers keep the behavioural change reviewable while the
smoke suite protects the original workflows.

## Verification baseline

The black-box suite creates an isolated application and database, performs a
fresh web installation, and verifies:

1. administrator creation and board configuration;
2. administrator login, logout, and re-login;
3. category and forum creation;
4. user registration, login, and logout;
5. new topic and reply creation;
6. post editing and deletion;
7. private-message sending and reading; and
8. search.

The complete suite passes on PHP 8.4 and MariaDB 11.4. All PHP source files
also pass PHP 8.4 syntax checks, and the successful run produced no PHP fatal
errors, parse errors, or deprecation diagnostics.

The covered run produces no deprecations, fatal errors, or parse errors. A
later explicit log audit identified legacy undefined-variable warnings in
unselected form options; these do not break the covered workflows and remain
tracked for the final error-handling cleanup. The long-array aliases remain
temporarily for cookie code, but request keys are no longer copied into
arbitrary globals.

The negative characterization suite also passes for SQL-injection-shaped login
input, arbitrary search sort expressions, cross-user post edit/delete attempts,
unauthorized private-message access, and forged private-message HTML options.
It explicitly reports the still-open CSRF finding rather than treating the
application as hardened.

## Security findings

The following are the main classes of risk to address before public use. This
list is architectural triage, not a claim that every exploitable path has
already been enumerated.

### Critical

- SQL is assembled by interpolating request and stored values. Moving to PDO
  removed an obsolete driver; it did not remove SQL injection.
- Passwords use unsalted MD5 and must move to `password_hash()` and
  `password_verify()`.
- State-changing actions lack modern CSRF protection.

### High

- Output encoding is inconsistent, leaving stored and reflected XSS risk in
  posts, profiles, messages, search results, and administration pages.
- Session identifiers and session handling predate modern cryptographic and
  cookie-security requirements.
- The installer can rewrite configuration and should be made unavailable after
  installation by construction, not merely by convention.
- Authorization checks are distributed through page scripts and should be
  audited operation by operation, especially editing, deletion, private
  forums, private messages, and administration actions.

### Medium

- Input validation is inconsistent and tied to a historical filtering script.
- Error paths can disclose query text and internal database details.
- Email, URL, BBCode, and HTML handling rely on obsolete trust assumptions.
- Security headers, request-size limits, rate limits, and login throttling are
  absent.

## Recommended next phase

Preserve the existing HTML, navigation, URLs, and workflows while changing the
trust boundaries underneath them:

1. Extend the database adapter with prepared statements, then convert all
   queries reached by the smoke suite before covering the remaining admin and
   profile paths.
2. Introduce output-escaping helpers with explicit exceptions for the narrow
   legacy markup/BBCode surface.
3. Replace authentication and session primitives, including password hashing,
   cryptographically random session tokens, rotation, expiry, and secure cookie
   attributes.
4. Add CSRF tokens to every state-changing form and enforce POST-only mutation
   endpoints.
5. Continue centralising authorization checks and expand the negative tests to
   every cross-user and lower-privilege operation.
6. Disable the installer after successful setup, reduce error disclosure, and
   add baseline response headers and abuse controls.

Each step can be made behind the original presentation. The smoke suite should
remain the behavioural baseline, with focused negative security tests added
alongside each hardening change.

## Security hardening progress

The PHP 8 compatibility milestone is preserved at `PortToPhp8Complete`. The
detailed evidence and endpoint inventory now live in
[`SECURITY_AUDIT.md`](SECURITY_AUDIT.md). The agreed product and deployment
decisions are:

- remove raw user-authored HTML while retaining BBCode and smilies;
- preserve the web installer, but lock it permanently after successful setup;
- target HTTPS deployment using explicit trusted-proxy configuration; and
- preserve the 2001 presentation while modernising every trust boundary.

Progress is tracked as focused, independently verified commits:

- [x] Slice 1 — complete security inventory and executable characterization
  baseline (`SECURITY_AUDIT.md`; five known-open conditions reproduced).
- [x] Slice 2 — native prepared-statement API and complete value-bearing query
  conversion; legacy SQL escaping/filtering removed; smoke and security suites
  passing.
- [ ] Slice 3 — contextual output encoding, safe URLs, and BBCode-only content.
- [ ] Slice 4 — password hashing, reset tokens, sessions, cookies, HTTPS, and
  trusted proxies.
- [ ] Slice 5 — CSRF tokens and POST-only state changes.
- [ ] Slice 6 — centralized authorization and full role/ownership coverage.
- [ ] Slice 7 — installer lock, safe errors, headers, validation, throttling,
  deployment guidance, and final residual-risk review.

## Conclusion

The runtime upgrade is reasonably straightforward and is now working for the
agreed fresh-install workflows. Hardening is practical without sacrificing the
arcane look and feel, but it should be treated as a systematic second phase,
not as a handful of search-and-replace patches.
