<?php
/**
 * Better HTTP admin diagnostics template.
 *
 * @package BetterHTTP
 * @version 1.0.0
 */

function template_better_http_admin()
{
	global $context, $txt, $scripturl;

	$env = $context['better_http_environment'];

	echo '
	<div class="cat_bar"><h3 class="catbg">', $txt['better_http_environment'], '</h3></div>
	<div class="windowbg">
		<table class="table_grid" style="width:100%">
			<thead><tr class="title_bar"><th>', $txt['better_http_status'], '</th><th>', $txt['better_http_value'], '</th></tr></thead>
			<tbody>',
		BetterHttpAdmin_Row('PHP', $env['php']),
		BetterHttpAdmin_Row('cURL available', !empty($env['curl_available']) ? $txt['better_http_yes'] : $txt['better_http_no']),
		BetterHttpAdmin_Row('cURL version', $env['curl_version'] !== '' ? $env['curl_version'] : $txt['better_http_not_available']),
		BetterHttpAdmin_Row('TLS library', $env['ssl_version'] !== '' ? $env['ssl_version'] : $txt['better_http_not_available']),
		BetterHttpAdmin_Row('cURL IPv6 support', !empty($env['ipv6']) ? $txt['better_http_yes'] : $txt['better_http_no']),
		BetterHttpAdmin_Row('fsockopen fallback available', !empty($env['fsockopen_available']) ? $txt['better_http_yes'] : $txt['better_http_no']),
		'</tbody>
		</table>
	</div>

	<div class="cat_bar"><h3 class="catbg">', $txt['better_http_observability'], '</h3></div>
	<div class="windowbg">
		<p>', $txt['better_http_observability_desc'], '</p>';

	$obs = !empty($context['better_http_observability']) && is_array($context['better_http_observability'])
		? $context['better_http_observability']
		: array('stats' => array(), 'history' => array());
	$stats = !empty($obs['stats']) && is_array($obs['stats']) ? $obs['stats'] : array();
	$history = !empty($obs['history']) && is_array($obs['history']) ? $obs['history'] : array();

	echo '
		<table class="table_grid" style="width:100%">
			<thead><tr class="title_bar"><th>Status</th><th>Value</th></tr></thead><tbody>',
		BetterHttpAdmin_Row('Total real requests', number_format((int) ($stats['total_requests'] ?? 0))),
		BetterHttpAdmin_Row('cURL successes', number_format((int) ($stats['curl_successes'] ?? 0))),
		BetterHttpAdmin_Row('IP-family recoveries', number_format((int) ($stats['family_recoveries'] ?? 0))),
		BetterHttpAdmin_Row('Native socket fallbacks initiated', number_format((int) ($stats['socket_fallbacks'] ?? 0))),
		BetterHttpAdmin_Row('Native socket connection failures', number_format((int) ($stats['socket_connect_failures'] ?? 0))),
		BetterHttpAdmin_Row('HTTP non-success responses', number_format((int) ($stats['http_non_success'] ?? 0))),
		BetterHttpAdmin_Row('Terminal Better HTTP failures', number_format((int) ($stats['terminal_failures'] ?? 0))),
		'</tbody></table>';

	$summary = !empty($context['better_http_observability_summary']) && is_array($context['better_http_observability_summary'])
		? $context['better_http_observability_summary']
		: array();
	$last_recovery = !empty($stats['last_recovery']) && is_array($stats['last_recovery']) ? $stats['last_recovery'] : array();
	$last_socket = !empty($stats['last_socket_fallback']) && is_array($stats['last_socket_fallback']) ? $stats['last_socket_fallback'] : array();
	$last_failure = !empty($stats['last_terminal_failure']) && is_array($stats['last_terminal_failure']) ? $stats['last_terminal_failure'] : array();
	$last_http = !empty($stats['last_http_non_success']) && is_array($stats['last_http_non_success']) ? $stats['last_http_non_success'] : array();
	$latest = !empty($summary['latest_request']) ? $summary['latest_request'] : array();
	$active = !empty($summary['most_active_host']) ? $summary['most_active_host'] : array();
	$slowest = !empty($summary['slowest_request']) ? $summary['slowest_request'] : array();

	echo '
		<h4 style="margin-top:1.5em">', $txt['better_http_at_a_glance'], '</h4>
		<table class="table_grid" style="width:100%"><tbody>',
		BetterHttpAdmin_Row($txt['better_http_last_real_request'], !empty($latest) ? timeformat((int) ($latest['time'] ?? 0), false) . ' — ' . ($latest['host'] ?? 'unknown') : $txt['better_http_none_recorded']),
		BetterHttpAdmin_Row($txt['better_http_last_recovery'], !empty($last_recovery) ? timeformat((int) $last_recovery['time'], false) . ' — ' . ($last_recovery['host'] ?? 'unknown') . ' (' . ($last_recovery['from_family'] ?? 'unknown') . ' → ' . ($last_recovery['to_family'] ?? 'unknown') . ')' : $txt['better_http_none_recorded']),
		BetterHttpAdmin_Row($txt['better_http_last_socket_fallback'], !empty($last_socket) ? timeformat((int) $last_socket['time'], false) . ' — ' . ($last_socket['host'] ?? 'unknown') . ' — ' . (!empty($last_socket['connected']) ? $txt['better_http_socket_connected'] : $txt['better_http_socket_failed']) : $txt['better_http_none_recorded']),
		BetterHttpAdmin_Row($txt['better_http_last_http_non_success'], !empty($last_http) ? timeformat((int) $last_http['time'], false) . ' — ' . ($last_http['host'] ?? 'unknown') . ' — HTTP ' . (int) ($last_http['code'] ?? 0) : $txt['better_http_none_recorded']),
		BetterHttpAdmin_Row($txt['better_http_last_terminal_failure'], !empty($last_failure) ? timeformat((int) $last_failure['time'], false) . ' — ' . ($last_failure['host'] ?? 'unknown') . ' — ' . ($last_failure['family'] ?? 'unknown') : $txt['better_http_none_recorded']),
		BetterHttpAdmin_Row($txt['better_http_most_active_host'], !empty($active) ? ($active['host'] ?? 'unknown') . ' — ' . number_format((int) ($active['requests'] ?? 0)) . ' recent requests' : $txt['better_http_none_recorded']),
		BetterHttpAdmin_Row($txt['better_http_slowest_recent'], !empty($slowest) ? ($slowest['host'] ?? 'unknown') . ' — ' . number_format((float) ($slowest['duration_ms'] ?? 0), 1) . ' ms' : $txt['better_http_none_recorded']),
		'</tbody></table>';

	if (!empty($summary['hosts']))
	{
		echo '
		<h4 style="margin-top:1.5em">', $txt['better_http_host_activity'], '</h4>
		<table class="table_grid" style="width:100%">
			<thead><tr class="title_bar"><th>Host</th><th>Requests</th><th>Average</th><th>Slowest</th><th>Recoveries</th><th>Fallbacks</th></tr></thead><tbody>';

		foreach ($summary['hosts'] as $host_row)
		{
			echo '<tr class="windowbg">',
				'<td><code>', BetterHttpAdmin_Escape($host_row['host'] ?? 'unknown'), '</code></td>',
				'<td>', number_format((int) ($host_row['requests'] ?? 0)), '</td>',
				'<td><code>', number_format((float) ($host_row['average_ms'] ?? 0), 1), ' ms</code></td>',
				'<td><code>', number_format((float) ($host_row['max_ms'] ?? 0), 1), ' ms</code></td>',
				'<td>', number_format((int) ($host_row['recoveries'] ?? 0)), '</td>',
				'<td>', number_format((int) ($host_row['fallbacks'] ?? 0)), '</td>',
			'</tr>';
		}
		echo '</tbody></table>';
	}

	if (!empty($summary['slowest']))
	{
		echo '
		<h4 style="margin-top:1.5em">', $txt['better_http_slowest_requests'], '</h4>
		<table class="table_grid" style="width:100%">
			<thead><tr class="title_bar"><th>Time</th><th>Host</th><th>Method</th><th>Transport</th><th>Family</th><th>HTTP</th><th>Time</th></tr></thead><tbody>';
		foreach ($summary['slowest'] as $row)
		{
			echo '<tr class="windowbg">',
				'<td>', BetterHttpAdmin_Escape(timeformat((int) ($row['time'] ?? 0), false)), '</td>',
				'<td><code>', BetterHttpAdmin_Escape($row['host'] ?? 'unknown'), '</code></td>',
				'<td><code>', BetterHttpAdmin_Escape($row['method'] ?? 'GET'), '</code></td>',
				'<td>', BetterHttpAdmin_Escape($row['transport'] ?? '—'), '</td>',
				'<td><code>', BetterHttpAdmin_Escape($row['final_family'] ?? 'unknown'), '</code></td>',
				'<td><code>', (int) ($row['http_status'] ?? 0), '</code></td>',
				'<td><code>', number_format((float) ($row['duration_ms'] ?? 0), 1), ' ms</code></td>',
			'</tr>';
		}
		echo '</tbody></table>';
	}

	if (!empty($context['better_http_observability_reset']))
		echo '<div class="infobox" style="margin-top:1em">', $txt['better_http_reset_done'], '</div>';

	echo '
		<div class="righttext" style="margin-top:1em">
			<form action="', $scripturl, '?action=admin;area=betterhttp" method="post" style="display:inline-block" onsubmit="return confirm(\'', BetterHttpAdmin_Escape($txt['better_http_reset_confirm']), '\');">
				<input type="submit" name="reset_observability" value="', BetterHttpAdmin_Escape($txt['better_http_reset']), '" class="button">
				<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
			</form>
		</div>

		<h4 style="margin-top:1.5em">', $txt['better_http_recent_requests'], '</h4>';

	if (empty($history))
		echo '<p>', $txt['better_http_no_history'], '</p>';
	else
	{
		echo '
		<table class="table_grid" style="width:100%">
			<thead><tr class="title_bar">
				<th>Time</th><th>Host</th><th>Method</th><th>Transport</th><th>Initial</th><th>Recovery</th><th>Final</th><th>HTTP</th><th>Time</th><th>Fallback</th><th>Result</th>
			</tr></thead><tbody>';

		foreach ($history as $row)
		{
			echo '<tr class="windowbg">',
				'<td>', BetterHttpAdmin_Escape(timeformat((int) ($row['time'] ?? 0), false)), '</td>',
				'<td><code>', BetterHttpAdmin_Escape($row['host'] ?? 'unknown'), '</code></td>',
				'<td><code>', BetterHttpAdmin_Escape($row['method'] ?? 'GET'), '</code></td>',
				'<td>', BetterHttpAdmin_Escape($row['transport'] ?? '—'), '</td>',
				'<td><code>', BetterHttpAdmin_Escape($row['initial_family'] ?? 'unknown'), '</code></td>',
				'<td>', !empty($row['recovery_used']) ? $txt['better_http_yes'] : $txt['better_http_no'], '</td>',
				'<td><code>', BetterHttpAdmin_Escape($row['final_family'] ?? 'unknown'), '</code></td>',
				'<td><code>', (int) ($row['http_status'] ?? 0), '</code></td>',
				'<td><code>', BetterHttpAdmin_Escape(number_format((float) ($row['duration_ms'] ?? 0), 1) . ' ms'), '</code></td>',
				'<td>', !empty($row['fallback_used']) ? $txt['better_http_yes'] : $txt['better_http_no'], '</td>',
				'<td>', BetterHttpAdmin_Escape($row['result'] ?? '—'), '</td>',
			'</tr>';
		}

		echo '</tbody></table>';
	}

	echo '
	</div>

	<div class="cat_bar"><h3 class="catbg">', $txt['better_http_diagnostic'], '</h3></div>
	<div class="windowbg">
		<p>', $txt['better_http_diagnostic_desc'], '</p>
		<form action="', $scripturl, '?action=admin;area=betterhttp" method="post" style="display:inline-block;margin-right:8px">
			<input type="submit" name="run_test" value="', $txt['better_http_run_test'], '" class="button">
			<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
		</form>
		<form action="', $scripturl, '?action=admin;area=betterhttp" method="post" style="display:inline-block;margin-right:8px">
			<input type="submit" name="run_matrix" value="', $txt['better_http_run_matrix'], '" class="button">
			<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
		</form>
		<form action="', $scripturl, '?action=admin;area=betterhttp" method="post" style="display:inline-block;margin-right:8px">
			<input type="submit" name="run_recovery" value="', $txt['better_http_run_recovery'], '" class="button">
			<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
		</form>
		<form action="', $scripturl, '?action=admin;area=betterhttp" method="post" style="display:inline-block">
			<input type="submit" name="run_full_fallback" value="', $txt['better_http_run_full_fallback'], '" class="button">
			<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
		</form>';

	if (is_array($context['better_http_test']))
	{
		$t = $context['better_http_test'];
		$c = !empty($t['curl']) && is_array($t['curl']) ? $t['curl'] : array();
		echo '
		<hr>
		<table class="table_grid" style="width:100%">
			<tbody>',
		BetterHttpAdmin_Row('Result', !empty($t['overall_success']) ? $txt['better_http_success'] : $txt['better_http_failed']),
		BetterHttpAdmin_Row('Target', $t['target']),
		BetterHttpAdmin_Row('Transport used', $t['transport']),
		BetterHttpAdmin_Row('Family recovery used', !empty($c['family_recovery_used']) ? $txt['better_http_yes'] : $txt['better_http_no']),
		BetterHttpAdmin_Row('Fallback used', !empty($t['fallback_used']) ? $txt['better_http_yes'] : $txt['better_http_no']),
		BetterHttpAdmin_Row('HTTP status', isset($c['code']) ? (string) $c['code'] : '—'),
		BetterHttpAdmin_Row('Remote IP', !empty($c['primary_ip']) ? $c['primary_ip'] : '—'),
		BetterHttpAdmin_Row('IP family', !empty($c['ip_family']) ? $c['ip_family'] : '—'),
		BetterHttpAdmin_Row('TLS verification', BetterHttpAdmin_TlsText($c)),
		BetterHttpAdmin_Row('DNS lookup', isset($c['namelookup_time']) ? BetterHttpAdmin_Time($c['namelookup_time']) : '—'),
		BetterHttpAdmin_Row('Connect time', isset($c['connect_time']) ? BetterHttpAdmin_Time($c['connect_time']) : '—'),
		BetterHttpAdmin_Row('TLS handshake', isset($c['appconnect_time']) ? BetterHttpAdmin_Time($c['appconnect_time']) : '—'),
		BetterHttpAdmin_Row('Final cURL attempt time', isset($c['total_time']) ? BetterHttpAdmin_Time($c['total_time']) : '—'),
		BetterHttpAdmin_Row('Entire cURL path time', isset($c['curl_path_time']) ? BetterHttpAdmin_Time($c['curl_path_time']) : '—'),
		BetterHttpAdmin_Row('SMF path total time', BetterHttpAdmin_Time($t['elapsed'])),
		BetterHttpAdmin_Row('Bytes received', number_format((int) $t['bytes'])),
		BetterHttpAdmin_Row('Recovery note', !empty($c['recovery_reason']) ? $c['recovery_reason'] : 'None'),
		BetterHttpAdmin_Row('cURL error', !empty($c['error']) ? $c['error'] : 'None'),
		'</tbody>
		</table>';

		if (!empty($c['attempts']) && is_array($c['attempts']))
			BetterHttpAdmin_Attempts($c['attempts']);
	}


	if (is_array($context['better_http_recovery_test']))
	{
		$r = $context['better_http_recovery_test'];
		echo '
		<hr>
		<h4>', $txt['better_http_recovery_title'], '</h4>
		<p>', $txt['better_http_recovery_desc'], '</p>
		<table class="table_grid" style="width:100%"><tbody>',
		BetterHttpAdmin_Row('Result', !empty($r['success']) ? $txt['better_http_success'] : $txt['better_http_failed']),
		BetterHttpAdmin_Row('Target', !empty($r['target']) ? $r['target'] : '—'),
		BetterHttpAdmin_Row('Automatic family discovered', !empty($r['from_family']) ? $r['from_family'] : (!empty($r['probe']['ip_family']) ? $r['probe']['ip_family'] : '—')),
		BetterHttpAdmin_Row('Recovery family attempted', !empty($r['to_family']) ? $r['to_family'] : '—'),
		BetterHttpAdmin_Row('Recovery note', !empty($r['note']) ? $r['note'] : '—'),
		'</tbody></table>';

		if (!empty($r['probe']) && is_array($r['probe']))
		{
			echo '<h4 style="margin-top:1.5em">Discovery Probe</h4>';
			BetterHttpAdmin_Attempts(array($r['probe']));
		}

		if (!empty($r['attempts']) && is_array($r['attempts']))
		{
			echo '<h4 style="margin-top:1.5em">Recovery Attempt Chain</h4>';
			BetterHttpAdmin_Attempts($r['attempts']);
		}
	}


	if (is_array($context['better_http_full_fallback_test']))
	{
		$f = $context['better_http_full_fallback_test'];
		echo '
		<hr>
		<h4>', $txt['better_http_full_fallback_title'], '</h4>
		<p>', $txt['better_http_full_fallback_desc'], '</p>
		<table class="table_grid" style="width:100%"><tbody>',
		BetterHttpAdmin_Row('Result', !empty($f['success']) ? $txt['better_http_success'] : $txt['better_http_failed']),
		BetterHttpAdmin_Row('Target', !empty($f['target']) ? $f['target'] : '—'),
		BetterHttpAdmin_Row('Automatic family discovered', !empty($f['from_family']) ? $f['from_family'] : (!empty($f['probe']['ip_family']) ? $f['probe']['ip_family'] : '—')),
		BetterHttpAdmin_Row('Simulated recovery family', !empty($f['recovery_family']) ? $f['recovery_family'] : '—'),
		BetterHttpAdmin_Row('Final transport', !empty($f['success']) ? 'Native SMF fsockopen()' : 'Native SMF fsockopen() attempted'),
		BetterHttpAdmin_Row('Socket fallback time', isset($f['socket_elapsed']) ? BetterHttpAdmin_Time($f['socket_elapsed']) : '—'),
		BetterHttpAdmin_Row('Bytes received', isset($f['bytes']) ? number_format((int) $f['bytes']) : '0'),
		BetterHttpAdmin_Row('Fallback note', !empty($f['note']) ? $f['note'] : '—'),
		'</tbody></table>';

		if (!empty($f['probe']) && is_array($f['probe']))
		{
			echo '<h4 style="margin-top:1.5em">Discovery Probe</h4>';
			BetterHttpAdmin_Attempts(array($f['probe']));
		}

		if (!empty($f['attempts']) && is_array($f['attempts']))
		{
			echo '<h4 style="margin-top:1.5em">Complete Fallback Ladder</h4>';
			BetterHttpAdmin_FallbackAttempts($f['attempts']);
		}
	}

	if (is_array($context['better_http_matrix']))
	{
		$m = $context['better_http_matrix'];
		echo '
		<hr>
		<h4>', $txt['better_http_matrix_title'], '</h4>
		<p>', $txt['better_http_matrix_desc'], '</p>
		<table class="table_grid" style="width:100%">
			<thead><tr class="title_bar">
				<th>Mode</th><th>Result</th><th>HTTP</th><th>Remote IP</th><th>Family</th><th>Time</th><th>cURL error</th>
			</tr></thead><tbody>';

		foreach ($m['rows'] as $row)
		{
			if (empty($row['available']))
			{
				echo '<tr class="windowbg"><td><strong>', BetterHttpAdmin_Escape($row['label']), '</strong></td><td colspan="6">Not available on this server</td></tr>';
				continue;
			}

			echo '<tr class="windowbg">',
				'<td><strong>', BetterHttpAdmin_Escape($row['label']), '</strong></td>',
				'<td>', !empty($row['success']) ? $txt['better_http_success'] : $txt['better_http_failed'], '</td>',
				'<td><code>', (int) $row['code'], '</code></td>',
				'<td><code>', BetterHttpAdmin_Escape($row['primary_ip'] !== '' ? $row['primary_ip'] : '—'), '</code></td>',
				'<td><code>', BetterHttpAdmin_Escape($row['ip_family']), '</code></td>',
				'<td><code>', BetterHttpAdmin_Time($row['total_time']), '</code></td>',
				'<td><code>', BetterHttpAdmin_Escape($row['error'] !== '' ? $row['error'] : 'None'), '</code></td>',
			'</tr>';
		}

		echo '</tbody></table>';
	}

	echo '
	</div>';
}

function BetterHttpAdmin_Attempts(array $attempts)
{
	global $txt;

	echo '
	<h4 style="margin-top:1.5em">', $txt['better_http_attempts_title'], '</h4>
	<table class="table_grid" style="width:100%">
		<thead><tr class="title_bar">
			<th>Attempt</th><th>Result</th><th>HTTP</th><th>Remote IP</th><th>Family</th><th>Time</th><th>cURL error</th>
		</tr></thead><tbody>';

	foreach ($attempts as $attempt)
	{
		echo '<tr class="windowbg">',
			'<td><strong>', BetterHttpAdmin_Escape($attempt['label']), '</strong></td>',
			'<td>', !empty($attempt['success']) ? $txt['better_http_success'] : $txt['better_http_failed'], '</td>',
			'<td><code>', (int) $attempt['code'], '</code></td>',
			'<td><code>', BetterHttpAdmin_Escape($attempt['primary_ip'] !== '' ? $attempt['primary_ip'] : '—'), '</code></td>',
			'<td><code>', BetterHttpAdmin_Escape($attempt['ip_family']), '</code></td>',
			'<td><code>', BetterHttpAdmin_Time($attempt['total_time']), '</code></td>',
			'<td><code>', BetterHttpAdmin_Escape($attempt['error'] !== '' ? $attempt['error'] : 'None'), '</code></td>',
		'</tr>';
	}

	echo '</tbody></table>';
}


function BetterHttpAdmin_FallbackAttempts(array $attempts)
{
	global $txt;

	echo '
	<table class="table_grid" style="width:100%">
		<thead><tr class="title_bar">
			<th>Attempt</th><th>Transport</th><th>Mode</th><th>Result</th><th>HTTP</th><th>Family</th><th>Time</th><th>Error</th>
		</tr></thead><tbody>';

	foreach ($attempts as $attempt)
	{
		$mode = !empty($attempt['simulated']) ? 'Simulated failure' : 'Real request';
		echo '<tr class="windowbg">',
			'<td><strong>', BetterHttpAdmin_Escape($attempt['label']), '</strong></td>',
			'<td><code>', BetterHttpAdmin_Escape(!empty($attempt['transport']) ? $attempt['transport'] : 'cURL'), '</code></td>',
			'<td>', BetterHttpAdmin_Escape($mode), '</td>',
			'<td>', !empty($attempt['success']) ? $txt['better_http_success'] : $txt['better_http_failed'], '</td>',
			'<td><code>', (int) $attempt['code'], '</code></td>',
			'<td><code>', BetterHttpAdmin_Escape(!empty($attempt['ip_family']) ? $attempt['ip_family'] : '—'), '</code></td>',
			'<td><code>', BetterHttpAdmin_Time(isset($attempt['total_time']) ? $attempt['total_time'] : 0), '</code></td>',
			'<td><code>', BetterHttpAdmin_Escape(!empty($attempt['error']) ? $attempt['error'] : 'None'), '</code></td>',
		'</tr>';
	}

	echo '</tbody></table>';
}

function BetterHttpAdmin_Row($label, $value)
{
	return '<tr class="windowbg"><td style="width:35%"><strong>' . BetterHttpAdmin_Escape($label) . '</strong></td><td><code>' . BetterHttpAdmin_Escape($value) . '</code></td></tr>';
}

function BetterHttpAdmin_Escape($value)
{
	global $smcFunc;
	return $smcFunc['htmlspecialchars']((string) $value);
}

function BetterHttpAdmin_Time($seconds)
{
	return number_format(((float) $seconds) * 1000, 1) . ' ms';
}

function BetterHttpAdmin_TlsText(array $curl)
{
	if (!array_key_exists('tls_verified', $curl))
		return '—';
	if ($curl['tls_verified'] === null)
		return 'Not applicable';
	return $curl['tls_verified'] === true ? 'Enabled / request accepted' : 'Verification failed';
}
