# AI session log

Short summary per session. Full transcripts are in `ai-logs/`.

## Session 1 — 9 November 2026: planning

**Done**
- Read the brief and working rules. Listed ambiguities in the brief and proposed interpretations.
- Proposed architecture, folder structure, data model, REST endpoints, day-by-day plan and risks.
- Checked the local machine: PHP 8.4.1, Composer 2.8.12 and the Laravel installer come from Herd Lite (`pdo_pgsql` present); Node 26, Homebrew and `gh` installed. Missing: local PostgreSQL. No Docker (not needed).
- Wrote `docs/PLAN.md` and `docs/DECISIONS.md`.

**Decisions** (details in `DECISIONS.md`)
- Eneba.com is the design reference (no Figma was provided). Placeholder images.
- Nedas changed the homepage design: `GET /home` returns the list of blocks (type, title, endpoint), so the frontend hardcodes nothing about which blocks exist or their order (D4).
- Checkout expiry, answering Nedas's question: hard 15-minute expiry, a 2-minute payment hold before charging, lazy status updates because Render's free tier has no cron (D10).
- Day 1 is setup and deploy only; CI moves to day 2.

**Open issues**
- Day 1 steps proposed, waiting for approval before scaffolding.
- Exact Eneba colors and fonts still to be sampled (day 2).
