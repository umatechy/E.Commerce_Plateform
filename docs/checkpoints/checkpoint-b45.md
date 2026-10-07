============================================================
PHASE B45 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B45 — Starter templates by business category (gap G23, remaining part;
Module 07 §20, §101, §103, §105–106; Module 03 §57–58)

Owner request (2026-10-05): "b45 working start" — next item of the
ordered list, one phase at a time, auditing existing code first.

Starting point:
v1.1 at dea0c5e (B44, CI green). Stores knew what they sell
(business_category) but always started with an empty catalogue.

Implementation Summary:
1. StarterTemplates: 14 platform templates, one per business category —
   categories (with SEO descriptions), attributes and values (colours with
   codes), category attributes with filter/required flags, an attribute
   set, themes in order of preference, the default product order; brands
   for mobiles and electronics, offered only. No products, prices or
   reviews.
2. StarterTemplateService: preview (marked against the store) and apply —
   add only (Module 07 §103), one transaction with the store row locked;
   theme by package entitlements (published before launch, draft after);
   history table starter_template_applications, audit and outbox event.
3. API: GET /starter-templates, GET /starter-templates/{key}, POST
   /starter-templates/{key}/apply (throttled; categories + attributes
   management, plus brands / theme publishing for those options).
4. Store creation (B44 service): an option on sign-up and on Super Admin
   "Create store for a customer" (on by default), inside the provisioning
   transaction.
5. Screens: Categories → "Start from a template" (header and empty state);
   sign-up box; Super Admin create-store box; store page "Starter template".

Audit: reused AttributeManager, CategoryAttributes, attribute sets,
ThemeService/ThemeEntitlements, the catalog.default_sort setting, SeoResolver's
category-description fallback and the B44 provisioning service; product CSV
import (B40) stays the "import existing catalogue" route. Attribute keys that
meant different things in different templates (storage, pieces, gender, age
group, size) were separated so two templates never mix values (checked by a
test).

Tests (2026-10-05):
PHP: 1153 passed (StarterTemplatesTest 5).
PHPStan: no errors.
Vitest: 201 passed (starterTemplates.test.tsx 4; onboarding test updated for
the new box).
ESLint, TypeScript: clean. Production build: passes.

Browser verification — EXECUTED (Chromium, local server, MFA and step-up
on, production build): 7 of 7, no console errors.
1. Platform staff sign in with two-step sign-in.
2. Create a Premium fashion store with the template box (on by default);
   store page: "Starter template: Clothing & fashion".
3. The customer accepts the invitation and turns on two-step sign-in.
4. Categories Women/Men/Kids/Accessories with sub-categories; 7 attributes;
   Boutique theme published (store not live yet).
5. "Start from a template": fashion preselected, "already in your store"
   marks, previous application shown; electronics with brands, theme box
   off → 8 new categories (the existing Accessories kept and extended),
   8 brands, theme unchanged.
6. Phone width 375 px: the dialog fits.
7. Sign-up as a food store with the box on: the store's history shows the
   food template.

Not built (b45-starter-templates.md §6): Urdu names in templates; templates
edited in the Super Admin (marketplace); store cloning; connect external
catalogue; AI suggestions.

CI: run on cdbd1d7 — success, verified 2026-10-05.


------------------------------------------------------------
FOLLOW-UP (2026-10-06) — what B45 had left
------------------------------------------------------------

Owner request (2026-10-06): before B46, finish what was not built, after
auditing the existing code.

Built:
1. Urdu text for every built-in template (StarterTemplateUrdu, one glossary
   of ~380 entries); written as `ur` translations of the items a template
   creates; a test guards coverage.
2. StarterTemplateRegistry + platform_starter_templates: built-in templates
   can be switched off or kept for staff only; Super Admin → Starter
   templates (list, view, edit, delete saved ones).
3. Store to template (Module 03 §52, controlled cloning): Super Admin → a
   store → "Save as a starter template" copies structure only; staff create
   stores from it ("Template" in Create store for a customer); offering it to
   owners is a separate step.

Audit: reused TranslationService (no second translation store), the B45
apply service (one apply path for built-in and saved templates), the B44
provisioning service, and the template rules (StarterTemplates::problems(),
now shared by the test and the capture). The static
StarterTemplates::forBusinessCategory() was replaced by the registry, so
there is one answer to "which template fits this store".

Still waiting on owner inputs (end of the list): connect external catalogue
(provider), AI taxonomy suggestions (Module 26, OpenAI key).

Tests: PHP 1156 passed (StarterTemplatesTest 5, PlatformStarterTemplatesTest
3, StepUpTest list updated); PHPStan no errors; Vitest 204 passed
(platformTemplates.test.tsx 3); ESLint, TypeScript clean; build passes.

Browser verification — EXECUTED: 7 of 7, no console errors (Starter
templates page with 14 built-in; view; save store 65 as a template; staff
only → offered; create a store from it; source and use counted; 375 px).

CI: run on 978023a — success, verified 2026-10-06.

Next (on the owner's word): B46 — tax engine (G3).
