# Technopath Commerce Intelligence
## Customer Operations, Deployment, and Maintenance Guide

> **Document Classification:** Customer-Facing & Operations Reference  
> **Target Audience:** Client Technical Leads, DevOps Engineers, System Administrators, QA Managers  
> **System Scope:** Technopath Commerce Intelligence Platform (Laravel 11, PostgreSQL 16, Redis 7, Shopify & Adobe Commerce Connectors, GA4 / Clarity Telemetry, AI Engine)  
> **Last Updated:** September 2026  

---

## Table of Contents
1. [Executive Summary & System Overview](#1-executive-summary--system-overview)
2. [Development, Staging, and Production Environments](#2-development-staging-and-production-environments)
3. [Required Access and Administrative Prerequisites](#3-required-access-and-administrative-prerequisites)
4. [Dev-to-Production Deployment and Approval Workflow](#4-dev-to-production-deployment-and-approval-workflow)
5. [Backup, Snapshot, Retention, and Restore Procedures](#5-backup-snapshot-retention-and-restore-procedures)
6. [Code and Database Rollback Procedures](#6-code-and-database-rollback-procedures)
7. [Post-Deployment and Post-Rollback Validation](#7-post-deployment-and-post-rollback-validation)
8. [Troubleshooting and Escalation Guidance](#8-troubleshooting-and-escalation-guidance)

---

## 1. Executive Summary & System Overview

**Technopath Commerce Intelligence (TCI)** is an enterprise e-commerce intelligence engine designed to aggregate, analyze, and generate actionable insights across multi-channel store platforms (Shopify, Adobe Commerce), analytics tools (Google Analytics 4, Microsoft Clarity), and customer interaction channels.

### Core Stack Architecture
* **Application Framework:** Laravel 11.x (PHP 8.2+) with Vite asset management.
* **Primary Database:** PostgreSQL 16 (Relational analytics, encrypted credentials, integration states).
* **Caching & Message Broker:** Redis 7 (Session driver, application caching, async queue workers).
* **AI & Intelligence Engine:** Multi-provider LLM integrations (OpenAI, OpenRouter, Google Gemini) for automated meeting transcription, sentiment analysis, and commerce metrics anomaly detection.
* **E-Commerce & Telemetry Integrations:**
  * Shopify REST / GraphQL Admin APIs
  * Adobe Commerce REST API
  * Google Analytics 4 Data API (Service Account OAuth2)
  * Microsoft Clarity Export API
  * Google OAuth2 User Authentication
  * Atlassian Jira Cloud Integration

---

## 2. Development, Staging, and Production Environments

TCI utilizes a strict 3-tier isolated environment hierarchy to guarantee zero customer impact during development and testing phases.

### 2.1 Environment Matrix

| Environment Feature | Development (`dev`) | Staging / UAT (`staging`) | Production (`production`) |
| :--- | :--- | :--- | :--- |
| **Purpose** | Feature development, unit testing, local integration | Pre-release validation, UAT, load testing, customer preview | Live customer operations & background job processing |
| **Base Domain** | `https://dev.technopathcommerce.com` | `https://staging.technopathcommerce.com` | `https://app.technopathcommerce.com` |
| **Database Isolation** | Isolated Postgres DB (`tci_dev`) | Replica-schema Postgres DB (`tci_staging`) | High-Availability Postgres Cluster (`tci_prod`) |
| **Redis Broker** | Dedicated DB `0` & `1` | Dedicated DB `2` & `3` | Dedicated Redis Cluster / Managed ElastiCache |
| **Debug Mode (`APP_DEBUG`)** | `true` | `false` | `false` |
| **Log Level (`LOG_LEVEL`)** | `debug` | `info` | `notice` / `error` |
| **External API Mode** | Mock / Sandbox API endpoints | Sandbox / Staging store credentials | Live Production E-Commerce & Analytics APIs |
| **Queue Worker Execution** | Local synchronous or async `php artisan queue:work` | Multi-worker background process | Scaled, auto-healing queue supervisor processes |

### 2.2 Environment Configuration Management

Environment variables are managed dynamically via `.env` templates and sealed secrets vaults:
* **Secrets Management:** Environment secrets (`APP_KEY`, database credentials, API tokens) MUST NOT be committed to version control. They are injected via HashiCorp Vault / AWS Secrets Manager / GitHub Repository Secrets at deploy time.
* **Database Isolation:** Cross-environment database connections are blocked at the cloud security group / firewall level. Staging and Production databases run on distinct VPC subnets.
* **Data Privacy & Anonymization:** Staging environments use sanitized or synthetic datasets. Real customer telemetry and PII from production are strictly prohibited in Non-Production environments.

---

## 3. Required Access and Administrative Prerequisites

### 3.1 Role-Based Access Control (RBAC) Matrix

To operate, deploy, or maintain the TCI platform, personnel must be assigned specific roles according to the principle of least privilege:

| Role | Development Env | Staging Env | Production Env | CI/CD & GitHub |
| :--- | :--- | :--- | :--- | :--- |
| **Software Engineer** | Admin / Full Control | Read / Deployment Trigger | No direct access | Write (Feature branches) |
| **QA / Automation Engineer** | Read / Write | Full Access | Read-only Telemetry | Read / Reviewer |
| **DevOps / SRE Lead** | Full Control | Full Control | Full Admin & SSH | Maintainer / Admin |
| **Security / Compliance** | Audit Access | Audit Access | Audit & Log Access | Read / Security Alerts |
| **Client Administrator** | No Access | Tenant Admin (UAT) | Tenant Admin | No Access |

### 3.2 Infrastructure & Security Prerequisites
1. **Identity & Access Management (IAM):**
   * Multi-Factor Authentication (MFA) required across all cloud consoles, GitHub, and bastion servers.
   * Access to production servers requires SSH Key-Pair authentication via an AWS Systems Manager (SSM) Session Manager or encrypted VPN with IP whitelisting.
2. **Network Security:**
   * Inbound HTTP/HTTPS traffic restricted to ports `80` / `443` via Cloudflare / AWS ALB WAF.
   * PostgreSQL (`5432`) and Redis (`6379`) accessible ONLY within private VPC subnets.

### 3.3 Third-Party API & Service Prerequisites Checklist

Before promoting to Staging or Production, ensure the following API credentials and configurations are provisioned:

```
[ ] Shopify Integration:
    - Custom App / Public App API Key & Shared Secret
    - Encrypted Access Tokens with scopes: read_orders, read_products, read_customers
[ ] Adobe Commerce Integration:
    - Integration Access Token (OAuth 1.0a / Bearer Token)
    - REST Base URL verified
[ ] Google Analytics 4 (GA4):
    - Service Account JSON key stored at designated path or base64 env string
    - GA4 Property ID linked with read permissions
[ ] Microsoft Clarity:
    - API Bearer Token provisioned (Rate Limit aware: 10 calls/project/day)
[ ] AI Provider Credentials:
    - OpenAI API Key (`OPENAI_API_KEY`)
    - OpenRouter API Key (`OPENROUTER_API_KEY`)
    - Google Gemini API Key (`GEMINI_API_KEY`)
[ ] Google OAuth2:
    - OAuth 2.0 Client ID & Client Secret
    - Authorized Redirect URIs configured (e.g., https://app.technopathcommerce.com/google/oauth/callback)
[ ] Atlassian Jira Cloud:
    - API Service Account Token & Org Base URL (`JIRA_BASE_URL`, `JIRA_EMAIL`, `JIRA_API_TOKEN`)
```

---

## 4. Dev-to-Production Deployment and Approval Workflow

### 4.1 Git Branching Strategy & Lifecycle

```
[ feature/* ] ──(PR / Review)──> [ develop ] ──(Auto Deploy)──> Development Env
                                      │
                                (Release Tag)
                                      ▼
                               [ release/vX.Y.Z ] ──(Deploy)──> Staging Env (UAT)
                                      │
                                 (CAB Approval)
                                      ▼
                                  [ main ] ────────(Deploy)──> Production Env
```

1. **Feature Branches (`feature/*`):** Developers build isolated code. All PRs require at least one peer code review and passing CI pipelines.
2. **Development Branch (`develop`):** Merged feature branches automatically build and deploy to the Development environment.
3. **Release Branches (`release/vX.Y.Z`):** Created when preparing for a major/minor release. Deployed to Staging for UAT and security scanning.
4. **Main Branch (`main`):** Production-ready tag. Merging to `main` requires explicit Change Advisory Board (CAB) sign-off.

### 4.2 Automated CI/CD Pipeline Stages

Every pull request and merge triggers the automated GitHub Actions / GitLab CI pipeline:

> [!NOTE]  
> A failure in any stage immediately aborts the deployment pipeline and alerts the release team via Slack/Teams.

1. **Static Analysis & Formatting:**
   * `vendor/bin/pint --test` (Laravel Pint code style check)
   * `vendor/bin/phpstan analyse` (PHPStan static type checking)
2. **Automated Testing Suite:**
   * `php artisan test` / `vendor/bin/phpunit` (Unit, Feature, and Integration tests)
3. **Frontend Asset Compilation:**
   * `npm ci && npm run build` (Vite production asset bundle)
4. **Container Build & Security Vulnerability Scan:**
   * Docker image build (`postgres:16-alpine`, `redis:7-alpine`, PHP 8.2-FPM container)
   * Trivy / Clair vulnerability scanning for OS and PHP dependencies (`composer audit`).

### 4.3 Staging-to-Production Promotion & Governance Gates

To promote a release from Staging to Production, the following approval criteria MUST be satisfied:

1. **UAT Sign-off:** QA Lead and Product Owner approve feature completeness in Staging.
2. **Security & Vulnerability Pass:** Zero critical/high CVEs in application dependencies.
3. **Change Management Ticket:** Jira deployment ticket logged with release notes, risk assessment, and execution window.
4. **CAB Approval:** Sign-off from DevOps Lead and Client Technical Sponsor.

### 4.4 Standard Zero-Downtime Deployment Execution Steps

Deployments are executed using a Blue-Green or Rolling Deployment pattern. Follow these step-by-step commands during an authorized release window:

#### Step 1: Pre-Deployment Health Check & Backup Trigger
```bash
# Verify environment readiness
php artisan health:check || exit 1

# Trigger automated database pre-deployment snapshot
pg_dump -U tci -h $DB_HOST -d technopath_commerce -F c -b -v -f "/backups/pre_deploy_$(date +%Y%m%m_%H%M%S).dump"
```

#### Step 2: Enable Maintenance Mode Banner (Optional for maintenance windows)
```bash
php artisan down --secret="tci-deploy-bypass-key" --retry=60 --status=503
```

#### Step 3: Fetch Code & Update Dependencies
```bash
# Pull production main branch or pull updated Docker containers
git checkout main && git pull origin main

# Install optimized PHP production dependencies
composer install --no-dev --optimize-autoloader

# Compile production assets
npm ci && npm run build
```

#### Step 4: Database Migrations
```bash
# Run database migrations in forced production mode
php artisan migrate --force
```

#### Step 5: Clear and Rebuild System Caches
```bash
# Clear old cached states
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Rebuild production performance caches
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

#### Step 6: Restart Background Queue Workers Gracefully
```bash
# Signal queue workers to complete current jobs and restart with new code
php artisan queue:restart
```

#### Step 7: Disable Maintenance Mode & Re-enable Traffic Router
```bash
php artisan up
```

---

## 5. Backup, Snapshot, Retention, and Restore Procedures

### 5.1 Recovery Objectives (RPO / RTO)
* **Recovery Point Objective (RPO):** $< 15\text{ minutes}$ (Maximum acceptable data loss window).
* **Recovery Time Objective (RTO):** $< 60\text{ minutes}$ (Maximum acceptable system downtime).

### 5.2 Backup Strategy Matrix

| Data Asset | Backup Mechanism | Frequency | Storage Location | Retention Period |
| :--- | :--- | :--- | :--- | :--- |
| **PostgreSQL Database** | Full Dump (`pg_dump`) | Daily (01:00 UTC) | Encrypted S3 / GCS Bucket | 30 Days |
| **PostgreSQL WAL Logs** | Continuous WAL Archiving (PITR) | Real-time / Every 5 min | Immutable Off-site Cloud Storage | 14 Days |
| **EBS / Storage Snapshots**| Cloud Infrastructure Snapshot | Every 6 Hours | AWS/GCP Native Snapshot Vault | 7 Days |
| **Redis Cache / Queues** | RDB Snapshot (`SAVE`/`BGSAVE`) | Hourly | Local & Cloud Storage | 3 Days |
| **File Storage (`storage/app`)**| Cross-Region Object Replication | Real-time | Secondary S3 Storage Bucket | 90 Days |
| **Compliance Archive** | Immutable Encrypted Snapshot | Monthly (1st of month) | Cold Glacier Vault | 7 Years |

### 5.3 Automated Database Backup Procedure

The daily PostgreSQL database backup is driven by an automated cron task running:

```bash
#!/usr/bin/env bash
set -eo pipefail

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_DIR="/var/backups/tci_postgres"
BACKUP_FILE="${BACKUP_DIR}/tci_prod_${TIMESTAMP}.sql.gz"
S3_BUCKET="s3://technopath-backups-prod/database"

mkdir -p "$BACKUP_DIR"

# Perform compressed custom-format pg_dump
pg_dump -h $DB_HOST -U $DB_USERNAME -d $DB_DATABASE -Fc | gzip > "$BACKUP_FILE"

# Upload to encrypted off-site cloud storage
aws s3 cp "$BACKUP_FILE" "${S3_BUCKET}/tci_prod_${TIMESTAMP}.sql.gz" --sse AES256

# Prune local backups older than 7 days
find "$BACKUP_DIR" -type f -name "*.sql.gz" -mtime +7 -exec rm -f {} \;
```

### 5.4 Step-by-Step Restoration Procedures

> [!CAUTION]  
> Executing a database restore will overwrite existing target database data. Always isolate the destination database and stop queue workers before restoring!

#### Option A: Full Database Restoration from Backup Dump
1. **Stop Application Queues & Put App in Maintenance Mode:**
   ```bash
   php artisan down --message="Database maintenance in progress."
   php artisan queue:pause
   ```

2. **Drop Existing Active Connections & Restore Database:**
   ```bash
   # Terminate all active database connections
   psql -h $DB_HOST -U $DB_USERNAME -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'technopath_commerce' AND pid <> pg_backend_pid();"

   # Drop and recreate target database
   dropdb -h $DB_HOST -U $DB_USERNAME technopath_commerce
   createdb -h $DB_HOST -U $DB_USERNAME technopath_commerce

   # Restore from compressed backup dump
   gunzip -c /var/backups/tci_postgres/tci_prod_20260908_010000.sql.gz | pg_restore -h $DB_HOST -U $DB_USERNAME -d technopath_commerce -v --no-owner --role=$DB_USERNAME
   ```

3. **Verify Integrity & Re-enable Application:**
   ```bash
   # Run integrity check
   php artisan db:monitor
   php artisan migrate --status

   # Clear cache and bring app back up
   php artisan config:clear
   php artisan up
   ```

#### Option B: Point-In-Time Recovery (PITR) Procedure
For recovering from accidental data deletion occurring at a specific time (e.g., `2026-09-08 14:15:00 UTC`):

1. Provision a new PostgreSQL instance from the latest base full backup prior to the event.
2. Apply `recovery.conf` / PostgreSQL 16 recovery target configuration:
   ```ini
   restore_command = 'aws s3 cp s3://technopath-backups-prod/wal/%f %p'
   recovery_target_time = '2026-09-08 14:14:59 UTC'
   recovery_target_action = 'promote'
   ```
3. Start PostgreSQL service to replay WAL logs up to the exact millisecond before corruption.
4. Repoint application database host environment variable (`DB_HOST`) to the restored instance.

---

## 6. Code and Database Rollback Procedures

### 6.1 Rollback Trigger Criteria

An immediate rollback MUST be initiated if any of the following conditions occur within 30 minutes post-deployment:
* **HTTP 5xx Error Spike:** Elevated server error rate exceeding $1.0\%$ of total requests.
* **Database Deadlocks / Migration Failures:** Schema migration failures or unhandled database locking.
* **Core Business Flow Degradation:** Failures in Shopify/Adobe sync, order ingestion, or user authentication.
* **Queue Stagnation:** Queue job failures exceeding $5\%$ or queue backlog increasing exponentially.

### 6.2 Application Code Rollback Procedure

#### Scenario A: Containerized / Blue-Green Router Reversion (Instant)
If using Blue-Green deployments or load balancer target groups:
1. Re-route 100% of ingress traffic to the previous **Green (Stable)** container target group via AWS ALB / Cloudflare API.
2. Verify traffic stability on the Green target group.

#### Scenario B: Direct Server Git / Artifact Rollback
```bash
# 1. Put application into maintenance mode
php artisan down --secret="tci-rollback-key"

# 2. Revert Git commit or checkout target release tag
git checkout release/v1.4.1 # (Previous stable release tag)

# 3. Reinstall production dependencies & compile previous assets
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# 4. Gracefully restart queue workers
php artisan queue:restart

# 5. Clear application caches
php artisan config:cache
php artisan route:cache

# 6. Bring application back online
php artisan up
```

### 6.3 Database Rollback Procedures

#### Scenario A: Migration Reversion (Reversible Schema Changes)
If the deployed release included reversible Laravel database migrations:

```bash
# Roll back the last migration batch
php artisan migrate:rollback --step=1 --force

# Verify schema state
php artisan migrate:status
```

> [!WARNING]  
> `migrate:rollback` will drop newly created tables or columns introduced in the target batch. Ensure any newly ingested data is backed up before executing.

#### Scenario B: Irreversible / Destructive Schema Changes
If migrations altered, renamed, or deleted columns destructively:
1. Do **NOT** run `migrate:rollback`.
2. Restose the database to the pre-deployment snapshot created in [Section 4.4 Step 1](#step-1-pre-deployment-health-check--backup-trigger).
3. Re-play any customer transactions or e-commerce sync events logged during the failed window from the queue dead-letter pool or external API sync logs.

### 6.4 Dead-Letter Queue (DLQ) & Job Reconciliation

Following code or database rollback, failed background queue jobs must be reconciled:

```bash
# List failed jobs recorded in Redis / Database
php artisan queue:failed

# Retry specific critical jobs or retry all failed jobs
php artisan queue:retry all
```

---

## 7. Post-Deployment and Post-Rollback Validation

### 7.1 Automated Healthcheck Verification

Immediately following a deployment or rollback, execute the automated health checks:

```bash
# Run Laravel framework system check
php artisan health:check

# Ping core application routes via curl
curl -f -I https://app.technopathcommerce.com/up
curl -f -s https://app.technopathcommerce.com/api/health
```

Expected HTTP Response: `200 OK` with JSON payload:
```json
{
  "status": "ok",
  "timestamp": "2026-09-08T14:55:00Z",
  "services": {
    "database": "connected",
    "redis": "connected",
    "queues": "operational"
  }
}
```

### 7.2 Post-Deployment Functional Checklist

| Functional Domain | Test Procedure | Expected Result | Sign-off |
| :--- | :--- | :--- | :--- |
| **Authentication** | Perform Google OAuth login & standard session creation | User authenticated successfully, token stored in Redis | `[ ] Pass` |
| **Shopify Connector** | Trigger manual webhooks test / sync fetch (`/api/shopify/sync`) | Store metrics ingested without OAuth errors | `[ ] Pass` |
| **Adobe Commerce** | Ping REST endpoint status (`/api/adobe/health`) | Handshake verified (`200 OK`) | `[ ] Pass` |
| **GA4 & Clarity** | Verify telemetry ingestion workers | Event metrics recorded in PostgreSQL tables | `[ ] Pass` |
| **AI Engine** | Execute prompt test via Meeting Agent / Intelligence Engine | Valid AI analysis returned from active LLM provider | `[ ] Pass` |
| **Jira Integration** | Create test ticket synchronization item | Ticket created/updated in Jira Cloud | `[ ] Pass` |
| **Queue Workers** | Dispatch test background job | Job processed with zero failures in `failed_jobs` | `[ ] Pass` |

### 7.3 Log Inspection & Telemetry Audit

Inspect real-time application logs for latent exceptions or PHP deprecations:

```bash
# Tail live production application log
tail -f storage/logs/laravel.log | grep -iE "error|critical|exception"

# Check Redis memory and queue length
redis-cli -h $REDIS_HOST info memory
redis-cli -h $REDIS_HOST llen queues:default
```

---

## 8. Troubleshooting and Escalation Guidance

### 8.1 Severity Level Classification Matrix

| Severity Level | Definition & Impact | Initial Response SLA | Target Resolution SLA |
| :--- | :--- | :--- | :--- |
| **Sev-1 (Critical)** | Complete platform outage, database corruption, or total inability to ingest e-commerce data. | $< 15\text{ mins}$ | $< 2\text{ hours}$ |
| **Sev-2 (High)** | Degradation of core features (e.g., AI insights offline, single e-commerce connector failing). | $< 30\text{ mins}$ | $< 6\text{ hours}$ |
| **Sev-3 (Medium)** | Minor feature bug, non-critical UI issue, slow query performance with working workaround. | $< 2\text{ hours}$ | $< 24\text{ hours}$ |
| **Sev-4 (Low)** | Minor cosmetic issue, documentation update, or feature request. | $< 1\text{ business day}$ | Next Planned Sprint |

### 8.2 Common Failure Modes & Diagnostic Runbooks

#### Issue 1: High Database CPU / Lock Contention
* **Symptom:** Slow API responses ($> 5\text{s}$), database connection timeouts.
* **Diagnosis Commands:**
  ```sql
  -- Identify long-running queries
  SELECT pid, now() - pg_stat_activity.query_start AS duration, query, state
  FROM pg_stat_activity
  WHERE state != 'idle' AND (now() - pg_stat_activity.query_start) > interval '5 seconds';

  -- Terminate blocking query if necessary
  SELECT pg_cancel_backend(pid);
  ```
* **Resolution:** Restart idle pool connections, scale read-replicas, or optimize missing index on queried foreign keys.

#### Issue 2: Redis Queue Backlog / Stalled Queue Workers
* **Symptom:** Delayed background analytics generation or unhandled jobs piling up.
* **Diagnosis Commands:**
  ```bash
  # Check failed job count
  php artisan queue:failed

  # Check process status of supervisor workers
  supervisorctl status tci-worker:*
  ```
* **Resolution:** Scale worker instances or restart worker processes using `php artisan queue:restart`.

#### Issue 3: E-Commerce Integration API Rate Limiting (Shopify / Clarity)
* **Symptom:** HTTP 429 Too Many Requests in `laravel.log`. Note: Microsoft Clarity has a strict limit of 10 requests/project/day.
* **Diagnosis:** Search logs for rate limit response headers.
* **Resolution:** Verify exponential backoff logic in job retries; adjust cron schedule in `config/intelligence.php` to prevent API rate limit exhaustion.

#### Issue 4: AI Provider Outage / Token Exhaustion
* **Symptom:** Failure in intelligence engine or meeting transcript summaries.
* **Resolution:** Dynamic fallback switching in `.env`:
  ```bash
  # Switch primary provider from openrouter to openai or gemini
  AI_PROVIDER=gemini
  ```
  Then reload configuration: `php artisan config:cache`.

### 8.3 Incident Escalation Path

```
[ Customer / Telemetry Alert ]
              │
              ▼
    [ L1 Support Desk ] ──(Unresolved > 15m)──> [ L2 DevOps / SRE On-Call ]
                                                        │
                                                 (Code Defect / Outage > 30m)
                                                        ▼
                                             [ L3 Core Engineering Lead ]
                                                        │
                                                 (Executive Escalation)
                                                        ▼
                                             [ Technical VP & Client Success ]
```

### 8.4 Escalation Contact Directory

> [!IMPORTANT]  
> Emergency escalation phone numbers and PagerDuty schedules are maintained in the customer portal secure directory.

* **Primary On-Call SRE Team:** `sre-alerts@technopath.com`
* **Technical Support Lead:** `support@technopath.com`
* **Jira Support Portal:** `https://technopath.atlassian.net/servicedesk`
* **Status Page:** `https://status.technopathcommerce.com`

---

## 9. Document Sign-off & Revision Control

| Revision | Date | Author | Description of Changes |
| :--- | :--- | :--- | :--- |
| **v1.0.0** | 2026-09-08 | Technopath DevOps & Engineering Team | Initial Customer-Facing Release of Operations & Deployment Guide |
