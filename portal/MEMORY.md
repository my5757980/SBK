# SBK Auction Portal - Project Memory Index

**Project**: SBK Auction Portal  
**Owner**: Muhammad Yaseen  
**Created**: 2026-07-28  
**Version**: 1.0.0

---

## Core Project Documents

### Constitution (Project Foundation)
- [Constitution](.specify/memory/constitution.md) — Project principles, architecture, standards, decision framework

### Features & Specifications
- [Feature 1: Car Auction Portal](specs/1-car-auction/spec.md) — Car listings, orders, inquiries
  - [Plan](specs/1-car-auction/plan.md) — MVC architecture, 6 design patterns, 8 technical debt issues
  - [Tasks](specs/1-car-auction/tasks.md) — 28 implementation tasks across 8 phases
  - [Quality Checklist](specs/1-car-auction/checklists/requirements.md) — 15 criteria passing

### Feature 1 Update: Auto-Sync Background Service (Phase 9)
- [Status]: IN PROGRESS - Tasks 29-41
- [Purpose]: Every 5 minutes, sync only new/changed cars from nikkyocars.ajes.com into portal database
- [Scope]: Python asyncio service, minimal requests, smart change detection, 24/7 infinite loop with logging
- [Implementation]: auto_sync_fast.py + modules/ (auth.py, change_detection.py, data_fetcher.py, database.py, logger.py)

---

## Prompt History Records (PHR)

### Car Auction Portal
- [Spec Creation](history/prompts/1-car-auction/1-car-auction-spec.md) — Initial specification work
- [Reverse Engineering](history/prompts/1-car-auction/2-reverse-engineer-complete.md) — Architecture documentation

### Constitution
- [Constitution Creation](history/prompts/constitution/1-constitution-created.md) — Project-wide principles (UPCOMING)

---

## User Preferences & Working Style

**Address**: "Sir"  
**Language**: Roman Urdu (Hindi/Urdu in Latin characters)  
**Preference**: Live testing, brutal honesty, no fake "done"  
**Style**: Follow SDD (Spec-Driven Development) pipeline:
1. Constitution (project foundation)
2. Specification (requirements)
3. Plan (architecture)
4. Tasks (implementation)
5. Implement (code)
6. PHR (document)

---

## Project Status

| Aspect | Status |
|--------|--------|
| **Architecture** | MVC + Service Layer ✅ |
| **Database** | MySQL sbk_auction (4 tables) ✅ |
| **Website** | http://localhost/sbk-auction/ (LIVE) ✅ |
| **Documentation** | Complete (spec, plan, tasks) ✅ |
| **Security** | Prepared statements, bcrypt, XSS prevention ✅ |
| **Testing** | Manual testing complete ✅ |
| **Constitution** | CREATED ✅ |
| **Auto-Sync** | IN PROGRESS (next feature) |

---

## Quick Links

- **Project Directory**: E:\New folder\sbk-auction\
- **Database**: MySQL sbk_auction on localhost:3306
- **Web**: Apache on port 80 (E:\New folder (5)\New folder (2)\)
- **Logs**: sbk_scraper.log, sync_log.txt

---

**Note**: This index is updated as project evolves. Check constitution.md for detailed architecture & standards.
