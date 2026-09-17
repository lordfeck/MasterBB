<?php
/***************************************************************************
                            admin.php  -  description
                               -------------------
      begin                : Sat Oct 28 2000
      copyright            : (C) 2001 The phpBB Group
      email                : support@phpbb.com
 
      $Id: smiles.php,v 1.9 2001/07/07 11:14:18 blackpuma Exp $
  
 ***************************************************************************/
/****************************************************************************
 *                                                                                                       
 *   This program is free software; you can redistribute it and/or modify       
 *   it under the terms of the GNU General Public License as published by  
 *   the Free Software Foundation; either version 2 of the License, or          
 *   (at your option) any later version.
 *
 ***************************************************************************/
  /*
   * This file was created by Viceroy (http://www.youdotheweb.com) as part of
   * a 'Smile Control Panel' hack for phpBB. It was later imported into the
   * official phpBB distribution.
   */
include('../extention.inc');
include('../functions.'.$phpEx);
include('../config.'.$phpEx);
require('../auth.'.$phpEx);

$login = request_string('login', '', 'post');
$username = request_string('username', '', 'post');
$password = request_string('password', '', 'post');
$mode = request_string('mode');
$submit = request_string('submit', '', 'post');
$id_present = request_present('id', 'get');
$id = request_int('id', 0, 'get');
$smile = request_int('smile', 0, 'post');
$smile_id = request_int('smile_id', 0, 'post');
$code = request_string('code', '', 'post');
$smile_url = request_string('smile_url', '', 'post');
$emotion = request_string('emotion', '', 'post');
if($login) {
      if ($username == '') {
	       die("You have to enter your username. Go back and do so.");
      }
      if ($password == '') {
	       die("You have to enter your password. Go back and do so.");
      }
      if (!check_user_pw($username, $password, $db)) {
	       die("Invalid username or password. Go back and try again.");
      }
           
      $userdata = get_userdata($username, $db);
      $sessid = new_session($userdata[user_id], $REMOTE_ADDR, $sesscookietime, $db);
      set_session_cookie($sessid, $sesscookietime, $sesscookiename, $cookiepath, $cookiedomain, $cookiesecure);
           
      if (defined('USE_IIS_LOGIN_HACK') && USE_IIS_LOGIN_HACK)
		{
			echo "<META HTTP-EQUIV=\"refresh\" content=\"1;URL=$url_admin_index\">";
		}
		else
		{
			header("Location: $url_admin_index");	
		}
}
else if(!$user_logged_in) {
      $pagetitle = "Forum Administration";
      $pagetype = "admin";
      include('../page_header.'.$phpEx);
   
   ?>
          <TABLE BORDER="0" CELLPADDING="1" CELLSPACING="0" ALIGN="CENTER" VALIGN="TOP" WIDTH="<?php echo $TableWidth?>">
          <TR><TD  BGCOLOR="<?php echo $table_bgcolor?>">
          <TABLE BORDER="0" CELLPADDING="1" CELLSPACING="1" WIDTH="100%">
          <TR BGCOLOR="<?php echo $color1?>" ALIGN="LEFT">
          <TD><P><BR><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
          Please enter your username and password to login.<BR>
     <i>(NOTE: You MUST have cookies enabled in order to login to the administration section of this forum)</i><BR>
          <UL>
          <FORM ACTION="<?php echo $PHP_SELF?>" METHOD="POST">
     <b>User Name: </b><INPUT TYPE="TEXT" NAME="username" SIZE="25" MAXLENGTH="40" VALUE="<?php echo html_escape($userdata[username])?>"><BR>
     <b>Password: </b><INPUT TYPE="PASSWORD" NAME="password" SIZE="25" MAXLENGTH="255"><br><br>
          <INPUT TYPE="SUBMIT" NAME="login" VALUE="Submit">&nbsp;&nbsp;&nbsp;<INPUT TYPE="RESET" VALUE="Clear"></ul>
          </FORM>
          </TD></TR></TABLE></TD></TR></TABLE>
     <?php
          include('../page_tail.'.$phpEx);
        exit();
}
else if(forum_user_is_admin($userdata, $user_logged_in)) {
   
$pagetitle = "Smiles Control";
$pagetype = "admin";
include('../page_header.'.$phpEx);

echo "<font face=\"$FontFace\" size=\"$FontSize2\">";
echo "<TABLE width=\"45%\" border=\"1\" cellspacing=\"0\" cellpadding=\"0\" align=\"center\" bordercolor=\"$table_bgcolor\">";
echo "<tr><td align=\"center\" width=\"100%\" bgcolor=\"$color1\"><font face=\"$FontFace\" size=\"$FontSize4\" color=\"$textcolor\"><B>Smilies Utility.</B></font></td></TR>";
echo "<tr><td align=\"center\" width=\"100%\" bgcolor=\"$color1\"><font face=\"$FontFace\" size=\"$FontSize2\" color=\"$textcolor\"><a href=\"$PHP_SELF?mode=add\">Add Smile</a></TD></TR>";
echo "</TR></table></TD></TR></TABLE><BR><BR><center>";

if ($mode == '') {
   $mode = 'view';
}

switch ($mode) {
 case 'view':
   if ($getsmiles = db_query("SELECT * FROM smiles")) {
      if (($numsmiles = db_num_rows($getsmiles)) == "0") {
	 echo "<font face=\"$FontFace\" size=2>No smiles currently. <a href='$PHP_SELF?mode=add'>Click here</a> to add some.</font>";
      } else {
	 echo "<table border=0 cellspacing=1 cellpadding=3><tr><td bgcolor=\"$color1\"><font face=\"$FontFace\" size=2>Code</font></td><td bgcolor='$color2'><font face=\"$FontFace\" size=2>Smile</font></td><td bgcolor='$color1'>&nbsp;</td><td bgcolor='$color2'>&nbsp;</td></tr>";
	 while ($smiles = db_fetch_array($getsmiles)) {
	    echo "<tr><td bgcolor='$color1'><font face=\"$FontFace\" size=2>" . html_escape($smiles[code]) . "</font></td><td bgcolor='$color2'><img src=\"" . html_web_url($url_smiles . '/' . $smiles[smile_url], true) . "\"></td><td bgcolor='$color1'><a href=\"$PHP_SELF?mode=edit&id=$smiles[id]\">Edit</a></td><td bgcolor='$color2'><a href=\"$PHP_SELF?mode=delete&id=$smiles[id]\">Delete</a></td></tr>";
	 }
	 echo "</table>";
      }
   } else {
      echo "Could not retrieve from the smile database.";
   }
   break;
   
 case 'add':
   if (!$submit) {
echo "<TABLE width=\"45%\" border=\"1\" cellspacing=\"0\" cellpadding=\"0\" align=\"center\" bordercolor=\"$table_bgcolor\">";
echo "<tr><td align=\"center\" width=\"100%\" bgcolor=\"$color1\"><font face=\"$FontFace\" size=\"$FontSize2\" color=\"$textcolor\"><B>Add Smilie.</B></font></td>";
echo "</tr><TR><TD><TABLE width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><TR>";
echo "<td align=\"center\" width=\"100%\" bgcolor=\"$color2\"><font face=\"$FontFace\" size=\"$FontSize2\" color=\"$textcolor\"><P>";
      ?>

	Make sure you uploaded your smiles in the proper directory.<br>
	For Smile URL, just put the smile filename.
	
	<form method=post action="<?php echo $PHP_SELF?>">
	Smile Code: <input type="text" name="code"><br>
	Smile URL: <input type="text" name="smile_url"><br>
	Smile Emotion: <input type="text" name="emotion"><br>
	<input type="hidden" name="mode" value="add">
	<input type="submit" name="submit" value="Add the Smile!">
	</form>
	<?php
echo "</font><P></TD>";
echo "</TR></table></TD></TR></TABLE>";
   } else {
      
      if (!$insertsmile = db_query_params("INSERT INTO smiles (code, smile_url, emotion) VALUES (?, ?, ?)", array($code, $smile_url, $emotion))) {
echo "<TABLE width=\"45%\" border=\"1\" cellspacing=\"0\" cellpadding=\"0\" align=\"center\" bordercolor=\"$table_bgcolor\">";
echo "<tr><td align=\"center\" width=\"100%\" bgcolor=\"$color1\"><font face=\"$FontFace\" size=\"$FontSize2\" color=\"$textcolor\"><B>Add Smilie.</B></font></td>";
echo "</tr><TR><TD><TABLE width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><TR>";
echo "<td align=\"center\" width=\"100%\" bgcolor=\"$color2\"><font face=\"$FontFace\" size=\"$FontSize1\" color=\"$textcolor\"><P>&nbsp;&nbsp;Could Not Add The Smilie To The Database!</font><P></TD>";
echo "</TR></table></TD></TR></TABLE>";
      } else {
echo "<TABLE width=\"45%\" border=\"1\" cellspacing=\"0\" cellpadding=\"0\" align=\"center\" bordercolor=\"$table_bgcolor\">";
echo "<tr><td align=\"center\" width=\"100%\" bgcolor=\"$color1\"><font face=\"$FontFace\" size=\"$FontSize2\" color=\"$textcolor\"><B>Add Smilie.</B></font></td>";
echo "</tr><TR><TD><TABLE width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><TR>";
echo "<td align=\"center\" width=\"100%\" bgcolor=\"$color2\"><font face=\"$FontFace\" size=\"$FontSize1\" color=\"$textcolor\"><P>&nbsp;&nbsp;Your Smilie Has Been Added!</font><P></TD>";
echo "</TR></table></TD></TR></TABLE>";
      }
      
   }
   
   break;
   
 case 'edit':
   
   if ($id_present) {
      
      $submit = "Let's Edit the Smile!";
      $smile = $id;
      
   }
   
   if ($submit == "Let's Edit the Smile!") {
      
      if ($getsmiles = db_query_params("SELECT * FROM smiles WHERE id = ?", array((int) $smile))) {
	 $smiles = db_fetch_array($getsmiles);

echo "<TABLE width=\"45%\" border=\"1\" cellspacing=\"0\" cellpadding=\"0\" align=\"center\" bordercolor=\"$table_bgcolor\">";
echo "<tr><td align=\"center\" width=\"100%\" bgcolor=\"$color1\"><font face=\"$FontFace\" size=\"$FontSize2\" color=\"$textcolor\"><B>Edit Smilie.</B></font></td>";
echo "</tr><TR><TD><TABLE width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><TR>";
echo "<td align=\"center\" width=\"100%\" bgcolor=\"$color2\"><font face=\"$FontFace\" size=\"$FontSize2\" color=\"$textcolor\"><P>";

	 ?>
	   <form method=post action="<?php echo $PHP_SELF?>">
	   Smile Code: <input type="text" name="code" value="<?php echo html_escape($smiles[code])?>"><br>
	   Smile URL: <input type="text" name="smile_url" value="<?php echo html_escape($smiles[smile_url])?>"><br>
	   Smile Emotion: <input type="text" name="emotion" value="<?php echo html_escape($smiles[emotion])?>"><br>
	   <input type="hidden" name="mode" value="edit">
	   <input type="hidden" name="smile_id" value="<?php echo $smile?>">
	   <input type="submit" name="submit" value="Submit Changes">
	   </form>
	   <?php
echo "</font><P></TD>";
echo "</TR></table></TD></TR></TABLE>";
      } else {
echo "<TABLE width=\"45%\" border=\"1\" cellspacing=\"0\" cellpadding=\"0\" align=\"center\" bordercolor=\"$table_bgcolor\">";
echo "<tr><td align=\"center\" width=\"100%\" bgcolor=\"$color1\"><font face=\"$FontFace\" size=\"$FontSize2\" color=\"$textcolor\"><B>Edit Smilie.</B></font></td>";
echo "</tr><TR><TD><TABLE width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><TR>";
echo "<td align=\"center\" width=\"100%\" bgcolor=\"$color2\"><font face=\"$FontFace\" size=\"$FontSize1\" color=\"$textcolor\"><P>&nbsp;&nbsp;Could Not Retrieve The Image.</font><P></TD>";
echo "</TR></table></TD></TR></TABLE>";
      }
      
   } elseif ($submit == "Submit Changes") {
      if ($updatesmile = db_query_params("UPDATE smiles SET code = ?, emotion = ?, smile_url = ? WHERE id = ?", array($code, $emotion, $smile_url, (int) $smile_id))) {

echo "<TABLE width=\"45%\" border=\"1\" cellspacing=\"0\" cellpadding=\"0\" align=\"center\" bordercolor=\"$table_bgcolor\">";
echo "<tr><td align=\"center\" width=\"100%\" bgcolor=\"$color1\"><font face=\"$FontFace\" size=\"$FontSize2\" color=\"$textcolor\"><B>Edit Smilie.</B></font></td>";
echo "</tr><TR><TD><TABLE width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><TR>";
echo "<td align=\"center\" width=\"100%\" bgcolor=\"$color2\"><font face=\"$FontFace\" size=\"$FontSize1\" color=\"$textcolor\"><P>&nbsp;&nbsp;Smile Successfully Updated.</font><P></TD>";
echo "</TR></table></TD></TR></TABLE>";

      } else {
echo "<TABLE width=\"45%\" border=\"1\" cellspacing=\"0\" cellpadding=\"0\" align=\"center\" bordercolor=\"$table_bgcolor\">";
echo "<tr><td align=\"center\" width=\"100%\" bgcolor=\"$color1\"><font face=\"$FontFace\" size=\"$FontSize2\" color=\"$textcolor\"><B>Edit Smilie.</B></font></td>";
echo "</tr><TR><TD><TABLE width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><TR>";
echo "<td align=\"center\" width=\"100%\" bgcolor=\"$color2\"><font face=\"$FontFace\" size=\"$FontSize1\" color=\"$textcolor\"><P>&nbsp;&nbsp;Sorry Your Smilie Could Not Be Updated!</font><P></TD>";
echo "</TR></table></TD></TR></TABLE>";
      }
      
   } else {
      $count = 1;
      
      if ($getsmiles = db_query("SELECT * FROM smiles")) {
	 echo "Please select a smile from the pile below.";
	 if (($numsmiles = db_num_rows($getsmiles)) == "0") {
	    echo "<font face=\"$FontFace\" size=2>No smiles currently. <a href='$PHP_SELF?mode=add'>Click here</a> to add some.</font>";
	 } else {
	    echo "<form method=post action=\"$PHP_SELF\"><input type='hidden' name='mode' value='edit'>";
	    while ($smiles = db_fetch_array($getsmiles)) {
	       
	       echo "<input type=\"radio\" name=\"smile\" value=\"$smiles[id]\">&nbsp;&nbsp;<img src=\"" . html_web_url($url_smiles . '/' . $smiles[smile_url], true) . "\">&nbsp;&nbsp;" . html_escape($smiles[code]) . "&nbsp;&nbsp;&nbsp;&nbsp;\n";
	       
	       if (($count % "7") == "0") {
		  echo "<br>\n";
	       }
	       $count++;
	    }
	    echo "<br><input type='submit' name='submit' value=\"Let's Edit the Smile!\">";
	 }
      }
   }
   
   break;
   
 case 'delete':
   
   if ($id_present) {
      $smile_id = $id;
      echo "<form method=\"post\" action=\"$PHP_SELF\"><input type=\"hidden\" name=\"mode\" value=\"delete\"><input type=\"hidden\" name=\"smile_id\" value=\"$smile_id\"><input type=\"submit\" name=\"submit\" value=\"Delete Smile\"></form>";
      break;
   }
   
   if (!$submit) {
      if ($getsmiles = db_query("SELECT * FROM smiles")) {
	 echo "Please select a smile from the pile below.";
	 
	 if (($numsmiles = db_num_rows($getsmiles)) == "0") {
	    echo "<font face=\"$FontFace\" size=2>No smiles currently. <a href='$PHP_SELF?mode=add'>Click here</a> to add some.</font>";
	 } else {
	    echo "<form method=post action=\"$PHP_SELF\"><input type='hidden' name='mode' value='delete'>";
	    $count = 1;
	    while ($smiles = db_fetch_array($getsmiles)) {
	       
	       echo "<input type=\"radio\" name=\"smile\" value=\"$smiles[id]\">&nbsp;&nbsp;<img src=\"" . html_web_url($url_smiles . '/' . $smiles[smile_url], true) . "\">&nbsp;&nbsp;" . html_escape($smiles[code]) . "&nbsp;&nbsp;&nbsp;&nbsp;\n";
	       echo "<input type='hidden' name='smile_id' value='$smiles[id]'>";
	       
	       if (($count % "7") == "0") {
		  echo "<br>\n";
	       }
	       $count++;
	    };
	    echo "<br><input type='submit' name='submit' value='Delete Smile'>";
	 }
      }
   } elseif ($submit == "Delete Smile") {
      
      if (!$delsmile = db_query_params("DELETE FROM smiles WHERE id = ?", array((int) $smile_id))) {
echo "<TABLE width=\"45%\" border=\"1\" cellspacing=\"0\" cellpadding=\"0\" align=\"center\" bordercolor=\"$table_bgcolor\">";
echo "<tr><td align=\"center\" width=\"100%\" bgcolor=\"$color1\"><font face=\"$FontFace\" size=\"$FontSize2\" color=\"$textcolor\"><B>Delete Smilie.</B></font></td>";
echo "</tr><TR><TD><TABLE width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><TR>";
echo "<td align=\"center\" width=\"100%\" bgcolor=\"$color2\"><font face=\"$FontFace\" size=\"$FontSize1\" color=\"$textcolor\"><P>&nbsp;&nbsp;Sorry Your Smilie Could Not Be Deleted!</font><P></TD>";
echo "</TR></table></TD></TR></TABLE>";
      } else {
echo "<TABLE width=\"45%\" border=\"1\" cellspacing=\"0\" cellpadding=\"0\" align=\"center\" bordercolor=\"$table_bgcolor\">";
echo "<tr><td align=\"center\" width=\"100%\" bgcolor=\"$color1\"><font face=\"$FontFace\" size=\"$FontSize2\" color=\"$textcolor\"><B>Delete Smilie.</B></font></td>";
echo "</tr><TR><TD><TABLE width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><TR>";
echo "<td align=\"center\" width=\"100%\" bgcolor=\"$color2\"><font face=\"$FontFace\" size=\"$FontSize1\" color=\"$textcolor\"><P>&nbsp;&nbsp;Your Smilie Has Be Deleted!</font><P></TD>";
echo "</TR></table></TD></TR></TABLE>";

      }
   }
   
   
   break;
}
   
   echo "</font></center><br><br>";
}
else {
      http_response_code(403);
      $pagetype = "admin";
      $pagetitle = "Access Denied!";
   
      include('../page_header.'.$phpEx);
   ?>
          <TABLE BORDER="0" CELLPADDING="1" CELLSPACING="0" ALIGN="CENTER" VALIGN="TOP" WIDTH="<?php echo $TableWidth?>">
          <TR><TD  BGCOLOR="<?php echo $table_bgcolor?>">
          <TABLE BORDER="0" CELLPADDING="1" CELLSPACING="1" WIDTH="100%">
          <TR BGCOLOR="<?php echo $color1?>" ALIGN="center" VALIGN="TOP">
          <TD><FONT FACE="<?php echo $FontFace?>" SIZE="<?php echo $FontSize2?>" COLOR="<?php echo $textcolor?>">
          <B>You do not have acess to this area!</b><BR>
          Go <a href="<?php echo $url_phpbb_index?>">Back</a>
          </TD></TR></TABLE></TD></TR></TABLE>
     <?php
}

include('../page_tail.'.$phpEx);
?>
