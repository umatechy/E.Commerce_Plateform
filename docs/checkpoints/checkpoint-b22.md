============================================================
PHASE B22 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B22 — Security, Audit & Compliance (Module 32)

Implementation Summary:
The audit trail used to be log lines only (storage/logs/audit-*.log). It
is now a queryable, tamper-evident store. Each store and the platform
have their own SHA-256 hash chain, and every entry records who acted,
through which surface, on what, and under whose impersonation. All of
this is derived server-side. All 26 existing audit call sites write
through the new AuditLogger, and authentication events are now audited
for staff and customers. Stores can export and erase a customer's
personal data (right of access / right to erasure) without destroying
financial records. Every response carries baseline security headers,
and the password policy is enforced.

New Components Implemented:
App\Domain\Compliance — AuditLog, AuditActorType, AuditSurface,
AuditLogger, AuditHasher, AuditChainVerifier, AuditRetentionService,
AuditLogQuery, CustomerDataService, RecordAuthenticationEvents,
audit:verify and audit:prune commands, CompliancePolicy,
CustomerErasureBlockedException, AuditLogController,
CustomerPrivacyController, AuditLogResource; SuperAdminAuditController;
AddSecurityHeaders middleware; config/compliance.php. See
docs/architecture/b22-security-audit-compliance.md.

Database Changes:
- New: audit_logs, audit_chain_heads.
- customers.erased_at (nullable timestamp).

Permissions:
audit.view, privacy.manage (PermissionSeeder; Owner only by default,
not granted to the seeded Manager role).

Scheduler:
audit:prune daily at 03:10 (retention AUDIT_RETENTION_DAYS, default 365).

Automated Test Status:
EXECUTED.
- Backend: 754 tests, 1426 assertions — all passing on MySQL 8.0
  (35 new Module 32 tests in tests/Feature/Compliance/).
- Static analysis: PHPStan/Larastan level 5 — no errors.
- Frontend: unchanged in this phase.

Runtime Verification Status:

| Area | Status |
|------|--------|
| Migrations up / rollback / up (MySQL 8.0) | EXECUTED |
| PHPUnit suite (MySQL 8.0, Redis) | EXECUTED — PASSING |
| PHPStan level 5 | EXECUTED — CLEAN |
| audit:verify / audit:prune against the dev database | EXECUTED |
| Security headers over php artisan serve (curl) | EXECUTED |
| GitHub Actions CI run | NOT EXECUTED — runs on the next push to main/develop or a PR |
| Breached-password check (external API) | NOT EXECUTED — production only by design |

Known Limitations:
- A full, consistent rewrite of a chain by a database superuser is not
  detected; chain heads should be published to write-once storage.
- No page CSP for the Inertia admin; no two-factor authentication.
- No admin UI yet for the audit log or privacy requests (API only).

Recommended Next Milestone:
Phase B23 — Module 29 (Billing, Invoices & Renewals): subscriptions have
a lifecycle but no invoices, prices or renewal engine; billing actions
can now be audited through AuditLogger from day one.
