<?php
/***************************************************************************
 * Password reset, retaining the original phpBB presentation.
 ***************************************************************************/
include('extention.inc');
include('functions.'.$phpEx);
include('config.'.$phpEx);
require('auth.'.$phpEx);

$token = request_string('token', '', 'get');
$posted_token = request_string('token', '', 'post');
$submit = request_string('submit', '', 'post');
$reset = request_string('reset', '', 'post');
$user = request_string('user', '', 'post');
$email = request_string('email', '', 'post');
$new_password = request_string('new_password', '', 'post');
$new_password_confirm = request_string('new_password_confirm', '', 'post');
$pagetype = 'other';
$pagetitle = 'Reset Password';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
include('page_header.'.$phpEx);

function password_reset_panel($title, $body) {
	global $TableWidth, $table_bgcolor, $color1, $color2;
	echo '<TABLE BORDER="0" WIDTH="' . html_escape($TableWidth) . '" CELLPADDING="1" CELLSPACING="0" ALIGN="CENTER" VALIGN="TOP">';
	echo '<TR><TD BGCOLOR="' . html_escape($table_bgcolor) . '"><TABLE BORDER="0" CELLPADDING="1" CELLSPACING="1" WIDTH="100%">';
	echo '<TR BGCOLOR="' . html_escape($color1) . '" ALIGN="LEFT"><TD ALIGN="CENTER"><b>' . html_escape($title) . '</b></TD></TR>';
	echo '<TR ALIGN="LEFT"><TD BGCOLOR="' . html_escape($color2) . '">' . $body . '</TD></TR></TABLE></TD></TR></TABLE>';
}

if ($reset !== '') {
	if (preg_match('/^[a-f0-9]{64}$/D', $posted_token) !== 1) {
		error_die('This password reset link is invalid or has expired.');
	}
	if ($new_password !== $new_password_confirm) {
		error_die($l_mismatch . ' ' . $l_tryagain);
	}
	if ($password_error = forum_password_error($new_password)) {
		error_die($password_error . ' ' . $l_tryagain);
	}

	$digest = hash('sha256', $posted_token);
	$sql = 'SELECT user_id FROM users WHERE user_reset_token = ? AND user_reset_expires >= ?';
	if (!$result = db_query_params($sql, array($digest, time()), $db)) {
		error_die('Error while attempting to query the database.');
	}
	$row = db_fetch_array($result);
	if (!$row) {
		error_die('This password reset link is invalid or has expired.');
	}

	$sql = 'UPDATE users SET user_password = ?, user_reset_token = NULL, user_reset_expires = NULL WHERE user_id = ? AND user_reset_token = ? AND user_reset_expires >= ?';
	$update = db_query_params($sql, array(forum_hash_password($new_password), (int) $row['user_id'], $digest, time()), $db);
	if (!$update) {
		error_die('Error while attempting to update the password.');
	}
	if (db_affected_rows($update) !== 1) {
		error_die('This password reset link is invalid or has expired.');
	}
	end_all_user_sessions((int) $row['user_id'], $db);
	password_reset_panel($l_password, 'Your password has been changed. You may now log in with the new password.');
}
else if ($token !== '') {
	if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
		error_die('This password reset link is invalid or has expired.');
	}
	$sql = 'SELECT user_id FROM users WHERE user_reset_token = ? AND user_reset_expires >= ?';
	$result = db_query_params($sql, array(hash('sha256', $token), time()), $db);
	if (!$result || !db_fetch_array($result)) {
		error_die('This password reset link is invalid or has expired.');
	}
	$body = '<FORM ACTION="' . html_escape($PHP_SELF) . '" METHOD="POST">';
	$body .= '<INPUT TYPE="HIDDEN" NAME="token" VALUE="' . html_escape($token) . '">';
	$body .= '<TABLE BORDER="0" CELLPADDING="2" CELLSPACING="1" WIDTH="100%">';
	$body .= '<TR><TD>New password:</TD><TD><INPUT TYPE="PASSWORD" NAME="new_password" SIZE="35" MAXLENGTH="255"></TD></TR>';
	$body .= '<TR><TD>Confirm password:</TD><TD><INPUT TYPE="PASSWORD" NAME="new_password_confirm" SIZE="35" MAXLENGTH="255"></TD></TR>';
	$body .= '<TR><TD COLSPAN="2" ALIGN="CENTER"><INPUT TYPE="SUBMIT" NAME="reset" VALUE="Change Password"></TD></TR></TABLE></FORM>';
	password_reset_panel($l_password, $body);
}
else if ($submit !== '') {
	$sql = 'SELECT user_id, username, user_email FROM users WHERE username = ? AND user_email = ? AND user_level != -1';
	$result = db_query_params($sql, array($user, $email), $db);
	$checkinfo = $result ? db_fetch_array($result) : false;
	if ($checkinfo) {
		$raw_token = bin2hex(random_bytes(32));
		$digest = hash('sha256', $raw_token);
		$expires = time() + $password_reset_lifetime;
		$sql = 'UPDATE users SET user_reset_token = ?, user_reset_expires = ? WHERE user_id = ?';
		if (!db_query_params($sql, array($digest, $expires, (int) $checkinfo['user_id']), $db)) {
			error_die('An error occurred while trying to update the database.');
		}
		$reset_url = forum_public_url('sendpassword.' . $phpEx . '?token=' . $raw_token);
		$message = "Dear " . $checkinfo['username'] . ",\n\n";
		$message .= "A password reset was requested for your account. Use this one-time link within " . (intdiv($password_reset_lifetime, 60)) . " minutes:\n";
		$message .= $reset_url . "\n\nIf you did not request this, you can ignore this message.\n";
		forum_send_mail($checkinfo['user_email'], $l_passsubj, $message, $email_from);
	}
	password_reset_panel($l_password, 'If the supplied details match an account, a password reset link has been sent.');
}
else {
	$body = '<FORM ACTION="' . html_escape($PHP_SELF) . '" METHOD="POST">';
	$body .= '<TABLE BORDER="0" CELLPADDING="1" CELLSPACING="1" WIDTH="100%">';
	$body .= '<TR><TD COLSPAN="2" ALIGN="CENTER">Enter your account details and a one-time password reset link will be emailed to you.</TD></TR>';
	$body .= '<TR><TD>' . html_escape($l_username) . ':</TD><TD><INPUT TYPE="TEXT" NAME="user" VALUE="' . html_escape($userdata['username']) . '" SIZE="35" MAXLENGTH="40"></TD></TR>';
	$body .= '<TR><TD>' . html_escape($l_emailaddress) . ':</TD><TD><INPUT TYPE="TEXT" NAME="email" SIZE="35" MAXLENGTH="100"></TD></TR>';
	$body .= '<TR><TD COLSPAN="2" ALIGN="CENTER"><INPUT TYPE="SUBMIT" NAME="submit" VALUE="' . html_escape($l_sendpass) . '"></TD></TR>';
	$body .= '</TABLE></FORM>';
	password_reset_panel($l_emailpass, $body);
}

include('page_tail.'.$phpEx);
?>
