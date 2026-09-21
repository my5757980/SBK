---
name: sbk-auction-constitution
description: SBK Auction Portal project constitution - core principles, standards, tech stack
metadata:
  type: constitution
  project: SBK Auction Portal
  created_date: 2026-07-28
  version: 1.0.0
---

# SBK Auction Portal - Constitution

**Project**: SBK Auction Portal  
**Owner**: Muhammad Yaseen  
**Created**: 2026-07-28  
**Framework**: Spec-Driven Development (SpecKit Plus)

---

## Core Principles

### 1. Spec-Driven Development (Non-Negotiable)
Every feature MUST start with clear specification before any planning or code. Flow:
```
Constitution → Specification → Plan → Tasks → Implementation → PHR
```

### 2. Security First
- ✅ Prepared statements for ALL database queries (SQL injection prevention)
- ✅ bcrypt password hashing (never plaintext)
- ✅ XSS prevention via output encoding (htmlspecialchars)
- ✅ HTTPOnly, Secure cookies (when applicable)
- **No shortcuts on security - non-negotiable**

### 3. Simplicity Over Complexity
- ✅ MVC architecture (NOT microservices)
- ✅ Pragmatic solutions (not perfect)
- ✅ No unnecessary frameworks or dependencies
- ✅ Straightforward code structure

### 4. Transparency & Documentation
- ✅ Every architectural decision documented with rationale
- ✅ Code comments explain WHY, not WHAT
- ✅ Specification-driven (explicit requirements)
- ✅ Audit trails for sensitive operations
- ✅ PHR (Prompt History Record) for all work

### 5. Operational Excellence
- ✅ Real-time data synchronization (minimal latency)
- ✅ Graceful degradation (features work when dependencies fail)
- ✅ Comprehensive logging with timestamps
- ✅ Automated backups (data is sacred)
- ✅ Target: <3 second page load, 99.5% uptime

### 6. User-Centric Design
- ✅ Mobile-first responsive design
- ✅ Intuitive interfaces (no training needed)
- ✅ Clear error messages (actionable, not cryptic)
- ✅ Accessibility standards (WCAG 2.1 AA)
- ✅ Users complete tasks in <3 minutes

---

## Technology Stack (Locked - NO Changes Without ADR)

| Layer | Technology | Version | Rationale |
|-------|-----------|---------|-----------|
| **Frontend** | HTML5 + CSS3 + Vanilla JS | Latest | No framework overhead |
| **Backend** | PHP | 7.4+ | Rapid dev, built-in web features |
| **Database** | MySQL / MariaDB | 5.7+ | ACID, JSON support, reliability |
| **Server** | Apache 2.4 | Latest | mod_php, .htaccess routing |
| **Real-time Sync** | Python 3.8+ Asyncio | Latest | Non-blocking I/O |
| **Browser Automation** | Playwright | Latest | JS-heavy sites, parallel runs |

---

## Architecture Standards

### MVC + Service Layer

**Mandatory layers** (every feature must follow):
```
Presentation (Views)
  ├─ PHP templates (*.php files)
  └─ HTML rendering

Business Logic (Services)
  ├─ functions.php (reusable functions)
  └─ Authentication, processing, syncing

Data Access (Repositories)
  ├─ Prepared statements
  └─ Database abstraction

Database (MySQL)
  ├─ Normalized schema
  └─ Strategic indexes
```

### Database Design Principles

1. **Normalize aggressively** - eliminate duplication, single source of truth
2. **Index strategically** - filter columns, JOINs, full-text search
3. **Use JSON for flexibility** - arrays, semi-structured data, schema evolution
4. **Soft deletes over hard** - is_active flag, preserve audit trail
5. **Timestamp everything** - created_at, updated_at, audit tracking

---

## Code Quality Standards

### Security Mandates (Non-Negotiable)

**SQL Injection Prevention**
```php
// ❌ NEVER
$query = "SELECT * FROM users WHERE id = $id";

// ✅ ALWAYS
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
```

**Password Hashing**
```php
// ✅ ONLY
$hash = password_hash($password, PASSWORD_BCRYPT);
```

**XSS Prevention**
```php
// ✅ ALWAYS on output
echo htmlspecialchars($user_input, ENT_QUOTES, 'UTF-8');
```

**Input Validation**
```php
// ✅ Validate at entry points
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { reject; }
if (strlen($password) < 6) { reject; }
```

### Code Style

- **Comments**: WHY not WHAT (explain reasoning, not obvious code)
- **Naming**: Descriptive, not abbreviated
- **Functions**: Single responsibility
- **Errors**: Graceful fallbacks, clear messages
- **Logging**: Trace all operations

### Testing Standards

| Level | Coverage | Cadence |
|-------|----------|---------|
| **Unit** | 80%+ | Per commit |
| **Integration** | 60%+ | Per sprint |
| **Security** | 100% | Per release |

---

## Feature Development Workflow

### SpecKit Plus Pipeline

**Every feature follows this exact flow:**

```
1. CONSTITUTION (this doc)
   ↓
2. /sp.specify → specs/NNN-feature/spec.md
   (Write requirements, 12+ FR, scenarios)
   ↓
3. /sp.plan → specs/NNN-feature/plan.md
   (Architecture, design decisions, rationale)
   ↓
4. /sp.tasks → specs/NNN-feature/tasks.md
   (Discrete, testable task breakdown)
   ↓
5. Implement (write code following Constitution)
   ↓
6. /sp.phr → history/prompts/
   (Record decisions & rationale)
```

### Feature Folder Structure

```
specs/001-car-auction/
├── spec.md (requirements + acceptance)
├── plan.md (architecture + decisions)
├── tasks.md (implementation tasks)
└── checklists/requirements.md (quality gates)

specs/002-auto-sync/
├── spec.md (...)
├── plan.md (...)
└── tasks.md (...)

specs/NNN-feature-name/
├── spec.md
├── plan.md
└── tasks.md
```

### Pre-Merge Checklist (Every Feature)

- [ ] Specification written (12+ FR items)
- [ ] Plan documented (architecture justified)
- [ ] Tasks completed & tested
- [ ] Code review passed (minimum 1 reviewer)
- [ ] Tests pass (80%+ coverage)
- [ ] No security issues (manual + automated scan)
- [ ] Documentation updated
- [ ] PHR recorded (decision traceability)

---

## Performance Targets

### Page Load Times

| Page | Target | Measured Status |
|------|--------|-----------------|
| Home (index.php) | <3 sec | ✅ |
| Car Details | <3 sec | ✅ |
| Search/Filter | <2 sec | ✅ |
| Admin Dashboard | <2 sec | ✅ |

### Database Queries

| Query Type | Target | Measured Status |
|-----------|--------|-----------------|
| Single row SELECT | <50ms | ✅ |
| Filtered list (100 rows) | <100ms | ✅ |
| Join query (3 tables) | <150ms | ✅ |
| Full-text search | <200ms | ✅ |

### System Capacity

| Metric | Target | Current | Buffer |
|--------|--------|---------|--------|
| Concurrent users | 100 | 50 | 50% |
| Cars in database | 1M | 10k | 1% |
| Requests/sec | 100 | 20 | 80% |

---

## Data Governance

### Classification & Retention

| Classification | Examples | Retention | Encryption | Access |
|---|---|---|---|---|
| **Public** | Car specs, prices | 7 years | No | Everyone |
| **Internal** | Email, phone | 2 years | No | Staff |
| **Sensitive** | Passwords | Until delete | bcrypt | User only |

### Mandatory Logging

- User login/logout (timestamp, IP)
- Order creation/changes (who, when, what)
- Price changes (old → new, timestamp)
- Car deletion (what, by whom)
- All admin actions

**Retention**: 90 days (or per legal)

---

## Operations & Monitoring

### Uptime SLA: 99.5%

**Excludes**: Planned maintenance (24hr notice), DDoS, third-party failures

### Monitoring (24/7)

- Page load time (alert if >3 sec)
- Error rate (alert if >1%)
- Database connection errors (alert immediately)
- Disk space (alert if >80%)
- CPU usage (alert if >80%)

### Incident Response (SLA)

| Severity | Impact | Response SLA |
|----------|--------|--------------|
| **P0** | Site down | 15 min |
| **P1** | Feature broken | 4 hours |
| **P2** | Degraded | 1 day |
| **P3** | Minor | Best effort |

---

## Deployment Standards

### Deployment Checklist

- [ ] All tests pass (unit + integration + E2E)
- [ ] No security vulnerabilities (OWASP Top 10)
- [ ] Database migrations tested & rollback verified
- [ ] Performance benchmarked (no regression)
- [ ] Documentation updated
- [ ] Feature flags configured
- [ ] Monitoring alerts configured
- [ ] Backup verified & tested

---

## Decision Framework

### How to Make Architectural Decisions

1. **Define the problem** (not the solution)
2. **Identify constraints** (time, budget, expertise, dependencies)
3. **List options** (minimum 2, maximum 4)
4. **Decide** (weighted by values):
   - Simplicity (40%)
   - Security (30%)
   - Performance (20%)
   - Cost (10%)
5. **Document** via ADR (Architecture Decision Record)
6. **Communicate** to team

---

## Roadmap

### Phase 1: MVP (COMPLETE ✅)
- Car listings, search, orders, inquiries, admin dashboard
- Manual data import
- Capacity: 10k cars, 50 concurrent users

### Phase 2: Automation (IN PROGRESS)
- Automated scraper (full catalog)
- Incremental sync (every 5 min)
- Capacity: 100k cars, 100 concurrent users

### Phase 3: Scaling (PLANNED)
- Redis caching
- API layer
- Mobile app
- Capacity: 1M cars, 1000 concurrent users

### Phase 4: Intelligence (PLANNED)
- Recommendation engine
- Price predictions
- Alerts & notifications

---

## Success Metrics

### Business
- Cars listed: 10k → 100k → 1M
- Orders/month: Trend upward
- Client NPS: >50
- Revenue: Monthly ARR target

### Technical
- Uptime: 99.5%+
- Page load: <3 seconds
- Test coverage: 80%+
- Security: Zero critical vulnerabilities

### Team
- Code review time: <24 hours
- Deployment frequency: Weekly
- Incident resolution (P1): <4 hours
- Developer satisfaction: Positive

---

## Sign-Off

**Constitution Approved**: ✅  
**Version**: 1.0.0  
**Effective**: 2026-07-28  
**Author**: Claude Code (Agent)  
**Owner**: Muhammad Yaseen

This Constitution is the foundation for ALL development. Every feature, every line of code, every decision aligns with these principles.

**ALL FEATURES MUST FOLLOW THIS CONSTITUTION**

---

**Last Updated**: 2026-07-28  
**Review Cycle**: Quarterly (every 90 days)

