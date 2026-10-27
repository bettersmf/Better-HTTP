<?php
/**
 * Better HTTP destructive data cleanup.
 *
 * SMF Package Manager executes <database> uninstall steps only when the
 * administrator selects Remove data. Normal uninstall therefore preserves
 * Better HTTP observability counters and recent-request history.
 *
 * @package BetterHTTP
 * @version 1.0.0
 */

if (!defined('SMF'))
	die('No direct access...');

global $smcFunc;

if (!isset($smcFunc['db_query']))
	return;

$smcFunc['db_query']('', '
	DELETE FROM {db_prefix}settings
	WHERE variable IN ({array_string:better_http_settings})',
	array(
		'better_http_settings' => array(
			'better_http_stats',
			'better_http_history',
		),
	)
);
