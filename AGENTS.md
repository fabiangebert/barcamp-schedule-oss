# Agent Guidelines for Barcamp Schedule

This document provides guidelines for AI agents working on the Barcamp Schedule project, a Vue 3 + Slim Framework application.

## Code Style & Architectural Guidelines

- **Clean Controllers / Fat Models & Services**: Route closures in `/src/Routes/` must remain extremely thin. All heavy lifting, validation, file operations, and external system integrations must be extracted into dedicated service classes (e.g., `App\Services\UploadService`).
- **All comments in English**: All code comments and documentation headers must be written in English. Do not write German comments in the codebase.
- **Strict Typing & Conventions**: Adhere strictly to existing naming conventions and types. Avoid cast overrides or unverified libraries.

## Uploads & Security (finfo Validation)
- Any uploaded file must undergo dual-layer validation before it is moved to persistent storage:
  1. **Extension Check**: Accept only `jpg`, `jpeg`, `png`, `webp`, and `pdf` (case-insensitive).
  2. **Content-Based MIME-Type Check**: Inspect actual file bytes using PHP's OO `finfo` API (`new \finfo(FILEINFO_MIME_TYPE)`). Acceptable MIME types are strictly limited to `image/jpeg`, `image/png`, `image/webp`, and `application/pdf`.
- All uploads must go through `App\Services\UploadService` to ensure consistent security and separation of concerns.

## Internationalization (i18n)
- The frontend supports both German and English.
- Translation files are stored in `frontend/src/locales/de.json` and `frontend/src/locales/en.json`.
- If you introduce new visual text, always define corresponding i18n keys and access them via `t('key')` in the frontend code.

## Project Structure

- **Frontend**: Vue 3 in `/frontend` directory
- **Backend**: Slim Framework (PHP) in `/src` directory
- **Database**: SQLite with migrations in `/migrations` directory
- **Tests**: PHPUnit tests in `/tests` and Vitest tests in `/frontend/tests`

## Tooling & Quality Assurance

- **PHP**: PHP 8.1+ with Composer
- **Node.js**: 18+ with npm
- **Linting**:
  - Backend: `composer lint:style` (PHP_CodeSniffer with PSR-12) and `composer lint:logic` (PHPStan). Run both with `composer lint`.
  - Frontend: `npm run lint` (ESLint and Prettier rules).
- **Testing**:
  - Backend: `composer test` (runs PHPUnit tests)
  - Frontend (Unit/Integration): `npm run test` (runs Vitest tests)
  - Frontend (Browser E2E): `npm run test:e2e` inside the `frontend/` directory (runs Playwright tests)

## JSON Schema Generation (Schedule Data Validation)

The backend validates schedule data against a JSON Schema that is **auto-generated from the frontend TypeScript types** to prevent drift.

### Source of truth
- `frontend/src/types/schedule.ts` — defines `ScheduleData`, `Room`, `Timeslot`, `Session`, `Topic`, `SizeCategory`.

### Regeneration
- Run from anywhere: `composer generate:schema`
- This delegates to: `cd frontend && npm run generate:schema`
- Which runs: `ts-json-schema-generator --path 'src/types/schedule.ts' --type 'ScheduleData' --expose all`
- Output: `backend/resources/schedule.schema.json`

### When to regenerate
- **Any time** `frontend/src/types/schedule.ts` is modified (new fields, changed types, required/optional changes).
- The `npm run build` script auto-regenerates before building, so CI builds also catch changes.
- A PHPUnit test (`ScheduleSchemaUpToDateTest`) detects drift at test time — if it fails, run `composer generate:schema`.

### Validation flow
1. Frontend TypeScript types → `ts-json-schema-generator` → JSON Schema (committed)
2. `POST /schedules` and `PATCH /schedules/{id}` call `ScheduleValidationService::validate()`
3. The service loads the JSON Schema and validates the incoming data via `opis/json-schema`
4. Invalid data returns `400` with `{error, details}` where `details` is an array of field-level messages (e.g. `"data.rooms.0: missing required name"`)

## Automated Integration / E2E Simulation Tests

To ensure robust correctness, the project implements extensive automated integration and browser-based E2E tests:

1. **Schedule Editor**:
   - `tests/SchedulePatchRouteTest.php` and `tests/ScheduleTest.php` verify schedule updates, validation, version conflict resolution, and editor permissions.
   - `frontend/e2e/editor.spec.js` (Playwright) performs full browser-based end-to-end interactions: logging in, entering Edit Mode, editing session titles, renaming room names/timeslots, simulating cell drag-and-drop, saving edits, and reloading the page to confirm database persistence.
2. **Schedule Viewer & Share Links**:
   - `tests/ShareLinkTest.php` and `tests/ShareLinkRoutesCoverageTest.php` verify viewer access, read-only constraints, and share link token expiration.
3. **Event Feedback**:
   - `tests/EventFeedbackTest.php` and `tests/LLMExportRatingTest.php` verify that global event and session feedback (ratings, reviews) can be successfully submitted, stored, and exported.
4. **Documentation**:
   - `tests/DocumentationTest.php`, `tests/DocumentationCoverageTest.php`, `tests/DocumentationDeleteTest.php` and `tests/UploadTest.php` cover adding, querying, and deleting documentation.
   - `tests/FileUploadAutomationTest.php` acts as an automated simulation of the manual QA plan for uploads and document attachments, ensuring spoofed files are securely rejected.
5. **Session Expiration & Hard-Cap Management**:
   - `tests/SessionAutomationTest.php` automates the complex validation scenarios of sliding user sessions (JWT auto-renewal) and hard guest-session limits.
6. **Playwright Browser E2E Lifecycle**:
   - Running `npm run test:e2e` inside `/frontend` delegates orchestration to `run-e2e.js`. It copies and backs up any existing `database.sqlite` database, seeds a clean E2E test schedule via `tests/seed_e2e.php`, spins up both the PHP and Vite local webservers, executes the Playwright test suite, and fully restores the original local database on completion.

## Implementation Checklist

Before completing any task, verify the following:

- [ ] Database migrations are up to date.
- [ ] JSON Schema is regenerated if `frontend/src/types/schedule.ts` was changed (`composer generate:schema`).
- [ ] All imports are correct and relative paths work.
- [ ] New functionality has corresponding tests.
- [ ] Existing tests still pass (`composer test` and frontend `npm run test`).
- [ ] PHP_CodeSniffer and PHPStan checks pass for backend (`composer lint`).
- [ ] ESLint/Prettier checks pass for frontend (`npm run lint`).
- [ ] Code follows existing style and patterns.
- [ ] I18n strings are updated if necessary.
