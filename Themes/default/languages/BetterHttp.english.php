<?php
/**
 * Better HTTP language strings.
 *
 * @package BetterHTTP
 * @version 1.0.0
 */

$txt['better_http_admin_title'] = 'Better HTTP';
$txt['better_http_admin_desc'] = 'Diagnostics for SMF outbound HTTP/HTTPS requests using Better HTTP\'s cURL-first transport, IP-family recovery, and native SMF socket fallback.';
$txt['better_http_environment'] = 'Transport Environment';
$txt['better_http_diagnostic'] = 'Live Connection Tests';
$txt['better_http_diagnostic_desc'] = 'Runs the normal SMF fetch_web_data() path against the IANA TLD list, tests automatic/IPv4/IPv6 reachability, safely simulates alternate-family recovery, or forces the full ladder down to the real native SMF fsockopen() fallback.';
$txt['better_http_run_test'] = 'Run IANA Connection Test';
$txt['better_http_run_matrix'] = 'Run IPv4 / IPv6 Matrix';
$txt['better_http_run_recovery'] = 'Run Recovery Simulation';
$txt['better_http_recovery_title'] = 'Controlled Recovery Simulation';
$txt['better_http_recovery_desc'] = 'Uses a real automatic probe to learn the currently selected IP family, then stages a synthetic connection failure and performs the real alternate-family retry. It does not alter DNS, firewall rules, routes, or normal SMF traffic.';
$txt['better_http_matrix_title'] = 'IP Family Reachability';
$txt['better_http_matrix_desc'] = 'Direct cURL checks using automatic resolution, forced IPv4, and forced IPv6. These tests do not change the normal SMF fetch path.';
$txt['better_http_attempts_title'] = 'cURL Attempt Chain';
$txt['better_http_status'] = 'Status';
$txt['better_http_value'] = 'Value';
$txt['better_http_yes'] = 'Yes';
$txt['better_http_no'] = 'No';
$txt['better_http_success'] = 'Success';
$txt['better_http_failed'] = 'Failed';
$txt['better_http_not_available'] = 'Not available';

$txt['better_http_run_full_fallback'] = 'Run Full Fallback Simulation';
$txt['better_http_full_fallback_title'] = 'Full cURL-to-Socket Fallback Simulation';
$txt['better_http_full_fallback_desc'] = 'Stages a synthetic automatic cURL failure and synthetic alternate-family failure, then bypasses Better HTTP only for the final test request so stock SMF performs a real fsockopen() HTTPS fetch. No DNS, firewall, route, or persistent setting is changed.';

$txt['better_http_observability'] = 'Real Request Observability';
$txt['better_http_observability_desc'] = 'Privacy-safe counters and the 20 most recent real Better HTTP requests. Only target hostnames and transport metadata are stored; URLs, query strings, response bodies, remote server IPs, member data, and visitor IPs are not stored.';
$txt['better_http_recent_requests'] = 'Recent Real Requests';
$txt['better_http_no_history'] = 'No real Better HTTP requests have been recorded yet.';

$txt['better_http_at_a_glance'] = 'At a Glance';
$txt['better_http_last_real_request'] = 'Last real request';
$txt['better_http_last_recovery'] = 'Last IP-family recovery';
$txt['better_http_last_socket_fallback'] = 'Last native socket fallback';
$txt['better_http_last_http_non_success'] = 'Last HTTP non-success response';
$txt['better_http_last_terminal_failure'] = 'Last terminal failure';
$txt['better_http_most_active_host'] = 'Most active recent host';
$txt['better_http_slowest_recent'] = 'Slowest recent request';
$txt['better_http_host_activity'] = 'Recent Host Activity';
$txt['better_http_slowest_requests'] = 'Slowest Recent Requests';
$txt['better_http_none_recorded'] = 'None recorded';
$txt['better_http_socket_connected'] = 'socket connected';
$txt['better_http_socket_failed'] = 'socket connection failed';
$txt['better_http_reset'] = 'Reset Observability Data';
$txt['better_http_reset_confirm'] = 'Reset Better HTTP counters and recent real-request history? This does not change transport settings.';
$txt['better_http_reset_done'] = 'Better HTTP observability counters and recent request history were reset.';
