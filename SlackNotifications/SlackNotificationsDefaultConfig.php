<?php
/**#@+
 * This extension integrates Slack with MediaWiki. Sends Slack notifications
 * for selected actions that have occurred in your MediaWiki sites.
 *
 * This file contains configuration options for the extension.
 *
 * @ingroup Extensions
 * @link https://github.com/kulttuuri/slack_mediawiki
 * @author Aleksi Postari / kulttuuri <aleksi@postari.net>
 * @copyright Copyright © 2016, Aleksi Postari
 * @license http://en.wikipedia.org/wiki/MIT_License MIT
 */

if(!defined('MEDIAWIKI')) die();
if (!isset($hpc_attached)) die();

########################
# SLACK API PARAMETERS #
########################
// Basic Slack configurations.
	
	// MANDATORY
	
	// Required. Your Slack incoming webhook URL. Read more from here: https://api.slack.com/incoming-webhooks
	$wgSlackIncomingWebhookUrl = "";
	// Required. Name the message will appear be sent from.
	$wgSlackFromName = $wgSitename;
	
	// OPTIONAL
	// Slack room #name where you want all the notifications to go into. When you setup a webhook you can already define a room so setting this is not required. Remember to add # before the roomname.
	$wgSlackRoomName = "";
	// What method will be used to send the data to Slack server. By default this is "curl" which only works if you have the curl extension enabled. This can be: "curl" or "file_get_contents". Default: "curl".
	$wgSlackSendMethod = "curl";
	// If this is true, pages will get additional links in the notification message (edit | delete | history).
	$wgSlackIncludePageUrls = true;
	// If this is true, users will get additional links in the notification message (block | groups | talk | contribs).
	$wgSlackIncludeUserUrls = true;
	// If this is true, all minor edits made to articles will not be submitted to Slack.
	$wgSlackIgnoreMinorEdits = false;
	// If this is set, actions by users with this permission won't cause alerts
	$wgExcludedPermission = "";
	
##################
# MEDIAWIKI URLS #
##################
// URLs into your MediaWiki installation.
	
	// MANDATORY
	
	// URL into your MediaWiki installation with the trailing /.
	$wgWikiUrl		= "";
	// Wiki script name. Leave this to default one if you do not have URL rewriting enabled.
	$wgWikiUrlEnding = "index.php?title=";
	
	// OPTIONAL
	
	$wgWikiUrlEndingUserRights          = "Special%3AUserRights&user=";
	$wgWikiUrlEndingBlockUser           = "Special:Block/";
	$wgWikiUrlEndingUserPage            = "User:";
	$wgWikiUrlEndingUserTalkPage        = "User_talk:";
	$wgWikiUrlEndingUserContributions   = "Special:Contributions/";
	$wgWikiUrlEndingBlockList           = "Special:BlockList";
	$wgWikiUrlEndingEditArticle         = "action=edit";
	$wgWikiUrlEndingDeleteArticle       = "action=delete";
	$wgWikiUrlEndingHistory             = "action=history";

#####################
# MEDIAWIKI ACTIONS #
#####################
// MediaWiki actions that will be sent notifications of into Slack.
// Set desired options to false to disable notifications of those actions.
	
	// New user added into MediaWiki
	$wgSlackNotificationNewUser = true;
	// User or IP blocked in MediaWiki
	$wgSlackNotificationBlockedUser = true;
	// Article added to MediaWiki
	$wgSlackNotificationAddedArticle = true;
	// Article removed from MediaWiki
	$wgSlackNotificationRemovedArticle = true;
	// Article moved under another title
	$wgSlackNotificationMovedArticle = true;
	// Article edited in MediaWiki
	$wgSlackNotificationEditedArticle = true;
	// File uploaded
	$wgSlackNotificationFileUpload = true;
?>
