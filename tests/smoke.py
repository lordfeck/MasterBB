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
        self.last_status = None
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookies)
        )

    def request(
        self,
        path: str,
        fields: dict[str, str | list[str]] | None = None,
        headers: dict[str, str] | None = None,
        csrf: bool = True,
    ) -> str:
        url = f"{self.base_url}/{path.lstrip('/')}"
        data = None
        if fields is not None:
            fields = dict(fields)
            if csrf and "_csrf" not in fields:
                csrf_cookie = self.cookie("phpBBcsrf")
                if csrf_cookie is None:
                    self.request(path, headers=headers)
                    csrf_cookie = self.cookie("phpBBcsrf")
                if csrf_cookie is None:
                    raise SmokeFailure(f"No CSRF cookie was issued for {url}")
                fields["_csrf"] = csrf_cookie.value
            data = urllib.parse.urlencode(fields, doseq=True).encode("ascii")
        request = urllib.request.Request(url, data=data, headers=headers or {})
        try:
            with self.opener.open(request, timeout=45) as response:
                self.last_headers = response.headers
                self.last_status = response.status
                payload = response.read()
        except urllib.error.HTTPError as error:
            self.last_headers = error.headers
            self.last_status = error.code
            payload = error.read()
        except (OSError, urllib.error.URLError) as error:
            raise SmokeFailure(f"Request failed for {url}: {error}") from error
        return payload.decode("latin-1", errors="replace")

    def set_cookie(self, name: str, value: str, path: str = "/phpBB") -> None:
        host = urllib.parse.urlparse(self.base_url).hostname or "localhost"
        self.cookies.set_cookie(
            http.cookiejar.Cookie(
                version=0,
                name=name,
                value=value,
                port=None,
                port_specified=False,
                domain=host,
                domain_specified=False,
                domain_initial_dot=False,
                path=path,
                path_specified=True,
                secure=False,
                expires=None,
                discard=True,
                comment=None,
                comment_url=None,
                rest={},
                rfc2109=False,
            )
        )

    def cookie(self, name: str):
        return next((cookie for cookie in self.cookies if cookie.name == name), None)


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
            require(page, 'NAME="_csrf"', "installer CSRF form injection")
            report("legacy Apache and installer are ready")
            return
        except (SmokeFailure, OSError) as error:
            last_error = error
            time.sleep(2)
    raise SmokeFailure(f"installer did not become ready: {last_error}")


def install(base_url: str) -> None:
    browser = Browser(base_url)
    wait_for_installer(browser)
    initial_install_cookie = browser.cookie("phpBBinstall")
    if initial_install_cookie is None:
        raise SmokeFailure("installer session: no server-side state cookie was issued")
    install_cookie_attributes = {key.lower() for key in initial_install_cookie._rest}
    if "httponly" not in install_cookie_attributes or "samesite" not in install_cookie_attributes:
        raise SmokeFailure("installer session: expected HttpOnly and SameSite attributes")

    page = browser.request("install.php", {"next": "database"}, csrf=False)
    require(page, "Invalid or missing form token", "installer CSRF rejection")
    if browser.last_status != 403:
        raise SmokeFailure("installer CSRF rejection: expected HTTP 403")

    installer_secret = "installer-secret-must-not-echo"
    page = browser.request(
        "install.php",
        {
            "next": "database",
            "dbserver": "db",
            "dbname": "phpbb",
            "dbuser": "invalid-installer-user",
            "dbpass": installer_secret,
        },
    )
    require(page, "database connection failed", "installer database error")
    reject(page, installer_secret, "installer credential disclosure")
    failed_install_cookie = browser.cookie("phpBBinstall")
    if failed_install_cookie is None or failed_install_cookie.value == initial_install_cookie.value:
        raise SmokeFailure("installer session: identifier did not rotate before storing credentials")

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
    reject(page, 'NAME="dbpass"', "installer server-side credential state")
    database_install_cookie = browser.cookie("phpBBinstall")
    if database_install_cookie is None or database_install_cookie.value == failed_install_cookie.value:
        raise SmokeFailure("installer session: replacement database credentials did not rotate state")
    report("fresh database schema and seed data installed")

    page = browser.request(
        "install.php",
        {
            "next": "database",
            "done": "1",
        },
    )
    require(page, 'NAME="username"', "administrator form")

    page = browser.request(
        "install.php",
        {
            "next": "user",
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
    if browser.cookie("phpBBinstall") is not None:
        raise SmokeFailure("installer session: successful setup did not clear server-side state")
    report("board configured")

    lock_browser = Browser(base_url)
    page = lock_browser.request("install.php")
    require(page, "permanently locked", "installer lock")
    if lock_browser.last_status == 200:
        raise SmokeFailure("installer lock: expected a non-success response")
    report("installer permanently locked")


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
    require(page, "Redmond95", "Redmond95 theme availability")
    require(page, "Hot Dog Stand", "Hot Dog Stand theme availability")
    page = user.request("bb_memberlist.php?sortby=user&start=0")
    require(page, "Memberslist", "member-list request boundary")
    page = user.request("faq.php?mode=bbcode")
    require(page, "BBCode", "FAQ request boundary")
    report("profile, preferences, member-list, and FAQ pages")

    page = user.request("logout.php", {"logout": "Logout"})
    require(page, "Not logged in", "user logout")
    reject(page, f"Logged in as {USER_NAME}", "user logout")
    report("user logout")

    admin.request("logout.php", {"logout": "Logout"})
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
