# Umar Techy E-Commerce Platform

Core multi-tenant e-commerce SaaS (Laravel + Inertia/React). Basic / Business /
Premium are entitlement tiers of one codebase.

## Specifications come first

- The authoritative specs live in [`docs/source/`](docs/source/README.md): Project
  Bible, URS, SRS, Technical Architecture, Master Index, Master Development Prompt,
  Module Blueprints 01–35, and the owner's decisions (`docs/source/decisions/`).
  Read the relevant module blueprint **before** planning or building a phase.
  Do not rely on second-hand summaries in checkpoints.
- The scope is **35 modules** (see the module map in `docs/source/README.md`). A
  module missing from the code is unbuilt scope, not unknown scope.
- Never edit files under `docs/source/`. They are stored byte-for-byte and checked
  by `docs/source/SHA256SUMS`.
- Owner decisions (tax configurable/no hardcoded rates, provider adapters with no
  fake credentials, alerts, retention/RPO/RTO, no inventory costing, OpenAI behind
  an abstraction) are in `docs/source/decisions/`. Never invent credentials, tax
  rates or provider capabilities.
- Order of authority: Project Bible > SRS/URS > Technical Architecture > Module
  Blueprints > ADRs (`docs/adr/`) > existing code. Report conflicts; do not
  silently resolve them.

## Progress records

- What is built vs the specs, and the prioritized gap list:
  `docs/development/requirement-gap-matrix.md`. Update it when a phase closes a gap.

- Phase checkpoints: `docs/checkpoints/checkpoint-bNN.md` (latest = highest NN).
- Per-phase architecture, security review and inspection findings:
  `docs/architecture/`, `docs/security/`, `docs/development/`.
