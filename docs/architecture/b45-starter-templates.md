# B45 — Starter templates by business category

Gap **G23** (the remaining part after B44), owner decision 13. A new store can
start from a ready-made structure for what it sells instead of an empty
catalogue.

Specs read before building: Module 07 §20 (category templates: default
attributes, filters, product fields, suggested brands, SEO defaults, display
layout), §101 (template marketplace foundation: "importing a template must
create tenant-owned copies"), §103 (AI/taxonomy safety: no automatic deletes,
merges or re-typing), §105 (store setup: start empty / industry template /
import existing catalogue / connect external catalogue), §106 (industry
templates, "platform-managed but copied into the tenant's own data"); Module
03 §52 (store template / cloning — not this feature, see §6), §57–58
(onboarding UX, package-aware onboarding); Module 01 §8 (theme categories such
as Fashion, Food, Technology); Module 17 §18–19 (theme selection, draft and
publish).

## 1. Audit first — what was reused

| Need | Existing code | Decision |
|---|---|---|
| What a store sells | `stores.business_category`, `BusinessCategories` (B44) | the template key **is** the business category key; 14 templates, one each |
| Categories | `Category` (slug convention name + 6 random characters) | created the same way; matched by name under the same parent |
| Attributes and values | `Attribute`, `AttributeManager::syncValues()` (validation, slugs, colour codes, in-use rules) | used as is; existing attributes get missing values through the same method |
| Which attributes a category uses, filters, required | `CategoryAttributes::set()` / `own()`; children inherit the parent's list | used as is; only top-level categories carry a list |
| Attribute sets | `AttributeManager::saveSet()` | one set per template, named after it |
| Brands | `Brand` | created only on request |
| Theme | `ThemeCatalog`, `ThemeEntitlements::allows()`, `ThemeService::selectTheme()` / `publish()` | the template lists themes in order of preference; the first the package includes is used |
| Default product order | setting `catalog.default_sort` (B43) | set only when the store has not chosen its own |
| SEO description | `SeoResolver` falls back to the category description | each top-level category has a description, so its page has a meta description from the start |
| Import existing catalogue (§105 option 3) | product CSV import (B40; attributes column B42) | not duplicated; the Categories page keeps it where it is |
| Store creation | `StoreProvisioningService` (B44) | an option on both creation models |

Nothing was copied from the demo store command: its catalogue is demo data
with products, which a template deliberately does not contain.

## 2. The templates

`App\Domain\Catalog\Support\StarterTemplates` — platform data in code, like
`ThemeCatalog` (reviewed in pull requests, versioned per template, no table
to drift). Each template has:

- a name, a version and a one-line summary;
- 4–8 top-level categories (some with sub-categories), each with a short
  description;
- 3–7 attributes with values (colours with their colour codes) — sizes,
  colours, fabric, storage, RAM, PTA approval, scent family, metal, age,
  weight and so on;
- per category: which attributes apply, which are filters, which are required
  (e.g. "Condition" on mobile phones);
- themes in order of preference (e.g. fashion: Boutique → Modern → Classic);
- the default product order (newest, or best selling for grocery, beauty,
  food, fragrance, kids, sports);
- brands only for mobiles and electronics (Samsung, Apple, Xiaomi, Infinix,
  Tecno, Oppo, Vivo, Realme), offered, never added without asking.

Rules checked by a test for every template: an attribute key means one type
in all templates (so two templates in one store never mix "Room temperature"
into a storage list — the grocery attribute is `keep`, not `storage`), keys and
category names are unique, every category attribute is defined, colour values
have a code, themes exist, the order is a valid one, limits (200 values, 40
attributes per category) are respected.

**What a template never contains:** products, prices, stock, reviews,
policies or claims. A new store must not look as if it had real things for
sale.

## 3. Applying: add only

`StarterTemplateService::apply()` in one transaction with the store row
locked (two clicks cannot create a category twice):

| Already in the store | What happens |
|---|---|
| a category with the same name (any case) under the same parent | kept as it is (name, description, status, visibility); its sub-categories are matched the same way |
| an attribute with the same key and type | kept; missing values are added after the store's own |
| an attribute with the same key and another type | kept untouched and reported ("kept your own") |
| a category's own attribute list | missing attributes are added at the end; the store's flags stay |
| a brand with the same name | kept |
| the store chose its own default order | kept |

Nothing is ever deleted, renamed, merged or re-typed (Module 07 §103).
Applying the same template again adds nothing.

**Theme:** the first theme of the template that the package includes (Basic:
Classic/Minimal; Business adds Modern; Premium all). A store that is not live
yet gets it published at once; a live store gets it in its theme draft and the
owner publishes it when ready (Module 17 §19). If the theme cannot be used
(e.g. the package changed), the theme is left as it is and the rest still
applies.

Each application writes a history row (`starter_template_applications`: key,
version, who, summary of what was added), an audit entry
`catalog.starter_template_applied` and an outbox event with the same name.

## 4. Where it is offered

| Place | Behaviour |
|---|---|
| Sign-up | after "What will you sell?", a box "Set up categories and filters for what I sell" (on by default); off = start empty |
| Super Admin → Create store for a customer | "Set it up from the starter template" (on by default) |
| Admin → Categories | "Start from a template" (header and empty state): choose a template (the store's own is preselected and marked), preview categories, filters and attributes with "already in your store" / "yours is kept" marks, options for theme, brands and default order, apply; a message says what was added |
| Super Admin → store page | "Starter template: …" or "none — started empty" |

At store creation the template runs inside the provisioning transaction (a
store is never left half set up) with theme and default order, without
brands.

## 5. Permissions

| Action | Needs |
|---|---|
| see templates / preview | `categories.manage` or `attributes.manage` |
| apply | `categories.manage` **and** `attributes.manage` |
| … with brands | also `brands.manage` |
| … with the theme | also `theme.publish` |

A template never does more than the person could do by hand. At store
creation the actor is the new owner (self-service) or the platform staff
member (assisted), as for every other creation step.

## 6. Follow-up (2026-10-06): Urdu, platform-managed templates, store to template

Built after the first B45 delivery, on the owner's request to finish what
was left.

### 6.1 Urdu text (Module 07 §38, Module 31)

`StarterTemplateUrdu::TEXT` is one glossary keyed by the English text (about
380 entries): every category name and description, attribute name and worded
value of the built-in templates. One entry serves every template that uses
the word, so nothing is translated twice. Plain sizes and numbers (XS, 128 GB,
UK 7) read the same and are not listed. A test fails if a built-in template
gains text without Urdu.

When a template **creates** a category, attribute or value, its Urdu goes into
`content_translations` (`ur`) through `TranslationService` — the same place
the owner's own translations live, so a store offering Urdu shows it at once
and the owner can change it under Translate. Items the store already had are
never touched.

### 6.2 Templates managed by Umar Techy (Module 07 §101)

`StarterTemplateRegistry` gives one list: the built-in templates (code) and
the templates saved from stores (`platform_starter_templates`, platform data,
no store scope). Per template staff decide:

| Switch | Effect |
|---|---|
| On / Off | Off: nobody can start from it (sign-up, staff, owners); stores that used it keep what it added |
| Offered to store owners | Off: only staff use it when creating stores |

Built-in content stays in code (a reviewed change with a version bump);
templates saved from a store can also be renamed, given a summary and a
business category, or deleted. Super Admin → **Starter templates** lists all
of them with source, contents, status and how many stores used each.

### 6.3 Store to template — the controlled form of cloning (Module 03 §52)

Super Admin → a store → **Save as a starter template** copies the store's
**structure**: categories (two levels; deeper ones are counted and reported),
descriptions, active attributes and active values (colours with their codes,
or none if the store had none), the top-level categories' filters and required
fields, brand names, the published theme's key, the default order, and the
Urdu text of all of these.

Never copied (§52): products, prices, stock, orders, customers, reviews, team,
pictures and logos, pages and policies, domains, payment and shipping
settings, credentials or other settings. A new store made from the template
goes through the normal creation path, so its ids, package, subscription and
domains are its own.

A saved template starts **staff only** (it may carry the source store's
names); staff choose it in "Create store for a customer" (Template), or offer
it to owners.

| Who | Can |
|---|---|
| Platform staff (step-up) | save from a store, switch on/off, offer, rename, delete |
| Store staff | see and apply only templates that are on and offered |

## 7. Still not built

- "Connect external catalogue" (§105 option 4): needs a provider and its
  credentials — an owner input, kept for the end of the list.
- AI suggestions for categories and attributes (§102): Module 26 must first
  define features, provider/model, privacy, entitlements, limits, costs and
  audit (owner decision 7), and needs the OpenAI key — kept for the end.
- Editing the content of a template (categories, attributes) in the Super
  Admin: change the source store and save it again, or change the code.
