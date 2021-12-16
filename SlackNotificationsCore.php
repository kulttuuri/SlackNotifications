<?php
class SlackNotifications
{
	/**
	 * Gets nice HTML text for user containing the link to user page
	 * and also links to user site, groups editing, talk and contribs pages.
	 */
	static function getSlackUserText($user)
	{
		global $wgSlackNotificationWikiUrl, $wgSlackNotificationWikiUrlEnding, $wgSlackNotificationWikiUrlEndingUserPage,
			$wgSlackNotificationWikiUrlEndingBlockUser, $wgSlackNotificationWikiUrlEndingUserRights, 
			$wgSlackNotificationWikiUrlEndingUserTalkPage, $wgSlackNotificationWikiUrlEndingUserContributions,
			$wgSlackIncludeUserUrls;

		if ($wgSlackIncludeUserUrls)
		{
			return sprintf(
				"%s (%s | %s | %s | %s)",
				"<".$wgSlackNotificationWikiUrl.$wgSlackNotificationWikiUrlEnding.$wgSlackNotificationWikiUrlEndingUserPage.$user."|$user>",
				"<".$wgSlackNotificationWikiUrl.$wgSlackNotificationWikiUrlEnding.$wgSlackNotificationWikiUrlEndingBlockUser.$user."|block>",
				"<".$wgSlackNotificationWikiUrl.$wgSlackNotificationWikiUrlEnding.$wgSlackNotificationWikiUrlEndingUserRights.$user."|groups>",
				"<".$wgSlackNotificationWikiUrl.$wgSlackNotificationWikiUrlEnding.$wgSlackNotificationWikiUrlEndingUserTalkPage.$user."|talk>",
				"<".$wgSlackNotificationWikiUrl.$wgSlackNotificationWikiUrlEnding.$wgSlackNotificationWikiUrlEndingUserContributions.$user."|contribs>");
		}
		else
		{
			return "<".$wgSlackNotificationWikiUrl.$wgSlackNotificationWikiUrlEnding.$wgSlackNotificationWikiUrlEndingUserPage.$user."|$user>";
		}
	}

	/**
	 * Gets nice HTML text for article containing the link to article page
	 * and also into edit, delete and article history pages.
	 */
	static function getSlackArticleText(WikiPage $article, $diff = false)
	{
		global $wgSlackNotificationWikiUrl, $wgSlackNotificationWikiUrlEnding, $wgSlackNotificationWikiUrlEndingEditArticle,
			$wgSlackNotificationWikiUrlEndingDeleteArticle, $wgSlackNotificationWikiUrlEndingHistory,
			$wgSlackNotificationWikiUrlEndingDiff, $wgSlackIncludePageUrls;

		$prefix = "<".$wgSlackNotificationWikiUrl.$wgSlackNotificationWikiUrlEnding.str_replace(" ", "_", $article->getTitle()->getFullText());
		if ($wgSlackIncludePageUrls)
		{
			$out = sprintf(
				"%s (%s | %s | %s",
				$prefix."|".$article->getTitle()->getFullText().">",
				$prefix."&".$wgSlackNotificationWikiUrlEndingEditArticle."|edit>",
				$prefix."&".$wgSlackNotificationWikiUrlEndingDeleteArticle."|delete>",
				$prefix."&".$wgSlackNotificationWikiUrlEndingHistory."|history>"/*,
					"move",
					"protect",
					"watch"*/);
			if ($diff)
			{
				$out .= " | ".$prefix."&".$wgSlackNotificationWikiUrlEndingDiff.$article->getRevisionRecord()->getID()."|diff>)";
			}
			else
			{
				$out .= ")";
			}
			return $out;
		}
		else
		{
			return $prefix."|".$article->getTitle()->getFullText().">";
		}
	}

	/**
	 * Gets nice HTML text for title object containing the link to article page
	 * and also into edit, delete and article history pages.
	 */
	static function getSlackTitleText(Title $title)
	{
		global $wgSlackNotificationWikiUrl, $wgSlackNotificationWikiUrlEnding, $wgSlackNotificationWikiUrlEndingEditArticle,
			$wgSlackNotificationWikiUrlEndingDeleteArticle, $wgSlackNotificationWikiUrlEndingHistory,
			$wgSlackIncludePageUrls;

		$titleName = $title->getFullText();
		if ($wgSlackIncludePageUrls)
		{
			return sprintf(
				"%s (%s | %s | %s)",
				"<".$wgSlackNotificationWikiUrl.$wgSlackNotificationWikiUrlEnding.$titleName."|".$titleName.">",
				"<".$wgSlackNotificationWikiUrl.$wgSlackNotificationWikiUrlEnding.$titleName."&".$wgSlackNotificationWikiUrlEndingEditArticle."|edit>",
				"<".$wgSlackNotificationWikiUrl.$wgSlackNotificationWikiUrlEnding.$titleName."&".$wgSlackNotificationWikiUrlEndingDeleteArticle."|delete>",
				"<".$wgSlackNotificationWikiUrl.$wgSlackNotificationWikiUrlEnding.$titleName."&".$wgSlackNotificationWikiUrlEndingHistory."|history>"/*,
						"move",
						"protect",
						"watch"*/);
		}
		else
		{
			return "<".$wgSlackNotificationWikiUrl.$wgSlackNotificationWikiUrlEnding.$titleName."|".$titleName.">";
		}
	}

	/**
	 * Returns whether the given title should be excluded
	 */
	private static function titleIsExcluded( $title ) {
		global $wgSlackExcludeNotificationsFrom;
		if ( is_array( $wgSlackExcludeNotificationsFrom ) && count( $wgSlackExcludeNotificationsFrom ) > 0 ) {
			foreach ( $wgSlackExcludeNotificationsFrom as &$currentExclude ) {
				if ( 0 === strpos( $title, $currentExclude ) ) return true;
			}
		}
		return false;
	}

	/**
	 * Occurs after an article has been created or edited.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageSaveComplete
	 */
	static function slack_article_saved($wikiPage, $user, $summary, $flags, $revisionRecord, $editResult)
	{
		global $wgSlackNotificationEditedArticle, $wgSlackIgnoreMinorEdits,
			$wgSlackNotificationAddedArticle, $wgSlackIncludeDiffSize;
		$isNew = (bool)( $flags & EDIT_NEW );
		
		if ( !$wgSlackNotificationEditedArticle && !$isNew ) return true;
		if ( !$wgSlackNotificationAddedArticle && $isNew ) return true;
		if ( self::titleIsExcluded( $wikiPage->getTitle() ) ) return true;
		// Ignore null / empty edits (https://en.wikipedia.org/wiki/Wikipedia:Purge#Null_edit)
		if ($editResult->isNullEdit() == 1) return true;

		// Do not announce newly added file uploads as articles...
		if ( $wikiPage->getTitle()->getNsText() && $wikiPage->getTitle()->getNsText() == 'File' ) return true;
		
		if ( $isNew ) {
			$message = sprintf(
				"%s has %s article %s %s",
				self::getSlackUserText( $user ),
				"created",
				self::getSlackArticleText( $wikiPage ),
				$summary == "" ? "" : "Summary: $summary"
			);
			if ( $wgSlackIncludeDiffSize ) {
				$message .= sprintf(
					" (%+d bytes)",
					$revisionRecord->getSize());
			}
			self::push_slack_notify($message, "yellow", $user);
		} else {
			$isMinor = (bool)( $flags & EDIT_MINOR );
			// Skip minor edits if user wanted to ignore them
			if ( $isMinor && $wgSlackIgnoreMinorEdits ) return true;
			
			$message = sprintf(
				'%s has %s article %s %s',
				self::getSlackUserText( $user ),
				$isMinor == true ? "made minor edit to" : "edited",
				self::getSlackArticleText( $wikiPage, true ),
				$summary == "" ? "" : "Summary: $summary"
			);
			
			if ( $wgSlackIncludeDiffSize ) {
				$message .= sprintf(
					" (%+d bytes)",
					$revisionRecord->getSize());
			}
			self::push_slack_notify($message, "yellow", $user);
		}
		return true;
	}

	/**
	 * Occurs after the delete article request has been processed.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/ArticleDeleteComplete
	 */
	static function slack_article_deleted(WikiPage $article, $user, $reason, $id)
	{
		global $wgSlackNotificationRemovedArticle;
		if (!$wgSlackNotificationRemovedArticle) return;

		// Discard notifications from excluded pages
		global $wgSlackExcludeNotificationsFrom;
		if (count($wgSlackExcludeNotificationsFrom) > 0) {
			foreach ($wgSlackExcludeNotificationsFrom as &$currentExclude) {
				if (0 === strpos($article->getTitle(), $currentExclude)) return;
			}
		}
		// Discard notifications from non-included pages
		global $wgSlackIncludeNotificationsFrom;
		if (count($wgSlackIncludeNotificationsFrom) > 0) {
			foreach ($wgSlackIncludeNotificationsFrom as &$currentInclude) {
				if (0 !== strpos($article->getTitle(), $currentInclude)) return;
			}
		}

		$message = sprintf(
			"%s has deleted article %s Reason: %s",
			self::getSlackUserText($user),
			self::getSlackArticleText($article),
			$reason);
		self::push_slack_notify($message, "red", $user);
		return true;
	}

	/**
	 * Occurs after a page has been moved.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/TitleMoveComplete
	 */
	static function slack_article_moved($title, $newtitle, $user, $oldid, $newid, $reason = null)
	{
		global $wgSlackNotificationMovedArticle;
		if (!$wgSlackNotificationMovedArticle) return;

		// Discard notifications from excluded pages
		global $wgSlackExcludeNotificationsFrom;
		if (count($wgSlackExcludeNotificationsFrom) > 0) {
			foreach ($wgSlackExcludeNotificationsFrom as &$currentExclude) {
				if (0 === strpos($title, $currentExclude)) return;
				if (0 === strpos($newtitle, $currentExclude)) return;
			}
		}
		// Discard notifications from non-included pages
		global $wgSlackIncludeNotificationsFrom;
		if (count($wgSlackIncludeNotificationsFrom) > 0) {
			foreach ($wgSlackIncludeNotificationsFrom as &$currentInclude) {
				if (0 !== strpos($title, $currentInclude)) return;
				if (0 !== strpos($newtitle, $currentInclude)) return;
			}
		}

		$message = sprintf(
			"%s has moved article %s to %s. Reason: %s",
			self::getSlackUserText($user),
			self::getSlackTitleText($title),
			self::getSlackTitleText($newtitle),
			$reason);
		self::push_slack_notify($message, "green", $user);
		return true;
	}

	/**
	 * Occurs after the protect article request has been processed.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleProtectComplete
	 */
	static function slack_article_protected($article, $user, $protect, $reason, $moveonly = false)
	{
		global $wgSlackNotificationProtectedArticle;
		if (!$wgSlackNotificationProtectedArticle) return;
		$message = sprintf(
			"%s has %s article %s. Reason: %s",
			self::getSlackUserText($user),
			$protect ? "changed protection of" : "removed protection of",
			self::getSlackArticleText($article),
			$reason);
		self::push_slack_notify($message, "yellow", $user);
		return true;
	}

	/**
	 * Called after a user account is created.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/AddNewAccount
	 */
	static function slack_new_user_account($user, $byEmail)
	{
		global $wgSlackNotificationNewUser, $wgSlackShowNewUserEmail, $wgSlackShowNewUserFullName, $wgSlackShowNewUserIP;
		if (!$wgSlackNotificationNewUser) return;

		$email = "";
		$realname = "";
		$ipaddress = "";
		try { $email = $user->getEmail(); } catch (Exception $e) {}
		try { $realname = $user->getRealName(); } catch (Exception $e) {}
		try { $ipaddress = $user->getRequest()->getIP(); } catch (Exception $e) {}
		$messageExtra = "";
		if ($wgSlackShowNewUserEmail || $wgSlackShowNewUserFullName || $wgSlackShowNewUserIP) {
			$messageExtra = "(";
			if ($wgSlackShowNewUserEmail) $messageExtra .= $email . ", ";
			if ($wgSlackShowNewUserFullName) $messageExtra .= $realname . ", ";
			if ($wgSlackShowNewUserIP) $messageExtra .= $ipaddress . ", ";
			$messageExtra = substr($messageExtra, 0, -2); // Remove trailing , 
			$messageExtra .= ")";
		}

		$message = sprintf(
			"New user account %s was just created %s",
			self::getSlackUserText($user),
			$messageExtra);
		self::push_slack_notify($message, "green", $user);
		return true;
	}

	/**
	 * Called when a file upload has completed.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/UploadComplete
	 */
	static function slack_file_uploaded($image)
	{
		global $wgSlackNotificationFileUpload;
		if (!$wgSlackNotificationFileUpload) return;

		global $wgSlackNotificationWikiUrl, $wgSlackNotificationWikiUrlEnding, $wgUser;
		$message = sprintf(
			"%s has uploaded file <%s|%s> (format: %s, size: %s MB, summary: %s)",
			self::getSlackUserText($wgUser->mName),
			$wgSlackNotificationWikiUrl . $wgSlackNotificationWikiUrlEnding . $image->getLocalFile()->getTitle(),
			$image->getLocalFile()->getTitle(),
			$image->getLocalFile()->getMimeType(),
			round($image->getLocalFile()->size / 1024 / 1024, 3),
			$image->getLocalFile()->getDescription());

		self::push_slack_notify($message, "green", $wgUser);
		return true;
	}

	/**
	 * Occurs after the request to block an IP or user has been processed
	 * @see http://www.mediawiki.org/wiki/Manual:MediaWiki_hooks/BlockIpComplete
	 */
	static function slack_user_blocked(Block $block, $user)
	{
		global $wgSlackNotificationBlockedUser;
		if (!$wgSlackNotificationBlockedUser) return;

		global $wgSlackNotificationWikiUrl, $wgSlackNotificationWikiUrlEnding, $wgSlackNotificationWikiUrlEndingBlockList;
 		if (defined('MW_VERSION') && version_compare(MW_VERSION, '1.35', '>=')) {  //DatabaseBlock::$mReason was made protected in MW 1.35
 			$mReason = $block->getReasonComment()->text;
 		} else {
 			$mReason = $block->mReason;
 		}
		$message = sprintf(
			"%s has blocked %s %s Block expiration: %s. %s",
			self::getSlackUserText($user),
			self::getSlackUserText($block->getTarget()),
			$mReason == "" ? "" : "with reason '".$mReason."'.",
			$block->mExpiry,
			"<".$wgSlackNotificationWikiUrl.$wgSlackNotificationWikiUrlEnding.$wgSlackNotificationWikiUrlEndingBlockList."|List of all blocks>.");
		self::push_slack_notify($message, "red", $user);
		return true;
	}

	/**
	 * Occurs after the user groups (rights) have been changed
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/UserGroupsChanged
	 */
	static function slack_user_groups_changed(User $user, array $added, array $removed, $performer, $reason, $oldUGMs, $newUGMs)
	{
		global $wgSlackNotificationUserGroupsChanged;
		if (!$wgSlackNotificationUserGroupsChanged) return;

		global $wgSlackNotificationWikiUrl, $wgSlackNotificationWikiUrlEnding, $wgSlackNotificationWikiUrlEndingUserRights;
		$message = sprintf(
            "%s has changed user groups for %s. New groups: %s",
			self::getSlackUserText($performer),
			self::getSlackUserText($user),
			implode(", ", $user->getGroups()));
		self::push_slack_notify($message, "green", $user);
		return true;
	}

	/**
	 * Sends the message into Slack room.
	 * @param message Message to be sent.
	 * @param color Background color for the message. One of "green", "yellow" or "red". (default: yellow)
	 * @see https://api.slack.com/incoming-webhooks
	 */
	static function push_slack_notify($message, $bgColor, $user)
	{
		global $wgSlackIncomingWebhookUrl, $wgSlackFromName, $wgSlackRoomName, $wgSlackSendMethod, $wgSlackExcludedPermission, $wgSitename, $wgSlackEmoji, $wgHTTPProxy;
		
		if ( $wgSlackExcludedPermission != "" ) {
			if ( $user->isAllowed( $wgSlackExcludedPermission ) )
			{
				return; // Users with the permission suppress notifications
			}
		}

		$slackColor = "warning";
		if ($bgColor == "green") $slackColor = "good";
		else if ($bgColor == "red") $slackColor = "danger";
		
		$optionalChannel = "";
		if (!empty($wgSlackRoomName)) {
			$optionalChannel = ' "channel": "'.$wgSlackRoomName.'", ';
		}

		// Convert " to ' in the message to be sent as otherwise JSON formatting would break.
		$message = str_replace('"', "'", $message);
		//Ensure a random \ is not read as the beginning of an escape character. Windows users may be authenticated with a username containing domain\user, so to ensure \ is encoded as \\...
		$message = str_replace('\\', "\\\\", $message);

		$slackFromName = $wgSlackFromName;
		if ( $slackFromName == "" )
		{
			$slackFromName = $wgSitename;
		}
		
		$post = sprintf('payload={"username": "%s",'.$optionalChannel.' "attachments": [ { "text": "%s", "color": "%s" } ]',
		urlencode($slackFromName),
		urlencode($message),
		urlencode($slackColor));
		if ( $wgSlackEmoji != "" )
		{
			$post .= sprintf( ', "icon_emoji": "%s"', $wgSlackEmoji );
		}
		$post .= '}';

		// Use file_get_contents to send the data. Note that you will need to have allow_url_fopen enabled in php.ini for this to work.
		if ($wgSlackSendMethod == "file_get_contents") {
			$extradata = array(
				'http' => array(
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => $post,
				),
			);
			$context = stream_context_create($extradata);
			$result = file_get_contents($wgSlackIncomingWebhookUrl, false, $context);
		}
		// Call the Slack API through cURL (default way). Note that you will need to have cURL enabled for this to work.
		else {
			$h = curl_init();
			curl_setopt($h, CURLOPT_URL, $wgSlackIncomingWebhookUrl);
			curl_setopt($h, CURLOPT_POST, 1);
			curl_setopt($h, CURLOPT_POSTFIELDS, $post);
			curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
			// Commented out lines below. Using default curl settings for host and peer verification.
			//curl_setopt ($h, CURLOPT_SSL_VERIFYHOST, 0);
			//curl_setopt ($h, CURLOPT_SSL_VERIFYPEER, 0);
			// Set proxy for the request if user had proxy URL set
			if ($wgHTTPProxy) {
				curl_setopt($h, CURLOPT_PROXY, $wgHTTPProxy);
			}
			// ... Aaand execute the curl script!
			$ok = curl_exec($h);
			curl_close($h);
		}
	}
}
?>
