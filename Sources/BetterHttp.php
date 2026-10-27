<?php
/**
 * Better HTTP
 * cURL-first outbound HTTP transport, IP-family recovery, and diagnostics for SMF 2.1.x.
 *
 * @package BetterHTTP
 * @version 1.0.0
 */

if (!defined('SMF'))
	die('No direct access...');

/**
 * Try an HTTP(S) request with cURL before SMF falls back to its native socket path.
 *
 * Returns a string on success, null when SMF should continue with its socket fallback,
 * or false when Better HTTP has a terminal failure that must not be downgraded to the
 * less-strict socket transport (for example, a TLS certificate verification failure).
 *
 * @param string $url
 * @param string $post_data
 * @param int $redirection_level
 * @return string|false|null
 */
function BetterHttp_FetchCurlFirst($url, $post_data = '', $redirection_level = 0)
{
	// Admin-only diagnostics can deliberately bypass Better HTTP so the exact
	// native SMF fsockopen() branch is exercised for a controlled test. The
	// flag exists only for the current PHP request and is never persisted.
	if (!empty($GLOBALS['better_http_force_native_socket']))
		return null;

	if (!function_exists('curl_init'))
	{
		BetterHttp_SetLastResult(array(
			'transport' => 'curl',
			'success' => false,
			'fallback' => true,
			'allow_fallback' => true,
			'code' => 0,
			'error' => 'cURL is not available.',
			'url' => $url,
			'body' => false,
			'attempts' => array(),
		));

		return null;
	}

	$result = BetterHttp_CurlRequest($url, $post_data, $redirection_level);
	BetterHttp_SetLastResult($result);

	if (empty($GLOBALS['better_http_diagnostic_mode']))
		BetterHttp_RecordRequest($url, $post_data, $result);

	if (!empty($result['success']))
		return (string) $result['body'];

	if (isset($result['allow_fallback']) && $result['allow_fallback'] === false)
		return false;

	// Let the stock socket branch update this same request's observability row.
	if (empty($GLOBALS['better_http_diagnostic_mode']))
		$GLOBALS['better_http_socket_log_pending'] = true;

	// Returning null tells the small Subs.php bridge to continue into SMF's
	// existing fsockopen() implementation unchanged.
	return null;
}

/**
 * Run the normal cURL attempt and, for safe GET requests that fail at the
 * connection layer, retry using the alternate address family before allowing
 * SMF's native socket fallback.
 *
 * @param string $url
 * @param string $post_data
 * @param int $redirection_level
 * @return array
 */
function BetterHttp_CurlRequest($url, $post_data = '', $redirection_level = 0)
{
	$attempts = array();
	$started = microtime(true);

	$first = BetterHttp_CurlAttempt($url, $post_data, $redirection_level, 'Auto', BetterHttp_IpResolveValue('auto'));
	$attempts[] = BetterHttp_AttemptSummary($first, 'Auto');

	if (!empty($first['success']))
		return BetterHttp_FinalizeResult($first, $attempts, false, microtime(true) - $started);

	// Never try to route around security failures. A different IP family cannot
	// fix a bad certificate, and falling back to SMF's raw ssl:// socket would be
	// a security downgrade because stock SMF does not perform the same cURL-style
	// peer/hostname verification here.
	if (BetterHttp_IsTlsVerificationFailure($first))
	{
		$first['allow_fallback'] = false;
		$first['fallback'] = false;
		$first['recovery_reason'] = 'TLS verification failure; fallback blocked.';
		return BetterHttp_FinalizeResult($first, $attempts, false, microtime(true) - $started);
	}

	// If cURL reached the remote HTTP server and received a real HTTP status,
	// the transport itself worked. Retrying the same request via fsockopen()
	// cannot repair a 4xx/5xx response and can be unsafe for POST requests.
	if (BetterHttp_HasHttpResponse($first))
	{
		$first['allow_fallback'] = false;
		$first['fallback'] = false;
		$first['failure_type'] = 'http_status';
		$first['recovery_reason'] = !empty($first['curl_errno'])
			? 'Remote HTTP response received before cURL reported a later transfer error; socket fallback blocked to avoid replaying the request.'
			: 'Remote HTTP response received; socket fallback is not applicable.';
		return BetterHttp_FinalizeResult($first, $attempts, false, microtime(true) - $started);
	}

	// Family retries are deliberately GET-only. Retrying a POST after an uncertain
	// network failure can duplicate a state-changing request on the remote server.
	$can_family_retry = $post_data === '' && BetterHttp_IsConnectionFailure($first) && defined('CURLOPT_IPRESOLVE');
	$recovery_used = false;

	if ($can_family_retry)
	{
		$families = BetterHttp_RecoveryFamilies($first);
		foreach ($families as $family)
		{
			$resolve = BetterHttp_IpResolveValue($family);
			if ($resolve === null)
				continue;

			$retry = BetterHttp_CurlAttempt($url, $post_data, $redirection_level, $family, $resolve);
			$attempts[] = BetterHttp_AttemptSummary($retry, $family);
			$recovery_used = true;

			if (!empty($retry['success']))
			{
				$retry['recovery_reason'] = 'Connection-class failure recovered by forcing ' . $family . '.';
				return BetterHttp_FinalizeResult($retry, $attempts, true, microtime(true) - $started);
			}

			if (BetterHttp_IsTlsVerificationFailure($retry))
			{
				$retry['allow_fallback'] = false;
				$retry['fallback'] = false;
				$retry['recovery_reason'] = 'TLS verification failure during family retry; fallback blocked.';
				return BetterHttp_FinalizeResult($retry, $attempts, true, microtime(true) - $started);
			}

			// If the forced-family attempt reached an HTTP server and returned an HTTP
			// status, another IP-family retry is not useful.
			if (!BetterHttp_IsConnectionFailure($retry))
				break;
		}
	}

	$first['allow_fallback'] = true;
	$first['fallback'] = true;
	$first['recovery_reason'] = $recovery_used
		? 'cURL family recovery did not succeed; native SMF socket fallback allowed.'
		: 'No safe cURL family recovery was applicable; native SMF socket fallback allowed.';

	return BetterHttp_FinalizeResult($first, $attempts, $recovery_used, microtime(true) - $started);
}

/**
 * Perform one cURL request using the requested resolver mode. Redirects keep the
 * same resolver mode so a forced-family recovery remains forced across the chain.
 */
function BetterHttp_CurlAttempt($url, $post_data = '', $redirection_level = 0, $attempt_label = 'Auto', $ip_resolve = null)
{
	$started = microtime(true);
	$ch = curl_init();
	if ($ch === false)
	{
		return array(
			'transport' => 'curl',
			'success' => false,
			'fallback' => true,
			'allow_fallback' => true,
			'code' => 0,
			'curl_errno' => 0,
			'error' => 'Unable to initialize cURL.',
			'url' => $url,
			'body' => false,
			'total_time' => microtime(true) - $started,
			'attempt_label' => $attempt_label,
		);
	}

	$options = array(
		CURLOPT_URL => str_replace(' ', '%20', $url),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_MAXREDIRS => 3,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_USERAGENT => defined('SMF_USER_AGENT') ? SMF_USER_AGENT : 'Better HTTP/1.0.0',
		CURLOPT_ENCODING => '',
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
	);

	if (defined('CURLOPT_NOSIGNAL'))
		$options[CURLOPT_NOSIGNAL] = true;

	if (defined('CURLOPT_IPRESOLVE') && $ip_resolve !== null)
		$options[CURLOPT_IPRESOLVE] = $ip_resolve;

	if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS'))
		$options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;

	if ($post_data !== '')
	{
		$options[CURLOPT_POST] = true;
		$options[CURLOPT_POSTFIELDS] = $post_data;
		$options[CURLOPT_HTTPHEADER] = array('Content-Type: application/x-www-form-urlencoded');
	}

	curl_setopt_array($ch, $options);
	$content = curl_exec($ch);
	$error_no = curl_errno($ch);
	$error = $error_no ? curl_error($ch) : '';
	$info = curl_getinfo($ch);

	// Some PHP/libcurl combinations report appconnect_time as zero even though
	// TLS succeeded. Prefer the microsecond timing API when available.
	$appconnect_time = isset($info['appconnect_time']) ? (float) $info['appconnect_time'] : 0.0;
	if ($appconnect_time <= 0.0 && defined('CURLINFO_APPCONNECT_TIME_T'))
	{
		$microseconds = curl_getinfo($ch, CURLINFO_APPCONNECT_TIME_T);
		if (is_int($microseconds) && $microseconds > 0)
			$appconnect_time = $microseconds / 1000000;
	}

	curl_close($ch);

	$code = !empty($info['http_code']) ? (int) $info['http_code'] : 0;
	$effective_url = !empty($info['url']) ? $info['url'] : $url;
	$header_size = !empty($info['header_size']) ? (int) $info['header_size'] : 0;
	$headers = is_string($content) ? substr($content, 0, $header_size) : '';
	$body = is_string($content) ? substr($content, $header_size) : false;

	$metrics = array(
		'primary_ip' => !empty($info['primary_ip']) ? (string) $info['primary_ip'] : '',
		'primary_port' => !empty($info['primary_port']) ? (int) $info['primary_port'] : 0,
		'local_ip' => !empty($info['local_ip']) ? (string) $info['local_ip'] : '',
		'namelookup_time' => isset($info['namelookup_time']) ? (float) $info['namelookup_time'] : 0.0,
		'connect_time' => isset($info['connect_time']) ? (float) $info['connect_time'] : 0.0,
		'appconnect_time' => $appconnect_time,
		'total_time' => isset($info['total_time']) ? (float) $info['total_time'] : (microtime(true) - $started),
		'redirect_count' => isset($info['redirect_count']) ? (int) $info['redirect_count'] : 0,
		'content_type' => !empty($info['content_type']) ? (string) $info['content_type'] : '',
		'ip_family' => BetterHttp_IpFamily(!empty($info['primary_ip']) ? (string) $info['primary_ip'] : ''),
		'tls_verified' => stripos($effective_url, 'https://') === 0 ? ($error_no === 0 ? true : !BetterHttp_IsTlsErrno($error_no)) : null,
		'attempt_label' => $attempt_label,
	);

	if ($error_no)
	{
		return array_merge(array(
			'transport' => 'curl',
			'success' => false,
			'fallback' => true,
			'allow_fallback' => true,
			'code' => $code,
			'curl_errno' => $error_no,
			'error' => $error,
			'url' => $effective_url,
			'body' => false,
		), $metrics);
	}

	// Match SMF's historical redirect behavior, plus permanent redirect 308.
	if ($redirection_level < 3 && in_array($code, array(301, 302, 307, 308), true))
	{
		$location = BetterHttp_ExtractLocation($headers);
		if ($location !== '')
		{
			$next_url = BetterHttp_ResolveUrl($effective_url, $location);
			if ($next_url !== '')
			{
				$result = BetterHttp_CurlAttempt($next_url, $post_data, $redirection_level + 1, $attempt_label, $ip_resolve);
				$result['redirect_count'] = max(isset($result['redirect_count']) ? (int) $result['redirect_count'] : 0, $redirection_level + 1);
				return $result;
			}
		}
	}

	if ($code === 200 || $code === 201)
	{
		return array_merge(array(
			'transport' => 'curl',
			'success' => true,
			'fallback' => false,
			'allow_fallback' => false,
			'code' => $code,
			'curl_errno' => 0,
			'error' => '',
			'url' => $effective_url,
			'body' => $body === false ? '' : $body,
		), $metrics);
	}

	return array_merge(array(
		'transport' => 'curl',
		'success' => false,
		'fallback' => false,
		'allow_fallback' => false,
		'failure_type' => 'http_status',
		'code' => $code,
		'curl_errno' => 0,
		'error' => 'Unexpected HTTP status ' . $code . '.',
		'url' => $effective_url,
		'body' => false,
	), $metrics);
}

function BetterHttp_FinalizeResult(array $result, array $attempts, $recovery_used, $overall_curl_time)
{
	$result['attempts'] = $attempts;
	$result['family_recovery_used'] = (bool) $recovery_used;
	$result['curl_path_time'] = (float) $overall_curl_time;
	return $result;
}

function BetterHttp_AttemptSummary(array $result, $label)
{
	return array(
		'label' => (string) $label,
		'success' => !empty($result['success']),
		'code' => isset($result['code']) ? (int) $result['code'] : 0,
		'curl_errno' => isset($result['curl_errno']) ? (int) $result['curl_errno'] : 0,
		'error' => !empty($result['error']) ? (string) $result['error'] : '',
		'primary_ip' => !empty($result['primary_ip']) ? (string) $result['primary_ip'] : '',
		'ip_family' => !empty($result['ip_family']) ? (string) $result['ip_family'] : 'unknown',
		'total_time' => isset($result['total_time']) ? (float) $result['total_time'] : 0.0,
	);
}

function BetterHttp_HasHttpResponse(array $result)
{
	// Once libcurl reports an HTTP status line, the remote HTTP layer answered.
	// A later cURL error can still occur while reading/closing the response, but
	// retrying a POST through fsockopen() at that point risks duplicating it.
	// Treat the HTTP response as authoritative for fallback classification.
	return empty($result['success'])
		&& !empty($result['code'])
		&& (int) $result['code'] >= 100;
}

function BetterHttp_IsConnectionFailure(array $result)
{
	if (!empty($result['success']))
		return false;

	$errno = isset($result['curl_errno']) ? (int) $result['curl_errno'] : 0;
	$connection_errors = array();
	foreach (array('CURLE_COULDNT_RESOLVE_HOST', 'CURLE_COULDNT_CONNECT', 'CURLE_OPERATION_TIMEDOUT', 'CURLE_SSL_CONNECT_ERROR') as $constant)
	{
		if (defined($constant))
			$connection_errors[] = constant($constant);
	}

	return $errno !== 0 && in_array($errno, $connection_errors, true);
}

function BetterHttp_IsTlsErrno($errno)
{
	$tls_errors = array();
	foreach (array('CURLE_PEER_FAILED_VERIFICATION', 'CURLE_SSL_CACERT', 'CURLE_SSL_CACERT_BADFILE', 'CURLE_SSL_CERTPROBLEM', 'CURLE_SSL_ISSUER_ERROR') as $constant)
	{
		if (defined($constant))
			$tls_errors[] = constant($constant);
	}
	return in_array((int) $errno, array_unique($tls_errors), true);
}

function BetterHttp_IsTlsVerificationFailure(array $result)
{
	return !empty($result['curl_errno']) && BetterHttp_IsTlsErrno((int) $result['curl_errno']);
}

function BetterHttp_RecoveryFamilies(array $first)
{
	$family = !empty($first['ip_family']) ? $first['ip_family'] : 'unknown';
	$families = array();

	if ($family === 'IPv6')
		$families[] = 'IPv4';
	elseif ($family === 'IPv4')
	{
		if (BetterHttp_CurlSupportsIpv6())
			$families[] = 'IPv6';
	}
	else
	{
		// If cURL did not expose a primary IP, we cannot know which family the
		// automatic resolver attempted. Try IPv4 first (widest hosting support),
		// then IPv6 when libcurl reports IPv6 capability.
		$families[] = 'IPv4';
		if (BetterHttp_CurlSupportsIpv6())
			$families[] = 'IPv6';
	}

	return $families;
}

function BetterHttp_IpResolveValue($family)
{
	if (!defined('CURLOPT_IPRESOLVE'))
		return null;

	if ($family === 'IPv4' && defined('CURL_IPRESOLVE_V4'))
		return CURL_IPRESOLVE_V4;
	if ($family === 'IPv6' && defined('CURL_IPRESOLVE_V6'))
		return CURL_IPRESOLVE_V6;
	if ($family === 'auto' && defined('CURL_IPRESOLVE_WHATEVER'))
		return CURL_IPRESOLVE_WHATEVER;

	return null;
}

function BetterHttp_CurlSupportsIpv6()
{
	$curl_version = function_exists('curl_version') ? curl_version() : array();
	return !empty($curl_version['features']) && defined('CURL_VERSION_IPV6')
		? (($curl_version['features'] & CURL_VERSION_IPV6) === CURL_VERSION_IPV6)
		: false;
}

function BetterHttp_ExtractLocation($headers)
{
	$location = '';
	foreach (preg_split('~\r?\n~', (string) $headers) as $header)
	{
		if (stripos($header, 'Location:') === 0)
			$location = trim(substr($header, 9));
	}
	return $location;
}

function BetterHttp_ResolveUrl($base, $location)
{
	$location = trim((string) $location);
	if ($location === '')
		return '';

	if (preg_match('~^https?://~i', $location))
		return $location;

	$parts = parse_url($base);
	if (empty($parts['scheme']) || empty($parts['host']))
		return '';

	$authority = $parts['scheme'] . '://' . $parts['host'];
	if (!empty($parts['port']))
		$authority .= ':' . (int) $parts['port'];

	if (substr($location, 0, 2) === '//')
		return $parts['scheme'] . ':' . $location;

	if ($location[0] === '/')
		return $authority . $location;

	$path = isset($parts['path']) ? $parts['path'] : '/';
	$dir = preg_replace('~/[^/]*$~', '/', $path);
	$combined = $dir . $location;

	$segments = array();
	foreach (explode('/', $combined) as $segment)
	{
		if ($segment === '' || $segment === '.')
			continue;
		if ($segment === '..')
			array_pop($segments);
		else
			$segments[] = $segment;
	}

	return $authority . '/' . implode('/', $segments);
}

function BetterHttp_IpFamily($ip)
{
	if ($ip === '')
		return 'unknown';
	if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
		return 'IPv6';
	if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
		return 'IPv4';
	return 'unknown';
}

function BetterHttp_SetLastResult(array $result)
{
	$GLOBALS['better_http_last_result'] = $result;
}

function BetterHttp_ObservabilityDefaults()
{
	return array(
		'total_requests' => 0,
		'curl_successes' => 0,
		'family_recoveries' => 0,
		'socket_fallbacks' => 0,
		'socket_connect_failures' => 0,
		'terminal_failures' => 0,
		'http_non_success' => 0,
		'last_recovery' => array(),
		'last_socket_fallback' => array(),
		'last_terminal_failure' => array(),
		'last_http_non_success' => array(),
	);
}

function BetterHttp_LoadObservability()
{
	global $modSettings;

	$stats = BetterHttp_ObservabilityDefaults();
	if (!empty($modSettings['better_http_stats']))
	{
		$decoded = json_decode($modSettings['better_http_stats'], true);
		if (is_array($decoded))
			$stats = array_merge($stats, $decoded);
	}

	$history = array();
	if (!empty($modSettings['better_http_history']))
	{
		$decoded = json_decode($modSettings['better_http_history'], true);
		if (is_array($decoded))
			$history = $decoded;
	}

	return array('stats' => $stats, 'history' => $history);
}

function BetterHttp_SaveObservability(array $stats, array $history)
{
	global $modSettings;

	if (!function_exists('updateSettings'))
		return;

	$history = array_slice(array_values($history), 0, 20);
	$stats_json = json_encode($stats);
	$history_json = json_encode($history);

	updateSettings(array(
		'better_http_stats' => $stats_json,
		'better_http_history' => $history_json,
	));

	// Keep this request's in-memory settings in sync so a socket fallback that
	// immediately follows the cURL attempt can update the exact same row.
	$modSettings['better_http_stats'] = $stats_json;
	$modSettings['better_http_history'] = $history_json;
}

function BetterHttp_RecordRequest($url, $post_data, array $result)
{
	$state = BetterHttp_LoadObservability();
	$stats = $state['stats'];
	$history = $state['history'];

	$host = parse_url((string) $url, PHP_URL_HOST);
	if (!is_string($host) || $host === '')
		$host = 'unknown';
	$host = strtolower($host);

	$stats['total_requests']++;
	if (!empty($result['success']))
		$stats['curl_successes']++;
	if (!empty($result['family_recovery_used']) && !empty($result['success']))
	{
		$stats['family_recoveries']++;
		$stats['last_recovery'] = array(
			'time' => time(),
			'host' => $host,
			'from_family' => !empty($result['attempts'][0]['ip_family']) ? $result['attempts'][0]['ip_family'] : 'unknown',
			'to_family' => !empty($result['ip_family']) ? $result['ip_family'] : 'unknown',
		);
	}
	if (empty($result['success']) && !empty($result['fallback']) && (!isset($result['allow_fallback']) || $result['allow_fallback'] !== false))
		$stats['socket_fallbacks']++;
	$is_http_non_success = !empty($result['failure_type']) && $result['failure_type'] === 'http_status';
	if ($is_http_non_success)
	{
		$stats['http_non_success']++;
		$stats['last_http_non_success'] = array(
			'time' => time(),
			'host' => $host,
			'family' => !empty($result['ip_family']) ? $result['ip_family'] : 'unknown',
			'code' => isset($result['code']) ? (int) $result['code'] : 0,
		);
	}
	elseif (empty($result['success']) && isset($result['allow_fallback']) && $result['allow_fallback'] === false)
	{
		$stats['terminal_failures']++;
		$stats['last_terminal_failure'] = array(
			'time' => time(),
			'host' => $host,
			'family' => !empty($result['ip_family']) ? $result['ip_family'] : 'unknown',
		);
	}

	$attempts = !empty($result['attempts']) && is_array($result['attempts']) ? $result['attempts'] : array();
	$initial_family = !empty($attempts[0]['ip_family']) ? $attempts[0]['ip_family'] : (!empty($result['ip_family']) ? $result['ip_family'] : 'unknown');
	$final_family = !empty($result['ip_family']) ? $result['ip_family'] : 'unknown';
	$fallback_pending = empty($result['success']) && !empty($result['fallback']) && (!isset($result['allow_fallback']) || $result['allow_fallback'] !== false);

	$transport = !empty($result['success'])
		? (!empty($result['family_recovery_used']) ? 'cURL recovery' : 'cURL')
		: ($fallback_pending ? 'SMF socket pending' : 'cURL');

	$result_label = !empty($result['success'])
		? 'Success'
		: ($fallback_pending
			? 'Fallback pending'
			: ($is_http_non_success ? 'HTTP ' . (int) ($result['code'] ?? 0) : 'Failed'));

	$request_id = function_exists('random_bytes') ? bin2hex(random_bytes(6)) : uniqid('bh', true);
	if ($fallback_pending)
		$GLOBALS['better_http_socket_log_request_id'] = $request_id;

	array_unshift($history, array(
		'id' => $request_id,
		'time' => time(),
		'host' => $host,
		'method' => $post_data === '' ? 'GET' : 'POST',
		'transport' => $transport,
		'initial_family' => $initial_family,
		'recovery_used' => !empty($result['family_recovery_used']),
		'final_family' => $final_family,
		'http_status' => isset($result['code']) ? (int) $result['code'] : 0,
		'duration_ms' => round((isset($result['curl_path_time']) ? (float) $result['curl_path_time'] : (isset($result['total_time']) ? (float) $result['total_time'] : 0.0)) * 1000, 1),
		'fallback_used' => $fallback_pending,
		'result' => $result_label,
	));

	BetterHttp_SaveObservability($stats, $history);
}

/**
 * Called by the tiny Subs.php observer after the native fsockopen() call.
 * It updates only the most recent pending fallback row and stores no remote IP.
 */
function BetterHttp_RecordNativeSocketObservation($connected, $error = '')
{
	if (empty($GLOBALS['better_http_socket_log_pending']) || !empty($GLOBALS['better_http_diagnostic_mode']))
		return;

	unset($GLOBALS['better_http_socket_log_pending']);
	$request_id = !empty($GLOBALS['better_http_socket_log_request_id']) ? (string) $GLOBALS['better_http_socket_log_request_id'] : '';
	unset($GLOBALS['better_http_socket_log_request_id']);

	$state = BetterHttp_LoadObservability();
	$stats = $state['stats'];
	$history = $state['history'];

	if (!$connected)
		$stats['socket_connect_failures']++;

	$stats['last_socket_fallback'] = array(
		'time' => time(),
		'connected' => (bool) $connected,
		'host' => 'unknown',
	);

	foreach ($history as &$row)
	{
		if (($request_id !== '' && !empty($row['id']) && $row['id'] === $request_id) || ($request_id === '' && $row['result'] === 'Fallback pending'))
		{
			$row['transport'] = 'SMF socket';
			$row['result'] = $connected ? 'Socket connected' : 'Socket connection failed';
			$row['http_status'] = 0;
			$row['fallback_used'] = true;
			$stats['last_socket_fallback']['host'] = !empty($row['host']) ? $row['host'] : 'unknown';
			break;
		}
	}
	unset($row);

	BetterHttp_SaveObservability($stats, $history);
}

function BetterHttp_ResetObservability()
{
	BetterHttp_SaveObservability(BetterHttp_ObservabilityDefaults(), array());
}

function BetterHttp_ObservabilitySummary(array $state)
{
	$history = !empty($state['history']) && is_array($state['history']) ? $state['history'] : array();
	$hosts = array();
	$slowest = $history;

	foreach ($history as $row)
	{
		$host = !empty($row['host']) ? (string) $row['host'] : 'unknown';
		if (!isset($hosts[$host]))
		{
			$hosts[$host] = array(
				'host' => $host,
				'requests' => 0,
				'total_ms' => 0.0,
				'max_ms' => 0.0,
				'recoveries' => 0,
				'fallbacks' => 0,
			);
		}

		$duration = isset($row['duration_ms']) ? (float) $row['duration_ms'] : 0.0;
		$hosts[$host]['requests']++;
		$hosts[$host]['total_ms'] += $duration;
		$hosts[$host]['max_ms'] = max($hosts[$host]['max_ms'], $duration);
		if (!empty($row['recovery_used']))
			$hosts[$host]['recoveries']++;
		if (!empty($row['fallback_used']))
			$hosts[$host]['fallbacks']++;
	}

	foreach ($hosts as &$host_row)
		$host_row['average_ms'] = $host_row['requests'] > 0 ? round($host_row['total_ms'] / $host_row['requests'], 1) : 0.0;
	unset($host_row);

	$hosts = array_values($hosts);
	usort($hosts, function($a, $b)
	{
		if ($a['requests'] === $b['requests'])
			return strcmp($a['host'], $b['host']);
		return $b['requests'] <=> $a['requests'];
	});

	usort($slowest, function($a, $b)
	{
		return ((float) ($b['duration_ms'] ?? 0)) <=> ((float) ($a['duration_ms'] ?? 0));
	});

	return array(
		'hosts' => $hosts,
		'slowest' => array_slice($slowest, 0, 5),
		'most_active_host' => !empty($hosts[0]) ? $hosts[0] : array(),
		'slowest_request' => !empty($slowest[0]) ? $slowest[0] : array(),
		'latest_request' => !empty($history[0]) ? $history[0] : array(),
	);
}

function BetterHttp_LoadLanguage()
{
	loadLanguage('BetterHttp');
}

/**
 * Add Better HTTP to the Configuration section of the SMF admin center.
 */
function BetterHttp_AdminAreas(&$admin_areas)
{
	global $txt;

	BetterHttp_LoadLanguage();
	$admin_areas['config']['areas']['betterhttp'] = array(
		'label' => $txt['better_http_admin_title'],
		'file' => 'BetterHttp.php',
		'function' => 'BetterHttp_Admin',
		'icon' => 'better_http.png',
		'permission' => array('admin_forum'),
	);
}

function BetterHttp_Admin()
{
	global $context, $txt;

	isAllowedTo('admin_forum');
	BetterHttp_LoadLanguage();
	loadTemplate('BetterHttpAdmin');

	$context['page_title'] = $txt['better_http_admin_title'];
	if (!empty($context['admin_menu_name']))
	{
		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title' => $txt['better_http_admin_title'],
			'description' => $txt['better_http_admin_desc'],
		);
	}

	if (isset($_POST['reset_observability']))
	{
		checkSession('post');
		BetterHttp_ResetObservability();
		$context['better_http_observability_reset'] = true;
	}

	$context['better_http_environment'] = BetterHttp_Environment();
	$context['better_http_test'] = null;
	$context['better_http_matrix'] = null;
	$context['better_http_recovery_test'] = null;
	$context['better_http_full_fallback_test'] = null;
	$context['better_http_observability'] = BetterHttp_LoadObservability();
	$context['better_http_observability_summary'] = BetterHttp_ObservabilitySummary($context['better_http_observability']);

	if (isset($_POST['run_test']))
	{
		checkSession('post');
		$context['better_http_test'] = BetterHttp_RunDiagnosticTest();
	}
	elseif (isset($_POST['run_matrix']))
	{
		checkSession('post');
		$context['better_http_matrix'] = BetterHttp_RunFamilyMatrix();
	}
	elseif (isset($_POST['run_recovery']))
	{
		checkSession('post');
		$context['better_http_recovery_test'] = BetterHttp_RunRecoverySimulation();
	}
	elseif (isset($_POST['run_full_fallback']))
	{
		checkSession('post');
		$context['better_http_full_fallback_test'] = BetterHttp_RunFullFallbackSimulation();
	}

	$context['sub_template'] = 'better_http_admin';
}

function BetterHttp_Environment()
{
	$curl_version = function_exists('curl_version') ? curl_version() : array();

	return array(
		'php' => PHP_VERSION,
		'curl_available' => function_exists('curl_init'),
		'curl_version' => !empty($curl_version['version']) ? $curl_version['version'] : '',
		'ssl_version' => !empty($curl_version['ssl_version']) ? $curl_version['ssl_version'] : '',
		'ipv6' => BetterHttp_CurlSupportsIpv6(),
		'fsockopen_available' => function_exists('fsockopen'),
	);
}

/**
 * Exercise the exact SMF fetch_web_data() path against the IANA TLD list.
 */
function BetterHttp_RunDiagnosticTest()
{
	$target = 'https://data.iana.org/TLD/tlds-alpha-by-domain.txt';
	$GLOBALS['better_http_last_result'] = array();

	$GLOBALS['better_http_diagnostic_mode'] = true;
	$started = microtime(true);
	try
	{
		$data = fetch_web_data($target);
	}
	finally
	{
		unset($GLOBALS['better_http_diagnostic_mode']);
	}
	$elapsed = microtime(true) - $started;
	$curl = !empty($GLOBALS['better_http_last_result']) && is_array($GLOBALS['better_http_last_result'])
		? $GLOBALS['better_http_last_result']
		: array();

	$overall_success = is_string($data) && $data !== '';
	$curl_success = !empty($curl['success']);
	$fallback_used = !$curl_success && !empty($curl['fallback']) && (!isset($curl['allow_fallback']) || $curl['allow_fallback'] !== false);

	if ($curl_success)
		$transport = !empty($curl['family_recovery_used']) ? 'cURL family recovery' : 'cURL';
	elseif ($overall_success && $fallback_used)
		$transport = 'SMF socket fallback';
	elseif ($overall_success)
		$transport = 'SMF fetch path';
	else
		$transport = 'Failed';

	return array(
		'target' => $target,
		'overall_success' => $overall_success,
		'transport' => $transport,
		'fallback_used' => $fallback_used,
		'elapsed' => $elapsed,
		'bytes' => $overall_success ? strlen($data) : 0,
		'curl' => $curl,
	);
}

/**
 * Independently test automatic, IPv4-only, and IPv6-only cURL reachability.
 * This does not alter the normal fetch path; it is purely diagnostic.
 */
function BetterHttp_RunFamilyMatrix()
{
	$target = 'https://data.iana.org/TLD/tlds-alpha-by-domain.txt';
	$rows = array();

	foreach (array('Auto' => 'auto', 'IPv4' => 'IPv4', 'IPv6' => 'IPv6') as $label => $family)
	{
		$resolve = BetterHttp_IpResolveValue($family);
		if ($resolve === null || ($family === 'IPv6' && !BetterHttp_CurlSupportsIpv6()))
		{
			$rows[] = array('label' => $label, 'available' => false);
			continue;
		}

		$result = BetterHttp_CurlAttempt($target, '', 0, $label, $resolve);
		$rows[] = array_merge(array('label' => $label, 'available' => true), BetterHttp_AttemptSummary($result, $label));
	}

	return array('target' => $target, 'rows' => $rows);
}


/**
 * Safely exercise the family-recovery orchestration without breaking DNS,
 * routing, firewall rules, or normal SMF traffic.
 *
 * The test first performs a real automatic cURL probe only to learn which
 * address family libcurl selected. It then converts that successful probe into
 * a synthetic CURLE_COULDNT_CONNECT result and feeds it through the same
 * BetterHttp_RecoveryFamilies() decision logic used by production requests.
 * The selected alternate family is then contacted for real.
 *
 * No production request path or server networking is modified by this test.
 */
function BetterHttp_RunRecoverySimulation()
{
	$target = 'https://data.iana.org/TLD/tlds-alpha-by-domain.txt';
	$auto_resolve = BetterHttp_IpResolveValue('auto');

	if (!function_exists('curl_init') || $auto_resolve === null)
	{
		return array(
			'target' => $target,
			'available' => false,
			'success' => false,
			'note' => 'cURL IP-family controls are not available on this server.',
			'attempts' => array(),
		);
	}

	// Real probe: discover the family that automatic resolution currently picks.
	$probe = BetterHttp_CurlAttempt($target, '', 0, 'Auto discovery probe', $auto_resolve);
	if (empty($probe['success']))
	{
		return array(
			'target' => $target,
			'available' => true,
			'success' => false,
			'note' => 'Automatic discovery probe failed, so a controlled recovery simulation could not be staged.',
			'attempts' => array(BetterHttp_AttemptSummary($probe, 'Auto discovery probe')),
		);
	}

	// Build a synthetic connection-class failure from the real probe metadata.
	// This deliberately does not alter the server, resolver, firewall, or route.
	$simulated = $probe;
	$simulated['success'] = false;
	$simulated['code'] = 0;
	$simulated['body'] = false;
	$simulated['curl_errno'] = defined('CURLE_COULDNT_CONNECT') ? CURLE_COULDNT_CONNECT : 7;
	$simulated['error'] = 'Simulated connection failure for Better HTTP recovery test.';
	$simulated['allow_fallback'] = true;
	$simulated['fallback'] = true;

	$attempts = array();
	$sim_summary = BetterHttp_AttemptSummary($simulated, 'Auto — simulated failure');
	$sim_summary['simulated'] = true;
	$attempts[] = $sim_summary;

	$families = BetterHttp_RecoveryFamilies($simulated);
	if (empty($families))
	{
		return array(
			'target' => $target,
			'available' => true,
			'success' => false,
			'probe' => BetterHttp_AttemptSummary($probe, 'Auto discovery probe'),
			'note' => 'No alternate IP family was available for the family selected by cURL.',
			'attempts' => $attempts,
		);
	}

	foreach ($families as $family)
	{
		$resolve = BetterHttp_IpResolveValue($family);
		if ($resolve === null)
			continue;

		$retry = BetterHttp_CurlAttempt($target, '', 0, $family . ' recovery', $resolve);
		$attempts[] = BetterHttp_AttemptSummary($retry, $family . ' recovery');

		if (!empty($retry['success']))
		{
			return array(
				'target' => $target,
				'available' => true,
				'success' => true,
				'probe' => BetterHttp_AttemptSummary($probe, 'Auto discovery probe'),
				'from_family' => !empty($probe['ip_family']) ? $probe['ip_family'] : 'unknown',
				'to_family' => $family,
				'note' => 'Synthetic connection failure was recovered by a real forced-' . $family . ' cURL request.',
				'attempts' => $attempts,
			);
		}

		if (BetterHttp_IsTlsVerificationFailure($retry) || !BetterHttp_IsConnectionFailure($retry))
			break;
	}

	return array(
		'target' => $target,
		'available' => true,
		'success' => false,
		'probe' => BetterHttp_AttemptSummary($probe, 'Auto discovery probe'),
		'from_family' => !empty($probe['ip_family']) ? $probe['ip_family'] : 'unknown',
		'note' => 'Synthetic connection failure was staged, but the real alternate-family retry did not succeed.',
		'attempts' => $attempts,
	);
}


/**
 * Exercise the complete Better HTTP ladder without damaging server networking:
 * synthetic Auto cURL failure -> synthetic alternate-family cURL failure ->
 * real native SMF fsockopen() fallback.
 *
 * The first two failures are staged only inside this admin test. The final
 * fallback is real: a request-scoped bypass flag makes BetterHttp_FetchCurlFirst()
 * return null so stock fetch_web_data() continues into its original socket code.
 */
function BetterHttp_RunFullFallbackSimulation()
{
	$target = 'https://data.iana.org/TLD/tlds-alpha-by-domain.txt';

	if (!function_exists('curl_init') || !function_exists('fsockopen'))
	{
		return array(
			'target' => $target,
			'available' => false,
			'success' => false,
			'note' => 'Both cURL and fsockopen are required for the full fallback simulation.',
			'attempts' => array(),
		);
	}

	$auto_resolve = BetterHttp_IpResolveValue('auto');
	if ($auto_resolve === null)
	{
		return array(
			'target' => $target,
			'available' => false,
			'success' => false,
			'note' => 'cURL IP-family controls are not available on this server.',
			'attempts' => array(),
		);
	}

	// Real discovery probe so the synthetic chain reflects the family this host
	// would actually use for the first automatic request.
	$probe = BetterHttp_CurlAttempt($target, '', 0, 'Auto discovery probe', $auto_resolve);
	if (empty($probe['success']))
	{
		return array(
			'target' => $target,
			'available' => true,
			'success' => false,
			'probe' => BetterHttp_AttemptSummary($probe, 'Auto discovery probe'),
			'note' => 'Automatic discovery probe failed, so the controlled full-fallback simulation could not be staged.',
			'attempts' => array(),
		);
	}

	$first = $probe;
	$first['success'] = false;
	$first['code'] = 0;
	$first['body'] = false;
	$first['curl_errno'] = defined('CURLE_COULDNT_CONNECT') ? CURLE_COULDNT_CONNECT : 7;
	$first['error'] = 'Simulated automatic cURL connection failure.';
	$first['allow_fallback'] = true;
	$first['fallback'] = true;

	$attempts = array();
	$auto_summary = BetterHttp_AttemptSummary($first, 'Auto — simulated failure');
	$auto_summary['simulated'] = true;
	$auto_summary['transport'] = 'cURL';
	$attempts[] = $auto_summary;

	$families = BetterHttp_RecoveryFamilies($first);
	$recovery_family = !empty($families) ? reset($families) : '';

	if ($recovery_family !== '')
	{
		// Stage the second connection-class failure too. We intentionally do not
		// make a real forced-family request here because dev3/dev4 already proved
		// that path. The purpose of dev5 is to force the ladder down one more rung.
		$second = $first;
		$second['ip_family'] = $recovery_family;
		$second['primary_ip'] = '';
		$second['total_time'] = 0.0;
		$second['error'] = 'Simulated ' . $recovery_family . ' recovery connection failure.';
		$second_summary = BetterHttp_AttemptSummary($second, $recovery_family . ' recovery — simulated failure');
		$second_summary['simulated'] = true;
		$second_summary['transport'] = 'cURL';
		$attempts[] = $second_summary;
	}

	// Now exercise the actual stock socket branch. Keeping the flag set for the
	// entire fetch means any stock redirect recursion also remains on fsockopen().
	$socket_started = microtime(true);
	$GLOBALS['better_http_force_native_socket'] = true;
	$GLOBALS['better_http_native_socket_attempted'] = false;
	$GLOBALS['better_http_native_socket_connected'] = false;
	$GLOBALS['better_http_native_socket_error'] = '';
	try
	{
		$data = fetch_web_data($target);
	}
	finally
	{
		unset($GLOBALS['better_http_force_native_socket']);
	}
	$socket_elapsed = microtime(true) - $socket_started;
	$socket_attempted = !empty($GLOBALS['better_http_native_socket_attempted']);
	$socket_connected = !empty($GLOBALS['better_http_native_socket_connected']);
	$socket_error = !empty($GLOBALS['better_http_native_socket_error']) ? (string) $GLOBALS['better_http_native_socket_error'] : '';
	unset($GLOBALS['better_http_native_socket_attempted'], $GLOBALS['better_http_native_socket_connected'], $GLOBALS['better_http_native_socket_error']);

	// Count the test as a socket success only if our dev5 marker proves the
	// stock fsockopen() branch actually connected. This prevents stock SMF's own
	// cURL fallback from creating a false positive if fsockopen itself failed.
	$socket_success = $socket_attempted && $socket_connected && is_string($data) && $data !== '';

	$socket_attempt = array(
		'label' => 'Native SMF fsockopen fallback — real request',
		'transport' => 'fsockopen',
		'success' => $socket_success,
		'code' => $socket_success ? 200 : 0,
		'curl_errno' => 0,
		'error' => $socket_success ? '' : ($socket_error !== '' ? $socket_error : 'Native SMF fsockopen() did not establish a successful connection.'),
		'primary_ip' => '',
		'ip_family' => 'native resolver',
		'total_time' => $socket_elapsed,
		'simulated' => false,
	);
	$attempts[] = $socket_attempt;

	return array(
		'target' => $target,
		'available' => true,
		'success' => $socket_success,
		'probe' => BetterHttp_AttemptSummary($probe, 'Auto discovery probe'),
		'from_family' => !empty($probe['ip_family']) ? $probe['ip_family'] : 'unknown',
		'recovery_family' => $recovery_family !== '' ? $recovery_family : 'none',
		'socket_elapsed' => $socket_elapsed,
		'bytes' => $socket_success ? strlen($data) : 0,
		'socket_attempted' => $socket_attempted,
		'socket_connected' => $socket_connected,
		'note' => $socket_success
			? 'Both cURL stages were intentionally failed and the real native SMF fsockopen() fallback fetched the IANA list successfully.'
			: 'Both cURL stages were intentionally failed, but the real native SMF fsockopen() fallback did not return data.',
		'attempts' => $attempts,
	);
}
