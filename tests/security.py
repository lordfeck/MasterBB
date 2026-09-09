#!/usr/bin/env python3
"""Security characterization checks for a disposable, smoke-populated board."""

from __future__ import annotations

import argparse
import re
import sys

from smoke import (
    ADMIN_NAME,
    ADMIN_PASSWORD,
    USER_NAME,
    USER_PASSWORD,
    Browser,
    SmokeFailure,
    login,
    reject,
    require,
)


ATTACKER_NAME = "Smoke O'Brien"
ATTACKER_PASSWORD = "smoke-attacker-pass"


def secure(step: str) -> None:
    print(f"ok - blocked: {step}", flush=True)


def known_vulnerability(step: str) -> None:
    print(f"known vulnerable - {step}", flush=True)


def characterize(base_url: str) -> None:
    known_findings = 0
    anonymous = Browser(base_url)
    user = Browser(base_url)
    attacker = Browser(base_url)
    admin = Browser(base_url)

    page = login(anonymous, "SmokeAdmin' OR 1=1 -- ", "not-the-password")
    reject(page, f"Logged in as {ADMIN_NAME}", "SQL-injection login attempt")
    secure("SQL-injection-shaped login is rejected")

    reflected_marker = '<script id="masterbb-reflected-xss">alert(1)</script>'
    page = Browser(base_url).request(
        "admin/index.php",
        {
            "login": "Submit",
            "username": reflected_marker,
            "password": "not-the-password",
        },
    )
    reject(page, reflected_marker, "administration login HTML escaping")
    require(
        page,
        "&lt;script id=&quot;masterbb-reflected-xss&quot;&gt;",
        "administration login HTML escaping",
    )
    secure("administration login errors encode reflected input")

    page = attacker.request(
        "bb_register.php",
        {
            "submit": "Submit",
            "username": ATTACKER_NAME,
            "password": ATTACKER_PASSWORD,
            "password_rep": ATTACKER_PASSWORD,
            "email": "attacker@example.test",
            "website": "http://",
        },
    )
    require(page, "You have been added to the database", "attacker fixture")
    page = login(attacker, ATTACKER_NAME, ATTACKER_PASSWORD)
    require(page, "Logged in as Smoke O&#039;Brien", "attacker login")
    secure("an apostrophe-containing username registers and authenticates intact")

    session_cookies = [
        cookie for cookie in attacker.cookies if cookie.name == "phpBBsession"
    ]
    if len(session_cookies) != 1:
        raise SmokeFailure("session characterization: expected one session cookie")
    session_cookie = session_cookies[0]
    if not session_cookie.value.isdigit():
        raise SmokeFailure("session characterization: expected a legacy numeric token")
    cookie_attributes = {key.lower() for key in session_cookie._rest}
    if "httponly" in cookie_attributes or "samesite" in cookie_attributes:
        raise SmokeFailure(
            "session characterization: expected missing HttpOnly and SameSite attributes"
        )
    known_vulnerability(
        "session identifiers are small numeric values and the cookie lacks HttpOnly/SameSite"
    )
    known_findings += 1

    page = attacker.request("admin/admin_board.php?mode=setoptions")
    require(page, "do not have acess to this area", "member administration boundary")
    secure("a regular user cannot enter the administration pages")

    page = attacker.request(
        "editpost.php",
        {
            "submit": "Submit",
            "post_id": "1",
            "forum": "1",
            "username": ATTACKER_NAME,
            "subject": "Cross-user edit attempt",
            "message": "Cross-user overwrite attempt",
        },
    )
    reject(page, "Your Message has been stored", "cross-user edit response")
    page = user.request("viewtopic.php?topic=1&forum=1")
    require(page, "Edited smoke body", "cross-user edit preservation")
    reject(page, "Cross-user overwrite attempt", "cross-user edit preservation")
    secure("a regular user cannot edit another user's post")

    page = attacker.request(
        "editpost.php",
        {
            "submit": "Submit",
            "delete": "on",
            "post_id": "1",
            "forum": "1",
            "username": ATTACKER_NAME,
            "subject": "Cross-user delete attempt",
            "message": "Cross-user delete attempt",
        },
    )
    reject(page, "Your Message has been stored", "cross-user delete response")
    page = user.request("viewtopic.php?topic=1&forum=1")
    require(page, "Edited smoke body", "cross-user delete preservation")
    secure("a regular user cannot delete another user's post")

    page = attacker.request("replypmsg.php?msgid=1&quote=1")
    require(page, "wasn't sent to you", "private-message ownership")
    reject(page, "Smoke private message", "private-message disclosure")
    secure("a user cannot quote or read another user's private message")

    marker = '<script id="masterbb-xss">alert(1)</script>'
    page = attacker.request(
        "sendpmsg.php",
        {
            "submit": "Submit",
            "tousername": ADMIN_NAME,
            "message": marker,
            # Historically this unexpected field bypassed the HTML-disabled setting.
            "html": "1",
        },
    )
    require(page, "Your Message has been stored", "XSS fixture send")
    page = login(admin, ADMIN_NAME, ADMIN_PASSWORD, admin=True)
    require(page, "phpBB Forum Administration", "security administrator login")
    page = admin.request("viewpmsg.php")
    reject(page, marker, "private-message HTML escaping")
    require(page, "&lt;script id=&quot;masterbb-xss&quot;&gt;", "private-message HTML escaping")
    secure("forged HTML option cannot enable stored script markup in private messages")

    post_xss_marker = '<script id="masterbb-post-xss">alert(1)</script>'
    bbcode_body = (
        post_xss_marker
        + "\n[b]BBCode survives[/b]"
        + "\n[url=https://example.test/path?a=1&b=2]safe link[/url]"
        + "\n[url=javascript:alert(1)]unsafe link[/url]"
        + "\n[img]data:text/html,unsafe[/img]"
        + "\n:)"
    )
    page = attacker.request(
        "reply.php",
        {
            "submit": "Submit",
            "forum": "1",
            "topic": "1",
            "message": bbcode_body,
            # Raw HTML remains disabled even when an old client forges this field.
            "html": "1",
        },
    )
    require(page, "Your Message has been stored", "post rendering fixture")
    page = attacker.request("viewtopic.php?topic=1&forum=1")
    reject(page, post_xss_marker, "post HTML escaping")
    require(page, "&lt;script id=&quot;masterbb-post-xss&quot;&gt;", "post HTML escaping")
    require(page, "<B>BBCode survives</B>", "BBCode preservation")
    require(
        page,
        'HREF="https://example.test/path?a=1&amp;b=2"',
        "safe BBCode URL rendering",
    )
    reject(page, 'HREF="javascript:', "unsafe BBCode URL rendering")
    reject(page, 'SRC="data:', "unsafe BBCode image rendering")
    require(page, 'ALT="smilie"', "smilie preservation")
    secure("raw post HTML is encoded while constrained BBCode and smilies remain active")

    for path, step in (
        ("editpost.php?post_id=3&topic=1&forum=1", "post edit form escaping"),
        ("reply.php?topic=1&forum=1&post=3&quote=1", "quoted reply escaping"),
    ):
        page = attacker.request(path)
        reject(page, post_xss_marker, step)
        require(page, "&lt;script id=&quot;masterbb-post-xss&quot;&gt;", step)
    secure("post source remains inert in edit and quoted-reply forms")

    profile_form = attacker.request("bb_profile.php?mode=edit")
    user_id_match = re.search(r'NAME="user_id" VALUE="([0-9]+)"', profile_form)
    if user_id_match is None:
        raise SmokeFailure("profile XSS fixture: could not identify attacker user")
    attacker_user_id = user_id_match.group(1)
    profile_marker = '<script id="masterbb-profile-xss">alert(1)</script>'
    page = attacker.request(
        "bb_profile.php",
        {
            "submit": "Submit",
            "mode": "edit",
            "save": "1",
            "user_id": attacker_user_id,
            "password": ATTACKER_PASSWORD,
            "email": "attacker@example.test",
            "website": "javascript:alert(1)",
            "from": profile_marker,
            "occ": '\" onmouseover=\"alert(1)',
            "intrest": "Arcane boards & secure code",
            "sig": "[b]Safe signature[/b] " + profile_marker,
        },
    )
    require(page, "Your Information has been updated", "profile XSS fixture")
    page = attacker.request(f"bb_profile.php?mode=view&user={attacker_user_id}")
    reject(page, profile_marker, "profile HTML escaping")
    require(
        page,
        "&lt;script id=&quot;masterbb-profile-xss&quot;&gt;",
        "profile HTML escaping",
    )
    require(page, '&quot; onmouseover=&quot;alert(1)', "profile attribute escaping")
    require(page, '<a href="#" target="_blank"', "unsafe profile URL handling")
    secure("profile text and attributes are encoded and unsafe website schemes are inert")

    page = attacker.request(
        "reply.php",
        {
            "submit": "Submit",
            "forum": "1",
            "topic": "1",
            "message": "Signature rendering check",
            "sig": "1",
        },
    )
    require(page, "Your Message has been stored", "signature rendering fixture")
    page = attacker.request("viewtopic.php?topic=1&forum=1")
    require(page, "<B>Safe signature</B>", "signature BBCode preservation")
    reject(page, profile_marker, "signature HTML escaping")
    secure("signatures use the same constrained renderer as posts")

    hmf_marker = '<script id="masterbb-hmf-xss">alert(1)</script>'
    page = admin.request(
        "admin/admin_board.php",
        {
            "submit": "Save Text",
            "mode": "headermetafooter",
            "header": hmf_marker,
            "metacode": '\" onload=\"alert(1)',
            "footer": hmf_marker,
        },
    )
    require(page, "Data Added", "header/meta/footer fixture")
    page = anonymous.request("index.php")
    reject(page, hmf_marker, "header/footer HTML escaping")
    require(
        page,
        "&lt;script id=&quot;masterbb-hmf-xss&quot;&gt;",
        "header/footer HTML escaping",
    )
    require(
        page,
        'CONTENT="&quot; onload=&quot;alert(1)"',
        "meta-description attribute escaping",
    )
    secure("administrator header, meta, and footer values are display text only")

    page = attacker.request(
        "search.php",
        {
            "submit": "Search",
            "term": "Needle",
            "addterms": "any",
            "forum": "all",
            "sortby": "not_a_column, (SELECT user_password FROM users LIMIT 1)",
            "searchboth": "both",
        },
    )
    require(page, "Smoke Topic Needle Edited", "search sort allowlist")
    reject(page, "unable to query", "search sort allowlist")
    secure("search rejects arbitrary SQL sort expressions")

    csrf_marker = "CSRF characterization reply"
    page = attacker.request(
        "reply.php",
        {
            "submit": "Submit",
            "forum": "1",
            "topic": "1",
            "message": csrf_marker,
        },
    )
    require(page, "Your Message has been stored", "CSRF characterization")
    page = attacker.request("viewtopic.php?topic=1&forum=1")
    require(page, csrf_marker, "CSRF characterization persistence")
    known_vulnerability("state-changing POSTs still have no CSRF token")
    known_findings += 1

    page = anonymous.request("install.php")
    require(page, "not writeable by the web server", "installer availability characterization")
    known_vulnerability(
        "the installer endpoint remains reachable and relies on config.php permissions"
    )
    known_findings += 1

    if anonymous.last_headers is None:
        raise SmokeFailure("security-header characterization: no response headers")
    expected_headers = (
        "Content-Security-Policy",
        "X-Content-Type-Options",
        "Referrer-Policy",
        "X-Frame-Options",
    )
    present_headers = {
        name.lower() for name in anonymous.last_headers.keys()
    }
    if any(name.lower() in present_headers for name in expected_headers):
        raise SmokeFailure(
            "security-header characterization: baseline unexpectedly changed"
        )
    known_vulnerability("baseline browser security headers are absent")
    known_findings += 1

    print(
        f"Security characterization completed with {known_findings} known open findings.",
        flush=True,
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        characterize(args.base_url)
    except SmokeFailure as error:
        print(f"not ok - {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
