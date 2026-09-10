#!/usr/bin/env python3
"""Black-box checks for a disposable password-reset token."""

from __future__ import annotations

import argparse
import sys

from smoke import USER_NAME, USER_PASSWORD, Browser, SmokeFailure, login, reject, require


NEW_PASSWORD = "smoke-user-reset-pass"
RESET_MESSAGE = "If the supplied details match an account, a password reset link has been sent."


def request_new(base_url: str) -> None:
    page = Browser(base_url).request(
        "sendpassword.php",
        {"submit": "Send Password", "user": USER_NAME, "email": "user@example.test"},
    )
    require(page, RESET_MESSAGE, "replacement reset request")
    print("ok - replacement password-reset token requested", flush=True)


def check_expired(base_url: str, token: str) -> None:
    page = Browser(base_url).request(f"sendpassword.php?token={token}")
    require(page, "invalid or has expired", "expired reset token")
    print("ok - expired password-reset token is rejected", flush=True)


def check(base_url: str, token: str) -> None:
    active_session = Browser(base_url)
    require(login(active_session, USER_NAME, USER_PASSWORD), f"Logged in as {USER_NAME}", "pre-reset login")

    reset_browser = Browser(base_url)
    page = reset_browser.request(f"sendpassword.php?token={token}")
    require(page, 'NAME="new_password"', "reset-link form")
    require(
        login(Browser(base_url), USER_NAME, USER_PASSWORD),
        f"Logged in as {USER_NAME}",
        "reset GET remains non-destructive",
    )

    page = reset_browser.request(
        "sendpassword.php",
        {
            "reset": "Change Password",
            "token": token,
            "new_password": NEW_PASSWORD,
            "new_password_confirm": "a-different-password",
        },
    )
    require(page, "do not match", "reset password confirmation")

    page = reset_browser.request(
        "sendpassword.php",
        {
            "reset": "Change Password",
            "token": token,
            "new_password": NEW_PASSWORD,
            "new_password_confirm": NEW_PASSWORD,
        },
    )
    require(page, "Your password has been changed", "password reset")

    page = reset_browser.request(
        "sendpassword.php",
        {
            "reset": "Change Password",
            "token": token,
            "new_password": "another-valid-password",
            "new_password_confirm": "another-valid-password",
        },
    )
    require(page, "invalid or has expired", "one-time reset token")

    reject(
        login(Browser(base_url), USER_NAME, USER_PASSWORD),
        f"Logged in as {USER_NAME}",
        "old password rejection",
    )
    require(
        login(Browser(base_url), USER_NAME, NEW_PASSWORD),
        f"Logged in as {USER_NAME}",
        "new password login",
    )
    reject(
        active_session.request("index.php"),
        f"Logged in as {USER_NAME}",
        "reset session revocation",
    )
    print("ok - password reset tokens are expiring, one-time, and revoke existing sessions", flush=True)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--token", default="")
    parser.add_argument("command", choices=("request", "expired", "consume"))
    args = parser.parse_args()
    try:
        if args.command == "request":
            request_new(args.base_url)
        elif args.command == "expired":
            check_expired(args.base_url, args.token)
        else:
            check(args.base_url, args.token)
    except SmokeFailure as error:
        print(f"not ok - {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
