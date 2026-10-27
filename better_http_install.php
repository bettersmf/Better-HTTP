<?php
/**
 * Better HTTP hook installer / upgrade self-healer.
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

remove_integration_function($hook, $function, true, $file);
remove_integration_function($hook, $function, true);

add_integration_function($hook, $function, true, $file);
