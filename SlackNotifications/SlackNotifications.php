<?php
/**#@+
 * This extension integrates Slack with MediaWiki. Sends Slack notifications
 * for selected actions that have occurred in your MediaWiki sites.
 *
 * This file contains functionality for the extension.
 *
 * @ingroup Extensions
 * @link https://github.com/kulttuuri/slack_mediawiki
 * @author Aleksi Postari / kulttuuri <aleksi@postari.net>
 * @copyright Copyright © 2016, Aleksi Postari
 * @license http://en.wikipedia.org/wiki/MIT_License MIT
 */

if (!defined('MEDIAWIKI')) die();

$hpc_attached = true;
require_once("SlackNotificationsCore.php");
require_once("SlackNotificationsDefaultConfig.php");

$wgHooks['ArticleSaveComplete'][] = array('SlackNotifications::slack_article_saved');			// When article has been saved
$wgHooks['ArticleInsertComplete'][] = array('SlackNotifications::slack_article_inserted');		// When new article has been inserted
$wgHooks['ArticleDeleteComplete'][] = array('SlackNotifications::slack_article_deleted');		// When article has been removed
$wgHooks['TitleMoveComplete'][] = array('SlackNotifications::slack_article_moved');				// When article has been moved
$wgHooks['AddNewAccount'][] = array('SlackNotifications::slack_new_user_account');				// When new user account is created
$wgHooks['BlockIpComplete'][] = array('SlackNotifications::slack_user_blocked');				// When user or IP has been blocked
$wgHooks['UploadComplete'][] = array('SlackNotifications::slack_file_uploaded');				// When file has been uploaded

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'Slack Notifications',
	'author' => 'Aleksi Postari',
	'description' => 'Sends Slack notifications for selected actions that have occurred in your MediaWiki sites.',
	'url' => 'https://github.com/kulttuuri/slack_mediawiki',
	"version" => "1.04"
);
?>
