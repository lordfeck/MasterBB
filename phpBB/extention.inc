<?php
/*******************************************************
 * This File simply holds the file extention for your
 * phpBB files. If you use .php simply leave this file
 * alone
 * *****************************************************/
$phpEx = "php";

/*
 * PHP 3/4 exposed request data through long arrays and register_globals.
 * Preserve that input shape at this compatibility boundary for now.
 */
$HTTP_GET_VARS =& $_GET;
$HTTP_POST_VARS =& $_POST;
$HTTP_COOKIE_VARS =& $_COOKIE;
$HTTP_SERVER_VARS =& $_SERVER;

foreach (array($_GET, $_POST, $_COOKIE) as $request_values)
{
    foreach ($request_values as $request_key => $request_value)
    {
        if ($request_key !== 'GLOBALS' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $request_key))
        {
            $GLOBALS[$request_key] = $request_value;
        }
    }
}

$PHP_SELF = $_SERVER['PHP_SELF'] ?? '';
$REQUEST_URI = $_SERVER['REQUEST_URI'] ?? '';
$REQUEST_METHOD = $_SERVER['REQUEST_METHOD'] ?? '';
$REMOTE_ADDR = $_SERVER['REMOTE_ADDR'] ?? '';
$SERVER_NAME = $_SERVER['SERVER_NAME'] ?? 'localhost';

/* PHP 8 no longer treats bare array keys as their string names. */
$legacy_array_keys = 'access_id access_title ban_end ban_id ban_ip ban_start ban_time_type ban_userid bbcode bgcolor can_post cat_id cat_order cat_title code color1 color2 count disallow_id disallow_username emotion fontface fontsize1 fontsize2 fontsize3 fontsize4 footer forum forum_access forum_desc forum_id forum_moderator forum_name forum_type from_userid header header_image id linkcolor meta msg_id msg_text msg_time newtopic_image ordered post_id post_text post_time postcount poster_id poster_ip poster_name rank_id rank_image rank_max rank_min rank_title replacement reply_image replylocked_image smile_url table_bgcolor tablewidth textcolor theme_default theme_id theme_name to_userid topic_id topic_notify topic_status topic_title topic_views total unordered user_aim user_attachsig user_bbcode user_count user_desmile user_email user_from user_html user_icq user_id user_intrest user_level user_msnm user_occ user_password user_posts user_rank user_regdate user_sig user_viewemail user_web user_website user_yim username vlinkcolor word word_id';
foreach (explode(' ', $legacy_array_keys) as $legacy_array_key)
{
    if (!defined($legacy_array_key))
    {
        define($legacy_array_key, $legacy_array_key);
    }
}
unset($legacy_array_key, $legacy_array_keys, $request_key, $request_value, $request_values);
?>
