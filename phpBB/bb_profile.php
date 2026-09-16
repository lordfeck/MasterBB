<?php
/***************************************************************************
                            bb_profile.php  -  description
                             -------------------
    begin                : Sat June 17 2000
    copyright            : (C) 2001 The phpBB Group
    email                : support@phpBB.com

    $Id: bb_profile.php,v 1.56 2001/08/01 00:10:55 thefinn Exp $

 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/
include('extention.inc');
include('functions.'.$phpEx);
include('config.'.$phpEx);
require('auth.'.$phpEx);
$mode = request_string('mode');
$user = request_int('user', 0, 'get');
$user_id = request_int('user_id');
$submit = request_string('submit', '', 'post');
$save = request_present('save', 'post');
$user_name = request_string('user_name', '', 'post');
$user = request_string('user', $user ? (string) $user : '', 'post');
$passwd = request_string('passwd', '', 'post');
$password = request_string('password', '', 'post');
$new_password = request_string('new_password', '', 'post');
$password2 = request_string('password2', '', 'post');
$email = request_string('email', '', 'post');
$icq = request_string('icq', '', 'post');
$aim = request_string('aim', '', 'post');
$yim = request_string('yim', '', 'post');
$msnm = request_string('msnm', '', 'post');
$website = request_string('website', '', 'post');
$from = request_string('from', '', 'post');
$occ = request_string('occ', '', 'post');
$intrest = request_string('intrest', '', 'post');
$sig = request_string('sig', '', 'post');
$viewemail = request_int('viewemail', 0, 'post');
$new_name = false;
$pagetitle = $l_profile;
$pagetype = "Edit Profile";


if($mode) {
	switch($mode) {
	 case 'view':
	   include('page_header.'.$phpEx);
	   $userdata = get_userdata_from_id($user, $db);
	   $total_posts = get_total_posts("0", $db, "all");
	   if($userdata[user_posts] != 0 && $total_posts != 0){
	     $user_percentage = $userdata[user_posts] / $total_posts * 100;
	   } else {
	     $user_percentage = 0;
	   }

	   // Calculate the number of days this user has been a member ($memberdays)
	   $regdate = strtotime($userdata[user_regdate]);
	   $memberdays = (time()-$regdate)/(24*60*60);
	   $postday = $userdata[user_posts]/$memberdays;


	   if (!$userdata[user_id]) {
	      error_die($l_nouser);
	   }
	   if($userdata[user_level] == -1) {
			error_die($l_userremoved);
	   }
	   $profile_email_url = html_email_url($userdata[user_email]);
	   $profile_website_url = html_web_url($userdata[user_website]);
?>
	<TABLE BORDER="0" CELLPADDING="1" CELLSPACING="0" ALIGN="CENTER" VALIGN="TOP" WIDTH="<?php echo $TableWidth?>"><TR><TD  BGCOLOR="<?php echo  $table_bgcolor?>">
	<TABLE BORDER="0" CELLPADDING="1" CELLSPACING="1" WIDTH="100%">
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_username?>:</FONT></b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><?php echo html_escape($userdata[username])?></FONT>
	        <font size=-2>(<a href="search.<?php echo $phpEx?>?term=&addterms=any&forum=all&search_username=<?php echo rawurlencode($userdata[username])?>&sortby=p.post_time&searchboth=both&submit=Search"><?php echo $l_viewpostuser?></a>)
			  &nbsp;&nbsp;(<a href="sendpmsg.<?php echo $phpEx?>?tousername=<?php echo rawurlencode($userdata[username])?>"><?php echo $l_sendpmsg?></a>)</font></TD>
	</TR>
	<TR ALIGN="LEFT">
                <TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_joined?>:</FONT></b></TD>
                <TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><?php printf("%s (%.2f %s)", $userdata[user_regdate], $postday, $l_perday)?></FONT></TD>
        </TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_posts?>:</FONT></b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><?php printf("%s (%.2f%% %s)", $userdata[user_posts], $user_percentage, $l_oftotal)?></FONT></TD>
	</TR>

<?php
			if($userdata[user_viewemail] == 1) {
?>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_emailaddress?>:<b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><a href="<?php echo $profile_email_url?>"><?php echo html_escape($userdata[user_email])?></a></TD>
	</TR>
<?php
			}
?>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_icq . " " .$l_number?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><?php echo html_escape($userdata[user_icq])?>&nbsp;
		</TD>

	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_aim?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><?php echo html_escape($userdata[user_aim])?></FONT>&nbsp;</TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_yahoo?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><?php echo html_escape($userdata[user_yim])?></FONT>&nbsp;</TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_messenger?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><?php echo html_escape($userdata[user_msnm])?>&nbsp;</TD>
	</TR>

	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_website?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><a href="<?php echo $profile_website_url?>" target="_blank" rel="noopener noreferrer"><?php echo html_escape($userdata[user_website])?></a></FONT>&nbsp;</TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_location?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><?php echo html_escape(stripslashes($userdata[user_from]))?>&nbsp;</TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_occupation?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><?php echo html_escape(stripslashes($userdata[user_occ]))?>&nbsp;</TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_interests?>: <b></TD>
<?php
	$userdata[user_intrest] = stripslashes($userdata[user_intrest]);
?>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><?php echo html_escape($userdata[user_intrest])?>&nbsp;</TD>
	</TR>
	</TABLE></TD></TR></TABLE>
<?php

	break;
	case 'edit':
	   if ($submit || $user_logged_in) {
	      // ok.. either the user's entered their username and password, or they have a valid session.
	      if ($save) {
		 // trying to save their profile information..
		 if (!forum_user_can_edit_profile($userdata, $user_id, $user_logged_in)) {
		    include('page_header.'.$phpEx);
		    forum_authorization_denied();
		 }
		 $userdata = get_userdata_from_id($user_id, $db);
		 if(is_banned($userdata[user_id], "username", $db))
		   error_die($l_banned);
		 if (!$userdata[user_id]) {
		    error_die($l_nouser);
		 }
		 if ($password == '') {
		    include('page_header.'.$phpEx);
		    error_die("$l_enterpassword $l_tryagain");
		 }
		 if (!forum_verify_password($password, $userdata[user_password])) {
		    include('page_header.'.$phpEx);
		    error_die("$l_wrongpass $l_tryagain");
		 }

		 $md_pass = $userdata[user_password];
		 $password_changed = false;
		 if ($new_password != '') {
		    if ($new_password != $password2)  {
		       include('page_header.'.$phpEx);
		       error_die("$l_mismatch $l_tryagain");
		    }
		    if($password_error = forum_password_error($new_password)) {
		       include('page_header.'.$phpEx);
		       error_die($password_error . ' ' . $l_tryagain);
		    }
		    $md_pass = forum_hash_password($new_password);
		    $password_changed = true;
		 }
		 // whatever the case, $md_pass contains the password for the DB.
		 // ready to save, they've authed just fine..

		 if($allow_namechange && $user_name != $userdata[username]) {
			 $user_name = strip_tags(trim(normalize_whitespace($user_name)));
		    if (check_username($user_name, $db)) {
		       error_die("$l_usertaken $l_tryagain");
		    }
		    if(validate_username($user_name, $db) == 1) {
		       include('page_header.'.$phpEx);
		       error_die("$l_userdisallowed $l_tryagain");
		    }
		    $new_name = 1;
		 }
		 $sig = rtrim($sig);
		 $passwd = $md_pass;


		 // Ensure the website URL starts with "http://".
	    $website = trim($website);
		 if(!preg_match('#^https?://#i', $website))
		   {
		      $website = "http://" . $website;
		   }

		 if($website == "http://")
		 {
		 	$website = "";
		 }

		 // Check if the ICQ number only contains digits
		 $icq = (preg_match("/^[0-9]+$/", $icq)) ? $icq : '';

		 if($new_name) {
		    $sql = "UPDATE users SET username = ?, user_password = ?, user_icq = ?, user_occ = ?, user_intrest = ?, user_from = ?, user_website = ?, user_sig = ?, user_email = ?, user_viewemail = ?, user_aim = ?, user_yim = ?, user_msnm = ? WHERE user_id = ?";
		    $profile_params = array($user_name, $md_pass, $icq, $occ, $intrest, $from, $website, $sig, $email, (int) $viewemail, $aim, $yim, $msnm, (int) $user_id);
		 }
		 else {
		    $sql = "UPDATE users SET user_password = ?, user_icq = ?, user_occ = ?, user_intrest = ?, user_from = ?, user_website = ?, user_sig = ?, user_email = ?, user_viewemail = ?, user_aim = ?, user_yim = ?, user_msnm = ? WHERE user_id = ?";
		    $profile_params = array($md_pass, $icq, $occ, $intrest, $from, $website, $sig, $email, (int) $viewemail, $aim, $yim, $msnm, (int) $user_id);
		 }
		 if(!$result = db_query_params($sql, $profile_params, $db)) {
		    error_die("Could not update userinfo in database.<br>$sql");
		 }
		 if($password_changed) {
		    end_all_user_sessions($userdata[user_id], $db);
		 }
		 // They have authed, log them in.
		 $sessid = new_session($userdata[user_id], $REMOTE_ADDR, $sesscookietime, $db);
		 set_session_cookie($sessid, $sesscookietime, $sesscookiename, $cookiepath, $cookiedomain, $cookiesecure);
		 include('page_header.'.$phpEx);
		 echo "$l_infoupdated.<br>$l_click <a href=\"index.$phpEx\">$l_here</a> $l_returnindex.";
	      } else {
		 // not trying to save, so show the form.
		 if (!$user_logged_in) {
		    // no valid session, need to check user/pass.
		    if($user == '' || $passwd == '') {
		       error_die("$l_userpass $l_tryagain");
		    }
		    $userdata = get_userdata($user, $db);
		    if(is_banned($userdata[user_id], "username", $db))
		      error_die("$l_banned");
		    if(!forum_verify_password($passwd, $userdata["user_password"] ?? '')) {
		       error_die("$l_wrongpass $l_tryagain");
		    }
		    // They have authed succecfully, log them in.
		    $sessid = new_session($userdata[user_id], $REMOTE_ADDR, $sesscookietime, $db);
		    set_session_cookie($sessid, $sesscookietime, $sesscookiename, $cookiepath, $cookiedomain, $cookiesecure);
		 }
		 include('page_header.'.$phpEx);
?>
	<FORM ACTION="<?php echo $PHP_SELF?>" METHOD="POST">
	<TABLE BORDER="0" CELLPADDING="1" CELLSPACING="0" ALIGN="CENTER" VALIGN="TOP" WIDTH="<?php echo $TableWidth?>">
	<TR><TD  BGCOLOR="<?php echo $table_bgcolor?>">
	<TABLE BORDER="0" CELLPADDING="1" CELLSPACING="1" WIDTH="100%">
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_username?>: *</FONT></b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
<?php
		if($allow_namechange) {
		   echo "<input type=\"text\" name=\"user_name\" size=\"35\" maxlength=\"40\" value=\"" . html_escape($userdata[username]) . "\">";
		}
		else {
		   echo html_escape($userdata[username]);
		}
?>
	       </FONT></TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_password?>: *</FONT></b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><INPUT TYPE="PASSWORD" NAME="password" SIZE="25" MAXLENGTH="255"></TD>
	</TR>
		<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_new ." " .$l_password?>: </FONT></b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><INPUT TYPE="PASSWORD" NAME="new_password" SIZE="25" MAXLENGTH="255"></TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_confirm . " " . $l_password?>:</b></FONT><br><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize1?>" COLOR="<?php echo $textcolor?>">(<?php echo $l_onlyreq?>)</FONT></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><INPUT TYPE="PASSWORD" NAME="password2" SIZE="25" MAXLENGTH="255"></TD>
	</TR>

	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_emailaddress?>: *<b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><INPUT TYPE="TEXT" NAME="email" SIZE="25" MAXLENGTH="80" VALUE="<?php echo html_escape($userdata[user_email])?>"></TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_icq . " ". $l_number?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><INPUT TYPE="TEXT" NAME="icq" SIZE="10" MAXLENGTH="20" VALUE="<?php echo html_escape($userdata[user_icq])?>"></TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_aim?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><INPUT TYPE="TEXT" NAME="aim" SIZE="25" MAXLENGTH="80" VALUE="<?php echo html_escape($userdata[user_aim])?>"></TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_yahoo?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><INPUT TYPE="TEXT" NAME="yim" SIZE="25" MAXLENGTH="80" VALUE="<?php echo html_escape($userdata[user_yim])?>"></TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo  $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_messenger?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><INPUT TYPE="TEXT" NAME="msnm" SIZE="25" MAXLENGTH="80" VALUE="<?php echo html_escape($userdata[user_msnm])?>"></TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_website?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><INPUT TYPE="TEXT" NAME="website" SIZE="25" MAXLENGTH="120" VALUE="<?php echo html_escape($userdata[user_website])?>"></TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_location?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><INPUT TYPE="TEXT" NAME="from" SIZE="25" MAXLENGTH="40" VALUE="<?php echo html_escape($userdata[user_from])?>"></TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_occupation?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><INPUT TYPE="TEXT" NAME="occ" SIZE="25" MAXLENGTH="255" VALUE="<?php echo html_escape($userdata[user_occ])?>"></TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_interests?>: <b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><INPUT TYPE="TEXT" NAME="intrest" SIZE="25" MAXLENGTH="255" VALUE="<?php echo html_escape($userdata[user_intrest])?>"></TD>
	</TR>
<?php
	$sig = str_replace("<BR>", "\n", $userdata[user_sig]);
	$sig = stripslashes($sig);
?>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_signature?>:</b><br><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize1?>" COLOR="<?php echo $textcolor?>"><?php echo $l_sigexplain?></font></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><TEXTAREA NAME="sig" ROWS=6 COLS=45><?php echo html_escape($sig)?></TEXTAREA></TD>
	</TR>
	<TR ALIGN="LEFT">
<?php
		if($userdata[user_viewemail] == 1)
			$s = " CHECKED";
?>
		<TD  BGCOLOR="<?php echo $color1?>" width="25%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><b><?php echo $l_options?>:</FONT></b></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><INPUT TYPE="CHECKBOX" NAME="viewemail" VALUE="1" <?php echo $s?>> <?php echo $l_publicmail?></TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" colspan = 2><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize3?>" COLOR="<?php echo $textcolor?>"><?php echo $l_itemsreq?></font></TD>
	</TR>
	<TR>
		<TD BGCOLOR="<?php echo $color1?>" colspan=2 ALIGN="CENTER">
		<INPUT TYPE="HIDDEN" NAME="mode" VALUE="edit">
		<INPUT TYPE="HIDDEN" NAME="save" VALUE="1">
		<INPUT TYPE="HIDDEN" NAME="user_id" VALUE="<?php echo $userdata[user_id]?>">
		<INPUT TYPE="SUBMIT" NAME="submit" VALUE="<?php echo $l_submit?>">
		</TD>
	</TR>
	</TABLE></TD></TR></TABLE></FORM>
<?php

			}
		} else {
			// no valid session, and they haven't submitted.
			// so, we need to get a user/pass.
	      include('page_header.'.$phpEx);
			login_form();
		}
	break;

	} // switch

} // if ($mode)
?>
<?php
include('page_tail.'.$phpEx);
?>
