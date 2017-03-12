# Slack MediaWiki

This is a extension for [MediaWiki](https://www.mediawiki.org/wiki/MediaWiki) that sends notifications of actions in your Wiki like editing, adding or removing a page into [Slack](https://slack.com/) channel.

> Looking for extension that can send notifications to [HipChat](https://github.com/kulttuuri/hipchat_mediawiki) or [Discord](https://github.com/kulttuuri/discord_mediawiki)?

![Screenshot](http://i.imgur.com/4SG64a3.jpg)

## Supported MediaWiki operations to send notifications

* Article is added, removed, moved or edited.
* Article protection settings are changed.
* New user is added.
* User is blocked.
* File is uploaded.
* ... and each notification can be individually enabled or disabled :)

## Requirements

* [cURL](http://curl.haxx.se/). This extension also supports using `file_get_contents` for sending the data. See the configuration parameter `$wgSlackSendMethod` below to change this.
* MediaWiki 1.8+ (tested with version 1.8, also tested and works with 1.25+)
* Apache should have NE (NoEscape) flag on to prevent issues in URLs. By default you should have this enabled.

## How to install

1) Create a new Slack Incoming Webhook. When setting up the webhook, define channel where you want the notifications to go into. You can setup a new webhook on [this page](https://slack.com/services/new/incoming-webhook).

2) After setting up the Webhook you will get a Webhook URL. Copy that URL as you will need it in step 4.

3) [Download latest release of this extension](https://github.com/kulttuuri/slack_mediawiki/archive/master.zip), uncompress the archive and move folder `SlackNotifications` into your `mediawiki_installation/extensions` folder.

4) Add settings listed below in your `localSettings.php`. Note that it is mandatory to set these settings for this extension to work:

```php
require_once("$IP/extensions/SlackNotifications/SlackNotifications.php");
// Required. Your Slack incoming webhook URL. Read more from here: https://api.slack.com/incoming-webhooks
$wgSlackIncomingWebhookUrl = "";
// Required. Name the message will appear to be sent from. Change this to whatever you wish it to be.
$wgSlackFromName = $wgSitename;
// URL into your MediaWiki installation with the trailing /.
$wgWikiUrl		= "http://your_wiki_url/";
// Wiki script name. Leave this to default one if you do not have URL rewriting enabled.
$wgWikiUrlEnding = "index.php?title=";
// What method will be used to send the data to Slack server. By default this is "curl" which only works if you have the curl extension enabled. This can be: "curl" or "file_get_contents". Default: "curl".
$wgSlackSendMethod = "curl";
```

5) Enjoy the notifications in your Slack room!
	
## Additional options

These options can be set after including your plugin in your localSettings.php file.

### Customize room where notifications gets sent to

By default when you create incoming webhook at Slack site you'll define which room notifications go into. You can also override this in MediaWiki by setting the parameter below. Remember to also include # before your room name.

```php
$wgSlackRoomName = "";
```

### Remove additional links from user and article pages

By default user and article links in the nofication message will get additional links for ex. to block user, view article history etc. You can disable either one of those by setting settings below to false.

```php
// If this is true, pages will get additional links in the notification message (edit | delete | history).
$wgSlackIncludePageUrls = true;
// If this is true, users will get additional links in the notification message (block | groups | talk | contribs).
$wgSlackIncludeUserUrls = true;
// If this is true, all minor edits made to articles will not be submitted to Slack.
$wgSlackIgnoreMinorEdits = false;
```

### Set emoji for notification

By default notification in Slack has the default emoji for notification. You can customize this with the setting below. You can find all available emojis from [here](http://www.webpagefx.com/tools/emoji-cheat-sheet/).

```php
$wgSlackEmoji = "";
```

### Show edit size

By default we show size of the edit. You can hide this information with the setting below.

```php
$wgSlackIncludeDiffSize = false;
```

### Disable new user extra information

By default we show full name, email and IP address of newly created user in the notification. You can individually disable each of these using the settings below. This is helpful for example in situation where you do not want to expose this information for users in your Slack channel.

```php
// If this is true, newly created user email address is added to notification.
$wgSlackShowNewUserEmail = true;
// If this is true, newly created user full name is added to notification.
$wgSlackShowNewUserFullName = true;
// If this is true, newly created user IP address is added to notification.
$wgSlackShowNewUserIP = true;
```

### Disable notifications from certain user roles

By default notifications from all users will be sent to your Slack room. If you wish to exclude users in certain group to not send notification of any actions, you can set the group with the setting below.

```php
// If this is set, actions by users with this permission won't cause alerts
$wgExcludedPermission = "";
```

### Actions to notify of

MediaWiki actions that will be sent notifications of into Slack. Set desired options to false to disable notifications of those actions.

```php
// New user added into MediaWiki
$wgSlackNotificationNewUser = true;
// User or IP blocked in MediaWiki
$wgSlackNotificationBlockedUser = true;
// Article added to MediaWiki
$wgSlackNotificationAddedArticle = true;
// Article removed from MediaWiki
$wgSlackNotificationRemovedArticle = true;
// Article moved under new title in MediaWiki
$wgSlackNotificationMovedArticle = true;
// Article edited in MediaWiki
$wgSlackNotificationEditedArticle = true;
// File uploaded
$wgSlackNotificationFileUpload = true;
// Article protection settings changed
$wgSlackNotificationProtectedArticle = true;
```
	
## Additional MediaWiki URL Settings

Should any of these default MediaWiki system page URLs differ in your installation, change them here.

```php
$wgWikiUrlEndingUserRights          = "Special%3AUserRights&user=";
$wgWikiUrlEndingBlockUser           = "Special:Block/";
$wgWikiUrlEndingUserPage            = "User:";
$wgWikiUrlEndingUserTalkPage        = "User_talk:";
$wgWikiUrlEndingUserContributions   = "Special:Contributions/";
$wgWikiUrlEndingBlockList           = "Special:BlockList";
$wgWikiUrlEndingEditArticle         = "action=edit";
$wgWikiUrlEndingDeleteArticle       = "action=delete";
$wgWikiUrlEndingHistory             = "action=history";
$wgWikiUrlEndingDiff                = "diff=prev&oldid=";
```

## Contributors

[@jacksga](https://github.com/jacksga) [@Meneth](https://github.com/Meneth)

## License

[MIT License](http://en.wikipedia.org/wiki/MIT_License)

## Issues / Ideas / Comments

Feel free to use the [Issues](https://github.com/kulttuuri/slack_mediawiki/issues) section on Github for this project to submit any issues / ideas / comments! :)
