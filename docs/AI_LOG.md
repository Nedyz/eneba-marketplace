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
- Exact Eneba colors and fonts still to be sampled (day 2).

## Session 1 (continued) — 9 November 2026: setup and deployment (day 1)

**Done**
- Installed PostgreSQL 17 (Homebrew), created `eneba` and `eneba_test`.
- Root `.gitignore` committed before any `.env` existed.
- Backend: Laravel 13 API with Sanctum; tests forced onto PostgreSQL (`eneba_test`) because `pg_trgm` does not exist in SQLite; `pg_trgm` enabled by a migration; `GET /api/health` (DB + `pg_trgm` check, 200/503) and CORS restricted to `FRONTEND_URL`; 8 tests.
- Removed installer extras that could not be justified: generated agent files (`backend/CLAUDE.md`, `AGENTS.md`), `laravel/pao` (changes test output when an AI agent runs tests), unused Vite/Blade files.
- Frontend: Vite + React 19 + TypeScript (strict), React Router 8, TanStack Query, Tailwind v4; typed fetch client; temporary health page; `vercel.json` for deep links.
- Dockerfile (serversideup/php 8.4 fpm-nginx) with migrations and caching on start.
- Deployed: https://eneba-marketplace.vercel.app → https://eneba-marketplace-api.onrender.com → Neon. Verified in a browser: health ok, deep links, CORS.
- Filled in the Commands and Deployment sections of `CLAUDE.md`.

**Decisions**
- D18: Sanctum tokens expire after 7 days and are deleted on logout (after checking in Sanctum's source that only SHA-256 hashes are stored).
- D19: a failed migration stops the container before nginx starts, so Render keeps the previous version live; migrations must be backward compatible.

**Problems found and fixed**
- A CORS test assumed a disallowed origin gets no header; the library sends the single allowed origin instead (the browser still blocks). Test rewritten to check the real property.
- Without local Docker, the image build and startup were replayed locally. This found two bugs that would have broken the first deploy: `optimize` failing on the missing views folder, and Laravel falling back to SQLite without `DB_CONNECTION`. Both fixed in the Dockerfile before the first Render build.

**Open issues**
- Day 2: CI (GitHub Actions + Postgres), migrations and seeders, catalog API, Eneba theme tokens, layout shell.
- Confirm on day 2 with a deliberately broken migration that Render's free tier keeps the old version live (D19).
- Render free tier cold start (about a minute) still to be handled in the UI and README.
