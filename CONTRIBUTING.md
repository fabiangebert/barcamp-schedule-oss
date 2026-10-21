# Contributing

## Development Setup

### Prerequisites
- PHP 8.4.27+ (minimum patched release for CVE-2026-109618/109619)
- Node.js 18+
- Composer
- SQLite (for local development and tests)

### Quick Start

```bash
# Backend
composer install
composer test

# Frontend
cd frontend
npm install
npm run test
```

### Code Style
- **PHP**: PSR-12 (`composer lint:style`) + PHPStan level 5 (`composer lint:logic`)
- **Frontend**: ESLint + Prettier (`npm run lint`)

### Testing
- Backend: `composer test` (PHPUnit on SQLite)
- Frontend unit: `npm run test` (Vitest)
- Frontend E2E: `npm run test:e2e` (Playwright via `run-e2e.js`)

## Pull Request Process
1. Ensure all tests pass and lint is clean.
2. Add tests for new functionality.
3. Update i18n strings in `frontend/src/locales/` if adding new UI text.
4. Update documentation (README, DEPLOYMENT) if changing setup or deployment.
