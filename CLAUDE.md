# Umar Techy E-Commerce Platform

Core multi-tenant e-commerce SaaS (Laravel + Inertia/React). Basic / Business /
Premium are entitlement tiers of one codebase.

## Specifications come first

- The authoritative specs live in [`docs/source/`](docs/source/README.md): Project
  Bible, SRS, Master Index, Master Development Prompt, and Module Blueprints 01–35.
  Read the relevant module blueprint **before** planning or building a phase.
  Do not rely on second-hand summaries in checkpoints.
- The scope is **35 modules** (see the module map in `docs/source/README.md`). A
  module missing from the code is unbuilt scope, not unknown scope.
- Never edit files under `docs/source/`. They are stored byte-for-byte and checked
  by `docs/source/SHA256SUMS`.
- URS v1.0 and Technical Architecture v1.0 have **not** been received yet. Say so
  instead of inventing their content.
- Order of authority: Project Bible > SRS/URS > Technical Architecture > Module
  Blueprints > ADRs (`docs/adr/`) > existing code. Report conflicts; do not
  silently resolve them.

## Progress records

- What is built vs the specs, and the prioritized gap list:
  `docs/development/requirement-gap-matrix.md`. Update it when a phase closes a gap.

- Phase checkpoints: `docs/checkpoints/checkpoint-bNN.md` (latest = highest NN).
- Per-phase architecture, security review and inspection findings:
  `docs/architecture/`, `docs/security/`, `docs/development/`.
