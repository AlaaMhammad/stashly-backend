# SSRF Protection — Mandatory Checklist

This document is a security acceptance gate, not optional hardening.

## Target validation
- [ ] Accept only `http` and `https`.
- [ ] Only ports 80 and 443.
- [ ] Reject URL credentials (`user:pass@host`).
- [ ] Reject malformed hosts, unsupported schemes, and URL length over 2048.
- [ ] Normalize before save and validate again immediately before fetch.

## DNS and IP validation
- [ ] Resolve all A and AAAA records.
- [ ] Reject if any resolved target is private, loopback, link-local, reserved, multicast, unspecified, or cloud metadata range.
- [ ] Apply IPv4 and IPv6 rules, including IPv4-mapped IPv6.
- [ ] Revalidate DNS results close to connection time.
- [ ] Connect to the checked IP to prevent DNS rebinding; preserve correct TLS hostname verification/SNI and HTTP Host header.
- [ ] If the HTTP stack cannot safely pin the checked IP, use an egress proxy/isolated fetch service rather than claiming the check is sufficient.

## Redirects and response limits
- [ ] Disable automatic redirects unless each redirect can be validated before following.
- [ ] Maximum three redirects.
- [ ] Re-validate scheme, port, DNS, IP, and URL at each redirect.
- [ ] Total timeout 8 seconds; preview timeout 4 seconds.
- [ ] Read no more than 512 KB of HTML.
- [ ] Accept HTML only; reject media, archives, and unexpected content types.
- [ ] No cookies or ambient credentials.
- [ ] Never download remote image/video files; only retain external image URL.
- [ ] Prevent access to internal services, private networks, local files, cloud metadata, and internal DNS names.

## Runtime isolation
- [ ] Run metadata workers with no access to internal admin/database/cache endpoints except explicitly required queue/database paths.
- [ ] Restrict outbound network access where infrastructure supports it.
- [ ] Use low-privilege OS/container account and resource limits.
- [ ] Monitor repeated blocked attempts.

## Test cases
- `127.0.0.1`, `localhost`, `::1`, RFC1918 IPv4, IPv6 ULA/link-local, link-local metadata IP.
- Public hostname resolving to private IP.
- Public URL redirecting to private IP.
- DNS rebinding / changing answer between validation and connection.
- Non-standard port, credentials in URL, `file:` and `data:` schemes.
- Redirect loops and >3 redirects.
- Response >512 KB, slow response, invalid TLS, non-HTML response.
- IPv4-mapped IPv6 and mixed public/private DNS records.

Use controlled local test fixtures/mocks; do not probe third-party internal addresses in live tests.
