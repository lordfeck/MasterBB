#!/usr/bin/env python3
"""Security characterization checks for a disposable, smoke-populated board."""

from __future__ import annotations

import argparse
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


ATTACKER_NAME = "SmokeAttacker"
ATTACKER_PASSWORD = "smoke-attacker-pass"


def secure(step: str) -> None:
    print(f"ok - blocked: {step}", flush=True)


def known_vulnerability(step: str) -> None:
    print(f"known vulnerable - {step}", flush=True)


def characterize(base_url: str) -> None:
    anonymous = Browser(base_url)
    user = Browser(base_url)
    attacker = Browser(base_url)
    admin = Browser(base_url)

    page = login(anonymous, "SmokeAdmin' OR 1=1 -- ", "not-the-password")
    reject(page, f"Logged in as {ADMIN_NAME}", "SQL-injection login attempt")
    secure("SQL-injection-shaped login is rejected")

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
    require(page, f"Logged in as {ATTACKER_NAME}", "attacker login")

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

    print("Security characterization completed with 1 known open finding.", flush=True)


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
