<?php
/***************************************************************************
                          delpmsg.php  -  description
                             -------------------
    begin                : Wed June 19 2000
    copyright            : (C) 2001 The phpBB Group
    email                : support@phpbb.com

    $Id: delpmsg.php,v 1.9 2001/03/28 08:02:20 thefinn Exp $

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
 * delpmsg.php - Nathan Codding
 * - Used for deleting private messages by users of the BB.
 */
include('extention.inc');
include('functions.'.$phpEx);
include('config.'.$phpEx);
require('auth.'.$phpEx);
$submit = request_string('submit', '', 'post');
$user = request_string('user', '', 'post');
$passwd = request_string('passwd', '', 'post');
$msgid = request_int('msgid');
$pagetitle = "Private Messages";
$pagetype = "privmsgs";
include('page_header.'.$phpEx);

$sql = "SELECT from_userid, to_userid FROM priv_msgs WHERE msg_id = ?";
$resultID = db_query_params($sql, array($msgid), $db);
$message_row = $resultID ? db_fetch_array($resultID) : false;
if (!$message_row) {
	error_die("Message not found.");
}
if (!forum_user_can_access_message($userdata, $message_row, $user_logged_in)) {
	forum_authorization_denied("That's not your message. You can't delete it.");
}

if ($REQUEST_METHOD !== 'POST') {
	echo '<FORM ACTION="' . html_escape($PHP_SELF) . '" METHOD="POST"><P ALIGN="CENTER">';
	echo '<INPUT TYPE="HIDDEN" NAME="msgid" VALUE="' . (int) $msgid . '">';
	echo '<INPUT TYPE="SUBMIT" NAME="submit" VALUE="' . html_escape($l_delete) . '">';
	echo '</P></FORM>';
	require('page_tail.'.$phpEx);
	exit();
}

$deleteSQL = "DELETE FROM priv_msgs WHERE msg_id = ?";
	$success = db_query_params($deleteSQL, array($msgid));
	if (!$success) {
		error_die("Error deleting from DB.");
	}
   echo "<br><TABLE BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\" ALIGN=\"CENTER\" VALIGN=\"TOP\" WIDTH=\"$tablewidth\">";
   echo "<TR><TD  BGCOLOR=\"$table_bgcolor\"><TABLE BORDER=\"0\" CALLPADDING=\"1\" CELLSPACING=\"1\" WIDTH=\"100%\">";
   echo "<TR BGCOLOR=\"$color1\" ALIGN=\"LEFT\"><TD><font face=\"Verdana\" size=\"2\"><P>";
   echo "<P><BR><center>$l_deletesucces $l_click <a href=\"$url_phpbb/viewpmsg.$phpEx\">$l_here</a> $l_toreturn<p></center></font>";
   echo "</TD></TR></TABLE></TD></TR></TABLE><br>";

require('page_tail.'.$phpEx);
?>
