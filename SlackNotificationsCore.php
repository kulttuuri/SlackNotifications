<?php
use MediaWiki\MediaWikiServices;

class SlackNotifications
{
    /** @var MediaWikiServices The services object */
    private static $mwServices = null;

    /** @var Config The mediawiki site config object */
    private static $mwConfig = null;

    /** @var Config The extension config object */
    private static $snConfig = null;

    const RED = "#b21717";
    const YELLOW = "#d6be37";
    const GREEN = "#299b29";

    /**
     * Initializes (if needed) and returns the site config object
     *
     * @return Config
     */
    private static function getMwConfig()
    {
        if (self::$mwConfig == null) {
            if (self::$mwServices === null) {
                self::$mwServices = MediaWikiServices::getInstance();
            }
            self::$mwConfig = self::$mwServices->getMainConfig();
        }
        return self::$mwConfig;
    }

    /**
     * Initializes (if needed) and returns the extension config object
     *
     * @return Config
     */
    private static function getExtConfig()
    {
        if (self::$snConfig == null) {
            if (self::$mwServices === null) {
                self::$mwServices = MediaWikiServices::getInstance();
            }
            self::$snConfig = self::$mwServices->getConfigFactory()->makeConfig('SlackNotifications');
        }
        return self::$snConfig;
    }

    /**
     * Gets nice HTML text for user containing the link to user page
     * or links to user site, groups editing, talk and contribs pages.
     * @param User $user The user object
     * @param bool $actionLinks Whether to return the user link or the user action links
     * @return string Links formatted for a Slack message
     */
    private static function getSlackUserText(User $user, $actionLinks = false)
    {
        if ($actionLinks) {
            $block   = new SpecialBlock();
            $rights  = new UserrightsPage();
            $contrib = new SpecialContributions();
            return sprintf(
                "<%s|block> | <%s|groups> | <%s|talk> | <%s|contribs>",
                $block->getPageTitle()->getFullUrl() . "/" . urlencode($user),
                $rights->getPageTitle()->getFullUrl() . "/" . urlencode($user),
                $user->getTalkPage()->getFullUrl(),
                $contrib->getPageTitle()->getFullUrl() . "/" . urlencode($user)
            );
        } else {
            $title = $user->getUserPage();
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
    private static function getSlackArticleText(WikiPage $article, $actionLinks = false, $diff = false)
    {
        $title = $article->getTitle();

        if ($actionLinks) {
            $out = sprintf(
                "<%s|edit> | <%s|delete> | <%s|history>",
                $title->getFullUrl(array("action"=>"edit")),
                $title->getFullUrl(array("action"=>"delete")),
                $title->getFullUrl(array("action"=>"history"))
            );
            if ($diff) {
                $revid = $article->getRevision()->getID();
                $out .= sprintf(
                    " | <%s|diff>",
                    $title->getFullUrl(array("type"=>"revision", "diff"=>$revid))
                );
            }
            return $out;
        } else {
            return sprintf("<%s|%s>", $title->getFullUrl(), $title->getFullText());
        }
    }

    /**
     * Gets nice HTML text for title object containing the link to article page
     * and also into edit, delete and article history pages.
     * @param Title $title The title object of the page
     * @return string
     */
    private static function getSlackTitleText(Title $title, $actionLinks = false)
    {
        if ($actionLinks) {
            return sprintf(
                "<%s|edit> | <%s|delete> | <%s|history>",
                $title->getFullUrl(array("action"=>"edit")),
                $title->getFullUrl(array("action"=>"delete")),
                $title->getFullUrl(array("action"=>"history"))
            );
        } else {
            return sprintf("<%s|%s>", $title->getFullUrl(), $title->getFullText());
        }
    }

    /**
     * Determines if a title is a prefix match for an entry in the excluded list
     * @param string $title The page title
     * @return boolean Whether the title matched the list
     */
    private static function isExcluded(Title $title)
    {
        $config = self::getExtConfig();
        $nspace = $title->getNsText();
        $spaces = $config->get("SlackExcludedNamespaces");
        if (is_array($spaces) && count($spaces) > 0) {
            $result = array_filter(
                $spaces,
                function ($v) use ($nspace) {
                    return strcmp($nspace, $v) === 0;
                }
            );
            if (count($result)) {
                return true;
            }
        }

        $btitle = $title->getBaseText();
        $titles = $config->get("SlackExcludedTitles");
        if (is_array($titles) && count($titles) > 0) {
            $result = array_filter(
                $titles,
                function ($v) use ($btitle) {
                    return strpos($btitle, $v) === 0;
                }
            );
            if (count($result)) {
                return true;
            }
        }

        $ftitle = $title->getFullText();
        $legacy = $config->get("SlackExcludeNotificationsFrom");
        if (is_array($legacy) && count($legacy) > 0) {
            $result = array_filter(
                $legacy,
                function ($v) use ($ftitle) {
                    return strpos($ftitle, $v) === 0;
                }
            );
            if (count($result)) {
                return true;
            }
        }
        return false;
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
    public static function articleSaved(
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
        $wgSlackIncludePageUrls           = $config->get("SlackIncludePageUrls");
        $wgSlackIncludeUserUrls           = $config->get("SlackIncludeUserUrls");
        $wgSlackIgnoreMinorEdits          = $config->get("SlackIgnoreMinorEdits");
        $wgSlackNotificationEditedArticle = $config->get("SlackNotificationEditedArticle");


        if (
            !$wgSlackNotificationEditedArticle ||
            self::isExcluded($article->getTitle()) ||
            // Skip new articles, minor edits, or null revisions (eg protecting articles)
            (int)$status->value['new'] === 1 ||
            ($isMinor && $wgSlackIgnoreMinorEdits) ||
            $article->getRevision()->getPrevious() === null
        ) {
            return;
        }

        $message = "A page was updated";
        $attach[] = array(
            "fallback"   => sprintf("%s has edited %s", $user, $article->getTitle()->getFullText()),
            "color"      => self::YELLOW,
            "title"      => $article->getTitle()->getFullText(),
            "title_link" => $article->getTitle()->getFullUrl(),
            "fields"     => array(),
            "ts"         => DateTime::createFromFormat("YmdHis", $revision->getTimestamp())->format("U"),
            "text"       => sprintf(
                "Page was edited%s by %s %s\nSummary: %s",
                ($isMinor ? " (minor)" : ""),
                self::getSlackUserText($user),
                (
                    $wgSlackIncludeDiffSize ?
                    sprintf("(%+d bytes change)", $revision->getSize() - $revision->getPrevious()->getSize()) :
                    ""
                ),
                $summary ? "_{$summary}_" : "none provided"
            ),
        );
        if ($wgSlackIncludePageUrls) {
            $attach[0]["fields"][] = array(
                "title" => "Page Links",
                "short" => "true",
                "value" => self::getSlackArticleText($article, true, true),
            );
        }
        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "true",
                "value" => self::getSlackUserText($user, true),
            );
        }
        self::sendNotification($message, $user, $attach);
    }

    /**
     * Occurs after a new article has been created.
     * @param WikiPage $article The new page object
     * @param User $user The user that created the page
     * @param Content $text The new page's content object
     * @param string $summary The new page summary
     * @param bool $isMinor Whether the page creation was marked as a minor edit
     * @param bool $isWatch Whether the page creator is watching the new page
     * @param null $section Not used
     * @param int $flags Bitfield of options
     * @param Revision $revision The revision object created by the new page
     * @return void
     * @see http://www.mediawiki.org/wiki/Manual:Hooks/ArticleInsertComplete
     */
    public static function articleInserted(
        WikiPage $article,
        User $user,
        Content $text,
        $summary,
        $isMinor,
        $isWatch,
        $section,
        $flags,
        Revision $revision
    ) {
        $config                           = self::getExtConfig();
        $wgSlackIncludeDiffSize           = $config->get("SlackIncludeDiffSize");
        $wgSlackIncludePageUrls           = $config->get("SlackIncludePageUrls");
        $wgSlackIncludeUserUrls           = $config->get("SlackIncludeUserUrls");
        $wgSlackNotificationAddedArticle  = $config->get("SlackNotificationAddedArticle");

        if (
            !$wgSlackNotificationAddedArticle ||
            self::isExcluded($article->getTitle()) ||
            // Do not announce newly added file uploads as articles...
            $article->getTitle()->getNsText() === "File"
        ) {
            return;
        }

        $message = "A page was created";
        $attach[] = array(
            "fallback"   => sprintf("%s has created %s", $user, $article->getTitle()->getFullText()),
            "color"      => self::GREEN,
            "title"      => $article->getTitle()->getFullText(),
            "title_link" => $article->getTitle()->getFullUrl(),
            "fields"     => array(),
            "ts"         => DateTime::createFromFormat("YmdHis", $revision->getTimestamp())->format("U"),
            "text"       => sprintf(
                "Page was created by %s %s\nSummary: %s",
                self::getSlackUserText($user),
                $wgSlackIncludeDiffSize ? sprintf("(%+d bytes)", $revision->getSize()) : "",
                $summary ? "_{$summary}_" : "none provided"
            ),
        );

        if ($wgSlackIncludePageUrls) {
            $attach[0]["fields"][] = array(
                "title" => "Page Links",
                "short" => "true",
                "value" => self::getSlackArticleText($article, true, true),
            );
        }
        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "true",
                "value" => self::getSlackUserText($user, true),
            );
        }

        self::sendNotification($message, $user, $attach);
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
    public static function articleDeleted(
        WikiPage $article,
        User $user,
        $reason,
        $id,
        Content $content,
        LogEntry $logEntry
    ) {
        $config                            = self::getExtConfig();
        $wgSlackIncludePageUrls            = $config->get("SlackIncludePageUrls");
        $wgSlackIncludeUserUrls            = $config->get("SlackIncludeUserUrls");
        $wgSlackNotificationRemovedArticle = $config->get("SlackNotificationRemovedArticle");

        if (!$wgSlackNotificationRemovedArticle || self::isExcluded($article->getTitle())) {
            return;
        }

        $message = "A page was deleted";
        $attach[] = array(
            "fallback"   => sprintf("%s has deleted %s", $user, $article->getTitle()->getFullText()),
            "color"      => self::RED,
            "title"      => $article->getTitle()->getFullText(),
            "title_link" => $article->getTitle()->getFullUrl(),
            "fields"     => array(),
            "ts"         => DateTime::createFromFormat("YmdHis", $logEntry->getTimestamp())->format("U"),
            "text"       => sprintf(
                "Page was deleted by %s\nReason: %s",
                self::getSlackUserText($user),
                $reason ? "_{$reason}_" : "none provided"
            ),
        );

        if ($wgSlackIncludePageUrls) {
            $attach[0]["fields"][] = array(
                "title" => "Page Links",
                "short" => "true",
                "value" => self::getSlackArticleText($article, true),
            );
        }
        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "true",
                "value" => self::getSlackUserText($user, true),
            );
        }

        self::sendNotification($message, $user, $attach);
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
    public static function articleMoved(
        Title $title,
        Title $newTitle,
        User $user,
        $oldId,
        $newId,
        $reason = null,
        Revision $revision = null
    ) {
        $config                           = self::getExtConfig();
        $wgSlackIncludePageUrls           = $config->get("SlackIncludePageUrls");
        $wgSlackIncludeUserUrls           = $config->get("SlackIncludeUserUrls");
        $wgSlackNotificationMovedArticle  = $config->get("SlackNotificationMovedArticle");

        if (
            !$wgSlackNotificationMovedArticle ||
            self::isExcluded($title) ||
            self::isExcluded($newTitle)
        ) {
            return;
        }

        $message = "A page was moved";
        $attach[] = array(
            "fallback"   => sprintf("%s has moved %s to %s", $user, $title->getFullText(), $newTitle->getFullText()),
            "color"      => self::YELLOW,
            "title"      => $title->getFullText(),
            "title_link" => $title->getFullUrl(),
            "fields"     => array(),
            "ts"         => DateTime::createFromFormat("YmdHis", $revision->getTimestamp())->format("U"),
            "text"       => sprintf(
                "Page was moved to %s by %s\nReason: %s",
                self::getSlackTitleText($newTitle),
                self::getSlackUserText($user),
                $reason ? "_{$reason}_" : "none given"
            ),
        );

        if ($wgSlackIncludePageUrls) {
            $attach[0]["fields"][] = array(
                "title" => "New Page Links",
                "short" => "true",
                "value" => self::getSlackTitleText($newTitle, true),
            );
        }
        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "true",
                "value" => self::getSlackUserText($user, true),
            );
        }

        self::sendNotification($message, $user, $attach);
    }

    /**
     * Occurs after the protect article request has been processed.
     * @param WikiPage $article The page that was protected
     * @param User $user The user that protected the page
     * @param array $protect An array of restrictions indexed by permission
     * @param string $reason The reason for the change in protection
     * @param bool $moveonly True if the protection is for moves only
     * @return void
     * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleProtectComplete
     */
    public static function articleProtected(
        WikiPage $article,
        User $user,
        $protect,
        $reason,
        $moveOnly = false
    ) {
        $config                              = self::getExtConfig();
        $wgSlackIncludePageUrls              = $config->get("SlackIncludePageUrls");
        $wgSlackIncludeUserUrls              = $config->get("SlackIncludeUserUrls");
        $wgSlackNotificationProtectedArticle = $config->get("SlackNotificationProtectedArticle");

        if (!$wgSlackNotificationProtectedArticle || self::isExcluded($article->getTitle())) {
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

        foreach ($protect as $permission=>$groupname) {
            // if there's a restriction in place, the value is a group name
            if ($groupname) {
                $isProtecting = true;
                break;
            }
        }

        $message = "A page was protected";
        $attach[] = array(
            "fallback"   => sprintf("%s has %s %s", $user, $protect ? "protected" : "unprotected", $article->getTitle()->getFullText()),
            "color"      => self::YELLOW,
            "title"      => $article->getTitle()->getFullText(),
            "title_link" => $article->getTitle()->getFullUrl(),
            "fields"     => array(),
            "ts"         => DateTime::createFromFormat("YmdHis", $article->getRevision()->getTimestamp())->format("U"),
            "text"       => sprintf(
                "Page had protection %s by %s\nReason: %s",
                $isProtecting ? "changed" : "removed",
                self::getSlackUserText($user),
                $reason ? "_${reason}_" : "none given"
            ),
        );

        if ($wgSlackIncludePageUrls) {
            $attach[0]["fields"][] = array(
                "title" => "Page Links",
                "short" => "true",
                "value" => self::getSlackArticleText($article, true),
            );
        }
        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "true",
                "value" => self::getSlackUserText($user, true),
            );
        }

        self::sendNotification($message, $user, $attach);
    }

    /**
     * Called after a user account is created.
     * @param User $user The new user
     * @param bool $byEmail True if the user was created "by email"
     * @return void
     * @see http://www.mediawiki.org/wiki/Manual:Hooks/AddNewAccount
     */
    public static function newUserAccount(User $user, $byEmail)
    {
        $config                     = self::getExtConfig();
        $wgSlackShowNewUserIP       = $config->get("SlackShowNewUserIP");
        $wgSlackIncludeUserUrls     = $config->get("SlackIncludeUserUrls");
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
            $realName = $user->getRealName();
        } catch (Exception $e) {
            $realName = "";
        }
        try {
            $ipAddress = $user->getRequest()->getIP();
        } catch (Exception $e) {
            $ipAddress = "";
        }

        $message = "A user was created";
        $attach[] = array(
            "fallback"   => sprintf("User %s was created", $user),
            "color"      => self::GREEN,
            "title"      => $user,
            "title_link" => $user->getUserPage->getFullUrl(),
            "text"       => sprintf("New user account was created"),
            "fields"     => array(),
            "ts"         => DateTime::createFromFormat("YmdHis", $user->getRegistration())->format("U"),
        );

        if ($wgSlackShowNewUserEmail && $email) {
            $attach[0]["fields"][] = array("title" => "Email", "value" => $email, "short" => true);
        }
        if ($wgSlackShowNewUserFullName && $realName) {
            $attach[0]["fields"][] = array("title" => "Name", "value" => $realName, "short" => true);
        }
        if ($wgSlackShowNewUserIP && $ipAddress) {
            $attach[0]["fields"][] = array("title" => "IP", "value" => $ipAddress, "short" => true);
        }

        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "false",
                "value" => self::getSlackUserText($user, true),
            );
        }

        self::sendNotification($message, $user, $attach);
    }

    /**
     * Called when a file upload has completed.
     * @param UploadBase $image The upload object
     * @return void
     * @see http://www.mediawiki.org/wiki/Manual:Hooks/UploadComplete
     */
    public static function fileUploaded(UploadBase $image)
    {
        $config                        = self::getExtConfig();
        $wgSlackIncludePageUrls        = $config->get("SlackIncludePageUrls");
        $wgSlackIncludeUserUrls        = $config->get("SlackIncludeUserUrls");
        $wgSlackNotificationFileUpload = $config->get("SlackNotificationFileUpload");

        if (!$wgSlackNotificationFileUpload || self::isExcluded($image->getLocalFile()->getTitle())) {
            return;
        }

        $user = $image->getLocalFile()->getUser("object");
        if (is_numeric($user)) {
            $user = User::newFromId($user);
        }

        $message = "A file was uploaded";
        $attach[] = array(
            "fallback"   => sprintf("%s has uploaded %s", $user, $image->getLocalFile()->getTitle()->getFullText()),
            "color"      => self::GREEN,
            "title"      => $image->getLocalFile()->getTitle()->getFullText(),
            "title_link" => $image->getLocalFile()->getTitle()->getFullUrl(),
            "fields"     => array(),
            "ts"         => DateTime::createFromFormat("YmdHis", $image->getLocalFile()->getTimestamp())->format("U"),
            "text"       => sprintf(
                "File was uploaded by %s\nSummary: %s",
                self::getSlackUserText($user),
                $image->getLocalFile()->getDescription() ? "_" . $image->getLocalFile()->getDescription() . "_" : "none given"
            ),
        );

        $attach[0]["fields"][] = array("title" => "Type", "short" => "true", "value" => $image->getLocalFile()->getMimeType());

        $size = $image->getLocalFile()->size;
        if ($size > 1024 * 1024 * 1024) {
            $size = sprintf("%f GB", round($size / 1024 / 1024 / 1024, 2));
        } elseif ($size > 1024 * 1024) {
            $size = sprintf("%f MB", round($size / 1024 / 1024, 1));
        } elseif ($size > 1024) {
            $size = sprintf("%d kB", floor($size / 1024));
        } else {
            $size = sprintf("%d B", $size);
        }

        $attach[0]["fields"][] = array("title" => "Size", "short" => "true", "value" => $size);

        if ($wgSlackIncludePageUrls) {
            $attach[0]["fields"][] = array(
                "title" => "File Links",
                "short" => "true",
                "value" => self::getSlackTitleText($image->getTitle(), true),
            );
        }
        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "true",
                "value" => self::getSlackUserText($user, true),
            );
        }

        self::sendNotification($message, $user, $attach);
    }

    /**
     * Occurs after the request to block an IP or user has been processed
     * @param Block $block The user block object
     * @param User $user The user performing the block
     * @return void
     * @see http://www.mediawiki.org/wiki/Manual:MediaWiki_hooks/BlockIpComplete
     */
    public static function userBlocked(Block $block, User $user)
    {
        $config                         = self::getExtConfig();
        $wgSlackIncludeUserUrls         = $config->get("SlackIncludeUserUrls");
        $wgSlackNotificationBlockedUser = $config->get("SlackNotificationBlockedUser");

        if (!$wgSlackNotificationBlockedUser) {
            return;
        }

        $blockpage = new SpecialBlockList();
        $message   = "A user was blocked";
        $attach[]  = array(
            "fallback"   => sprintf("%s has blocked %s", $user, $block->getTarget()->getName()),
            "color"      => self::RED,
            "title"      => $block->getTarget()->getName(),
            "title_link" => $block->getTarget()->getUserPage()->getFullUrl(),
            "fields"     => array(),
            "ts"         => DateTime::createFromFormat("YmdHis", $block->mTimestamp)->format("U"),
            "text"       => sprintf(
                "User was blocked by %s\nReason: %s.",
                self::getSlackUserText($user),
                $block->mReason ? "_{$block->mReason}_" : "none given"
            ),
        );
        $attach[0]["fields"][] = array("title" => "Expiry", "short" => "true", "value" => $block->mExpiry);
        $attach[0]["fields"][] = array(
            "title" => "More Info",
            "short" => "true",
            "value" => sprintf("<%s|%s>", $blockpage->getPageTitle()->getFullUrl(), "Block list"),
        );
        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "true",
                "value" => self::getSlackUserText($block->getTarget(), true),
            );
        }
        self::sendNotification($message, $user, $attach);
    }

    /**
     * Sends the message to the Slack webhook
     *
     * @param string $message Message to be sent.
     * @param User $user The Mediawiki user object.
     * @param array $attach Array of attachment objects to be sent.
     * @return void
     * @see https://api.slack.com/incoming-webhooks
     */
    private static function sendNotification($message, User $user, $attach = array())
    {
        $mwConfig = self::getMwConfig();
        $config   = self::getExtConfig();

        $wgSitename                = $mwConfig->get("Sitename");
        $wgHTTPProxy               = $mwConfig->get("HTTPProxy");
        $wgSlackEmoji              = $config->get("SlackEmoji");
        $wgSlackFromName           = $config->get("SlackFromName");
        $wgSlackRoomName           = $config->get("SlackRoomName");
        $wgSlackExcludeGroup       = $config->get("SlackExcludeGroup");
        $wgSlackIncomingWebhookUrl = $config->get("SlackIncomingWebhookUrl");

        if ($wgSlackExcludeGroup && $user->isAllowed($wgSlackExcludeGroup)) {
            return; // Users with the permission suppress notifications
        }

        $postData = array(
            "text"        => $message,
            "channel"     => $wgSlackRoomName ?: null,
            "username"    => $wgSlackFromName ?: $wgSitename,
            "icon_emoji"  => $wgSlackEmoji    ?: null,
            "attachments" => $attach,
        );
        $postData = json_encode($postData);

        if (ini_get("allow_url_fopen")) {
            $options = array(
                "http" => array(
                    "header"  => "Content-type: application/json",
                    "method"  => "POST",
                    "content" => $postData,
                    "proxy"   => $wgHTTPProxy ?: null,
                    "request_fulluri" => (bool)$wgHTTPProxy
                ),
            );
            $context = stream_context_create($options);
            file_get_contents($wgSlackIncomingWebhookUrl, false, $context);
        } elseif (extension_loaded("curl")) {
            $h = curl_init();
            curl_setopt_array($h, array(
                CURLOPT_URL        => $wgSlackIncomingWebhookUrl,
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER => array("Content-Type: application/json"),
                CURLOPT_PROXY      => $wgHTTPProxy ?: null,
            ));
            curl_exec($h);
            curl_close($h);
        } else {
            // no way to send the notification
            return false;
        }
    }
}
