#!/usr/bin/env python3
"""Black-box smoke tests for a freshly installed phpBB 1.4.4 board."""

from __future__ import annotations

import argparse
import http.cookiejar
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


ADMIN_NAME = "SmokeAdmin"
ADMIN_PASSWORD = "smoke-admin-pass"
USER_NAME = "SmokeUser"
USER_PASSWORD = "smoke-user-pass"


class SmokeFailure(AssertionError):
    pass


class Browser:
    def __init__(self, base_url: str) -> None:
        self.base_url = base_url.rstrip("/")
        self.cookies = http.cookiejar.CookieJar()
        self.last_headers = None
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookies)
        )

    def request(
        self, path: str, fields: dict[str, str | list[str]] | None = None
    ) -> str:
        url = f"{self.base_url}/{path.lstrip('/')}"
        data = None
        if fields is not None:
            data = urllib.parse.urlencode(fields, doseq=True).encode("ascii")
        request = urllib.request.Request(url, data=data)
        try:
            with self.opener.open(request, timeout=45) as response:
                self.last_headers = response.headers
                payload = response.read()
        except (OSError, urllib.error.URLError) as error:
            raise SmokeFailure(f"Request failed for {url}: {error}") from error
        return payload.decode("latin-1", errors="replace")


def require(page: str, text: str, step: str) -> None:
    if text not in page:
        raise SmokeFailure(f"{step}: expected {text!r} in response")


def reject(page: str, text: str, step: str) -> None:
    if text in page:
        raise SmokeFailure(f"{step}: did not expect {text!r} in response")


def report(step: str) -> None:
    print(f"ok - {step}", flush=True)


def wait_for_installer(browser: Browser) -> None:
    deadline = time.monotonic() + 120
    last_error: Exception | None = None
    while time.monotonic() < deadline:
        try:
            page = browser.request("install.php")
            require(page, "Database Server Address", "installer readiness")
            report("legacy Apache and installer are ready")
            return
        except (SmokeFailure, OSError) as error:
            last_error = error
            time.sleep(2)
    raise SmokeFailure(f"installer did not become ready: {last_error}")


def install(base_url: str) -> None:
    browser = Browser(base_url)
    wait_for_installer(browser)

    page = browser.request(
        "install.php",
        {
            "next": "database",
            "dbserver": "db",
            "dbname": "phpbb",
            "dbuser": "phpbb",
            "dbpass": "phpbb",
        },
    )
    require(page, "Database Created Successfully", "database installation")
    report("fresh database schema and seed data installed")

    page = browser.request(
        "install.php",
        {
            "next": "database",
            "done": "1",
            "dbserver": "db",
            "dbname": "phpbb",
            "dbuser": "phpbb",
            "dbpass": "phpbb",
        },
    )
    require(page, 'NAME="username"', "administrator form")

    page = browser.request(
        "install.php",
        {
            "next": "user",
            "dbserver": "db",
            "dbname": "phpbb",
            "dbuser": "phpbb",
            "dbpass": "phpbb",
            "username": ADMIN_NAME,
            "password": ADMIN_PASSWORD,
            "password_rep": ADMIN_PASSWORD,
            "email": "admin@example.test",
            "website": "http://",
        },
    )
    require(page, f"Administrator user, <b>{ADMIN_NAME}</b>", "administrator creation")
    report("administrator created")

    page = browser.request(
        "install.php",
        {
            "next": "options",
            "dbserver": "db",
            "dbname": "phpbb",
            "dbuser": "phpbb",
            "dbpass": "phpbb",
            "name": "Smoke Board",
            "email_from": "admin@example.test",
            "email_sig": "Smoke Board",
            "html": "1",
            "bb": "1",
            "sig": "1",
            "hot": "15",
            "ppp": "15",
            "tpp": "50",
            "language": "english",
        },
    )
    require(page, "successfully installed phpBB", "board configuration")
    report("board configured")


def login(browser: Browser, name: str, password: str, admin: bool = False) -> str:
    if admin:
        return browser.request(
            "admin/index.php",
            {"login": "Submit", "username": name, "password": password},
        )
    return browser.request(
        "login.php", {"submit": "Submit", "user": name, "passwd": password}
    )


def exercise(base_url: str) -> None:
    public = Browser(base_url)
    admin = Browser(base_url)
    user = Browser(base_url)

    page = admin.request("admin/index.php")
    require(page, "Forum Administration", "administrator login form")
    page = login(admin, ADMIN_NAME, ADMIN_PASSWORD, admin=True)
    require(page, "phpBB Forum Administration", "administrator login")
    report("administrator login")

    page = admin.request(
        "admin/admin_forums.php",
        {"mode": "addcat", "submit": "Create Category", "title": "Smoke Category"},
    )
    require(page, "Category Created", "category creation")

    page = admin.request(
        "admin/admin_forums.php",
        {
            "mode": "addforum",
            "submit": "Create Forum",
            "name": "Smoke Forum",
            "desc": "Smoke forum description",
            "mods[]": ["1"],
            "cat": "1",
            "forum_access": "1",
            "type": "0",
        },
    )
    require(page, "Forum Created", "forum creation")
    page = public.request("index.php")
    require(page, "Smoke Category", "public category listing")
    require(page, "Smoke Forum", "public forum listing")
    report("category and forum creation")

    page = user.request(
        "bb_register.php",
        {
            "submit": "Submit",
            "username": USER_NAME,
            "password": USER_PASSWORD,
            "password_rep": USER_PASSWORD,
            "email": "user@example.test",
            "website": "http://",
        },
    )
    require(page, "You have been added to the database", "registration")
    report("user registration")

    page = login(user, USER_NAME, USER_PASSWORD)
    require(page, f"Logged in as {USER_NAME}", "user login")
    report("user login")

    page = user.request(
        "newtopic.php",
        {
            "submit": "Submit",
            "forum": "1",
            "subject": "Smoke Topic Needle",
            "message": "Original smoke body",
        },
    )
    require(page, "Your Message has been stored", "new topic")
    page = user.request("viewtopic.php?topic=1&forum=1")
    require(page, "Smoke Topic Needle", "new topic listing")
    require(page, "Original smoke body", "new topic body")
    report("new topic")

    page = user.request(
        "reply.php",
        {
            "submit": "Submit",
            "forum": "1",
            "topic": "1",
            "message": "Smoke reply body",
        },
    )
    require(page, "Your Message has been stored", "reply")
    page = user.request("viewtopic.php?topic=1&forum=1")
    require(page, "Smoke reply body", "reply listing")
    report("reply")

    page = user.request(
        "editpost.php",
        {
            "submit": "Submit",
            "post_id": "1",
            "forum": "1",
            "username": USER_NAME,
            "subject": "Smoke Topic Needle Edited",
            "message": "Edited smoke body",
        },
    )
    require(page, "Your Message has been stored", "post edit")
    page = user.request("viewtopic.php?topic=1&forum=1")
    require(page, "Smoke Topic Needle Edited", "edited topic title")
    require(page, "Edited smoke body", "edited post body")
    reject(page, "Original smoke body", "edited post body")
    report("post edit")

    page = user.request("reply.php?topic=1&forum=1&post=1&quote=1")
    require(page, "Edited smoke body", "quoted-reply GET parameters")
    report("quoted reply form")

    page = user.request(
        "sendpmsg.php",
        {
            "submit": "Submit",
            "tousername": ADMIN_NAME,
            "message": "Smoke private message",
        },
    )
    require(page, "Your Message has been stored", "private-message send")
    page = admin.request("viewpmsg.php")
    require(page, "Smoke private message", "private-message inbox")
    require(page, USER_NAME, "private-message sender")
    report("private message send and read")

    page = user.request(
        "search.php",
        {
            "submit": "Search",
            "term": "Needle",
            "addterms": "any",
            "forum": "all",
            "sortby": "p.post_time desc",
            "searchboth": "both",
        },
    )
    require(page, "Smoke Topic Needle Edited", "search result")
    require(page, "Smoke Forum", "search forum")
    page = user.request(
        "search.php?submit=Search&term=Needle&addterms=any&forum=all"
        "&sortby=p.post_time%20desc&searchboth=both"
    )
    require(page, "Smoke Topic Needle Edited", "GET search result")
    report("POST and GET search")

    page = admin.request(
        "editpost.php",
        {
            "submit": "Submit",
            "delete": "on",
            "post_id": "2",
            "forum": "1",
            "username": ADMIN_NAME,
            "message": "Smoke reply body",
        },
    )
    require(page, "has been deleted", "post deletion")
    page = admin.request("viewtopic.php?topic=1&forum=1")
    reject(page, "Smoke reply body", "deleted reply")
    require(page, "Edited smoke body", "remaining topic post")
    report("post deletion")

    page = user.request("bb_profile.php?mode=edit")
    require(page, 'NAME="user_id"', "profile request boundary")
    page = user.request("prefs.php")
    require(page, "Edit Your Preferences", "preferences request boundary")
    require(page, "Midnight Terminal", "Midnight Terminal theme availability")
    require(page, "Sepia Gazette", "Sepia Gazette theme availability")
    page = user.request("bb_memberlist.php?sortby=user&start=0")
    require(page, "Memberslist", "member-list request boundary")
    page = user.request("faq.php?mode=bbcode")
    require(page, "BBCode", "FAQ request boundary")
    report("profile, preferences, member-list, and FAQ pages")

    page = user.request("logout.php")
    require(page, "Not logged in", "user logout")
    reject(page, f"Logged in as {USER_NAME}", "user logout")
    report("user logout")

    admin.request("logout.php")
    page = admin.request("admin/index.php")
    require(page, "Please enter your username and password", "administrator logout")
    page = login(admin, ADMIN_NAME, ADMIN_PASSWORD, admin=True)
    require(page, "phpBB Forum Administration", "administrator re-login")
    report("administrator logout and re-login")

    admin_pages = (
        ("admin/admin_board.php?mode=setoptions", "Set Forum Wide Options"),
        ("admin/admin_themes.php", "Theme Administration"),
        ("admin/admin_users.php?mode=moduser", "Select a User to Modify"),
        ("admin/admin_priv_forums.php", "Select a Forum to Edit"),
        ("admin/smiles.php", "Smilies Utility"),
    )
    for path, marker in admin_pages:
        page = admin.request(path)
        require(page, marker, f"administrator page {path}")
    report("secondary administration pages")

    print("All smoke tests passed.", flush=True)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", required=True)
    parser.add_argument("command", choices=("install", "exercise"))
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        if args.command == "install":
            install(args.base_url)
        else:
            exercise(args.base_url)
    except SmokeFailure as error:
        print(f"not ok - {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
