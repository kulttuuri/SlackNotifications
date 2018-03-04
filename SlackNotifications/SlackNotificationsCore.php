<?php
class SlackNotifications
{
	/**
	 * Gets nice HTML text for user containing the link to user page
	 * and also links to user site, groups editing, talk and contribs pages.
	 */
	static function getSlackUserText($user)
	{
		global $wgWikiUrl, $wgWikiUrlEnding, $wgWikiUrlEndingUserPage,
			$wgWikiUrlEndingBlockUser, $wgWikiUrlEndingUserRights, 
			$wgWikiUrlEndingUserTalkPage, $wgWikiUrlEndingUserContributions,
			$wgSlackIncludeUserUrls;
		
		if ($wgSlackIncludeUserUrls)
		{
			return sprintf(
				"%s (%s | %s | %s | %s)",
				"<".$wgWikiUrl.$wgWikiUrlEnding.$wgWikiUrlEndingUserPage.$user."|$user>",
				"<".$wgWikiUrl.$wgWikiUrlEnding.$wgWikiUrlEndingBlockUser.$user."|block>",
				"<".$wgWikiUrl.$wgWikiUrlEnding.$wgWikiUrlEndingUserRights.$user."|groups>",
				"<".$wgWikiUrl.$wgWikiUrlEnding.$wgWikiUrlEndingUserTalkPage.$user."|talk>",
				"<".$wgWikiUrl.$wgWikiUrlEnding.$wgWikiUrlEndingUserContributions.$user."|contribs>");
		}
		else
		{
			return "<".$wgWikiUrl.$wgWikiUrlEnding.$wgWikiUrlEndingUserPage.$user."|$user>";
		}
	}

	/**
	 * Gets nice HTML text for article containing the link to article page
	 * and also into edit, delete and article history pages.
	 */
	static function getSlackArticleText(WikiPage $article, $diff = false)
	{
		global $wgWikiUrl, $wgWikiUrlEnding, $wgWikiUrlEndingEditArticle,
			$wgWikiUrlEndingDeleteArticle, $wgWikiUrlEndingHistory,
			$wgWikiUrlEndingDiff, $wgSlackIncludePageUrls;

		$prefix = "<".$wgWikiUrl.$wgWikiUrlEnding.str_replace(" ", "_", $article->getTitle()->getFullText());
		if ($wgSlackIncludePageUrls)
		{
			$out = sprintf(
				"%s (%s | %s | %s",
				$prefix."|".$article->getTitle()->getFullText().">",
				$prefix."&".$wgWikiUrlEndingEditArticle."|edit>",
				$prefix."&".$wgWikiUrlEndingDeleteArticle."|delete>",
				$prefix."&".$wgWikiUrlEndingHistory."|history>"/*,
					"move",
					"protect",
					"watch"*/);
			if ($diff)
			{
				$out .= " | ".$prefix."&".$wgWikiUrlEndingDiff.$article->getRevision()->getID()."|diff>)";
			}
			else
			{
				$out .= ")";
			}
			return $out."\\n";
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
		global $wgWikiUrl, $wgWikiUrlEnding, $wgWikiUrlEndingEditArticle,
			$wgWikiUrlEndingDeleteArticle, $wgWikiUrlEndingHistory,
			$wgSlackIncludePageUrls;

		$titleName = $title->getFullText();
		if ($wgSlackIncludePageUrls)
		{
			return sprintf(
				"%s (%s | %s | %s)",
				"<".$wgWikiUrl.$wgWikiUrlEnding.$titleName."|".$titleName.">",
				"<".$wgWikiUrl.$wgWikiUrlEnding.$titleName."&".$wgWikiUrlEndingEditArticle."|edit>",
				"<".$wgWikiUrl.$wgWikiUrlEnding.$titleName."&".$wgWikiUrlEndingDeleteArticle."|delete>",
				"<".$wgWikiUrl.$wgWikiUrlEnding.$titleName."&".$wgWikiUrlEndingHistory."|history>"/*,
						"move",
						"protect",
						"watch"*/);
		}
		else
		{
			return "<".$wgWikiUrl.$wgWikiUrlEnding.$titleName."|".$titleName.">";
		}
	}

	/**
	 * Occurs after the save page request has been processed.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentSaveComplete
	 */
	static function slack_article_saved(WikiPage $article, $user, $content, $summary, $isMinor, $isWatch, $section, $flags, $revision, $status, $baseRevId)
	{
		global $wgSlackNotificationEditedArticle;
		global $wgSlackIgnoreMinorEdits, $wgSlackIncludeDiffSize;
		if (!$wgSlackNotificationEditedArticle) return;

		// Discard notifications from excluded pages
		global $wgSlackExcludeNotificationsFrom;
		if (count($wgSlackExcludeNotificationsFrom) > 0) {
			foreach ($wgSlackExcludeNotificationsFrom as &$currentExclude) {
				if (0 === strpos($article->getTitle(), $currentExclude)) return;
			}
		}

		// Skip new articles that have view count below 1. Adding new articles is already handled in article_added function and
		// calling it also here would trigger two notifications!
		$isNew = $status->value['new']; // This is 1 if article is new
		if ($isNew == 1) {
			return true;
		}

		// Skip minor edits if user wanted to ignore them
		if ($isMinor && $wgSlackIgnoreMinorEdits) return;
		
		if ( $article->getRevision()->getPrevious() == NULL )
		{
			return; // Skip edits that are just refreshing the page
		}
		
		$message = sprintf(
			"%s has %s article %s %s",
			self::getSlackUserText($user),
			$isMinor == true ? "made minor edit to" : "edited",
			self::getSlackArticleText($article, true),
			$summary == "" ? "" : "Summary: $summary");
		if ($wgSlackIncludeDiffSize)
		{		
			$message .= sprintf(
				" (%+d bytes)",
				$article->getRevision()->getSize() - $article->getRevision()->getPrevious()->getSize());
		}
		self::push_slack_notify($message, "yellow", $user);
		return true;
	}

	/**
	 * Occurs after a new article has been created.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/ArticleInsertComplete
	 */
	static function slack_article_inserted(WikiPage $article, $user, $text, $summary, $isminor, $iswatch, $section, $flags, $revision)
	{
		global $wgSlackNotificationAddedArticle, $wgSlackIncludeDiffSize;
		if (!$wgSlackNotificationAddedArticle) return;

		// Discard notifications from excluded pages
		global $wgSlackExcludeNotificationsFrom;
		if (count($wgSlackExcludeNotificationsFrom) > 0) {
			foreach ($wgSlackExcludeNotificationsFrom as &$currentExclude) {
				if (0 === strpos($article->getTitle(), $currentExclude)) return;
			}
		}

		// Do not announce newly added file uploads as articles...
		if ($article->getTitle()->getNsText() == "File") return true;
		
		$message = sprintf(
			"%s has created article %s %s",
			self::getSlackUserText($user),
			self::getSlackArticleText($article),
			$summary == "" ? "" : "Summary: $summary");
		if ($wgSlackIncludeDiffSize)
		{		
			$message .= sprintf(
				" (%d bytes)",
				$article->getRevision()->getSize());
		}
		self::push_slack_notify($message, "green", $user);
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
	static function slack_article_protected($article, $user, $protect, $reason, $moveonly)
	{
		global $wgSlackNotificationProtectedArticle;
		if (!$wgSlackNotificationProtectedArticle) return;
		$message = sprintf(
			"%s has %s article %s. Reason: %s",
			self::getSlackUserText($user),
			$protect ? "changed protection of" : "removed protection of",
			self::getSlackArticleText($article),
			$reason);
		self::push_slack_notify($message, $user);
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

		global $wgWikiUrl, $wgWikiUrlEnding, $wgUser;
		$message = sprintf(
			"%s has uploaded file <%s|%s> (format: %s, size: %s MB, summary: %s)",
			self::getSlackUserText($wgUser->mName),
			$wgWikiUrl . $wgWikiUrlEnding . $image->getLocalFile()->getTitle(),
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

		global $wgWikiUrl, $wgWikiUrlEnding, $wgWikiUrlEndingBlockList;
		$message = sprintf(
			"%s has blocked %s %s Block expiration: %s. %s",
			self::getSlackUserText($user),
			self::getSlackUserText($block->getTarget()),
			$block->mReason == "" ? "" : "with reason '".$block->mReason."'.",
			$block->mExpiry,
			"<".$wgWikiUrl.$wgWikiUrlEnding.$wgWikiUrlEndingBlockList."|List of all blocks>.");
		self::push_slack_notify($message, "red", $user);
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
		global $wgSlackIncomingWebhookUrl, $wgSlackFromName, $wgSlackRoomName, $wgSlackSendMethod, $wgExcludedPermission, $wgSitename, $wgSlackEmoji, $wgHTTPProxy;
		
		if ( $wgExcludedPermission != "" ) {
			if ( $user->isAllowed( $wgExcludedPermission ) )
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
			// I know this shouldn't be done, but because it wouldn't otherwise work because of SSL...
			curl_setopt ($h, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt ($h, CURLOPT_SSL_VERIFYPEER, 0);
			// Set proxy for the request if user had proxy URL set
			if ($wgHTTPProxy) {
				curl_setopt($h, CURLOPT_PROXY, $wgHTTPProxy);
				curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
			}
			// ... Aaand execute the curl script!
			curl_exec($h);
			curl_close($h);
		}
	}
}
?>
