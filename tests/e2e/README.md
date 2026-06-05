# E2E Tests (Playwright)

## Prerequisites

The dev server must be running before executing E2E tests:

```bash
# Inside Sail (starts Vite on port 5174 inside the container)
./vendor/bin/sail npm run dev &

# The Laravel app must also be up
./vendor/bin/sail up -d
```

## Running tests

```bash
# Headless (CI mode) - run from host, targets port 8080
PLAYWRIGHT_BASE_URL=http://localhost:8080 ./vendor/bin/sail npm run test:e2e

# With browser visible (debug)
PLAYWRIGHT_BASE_URL=http://localhost:8080 ./vendor/bin/sail npm run test:e2e:headed

# Interactive UI mode
PLAYWRIGHT_BASE_URL=http://localhost:8080 ./vendor/bin/sail npm run test:e2e:ui
```

## Port notes

- Laravel app is exposed on host port **8080** (Sail remaps from container port 80)
- Vite dev server is on port **5174** (inside container), proxied through Laravel
- `PLAYWRIGHT_BASE_URL` should point to **8080** when running from the host machine

## Test structure

```
tests/e2e/
  auth/
    login.spec.ts    # Login flow: valid creds, invalid creds, logout
    signup.spec.ts   # Registration: success, weak password rejection
  spa/
    routing.spec.ts  # Vue Router: home page, auth guard, 404 page
  smoke.spec.ts      # Legacy smoke tests (Sprint 0 baseline)
```

Each auth spec creates its own user via `POST /api/v1/auth/register` in `beforeEach`
style setup, using a timestamp+random suffix to avoid email collisions across runs.
