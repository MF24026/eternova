# Entrega Sprints 7 & 8 — Cotizaciones + Settings

> Resumen de lo implementado y mergeado a `develop`. Para el plan detallado ver
> `sprint-7-plan.md` y `sprint-8-plan.md`. Para la vista de producto, `../roadmap.md`.

## Sprint 7 — Cotizaciones (PDF)

Módulo de cotizaciones completo: del borrador al PDF, con transiciones de estado
y conversión a pedido. Dinero siempre en centavos; impuesto en basis points
(`tax = intdiv(max(0, subtotal - discount) * tax_rate_bps, 10000)`).

| Épica | PR | Entrega |
|-------|----|---------|
| E1 | #143 | Esquema retrofit: `quotations`, `quotation_items`, `quotation_sequences`, `quotation_status_history` (drop de tablas prototipo). Modelos `BelongsToTenant`, factories. |
| E2 | #144 | `QuotationService`: secuencia por-tenant (`COT-{año}-{NNNN}`, `lockForUpdate`), cálculo de totales entero, máquina de estados (`draft→sent\|accepted\|rejected\|expired`). |
| E3 | #145 | API CRUD + `QuotationPolicy` (extiende `TenantScopedPolicy`) + Resources + transiciones; `DomainException → 422`. |
| E4 | #146 | Pipeline PDF (DomPDF tras interfaz `QuotationPdfRenderer`, driver-based como OCR), Blade con branding del tenant. Fix de layout: `@page margin` en vez de padding (DomPDF ignora `box-sizing`). |
| E5 | #147 | Conversión cotización→pedido (`OrderService::createFromQuotation`, idempotente) + job diario de auto-expiración. |
| E6 | #148 | Página de lista (tabs por estado + counts, filtros, paginación), `QuotationService.ts`, tipos. |
| E7 | #149 | Builder slideover (líneas add/remove/reorder, totales en vivo centavos+bps, picker de cliente, modo crear/editar-borrador). 15 Playwright. |
| E8 | #150 | Página de detalle (PDF, send/accept/reject, aceptar-y-convertir, editar-borrador, timeline) + seeder demo (5 estados). 9 Playwright. |

**Decisiones clave:**
- Frontera de módulos one-way: Quotations → Orders. `OrderService` nunca importa tipos de Quotation; se pasan solo primitivos de dominio Order.
- Conversión a pedido se decide al aceptar (`accept(convert_to_order: true)`); no hay endpoint de convertir separado.

## Sprint 8 — Settings (Configuración del tenant)

Reemplaza el `SettingsPage` mock por un módulo real con dos backends de
almacenamiento y esquema listo para per-branch.

| Épica | PR | Entrega |
|-------|----|---------|
| E1 | #151 | Drop de tabla `settings` prototipo (rota: sin `tenant_id`). `branch_settings` branch-ready `(tenant_id, branch_id nullable, group, key, value)` + `UNIQUE` compuesto. `BranchSetting` con resolver lenient. `config/tenant-settings.php`. 8 PHPUnit. |
| E2 | #151 | `SettingsService` (rutea grupos a backend), `SettingsController` (`GET /settings`, `POST /settings/{group}` multipart), `UpdateSettingsRequest` (validación i18n), policy + gates `settings.view/manage`. 11 PHPUnit. |
| E3/E4 | #152 | `SettingsPage` real, 8 tabs cableados, save por grupo, conversión bps↔%, upload de logo, errores 422 inline. 8 Playwright. |
| E5 | #153 | `BranchSettingsSeeder` (contacto + fiscal por tenant demo). |

**Modelo de almacenamiento (clasificación 3-ejes del skill `laravel-saas-settings-architecture`):**
- **Política, tenant-wide → fila `tenants`:** marca (logo/colores/nombre), locale (moneda/país/idioma/tz), defaults de cotizaciones (S7) y reservas (S5).
- **Ejecución, branch-ready → `branch_settings`:** contacto, impuestos, pedidos, notificaciones. Resolver: `coded default ← tenant default (branch_id null) ← branch override`.

**Decisión clave — schema branch-ready, UX tenant-wide:** el esquema soporta
overrides por sucursal desde el día 1 (sin rewrite futuro), pero la UI actual solo
lee/escribe el default (`branch_id = null`). No existe infraestructura de "current
branch" (1 sucursal por tenant, sin switcher), así que badges/revert/save-scope se
difieren hasta que exista el switcher. Ver `sprint-8-plan.md`.

## Patrones transversales reutilizados

- **Cadena retrofit:** drop de tablas prototipo (sin `tenant_id`, sin `_cents`) → recrear con esquema multi-tenant correcto. Usado en Orders, Reservations, Quotations y la tabla `settings`.
- **Dual-layer testing:** PHPUnit (factory + `RefreshDatabase`) para dominio/auth/aislamiento; Playwright (navegador + DB reales) para UI. Backend sin UI → solo PHPUnit.
- **Build antes de e2e:** los e2e pegan al bundle compilado en `public/build` (no hay vite dev/hot). Cambios Vue sin compilar = UI stale. Siempre `npm run build` antes de correr e2e. (Esta fue la causa raíz de los "2 tests fallidos" en S7-E7.)
- **`migrate:fresh` de PHPUnit vacía la DB de dev** que usan los e2e — reseedear antes de e2e/QA visual.
- **Cadencia de entrega:** rama por épica → verificación propia (re-correr tests + leer código + QA visual light/dark/mobile) → squash-PR a `develop` → sync.

## Totales de cobertura

- Sprint 7: ~180 PHPUnit + 41 Playwright (lista + builder + detalle).
- Sprint 8: 27 PHPUnit (8 resolver + 11 API + 8 e2e) + 8 Playwright.
