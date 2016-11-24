<?php
if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'SlackNotifications' );
	wfWarn(
		'Deprecated PHP entry point used for SlackNotifications extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die( 'This version of the SlackNotifications extension requires MediaWiki 1.25+' );
}
