<?php
use MediaWiki\MediaWikiServices;

class SlackNotifications
{
	/** @var MediaWikiServices The services object */
	private static $mwservices = null;

	/** @var Config The mediawiki site config object */
	private static $mwconfig = null;

	/** @var Config The extension config object */
	private static $snconfig = null;


	/**
	 * Initializes (if needed) and returns the site config object
	 *
	 * @return Config
	 */
	private static function getMwConfig()
	{
		if (self::$mwconfig == null) {
			if (self::$mwservices === null) {
				self::$mwservices = MediaWikiServices::getInstance();
			}
			self::$mwconfig = self::$mwservices->getMainConfig();
		}
		return self::$mwconfig;
	}

	/**
	 * Initializes (if needed) and returns the extension config object
	 *
	 * @return Config
	 */
	private static function getExtConfig()
	{
		if (self::$snconfig == null) {
			if (self::$mwservices === null) {
				self::$mwservices = MediaWikiServices::getInstance();
			}
			self::$snconfig = self::$mwservices->getConfigFactory()->makeConfig('SlackNotifications');
		}
		return self::$snconfig;
	}

	/**
	 * Gets nice HTML text for user containing the link to user page
	 * and also links to user site, groups editing, talk and contribs pages.
	 * @param User $user The user object
	 * @return string
	 */
	private static function getSlackUserText(User $user)
	{
		$config                 = self::getExtConfig();
		$wgSlackIncludeUserUrls = $config->get("SlackIncludeUserUrls");

		$title   = $user->getUserPage();
		$block   = new SpecialBlock();
		$rights  = new UserrightsPage();
		$contrib = new SpecialContributions();

		if ($wgSlackIncludeUserUrls) {
			return sprintf(
				"<%s|%s> (<%s|block> | <%s|groups> | <%s|talk> | <%s|contribs>)",
				$title->getFullUrl(),
				$user,
				$block->getPageTitle()->getFullUrl() . "/" . urlencode($user),
				$rights->getPageTitle()->getFullUrl() . "/" . urlencode($user),
				$user->getTalkPage()->getFullUrl(),
				$contrib->getPageTitle()->getFullUrl . "/" . urlencode($user)
			);
		} else {
			return sprintf("<%s|%s>", $title->getFullUrl(), $user);
		}
	}

	/**
	 * Gets nice HTML text for article containing the link to article page
	 * and also into edit, delete and article history pages.
	 * @param WikiPage $article The page object
	 * @param bool $diff Whether to include a link to the diff
	 * @return string
	 */
	private static function getSlackArticleText(WikiPage $article, $diff = false)
	{
		$config                 = self::getExtConfig();
		$wgSlackIncludePageUrls = $config->get("SlackIncludePageUrls");

		$title = $article->getTitle();
		if ($wgSlackIncludePageUrls) {
			$out = sprintf(
				"<%s|%s> (<%s|edit> | <%s|delete> | <%s|history>",
				$title->getFullUrl(),
				$title->getFullText(),
				$title->getFullUrl(array("action"=>"edit")),
				$title->getFullUrl(array("action"=>"delete")),
				$title->getFullUrl(array("action"=>"history"))
			);
			if ($diff) {
				$revid = $article->getRevision()->getID();
				$out .= sprintf(
					" | <%s|diff>)",
					$title->getFullUrl(array("type"=>"revision", "diff"=>$revid))
				);
			} else {
				$out .= ")";
			}
			return $out."\\n";
		} else {
			return sprintf("<%s|%s>", $title->getFullUrl, $title->getFullText());
		}
	}

	/**
	 * Gets nice HTML text for title object containing the link to article page
	 * and also into edit, delete and article history pages.
	 * @param Title $title The title object of the page
	 * @return string
	 */
	private static function getSlackTitleText(Title $title)
	{
		$config                 = self::getExtConfig();
		$wgSlackIncludePageUrls = $config->get("SlackIncludePageUrls");

		if ($wgSlackIncludePageUrls) {
			return sprintf(
				"<%s|%s> (<%s|edit> | <%s|delete> | <%s|history>)",
				$title->getFullUrl(),
				$title->getFullText(),
				$title->getFullUrl(array("action"=>"edit")),
				$title->getFullUrl(array("action"=>"delete")),
				$title->getFullUrl(array("action"=>"history"))
			);
		} else {
			return sprintf("<%s|%s>", $title->getFullUrl(), $title->getFullText());
		}
	}

	/**
	 * Occurs after the save page request has been processed.
	 * @param WikiPage $article The page object that was updated
	 * @param User $user The user making the change
	 * @param Content $content The new page content
	 * @param string $summary The edit summary
	 * @param bool $isMinor Whether the edit was marked as minor
	 * @param bool $isWatch Whether the editor is now watching the page
	 * @param null $section Not used
	 * @param int $flags Bitfield of options
	 * @param Revision $revision The revision object created by the edit
	 * @param Status $status The status object yet to be returned
	 * @param int $baseRevId The revision ID the change was based on
	 * @param int $undidRevId The revision ID this change undid, if any
	 * @return void
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentSaveComplete
	 */
	public static function slack_article_saved(
		WikiPage $article,
		User $user,
		Content $content,
		$summary,
		$isMinor,
		$isWatch,
		$section,
		$flags,
		Revision $revision,
		Status $status,
		$baseRevId,
		$undidRevId = 0
	) {
		$config                           = self::getExtConfig();
		$wgSlackIncludeDiffSize           = $config->get("SlackIncludeDiffSize");
		$wgSlackIgnoreMinorEdits          = $config->get("SlackIgnoreMinorEdits");
		$wgSlackExcludeNotificationsFrom  = $config->get("SlackExcludeNotificationsFrom");
		$wgSlackNotificationEditedArticle = $config->get("SlackNotificationEditedArticle");;

		if (!$wgSlackNotificationEditedArticle) {
			return;
		}

		// Discard notifications from excluded pages
		if (is_array($wgSlackExcludeNotificationsFrom)) {
			foreach ($wgSlackExcludeNotificationsFrom as $currentExclude) {
				if (0 === strpos($article->getTitle(), $currentExclude)) {
					return;
				}
			}
		}

		// Skip new articles that have view count below 1. Adding new articles is already handled in article_added function and
		// calling it also here would trigger two notifications!
		// Skip minor edits if user wanted to ignore them
		if ((int)$status->value['new'] === 1 || ($isMinor && $wgSlackIgnoreMinorEdits)) {
			return;
		}

		if ($article->getRevision()->getPrevious() === null) {
			return; // Skip edits that are just refreshing the page
		}

		$message = sprintf(
			"%s has %s article %s %s",
			self::getSlackUserText($user),
			$isMinor === true ? "made minor edit to" : "edited",
			self::getSlackArticleText($article, true),
			$summary === "" ? "" : "Summary: $summary"
		);
		if ($wgSlackIncludeDiffSize) {
			$message .= sprintf(
				" (%+d bytes)",
				$article->getRevision()->getSize() - $article->getRevision()->getPrevious()->getSize()
			);
		}
		self::send_slack_notification($message, "yellow", $user);
	}

	/**
	 * Occurs after a new article has been created.
	 * @param WikiPage $article The new page object
	 * @param User $user The user that created the page
	 * @param Content $text The new page's content object
	 * @param string $summary The new page summary
	 * @param bool $isminor Whether the page creation was marked as a minor edit
	 * @param bool $iswatch Whether the page creator is watching the new page
	 * @param null $section Not used
	 * @param int $flags Bitfield of options
	 * @param Revision $revision The revision object created by the new page
	 * @return void
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/ArticleInsertComplete
	 */
	public static function slack_article_inserted(
		WikiPage $article,
		User $user,
		Content $text,
		$summary,
		$isminor,
		$iswatch,
		$section,
		$flags,
		Revision $revision
	) {
		$config                           = self::getExtConfig();
		$wgSlackIncludeDiffSize           = $config->get("SlackIncludeDiffSize");
		$wgSlackExcludeNotificationsFrom  = $config->get("SlackExcludeNotificationsFrom");
		$wgSlackNotificationAddedArticle  = $config->get("SlackNotificationAddedArticle");;

		if (!$wgSlackNotificationAddedArticle) {
			return;
		}

		// Discard notifications from excluded pages
		if (is_array($wgSlackExcludeNotificationsFrom)) {
			foreach ($wgSlackExcludeNotificationsFrom as $currentExclude) {
				if (0 === strpos($article->getTitle(), $currentExclude)) {
					return;
				}
			}
		}

		// Do not announce newly added file uploads as articles...
		if ($article->getTitle()->getNsText() === "File") {
			return;
		}

		$message = sprintf(
			"%s has created article %s %s",
			self::getSlackUserText($user),
			self::getSlackArticleText($article),
			$summary == "" ? "" : "Summary: $summary"
		);

		if ($wgSlackIncludeDiffSize) {
			$message .= sprintf(
				" (%d bytes)",
				$article->getRevision()->getSize()
			);
		}
		self::send_slack_notification($message, "green", $user);
	}

	/**
	 * Occurs after the delete article request has been processed.
	 * @param WikiPage $article The page that was deleted
	 * @param User $user The user that performed the deletion
	 * @param string $reason The reason given for the deletion
	 * @param int $id The database ID of the deleted page
	 * @param Content $content The deleted page content or null on error
	 * @param LogEntry $logEntry The log entry recording the deletion
	 * @return void
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/ArticleDeleteComplete
	 */
	public static function slack_article_deleted(
		WikiPage $article,
		User $user,
		$reason,
		$id,
		Content $content,
		LogEntry $logEntry
	) {
		$config                            = self::getExtConfig();
		$wgSlackExcludeNotificationsFrom   = $config->get("SlackExcludeNotificationsFrom");
		$wgSlackNotificationRemovedArticle = $config->get("SlackNotificationRemovedArticle");;

		if (!$wgSlackNotificationRemovedArticle) {
			return;
		}

		// Discard notifications from excluded pages
		if (is_array($wgSlackExcludeNotificationsFrom)) {
			foreach ($wgSlackExcludeNotificationsFrom as $currentExclude) {
				if (0 === strpos($article->getTitle(), $currentExclude)) {
					return;
				}
			}
		}

		$message = sprintf(
			"%s has deleted article %s Reason: %s",
			self::getSlackUserText($user),
			self::getSlackArticleText($article),
			$reason
		);
		self::send_slack_notification($message, "red", $user);
	}

	/**
	 * Occurs after a page has been moved.
	 * @param Title $title The page title object before the move
	 * @param Title $newtitle The page title object after the move
	 * @param User $user The user performing the move
	 * @param int $oldid The database ID of the page before the move
	 * @param int $newid The database ID of the redirection page or 0 if one wasn't created
	 * @param string $reason The reason for the move
	 * @param Revision $revision The revision object created by the move
	 * @return void
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/TitleMoveComplete
	 */
	public static function slack_article_moved(
		Title $title,
		Title $newtitle,
		User $user,
		$oldid,
		$newid,
		$reason = null,
		Revision $revision = null
	) {
		$config                           = self::getExtConfig();
		$wgSlackExcludeNotificationsFrom  = $config->get("SlackExcludeNotificationsFrom");
		$wgSlackNotificationMovedArticle  = $config->get("SlackNotificationMovedArticle");;

		if (!$wgSlackNotificationMovedArticle) {
			return;
		}

		// Discard notifications from excluded pages
		if (is_array($wgSlackExcludeNotificationsFrom)) {
			foreach ($wgSlackExcludeNotificationsFrom as $currentExclude) {
				if (0 === strpos($title, $currentExclude)) {
					return;
				}
				if (0 === strpos($newtitle, $currentExclude)) {
					return;
				}
			}
		}

		$message = sprintf(
			"%s has moved article %s to %s. Reason: %s",
			self::getSlackUserText($user),
			self::getSlackTitleText($title),
			self::getSlackTitleText($newtitle),
			$reason
		);
		self::send_slack_notification($message, "green", $user);
	}

	/**
	 * Occurs after the protect article request has been processed.
	 * @param WikiPage $article The page that was protected
	 * @param User $user The user that protected the page
	 * @param bool $protect True is the page is being protected, false if unprotected
	 * @param string $reason The reason for the change in protection
	 * @param bool $moveonly True if the protection is for moves only
	 * @return void
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleProtectComplete
	 */
	public static function slack_article_protected(
		WikiPage $article,
		User $user,
		$protect,
		$reason,
		$moveonly = false
	) {
		$config                              = self::getExtConfig();
		$wgSlackExcludeNotificationsFrom     = $config->get("SlackExcludeNotificationsFrom");
		$wgSlackNotificationProtectedArticle = $config->get("SlackNotificationProtectedArticle");;

		if (!$wgSlackNotificationProtectedArticle) {
			return;
		}

		// Discard notifications from excluded pages
		if (is_array($wgSlackExcludeNotificationsFrom)) {
			foreach ($wgSlackExcludeNotificationsFrom as $currentExclude) {
				if (0 === strpos($article->getTitle(), $currentExclude)) {
					return;
				}
			}
		}

		$message = sprintf(
			"%s has %s article %s. Reason: %s",
			self::getSlackUserText($user),
			$protect ? "changed protection of" : "removed protection of",
			self::getSlackArticleText($article),
			$reason
		);
		self::send_slack_notification($message, "yellow", $user);
	}

	/**
	 * Called after a user account is created.
	 * @param User $user The new user
	 * @param bool $byEmail True if the user was created "by email"
	 * @return void
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/AddNewAccount
	 */
	public static function slack_new_user_account(User $user, $byEmail)
	{
		$config                     = self::getExtConfig();
		$wgSlackShowNewUserIP       = $config->get("SlackShowNewUserIP");
		$wgSlackShowNewUserEmail    = $config->get("SlackShowNewUserEmail");
		$wgSlackNotificationNewUser = $config->get("SlackNotificationNewUser");
		$wgSlackShowNewUserFullName = $config->get("SlackShowNewUserFullName");

		if (!$wgSlackNotificationNewUser) {
			return;
		}

		try {
			$email = $user->getEmail();
		} catch (Exception $e) {
			$email = "";
		}
		try {
			$realname = $user->getRealName();
		} catch (Exception $e) {
			$realname = "";
		}
		try {
			$ipaddress = $user->getRequest()->getIP();
		} catch (Exception $e) {
			$ipaddress = "";
		}

		$messageExtra = "";
		if ($wgSlackShowNewUserEmail || $wgSlackShowNewUserFullName || $wgSlackShowNewUserIP) {
			$messageExtra = "(";
			if ($wgSlackShowNewUserEmail && $email) {
				$messageExtra .= $email . ", ";
			}
			if ($wgSlackShowNewUserFullName && $realname) {
				$messageExtra .= $realname . ", ";
			}
			if ($wgSlackShowNewUserIP && $ipaddress) {
				$messageExtra .= $ipaddress . ", ";
			}
			$messageExtra = substr($messageExtra, 0, -2); // Remove trailing , 
			$messageExtra .= ")";
		}

		$message = sprintf(
			"New user account %s was just created %s",
			self::getSlackUserText($user),
			$messageExtra
		);
		self::send_slack_notification($message, "green", $user);
	}

	/**
	 * Called when a file upload has completed.
	 * @param UploadBase $image The upload object
	 * @return void
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/UploadComplete
	 */
	public static function slack_file_uploaded(UploadBase $image)
	{
		$config                        = self::getExtConfig();
		$wgSlackNotificationFileUpload = $config->get("SlackNotificationFileUpload");

		if (!$wgSlackNotificationFileUpload) {
			return;
		}

		$user = $image->getLocalFile()->getUser("object");
		if (is_numeric($user)) {
			$user = User::newFromId($user);
		}
		$message = sprintf(
			"%s has uploaded file <%s|%s> (format: %s, size: %s MB, summary: %s)",
			self::getSlackUserText($user),
			$image->getLocalFile()->getTitle()->getFullUrl(),
			$image->getLocalFile()->getTitle()->getFullText(),
			$image->getLocalFile()->getMimeType(),
			round($image->getLocalFile()->size / 1024 / 1024, 3),
			$image->getLocalFile()->getDescription()
		);
		self::send_slack_notification($message, "green", $wgUser);
	}

	/**
	 * Occurs after the request to block an IP or user has been processed
	 * @param Block $block The user block object
	 * @param User $user The user performing the block
	 * @return void
	 * @see http://www.mediawiki.org/wiki/Manual:MediaWiki_hooks/BlockIpComplete
	 */
	public static function slack_user_blocked(Block $block, User $user)
	{
		$config                         = self::getExtConfig();
		$wgSlackNotificationBlockedUser = $config->get("SlackNotificationBlockedUser");

		if (!$wgSlackNotificationBlockedUser) {
			return;
		}

		$block   = new SpecialBlock();
		$message = sprintf(
			"%s has blocked %s – %s. Block expiration: %s. <%s|List of all blocks>.",
			self::getSlackUserText($user),
			self::getSlackUserText($block->getTarget()),
			$block->mReason === "" ? "no reason given" : "with reason '$block->mReason'",
			$block->mExpiry,
			$block->getPageTitle()->getFullUrl()
		);
		self::send_slack_notification($message, "red", $user);
	}

	/**
	 * Sends the message to the Slack webhook
	 *
	 * @param string $message Message to be sent.
	 * @param string $colour Deprecated
	 * @param User $user The Mediawiki user object.
	 * @param array $attach Array of attachment objects to be sent.
	 * @return void
	 * @see https://api.slack.com/incoming-webhooks
	 */
	private static function send_slack_notification($message, $colour, $user, $attach = array())
	{
		$mwconfig = self::getMwConfig();
		$config   = self::getExtConfig();
		$wgExcludedPermission      = $mwconfig->get("ExcludedPermission");
		$wgSitename                = $mwconfig->get("Sitename");
		$wgHTTPProxy               = $mwconfig->get("HTTPProxy");
		$wgSlackIncomingWebhookUrl = $config->get("SlackIncomingWebhookUrl");
		$wgSlackFromName           = $config->get("SlackFromName");
		$wgSlackRoomName           = $config->get("SlackRoomName");
		$wgSlackSendMethod         = $config->get("SlackSendMethod");
		$wgSlackEmoji              = $config->get("SlackEmoji");
		
		if ($wgExcludedPermission && $user->isAllowed($wgExcludedPermission)) {
			return; // Users with the permission suppress notifications
		}

		$post_data = array(
			"text"        => $message,
			"channel"     => $wgSlackRoomName ?: null,
			"username"    => $wgSlackFromName ?: $wgSitename,
			"icon_emoji"  => $wgSlackEmoji    ?: null,
			"attachments" => $attach,
		);
		$post_data = json_encode($post_data);

		// Use file_get_contents to send the data. Note that you will need to have allow_url_fopen enabled in php.ini for this to work.
		if ($wgSlackSendMethod == "file_get_contents") {
			$options = array(
				"http" => array(
					"header"  => "Content-type: application/json",
					"method"  => "POST",
					"content" => $post_data,
					"proxy"   => $wgHTTPProxy ?: null,
					"request_fulluri" => (bool)$wgHTTPProxy
				),
			);
			$context = stream_context_create($options);
			$result = file_get_contents($wgSlackIncomingWebhookUrl, false, $context);
		}
		// Call the Slack API through cURL (default way). Note that you will need to have cURL enabled for this to work.
		else {
			$h = curl_init();
			curl_setopt_array($h, array(
				CURLOPT_URL        => $wgSlackIncomingWebhookUrl,
				CURLOPT_POST       => true,
				CURLOPT_POSTFIELDS => $post_data,
				CURLOPT_HTTPHEADER => array("Content-Type: application/json"),
				CURLOPT_PROXY      => $wgHTTPProxy ?: null,
			));
			curl_exec($h);
			curl_close($h);
		}
	}
}
