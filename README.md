# Better HTTP 1.0.0

Stable release for SMF 2.1.x.

Better HTTP improves SMF outbound HTTP/HTTPS reliability by preferring cURL, safely recovering across IP families for eligible GET connection failures, and preserving SMF's native socket path as a final fallback. It also provides privacy-safe transport diagnostics and observability.

## Core transport
- cURL-first HTTP/HTTPS requests through SMF's existing `fetch_web_data()` path.
- TLS peer and hostname verification remain enabled.
- Safe GET-only alternate IP-family recovery for connection-class failures.
- POST requests are never replayed across IP families.
- Any real HTTP response (100+) is treated as an HTTP-layer response, not a transport failure, so 4xx/5xx responses do not fall through to `fsockopen()`.
- Native SMF `fsockopen()` remains the final fallback only for genuine transport-level failures where fallback is appropriate.
- FTP behavior is untouched.

## Diagnostics and observability
- Transport environment summary.
- IANA live connection test.
- Automatic / IPv4 / IPv6 reachability matrix.
- Controlled IP-family recovery simulation.
- Controlled full cURL-to-native-socket fallback simulation.
- Privacy-safe real-request counters and 20-entry history.
- Host aggregation, slowest recent requests, and at-a-glance recovery/failure markers.
- Separate classification for HTTP non-success responses, native socket failures, recoveries, and terminal transport failures.
- Observability reset control.

Stored observability data contains target hostnames and transport metadata only. Better HTTP does not store full URLs, query strings, response bodies, remote server IPs, member data, visitor IPs, session data, or POST contents.

## Release hardening
- Supports direct upgrade from the supported development builds and `1.0.0-RC1` / `1.0.0-RC1a`.
- Split uninstall cleanup into an always-run hook cleanup and an optional destructive data cleanup.
- Normal uninstall always removes Better HTTP code, core modifications, and integration hooks while preserving observability counters/history.
- Selecting Package Manager **Remove data** additionally deletes Better HTTP-owned observability settings (`better_http_stats` and `better_http_history`).
- Reinstall after a keep-data uninstall resumes the preserved observability data automatically.
- Install/upgrade hook registration is self-healing: current and legacy hook forms are removed before the file-qualified hook is added.

## Uninstall behavior

**Remove data unchecked**
- Removes Better HTTP files and SMF source modifications.
- Detaches Better HTTP hooks.
- Preserves observability counters and recent-request history.

**Remove data checked**
- Performs all normal uninstall cleanup.
- Also removes Better HTTP observability counters and recent-request history from SMF settings.

## Compatibility
- SMF 2.1.x (`2.1 - 2.1.99`).
- Package ID: `bettersmf:better-http`.
