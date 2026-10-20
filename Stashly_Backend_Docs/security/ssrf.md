# SSRF Protection — Mandatory Checklist

This document is a security acceptance gate, not optional hardening.

## Target validation
- [x] Accept only `http` and `https`. *(Evidence: `SsrfTest::test_unsupported_or_malformed_urls_are_rejected`.)*
- [x] Only ports 80 and 443. *(Evidence: `SsrfTest::test_only_ports_80_and_443_are_allowed`.)*
- [x] Reject URL credentials (`user:pass@host`). *(Evidence: `SsrfTest` (invalid URLs), `UrlNormalizerTest`.)*
- [x] Reject malformed hosts, unsupported schemes, and URL length over 2048. *(Evidence: `SsrfTest`, `UrlNormalizerTest`.)*
- [x] Normalize before save and validate again immediately before fetch. *(Evidence: `MetadataUrlGuard` re-normalizes every URL, including redirects.)*

## DNS and IP validation
- [x] Resolve all A and AAAA records. *(Evidence: `SystemDnsResolver` (A and AAAA), `SsrfTest::test_mixed_public_and_private_records_are_blocked`.)*
- [x] Reject if any resolved target is private, loopback, link-local, reserved, multicast, unspecified, or cloud metadata range. *(Evidence: `IpPolicyTest`.)*
- [x] Apply IPv4 and IPv6 rules, including IPv4-mapped IPv6. *(Evidence: `IpPolicyTest`.)*
- [x] Revalidate DNS results close to connection time. *(Evidence: DNS is resolved by the guard immediately before each request, and the connection is pinned to that answer.)*
- [x] Connect to the checked IP to prevent DNS rebinding; preserve correct TLS hostname verification/SNI and HTTP Host header. *(Evidence: `CurlTransportTest`, `SsrfTest`; real HTTPS verified manually, bad and expired certificates rejected.)*
- [x] If the HTTP stack cannot safely pin the checked IP, use an egress proxy/isolated fetch service rather than claiming the check is sufficient. *(Evidence: N/A: curl pins with CURLOPT_RESOLVE (verified).)*

## Redirects and response limits
- [x] Disable automatic redirects unless each redirect can be validated before following. *(Evidence: `CurlTransportTest::test_redirects_are_returned_not_followed`.)*
- [x] Maximum three redirects. *(Evidence: `SsrfTest::test_three_redirects_are_followed_but_not_four`.)*
- [x] Re-validate scheme, port, DNS, IP, and URL at each redirect. *(Evidence: `SsrfTest` redirect tests.)*
- [x] Total timeout 8 seconds; preview timeout 4 seconds. *(Evidence: `SsrfTest::test_one_time_budget_is_shared_across_all_hops`, `CurlTransportTest`; DNS lookup time is not covered (DEC-030).)*
- [x] Read no more than 512 KB of HTML. *(Evidence: `CurlTransportTest::test_body_is_cut_at_the_byte_limit`, `test_the_limit_applies_to_decompressed_bytes`.)*
- [x] Accept HTML only; reject media, archives, and unexpected content types. *(Evidence: `SsrfTest::test_only_html_is_accepted`, `CurlTransportTest::test_non_html_bodies_are_not_read`.)*
- [x] No cookies or ambient credentials. *(Evidence: `CurlTransportTest::test_requests_carry_no_cookies_or_credentials_and_a_fixed_user_agent`; proxy disabled.)*
- [x] Never download remote image/video files; only retain external image URL. *(Evidence: only the page URL is requested; `MetadataExtractorTest` URL tests.)*
- [x] Prevent access to internal services, private networks, local files, cloud metadata, and internal DNS names. *(Evidence: application level: `SsrfTest`; network level: see runtime isolation below (UNVERIFIED).)*

## Runtime isolation
- [ ] Run metadata workers with no access to internal admin/database/cache endpoints except explicitly required queue/database paths. *(Deployment task, UNVERIFIED: the code runs on its own `metadata` queue so it can be isolated.)*
- [ ] Restrict outbound network access where infrastructure supports it. *(Deployment task, UNVERIFIED: the code runs on its own `metadata` queue so it can be isolated.)*
- [ ] Use low-privilege OS/container account and resource limits. *(Deployment task, UNVERIFIED: the code runs on its own `metadata` queue so it can be isolated.)*
- [ ] Monitor repeated blocked attempts. *(Blocked attempts are logged with link id, domain and `BLOCKED_HOST`/`BLOCKED_PORT`; alerting is Phase 7.)*

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
