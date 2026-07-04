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

The target URL lives in **one place** — `tests/e2e/support/env.ts` (mirrored by
`use.baseURL` in `playwright.config.ts`). Specs import `BASE_URL` /
`tenantBaseURL` from it; none of them hardcode a host or port. The default is
`http://localhost` (the app's in-container port 80), so **inside Sail you set
nothing**:

```bash
# Inside the Sail container (default target http://localhost)
./vendor/bin/sail npm run test:e2e
./vendor/bin/sail npm run test:e2e:headed   # browser visible (debug)
./vendor/bin/sail npm run test:e2e:ui       # interactive UI mode
```

When running Playwright **from the host** (outside the container, e.g. host
Node), point it at the remapped host port instead:

```bash
PLAYWRIGHT_BASE_URL=http://localhost:8080 npx playwright test
```

`tenantBaseURL('slug')` derives tenant subdomains from the same base, so it
follows whichever port the base URL uses automatically.

## Port notes

- Laravel app is exposed on host port **8080** (Sail remaps from container port 80)
- Vite dev server is on port **5174** (inside container), proxied through Laravel
- In-container (the default for `sail npm run test:e2e`) the app is at port **80**, so no override is needed
- Set `PLAYWRIGHT_BASE_URL=http://localhost:8080` **only** when running Playwright from the host machine

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
