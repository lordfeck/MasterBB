<?php
require_once __DIR__ . '/../phpBB/functions.php';

function test_assert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "not ok - $message\n");
		exit(1);
	}
}

$password = 'correct horse battery staple';
$hash = forum_hash_password($password);
test_assert(forum_verify_password($password, $hash), 'password hash verification');
test_assert(!forum_verify_password('incorrect password', $hash), 'wrong password rejection');
test_assert(forum_password_error('too-short') !== '', 'minimum password length');
if (defined('PASSWORD_ARGON2ID')) {
	test_assert(str_starts_with($hash, '$argon2id$'), 'Argon2id preference');
}

putenv('MASTERBB_HTTPS_MODE=auto');
putenv('MASTERBB_TRUSTED_PROXIES=10.0.0.0/8,2001:db8::/32');
$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20, 10.2.3.4';
test_assert(forum_request_is_https(), 'trusted proxy HTTPS detection');
test_assert(forum_client_ip() === '198.51.100.20', 'trusted proxy client IP traversal');
$secure_cookie = forum_cookie_options(0, '/phpBB', '', forum_request_is_https());
test_assert($secure_cookie['secure'] && $secure_cookie['httponly'] && $secure_cookie['samesite'] === 'Lax', 'trusted HTTPS cookie options');

$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
test_assert(!forum_request_is_https(), 'untrusted forwarded scheme rejection');
test_assert(forum_client_ip() === '192.0.2.10', 'untrusted forwarded client rejection');

putenv('MASTERBB_HTTPS_MODE=on');
test_assert(forum_request_is_https(), 'forced HTTPS mode');
putenv('MASTERBB_HTTPS_MODE=off');
$_SERVER['HTTPS'] = 'on';
test_assert(!forum_request_is_https(), 'forced HTTP mode');

putenv('MASTERBB_PUBLIC_URL=javascript://unsafe.example');
$url_phpbb = '/phpBB';
$_SERVER['SERVER_NAME'] = 'forum.example.test';
test_assert(forum_public_url('sendpassword.php') === 'http://forum.example.test/phpBB/sendpassword.php', 'unsafe public URL scheme rejection');

echo "ok - password and trusted-proxy helpers\n";
?>
