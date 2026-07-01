# Notifications Bell Dropdown — Design Spec

**Date:** 2026-07-01
**Branch:** `feature/notifications-bell-dropdown`
**Author:** tech lead (Erick)

## Goal

Wire the decorative bell button in the admin topbar to the notifications API that
already exists. Deliver a bell dropdown with a live unread badge, a list of recent
notifications, and mark-as-read (single + all). No backend changes — this is a
frontend-only feature consuming `/api/v1/notifications`.

## Context / current state

- **Backend is complete.** `App\Modules\Inventory\Controllers\NotificationController`
  exposes, under `auth:sanctum` + `tenant` middleware:
  - `GET /api/v1/notifications/unread-count` → `{ data: { count }, meta }`
  - `GET /api/v1/notifications?unread_only=&per_page=` → paginated `NotificationCollection`
  - `PATCH /api/v1/notifications/{id}/read` → `NotificationResource`
  - `PATCH /api/v1/notifications/read-all` → `{ data: { marked_read }, meta }`
  - Every query filters by `notifiable_id = user` AND `tenant_id = current`.
- `NotificationResource` shape (per item):
  ```json
  {
    "id": "uuid",
    "type": "inventory.low_stock",
    "payload": { ...heterogeneous per type... },
    "read_at": "ISO8601 | null",
    "created_at": "ISO8601"
  }
  ```
  Note: the display fields live inside `payload`, NOT as `data` (the `data` key
  collides with the JsonResource envelope wrap key — this is deliberate).
- Existing coverage: `tests/Feature/Inventory/NotificationApiTest.php` and
  `tests/Feature/Billing/BillingNotificationsTest.php`. **No new PHPUnit needed** —
  the API contract is already tested.
- The bell in `resources/js/components/layout/AdminLayout.vue` (~line 295) is
  decorative: no click handler, and `admin-topbar-dot` is hardcoded always-on.
- Frontend conventions: axios `api` instance in `resources/js/services/api.ts`
  (baseURL `/api/v1`, `withCredentials`, CSRF cookie interceptor); Pinia stores in
  `resources/js/stores/`; domain types in `resources/js/types/domain/`.

## Scope

**In:** bell dropdown, live unread badge, list of recent (~15), mark-read single,
mark-all-read, 60s polling of unread-count (paused when tab hidden).

**Out (confirmed):** dedicated `/admin/notifications` page, per-type filters, wiring
the Settings notification toggles to actually suppress sends, realtime websockets.

## The one piece with real logic: the presenter

Notification `payload` shapes are heterogeneous and carry no title/body. A pure
presenter maps `type` + `payload` → `{ icon, title, body }` for display.

| `type`                    | payload fields                                  | icon (Lucide)  | title (es)                  | body (es)                                                            |
|---------------------------|-------------------------------------------------|----------------|-----------------------------|---------------------------------------------------------------------|
| `inventory.low_stock`     | `product_name, sku, branch_name, available, threshold` | `PackageX`     | `Stock bajo`                | `{product_name} ({sku}) en {branch_name}: {available} disponibles`  |
| `billing.trial_ending`    | `days_left`                                     | `Clock`        | `Tu prueba termina pronto`  | `Quedan {days_left} dias de prueba`                                 |
| `billing.invoice_ready`   | `number, total_cents, currency`                 | `FileText`     | `Factura lista`             | `Factura {number} — {money(total_cents, currency)}`                 |
| `billing.charge_failed`   | `subscription_id`                               | `AlertCircle`  | `Cobro fallido`             | `No pudimos procesar tu pago. Revisa tu metodo de pago.`            |
| `billing.suspended`       | `subscription_id`                               | `Ban`          | `Suscripcion suspendida`    | `Tu suscripcion fue suspendida por falta de pago.`                  |
| _(unknown fallback)_      | any                                             | `Bell`         | `Notificacion`              | `""` (empty body)                                                   |

- `money(cents, currency)` formats integer cents to a display string. Reuse the
  existing frontend money helper if present (search `formatMoney`/`formatCurrency`
  in `resources/js`); otherwise `new Intl.NumberFormat` with the currency. The
  presenter must NEVER throw on a missing payload field — coalesce to safe defaults.
- The presenter is pure and unit-testable (no Vue, no network).

## New files

1. `resources/js/types/domain/Notifications.ts` — `NotificationType` union of the 5
   known strings plus `string` widening; `AppNotification { id, type, payload:
   Record<string, unknown>, read_at: string | null, created_at: string }`;
   `PresentedNotification { icon, title, body }`.
2. `resources/js/services/NotificationsService.ts` — thin axios wrappers:
   `getUnreadCount(): Promise<number>`, `list(unreadOnly?, perPage?):
   Promise<AppNotification[]>`, `markRead(id): Promise<void>`, `markAllRead():
   Promise<void>`. Unwrap `data.count` / `data` / etc. per the API envelope.
3. `resources/js/stores/notifications.ts` — Pinia store: state `unreadCount`,
   `items`, `loading`, `open`; actions `fetchUnreadCount`, `fetchList`,
   `markRead(id)` (optimistic: set `read_at`, decrement count), `markAllRead`
   (optimistic), `toggle`/`close`; `startPolling()` (setInterval 60s on
   unread-count + `visibilitychange` pause) and `stopPolling()`.
4. `resources/js/components/layout/notificationPresenter.ts` — the pure presenter
   above.
5. `resources/js/components/layout/NotificationsDropdown.vue` — glass floating panel
   anchored to the bell. Header with title + "Marcar todas". Body: skeleton while
   `loading`, empty state ("No tienes notificaciones") when empty, else the list.
   Each row: presenter icon, title, body, relative time, unread blue dot; click →
   `markRead`. Close on click-outside + `Escape`. No emojis; Lucide icons only.

## Modified files

- `resources/js/components/layout/AdminLayout.vue` — import the store + dropdown;
  `startPolling` on mount / `stopPolling` on unmount; bell `@click` toggles the
  store; replace hardcoded `admin-topbar-dot` with a real badge bound to
  `unreadCount` (hidden when 0, "9+" when >9); render `<NotificationsDropdown>`.

## Data flow

1. AdminLayout mounts → `store.fetchUnreadCount()` + `store.startPolling()`.
2. Click bell → `store.toggle()`; on open → `store.fetchList()`.
3. Click a row → optimistic `markRead(id)` → PATCH; on failure, refetch to resync.
4. "Marcar todas" → optimistic `markAllRead()` → PATCH read-all.
5. Tab hidden → interval paused; tab visible → immediate `fetchUnreadCount` + resume.

## Error handling

- Service calls that 401 already redirect to login via the global api interceptor;
  do not special-case.
- On non-401 failure of `fetchList`/`fetchUnreadCount`, keep last-known state, set
  `loading=false`, and surface a toast via the existing `useToast` composable.
- Optimistic mutations roll back by refetching the list + count on PATCH failure.

## Testing (dual-layer)

- **PHPUnit:** none new. API is already covered by `NotificationApiTest`. (If a gap
  in the resource shape surfaces during implementation, add the assertion there.)
- **No JS unit runner exists** (only Playwright; precedent: the POS IVA `computeTax.ts`
  mirror was verified via e2e + review, not a JS unit runner). Do NOT add vitest — the
  presenter is verified through the e2e rendering and code review. The presenter must
  still be a pure, standalone module so it stays trivially reviewable.
- **Playwright e2e — `tests/e2e/admin/notifications.spec.ts`:**
  - Seed unread notifications for the acting user in the current tenant (via an
    authenticated API/artisan seed on the tenant subdomain, mirroring the pattern in
    `tests/e2e/admin/pos/pos-tax.spec.ts`). Seed at least TWO different types (e.g.
    `inventory.low_stock` + `billing.invoice_ready`) so the presenter mapping is
    exercised for more than one branch.
  - Login → badge shows `2`.
  - Open panel → both rows visible with their presented titles (assert the exact
    Spanish titles from the presenter table, e.g. "Stock bajo", "Factura lista").
  - Click a row → its unread dot clears and the badge decrements to `1`.
  - "Marcar todas" → badge drops to `0`, badge hidden, rows show as read.
  - Reopen after mark-all → empty-of-unread state reflected.
  - Run desktop + mobile viewport.
  - Use stable `data-testid`s: `notif-bell`, `notif-badge`, `notif-panel`,
    `notif-item`, `notif-mark-all`, `notif-empty`.

## Global constraints

- No emojis anywhere (code, UI, comments, commits). Lucide icons only.
- No `Co-Authored-By` / AI trailer in commits.
- Feature branch → PR against `develop` (never main).
- SPA is vue-router + axios services, NOT Inertia — no Inertia imports.
- Money is integer cents; never format by dividing floats loosely — use the money helper.
- Dark mode must work (the panel uses design tokens, not hardcoded colors).
- Run `npm run build` before e2e (Playwright hits the compiled bundle).
