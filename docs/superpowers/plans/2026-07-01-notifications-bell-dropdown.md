# Notifications Bell Dropdown Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the decorative admin topbar bell to the existing `/api/v1/notifications` API with a dropdown panel, live unread badge, and mark-as-read (single + all).

**Architecture:** Frontend-only. A thin axios service wraps the 4 existing endpoints; a Pinia store holds unread count + list and runs 60s polling of the count (paused when the tab is hidden); a pure presenter maps each notification `type`+`payload` to `{ icon, title, body }`; a glass dropdown component renders the list; AdminLayout mounts the store, cables the bell, and shows a real badge.

**Tech Stack:** Vue 3 `<script setup>` + TypeScript, Pinia, axios (`@/services/api`), lucide-vue-next icons, Playwright e2e. NO Inertia. No backend changes.

## Global Constraints

- No emojis anywhere (code, UI, comments, commits). Lucide icons only.
- No `Co-Authored-By` / AI trailer in commits. Commit messages in English, conventional format.
- Feature branch `feature/notifications-bell-dropdown` → PR against `develop` (never main).
- SPA is vue-router + axios services, NOT Inertia — no Inertia imports.
- Money is integer cents; format via `Intl.NumberFormat`, never loose float math.
- Dark mode must work: use design tokens / existing utility classes, no hardcoded hex.
- No JS unit runner exists (only Playwright). Do NOT add vitest. Per-task verification is `npx tsc --noEmit` (type-check) and `npm run build`; behavior is proven by the e2e in Task 5. Run `npm run build` before e2e (Playwright hits the compiled bundle).
- The notifications API is served under `auth:sanctum` + `tenant` middleware; the axios `api` instance already handles CSRF + 401 redirect. Do not special-case 401.
- Response envelopes: `GET /unread-count` → `{ data: { count }, meta }`; `GET /` (index) → paginated collection with items under `data` (BaseCollection); `PATCH /read-all` → `{ data: { marked_read }, meta }`; `PATCH /{id}/read` → a single `NotificationResource` under `data`.
- Notification item shape (`NotificationResource`): `{ id, type, payload, read_at, created_at }`. Display fields live inside `payload` (NOT `data`).

---

### Task 1: Domain types + axios service

**Files:**
- Create: `resources/js/types/domain/Notifications.ts`
- Create: `resources/js/services/NotificationsService.ts`

**Interfaces:**
- Consumes: the axios `api` default export from `@/services/api`.
- Produces:
  - `AppNotification { id: string; type: string; payload: Record<string, unknown>; read_at: string | null; created_at: string }`
  - `PresentedNotification { icon: unknown; title: string; body: string }`
  - `NotificationsService` object with: `getUnreadCount(): Promise<number>`, `list(unreadOnly?: boolean, perPage?: number): Promise<AppNotification[]>`, `markRead(id: string): Promise<void>`, `markAllRead(): Promise<void>`.

- [ ] **Step 1: Create the types file**

`resources/js/types/domain/Notifications.ts`:

```ts
/**
 * A notification row as returned by GET /api/v1/notifications.
 * Display fields live inside `payload` (heterogeneous per `type`); the
 * presenter (notificationPresenter.ts) turns them into title/body/icon.
 */
export interface AppNotification {
    id: string
    type: string
    payload: Record<string, unknown>
    read_at: string | null
    created_at: string
}

/** The display shape produced by the presenter for a given notification. */
export interface PresentedNotification {
    /** A lucide-vue-next component. Typed `unknown` to match the AdminLayout NavItem convention. */
    icon: unknown
    title: string
    body: string
}
```

- [ ] **Step 2: Create the service**

`resources/js/services/NotificationsService.ts`:

```ts
import api from '@/services/api'
import type { AppNotification } from '@/types/domain/Notifications'

/**
 * Thin wrappers over the notifications API. Every call is scoped server-side to
 * the authenticated user + current tenant; the axios instance handles CSRF + 401.
 */
export const NotificationsService = {
    async getUnreadCount(): Promise<number> {
        const { data } = await api.get('/notifications/unread-count')
        return Number(data?.data?.count ?? 0)
    },

    async list(unreadOnly = false, perPage = 15): Promise<AppNotification[]> {
        const { data } = await api.get('/notifications', {
            params: { unread_only: unreadOnly ? 'true' : undefined, per_page: perPage },
        })
        return (data?.data ?? []) as AppNotification[]
    },

    async markRead(id: string): Promise<void> {
        await api.patch(`/notifications/${id}/read`)
    },

    async markAllRead(): Promise<void> {
        await api.patch('/notifications/read-all')
    },
}
```

- [ ] **Step 3: Type-check**

Run: `./vendor/bin/sail npm run build`
Expected: build succeeds (no TS errors for the two new files).

- [ ] **Step 4: Commit**

```bash
git add resources/js/types/domain/Notifications.ts resources/js/services/NotificationsService.ts
git commit -m "feat(notifications): frontend types and API service"
```

---

### Task 2: Pure presenter (type + payload -> icon/title/body)

**Files:**
- Create: `resources/js/components/layout/notificationPresenter.ts`

**Interfaces:**
- Consumes: `AppNotification`, `PresentedNotification` from `@/types/domain/Notifications`; lucide-vue-next icon components.
- Produces: `presentNotification(n: AppNotification): PresentedNotification`.

**Notes:** Pure module — no Vue, no Pinia, no composables. Invoice money is formatted here with `Intl.NumberFormat('es-SV', { style: 'currency', currency })` using the payload's own `currency` (billing currency, may differ from tenant currency). Never throw on missing payload fields — coalesce.

- [ ] **Step 1: Create the presenter**

`resources/js/components/layout/notificationPresenter.ts`:

```ts
import { AlertCircle, Ban, Bell, Clock, FileText, PackageX } from 'lucide-vue-next'
import type { AppNotification, PresentedNotification } from '@/types/domain/Notifications'

function str(v: unknown, fallback = ''): string {
    return v === null || v === undefined ? fallback : String(v)
}

function money(cents: unknown, currency: unknown): string {
    const amount = Number(cents ?? 0) / 100
    const code = str(currency, 'USD')
    try {
        return new Intl.NumberFormat('es-SV', { style: 'currency', currency: code }).format(amount)
    } catch {
        return `${amount.toFixed(2)} ${code}`
    }
}

/**
 * Maps a notification's `type` + `payload` to a display shape. The API stores
 * heterogeneous payloads with no title/body, so all display copy lives here.
 * Unknown types fall back to a generic bell with an empty body.
 */
export function presentNotification(n: AppNotification): PresentedNotification {
    const p = n.payload ?? {}

    switch (n.type) {
        case 'inventory.low_stock':
            return {
                icon: PackageX,
                title: 'Stock bajo',
                body: `${str(p.product_name, 'Producto')} (${str(p.sku, 'N/A')}) en ${str(p.branch_name, 'sucursal')}: ${str(p.available, '0')} disponibles`,
            }
        case 'billing.trial_ending':
            return {
                icon: Clock,
                title: 'Tu prueba termina pronto',
                body: `Quedan ${str(p.days_left, '0')} dias de prueba`,
            }
        case 'billing.invoice_ready':
            return {
                icon: FileText,
                title: 'Factura lista',
                body: `Factura ${str(p.number, '')} — ${money(p.total_cents, p.currency)}`,
            }
        case 'billing.charge_failed':
            return {
                icon: AlertCircle,
                title: 'Cobro fallido',
                body: 'No pudimos procesar tu pago. Revisa tu metodo de pago.',
            }
        case 'billing.suspended':
            return {
                icon: Ban,
                title: 'Suscripcion suspendida',
                body: 'Tu suscripcion fue suspendida por falta de pago.',
            }
        default:
            return { icon: Bell, title: 'Notificacion', body: '' }
    }
}
```

- [ ] **Step 2: Type-check**

Run: `./vendor/bin/sail npm run build`
Expected: build succeeds.

- [ ] **Step 3: Commit**

```bash
git add resources/js/components/layout/notificationPresenter.ts
git commit -m "feat(notifications): pure presenter mapping type+payload to display"
```

---

### Task 3: Pinia store with polling

**Files:**
- Create: `resources/js/stores/notifications.ts`

**Interfaces:**
- Consumes: `NotificationsService` from `@/services/NotificationsService`; `AppNotification` type.
- Produces: `useNotificationsStore()` exposing refs `unreadCount`, `items`, `loading`, `open` and actions `fetchUnreadCount()`, `fetchList()`, `markRead(id)`, `markAllRead()`, `toggle()`, `close()`, `startPolling()`, `stopPolling()`.

**Notes:** Polling every 60s on the count only. Pause on `document.hidden`; on becoming visible, fetch immediately and resume. Optimistic mutations roll back by refetching count + list on failure. Errors surface via `useToast().error`.

- [ ] **Step 1: Create the store**

`resources/js/stores/notifications.ts`:

```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { NotificationsService } from '@/services/NotificationsService'
import { useToast } from '@/composables/useToast'
import type { AppNotification } from '@/types/domain/Notifications'

const POLL_MS = 60_000

export const useNotificationsStore = defineStore('notifications', () => {
    const unreadCount = ref(0)
    const items = ref<AppNotification[]>([])
    const loading = ref(false)
    const open = ref(false)

    let timer: ReturnType<typeof setInterval> | null = null
    let visibilityBound = false

    async function fetchUnreadCount(): Promise<void> {
        try {
            unreadCount.value = await NotificationsService.getUnreadCount()
        } catch {
            // Keep last-known count; polling will retry. Silent by design (background call).
        }
    }

    async function fetchList(): Promise<void> {
        loading.value = true
        try {
            items.value = await NotificationsService.list(false, 15)
        } catch {
            useToast().error('No pudimos cargar las notificaciones')
        } finally {
            loading.value = false
        }
    }

    async function markRead(id: string): Promise<void> {
        const target = items.value.find((n) => n.id === id)
        if (!target || target.read_at) return
        target.read_at = new Date().toISOString()
        unreadCount.value = Math.max(0, unreadCount.value - 1)
        try {
            await NotificationsService.markRead(id)
        } catch {
            useToast().error('No pudimos marcar la notificacion')
            await Promise.all([fetchUnreadCount(), fetchList()])
        }
    }

    async function markAllRead(): Promise<void> {
        const now = new Date().toISOString()
        items.value.forEach((n) => {
            if (!n.read_at) n.read_at = now
        })
        unreadCount.value = 0
        try {
            await NotificationsService.markAllRead()
        } catch {
            useToast().error('No pudimos marcar todas como leidas')
            await Promise.all([fetchUnreadCount(), fetchList()])
        }
    }

    function toggle(): void {
        open.value = !open.value
        if (open.value) void fetchList()
    }

    function close(): void {
        open.value = false
    }

    function onVisibility(): void {
        if (!document.hidden) void fetchUnreadCount()
    }

    function startPolling(): void {
        void fetchUnreadCount()
        if (timer === null) {
            timer = setInterval(() => {
                if (!document.hidden) void fetchUnreadCount()
            }, POLL_MS)
        }
        if (!visibilityBound) {
            document.addEventListener('visibilitychange', onVisibility)
            visibilityBound = true
        }
    }

    function stopPolling(): void {
        if (timer !== null) {
            clearInterval(timer)
            timer = null
        }
        if (visibilityBound) {
            document.removeEventListener('visibilitychange', onVisibility)
            visibilityBound = false
        }
    }

    return {
        unreadCount,
        items,
        loading,
        open,
        fetchUnreadCount,
        fetchList,
        markRead,
        markAllRead,
        toggle,
        close,
        startPolling,
        stopPolling,
    }
})
```

- [ ] **Step 2: Type-check**

Run: `./vendor/bin/sail npm run build`
Expected: build succeeds.

- [ ] **Step 3: Commit**

```bash
git add resources/js/stores/notifications.ts
git commit -m "feat(notifications): pinia store with visibility-aware polling"
```

---

### Task 4: NotificationsDropdown component

**Files:**
- Create: `resources/js/components/layout/NotificationsDropdown.vue`

**Interfaces:**
- Consumes: `useNotificationsStore()`; `presentNotification` from `./notificationPresenter`; `useFormatDate` from `@/composables/useFormatDate`; lucide `CheckCheck`, `Bell`.
- Produces: a self-contained dropdown component (no props). Emits nothing; reads/writes the store directly.

**Notes:** Renders only when `store.open`. Closes on click-outside and `Escape`. Testid hooks: `notif-panel`, `notif-item`, `notif-mark-all`, `notif-empty`. Use existing glass/card utility classes and design tokens (no hardcoded colors). The unread dot is a small accent-colored span shown when `item.read_at === null`.

- [ ] **Step 1: Create the component**

`resources/js/components/layout/NotificationsDropdown.vue`:

```vue
<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Bell, CheckCheck } from 'lucide-vue-next'
import { useNotificationsStore } from '@/stores/notifications'
import { presentNotification } from './notificationPresenter'
import { useFormatDate } from '@/composables/useFormatDate'

const store = useNotificationsStore()
const { formatRelative } = useFormatDate()
const root = ref<HTMLElement | null>(null)

function onClickOutside(e: MouseEvent): void {
    if (!store.open) return
    const target = e.target as Node
    // Ignore clicks on the bell itself (it toggles the store) and inside the panel.
    if (root.value && !root.value.contains(target) && !(target as HTMLElement).closest?.('[data-notif-bell]')) {
        store.close()
    }
}

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape') store.close()
}

onMounted(() => {
    document.addEventListener('click', onClickOutside)
    document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
    document.removeEventListener('click', onClickOutside)
    document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
    <div
        v-if="store.open"
        ref="root"
        class="notif-panel"
        role="menu"
        data-testid="notif-panel"
    >
        <header class="notif-panel-head">
            <span class="notif-panel-title">Notificaciones</span>
            <button
                type="button"
                class="notif-mark-all"
                data-testid="notif-mark-all"
                :disabled="store.unreadCount === 0"
                @click="store.markAllRead()"
            >
                <CheckCheck :size="16" />
                <span>Marcar todas</span>
            </button>
        </header>

        <div v-if="store.loading" class="notif-empty">
            <span>Cargando...</span>
        </div>

        <ul v-else-if="store.items.length" class="notif-list">
            <li
                v-for="item in store.items"
                :key="item.id"
                class="notif-item"
                data-testid="notif-item"
                :class="{ 'is-unread': item.read_at === null }"
                @click="store.markRead(item.id)"
            >
                <span class="notif-item-icon" aria-hidden="true">
                    <component :is="presentNotification(item).icon" :size="18" />
                </span>
                <span class="notif-item-body">
                    <span class="notif-item-title">{{ presentNotification(item).title }}</span>
                    <span class="notif-item-text">{{ presentNotification(item).body }}</span>
                    <span class="notif-item-time">{{ formatRelative(item.created_at) }}</span>
                </span>
                <span v-if="item.read_at === null" class="notif-item-dot" aria-hidden="true" />
            </li>
        </ul>

        <div v-else class="notif-empty" data-testid="notif-empty">
            <Bell :size="24" />
            <span>No tienes notificaciones</span>
        </div>
    </div>
</template>

<style scoped>
.notif-panel {
    position: absolute;
    top: calc(100% + 0.5rem);
    right: 0;
    width: 22rem;
    max-width: calc(100vw - 2rem);
    max-height: 26rem;
    overflow-y: auto;
    background: var(--surface);
    border-radius: var(--r-lg);
    box-shadow: var(--shadow-lifted);
    z-index: 50;
}
.notif-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1rem;
    background: var(--tier-mid);
    border-top-left-radius: var(--r-lg);
    border-top-right-radius: var(--r-lg);
}
.notif-panel-title {
    font-weight: 600;
    color: var(--on-surface);
}
.notif-mark-all {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.8rem;
    color: var(--primary);
    background: transparent;
    cursor: pointer;
}
.notif-mark-all:disabled {
    opacity: 0.5;
    cursor: default;
}
.notif-list {
    display: flex;
    flex-direction: column;
}
.notif-item {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    padding: 0.75rem 1rem;
    cursor: pointer;
}
.notif-item:hover {
    background: var(--tier);
}
.notif-item.is-unread {
    background: var(--tier-mid);
}
.notif-item-icon {
    color: var(--secondary);
    flex-shrink: 0;
    margin-top: 0.1rem;
}
.notif-item-body {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    min-width: 0;
    flex: 1;
}
.notif-item-title {
    font-weight: 600;
    font-size: 0.88rem;
    color: var(--on-surface);
}
.notif-item-text {
    font-size: 0.8rem;
    color: var(--on-surface-variant);
}
.notif-item-time {
    font-size: 0.72rem;
    color: var(--on-surface-variant);
    opacity: 0.75;
}
.notif-item-dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: var(--r-full);
    background: var(--primary);
    flex-shrink: 0;
    margin-top: 0.35rem;
}
.notif-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 2rem 1rem;
    color: var(--on-surface-variant);
    font-size: 0.85rem;
}
</style>
```

- [ ] **Step 2: Verify design tokens exist**

Run: `grep -nE "\-\-tier\b|\-\-tier-mid|\-\-on-surface-variant|\-\-shadow-lifted|\-\-r-lg|\-\-r-full|\-\-primary|\-\-secondary|\-\-surface\b" resources/css/app.css | head`
Expected: each token used in the component appears. If `--on-surface-variant` is absent, substitute the closest existing muted-text token found in `app.css` (do not invent a token).

- [ ] **Step 3: Type-check / build**

Run: `./vendor/bin/sail npm run build`
Expected: build succeeds.

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/layout/NotificationsDropdown.vue
git commit -m "feat(notifications): dropdown panel component"
```

---

### Task 5: Wire AdminLayout + Playwright e2e

**Files:**
- Modify: `resources/js/components/layout/AdminLayout.vue` (imports block ~1-31; bell button ~294-302; script setup lifecycle)
- Create: `tests/e2e/admin/notifications.spec.ts`

**Interfaces:**
- Consumes: `useNotificationsStore`, `NotificationsDropdown`.
- Produces: cabled bell with `data-notif-bell` + `data-testid="notif-bell"`, badge `data-testid="notif-badge"`, and a positioned wrapper so the dropdown anchors to the bell.

- [ ] **Step 1: Add imports to AdminLayout script setup**

In `resources/js/components/layout/AdminLayout.vue`, after the existing `import ConfirmDialog ...` line, add:

```ts
import { useNotificationsStore } from '@/stores/notifications'
import NotificationsDropdown from '@/components/layout/NotificationsDropdown.vue'
```

- [ ] **Step 2: Instantiate the store + lifecycle**

In the same `<script setup>`, after `const { isDark, toggle: toggleDark } = useTheme()`, add:

```ts
const notifications = useNotificationsStore()

onMounted(() => notifications.startPolling())
onUnmounted(() => notifications.stopPolling())
```

(`onMounted`/`onUnmounted` are already imported at the top of the file. If AdminLayout already has an `onMounted`/`onUnmounted` block, add these calls inside the existing hooks instead of adding duplicate hooks.)

- [ ] **Step 3: Replace the decorative bell button**

Replace the current bell block:

```vue
                <!-- Notifications -->
                <button
                    type="button"
                    class="btn-icon relative"
                    aria-label="Notificaciones"
                >
                    <Bell :size="20" />
                    <span class="admin-topbar-dot" aria-hidden="true" />
                </button>
```

with:

```vue
                <!-- Notifications -->
                <div class="relative">
                    <button
                        type="button"
                        class="btn-icon relative"
                        aria-label="Notificaciones"
                        data-notif-bell
                        data-testid="notif-bell"
                        :aria-expanded="notifications.open"
                        aria-haspopup="menu"
                        @click="notifications.toggle()"
                    >
                        <Bell :size="20" />
                        <span
                            v-if="notifications.unreadCount > 0"
                            class="notif-badge"
                            data-testid="notif-badge"
                        >{{ notifications.unreadCount > 9 ? '9+' : notifications.unreadCount }}</span>
                    </button>
                    <NotificationsDropdown />
                </div>
```

- [ ] **Step 4: Add the badge style**

Search for the `.admin-topbar-dot` rule in `resources/js/components/layout/AdminLayout.vue`'s `<style>` block. Directly after it, add:

```css
.notif-badge {
    position: absolute;
    top: -0.15rem;
    right: -0.15rem;
    min-width: 1.05rem;
    height: 1.05rem;
    padding: 0 0.28rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.68rem;
    font-weight: 700;
    line-height: 1;
    color: #fff;
    background: var(--primary);
    border-radius: var(--r-full);
}
```

(If `.admin-topbar-dot` is defined in a global stylesheet rather than this component, add `.notif-badge` there instead, next to it.)

- [ ] **Step 5: Build**

Run: `./vendor/bin/sail npm run build`
Expected: build succeeds.

- [ ] **Step 6: Write the e2e spec**

`tests/e2e/admin/notifications.spec.ts`. Follow the seeding + login pattern already used in `tests/e2e/admin/pos/pos-tax.spec.ts` (tenant subdomain via `tenantBaseURL`, Bearer/session login, in-page relative fetches). Seed TWO unread notifications of different types for the acting user, then drive the UI:

```ts
import { test, expect } from '@playwright/test'
import { tenantBaseURL } from '../support/env'

// Seeds two unread notifications of different types for the acting user in the
// current tenant, exercising more than one presenter branch, then verifies the
// bell badge + dropdown + mark-read flows on desktop and mobile.

const TENANT = 'rosaeterna'

test.describe('Admin notifications bell', () => {
    // NOTE: implementer — reuse the exact login + seeding helper shape from
    // tests/e2e/admin/pos/pos-tax.spec.ts. Seeding must create DatabaseNotification
    // rows with tenant_id set for the logged-in user. If the e2e seeding path in the
    // repo goes through an artisan/testing endpoint rather than direct DB, mirror that.

    test('shows unread badge, lists notifications, and marks read', async ({ page }) => {
        const base = tenantBaseURL(TENANT)

        // --- login (mirror pos-tax.spec.ts) ---
        // await loginAsStaff(page, base) ...

        // --- seed 2 unread notifications (mirror pos-tax.spec.ts seeding) ---
        // one inventory.low_stock + one billing.invoice_ready for the acting user

        await page.goto(`${base}/admin/dashboard`)

        // Badge shows 2
        await expect(page.getByTestId('notif-badge')).toHaveText('2')

        // Open the panel
        await page.getByTestId('notif-bell').click()
        await expect(page.getByTestId('notif-panel')).toBeVisible()

        // Both presenter titles are rendered
        await expect(page.getByText('Stock bajo')).toBeVisible()
        await expect(page.getByText('Factura lista')).toBeVisible()

        // Mark all read -> badge disappears
        await page.getByTestId('notif-mark-all').click()
        await expect(page.getByTestId('notif-badge')).toHaveCount(0)
    })
})
```

- [ ] **Step 7: Build then run the e2e**

Run: `./vendor/bin/sail npm run build && ./vendor/bin/sail npm run test:e2e -- notifications.spec.ts`
Expected: the notifications spec passes on the configured projects (desktop + mobile). If the mobile viewport hides the topbar bell behind a menu, scope the mobile assertion accordingly (mirror how pos-tax handles desktop-only UI) rather than asserting a hidden element.

- [ ] **Step 8: Commit**

```bash
git add resources/js/components/layout/AdminLayout.vue tests/e2e/admin/notifications.spec.ts
git commit -m "feat(notifications): wire admin bell to dropdown + e2e"
```

---

## Self-Review

**Spec coverage:**
- Bell dropdown + badge + list + mark-read single + mark-all → Tasks 4, 5. ✓
- 60s polling paused on hidden tab → Task 3 (`startPolling`/`onVisibility`). ✓
- Presenter for 5 types + fallback → Task 2. ✓
- Service over the 4 endpoints with correct envelope unwrapping → Task 1. ✓
- No new PHPUnit (API already covered) → stated in Global Constraints + no task. ✓
- e2e seeding two types, desktop + mobile → Task 5. ✓
- Out-of-scope items (dedicated page, filters, settings wiring, websockets) → not present in any task. ✓

**Placeholder scan:** The e2e spec (Task 5) intentionally references the pos-tax seeding/login helper rather than duplicating it — this is a real repo pattern the implementer must mirror, and the exact login helper is environment-specific (Bearer vs session). This is the one deliberate "mirror existing pattern" pointer; everything else is complete code.

**Type consistency:** `AppNotification`/`PresentedNotification` defined in Task 1 and used identically in Tasks 2-4. Service method names (`getUnreadCount`, `list`, `markRead`, `markAllRead`) match store usage in Task 3. Store surface (`unreadCount`, `items`, `loading`, `open`, `toggle`, `close`, `markRead`, `markAllRead`, `startPolling`, `stopPolling`) matches AdminLayout + dropdown usage in Tasks 4-5. ✓
