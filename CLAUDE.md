# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Tech Stack

Laravel 13 (PHP 8.3+) + Vue 3 + Inertia.js + TypeScript + Tailwind CSS 4 + Vite. UI components from Reka UI (shadcn-style). Charts via `vue3-apexcharts`. Auth via Laravel Fortify + Passkeys.

## Commands

```bash
# Start full dev stack (artisan serve + queue:listen + Vite HMR, all concurrent)
composer run dev

# Run full test suite (config:clear → pint check → tsc → phpunit)
composer run test

# Fix PHP code style
composer run lint

# Check PHP code style without writing
composer run lint:check

# Full CI pipeline (npm lint + format check + types + phpunit)
composer run ci:check

# Frontend checks
npm run lint          # ESLint auto-fix
npm run lint:check    # ESLint check only
npm run format        # Prettier write
npm run format:check  # Prettier check only
npm run types:check   # TypeScript type-check
```

There are no JavaScript/frontend tests — only `phpunit` for backend.

## Database

Two SQLite connections:

- **`sqlite` (default)**: `database/database.sqlite` — app data, sessions, cache, queue
- **`fasih` (read-only data)**: `storage/app/fasih.db` — imported census data. This file is uploaded by users at runtime; it may not exist. Always check `file_exists()` before querying this connection.

The `fasih` connection tables (`progress_pengawas`, `progress_pencacah`) use a `snapshot_at` column to distinguish multiple time-based snapshots within a single file.

## Auto-Generated Files — Do Not Edit

These paths are auto-generated and will be overwritten:

- `resources/js/actions/**` — Wayfinder Inertia action types
- `resources/js/routes/**` — Wayfinder route helpers
- `resources/js/components/ui/*` — shadcn/reka-ui component templates

## Code Style

**PHP**: Pint with `laravel` preset. Run `composer run lint` to fix.

**TypeScript/Vue**: Strict mode enabled. Key Prettier settings: `tabWidth: 4`, `singleQuote: true`, `semi: true`, `printWidth: 80`. Import type syntax: `import type { ... }` (separate-type style). ESLint uses flat config (v9+).

Prettier ignores `resources/js/components/ui/*` and `resources/views/mail/*`.

## Key Architecture Notes

- **Inertia.js**: Backend renders pages via `Inertia::render()`. Data flows as props — no REST API except `/api/data` and `/api/snapshots` used internally by the dashboard.
- **Large uploads**: `composer run dev` sets `upload_max_filesize=512M` so users can upload large `fasih.db` files. Don't reduce this.
- **Queue**: `queue:listen` runs in dev (part of `composer run dev`). Jobs use the `database` driver.
- **`.npmrc`**: `ignore-scripts=true` is intentional — disables postinstall hooks.
