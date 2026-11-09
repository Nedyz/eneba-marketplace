# Eneba Marketplace (internship assignment)

## Context
- Homework assignment for the Eneba Software Engineer (paid internship). The full brief is in `docs/ASSIGNMENT.md`.
- Deadline: 16 November 2026. Target submission: 15 November.
- Reviewers will read the full AI conversation history, so prefer clear reasoning and explanations over speed.
- The developer must be able to explain every part of the codebase in an interview.

## Stack
- `frontend/`: React + TypeScript (Vite), React Router, Tailwind CSS
- `backend/`: PHP 8 + Laravel, REST JSON API
- Database: PostgreSQL hosted on Neon (free plan), with the `pg_trgm` extension for typo-tolerant search
- Deployment: frontend on Vercel, backend on Render (Docker), database on Neon

## Scope priorities
- Go deep: search (typo tolerance, partial names, suggestions), store filters, sorting and pagination (state kept in the URL), product page offer comparison, checkout with transactional digital key assignment.
- Keep simple: authentication (email and password), homepage with 3 blocks (banner, best sellers, deals), order history, simulated payment (Stripe test mode only if time allows).
- The full purchase flow (homepage or search, product, cart, checkout with sign-in, payment, order and key) must work end to end.

## Working agreement
- Plan before code: for each feature, propose a short plan and wait for approval before writing code.
- Work in small steps, one feature per session.
- After writing code, explain what it does and why. Use C#/.NET comparisons where they help.
- Write tests for backend logic, especially search and key assignment at checkout.
- Homepage content is never hardcoded in the frontend; each block comes from its own backend service.
- Never commit secrets. Use `.env` files and keep `.env.example` up to date.
- Record scope decisions and trade-offs in `docs/DECISIONS.md` (used later to justify scope in the README).
- At the end of each session, append a short summary to `docs/AI_LOG.md`: what was done, decisions made, open issues. Full transcripts are exported separately with `/export` into `ai-logs/`.

## Commands
To be filled in once the frontend and backend are scaffolded.
