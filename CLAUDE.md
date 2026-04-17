# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A canteen/kantine point-of-sale (POS) system for De Hallebardiers (student organization). Pure PHP + vanilla JS, no build system required.

## Tech Stack

- **Backend**: PHP 8.0+ with PDO
- **Database**: MariaDB/MySQL (database name: `bar`, user: `bar`, credentials in `db.php`)
- **Frontend**: Vanilla JavaScript (ES6), no frameworks

## Development

No build step — deploy PHP files directly to web root. To develop locally:

1. Serve with any PHP-capable web server (Apache, Nginx, or `php -S localhost:8000`)
2. Configure MariaDB credentials in `db.php`
3. Run `install.php` once to create schema + sample data, then remove/rename it

No npm, no Makefile, no containers.

## Architecture

Three user-facing pages, each a standalone PHP view:
- `index.php` — POS/kassa: shift management, tabs, drink orders, payment
- `admin.php` — inventory: drink catalog, pricing per price list
- `rapport.php` — reports: shift history, revenue breakdown by payment method and drink

All business logic lives in `api.php` (switch/case on `action` parameter), which returns `{ok: boolean, ...data}` JSON. `db.php` provides the PDO connection. `css/pos.css` and `js/pos.js` are shared across all three pages.

### Key data model concepts

- **Shift** (`shifts`): one active work session at a time, linked to a price list and responsible person
- **Tab** (`tabs`): a customer bill within a shift; contains multiple line items
- **Price list** (`prijslijsten`): Training vs. Evenement — drinks can have different prices per list
- Cascading deletes maintain referential integrity

### Frontend conventions

- All API calls go through `fetch()` to `api.php`
- Modal pattern: `openModal(id)` / `closeModal(id)` toggle visibility
- XSS protection via `esc()` helper in `pos.js`
- Dutch language for all UI text and comments; function/variable names are English
- Function naming: camelCase English (`loadDrinks`, `showPosScreen`, `closeShift`)

## Authentication & Authorization

Google Workspace OAuth 2.0. All pages require a valid session. Credentials live in `config.php` (not committed with real values).

**Auth flow:** `login.php?google=1` → Google → `oauth_callback.php` → session → redirect. The `hd` claim in the userinfo response is verified against `GOOGLE_WORKSPACE_DOMAIN`. Session stores `['email', 'name', 'role']`.

**Roles** (stored in `user_roles` DB table):
- No role: kassa only
- `read`: kassa + view reports + view price lists
- `write`: everything + delete shifts + modify price lists

**Guards:**
- `requireAuth()` — any authenticated user (used in `index.php`)
- `requireRole('read')` — read or write role (used in `rapport.php`, `admin.php`)
- `requireRole('write')` — write role only (used in `users.php`)
- `api.php` enforces the same role checks server-side for every action

**Setup:** Run `migrate_auth.php` once to create the `user_roles` table, then delete it. Add initial users via `users.php` (requires a write-role user in the DB first — seed manually with `INSERT INTO user_roles (email, role) VALUES ('you@domain.be', 'write')`).
