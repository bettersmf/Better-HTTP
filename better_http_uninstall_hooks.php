<?php
/**
 * Better HTTP non-destructive uninstall cleanup.
 *
 * Always detaches Better HTTP integration hooks. Observability data is
 * intentionally preserved unless Package Manager's Remove data option is used.
 *
 * @package BetterHTTP
 * @version 1.0.0
 */

if (!defined('SMF'))
	die('No direct access...');

global $sourcedir;
require_once($sourcedir . '/Subs.php');

$hook = 'integrate_admin_areas';
$function = 'BetterHttp_AdminAreas';
$file = '$sourcedir/BetterHttp.php';

// Remove both the current file-qualified hook and any legacy unqualified form.
remove_integration_function($hook, $function, true, $file);
remove_integration_function($hook, $function, true);
