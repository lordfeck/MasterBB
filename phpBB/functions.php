<?php
/***************************************************************************
                           functions.php  -  description
                             -------------------
    begin                : Sat June 17 2000
    copyright            : (C) 2001 The phpBB Group
    email                : support@phpbb.com

    $Id: functions.php,v 1.115 2001/05/30 20:45:59 thefinn Exp $

 ***************************************************************************/

/***************************************************************************
 *                                         				                                
 *   This program is free software; you can redistribute it and/or modify  	
 *   it under the terms of the GNU General Public License as published by  
 *   the Free Software Foundation; either version 2 of the License, or	    	
 *   (at your option) any later version.
 *
 ***************************************************************************/

require_once __DIR__ . '/database.php';

/**
 * Start session-management functions - Nathan Codding, July 21, 2000.
 */

function forum_password_algorithm() {
	return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function forum_hash_password($password) {
	$hash = password_hash($password, forum_password_algorithm());
	if ($hash === false) {
		die('Unable to protect the password.');
	}
	return $hash;
}

function forum_verify_password($password, $hash) {
	return is_string($hash) && $hash !== '' && password_verify($password, $hash);
}

function forum_password_error($password) {
	$length = strlen($password);
	if ($length < 12) {
		return 'Passwords must contain at least 12 characters.';
	}
	if ($length > 255) {
		return 'Passwords may not contain more than 255 characters.';
	}
	return '';
}

function forum_env_int($name, $default, $minimum) {
	$value = getenv($name);
	if ($value === false || !preg_match('/^[0-9]+$/', $value)) {
		return $default;
	}
	return max($minimum, (int) $value);
}

function forum_security_headers() {
	if (headers_sent()) {
		return;
	}
	header('X-Content-Type-Options: nosniff');
	header('X-Frame-Options: DENY');
	header('Referrer-Policy: strict-origin-when-cross-origin');
	header('Permissions-Policy: camera=(), geolocation=(), microphone=()');
	header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' http: https:");
	$hsts_seconds = forum_env_int('MASTERBB_HSTS_SECONDS', 0, 0);
	if ($hsts_seconds > 0 && forum_request_is_https()) {
		header('Strict-Transport-Security: max-age=' . $hsts_seconds);
	}
}

function forum_enforce_request_size() {
	$maximum = forum_env_int('MASTERBB_MAX_REQUEST_BYTES', 262144, 1024);
	$length = $_SERVER['CONTENT_LENGTH'] ?? '';
	if ($length !== '' && preg_match('/^[0-9]+$/D', (string) $length) && (int) $length > $maximum) {
		http_response_code(413);
		die('The submitted request is too large.');
	}
}

function forum_valid_email($email) {
	return strlen((string) $email) <= 100 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function forum_valid_web_url($url, $allow_empty = true) {
	$url = trim((string) $url);
	if ($url === '') {
		return $allow_empty;
	}
	if (strlen($url) > 100 || !filter_var($url, FILTER_VALIDATE_URL)) {
		return false;
	}
	$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
	return $scheme === 'http' || $scheme === 'https';
}

function forum_valid_language($language) {
	global $phpEx;
	$language = (string) $language;
	return preg_match('/^[a-z0-9_]+$/D', $language) === 1
		&& is_file(__DIR__ . '/language/lang_' . $language . '.' . ($phpEx ?? 'php'));
}

function forum_valid_color($color) {
	return preg_match('/^#[0-9a-f]{6}$/Di', (string) $color) === 1;
}

function forum_valid_font_size($size) {
	return preg_match('/^[+-]?[1-7]$/D', (string) $size) === 1;
}

function forum_valid_table_width($width) {
	if (preg_match('/^([1-9][0-9]?|100)%$/D', (string) $width, $matches)) {
		return true;
	}
	return preg_match('/^[1-9][0-9]{1,3}$/D', (string) $width) === 1;
}

function forum_valid_font_face($font) {
	return strlen((string) $font) <= 100
		&& preg_match('/^[A-Za-z0-9 ,_-]+$/D', (string) $font) === 1;
}

function forum_valid_local_asset($path, $directory = 'images') {
	$path = str_replace('\\', '/', trim((string) $path));
	if ($path === '' || strlen($path) > 255 || str_contains($path, '..') || str_starts_with($path, '/')) {
		return false;
	}
	if (preg_match('#^[A-Za-z0-9_./-]+\.(?:gif|jpe?g|png|webp)$#Di', $path) !== 1) {
		return false;
	}
	return $directory === '' || str_starts_with($path, rtrim($directory, '/') . '/');
}

function forum_theme_validation_error($theme) {
	if (trim((string) ($theme['theme_name'] ?? '')) === '' || strlen((string) $theme['theme_name']) > 35) {
		return 'Theme names must contain between 1 and 35 characters.';
	}
	foreach (array('bgcolor', 'textcolor', 'color1', 'color2', 'table_bgcolor', 'linkcolor', 'vlinkcolor') as $field) {
		if (!forum_valid_color($theme[$field] ?? '')) {
			return 'Theme colours must use six-digit hexadecimal values such as #001122.';
		}
	}
	if (!forum_valid_font_face($theme['fontface'] ?? '')) {
		return 'The theme font list contains unsupported characters.';
	}
	foreach (array('fontsize1', 'fontsize2', 'fontsize3', 'fontsize4') as $field) {
		if (!forum_valid_font_size($theme[$field] ?? '')) {
			return 'Theme font sizes must be values from 1 to 7 with an optional sign.';
		}
	}
	if (!forum_valid_table_width($theme['tablewidth'] ?? '')) {
		return 'Theme table width must be a safe pixel value or percentage.';
	}
	foreach (array('header_image', 'newtopic_image', 'reply_image', 'replylocked_image') as $field) {
		if (!forum_valid_local_asset($theme[$field] ?? '', 'images')) {
			return 'Theme images must be local files below the images directory.';
		}
	}
	return '';
}

function forum_ip_in_range($ip, $range) {
	$parts = explode('/', trim($range), 2);
	$network = inet_pton($parts[0]);
	$address = inet_pton($ip);
	if ($network === false || $address === false || strlen($network) !== strlen($address)) {
		return false;
	}

	$bits = isset($parts[1]) ? filter_var($parts[1], FILTER_VALIDATE_INT) : strlen($network) * 8;
	if ($bits === false || $bits < 0 || $bits > strlen($network) * 8) {
		return false;
	}
	$bytes = intdiv($bits, 8);
	$remainder = $bits % 8;
	if ($bytes && substr($network, 0, $bytes) !== substr($address, 0, $bytes)) {
		return false;
	}
	if (!$remainder) {
		return true;
	}
	$mask = (0xFF << (8 - $remainder)) & 0xFF;
	return (ord($network[$bytes]) & $mask) === (ord($address[$bytes]) & $mask);
}

function forum_is_trusted_proxy($ip) {
	$ranges = getenv('MASTERBB_TRUSTED_PROXIES');
	if ($ranges === false || trim($ranges) === '') {
		return false;
	}
	foreach (explode(',', $ranges) as $range) {
		if (forum_ip_in_range($ip, $range)) {
			return true;
		}
	}
	return false;
}

function forum_request_is_https() {
	$mode = strtolower(trim((string) (getenv('MASTERBB_HTTPS_MODE') ?: 'auto')));
	if ($mode === 'on') {
		return true;
	}
	if ($mode === 'off') {
		return false;
	}
	$https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
	if ($https === 'on' || $https === '1') {
		return true;
	}
	$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
	if (!forum_is_trusted_proxy($remote)) {
		return false;
	}
	$values = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
	$proto = strtolower(trim((string) end($values)));
	return $proto === 'https';
}

function forum_client_ip($fallback = '') {
	$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? $fallback);
	if (!forum_is_trusted_proxy($remote)) {
		return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : $fallback;
	}
	$forwarded = array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
	$forwarded[] = $remote;
	for ($index = count($forwarded) - 1; $index >= 0; --$index) {
		$candidate = $forwarded[$index];
		if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
			continue;
		}
		if (!forum_is_trusted_proxy($candidate)) {
			return $candidate;
		}
	}
	return $remote;
}

forum_security_headers();
forum_enforce_request_size();

function forum_public_url($path = '') {
	global $url_phpbb;
	$configured = trim((string) (getenv('MASTERBB_PUBLIC_URL') ?: ''));
	$scheme = strtolower((string) parse_url($configured, PHP_URL_SCHEME));
	if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_URL) && ($scheme === 'http' || $scheme === 'https')) {
		$base = rtrim($configured, '/');
	} else {
		$host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
		if (preg_match('/^[A-Za-z0-9.-]+$/D', $host) !== 1) {
			$host = 'localhost';
		}
		$base = (forum_request_is_https() ? 'https://' : 'http://') . $host . $url_phpbb;
	}
	return $base . '/' . ltrim($path, '/');
}

function forum_send_mail($to, $subject, $message, $from) {
	if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
		return false;
	}
	$subject = str_replace(array("\r", "\n"), '', $subject);
	$test_log = getenv('MASTERBB_TEST_MAIL_LOG');
	if ($test_log !== false && $test_log !== '') {
		$record = json_encode(array('to' => $to, 'subject' => $subject, 'message' => $message), JSON_UNESCAPED_SLASHES);
		return file_put_contents($test_log, $record . "\n", FILE_APPEND | LOCK_EX) !== false;
	}
	$from = str_replace(array("\r", "\n"), '', $from);
	return mail($to, $subject, $message, 'From: ' . $from);
}

function forum_cookie_options($expires, $path, $domain, $secure, $http_only = true) {
	$options = array(
		'expires' => (int) $expires,
		'path' => $path ?: '/',
		'secure' => (bool) $secure,
		'httponly' => (bool) $http_only,
		'samesite' => 'Lax',
	);
	if ($domain !== '') {
		$options['domain'] = $domain;
	}
	return $options;
}

function set_forum_cookie($name, $value, $expires, $path, $domain, $secure, $http_only = true) {
	return setcookie($name, $value, forum_cookie_options($expires, $path, $domain, $secure, $http_only));
}

function clear_forum_cookie($name, $path, $domain, $secure) {
	unset($_COOKIE[$name], $GLOBALS['HTTP_COOKIE_VARS'][$name]);
	return set_forum_cookie($name, '', time() - 3600, $path, $domain, $secure);
}

function forum_csrf_initialize($cookiepath, $cookiedomain, $cookiesecure) {
	global $HTTP_COOKIE_VARS, $forum_csrf_token;
	$cookie_name = 'phpBBcsrf';
	$token = isset($HTTP_COOKIE_VARS[$cookie_name]) ? (string) $HTTP_COOKIE_VARS[$cookie_name] : '';
	if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
		$token = bin2hex(random_bytes(32));
		set_forum_cookie($cookie_name, $token, 0, $cookiepath, $cookiedomain, $cookiesecure);
		$HTTP_COOKIE_VARS[$cookie_name] = $token;
	}
	$forum_csrf_token = $token;
	if (!defined('FORUM_CSRF_BUFFER_STARTED')) {
		define('FORUM_CSRF_BUFFER_STARTED', true);
		ob_start('forum_csrf_inject_forms');
	}
}

function forum_csrf_inject_forms($html) {
	global $forum_csrf_token;
	if (!$forum_csrf_token || stripos($html, '<form') === false) {
		return $html;
	}
	$input = '<INPUT TYPE="HIDDEN" NAME="_csrf" VALUE="' . html_escape($forum_csrf_token) . '">';
	return preg_replace_callback('/<form\b[^>]*>/i', function ($match) use ($input) {
		if (!preg_match('/\bmethod\s*=\s*["\']?post["\']?/i', $match[0])) {
			return $match[0];
		}
		return $match[0] . $input;
	}, $html);
}

function forum_csrf_require_valid_post() {
	global $forum_csrf_token;
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
		return;
	}
	$submitted = request_string('_csrf', '', 'post');
	if (!$forum_csrf_token || !$submitted || !hash_equals($forum_csrf_token, $submitted)) {
		http_response_code(403);
		die('Invalid or missing form token. Please go back, reload the form, and try again.');
	}
}

function forum_csrf_rotate() {
	global $HTTP_COOKIE_VARS, $forum_csrf_token, $cookiepath, $cookiedomain, $cookiesecure;
	if (!isset($cookiepath)) {
		return;
	}
	$forum_csrf_token = bin2hex(random_bytes(32));
	$HTTP_COOKIE_VARS['phpBBcsrf'] = $forum_csrf_token;
	set_forum_cookie('phpBBcsrf', $forum_csrf_token, 0, $cookiepath, $cookiedomain, $cookiesecure);
}

function forum_require_post($message = 'This action must be submitted from its confirmation form.') {
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
		http_response_code(405);
		header('Allow: POST');
		die($message);
	}
}

function forum_session_digest($sessid) {
	if (!is_string($sessid) || preg_match('/^[a-f0-9]{64}$/D', $sessid) !== 1) {
		return false;
	}
	return hash('sha256', $sessid);
}

/**
 * new_session()
 * Adds a new session to the database for the given userid.
 * Returns the new session ID.
 * Stores only a digest of the random token and removes expired sessions.
 */
function new_session($userid, $remote_ip, $lifespan, $db) {
	global $HTTP_COOKIE_VARS, $sesscookiename, $sessabsolute;
	$currtime = time();
	$absolute = isset($sessabsolute) ? (int) $sessabsolute : 86400;
	$deleteSQL = "DELETE FROM sessions WHERE last_seen_at < ? OR created_at < ?";
	$delresult = db_query_params($deleteSQL, array($currtime - (int) $lifespan, $currtime - $absolute), $db);

	if (!$delresult) {
		die("Delete failed in new_session()");
	}

	if (isset($HTTP_COOKIE_VARS[$sesscookiename])) {
		end_user_session($HTTP_COOKIE_VARS[$sesscookiename], $db);
	}
	$sessid = bin2hex(random_bytes(32));
	$sql = "INSERT INTO sessions (sess_id, user_id, created_at, last_seen_at) VALUES (?, ?, ?, ?)";
	$result = db_query_params($sql, array(hash('sha256', $sessid), (int) $userid, $currtime, $currtime), $db);
	
	if ($result) {
		forum_csrf_rotate();
		return $sessid;
	} else {
		echo db_errno().": ".db_error()."<BR>";
		die("Insert failed in new_session()");
	} // if/else

} // new_session()

/**
 * Sets the sessID cookie for the given session ID. the $cookietime parameter
 * is no longer used, but just hasn't been removed yet. It'll break all the modules
 * (just login) that call this code when it gets removed. 
 * Sets a cookie with no specified expiry time. This makes the cookie last until the
 * user's browser is closed. (at last that's the case in IE5 and NS4.7.. Haven't tried
 * it with anything else.)
 */
function set_session_cookie($sessid, $cookietime, $cookiename, $cookiepath, $cookiedomain, $cookiesecure) {
	set_forum_cookie($cookiename, $sessid, 0, $cookiepath, $cookiedomain, $cookiesecure);

} // set_session_cookie()


/**
 * Returns the userID associated with a non-expired session. The remote-address
 * parameter is retained for caller compatibility but is deliberately ignored.
 */
function get_userid_from_session($sessid, $cookietime, $remote_ip, $db) {
	global $sessabsolute;
	$digest = forum_session_digest($sessid);
	if ($digest === false) {
		return 0;
	}
	$now = time();
	$mintime = $now - $cookietime;
	$absolute = isset($sessabsolute) ? (int) $sessabsolute : 86400;
	$sql = "SELECT user_id FROM sessions WHERE sess_id = ? AND last_seen_at >= ? AND created_at >= ?";
	$result = db_query_params($sql, array($digest, $mintime, $now - $absolute), $db);
	if (!$result) {
		echo db_error() . "<br>\n";
		die("Error doing DB query in get_userid_from_session()");
	}
	$row = db_fetch_array($result);
	
	if (!$row) {
		return 0;
	} else {
		return $row[user_id];
	}
	
} // get_userid_from_session()

/**
 * Refresh the last activity time of the given session in the database.
 * This is called whenever a page is hit by a user with a valid session.
 */
function update_session_time($sessid, $db) {
	$digest = forum_session_digest($sessid);
	if ($digest === false) {
		return 0;
	}
	$sql = "UPDATE sessions SET last_seen_at = ? WHERE sess_id = ?";
	$result = db_query_params($sql, array(time(), $digest), $db);
	if (!$result) {
		echo db_error() . "<br>\n";
		die("Error doing DB update in update_session_time()");
	}
	return 1;

} // update_session_time()

/**
 * Delete the given session from the database. Used by the logout page.
 */
function end_user_session($sessid, $db) {
	$digest = forum_session_digest($sessid);
	if ($digest === false) {
		return 1;
	}
	$sql = "DELETE FROM sessions WHERE sess_id = ?";
	$result = db_query_params($sql, array($digest), $db);
	if (!$result) {
		echo db_error() . "<br>\n";
		die("Delete failed in end_user_session()");
	}
	return 1;
	
} // end_session()

function end_all_user_sessions($userid, $db) {
	$result = db_query_params("DELETE FROM sessions WHERE user_id = ?", array((int) $userid), $db);
	if (!$result) {
		die("Delete failed in end_all_user_sessions()");
	}
	return 1;
}

/**
 * Prints either "logged in as [username]. Log out." or 
 * "Not logged in. Log in.", depending on the value of
 * $user_logged_in.
 */
function print_login_status($user_logged_in, $username, $url_phpbb) {
	global $phpEx;
	global $l_loggedinas, $l_notloggedin, $l_logout, $l_login;
	
	if($user_logged_in) {
		echo "<b>$l_loggedinas " . html_escape($username) . ". <a href=\"" . html_escape($url_phpbb) . "/logout.$phpEx\">$l_logout.</a></b><br>\n";
	} else {
		echo "<b>$l_notloggedin. <a href=\"$url_phpbb/login.$phpEx\">$l_login.</a></b><br>\n";
	}
} // print_login_status()

/**
 * Prints a link to either login.php or logout.php, depending
 * on whether the user's logged in or not.
 */
function make_login_logout_link($user_logged_in, $url_phpbb) {
	global $phpEx;
	global $l_logout, $l_login;
	if ($user_logged_in) {
		$link = "<a href=\"$url_phpbb/logout.$phpEx\">$l_logout</a>";
	} else {
		$link = "<a href=\"$url_phpbb/login.$phpEx\">$l_login</a>";
	}
	return $link;
} // make_login_logout_link()

/**
 * End session-management functions
 */

/*
 * Gets the total number of topics in a form
 */
function get_total_topics($forum_id, $db) {
	global $l_error;
	$sql = "SELECT count(*) AS total FROM topics WHERE forum_id = ?";
	if(!$result = db_query_params($sql, array((int) $forum_id), $db))
		return($l_error);
	if(!$myrow = db_fetch_array($result))
		return($l_error);
	
	return($myrow[total]);
}

/**
 * Encode untrusted text at an HTML text or quoted-attribute sink.
 *
 * The historical application serves UTF-8 pages in the modern port.  Keeping
 * this in one helper avoids PHP-version-dependent htmlspecialchars defaults.
 */
function html_escape($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
}

/**
 * Return a URL suitable for a quoted HTML attribute, or an inert fragment.
 * Profile and BBCode links deliberately support only ordinary web URLs.
 */
function html_web_url($value, $allow_relative = false) {
	$url = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML401, 'UTF-8'));
	if ($url === '' || preg_match('/[\\x00-\\x1F\\x7F]/', $url)) {
		return '#';
	}

	if ($allow_relative && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !str_starts_with($url, '//')) {
		return html_escape($url);
	}

	$parts = parse_url($url);
	if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
		return '#';
	}
	if (!in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
		return '#';
	}

	return html_escape($url);
}

function html_email_url($value) {
	$email = html_entity_decode(trim((string) $value), ENT_QUOTES | ENT_HTML401, 'UTF-8');
	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		return '#';
	}
	return 'mailto:' . html_escape($email);
}

/**
 * Convert plain user-authored source into the narrow markup accepted in posts
 * and private messages. Raw HTML is always encoded before BBCode is expanded.
 */
function render_user_text($source, $enable_bbcode = true, $enable_smilies = true) {
	$message = html_escape($source);
	if ($enable_bbcode) {
		$message = bbencode($message, true);
	}
	$message = make_clickable($message);
	if ($enable_smilies) {
		$message = smile($message);
	}
	return str_replace("\n", "<BR>", $message);
}
/*
 * Shows the 'header' data from the header/meta/footer table
 */
function showheader($db) {
        $sql = "SELECT header FROM headermetafooter";
        if($result = db_query($sql, $db)) {
	        if($header = db_fetch_array($result)) {
		        echo nl2br(html_escape(stripslashes($header[header])));
		}
	}
}
/*
 * Shows the meta information from the header/meta/footer table
 */
function showmeta($db) {
        $sql = "SELECT meta FROM headermetafooter";
        if($result = db_query($sql, $db)) {
		        if($meta = db_fetch_array($result)) {
	                return '<META NAME="description" CONTENT="' . html_escape(stripslashes($meta[meta])) . '">';
		}
	}
	return '';
}
/*
 * Show the footer from the header/meta/footer table
 */
function showfooter($db) {
        $sql = "SELECT footer FROM headermetafooter";
        if($result = db_query($sql, $db)) {
	        if($footer = db_fetch_array($result)) {
		        echo nl2br(html_escape(stripslashes($footer[footer])));
		}
	}
} 

/*
 * Used to keep track of all the people viewing the forum at this time
 * Anyone who's been on the board within the last 300 seconds will be 
 * returned. Any data older then 300 seconds will be removed
 */
function get_whosonline($IP, $username, $forum, $db) {
	global $sys_lang;
	if($username == '')
		$username = get_syslang_string($sys_lang, "l_guest");

	$time= explode(  " ", microtime());
	$userusec= (double)$time[0];
	$usersec= (double)$time[1];
	$deleteuser = db_query_params("DELETE FROM whosonline WHERE date < ?", array((int) $usersec - 300), $db);
	$userlog = db_fetch_row(db_query_params("SELECT * FROM whosonline WHERE IP = ?", array($IP), $db));
	if($userlog == false) {
		$ok = @db_query_params(
			"INSERT INTO whosonline (IP, DATE, username, forum) VALUES (?, ?, ?, ?)",
			array($IP, (int) $usersec, $username, (int) $forum),
			$db
		) or die("Unable to query db!");
	}
	$resultlogtab   = db_query("SELECT Count(*) as total FROM whosonline", $db);
	$numberlogtab   = db_fetch_array($resultlogtab);
	return($numberlogtab[total]);
}

/*
 * Returns the total number of posts in the whole system, a forum, or a topic
 * Also can return the number of users on the system.
 */ 
function get_total_posts($id, $db, $type) {
	$params = array();
   switch($type) {
    case 'users':
      $sql = "SELECT count(*) AS total FROM users WHERE (user_id != -1) AND (user_level != -1)";
      break;
    case 'all':
      $sql = "SELECT count(*) AS total FROM posts";
      break;
    case 'forum':
	  $sql = "SELECT count(*) AS total FROM posts WHERE forum_id = ?";
	  $params[] = (int) $id;
      break;
    case 'topic':
	  $sql = "SELECT count(*) AS total FROM posts WHERE topic_id = ?";
	  $params[] = (int) $id;
      break;
   // Old, we should never get this.   
    case 'user':
      die("Should be using the users.user_posts column for this.");
   }
   if(!$result = $params ? db_query_params($sql, $params, $db) : db_query($sql, $db))
     return("ERROR");
   if(!$myrow = db_fetch_array($result))
     return("0");
   
   return($myrow[total]);
   
}

/*
 * Returns the most recent post in a forum, or a topic
 */
function get_last_post($id, $db, $type) {
   global $l_error, $l_noposts, $l_by;
	$params = array((int) $id);
   switch($type) {
    case 'time_fix':
	  $sql = "SELECT p.post_time FROM posts p WHERE p.topic_id = ? ORDER BY post_time DESC LIMIT 1";
      break;
    case 'forum':
	  $sql = "SELECT p.post_time, p.poster_id, u.username FROM posts p, users u WHERE p.forum_id = ? AND p.poster_id = u.user_id ORDER BY post_time DESC LIMIT 1";
      break;
    case 'topic':
	  $sql = "SELECT p.post_time, u.username FROM posts p, users u WHERE p.topic_id = ? AND p.poster_id = u.user_id ORDER BY post_time DESC LIMIT 1";
      break;
    case 'user':
	  $sql = "SELECT p.post_time FROM posts p WHERE p.poster_id = ? LIMIT 1";
      break;
   }
   if(!$result = db_query_params($sql, $params, $db))
     return($l_error);
   
   if(!$myrow = db_fetch_array($result))
     return($l_noposts);
   if(($type != 'user') && ($type != 'time_fix'))
     $val = sprintf("%s <br> %s %s", html_escape($myrow[post_time]), html_escape($l_by), html_escape($myrow[username]));
   else
     $val = $myrow[post_time];
   
   return($val);
}

/*
 * Returns an array of all the moderators of a forum
 */
function get_moderators($forum_id, $db) {
	$sql = "SELECT u.user_id, u.username FROM users u, forum_mods f WHERE f.forum_id = ? and f.user_id = u.user_id";
    if(!$result = db_query_params($sql, array((int) $forum_id), $db))
     return(array());
   if(!$myrow = db_fetch_array($result))
     return(array());
   do {
      $array[] = array("$myrow[user_id]" => "$myrow[username]");
   } while($myrow = db_fetch_array($result));
   return($array);
}

/*
 * Checks if a user (user_id) is a moderator of a perticular forum (forum_id)
 * Retruns 1 if TRUE, 0 if FALSE or Error
 */
function is_moderator($forum_id, $user_id, $db) {
   $sql = "SELECT user_id FROM forum_mods WHERE forum_id = ? AND user_id = ?";
   if(!$result = db_query_params($sql, array((int) $forum_id, (int) $user_id), $db))
     return("0");
   if(!$myrow = db_fetch_array($result))
     return("0");
   if($myrow[user_id] != '')
     return("1");
   else
     return("0");
}

/*
 * Central authorization policy.  Historical access levels are:
 * -1 removed, 1 member, 2 forum moderator, 3 global moderator, 4 administrator.
 * Object lookup remains in the page scripts, while every role/ownership
 * decision is made here.
 */
function forum_user_is_authenticated($userdata, $user_logged_in = null) {
	if ($user_logged_in === null) {
		$user_logged_in = $GLOBALS['user_logged_in'] ?? false;
	}
	return (bool) $user_logged_in
		&& (int) ($userdata['user_id'] ?? 0) > 0
		&& (int) ($userdata['user_level'] ?? -1) >= 1;
}

function forum_user_is_admin($userdata, $user_logged_in = null) {
	return forum_user_is_authenticated($userdata, $user_logged_in)
		&& (int) ($userdata['user_level'] ?? 0) === 4;
}

function forum_user_can_moderate($userdata, $forum_id, $db, $user_logged_in = null) {
	if (!forum_user_is_authenticated($userdata, $user_logged_in)) {
		return false;
	}
	if (in_array((int) ($userdata['user_level'] ?? 0), array(3, 4), true)) {
		return true;
	}
	return (int) ($userdata['user_level'] ?? 0) === 2
		&& (bool) is_moderator((int) $forum_id, (int) $userdata['user_id'], $db);
}

function forum_user_has_private_forum_access($userdata, $forum_id, $is_posting, $db, $user_logged_in = null) {
	if (!forum_user_is_authenticated($userdata, $user_logged_in)) {
		return false;
	}
	if (forum_user_can_moderate($userdata, $forum_id, $db, true)) {
		return true;
	}
	$sql = 'SELECT can_post FROM forum_access WHERE user_id = ? AND forum_id = ?';
	$result = db_query_params($sql, array((int) $userdata['user_id'], (int) $forum_id), $db);
	$row = $result ? db_fetch_array($result) : false;
	return (bool) $row && (!$is_posting || (int) $row['can_post'] === 1);
}

function forum_user_can_read_forum($userdata, $forum, $db, $user_logged_in = null) {
	if ((int) ($forum['forum_type'] ?? 0) !== 1) {
		return true;
	}
	return forum_user_has_private_forum_access(
		$userdata,
		(int) ($forum['forum_id'] ?? 0),
		false,
		$db,
		$user_logged_in
	);
}

function forum_user_can_post_forum($userdata, $forum, $db, $user_logged_in = null) {
	$authenticated = forum_user_is_authenticated($userdata, $user_logged_in);
	$access = (int) ($forum['forum_access'] ?? 1);
	if (!in_array($access, array(1, 2, 3), true)) {
		return false;
	}
	if ($access === 1 && !$authenticated) {
		return false;
	}
	if ($access === 3 && !forum_user_can_moderate($userdata, (int) ($forum['forum_id'] ?? 0), $db, $user_logged_in)) {
		return false;
	}
	if ((int) ($forum['forum_type'] ?? 0) === 1) {
		return forum_user_has_private_forum_access(
			$userdata,
			(int) ($forum['forum_id'] ?? 0),
			true,
			$db,
			$user_logged_in
		);
	}
	return $access === 2 || $authenticated;
}

function forum_user_can_edit_post($userdata, $post, $db, $user_logged_in = null) {
	if (!forum_user_is_authenticated($userdata, $user_logged_in)) {
		return false;
	}
	return (int) ($post['poster_id'] ?? 0) === (int) $userdata['user_id']
		|| forum_user_can_moderate($userdata, (int) ($post['forum_id'] ?? 0), $db, true);
}

function forum_user_can_access_message($userdata, $message, $user_logged_in = null) {
	return forum_user_is_authenticated($userdata, $user_logged_in)
		&& (int) ($message['to_userid'] ?? 0) === (int) $userdata['user_id'];
}

function forum_user_can_edit_profile($userdata, $target_user_id, $user_logged_in = null) {
	return forum_user_is_authenticated($userdata, $user_logged_in)
		&& (int) $target_user_id === (int) $userdata['user_id'];
}

function forum_authorization_denied($message = 'You are not authorized to perform this action.') {
	http_response_code(403);
	error_die($message);
}

function forum_auth_attempt_keys($account, $network) {
	return array(
		hash('sha256', strtolower(trim((string) $account))),
		hash('sha256', trim((string) $network))
	);
}

function forum_audit_auth_event($action, $outcome, $account_key, $network_key) {
	$event = array(
		'event' => 'authentication',
		'action' => (string) $action,
		'outcome' => (string) $outcome,
		'account_key' => substr($account_key, 0, 12),
		'network_key' => substr($network_key, 0, 12),
	);
	error_log(json_encode($event, JSON_UNESCAPED_SLASHES));
}

function forum_auth_attempt_allowed($action, $account, $network, $db) {
	if (!in_array($action, array('login', 'password_reset'), true)) {
		return false;
	}
	list($account_key, $network_key) = forum_auth_attempt_keys($account, $network);
	$window = forum_env_int('MASTERBB_AUTH_WINDOW_SECONDS', 900, 60);
	$cutoff = time() - $window;
	db_query_params('DELETE FROM auth_attempts WHERE attempted_at < ?', array(time() - 86400), $db);
	$sql = 'SELECT '
		. 'SUM(CASE WHEN account_key = ? AND succeeded = 0 THEN 1 ELSE 0 END) AS account_failures, '
		. 'SUM(CASE WHEN network_key = ? AND succeeded = 0 THEN 1 ELSE 0 END) AS network_failures '
		. 'FROM auth_attempts WHERE action_name = ? AND attempted_at >= ?';
	$result = db_query_params($sql, array($account_key, $network_key, $action, $cutoff), $db);
	$row = $result ? db_fetch_array($result) : false;
	if (!$row) {
		return true;
	}
	$account_limit = forum_env_int('MASTERBB_AUTH_ACCOUNT_FAILURES', 5, 1);
	$network_limit = forum_env_int('MASTERBB_AUTH_NETWORK_FAILURES', 30, $account_limit);
	if ((int) $row['account_failures'] >= $account_limit || (int) $row['network_failures'] >= $network_limit) {
		http_response_code(429);
		header('Retry-After: ' . $window);
		forum_audit_auth_event($action, 'throttled', $account_key, $network_key);
		return false;
	}
	return true;
}

function forum_record_auth_attempt($action, $account, $network, $succeeded, $db) {
	list($account_key, $network_key) = forum_auth_attempt_keys($account, $network);
	if ($succeeded) {
		db_query_params('DELETE FROM auth_attempts WHERE action_name = ? AND account_key = ? AND succeeded = 0', array($action, $account_key), $db);
	}
	db_query_params(
		'INSERT INTO auth_attempts (action_name, account_key, network_key, attempted_at, succeeded) VALUES (?, ?, ?, ?, ?)',
		array($action, $account_key, $network_key, time(), $succeeded ? 1 : 0),
		$db
	);
	$count_result = db_query('SELECT COUNT(*) AS total FROM auth_attempts', $db);
	$count_row = $count_result ? db_fetch_array($count_result) : false;
	$excess = $count_row ? (int) $count_row['total'] - 10000 : 0;
	if ($excess > 0) {
		db_query_params('DELETE FROM auth_attempts ORDER BY attempt_id ASC LIMIT ?', array($excess), $db);
	}
	forum_audit_auth_event($action, $succeeded ? 'success' : 'failure', $account_key, $network_key);
}

/**
 * Nathan Codding - July 19, 2000
 * Checks the given password against the DB for the given username. Returns true if good, false if not.
 */
function check_user_pw($username, $password, $db) {
	$network = forum_client_ip($_SERVER['REMOTE_ADDR'] ?? '');
	if (!forum_auth_attempt_allowed('login', $username, $network, $db)) {
		return false;
	}
	$sql = "SELECT user_id, user_password FROM users WHERE username = ? AND user_level != -1";
	$resultID = db_query_params($sql, array($username), $db);
	if (!$resultID) {
		die('The login service is temporarily unavailable.');
	}
	$row = db_fetch_array($resultID);
	static $dummy_hash = null;
	if ($dummy_hash === null) {
		$dummy_hash = forum_hash_password(bin2hex(random_bytes(16)));
	}
	$verified = forum_verify_password($password, $row ? $row['user_password'] : $dummy_hash);
	forum_record_auth_attempt('login', $username, $network, (bool) ($row && $verified), $db);
	if (!$row || !$verified) {
		return false;
	}
	if (password_needs_rehash($row['user_password'], forum_password_algorithm())) {
		db_query_params("UPDATE users SET user_password = ? WHERE user_id = ?", array(forum_hash_password($password), (int) $row['user_id']), $db);
	}
	return true;
} // check_user_pw()


/**
 * Nathan Codding - July 19, 2000
 * Returns a count of the given userid's private messages.
 */
function get_pmsg_count($user_id, $db) {
	$sql = "SELECT msg_id FROM priv_msgs WHERE (to_userid = ?)";
	$resultID = db_query_params($sql, array((int) $user_id), $db);
	if (!$resultID) {
		echo db_error() . "<br>";
		die("Error doing DB query in get_pmsg_count");
	}
	return db_num_rows($resultID);
} // get_pmsg_count()


/**
 * Nathan Codding - July 19, 2000
 * Checks if a given username exists in the DB. Returns true if so, false if not.
 */
function check_username($username, $db) {
	$sql = "SELECT user_id FROM users WHERE (username = ?) AND (user_level != -1)";
	$resultID = db_query_params($sql, array($username), $db);
	if (!$resultID) {
		echo db_error() . "<br>";
		die("Error doing DB query in check_username()");
	}
	return db_num_rows($resultID);
} // check_username()


/**
 * Nathan Codding, July 19/2000
 * Get a user's data, given their user ID. 
 */

function get_userdata_from_id($userid, $db) {
	
	$sql = "SELECT * FROM users WHERE user_id = ?";
	if(!$result = db_query_params($sql, array((int) $userid), $db)) {
		$userdata = array("error" => "1");
		return ($userdata);
	}
	if(!$myrow = db_fetch_array($result)) {
		$userdata = array("error" => "1");
		return ($userdata);
	}
	
	return($myrow);
}

/* 
 * Gets user's data based on their username
 */
function get_userdata($username, $db) {
	$sql = "SELECT * FROM users WHERE username = ? AND user_level != -1";
	if(!$result = db_query_params($sql, array($username), $db))
		$userdata = array("error" => "1");
	if(!$myrow = db_fetch_array($result))
		$userdata = array("error" => "1");
	
	return($myrow);
}

/*
 * Returns all the rows in the themes table
 */
function setuptheme($theme, $db) {
	$sql = "SELECT * FROM themes WHERE theme_id = ?";
	if(!$result = db_query_params($sql, array((int) $theme), $db))
		return(0);
	if(!$myrow = db_fetch_array($result))
		return(0);
	return sanitize_theme_for_html($myrow);
}

function sanitize_theme_for_html($theme) {
	$defaults = array(
		'theme_name' => 'Safe Default', 'bgcolor' => '#000000', 'textcolor' => '#FFFFFF',
		'color1' => '#6C706D', 'color2' => '#2E4460', 'table_bgcolor' => '#001100',
		'linkcolor' => '#11C6BD', 'vlinkcolor' => '#11C6BD', 'fontface' => 'sans-serif',
		'fontsize1' => '1', 'fontsize2' => '2', 'fontsize3' => '-2', 'fontsize4' => '+1',
		'tablewidth' => '95%', 'header_image' => 'images/header-dark.jpg',
		'newtopic_image' => 'images/new_topic-dark.jpg', 'reply_image' => 'images/reply-dark.jpg',
		'replylocked_image' => 'images/reply_locked-dark.jpg'
	);
	if (!is_array($theme) || forum_theme_validation_error($theme) !== '') {
		$theme = array_merge(is_array($theme) ? $theme : array(), $defaults);
	}
	$text_fields = array(
		'theme_name', 'bgcolor', 'textcolor', 'color1', 'color2', 'table_bgcolor',
		'linkcolor', 'vlinkcolor', 'fontface', 'fontsize1', 'fontsize2',
		'fontsize3', 'fontsize4', 'tablewidth'
	);
	foreach ($text_fields as $field) {
		if (isset($theme[$field])) {
			$theme[$field] = html_escape($theme[$field]);
		}
	}
	$url_fields = array('header_image', 'newtopic_image', 'reply_image', 'replylocked_image');
	foreach ($url_fields as $field) {
		if (isset($theme[$field])) {
			$theme[$field] = html_web_url($theme[$field], true);
		}
	}
	return $theme;
}

/*
 * Checks if a forum or a topic exists in the database. Used to prevent
 * users from simply editing the URL to post to a non-existant forum or topic
 */
function does_exists($id, $db, $type) {
	switch($type) {
		case 'forum':
			$sql = "SELECT forum_id FROM forums WHERE forum_id = ?";
		break;
		case 'topic':
			$sql = "SELECT topic_id FROM topics WHERE topic_id = ?";
		break;
	}
	if(!$result = db_query_params($sql, array((int) $id), $db))
		return(0);
	if(!$myrow = db_fetch_array($result)) 
		return(0);
	return(1);
}

/*
 * Checks if a topic is locked
 */
function is_locked($topic, $db) {
	$sql = "SELECT topic_status FROM topics WHERE topic_id = ?";
	if(!$r = db_query_params($sql, array((int) $topic), $db))
		return(FALSE);
	if(!$m = db_fetch_array($r))
		return(FALSE);
	if($m[topic_status] == 1)
		return(TRUE);
	else
		return(FALSE);
}

/*
 * Changes :) to an <IMG> tag based on the smiles table in the database.
 *
 * Smilies must be either: 
 * 	- at the start of the message.
 * 	- at the start of a line.
 * 	- preceded by a space or a period.
 * This keeps them from breaking HTML code and BBCode.
 * TODO: Get rid of global variables.
 */
function smile($message) {
   global $db, $url_smiles;
   
   // Pad it with a space so the regexp can match.
   $message = ' ' . $message;
   
   if ($getsmiles = db_query("SELECT *, length(code) as length FROM smiles ORDER BY length DESC"))
   {
      while ($smiles = db_fetch_array($getsmiles)) 
      {
			$smile_code = preg_quote($smiles[code]);
			$smile_code = str_replace('/', '//', $smile_code);
			$smile_url = html_web_url($url_smiles . '/' . $smiles[smile_url], true);
			$message = preg_replace("/([\n\\ \\.])$smile_code/si", '\1<IMG SRC="' . $smile_url . '" ALT="smilie">', $message);
      }
   }
   
   // Remove padding, return the new string.
   $message = substr($message, 1);
   return($message);
}

/*
 * Changes a Smiliy <IMG> tag into its corresponding smile
 * TODO: Get rid of golbal variables, and implement a method of distinguishing between :D and :grin: using the <IMG> tag
 */
function desmile($message) {
   // Ick Ick Global variables...remind me to fix these! - theFinn
   global $db, $url_smiles;
   
   if ($getsmiles = db_query("SELECT * FROM smiles")){
      while ($smiles = db_fetch_array($getsmiles)) {
	 $smile_url = html_web_url($url_smiles . '/' . $smiles[smile_url], true);
	 $message = str_replace('<IMG SRC="' . $smile_url . '" ALT="smilie">', $smiles[code], $message);
	 // Preserve editability of content stored before the hardened renderer.
	 $message = str_replace("<IMG SRC=\"$url_smiles/$smiles[smile_url]\">", $smiles[code], $message);
      }
   }
   return($message);
}

/**
 * bbdecode/bbencode functions:
 * Rewritten - Nathan Codding - Aug 24, 2000
 * quote, code, and list rewritten again in Jan. 2001.
 * All BBCode tags now implemented. Nesting and multiple occurances should be 
 * handled fine for all of them. Using str_replace() instead of regexps often
 * for efficiency. quote, list, and code are not regular, so they are 
 * implemented as PDAs - probably not all that efficient, but that's the way it is. 
 *
 * Note: all BBCode tags are case-insensitive.
 */

function bbencode($message, $is_html_disabled) {

	// pad it with a space so we can distinguish between FALSE and matching the 1st char (index 0).
	// This is important; bbencode_quote(), bbencode_list(), and bbencode_code() all depend on it.
	$message = " " . $message;
	
	// First: If there isn't a "[" and a "]" in the message, don't bother.
	if (! (strpos($message, "[") && strpos($message, "]")) )
	{
		// Remove padding, return.
		$message = substr($message, 1);
		return $message;	
	}

	// [CODE] and [/CODE] for posting code (HTML, PHP, C etc etc) in your posts.
	$message = bbencode_code($message, $is_html_disabled);

	// [QUOTE] and [/QUOTE] for posting replies with quote, or just for quoting stuff.	
	$message = bbencode_quote($message);

	// [list] and [list=x] for (un)ordered lists.
	$message = bbencode_list($message);
	
	// [b] and [/b] for bolding text.
	$message = preg_replace("/\[b\](.*?)\[\/b\]/si", "<!-- BBCode Start --><B>\\1</B><!-- BBCode End -->", $message);
	
	// [i] and [/i] for italicizing text.
	$message = preg_replace("/\[i\](.*?)\[\/i\]/si", "<!-- BBCode Start --><I>\\1</I><!-- BBCode End -->", $message);
	
	// [img]image_url_here[/img] code. Only HTTP(S) resources are accepted.
	$message = preg_replace_callback("/\[img\](.*?)\[\/img\]/si", function($matches) {
		$url = html_web_url($matches[1]);
		if ($url === '#') {
			return $matches[0];
		}
		return '<!-- BBCode Start --><IMG SRC="' . $url . '" BORDER="0" ALT=""><!-- BBCode End -->';
	}, $message);
	
	// Patterns and replacements for URL and email tags..
	$message = preg_replace_callback("#\[url(?:=(.*?))?\](.*?)\[/url\]#si", function($matches) {
		$target = ($matches[1] ?? '') !== '' ? $matches[1] : $matches[2];
		$decoded_target = html_entity_decode($target, ENT_QUOTES | ENT_HTML401, 'UTF-8');
		if (!preg_match('#^https?://#i', $decoded_target)) {
			$decoded_target = 'http://' . $decoded_target;
		}
		$url = html_web_url($decoded_target);
		if ($url === '#') {
			return $matches[0];
		}
		return '<!-- BBCode URL Start --><A HREF="' . $url . '" TARGET="_blank" REL="noopener noreferrer">' . $matches[2] . '</A><!-- BBCode URL End -->';
	}, $message);

	$message = preg_replace_callback("#\[email\](.*?)\[/email\]#si", function($matches) {
		$url = html_email_url($matches[1]);
		if ($url === '#') {
			return $matches[0];
		}
		return '<!-- BBCode Start --><A HREF="' . $url . '">' . $matches[1] . '</A><!-- BBCode End -->';
	}, $message);
	
	// Remove our padding from the string..
	$message = substr($message, 1);
	return $message;
	
} // bbencode()



function bbdecode($message) {

		// Undo [code]
		$code_start_html = "<!-- BBCode Start --><TABLE BORDER=0 ALIGN=CENTER WIDTH=85%><TR><TD><font size=-1>Code:</font><HR></TD></TR><TR><TD><FONT SIZE=-1><PRE>";
		$code_end_html = "</PRE></FONT></TD></TR><TR><TD><HR></TD></TR></TABLE><!-- BBCode End -->";
		$message = str_replace($code_start_html, "[code]", $message);
		$message = str_replace($code_end_html, "[/code]", $message);

		// Undo [quote]
		$quote_start_html = "<!-- BBCode Quote Start --><TABLE BORDER=0 ALIGN=CENTER WIDTH=85%><TR><TD><font size=-1>Quote:</font><HR></TD></TR><TR><TD><FONT SIZE=-1><BLOCKQUOTE>";
		$quote_end_html = "</BLOCKQUOTE></FONT></TD></TR><TR><TD><HR></TD></TR></TABLE><!-- BBCode Quote End -->";
		$message = str_replace($quote_start_html, "[quote]", $message);
		$message = str_replace($quote_end_html, "[/quote]", $message);
		
		// Undo [b] and [i]
		$message = preg_replace("#<!-- BBCode Start --><B>(.*?)</B><!-- BBCode End -->#s", "[b]\\1[/b]", $message);
		$message = preg_replace("#<!-- BBCode Start --><I>(.*?)</I><!-- BBCode End -->#s", "[i]\\1[/i]", $message);
		
		// Undo [url] (long form)
		$message = preg_replace("#<!-- BBCode URL Start --><A HREF=\"(.*?)\" TARGET=\"_blank\" REL=\"noopener noreferrer\">(.*?)</A><!-- BBCode URL End -->#s", "[url=\\1]\\2[/url]", $message);
		
		// Undo [url] (short form)
		$message = preg_replace("#<!-- BBCode u1 Start --><A HREF=\"([a-z]+?://)(.*?)\" TARGET=\"_blank\">(.*?)</A><!-- BBCode u1 End -->#s", "[url]\\3[/url]", $message);
		
		// Undo [email]
		$message = preg_replace("#<!-- BBCode Start --><A HREF=\"mailto:(.*?)\">(.*?)</A><!-- BBCode End -->#s", "[email]\\1[/email]", $message);
		
		// Undo [img]
		$message = preg_replace("#<!-- BBCode Start --><IMG SRC=\"(.*?)\" BORDER=\"0\" ALT=\"\"><!-- BBCode End -->#s", "[img]\\1[/img]", $message);
		
		// Undo lists (unordered/ordered)
	
		// <li> tags:
		$message = str_replace("<!-- BBCode --><LI>", "[*]", $message);
		
		// [list] tags:
		$message = str_replace("<!-- BBCode ulist Start --><UL>", "[list]", $message);
		
		// [list=x] tags:
		$message = preg_replace("#<!-- BBCode olist Start --><OL TYPE=([A1])>#si", "[list=\\1]", $message);
		
		// [/list] tags:
		$message = str_replace("</UL><!-- BBCode ulist End -->", "[/list]", $message);
		$message = str_replace("</OL><!-- BBCode olist End -->", "[/list]", $message);

		return($message);
}
/**
 * James Atkinson - Feb 5, 2001
 * This function does exactly what the PHP4 function array_push() does
 * however, to keep phpBB compatable with PHP 3 we had to come up with out own 
 * method of doing it.
 */
function bbcode_array_push(&$stack, $value) {
   $stack[] = $value;
   return(sizeof($stack));
}

/**
 * James Atkinson - Feb 5, 2001
 * This function does exactly what the PHP4 function array_pop() does
 * however, to keep phpBB compatable with PHP 3 we had to come up with out own
 * method of doing it.
 */
function bbcode_array_pop(&$stack) {
	   return array_pop($stack);
}

/**
 * Nathan Codding - Jan. 12, 2001.
 * Performs [quote][/quote] bbencoding on the given string, and returns the results.
 * Any unmatched "[quote]" or "[/quote]" token will just be left alone. 
 * This works fine with both having more than one quote in a message, and with nested quotes.
 * Since that is not a regular language, this is actually a PDA and uses a stack. Great fun.
 *
 * Note: This function assumes the first character of $message is a space, which is added by 
 * bbencode().
 */
function bbencode_quote($message)
{
	// First things first: If there aren't any "[quote]" strings in the message, we don't
	// need to process it at all.
	
	if (!strpos(strtolower($message), "[quote]"))
	{
		return $message;	
	}
	
	$stack = Array();
	$curr_pos = 1;
	while ($curr_pos && ($curr_pos < strlen($message)))
	{	
		$curr_pos = strpos($message, "[", $curr_pos);
	
		// If not found, $curr_pos will be 0, and the loop will end.
		if ($curr_pos)
		{
			// We found a [. It starts at $curr_pos.
			// check if it's a starting or ending quote tag.
			$possible_start = substr($message, $curr_pos, 7);
			$possible_end = substr($message, $curr_pos, 8);
			if (strcasecmp("[quote]", $possible_start) == 0)
			{
				// We have a starting quote tag.
				// Push its position on to the stack, and then keep going to the right.
				bbcode_array_push($stack, $curr_pos);
				++$curr_pos;
			}
			else if (strcasecmp("[/quote]", $possible_end) == 0)
			{
				// We have an ending quote tag.
				// Check if we've already found a matching starting tag.
				if (sizeof($stack) > 0)
				{
					// There exists a starting tag. 
					// We need to do 2 replacements now.
					$start_index = bbcode_array_pop($stack);

					// everything before the [quote] tag.
					$before_start_tag = substr($message, 0, $start_index);

					// everything after the [quote] tag, but before the [/quote] tag.
					$between_tags = substr($message, $start_index + 7, $curr_pos - $start_index - 7);

					// everything after the [/quote] tag.
					$after_end_tag = substr($message, $curr_pos + 8);

					$message = $before_start_tag . "<!-- BBCode Quote Start --><TABLE BORDER=0 ALIGN=CENTER WIDTH=85%><TR><TD><font size=-1>Quote:</font><HR></TD></TR><TR><TD><FONT SIZE=-1><BLOCKQUOTE>";
					$message .= $between_tags . "</BLOCKQUOTE></FONT></TD></TR><TR><TD><HR></TD></TR></TABLE><!-- BBCode Quote End -->";
					$message .= $after_end_tag;
					
					// Now.. we've screwed up the indices by changing the length of the string. 
					// So, if there's anything in the stack, we want to resume searching just after it.
					// otherwise, we go back to the start.
					if (sizeof($stack) > 0)
					{
						$curr_pos = bbcode_array_pop($stack);
						bbcode_array_push($stack, $curr_pos);
						++$curr_pos;
					}
					else
					{
						$curr_pos = 1;
					}
				}
				else
				{
					// No matching start tag found. Increment pos, keep going.
					++$curr_pos;	
				}
			}
			else
			{
				// No starting tag or ending tag.. Increment pos, keep looping.,
				++$curr_pos;	
			}
		}
	} // while
	
	return $message;
	
} // bbencode_quote()


/**
 * Nathan Codding - Jan. 12, 2001.
 * Performs [code][/code] bbencoding on the given string, and returns the results.
 * Any unmatched "[code]" or "[/code]" token will just be left alone. 
 * This works fine with both having more than one code block in a message, and with nested code blocks.
 * Since that is not a regular language, this is actually a PDA and uses a stack. Great fun.
 *
 * Note: This function assumes the first character of $message is a space, which is added by 
 * bbencode().
 */
function bbencode_code($message, $is_html_disabled)
{
	// First things first: If there aren't any "[code]" strings in the message, we don't
	// need to process it at all.
	if (!strpos(strtolower($message), "[code]"))
	{
		return $message;	
	}
	
	// Second things second: we have to watch out for stuff like [1code] or [/code1] in the 
	// input.. So escape them to [#1code] or [/code#1] for now:
	$message = preg_replace("/\[([0-9]+?)code\]/si", "[#\\1code]", $message);
	$message = preg_replace("/\[\/code([0-9]+?)\]/si", "[/code#\\1]", $message);
	
	$stack = Array();
	$curr_pos = 1;
	$max_nesting_depth = 0;
	while ($curr_pos && ($curr_pos < strlen($message)))
	{	
		$curr_pos = strpos($message, "[", $curr_pos);
	
		// If not found, $curr_pos will be 0, and the loop will end.
		if ($curr_pos)
		{
			// We found a [. It starts at $curr_pos.
			// check if it's a starting or ending code tag.
			$possible_start = substr($message, $curr_pos, 6);
			$possible_end = substr($message, $curr_pos, 7);
			if (strcasecmp("[code]", $possible_start) == 0)
			{
				// We have a starting code tag.
				// Push its position on to the stack, and then keep going to the right.
				bbcode_array_push($stack, $curr_pos);
				++$curr_pos;
			}
			else if (strcasecmp("[/code]", $possible_end) == 0)
			{
				// We have an ending code tag.
				// Check if we've already found a matching starting tag.
				if (sizeof($stack) > 0)
				{
					// There exists a starting tag. 
					$curr_nesting_depth = sizeof($stack);
					$max_nesting_depth = ($curr_nesting_depth > $max_nesting_depth) ? $curr_nesting_depth : $max_nesting_depth;
					
					// We need to do 2 replacements now.
					$start_index = bbcode_array_pop($stack);

					// everything before the [code] tag.
					$before_start_tag = substr($message, 0, $start_index);

					// everything after the [code] tag, but before the [/code] tag.
					$between_tags = substr($message, $start_index + 6, $curr_pos - $start_index - 6);

					// everything after the [/code] tag.
					$after_end_tag = substr($message, $curr_pos + 7);

					$message = $before_start_tag . "[" . $curr_nesting_depth . "code]";
					$message .= $between_tags . "[/code" . $curr_nesting_depth . "]";
					$message .= $after_end_tag;
					
					// Now.. we've screwed up the indices by changing the length of the string. 
					// So, if there's anything in the stack, we want to resume searching just after it.
					// otherwise, we go back to the start.
					if (sizeof($stack) > 0)
					{
						$curr_pos = bbcode_array_pop($stack);
						bbcode_array_push($stack, $curr_pos);
						++$curr_pos;
					}
					else
					{
						$curr_pos = 1;
					}
				}
				else
				{
					// No matching start tag found. Increment pos, keep going.
					++$curr_pos;	
				}
			}
			else
			{
				// No starting tag or ending tag.. Increment pos, keep looping.,
				++$curr_pos;	
			}
		}
	} // while
	
	if ($max_nesting_depth > 0)
	{
		for ($i = 1; $i <= $max_nesting_depth; ++$i)
		{
			$start_tag = escape_slashes(preg_quote("[" . $i . "code]"));
			$end_tag = escape_slashes(preg_quote("[/code" . $i . "]"));
			
			$match_count = preg_match_all("/$start_tag(.*?)$end_tag/si", $message, $matches);
	
			for ($j = 0; $j < $match_count; $j++)
			{
				$before_replace = escape_slashes(preg_quote($matches[1][$j]));
				$after_replace = $matches[1][$j];
				
				if (($i < 2) && !$is_html_disabled)
				{
					// don't escape special chars when we're nested, 'cause it was already done
					// at the lower level..
					// also, don't escape them if HTML is disabled in this post. it'll already be done
					// by the posting routines.
					$after_replace = htmlspecialchars($after_replace);	
				}
				
				$str_to_match = $start_tag . $before_replace . $end_tag;
				
				$message = preg_replace("/$str_to_match/si", "<!-- BBCode Start --><TABLE BORDER=0 ALIGN=CENTER WIDTH=85%><TR><TD><font size=-1>Code:</font><HR></TD></TR><TR><TD><FONT SIZE=-1><PRE>$after_replace</PRE></FONT></TD></TR><TR><TD><HR></TD></TR></TABLE><!-- BBCode End -->", $message);
			}
		}
	}
	
	// Undo our escaping from "second things second" above..
	$message = preg_replace("/\[#([0-9]+?)code\]/si", "[\\1code]", $message);
	$message = preg_replace("/\[\/code#([0-9]+?)\]/si", "[/code\\1]", $message);
	
	return $message;
	
} // bbencode_code()


/**
 * Nathan Codding - Jan. 12, 2001.
 * Performs [list][/list] and [list=?][/list] bbencoding on the given string, and returns the results.
 * Any unmatched "[list]" or "[/list]" token will just be left alone. 
 * This works fine with both having more than one list in a message, and with nested lists.
 * Since that is not a regular language, this is actually a PDA and uses a stack. Great fun.
 *
 * Note: This function assumes the first character of $message is a space, which is added by 
 * bbencode().
 */
function bbencode_list($message)
{		
	$start_length = Array();
	$start_length[ordered] = 8;
	$start_length[unordered] = 6;
	
	// First things first: If there aren't any "[list" strings in the message, we don't
	// need to process it at all.
	
	if (!strpos(strtolower($message), "[list"))
	{
		return $message;	
	}
	
	$stack = Array();
	$curr_pos = 1;
	while ($curr_pos && ($curr_pos < strlen($message)))
	{	
		$curr_pos = strpos($message, "[", $curr_pos);
	
		// If not found, $curr_pos will be 0, and the loop will end.
		if ($curr_pos)
		{
			// We found a [. It starts at $curr_pos.
			// check if it's a starting or ending list tag.
			$possible_ordered_start = substr($message, $curr_pos, $start_length[ordered]);
			$possible_unordered_start = substr($message, $curr_pos, $start_length[unordered]);
			$possible_end = substr($message, $curr_pos, 7);
			if (strcasecmp("[list]", $possible_unordered_start) == 0)
			{
				// We have a starting unordered list tag.
				// Push its position on to the stack, and then keep going to the right.
				bbcode_array_push($stack, array($curr_pos, ""));
				++$curr_pos;
			}
			else if (preg_match("/\[list=([a1])\]/si", $possible_ordered_start, $matches))
			{
				// We have a starting ordered list tag.
				// Push its position on to the stack, and the starting char onto the start
				// char stack, the keep going to the right.
				bbcode_array_push($stack, array($curr_pos, $matches[1]));
				++$curr_pos;
			}
			else if (strcasecmp("[/list]", $possible_end) == 0)
			{
				// We have an ending list tag.
				// Check if we've already found a matching starting tag.
				if (sizeof($stack) > 0)
				{
					// There exists a starting tag. 
					// We need to do 2 replacements now.
					$start = bbcode_array_pop($stack);
					$start_index = $start[0];
					$start_char = $start[1];
					$is_ordered = ($start_char != "");
					$start_tag_length = ($is_ordered) ? $start_length[ordered] : $start_length[unordered];
					
					// everything before the [list] tag.
					$before_start_tag = substr($message, 0, $start_index);

					// everything after the [list] tag, but before the [/list] tag.
					$between_tags = substr($message, $start_index + $start_tag_length, $curr_pos - $start_index - $start_tag_length);
					// Need to replace [*] with <LI> inside the list.
					$between_tags = str_replace("[*]", "<!-- BBCode --><LI>", $between_tags);
					
					// everything after the [/list] tag.
					$after_end_tag = substr($message, $curr_pos + 7);

					if ($is_ordered)
					{
						$message = $before_start_tag . "<!-- BBCode olist Start --><OL TYPE=" . $start_char . ">";
						$message .= $between_tags . "</OL><!-- BBCode olist End -->";
					}
					else
					{
						$message = $before_start_tag . "<!-- BBCode ulist Start --><UL>";
						$message .= $between_tags . "</UL><!-- BBCode ulist End -->";
					}
					
					$message .= $after_end_tag;
					
					// Now.. we've screwed up the indices by changing the length of the string. 
					// So, if there's anything in the stack, we want to resume searching just after it.
					// otherwise, we go back to the start.
					if (sizeof($stack) > 0)
					{
						$a = bbcode_array_pop($stack);
						$curr_pos = $a[0];
						bbcode_array_push($stack, $a);
						++$curr_pos;
					}
					else
					{
						$curr_pos = 1;
					}
				}
				else
				{
					// No matching start tag found. Increment pos, keep going.
					++$curr_pos;	
				}
			}
			else
			{
				// No starting tag or ending tag.. Increment pos, keep looping.,
				++$curr_pos;	
			}
		}
	} // while
	
	return $message;
	
} // bbencode_list()



/**
 * Nathan Codding - Oct. 30, 2000
 *
 * Escapes the "/" character with "\/". This is useful when you need
 * to stick a runtime string into a PREG regexp that is being delimited 
 * with slashes.
 */
function escape_slashes($input)
{
	$output = str_replace('/', '\/', $input);
	return $output;
}

/*
 * Returns the name of the forum based on ID number
 */
function get_forum_name($forum_id, $db) {
	$sql = "SELECT forum_name FROM forums WHERE forum_id = ?";
	if(!$r = db_query_params($sql, array((int) $forum_id), $db))
		return("ERROR");
	if(!$m = db_fetch_array($r))
		return("None");
	return($m[forum_name]);
}


/**
 * Rewritten by Nathan Codding - Feb 6, 2001.
 * - Goes through the given string, and replaces xxxx://yyyy with an HTML <a> tag linking
 * 	to that URL
 * - Goes through the given string, and replaces www.xxxx.yyyy[zzzz] with an HTML <a> tag linking
 * 	to http://www.xxxx.yyyy[/zzzz] 
 * - Goes through the given string, and replaces xxxx@yyyy with an HTML mailto: tag linking
 *		to that email address
 * - Only matches these 2 patterns either after a space, or at the beginning of a line
 *
 * Notes: the email one might get annoying - it's easy to make it more restrictive, though.. maybe
 * have it require something like xxxx@yyyy.zzzz or such. We'll see.
 */

function make_clickable($text) {
	
	// pad it with a space so we can match things at the start of the 1st line.
	$ret = " " . $text;
	
	// matches an "xxxx://yyyy" URL at the start of a line, or after a space.
	// xxxx can only be alpha characters.
	// yyyy is anything up to the first space, newline, or comma.
	$ret = preg_replace_callback("#([\n ])(https?://[^, \n\r]+)#i", function($matches) {
		$url = html_web_url($matches[2]);
		if ($url === '#') {
			return $matches[0];
		}
		return $matches[1] . '<!-- BBCode auto-link start --><a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $matches[2] . '</a><!-- BBCode auto-link end -->';
	}, $ret);
	
	// matches a "www.xxxx.yyyy[/zzzz]" kinda lazy URL thing
	// Must contain at least 2 dots. xxxx contains either alphanum, or "-"
	// yyyy contains either alphanum, "-", or "."
	// zzzz is optional.. will contain everything up to the first space, newline, or comma.
	// This is slightly restrictive - it's not going to match stuff like "forums.foo.com"
	// This is to keep it from getting annoying and matching stuff that's not meant to be a link.
	$ret = preg_replace_callback("#([\n ])(www\.[a-z0-9\-]+\.[a-z0-9\-.~]+(?:/[^, \n\r]*)?)#i", function($matches) {
		$url = html_web_url('http://' . html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML401, 'UTF-8'));
		if ($url === '#') {
			return $matches[0];
		}
		return $matches[1] . '<!-- BBCode auto-link start --><a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $matches[2] . '</a><!-- BBCode auto-link end -->';
	}, $ret);
	
	// matches an email@domain type address at the start of a line, or after a space.
	// Note: before the @ sign, the only valid characters are the alphanums and "-", "_", or ".".
	// After the @ sign, we accept anything up to the first space, linebreak, or comma.
	$ret = preg_replace_callback("#([\n ])([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})#i", function($matches) {
		$url = html_email_url($matches[2]);
		if ($url === '#') {
			return $matches[0];
		}
		return $matches[1] . '<!-- BBcode auto-mailto start --><a href="' . $url . '">' . $matches[2] . '</a><!-- BBCode auto-mailto end -->';
	}, $ret);
	
	// Remove our padding..
	$ret = substr($ret, 1);
	
	return($ret);
}


/**
 * Nathan Codding - Feb 6, 2001
 * Reverses the effects of make_clickable(), for use in editpost.
 * - Does not distinguish between "www.xxxx.yyyy" and "http://aaaa.bbbb" type URLs.
 *
 */
 
function undo_make_clickable($text) {
	
	$text = preg_replace("#<!-- BBCode auto-link start --><a href=\"(.*?)\" target=\"_blank\" rel=\"noopener noreferrer\">.*?</a><!-- BBCode auto-link end -->#i", "\\1", $text);
	$text = preg_replace("#<!-- BBcode auto-mailto start --><a href=\"mailto:(.*?)\">.*?</a><!-- BBCode auto-mailto end -->#i", "\\1", $text);
	
	return $text;
	
}



/**
 * Nathan Codding - August 24, 2000.
 * Takes a string, and does the reverse of the PHP standard function
 * htmlspecialchars().
 */
function undo_htmlspecialchars($input) {
	$input = preg_replace("/&gt;/i", ">", $input);
	$input = preg_replace("/&lt;/i", "<", $input);
	$input = preg_replace("/&quot;/i", "\"", $input);
	$input = preg_replace("/&amp;/i", "&", $input);
	
	return $input;
}
/*
 * Make sure a username isn't on the disallow list
 */
function validate_username($username, $db) {
	$sql = "SELECT disallow_username FROM disallow WHERE disallow_username = ?";
	if(!$r = db_query_params($sql, array($username), $db))
		return(0);
	if($m = db_fetch_array($r)) {
		if($m[disallow_username] == $username)
			return(1);
		else
			return(0);
	}
	return(0);
}
/*
 * Check if this is the first post in a topic. Used in editpost.php
 */
function is_first_post($topic_id, $post_id, $db) {
   $sql = "SELECT post_id FROM posts WHERE topic_id = ? ORDER BY post_id LIMIT 1";
   if(!$r = db_query_params($sql, array((int) $topic_id), $db))
     return(0);
   if(!$m = db_fetch_array($r))
     return(0);
   if($m[post_id] == $post_id)
     return(1);
   else
     return(0);
}

/*
 * Replaces banned words in a string with their replacements
 */
function censor_string($string, $db) {
   $sql = "SELECT word, replacement FROM words";
   if(!$r = db_query($sql, $db))
      die("Error, could not contact the database! Please check your database settings in config.$phpEx");
   while($w = db_fetch_array($r)) {
      $word = quotemeta(stripslashes($w[word]));
      $replacement = stripslashes($w[replacement]);
	      $string = preg_replace("/ $word/i", $replacement, $string);
	      $string = preg_replace("/^$word/i", $replacement, $string);
	      $string = preg_replace("/<BR>$word/i", "<BR>$replacement", $string);
   }
   return($string);
}

function is_banned($ipuser, $type, $db) {
   
   // Remove old bans
   $sql = "DELETE FROM banlist WHERE (ban_end < ?) AND (ban_end > 0)";
   @db_query_params($sql, array(time()), $db);
   
   switch($type) {
    case "ip":
      $sql = "SELECT ban_ip FROM banlist";
      if($r = db_query($sql, $db)) {
	 while($iprow = db_fetch_array($r)) {
	    $ip = $iprow[ban_ip];
	    if($ip[strlen($ip) - 1] == ".") {
	       $db_ip = explode(".", $ip);
	       $this_ip = explode(".", $ipuser);
	       
	       for($x = 0; $x < count($db_ip) - 1; $x++) 
		 $my_ip .= $this_ip[$x] . ".";

	       if($my_ip == $ip)
		 return(TRUE);
	    }
	    else {
	       if($ipuser == $ip)
		 return(TRUE);
	    }
	 }
      }
      else 
	return(FALSE);
      break;
    case "username":
      $sql = "SELECT ban_userid FROM banlist WHERE ban_userid = ?";
      if($r = db_query_params($sql, array((int) $ipuser), $db)) {
	 if(db_num_rows($r) > 0)
	   return(TRUE);
      }
      break;
   }
   
   return(FALSE);
}

/**
 * Checks if the given userid is allowed to log into the given (private) forumid.
 * If the "is_posting" flag is true, checks if the user is allowed to post to that forum.
 */
function check_priv_forum_auth($userid, $forumid, $is_posting, $db)
{
	$private_userdata = get_userdata_from_id((int) $userid, $db);
	return forum_user_has_private_forum_access(
		$private_userdata,
		(int) $forumid,
		(bool) $is_posting,
		$db,
		(int) ($private_userdata['user_id'] ?? 0) > 0
	);
}

/**
 * Displays an error message and exits the script. Used in the posting files.
 */
function error_die($msg){
	global $tablewidth, $table_bgcolor, $color1;
	global $phpEx;
	global $db, $userdata, $user_logged_in;
	global $FontFace, $FontSize3, $textcolor, $phpbbversion;
	global $starttime;
	print("<br>
		<TABLE BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\" ALIGN=\"CENTER\" VALIGN=\"TOP\" WIDTH=\"$tablewidth\">
		<TR><TD BGCOLOR=\"$table_bgcolor\">
			<TABLE BORDER=\"0\" CALLPADDING=\"1\" CELLSPACEING=\"1\" WIDTH=\"100%\">
			<TR BGCOLOR=\"$color1\" ALIGN=\"LEFT\">
				<TD>
					<p><font face=\"Verdana\" size=\"2\"><ul>$msg</ul></font></P>
				</TD>
			</TR>
			</TABLE>
		</TD></TR>
	 	</TABLE>
	 <br>");
	 include('page_tail.'.$phpEx);
	 exit;
}

function make_jumpbox(){
global $phpEx, $db;
global $FontFace, $FontSize2, $textcolor;
global $l_jumpto, $l_selectforum, $l_go;

	?>
	<FORM ACTION="viewforum.<?php echo $phpEx?>" METHOD="GET">
	<SELECT NAME="forum"><OPTION VALUE="-1"><?php echo $l_selectforum?></OPTION>
	<?php
	  $sql = "SELECT cat_id, cat_title FROM catagories ORDER BY cat_order";
	if($result = db_query($sql, $db)) {
	   $myrow = db_fetch_array($result);
	   do {
	      echo "<OPTION VALUE=\"-1\">&nbsp;</OPTION>\n";
	      echo '<OPTION VALUE="-1">' . html_escape($myrow[cat_title]) . "</OPTION>\n";
	      echo "<OPTION VALUE=\"-1\">----------------</OPTION>\n";
	      $sub_sql = "SELECT forum_id, forum_name FROM forums WHERE cat_id = ? ORDER BY forum_id";
	      if($res = db_query_params($sub_sql, array((int) $myrow[cat_id]), $db)) {
	    if($row = db_fetch_array($res)) {
	       do {
		  $name = stripslashes($row[forum_name]);
		  echo '<OPTION VALUE="' . (int) $row[forum_id] . '">' . html_escape($name) . "</OPTION>\n";
	       } while($row = db_fetch_array($res));
	    }
	    else {
	       echo "<OPTION VALUE=\"0\">No More Forums</OPTION>\n";
	    }
	      }
	      else {
	    echo "<OPTION VALUE=\"0\">Error Connecting to DB</OPTION>\n";
	      }
	   } while($myrow = db_fetch_array($result));
	}
	else {
	   echo "<OPTION VALUE=\"-1\">ERROR</OPTION>\n";
	}
	echo "</SELECT>\n<INPUT TYPE=\"SUBMIT\" VALUE=\"$l_go\">\n</FORM>";
}

function language_select($default, $name="language", $dirname="language/"){
global $phpEx;
	$dir = opendir($dirname);
	$lang_select = "<SELECT NAME=\"$name\">\n";
	while ($file = readdir($dir)) {
		if (str_starts_with($file, "lang_")) {
			$file = str_replace("lang_", "", $file);
			$file = str_replace(".$phpEx", "", $file);
			if (!forum_valid_language($file)) {
				continue;
			}
			$file == $default ? $selected = " SELECTED" : $selected = "";
			$lang_select .= "  <OPTION$selected>$file\n";
		}
	}
	$lang_select .= "</SELECT>\n";
	closedir($dir);
	return $lang_select;

}

function get_translated_file($file){
	global $default_lang;
	
	// Try adding -default_lang to the filename. i.e.:
	// reply.jpg  becomes something like  reply-nederlands.jpg
	$trans_file = preg_replace("/(.*)(\..*?)/", "\\1-$default_lang\\2", $file);
	if(is_file($trans_file)){
		return $trans_file;
	} else {
		return $file;
	}
}

function get_syslang_string($sys_lang, $string) {
	global $phpEx, $username, $password, $sitename, $email_sig, $hot_threshold;
	$username = $username ?? '';
	$password = $password ?? '';
	$sitename = $sitename ?? '';
	$email_sig = $email_sig ?? '';
	$hot_threshold = $hot_threshold ?? 0;
	if (!forum_valid_language($sys_lang)) {
		$sys_lang = 'english';
	}
	include('language/lang_'.$sys_lang.'.'.$phpEx);
	$ret_string = isset($$string) ? $$string : '';
	return($ret_string);
}


/**
 * Translates any sequence of whitespace (\t, \r, \n, or space) in the given
 * string into a single space character.
 * Returns the result.
 */
function normalize_whitespace($str)
{
	$output = "";
	
	$tok = preg_split("/[ \t\r\n]+/", $str);
	$tok_count = sizeof($tok);
	for ($i = 0; $i < ($tok_count - 1); $i++)
	{
		$output .= $tok[$i] . " ";
	}
	
	$output .= $tok[$tok_count - 1];
      
	return $output;
}

function sync($db, $id, $type) {
	switch($type) {
		case 'forum':
			$sql = "SELECT max(post_id) AS last_post FROM posts WHERE forum_id = ?";
			if(!$result = db_query_params($sql, array((int) $id), $db))
   		{
   			die("Could not get post ID");
   		}
   		if($row = db_fetch_array($result))
   		{
   			$last_post = $row["last_post"];
   		}
   		
			$sql = "SELECT count(post_id) AS total FROM posts WHERE forum_id = ?";
			if(!$result = db_query_params($sql, array((int) $id), $db))
   		{
   			die("Could not get post count");
   		}
   		if($row = db_fetch_array($result))
   		{
   			$total_posts = $row["total"];
   		}
   		
			$sql = "SELECT count(topic_id) AS total FROM topics WHERE forum_id = ?";
			if(!$result = db_query_params($sql, array((int) $id), $db))
   		{
   			die("Could not get topic count");
   		}
   		if($row = db_fetch_array($result))
   		{
   			$total_topics = $row["total"];
   		}
   		
			$sql = "UPDATE forums SET forum_last_post_id = ?, forum_posts = ?, forum_topics = ? WHERE forum_id = ?";
			if(!$result = db_query_params($sql, array((int) $last_post, (int) $total_posts, (int) $total_topics, (int) $id), $db))
   		{
   			die("Could not update forum $id");
   		}
   	break;

   	case 'topic':
			$sql = "SELECT max(post_id) AS last_post FROM posts WHERE topic_id = ?";
			if(!$result = db_query_params($sql, array((int) $id), $db))
   		{
   			die("Could not get post ID");
   		}
   		if($row = db_fetch_array($result))
   		{
   			$last_post = $row["last_post"];
   		}
   		
			$sql = "SELECT count(post_id) AS total FROM posts WHERE topic_id = ?";
			if(!$result = db_query_params($sql, array((int) $id), $db))
   		{
   			die("Could not get post count");
   		}
   		if($row = db_fetch_array($result))
   		{
   			$total_posts = $row["total"];
   		}
   		$total_posts -= 1;
			$sql = "UPDATE topics SET topic_replies = ?, topic_last_post_id = ? WHERE topic_id = ?";
			if(!$result = db_query_params($sql, array((int) $total_posts, (int) $last_post, (int) $id), $db))
   		{
   			die("Could not update topic $id");
   		}
   	break;

   	case 'all forums':
   		$sql = "SELECT forum_id FROM forums";
   		if(!$result = db_query($sql, $db))
   		{
   			die("Could not get forum IDs");
   		}
   		while($row = db_fetch_array($result))
   		{
   			$id = $row["forum_id"];
   			sync($db, $id, "forum");
   		}
   	break;
   	case 'all topics':
   		$sql = "SELECT topic_id FROM topics";
   		if(!$result = db_query($sql, $db))
   		{
   			die("Could not get topic ID's");
   		}
   		while($row = db_fetch_array($result))
   		{
   			$id = $row["topic_id"];
   			sync($db, $id, "topic");
   		}
   	break;
   }
   return(TRUE);
}

function login_form(){
	global $TableWidth, $table_bgcolor, $color1, $color2, $textcolor;
	global $FontFace, $FontSize2;
	global $phpEx, $userdata, $PHP_SELF;
	global $l_userpass, $l_username, $l_password, $l_passwdlost, $l_submit;
	global $mode, $msgid;

?>
<FORM ACTION="<?php echo $PHP_SELF?>" METHOD="POST">
<TABLE BORDER="0" CELLPADDING="1" CELLSPACING="0" ALIGN="CENTER" VALIGN="TOP">
<TR><TD BGCOLOR="<?php echo $table_bgcolor?>">
<TABLE BORDER="0" CELLPADDING="10" CELLSPACING="1" WIDTH="100%">
	<TR BGCOLOR="<?php echo $color1?>" ALIGN="CENTER">
  		<TD COLSPAN="2">
			<FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
  			<b><?php echo $l_userpass?></b>
			</FONT>
			<br>
		</TD>
	</TR><TR BGCOLOR="<?php echo $color2?>">
		<TD>
			<FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
			<b><?php echo $l_username?>: &nbsp;</b></font>
			</FONT>
		</TD>
		<TD>
			<INPUT TYPE="TEXT" NAME="user" SIZE="25" MAXLENGTH="40" VALUE="<?php echo html_escape($userdata[username])?>">
		</TD>
	</TR><TR BGCOLOR="<?php echo $color2?>">
		<TD>
			<FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
			<b><?php echo $l_password?>: </b>
			</FONT>
		</TD><TD>
			<INPUT TYPE="PASSWORD" NAME="passwd" SIZE="25" MAXLENGTH="255">
		</TD>
	</TR><TR BGCOLOR="<?php echo $color2?>">
		<TD COLSPAN="2" ALIGN="CENTER">
			<FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
			<a href="sendpassword.<?php echo $phpEx?>"><?php echo $l_passwdlost?></a><br><br>
			</FONT>
			<?php
			if (isset($mode))
			{ 
			?>
				<INPUT TYPE="HIDDEN" NAME="mode" VALUE="<?php echo $mode?>">
			<?php		
			}
			?>
			<?php
			// Need to pass through the msgid for deleting private messages.
			if (isset($msgid))
			{ 
			?>
				<INPUT TYPE="HIDDEN" NAME="msgid" VALUE="<?php echo $msgid?>">
			<?php		
			}
			?>
			<INPUT TYPE="SUBMIT" NAME="submit" VALUE="<?php echo $l_submit?>">
		</TD>
	</TR>
</TABLE>
</TD></TR>
</TABLE>
</FORM>


<?php
}

/**
 * Less agressive version of stripslashes. Only replaces \\ \' and \"
 * The PHP stripslashes() also removed single backslashes from the string.
 * Expects a string or array as an argument.
 * Returns the result.
 */
function own_stripslashes($string)
{
   $find = array(
            '/\\\\\'/',  // \\\'
            '/\\\\/',    // \\
				'/\\\'/',    // \'
            '/\\\"/');   // \"
   $replace = array(
            '\'',   // \
            '\\',   // \
            '\'',   // '
            '"');   // "
            
   // preg_replace will throw a warning on PHP3 so we have to @ it.
   return @preg_replace($find, $replace, $string);
}

?>
