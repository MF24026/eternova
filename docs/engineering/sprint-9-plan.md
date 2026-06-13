# Sprint 9 (ing.) / Roadmap Sprint 8 — Dashboard + Plan Gating + Polish (cierre del MVP)

> Estado: EN CURSO. Convierte el `DashboardPage` mock en KPIs/gráficos reales,
> añade el gating de features por plan en la UI (Patrón B: visible-pero-bloqueado),
> y cierra el MVP con pulido visual + accesibilidad.
>
> Nota de numeración: las épicas de ingeniería iban 7=Cotizaciones, 8=Settings
> (deuda técnica fuera del roadmap). Este sprint corresponde al **Sprint 8 del
> roadmap de producto** ("Dashboard + Polish + Plan Gating"), el cierre del MVP.

## Estado actual (verificado)

- `DashboardPage.vue` es **100% mock**: KPIs hardcodeados, gráfico SVG con datos falsos, entregas/top-products/actividad inventados. `KpiCard.vue` es reutilizable (se conserva).
- **No hay módulo `app/Modules/Dashboard`** ni endpoint de agregación.
- **No hay librería de charts** instalada (roadmap pide Chart.js).
- **Plan gating:** backend tiene `Plan` (features/limits JSON, `getLimit`/`isUnlimited`) + `PlanGateException` (402), pero **la UI no expone ni bloquea features por plan**. `/me` (MeController) devuelve user+tenants pero **no el plan**.

## Decisiones

- **Charts:** `chart.js` + `vue-chartjs` (estándar, liviano, dark-mode vía tokens). Confirmado por roadmap.
- **Plan gating Patrón B** (del skill `saas-plan-gating-billing`): features de Pro/Enterprise **visibles pero bloqueadas con CTA de upgrade**, no ocultas — convierte mejor.
- **Plan en bootstrap:** extender `/me` (o `UserResource`) con el plan del tenant actual (`slug`, `features`, `limits`) para que el front decida el gating sin round-trips extra.
- **Dinero en centavos** en todas las agregaciones; formateo en el front.
- **Aislamiento multi-tenant** obligatorio en cada agregación (scope por tenant; nunca cruzar).

## Épicas

| Épica | Capa | Alcance |
|-------|------|---------|
| **S9-E1** | Backend | Módulo `Dashboard`: `DashboardService` con agregaciones scoped por tenant — Ventas hoy (orders pagadas/entregadas), Pedidos pendientes, Stock bajo (branch_inventory bajo reorder), Gastos del mes; serie de ventas por día (rango 7/14/30d); top productos (order_items por qty); entregas de hoy; actividad reciente. `DashboardController` (`GET /dashboard?range=14d`) + Resource. PHPUnit: cálculos correctos, rango, aislamiento por-tenant, tenant vacío no rompe. |
| **S9-E2** | Frontend | `DashboardService.ts` + tipos. Reescribir `DashboardPage`: KPIs reales (KpiCard), **gráfico Chart.js** (línea de ventas, tabs de rango, dark-mode vía tokens), top productos, entregas, actividad — todo desde la API. Estados loading/empty/error. Playwright + QA visual light/dark/mobile. |
| **S9-E3** | Backend+Frontend | Exponer plan en `/me` (`UserResource` → `plan: {slug, features, limits}`). `usePlanGate()` composable + `UpgradeBadge`/`PlanLock` (Patrón B). Aplicar gating a 1-2 features de plan superior (ej. reporte avanzado de gastos, multi-sucursal) con CTA a upgrade. Backend: helper `tenant->planFeatures()`. PHPUnit (resolución de plan/features) + Playwright (estado bloqueado + CTA visible, no oculto). |
| **S9-E4** | Frontend/QA | Onboarding tour ligero (overlay propio, sin lib pesada) en primer login del owner; pulido visual + accesibilidad básica (focus states, contraste, navegación por teclado en tabs/slideovers) + budget de performance. Pase `qa-engineer` (desktop+mobile+dark, regresiones cross-módulo). E2e del flujo dashboard + gating. |

**Orden:** E1 → E2 (dashboard) → E3 (gating) → E4 (tour + pulido + cierre).

## Reglas no negociables
- Dual-layer testing (PHPUnit + Playwright) por épica con mutación/UI.
- Agregaciones: una sola query por KPI donde sea posible; eager-load para evitar N+1; scope por tenant SIEMPRE.
- Sin emojis; Lucide icons; dark mode; responsive; español UI / inglés código.
- `npm run build` antes de e2e (los e2e pegan al bundle compilado); reseed tras `migrate:fresh`.
- QA visual antes de cerrar cada PR de frontend.

## Fin del MVP
Al cerrar este sprint, el producto está listo para una beta cerrada de tenants
reales (con billing manual hasta que Sprint 9-roadmap cierre Wompi).
