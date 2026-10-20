# Deployment and Operations Checklist

## Environment
- [x] PHP 8.2 and Laravel 12 compatibility verified. *(Local CLI only: PHP 8.2.12, Laravel 12.70.0; see `project-audit.md`. Re-verify on each deployment target.)*
- [ ] MySQL version/collation recorded and supported.
- [x] Production `.env` is not committed. *(`.env` is git-ignored; `.env.example` has placeholders only.)*
- [ ] `APP_DEBUG=false`; HTTPS enforced. *(`php artisan stashly:preflight` checks debug, `APP_ENV`, https `APP_URL`; the app forces https URLs and sends HSTS in production. Enforcing HTTPS at the edge is a deployment task.)*
- [ ] Database user has least required privileges.
- [ ] Mail transport configured and tested.
- [ ] Queue and cache drivers configured intentionally.
- [ ] `APP_URL`, API base URL, Terms/Privacy URLs, and app version values verified.
- [x] CORS restricted to intended origins. *(`config/cors.php`: no origin allowed unless `CORS_ALLOWED_ORIGINS` lists it; credentials off. `HardeningTest`.)*

## Database and deploy
- [ ] Backup before production migration.
- [ ] Migrations run successfully from clean state in CI.
- [ ] Migration rollback/recovery plan documented.
- [ ] Indexes/unique constraints verified on MySQL.
- [ ] Account deletion and export behavior validated. *(Automated: `ProfileTest`, `ExportTest`. Still to do: validate in staging.)*
- [ ] Restore from backup tested periodically.

## Queue and scheduler
- [ ] Queue worker supervised and restarted on deploy.
- [ ] Failed jobs monitored and reviewed.
- [x] Scheduled cleanup registered and verified. *(Registration verified in tests; the production cron entry for `schedule:run` is UNVERIFIED.)*
- [ ] Metadata job egress restrictions active.
- [ ] Email queue delivery monitored.
- [ ] No queue job can access internal services via arbitrary URL fetch.

## Metadata worker (security-sensitive)
The metadata job fetches arbitrary public web pages. Run it on its own queue and, where possible, on its own host or container:

```bash
php artisan queue:work --queue=metadata --tries=1 --timeout=30 --max-time=3600
php artisan queue:work --queue=default      # mail and everything else, separate worker
* * * * * php artisan schedule:run          # scheduler (cron)
```

- The app blocks private, loopback, link-local and cloud-metadata destinations itself, but that is defense in depth. The metadata worker should also have **no route** to the database admin port, cache, internal services or the cloud metadata address, and allow outbound traffic only on ports 80 and 443. (UNVERIFIED: infrastructure not available here.)
- Use a low-privilege account and CPU/memory limits. `--max-time` recycles the process regularly.
- PHP needs the `curl` extension with a CA bundle (`curl.cainfo`); there is no `intl` requirement.
- Scheduled commands (verified registered, production cron unverified): `links:expire-stale-metadata` every 5 minutes, `sanctum:prune-expired` and `model:prune` daily.

## Configuration reference (security-relevant)
| Variable | Purpose |
|---|---|
| `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...` | Required in production (`php artisan stashly:preflight` fails otherwise) |
| `TRUSTED_PROXIES` | Load balancer IPs/CIDRs allowed to set `X-Forwarded-For`. Unset = none trusted. Rate limits are per client IP, so set this behind a proxy |
| `CORS_ALLOWED_ORIGINS` | Browser origins allowed to call the API. Empty = none (the Android app needs none) |
| `STASHLY_*` | Terms/Privacy URLs, terms version, minimum and latest app versions (`GET /config`) |

Run `php artisan stashly:preflight` after every deploy: it exits non-zero on FAIL and never prints secrets.

## Monitoring
- [x] `/health` checks database/cache/queue as required. *(`HealthTest`.)*
- [x] Health returns 503 on failed required dependency. *(`HealthTest`.)*
- [x] Request IDs appear in safe logs and error responses. *(`ErrorEnvelopeTest`, `ObservabilityTest`.)*
- [ ] Alerting for queue backlog, repeated failures, and service outage.
- [ ] Rate limiting and blocked SSRF attempts observable.
- [x] Logs exclude passwords, tokens, reset tokens, and full URLs. *(`ObservabilityTest`.)*

## Release smoke tests
- [ ] Register/login/me/logout.
- [ ] Create link and verify metadata job runs asynchronously.
- [ ] Verify duplicate link returns 409.
- [ ] Verify collection/tag operations.
- [ ] Verify cross-user IDs return 404.
- [ ] Verify reset email and reset page.
- [ ] Verify export attachment and account deletion in safe staging.
- [ ] Verify queue, scheduler, and health monitoring.
