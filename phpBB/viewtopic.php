<?php
/***************************************************************************
                            viewtopic.php  -  description
                             -------------------
    begin                : Sat June 17 2000
    copyright            : (C) 2001 The phpBB Group
    email                : support@phpbb.com

    $Id: viewtopic.php,v 1.58 2001/08/01 00:10:55 thefinn Exp $

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
$forum = request_int('forum');
$topic = request_int('topic');
$start = request_int('start');
$logging_in = request_string('logging_in', '', 'post');
$username = request_string('username', '', 'post');
$password = request_string('password', '', 'post');
$pagetitle = $l_topictitle;
$pagetype = "viewtopic";

$sql = "SELECT f.forum_id, f.forum_type, f.forum_name FROM forums f, topics t WHERE f.forum_id = ? AND t.topic_id = ? AND t.forum_id = f.forum_id";
if(!$result = db_query_params($sql, array($forum, $topic), $db))
	error_die("<font size=+1>An Error Occured</font><hr>Could not connect to the forums database.");
if(!$myrow = db_fetch_array($result))
	error_die("Error - The forum/topic you selected does not exist. Please go back and try again.");
$forum_name = own_stripslashes($myrow[forum_name]);

// Note: page_header is included later on, because this page might need to send a cookie.
if(($myrow[forum_type] == 1) && !$user_logged_in && !$logging_in)
{
	require('page_header.'.$phpEx);
?>
<FORM ACTION="<?php echo $PHP_SELF?>" METHOD="POST">
	<TABLE BORDER="0" CELLPADDING="1" CELLSPACING="0" ALIGN="CENTER" VALIGN="TOP" WIDTH="<?php echo $tablewidth?>">
		<TR>
			<TD BGCOLOR="<?php echo $table_bgcolor?>">
				<TABLE BORDER="0" CELLPADDING="1" CELLSPACING="1" WIDTH="100%">
					<TR BGCOLOR="<?php echo $color1?>" ALIGN="LEFT">
						<TD ALIGN="CENTER"><?php echo $l_private?></TD>
					</TR>
					<TR BGCOLOR="<?php echo $color2?>" ALIGN="LEFT">
						<TD ALIGN="CENTER">
							<TABLE BORDER="0" CELLPADDING="1" CELLSPACING="0">
							  <TR>
							    <TD>
							      <FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
							      <b>User Name: &nbsp;</b></font></TD><TD><INPUT TYPE="TEXT" NAME="username" SIZE="25" MAXLENGTH="40" VALUE="<?php echo html_escape($userdata[username])?>">
							    </TD>
							  </TR><TR>
							    <TD>
							      <FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
							      <b>Password: </b></TD><TD><INPUT TYPE="PASSWORD" NAME="password" SIZE="25" MAXLENGTH="255">
							    </TD>
							  </TR>
							</TABLE>
						</TD>
					</TR>
					<TR BGCOLOR="<?php echo $color1?>" ALIGN="LEFT">
						<TD ALIGN="CENTER">
							<INPUT TYPE="HIDDEN" NAME="forum" VALUE="<?php echo $forum?>">
							<INPUT TYPE="HIDDEN" NAME="topic" VALUE="<?php echo $topic?>">
							<INPUT TYPE="SUBMIT" NAME="logging_in" VALUE="<?php echo $l_enter?>">
						</TD>
					</TR>
				</TABLE>
			</TD>
		</TR>
	</TABLE>
</FORM>
<?php
require('page_tail.'.$phpEx);
exit();
}
else
{
   if ($logging_in)
     {
	if ($username == '' || $password == '')
	  {
	     error_die("$l_userpass $l_tryagain");
	  }
	if (!check_username($username, $db))
	  {
	     error_die("$l_nouser $l_tryagain");
	  }
	if (!check_user_pw($username, $password, $db))
	  {
	     error_die("$l_wrongpass $l_tryagain");
	  }

	/* if we get here, user has entered a valid username and password combination. */

	$userdata = get_userdata($username, $db);

	$sessid = new_session($userdata[user_id], $REMOTE_ADDR, $sesscookietime, $db);

	set_session_cookie($sessid, $sesscookietime, $sesscookiename, $cookiepath, $cookiedomain, $cookiesecure);
	$user_logged_in = 1;

     }



   if (!forum_user_can_read_forum($userdata, $myrow, $db, $user_logged_in))
     {
	include('page_header.'.$phpEx);
	forum_authorization_denied("$l_privateforum $l_noread");
     }



$sql = "SELECT topic_title, topic_status FROM topics WHERE topic_id = ?";

$total = get_total_posts($topic, $db, "topic");
if($total > $posts_per_page) {
   $times = 0;
   for($x = 0; $x < $total; $x += $posts_per_page)
     $times++;
   $pages = $times;
}

if(!$result = db_query_params($sql, array($topic), $db))
  error_die("<font size=+1>An Error Occured</font><hr>Could not connect to the forums database.");
$myrow = db_fetch_array($result);
$topic_subject = own_stripslashes($myrow[topic_title]);
$lock_state = $myrow[topic_status];
include('page_header.'.$phpEx);

?>
<?php
if($total > $posts_per_page) {
   echo "<TABLE BORDER=0 WIDTH=$TableWidth ALIGN=CENTER>";
   $times = 1;
   echo "<TR ALIGN=\"LEFT\"><TD><FONT FACE=\"$FontFace\" SIZE=\"$FontSize3\" COLOR=\"$textcolor\">$l_gotopage ( ";
   $last_page = $start - $posts_per_page;
   if($start > 0) {
     echo "<a href=\"$PHP_SELF?topic=$topic&forum=$forum&start=$last_page\">$l_prevpage</a> ";
   }
   for($x = 0; $x < $total; $x += $posts_per_page) {
      if($times != 1)
	echo " | ";
      if($start && ($start == $x)) {
	   echo $times;
      }
      else if($start == 0 && $x == 0) {
	 echo "1";
      }
      else {
	echo "<a href=\"$PHP_SELF?mode=viewtopic&topic=$topic&forum=$forum&start=$x\">$times</a>";
      }
      $times++;
   }
   if(($start + $posts_per_page) < $total) {
      $next_page = $start + $posts_per_page;
      echo " <a href=\"$PHP_SELF?topic=$topic&forum=$forum&start=$next_page\">$l_nextpage</a>";
   }
   echo " ) </FONT></TD></TR></TABLE>\n";
}
?>

<TABLE BORDER="0" CELLPADDING="1" CELLSPACING="0" ALIGN="CENTER" VALIGN="TOP" WIDTH="<?php echo $TableWidth?>"><TR><TD  BGCOLOR="<?php echo $table_bgcolor?>">
<TABLE BORDER="0" CELLPADDING="3" CELLSPACING="1" WIDTH="100%">
<TR BGCOLOR="<?php echo $color1?>" ALIGN="LEFT">
	<TD WIDTH="20%"><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>"><?php echo $l_author?></FONT></TD>
	<TD><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2 ?>"COLOR="<?php echo $textcolor?>"><?php echo html_escape($topic_subject)?></FONT></TD>
</TR>
<?php
$sql = "SELECT p.*, pt.post_text FROM posts p, posts_text pt
   WHERE topic_id = ?
   AND p.post_id = pt.post_id
   ORDER BY post_id LIMIT ?, ?";
if(!$result = db_query_params($sql, array($topic, $start, (int) $posts_per_page), $db))
  error_die("<font size=+1>An Error Occured</font><hr>Could not connect to the posts database.");
$myrow = db_fetch_array($result);
$row_color = $color2;
$count = 0;
do {
   if(!($count % 2))
     $row_color = $color2;
   else
     $row_color = $color1;

   echo "<TR BGCOLOR=\"$row_color\" ALIGN=\"LEFT\">\n";
   if($myrow[poster_id] != -1) {
	   $posterdata = get_userdata_from_id($myrow[poster_id], $db);
	}
   else
     $posterdata = array("user_id" => -1, "username" => $l_anonymous, "user_posts" => "0", "user_rank" => -1);
   echo "<TD valign=top><FONT FACE=\"$FontFace\" COLOR=\"$textcolor\"><b>" . html_escape($posterdata[username]) . "</b></FONT>";
   $posts = $posterdata[user_posts];
   if($posterdata[user_id] != -1) {
      if($posterdata[user_rank] != 0) {
	$sql = "SELECT rank_title, rank_image FROM ranks WHERE rank_id = ?";
        $rank_params = array((int) $posterdata[user_rank]);
      }
      else {
	$sql = "SELECT rank_title, rank_image FROM ranks WHERE rank_min <= ? AND rank_max >= ? AND rank_special = 0";
        $rank_params = array((int) $posterdata[user_posts], (int) $posterdata[user_posts]);
      }
      if(!$rank_result = db_query_params($sql, $rank_params, $db))
	error_die("Error connecting to the database!");
      list($rank, $rank_image) = db_fetch_array($rank_result);
      echo "<BR><FONT FACE=\"$FontFace\" SIZE=\"$FontSize1\" COLOR=\"$textcolor\"><B>" . html_escape(own_stripslashes($rank)) . "</B></font>";
      if($rank_image != '')
	echo '<BR><IMG SRC="' . html_web_url($url_images . '/' . $rank_image, true) . '" BORDER="0">';
      echo "<BR><BR><FONT FACE=\"$FontFace\" SIZE=\"$FontSize1\" COLOR=\"$textcolor\">$l_joined: $posterdata[user_regdate]</FONT>";
      echo "<br><FONT FACE=\"$FontFace\" SIZE=\"$FontSize1\" COLOR=\"$textcolor\">$l_posts: $posts</FONT>";
      if ($posterdata[user_from] != ''){
        echo "<BR><FONT FACE=\"$FontFace\" SIZE=\"$FontSize1\" COLOR=\"$textcolor\">$l_location: " . html_escape($posterdata[user_from]) . "<br></FONT>";
      }
      echo "</td>";
   }
   else {
      echo "<BR><FONT FACE=\"$FontFace\" SIZE=\"$FontSize1\" COLOR=\"$textcolor\">$l_unregistered</font></TD>";
   }
   echo "<TD><img src=\"$posticon\"><FONT FACE=\"$FontFace\" SIZE=\"$FontSize1\" COLOR=\"$textcolor\">$l_posted: $myrow[post_time]&nbsp;&nbsp;&nbsp";
   echo "<HR></font>\n";
   $message = own_stripslashes($myrow[post_text]);

   $sig = str_replace("<BR>", "\n", own_stripslashes($posterdata[user_sig]));
   $rendered_sig = render_user_text($sig, $allow_bbcode == 1, true);
   $message = preg_replace("/\[addsig\]$/i", "<BR>_________________<BR>" . $rendered_sig, $message);

   echo "\n<FONT COLOR=\"$textcolor\" face=\"$FontFace\">" . $message . "</FONT><BR>";
   echo "\n<HR>";
   if ($posterdata[user_id] != -1)
   {
		echo "&nbsp;&nbsp<a href=\"$url_phpbb/bb_profile.$phpEx?mode=view&user=$posterdata[user_id]\"><img src=\"$profile_image\" border=0 alt=\"$l_profileof " . html_escape($posterdata[username]) . "\"></a>\n";

	   if($posterdata["user_viewemail"] != 0) {
	     $email_url = html_email_url($posterdata[user_email]);
	     echo "&nbsp;&nbsp;<a href=\"$email_url\"><IMG SRC=\"$email_image\" BORDER=0 ALT=\"$l_email " . html_escape($posterdata[username]) . "\"></a>\n";
	   }
	   if($posterdata["user_website"] != '') {
	      $website_url = html_web_url($posterdata[user_website]);
	      echo "&nbsp;&nbsp;<a href=\"$website_url\" TARGET=\"_blank\" REL=\"noopener noreferrer\"><IMG SRC=\"$www_image\" BORDER=0 ALT=\"$l_viewsite " . html_escape($posterdata[username]) . "\"></a>\n";
	   }

	   if($posterdata["user_msnm"] != '')
	     echo "&nbsp;&nbsp;<a href=\"$url_phpbb/bb_profile.$phpEx?mode=view&user=$posterdata[user_id]\"><img src=\"$images_msnm\" border=\"0\"></a>";

   	echo "&nbsp;&nbsp;<IMG SRC=\"images/div.gif\">\n";
   }
   else
   {
   	echo "&nbsp;&nbsp\n";
   }


   echo "&nbsp;&nbsp;<a href=\"$url_phpbb/editpost.$phpEx?post_id=$myrow[post_id]&topic=$topic&forum=$forum\"><img src=\"$edit_image\" border=0 alt=\"$l_editdelete\"></a>\n";

   echo "&nbsp;&nbsp;<a href=\"$url_phpbb/reply.$phpEx?topic=$topic&forum=$forum&post=$myrow[post_id]&quote=1\"><IMG SRC=\"$reply_wquote_image\" BORDER=\"0\" alt=\"$l_replyquote\"></a>\n";
   if(forum_user_can_moderate($userdata, $forum, $db, $user_logged_in)) {
      echo "&nbsp;&nbsp;<IMG SRC=\"images/div.gif\">\n";
      echo "&nbsp;&nbsp;<a href=\"$url_phpbb/topicadmin.$phpEx?mode=viewip&post=$myrow[post_id]&forum=$forum\"><IMG SRC=\"$ip_image\" BORDER=0 ALT=\"$l_viewip\"></a>\n";
   }
   echo "</TD></TR>";
   $count++;
} while($myrow = db_fetch_array($result));
$sql = "UPDATE topics SET topic_views = topic_views + 1 WHERE topic_id = ?";
@db_query_params($sql, array($topic), $db);
?>

</TABLE></TD></TR></TABLE>
<TABLE ALIGN="CENTER" BORDER="0" WIDTH="<?php echo $TableWidth?>">
<?php
if($total > $posts_per_page) {
   $times = 1;
   echo "<TR ALIGN=\"RIGHT\"><TD colspan=2><FONT FACE=\"$FontFace\" SIZE=\"$FontSize3\" COLOR=\"$textcolor\">$l_gotopage ( ";
   $last_page = $start - $posts_per_page;
   if($start > 0) {
      echo "<a href=\"$PHP_SELF?topic=$topic&forum=$forum&start=$last_page\">$l_prevpage</a> ";
   }
   for($x = 0; $x < $total; $x += $posts_per_page) {
      if($times != 1)
	echo " | ";
      if($start && ($start == $x)) {
	 echo $times;
      }
      else if($start == 0 && $x == 0) {
	 echo "1";
      }
      else {
	 echo "<a href=\"$PHP_SELF?mode=viewtopic&topic=$topic&forum=$forum&start=$x\">$times</a>";
      }
      $times++;
   }
   if(($start + $posts_per_page) < $total) {
      $next_page = $start + $posts_per_page;
      echo " <a href=\"$PHP_SELF?topic=$topic&forum=$forum&start=$next_page\">$l_nextpage</a>";
   }
   echo " ) </FONT></TD></TR>\n";
}
?>
<TR>
	<TD>
		<a href="newtopic.<?php echo $phpEx?>?forum=<?php echo $forum?>"><IMG SRC="<?php echo $newtopic_image?>" BORDER="0"></a>&nbsp;&nbsp;
<?php
		if($lock_state != 1) {
?>
			<a href="<?php echo $url_phpbb ?>/reply.<?php echo $phpEx?>?topic=<?php echo $topic ?>&forum=<?php echo $forum ?>"><IMG SRC="<?php echo $reply_image ?>" BORDER="0"></a></TD>
<?php
		}
		else {
?>
			<IMG SRC="<?php echo $reply_locked_image ?>" BORDER="0"></TD>
<?php
		}
?>
	</TD>
<TD ALIGN="RIGHT">
<?php
make_jumpbox();
?>
</TR></TABLE>

<?php
echo "<CENTER>";
if($lock_state != 1)
	echo "<a href=\"$url_phpbb/topicadmin.$phpEx?mode=lock&topic=$topic&forum=$forum\"><IMG SRC=\"$locktopic_image\" ALT=\"$l_locktopic\" BORDER=0></a> ";
else
	echo "<a href=\"$url_phpbb/topicadmin.$phpEx?mode=unlock&topic=$topic&forum=$forum\"><IMG SRC=\"$unlocktopic_image\" ALT=\"$l_unlocktopic\" BORDER=0></a> ";

echo "<a href=\"$url_phpbb/topicadmin.$phpEx?mode=move&topic=$topic&forum=$forum\"><IMG SRC=\"$movetopic_image\" ALT=\"$l_movetopic\" BORDER=0></a> ";
echo "<a href=\"$url_phpbb/topicadmin.$phpEx?mode=del&topic=$topic&forum=$forum\"><IMG SRC=\"$deltopic_image\" ALT=\"$l_deletetopic\" BORDER=0></a></CENTER>\n";

}

require('page_tail.'.$phpEx);

?>
