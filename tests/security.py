#!/usr/bin/env python3
"""Security characterization checks for a disposable, smoke-populated board."""

from __future__ import annotations

import argparse
import re
import sys
import time

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


def characterize(base_url: str, idle_timeout_test_seconds: int) -> None:
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
    reject(page, "masterbb-reflected-xss", "administration login disclosure")
    secure("administration login failures do not reflect account input")

    page = Browser(base_url).request(
        "bb_register.php",
        {
            "submit": "Submit",
            "username": "ShortPasswordUser",
            "password": "too-short",
            "password_rep": "too-short",
            "email": "short-password@example.test",
            "website": "http://",
        },
    )
    require(page, "at least 12 characters", "registration password policy")
    secure("new account passwords enforce the modern length boundary")

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
    pre_login_csrf = attacker.cookie("phpBBcsrf")
    if pre_login_csrf is None or re.fullmatch(r"[a-f0-9]{64}", pre_login_csrf.value) is None:
        raise SmokeFailure("CSRF security: expected a 256-bit hexadecimal token")
    page = login(attacker, ATTACKER_NAME, ATTACKER_PASSWORD)
    require(page, "Logged in as Smoke O&#039;Brien", "attacker login")
    secure("an apostrophe-containing username registers and authenticates intact")

    session_cookies = [
        cookie for cookie in attacker.cookies if cookie.name == "phpBBsession"
    ]
    if len(session_cookies) != 1:
        raise SmokeFailure("session characterization: expected one session cookie")
    session_cookie = session_cookies[0]
    if re.fullmatch(r"[a-f0-9]{64}", session_cookie.value) is None:
        raise SmokeFailure("session security: expected a 256-bit hexadecimal token")
    cookie_attributes = {key.lower() for key in session_cookie._rest}
    if "httponly" not in cookie_attributes or "samesite" not in cookie_attributes:
        raise SmokeFailure(
            "session security: expected HttpOnly and SameSite attributes"
        )
    if session_cookie.secure:
        raise SmokeFailure("session security: HTTP baseline unexpectedly set Secure")
    csrf_cookie = attacker.cookie("phpBBcsrf")
    if csrf_cookie is None or csrf_cookie.value == pre_login_csrf.value:
        raise SmokeFailure("CSRF security: authentication did not rotate the token")
    csrf_attributes = {key.lower() for key in csrf_cookie._rest}
    if "httponly" not in csrf_attributes or "samesite" not in csrf_attributes:
        raise SmokeFailure("CSRF security: expected HttpOnly and SameSite attributes")
    initial_session_token = session_cookie.value
    secure("sessions and CSRF tokens are random, scoped cookies rotated at authentication")

    fixed = Browser(base_url)
    fixed.set_cookie("phpBBsession", "a" * 64)
    page = login(fixed, ATTACKER_NAME, ATTACKER_PASSWORD)
    require(page, f"Logged in as Smoke O&#039;Brien", "session fixation login")
    fixed_cookie = fixed.cookie("phpBBsession")
    if fixed_cookie is None or fixed_cookie.value == "a" * 64:
        raise SmokeFailure("session fixation: authentication did not rotate the token")
    secure("successful authentication replaces a caller-supplied session token")

    invalid = Browser(base_url)
    invalid.set_cookie("phpBBsession", "b" * 64)
    page = invalid.request("index.php")
    reject(page, "Logged in as", "invalid session rejection")
    secure("unknown session tokens do not authenticate")

    spoofed_https = Browser(base_url)
    spoofed_https.request(
        "login.php",
        {"submit": "Submit", "user": ATTACKER_NAME, "passwd": ATTACKER_PASSWORD},
        {"X-Forwarded-Proto": "https"},
    )
    spoofed_cookie = spoofed_https.cookie("phpBBsession")
    if spoofed_cookie is None or spoofed_cookie.secure:
        raise SmokeFailure("trusted-proxy boundary: untrusted forwarded scheme was accepted")
    secure("untrusted X-Forwarded-Proto values cannot force proxy scheme detection")

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

    require(login(user, USER_NAME, USER_PASSWORD), f"Logged in as {USER_NAME}", "authorization member login")
    page = user.request(
        "prefs.php",
        {
            "submit": "Save Preferences",
            "save": "1",
            "themes": "1",
            "viewemail": "0",
            "savecookie": "0",
            "sig": "0",
            "smile": "0",
            "dishtml": "0",
            "disbbcode": "0",
            "lang": "../../config",
        },
    )
    require(page, "preference values are invalid", "language allowlist")
    secure("language and preference choices use explicit allowlists")
    member_profile = user.request("bb_profile.php?mode=edit")
    member_id_match = re.search(r'NAME="user_id" VALUE="([0-9]+)"', member_profile)
    if member_id_match is None:
        raise SmokeFailure("authorization fixture: could not identify member user")
    member_user_id = member_id_match.group(1)

    page = admin.request(
        "admin/admin_forums.php",
        {
            "mode": "addforum",
            "submit": "Create Forum",
            "name": "Authorization Private Forum",
            "desc": "Private authorization fixture",
            "mods[]": ["1"],
            "cat": "1",
            "forum_access": "1",
            "type": "1",
        },
    )
    require(page, "Forum Created", "private-forum authorization fixture")
    page = admin.request(
        "admin/admin_forums.php",
        {
            "mode": "addforum",
            "submit": "Create Forum",
            "name": "Authorization Moderator Forum",
            "desc": "Scoped moderator fixture",
            "mods[]": [attacker_user_id],
            "cat": "1",
            "forum_access": "3",
            "type": "0",
        },
    )
    require(page, "Forum Created", "moderator authorization fixture")
    forum_select = admin.request("admin/admin_forums.php?mode=editforum")
    private_match = re.search(
        r'<OPTION VALUE="([0-9]+)">Authorization Private Forum</OPTION>',
        forum_select,
    )
    moderator_match = re.search(
        r'<OPTION VALUE="([0-9]+)">Authorization Moderator Forum</OPTION>',
        forum_select,
    )
    if private_match is None or moderator_match is None:
        raise SmokeFailure("authorization fixture: forum ids not found")
    private_forum_id = private_match.group(1)
    moderator_forum_id = moderator_match.group(1)

    page = admin.request(
        "admin/admin_priv_forums.php",
        {
            "forum": private_forum_id,
            "op": "adduser",
            "userids[]": [member_user_id],
            "submit": "Add Users -->",
        },
    )
    require(page, USER_NAME, "private-forum member grant")
    admin.request(
        "admin/admin_priv_forums.php",
        {"forum": private_forum_id, "op": f"grantuserpost:{member_user_id}"},
    )

    private_body = "Private authorization body"
    page = user.request(
        "newtopic.php",
        {
            "submit": "Submit",
            "forum": private_forum_id,
            "subject": "Private Authorization Topic",
            "message": private_body,
        },
    )
    require(page, "Your Message has been stored", "authorized private-forum post")
    page = user.request(f"viewforum.php?forum={private_forum_id}")
    private_topic_match = re.search(
        rf'viewtopic\.php\?topic=([0-9]+)&forum={private_forum_id}[^>]*>Private Authorization Topic',
        page,
    )
    if private_topic_match is None:
        raise SmokeFailure("authorization fixture: private topic id not found")
    private_topic_id = private_topic_match.group(1)

    anonymous_edit = Browser(base_url)
    page = anonymous_edit.request("editpost.php?post_id=1&topic=1&forum=1")
    if anonymous_edit.last_status != 403:
        raise SmokeFailure("anonymous post edit: expected HTTP 403")
    reject(page, "Edited smoke body", "anonymous post-source disclosure")

    page = attacker.request("index.php")
    reject(page, "Authorization Private Forum", "private-forum index visibility")
    private_anonymous = Browser(base_url)
    page = private_anonymous.request(
        f"viewtopic.php?topic={private_topic_id}&forum={private_forum_id}"
    )
    require(page, "Private Forum", "anonymous private-forum login boundary")
    reject(page, private_body, "anonymous private-forum body disclosure")
    page = attacker.request(
        f"viewtopic.php?topic={private_topic_id}&forum={private_forum_id}"
    )
    if attacker.last_status != 403:
        raise SmokeFailure("private-forum read: expected HTTP 403")
    reject(page, private_body, "private-forum body disclosure")
    page = attacker.request(
        "reply.php",
        {
            "submit": "Submit",
            "forum": private_forum_id,
            "topic": private_topic_id,
            "message": "Unauthorized private reply",
        },
    )
    if attacker.last_status != 403:
        raise SmokeFailure("private-forum post: expected HTTP 403")
    page = user.request(f"viewtopic.php?topic={private_topic_id}&forum={private_forum_id}")
    reject(page, "Unauthorized private reply", "private-forum post persistence")
    require(page, private_body, "authorized private-forum read")
    page = admin.request(f"viewtopic.php?topic={private_topic_id}&forum={private_forum_id}")
    require(page, private_body, "administrator private-forum read")
    secure("private forums enforce read and post grants while administrators retain oversight")

    page = attacker.request(
        "newtopic.php",
        {
            "submit": "Submit",
            "forum": moderator_forum_id,
            "subject": "Scoped Moderator Topic",
            "message": "Scoped moderator body",
        },
    )
    require(page, "Your Message has been stored", "scoped moderator post")
    page = attacker.request(f"viewforum.php?forum={moderator_forum_id}")
    moderator_topic_match = re.search(
        rf'viewtopic\.php\?topic=([0-9]+)&forum={moderator_forum_id}[^>]*>Scoped Moderator Topic',
        page,
    )
    if moderator_topic_match is None:
        raise SmokeFailure("authorization fixture: moderator topic id not found")
    moderator_topic_id = moderator_topic_match.group(1)

    page = attacker.request(
        "topicadmin.php",
        {
            "submit": "Lock Topic",
            "mode": "lock",
            "topic": "1",
            "forum": moderator_forum_id,
        },
    )
    if attacker.last_status != 403:
        raise SmokeFailure("cross-forum moderation: expected HTTP 403")
    page = attacker.request(
        "reply.php",
        {
            "submit": "Submit",
            "forum": "1",
            "topic": "1",
            "message": "Cross-forum spoof did not lock this topic",
        },
    )
    require(page, "Your Message has been stored", "cross-forum moderation preservation")
    page = attacker.request(
        "topicadmin.php",
        {
            "submit": "Lock Topic",
            "mode": "lock",
            "topic": moderator_topic_id,
            "forum": moderator_forum_id,
        },
    )
    require(page, "topic has been locked", "forum moderator own-scope moderation")
    page = attacker.request(
        "topicadmin.php",
        {
            "submit": "Move Topic",
            "mode": "move",
            "topic": moderator_topic_id,
            "forum": moderator_forum_id,
            "newforum": "1",
        },
    )
    if attacker.last_status != 403:
        raise SmokeFailure("cross-forum topic move: expected HTTP 403")
    page = attacker.request(f"viewforum.php?forum={moderator_forum_id}")
    require(page, "Scoped Moderator Topic", "cross-forum topic move preservation")
    secure("forum moderators are confined to the actual source and destination forums")

    page = attacker.request("delpmsg.php?msgid=1")
    if attacker.last_status != 403:
        raise SmokeFailure("cross-user private-message delete GET: expected HTTP 403")
    page = attacker.request("delpmsg.php", {"msgid": "1", "submit": "Delete"})
    if attacker.last_status != 403:
        raise SmokeFailure("cross-user private-message delete POST: expected HTTP 403")
    require(admin.request("viewpmsg.php"), "Smoke private message", "private-message delete preservation")
    secure("private-message reply and deletion require recipient ownership")

    page = attacker.request(
        "bb_profile.php",
        {
            "submit": "Submit",
            "mode": "edit",
            "save": "1",
            "user_id": member_user_id,
            "password": ATTACKER_PASSWORD,
            "email": "hijacked@example.test",
            "from": "Unauthorized profile overwrite",
            "website": "http://",
        },
    )
    if attacker.last_status != 403:
        raise SmokeFailure("cross-user profile edit: expected HTTP 403")
    page = user.request(f"bb_profile.php?mode=view&user={member_user_id}")
    reject(page, "Unauthorized profile overwrite", "cross-user profile preservation")
    secure("post source and profile mutations require authenticated object ownership")

    page = admin.request(
        "admin/admin_users.php",
        {
            "mode": "moduser",
            "submit": "Modify User",
            "edit_user_id": member_user_id,
            "edit_username": USER_NAME,
            "email": "user@example.test",
            "rank": "0",
            "level": "3",
        },
    )
    require(page, "User Information Updated", "global moderator fixture")
    page = user.request(
        "topicadmin.php",
        {
            "submit": "Lock Topic",
            "mode": "lock",
            "topic": "1",
            "forum": "1",
        },
    )
    require(page, "topic has been locked", "global moderator lock")
    page = user.request(
        "topicadmin.php",
        {
            "submit": "Unlock Topic",
            "mode": "unlock",
            "topic": "1",
            "forum": "1",
        },
    )
    require(page, "topic has been unlocked", "global moderator unlock")
    admin.request(
        "admin/admin_priv_forums.php",
        {"forum": private_forum_id, "op": f"deluser:{member_user_id}"},
    )
    page = user.request(f"viewtopic.php?topic={private_topic_id}&forum={private_forum_id}")
    require(page, private_body, "global moderator private-forum oversight")
    user.request("admin/index.php")
    if user.last_status != 403:
        raise SmokeFailure("global moderator administration boundary: expected HTTP 403")
    secure("global moderators can moderate all forums but do not gain administration access")

    admin_paths = (
        "admin/index.php",
        "admin/admin_board.php?mode=setoptions",
        "admin/admin_forums.php?mode=addforum",
        "admin/admin_users.php?mode=moduser",
        "admin/admin_priv_forums.php",
        "admin/admin_themes.php",
        "admin/smiles.php",
    )
    for path in admin_paths:
        attacker.request(path)
        if attacker.last_status != 403:
            raise SmokeFailure(f"administrator role boundary {path}: expected HTTP 403")
    secure("member and moderator roles cannot enter any administration surface")

    profile_marker = '<script id="masterbb-profile-xss">alert(1)</script>'
    profile_fields = {
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
    }
    page = attacker.request("bb_profile.php", profile_fields)
    require(page, "profile fields are invalid", "profile URL validation")
    profile_fields["website"] = "https://example.test/profile"
    page = attacker.request("bb_profile.php", profile_fields)
    require(page, "Your Information has been updated", "profile XSS fixture")
    rotated_cookie = attacker.cookie("phpBBsession")
    if rotated_cookie is None or rotated_cookie.value == initial_session_token:
        raise SmokeFailure("session rotation: profile re-authentication retained its token")
    stale = Browser(base_url)
    stale.set_cookie("phpBBsession", initial_session_token)
    page = stale.request("index.php")
    reject(page, f"Logged in as Smoke O&#039;Brien", "retired session token")
    secure("credential re-authentication rotates and retires the prior session token")
    page = attacker.request(f"bb_profile.php?mode=view&user={attacker_user_id}")
    reject(page, profile_marker, "profile HTML escaping")
    require(
        page,
        "&lt;script id=&quot;masterbb-profile-xss&quot;&gt;",
        "profile HTML escaping",
    )
    require(page, '&quot; onmouseover=&quot;alert(1)', "profile attribute escaping")
    require(page, '<a href="https://example.test/profile" target="_blank"', "safe profile URL handling")
    reject(page, "javascript:alert(1)", "rejected profile URL persistence")
    secure("profile text and attributes are encoded and unsafe website schemes are rejected")

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

    form_probe = Browser(base_url)
    page = form_probe.request("bb_register.php")
    csrf_cookie = form_probe.cookie("phpBBcsrf")
    require(page, 'NAME="_csrf"', "CSRF form injection")
    if csrf_cookie is None or csrf_cookie.value not in page:
        raise SmokeFailure("CSRF form injection: hidden token does not match cookie")
    secure("legacy POST forms receive hidden CSRF fields")

    csrf_marker = "CSRF rejection reply"
    page = attacker.request(
        "reply.php",
        {
            "submit": "Submit",
            "forum": "1",
            "topic": "1",
            "message": csrf_marker,
        },
        csrf=False,
    )
    require(page, "Invalid or missing form token", "CSRF rejection")
    if attacker.last_status != 403:
        raise SmokeFailure("CSRF rejection: expected HTTP 403")
    page = attacker.request("viewtopic.php?topic=1&forum=1")
    reject(page, csrf_marker, "CSRF rejection persistence")
    secure("state-changing POSTs reject missing CSRF tokens")

    installer_probe = Browser(base_url)
    page = installer_probe.request("install.php")
    require(page, "permanently locked", "installer lock")
    if installer_probe.last_status != 403:
        raise SmokeFailure("installer lock: expected HTTP 403")
    reject(page, "Database Server Address", "installer lock form disclosure")
    page = installer_probe.request("install.php", {"next": "database"}, csrf=False)
    require(page, "permanently locked", "installer POST lock")
    secure("successful installation permanently locks installer GET and POST")

    header_probe = Browser(base_url)
    header_probe.request("index.php")
    if header_probe.last_headers is None:
        raise SmokeFailure("security-header characterization: no response headers")
    expected_headers = (
        "Content-Security-Policy",
        "X-Content-Type-Options",
        "Referrer-Policy",
        "X-Frame-Options",
    )
    present_headers = {name.lower() for name in header_probe.last_headers.keys()}
    missing_headers = [name for name in expected_headers if name.lower() not in present_headers]
    if missing_headers:
        raise SmokeFailure(f"security headers missing: {', '.join(missing_headers)}")
    if header_probe.last_headers.get("X-Content-Type-Options") != "nosniff":
        raise SmokeFailure("security headers: expected nosniff")
    csp = header_probe.last_headers.get("Content-Security-Policy", "")
    if "frame-ancestors 'none'" not in csp or "object-src 'none'" not in csp:
        raise SmokeFailure("security headers: CSP lacks framing/object restrictions")
    server_header = header_probe.last_headers.get("Server", "")
    if "/" in server_header or header_probe.last_headers.get("X-Powered-By"):
        raise SmokeFailure("response headers disclose server or PHP versions")
    secure("baseline browser security headers are enforced centrally")

    internal_probe = Browser(base_url)
    internal_probe.request("config.php")
    if internal_probe.last_status != 403:
        raise SmokeFailure("internal-file boundary: config.php was directly reachable")
    secure("internal bootstrap and configuration files are denied by Apache")

    oversized = Browser(base_url)
    page = oversized.request(
        "bb_register.php",
        {"submit": "Submit", "username": "x" * 270000},
    )
    if oversized.last_status != 413:
        raise SmokeFailure("request-size boundary: expected HTTP 413")
    secure("oversized requests are rejected before application processing")

    safe_get_logout = Browser(base_url)
    require(login(safe_get_logout, ATTACKER_NAME, ATTACKER_PASSWORD), "Logged in as", "safe-method login")
    require(safe_get_logout.request("logout.php"), 'NAME="logout"', "logout confirmation GET")
    require(safe_get_logout.request("index.php"), "Logged in as Smoke O&#039;Brien", "logout GET session preservation")

    require(login(admin, ADMIN_NAME, ADMIN_PASSWORD, admin=True), "Administration", "safe-method administrator login")
    invalid_theme_name = "Invalid Traversal Theme"
    page = admin.request(
        "admin/admin_themes.php",
        {
            "mode": "add",
            "submit": "Save Theme",
            "theme_name": invalid_theme_name,
            "theme_bgcolor": "#000000",
            "theme_textcolor": "#FFFFFF",
            "theme_color1": "#111111",
            "theme_color2": "#222222",
            "theme_tablebg": "#333333",
            "theme_linkcolor": "#00FFFF",
            "theme_vlinkcolor": "#00AAAA",
            "theme_fontface": "sans-serif",
            "theme_fontsize1": "1",
            "theme_fontsize2": "2",
            "theme_fontsize3": "-2",
            "theme_fontsize4": "+1",
            "theme_tablewidth": "95%",
            "image_header": "../config.php",
            "image_newtopic": "new_topic.jpg",
            "image_reply": "reply.jpg",
            "image_replylocked": "reply_locked.jpg",
        },
    )
    require(page, "local files below the images directory", "theme path validation")
    page = admin.request("admin/admin_themes.php")
    reject(page, invalid_theme_name, "invalid theme persistence")
    secure("theme and rank assets are restricted to local image paths")
    page = admin.request("admin/admin_themes.php?mode=remove&theme_id=4")
    require(page, 'NAME="mode" VALUE="remove"', "theme deletion confirmation GET")
    page = admin.request("admin/admin_themes.php")
    require(page, "Midnight Terminal", "theme deletion GET preservation")
    page = admin.request("admin/smiles.php?mode=delete&id=1")
    require(page, 'value="Delete Smile"', "smilie deletion confirmation GET")
    page = admin.request("admin/admin_themes.php?mode=setdefault&theme_id=4")
    if admin.last_status != 405:
        raise SmokeFailure("theme default GET: expected HTTP 405")
    page = admin.request("admin/admin_priv_forums.php?forum=1&op=clearusers")
    if admin.last_status != 405:
        raise SmokeFailure("private-forum clear GET: expected HTTP 405")
    secure("mutation-shaped GET requests render confirmation or remain read-only")

    reset_unknown = anonymous.request(
        "sendpassword.php",
        {"submit": "Send Password", "user": "Definitely Missing", "email": "missing@example.test"},
    )
    reset_known = anonymous.request(
        "sendpassword.php",
        {"submit": "Send Password", "user": USER_NAME, "email": "user@example.test"},
    )
    reset_message = "If the supplied details match an account, a password reset link has been sent."
    require(reset_unknown, reset_message, "unknown-account reset response")
    require(reset_known, reset_message, "known-account reset response")
    if re.search(r"token=[a-f0-9]{64}", reset_known):
        raise SmokeFailure("password reset: raw token appeared in the HTTP response")
    secure("password reset requests do not disclose whether an account exists")

    first = Browser(base_url)
    second = Browser(base_url)
    require(login(first, ATTACKER_NAME, ATTACKER_PASSWORD), "Logged in as", "first parallel login")
    require(login(second, ATTACKER_NAME, ATTACKER_PASSWORD), "Logged in as", "second parallel login")
    first.request("logout.php", {"logout": "Logout"})
    reject(first.request("index.php"), "Logged in as Smoke O&#039;Brien", "logged-out session")
    require(second.request("index.php"), "Logged in as Smoke O&#039;Brien", "parallel session preservation")
    secure("logout terminates only the current browser session")

    if idle_timeout_test_seconds:
        expiring = Browser(base_url)
        require(login(expiring, ATTACKER_NAME, ATTACKER_PASSWORD), "Logged in as", "expiry login")
        time.sleep(idle_timeout_test_seconds)
        reject(expiring.request("index.php"), "Logged in as Smoke O&#039;Brien", "idle session expiry")
        secure("idle sessions expire server-side")

    changer = Browser(base_url)
    change_peer = Browser(base_url)
    require(login(changer, ATTACKER_NAME, ATTACKER_PASSWORD), "Logged in as", "password-change login")
    require(login(change_peer, ATTACKER_NAME, ATTACKER_PASSWORD), "Logged in as", "password-change peer login")
    page = changer.request(
        "bb_profile.php",
        {
            "submit": "Submit",
            "mode": "edit",
            "save": "1",
            "user_id": attacker_user_id,
            "password": ATTACKER_PASSWORD,
            "new_password": "smoke-attacker-new-pass",
            "password2": "smoke-attacker-new-pass",
            "email": "attacker@example.test",
            "website": "http://",
        },
    )
    require(page, "Your Information has been updated", "profile password change")
    require(changer.request("index.php"), "Logged in as Smoke O&#039;Brien", "replacement password-change session")
    reject(change_peer.request("index.php"), "Logged in as Smoke O&#039;Brien", "password-change peer revocation")
    reject(login(Browser(base_url), ATTACKER_NAME, ATTACKER_PASSWORD), "Logged in as Smoke O&#039;Brien", "old changed password")
    secure("profile password changes revoke other sessions and rotate the current one")

    login_throttle = Browser(base_url)
    for attempt in range(5):
        page = login(login_throttle, "ThrottleTarget", f"incorrect-password-{attempt}")
        if login_throttle.last_status == 429:
            raise SmokeFailure("login throttling activated before the configured account limit")
    page = login(login_throttle, "ThrottleTarget", "incorrect-password-final")
    if login_throttle.last_status != 429:
        raise SmokeFailure("login throttling: expected HTTP 429 after repeated failures")
    require(page, "Invalid username or password", "login throttling uniform response")
    if login_throttle.last_headers is None or not login_throttle.last_headers.get("Retry-After"):
        raise SmokeFailure("login throttling: missing Retry-After header")

    reset_throttle = Browser(base_url)
    for attempt in range(5):
        page = reset_throttle.request(
            "sendpassword.php",
            {"submit": "Submit", "user": "ResetThrottle", "email": "nobody@example.test"},
        )
        if reset_throttle.last_status == 429:
            raise SmokeFailure("password-reset throttling activated before the configured account limit")
    page = reset_throttle.request(
        "sendpassword.php",
        {"submit": "Submit", "user": "ResetThrottle", "email": "nobody@example.test"},
    )
    if reset_throttle.last_status != 429:
        raise SmokeFailure("password-reset throttling: expected HTTP 429 after repeated requests")
    require(page, "Too many password-reset requests", "password-reset throttling response")
    secure("login and password-reset abuse is throttled per account and network")

    print(
        f"Security characterization completed with {known_findings} known open findings.",
        flush=True,
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--idle-timeout-test-seconds", type=int, default=0)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        characterize(args.base_url, args.idle_timeout_test_seconds)
    except SmokeFailure as error:
        print(f"not ok - {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
