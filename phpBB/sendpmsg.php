<?php
/***************************************************************************
                          sendpmsg.php  -  description
                             -------------------
    begin                : Wed June 19 2000
    copyright            : (C) 2001 The phpBB Group
    email                : support@phpbb.com

    $Id: sendpmsg.php,v 1.22 2001/03/28 08:02:20 thefinn Exp $
 
 ***************************************************************************/

/***************************************************************************
 *                                         				                                
 *   This program is free software; you can redistribute it and/or modify  	
 *   it under the terms of the GNU General Public License as published by  
 *   the Free Software Foundation; either version 2 of the License, or	    	
 *   (at your option) any later version.
 *
 ***************************************************************************/

/**
 * sendpmsg.php - Nathan Codding
 * - Used for sending private messages between users of the BB.
 */
include('extention.inc');
include('functions.'.$phpEx);
include('config.'.$phpEx);
require('auth.'.$phpEx);
$submit = request_string('submit', '', 'post');
$message = request_string('message', '', 'post');
$tousername = request_string('tousername');
$fromusername = request_string('fromusername', '', 'post');
$password = request_string('password', '', 'post');
$html = request_present('html', 'post');
$bbcode = request_present('bbcode', 'post');
$sig = request_present('sig', 'post');
$smile = request_present('smile', 'post');
$pagetitle = "Send Private Message";
$pagetype = "sendprivmsg";
include('page_header.'.$phpEx);


if($submit) {
	if($message == '') {
		error_die($l_emptymsg);
	}
	if ($tousername == '') {
		error_die($l_norecipient);
	}
	$touserdata = get_userdata($tousername, $db);
	if(!$touserdata[username]) {
		error_die($l_nouser);
	}

	if (!$user_logged_in) { // don't check this stuff if we have a valid session..
		if($fromusername == '' || $password == '') {
			error_die("$l_userpass $l_tryagain");
		}
		
		$fromuserdata = get_userdata($fromusername, $db);
		if(!forum_verify_password($password, $fromuserdata["user_password"] ?? '')) {
			error_die("$l_wrongpass $l_tryagain");
		}
	} else {
		// we have a valid session..
		$fromuserdata = $userdata; // fromuser = current user.
	}
	
	/* correct password or logged-in user, continuing with message send. */

	if($sig) {
		$message .= "\n__________________\n" . str_replace("<BR>", "\n", $fromuserdata[user_sig]);
	}
	$message = render_user_text($message, $allow_pmsg_bbcode == 1 && !$bbcode, !$smile);
	$time = date("Y-m-d H:i");
	
	$sql = "INSERT INTO priv_msgs (from_userid, to_userid, msg_time, msg_text) VALUES (?, ?, ?, ?)";
	
	if(!db_query_params($sql, array((int) $fromuserdata[user_id], (int) $touserdata[user_id], $time, $message), $db)) {
		echo $sql . " : " . db_error() . "<br>";
		error_die("Could not enter data into the database.");
	}

	echo "<br><TABLE BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\" ALIGN=\"CENTER\" VALIGN=\"TOP\" WIDTH=\"$tablewidth\">";
	echo "<TR><TD  BGCOLOR=\"$table_bgcolor\"><TABLE BORDER=\"0\" CALLPADDING=\"1\" CELLSPACING=\"1\" WIDTH=\"100%\">";
	echo "<TR BGCOLOR=\"$color1\" ALIGN=\"LEFT\"><TD><font face=\"Verdana\" size=\"2\"><P>";
	echo "<P><BR><center>";
	echo "$l_stored<br> \n";
	echo "<a href=\"sendpmsg.$phpEx\">$l_sendothermsg</a> <br> \n";
	echo "<p></center></font>";
	echo "</TD></TR></TABLE></TD></TR></TABLE><br>";
	

} else {

/* displaying the form */

?>
<FORM ACTION="<?php echo $PHP_SELF?>" METHOD="POST">
	<TABLE BORDER="0" CELLPADDING="1" CELLSPACEING="0" ALIGN="CENTER" VALIGN="TOP" WIDTH="95%"><TR><TD  BGCOLOR="<?php echo $table_bgcolor?>">
	<TABLE BORDER="0" CALLPADDING="1" CELLSPACEING="1" WIDTH="100%">
	<TR BGCOLOR="<?php echo $color1?>" ALIGN="LEFT">
		<TD width=25%>
			<FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
			<b><?php echo $l_aboutpost?></b>
			</FONT>
		</TD>
		<TD>
			<FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
			<?php echo "$l_regusers $l_cansend"?>
		</TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>"  width=25%>
			<FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
			<b><?php echo $l_yourname?>:<b>
			</FONT>
		</TD>
		<TD  BGCOLOR="<?php echo $color2?>">
			<FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
<?php
	if ($user_logged_in) {
		echo html_escape($userdata[username]) . " \n";
	} else {
		echo "<INPUT TYPE=\"TEXT\" NAME=\"fromusername\" SIZE=\"25\" MAXLENGTH=\"40\" VALUE=\"" . html_escape($userdata[username]) . "\"> \n";
	}
?>
			</FONT>
		</TD>
	</TR>
<?php
	if (!$user_logged_in) { 
		// no session, need a password.
		echo "    <TR ALIGN=\"LEFT\"> \n";
		echo "        <TD BGCOLOR=\"$color1\" width=25%><b>$l_password:</b></TD> \n";
		echo "        <TD BGCOLOR=\"$color2\"><INPUT TYPE=\"PASSWORD\" NAME=\"password\" SIZE=\"25\" MAXLENGTH=\"25\"></TD> \n";
		echo "    </TR> \n";
	}
?>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>"  width=25%>
			<FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
			<b><?php echo $l_recptname?>:<b>
			</FONT>
		</TD>
		<TD  BGCOLOR="<?php echo $color2?>"><INPUT TYPE="TEXT" NAME="tousername" SIZE="25" MAXLENGTH="40" VALUE="<?php echo html_escape($tousername)?>"></TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width=25%>
			<FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
			<b><?php echo $l_body?>:</b><br><br>
			</FONT>
		<font size=-1>
		<?php
		echo "$l_htmlis: ";
		if($allow_pmsg_html == 1)
			echo "$l_on<BR>\n";
		else
			echo "$l_off<BR>\n";
		echo "$l_bbcodeis:";
		if($allow_pmsg_bbcode == 1)
			echo "$l_on<br>\n";
		else
			echo "$l_off<BR>\n";
		?>		
		</font></TD>
		<TD  BGCOLOR="<?php echo $color2?>"><TEXTAREA NAME="message" ROWS=10 COLS=45 WRAP="VIRTUAL"></TEXTAREA></TD>
	</TR>
	<TR ALIGN="LEFT">
		<TD  BGCOLOR="<?php echo $color1?>" width=25%>
			<FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
			<b><?php echo $l_options?>:</b>
			</FONT>
		</TD>
		<TD  BGCOLOR="<?php echo $color2?>" >
			<FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
		<?php
			if($allow_pmsg_html == 1) {
				echo "<INPUT TYPE=\"CHECKBOX\" NAME=\"html\">$l_disable $l_html $l_onthispost<BR>";
			}
			if($allow_pmsg_bbcode == 1) {
				echo "<INPUT TYPE=\"CHECKBOX\" NAME=\"bbcode\">$l_disable <a href=\"$bbref_url\" target=\"_blank\"><i>$l_bbcode</i></a> $l_onthispost<BR>";
			}

		echo "<INPUT TYPE=\"CHECKBOX\" NAME=\"smile\">$l_disable <a href=\"$smileref_url\" target=\"_blank\"><i>$l_smilies</i></a> $l_onthispost.<BR>";
			if($allow_sig == 1) {
		?>
				<INPUT TYPE="CHECKBOX" NAME="sig"><?php echo $l_attachsig?></font><BR>
		<?php
			}
		?>
			</FONT>
		</TD>
	</TR>
	<TR>
		<TD  BGCOLOR="<?php echo $color1?>" colspan=2 ALIGN="CENTER">
		<INPUT TYPE="SUBMIT" NAME="submit" VALUE="<?php echo $l_submit?>">
	</TR>
	</TABLE></TD></TR></TABLE>
	</FORM>

<?php
}
require('page_tail.'.$phpEx);
?>
