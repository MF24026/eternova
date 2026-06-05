# CI / CD Reference

## Workflows overview

| File | Trigger | Purpose |
|------|---------|---------|
| `.github/workflows/ci.yml` | PR against `develop` or `main`; push to `develop`; manual | Full check suite: style, types, lint, PHPUnit, prod build, Scribe |
| `.github/workflows/e2e.yml` | Push to `develop` or `main`; manual | Playwright browser tests against a live Laravel + Vite server |

### Why E2E is not on every PR

Playwright spins up real browsers and is significantly slower and more resource-intensive than the unit/feature suite. The agreed doctrine:

1. Dev runs Playwright locally before opening the PR (`npm run test:e2e`).
2. The qa-engineer agent validates visually with Playwright MCP (screenshots desktop + mobile + dark mode).
3. E2E runs automatically on the merge commit to `develop` / `main` to catch integration regressions.

This keeps PR feedback fast while still gating the merge commit on browser tests.

---

## How to run CI checks locally

These commands mirror what the CI workflow runs. Run them before pushing to catch failures early.

```bash
# 1. Code style (Pint) -- must pass with zero violations
./vendor/bin/sail composer pint -- --test

# 2. TypeScript strict-mode type check
./vendor/bin/sail npm run type-check

# 3. Lint (currently a no-op echo, exits 0)
./vendor/bin/sail npm run lint

# 4. Full PHPUnit/Pest suite
./vendor/bin/sail artisan test

# 5. Verify production asset bundle compiles
./vendor/bin/sail npm run build

# 6. Verify Scribe API docs still generate cleanly
./vendor/bin/sail artisan scribe:generate --no-interaction
```

To run migrations fresh against the testing config locally (mirrors CI step 11):

```bash
./vendor/bin/sail artisan migrate:fresh --seed --env=testing
```

---

## How to run E2E locally

```bash
# 1. Sail up (MySQL + Redis + PHP)
./vendor/bin/sail up -d

# 2. Migrate + seed
./vendor/bin/sail artisan migrate:fresh --seed

# 3. Start Vite dev server (keep running in a separate terminal)
./vendor/bin/sail npm run dev

# 4. Run all Playwright tests (headless)
./vendor/bin/sail npm run test:e2e

# 5. Run Playwright in interactive UI mode (good for debugging)
./vendor/bin/sail npm run test:e2e:ui

# 6. Run Playwright with a visible browser (good for visual inspection)
./vendor/bin/sail npm run test:e2e:headed

# 7. View the last HTML report
./vendor/bin/sail npm run test:e2e:report
```

Playwright tests live under `tests/` following the spec naming convention in the project.
The HTML report is written to `tests/e2e-report/`.

---

## Required branch protection settings

> **These settings must be applied by the repo owner (`MF24026`) from the GitHub UI.**
> Collaborators (including `HA23039`) cannot write branch protection rules via the API.
>
> Path: **GitHub repo > Settings > Branches > Add branch protection rule**
> Apply the rule twice: once for `main`, once for `develop`.

### `main` branch

| Setting | Value |
|---------|-------|
| Branch name pattern | `main` |
| Require a pull request before merging | Enabled |
| Required approving reviews | 1 |
| Dismiss stale pull request approvals when new commits are pushed | Enabled |
| Require status checks to pass before merging | Enabled |
| Required status checks | `Tests & Checks` (job name in `ci.yml`) |
| Require branches to be up to date before merging | Enabled |
| Block force pushes | Enabled |
| Block deletions | Enabled |

### `develop` branch

| Setting | Value |
|---------|-------|
| Branch name pattern | `develop` |
| Require a pull request before merging | Optional (team preference) |
| Required approving reviews | 0 (move fast, checks gate quality) |
| Require status checks to pass before merging | Enabled |
| Required status checks | `Tests & Checks` (job name in `ci.yml`) |
| Require branches to be up to date before merging | Enabled |
| Block force pushes | Enabled |
| Block deletions | Enabled |

### Step-by-step for the owner

1. Go to `https://github.com/MF24026/eternova/settings/branches`.
2. Click **Add branch protection rule**.
3. Set **Branch name pattern** to `main`.
4. Tick the boxes listed in the `main` table above.
5. Click **Create** (or **Save changes**).
6. Click **Add branch protection rule** again.
7. Set **Branch name pattern** to `develop`.
8. Tick the boxes listed in the `develop` table above.
9. Click **Create**.

The status check name to enter is exactly: `Tests & Checks` (matches the `name:` field of the `tests` job in `ci.yml`).

---

## CI status badge

Add the following line to the top of `README.md` to display the live CI status:

```markdown
![CI](https://github.com/MF24026/eternova/actions/workflows/ci.yml/badge.svg?branch=develop)
```
