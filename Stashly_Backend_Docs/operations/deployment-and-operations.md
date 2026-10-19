# Deployment and Operations Checklist

## Environment
- [x] PHP 8.2 and Laravel 12 compatibility verified. *(Local CLI only: PHP 8.2.12, Laravel 12.70.0; see `project-audit.md`. Re-verify on each deployment target.)*
- [ ] MySQL version/collation recorded and supported.
- [ ] Production `.env` is not committed.
- [ ] `APP_DEBUG=false`; HTTPS enforced.
- [ ] Database user has least required privileges.
- [ ] Mail transport configured and tested.
- [ ] Queue and cache drivers configured intentionally.
- [ ] `APP_URL`, API base URL, Terms/Privacy URLs, and app version values verified.
- [ ] CORS restricted to intended origins.

## Database and deploy
- [ ] Backup before production migration.
- [ ] Migrations run successfully from clean state in CI.
- [ ] Migration rollback/recovery plan documented.
- [ ] Indexes/unique constraints verified on MySQL.
- [ ] Account deletion and export behavior validated.
- [ ] Restore from backup tested periodically.

## Queue and scheduler
- [ ] Queue worker supervised and restarted on deploy.
- [ ] Failed jobs monitored and reviewed.
- [ ] Scheduled cleanup registered and verified.
- [ ] Metadata job egress restrictions active.
- [ ] Email queue delivery monitored.
- [ ] No queue job can access internal services via arbitrary URL fetch.

## Monitoring
- [ ] `/health` checks database/cache/queue as required.
- [ ] Health returns 503 on failed required dependency.
- [ ] Request IDs appear in safe logs and error responses.
- [ ] Alerting for queue backlog, repeated failures, and service outage.
- [ ] Rate limiting and blocked SSRF attempts observable.
- [ ] Logs exclude passwords, tokens, reset tokens, and full URLs.

## Release smoke tests
- [ ] Register/login/me/logout.
- [ ] Create link and verify metadata job runs asynchronously.
- [ ] Verify duplicate link returns 409.
- [ ] Verify collection/tag operations.
- [ ] Verify cross-user IDs return 404.
- [ ] Verify reset email and reset page.
- [ ] Verify export attachment and account deletion in safe staging.
- [ ] Verify queue, scheduler, and health monitoring.
