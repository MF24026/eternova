# Sprint 8 — Settings (Configuración del tenant)

> Estado: EN CURSO. Reemplaza el `SettingsPage.vue` mock por un módulo de configuración real, con datos persistidos y validados, listo para per-branch a nivel de esquema.

## Contexto y decisiones

- **La config por-tenant ya vive en la fila `tenants`** (brand, locale, quotation defaults de S7, reservation defaults de S5). Esa parte se respeta y se expone para edición.
- **La tabla `settings` prototipo (2026_04_17) está rota**: sin `tenant_id`, `unique(group,key)` global, pero el modelo `Setting` asume `tenant_id`. No se usa en ninguna ruta. **Se elimina** (patrón retrofit) junto al modelo.
- **Decisión per-branch (confirmada con el usuario):** se construye el **esquema branch-ready desde el día 1** (`branch_settings` con `branch_id` nullable + UNIQUE compuesto + resolver lenient), pero la **UX de Sprint 8 es tenant-wide** (escribe siempre el default `branch_id = null`). No hay infraestructura de "current branch" (1 branch por tenant, sin switcher), así que badges/revert/scope-toggle se difieren hasta que exista el switcher — sin migración futura.
- **Auth (auth-granularity skill):** settings de política (marca, locale) son owner/admin. Los de ejecución (contacto, impuestos, pedidos, notificaciones) también owner/admin por ahora (sin manager-scoped hasta tener branch context).

## Clasificación 3-ejes de cada grupo de settings

| Tab UI | Grupo | Policy/Execution | Per-location | Storage |
|--------|-------|------------------|--------------|---------|
| Marca | brand | Policy | NO | fila `tenants` (cols existentes) |
| Localización | locale | Policy | NO | fila `tenants` (cols existentes) |
| Cotizaciones | quotation defaults | Policy | NO | fila `tenants` (cols S7) |
| Reservas | reservation defaults | Policy | NO | fila `tenants` (cols S5) |
| Contacto | `contact` | Execution | SÍ | `branch_settings` (default ahora) |
| Impuestos | `tax` | Policy+Execution | A veces | `branch_settings` (default ahora) |
| Pedidos | `orders` | Execution | SÍ | `branch_settings` (default ahora) |
| Notificaciones | `notifications` | Execution | SÍ | `branch_settings` (default ahora) |

## Modelo de datos

### `branch_settings` (nueva, branch-ready)
```
id                bigint pk
tenant_id         char(26) FK tenants cascade
branch_id         char(26) nullable FK branches cascade   // null = default del tenant
group             string(50)        // 'contact' | 'tax' | 'orders' | 'notifications'
key               string(100)
value             json nullable
timestamps
UNIQUE (tenant_id, branch_id, group, key)
INDEX (tenant_id, group)
```
- **Gotcha MySQL:** NULL es distinto en UNIQUE → "un default por (tenant,group,key)" se garantiza en código (upsert por resolver), no solo por el índice.
- **Resolver lenient** (`BranchAwareSetting` concern en el modelo `BranchSetting`): `branch override ?? tenant default (branch_id=null) ?? coded defaults`. Hoy la UI siempre escribe/lee el default.

### Fila `tenants` (settings de política, ya existentes; se exponen para edición)
brand: `business_name, logo_url, primary_color, secondary_color, favicon_url, brand_extra` ·
locale: `currency, country_code, language, timezone, locale_extra` ·
quotation: `quotation_tax_rate_bps, quotation_valid_days, quotation_terms` ·
reservation: `reservation_deposit_pct, reservation_occasions`.

## Épicas

| Épica | Capa | Alcance |
|-------|------|---------|
| **S8-E1** | Data | Drop legacy `settings` + modelo. Crear `branch_settings` + modelo `BranchSetting` + `BranchAwareSetting` resolver (lenient, defaults codificados, upsert default). Catálogo de defaults por grupo (`config/tenant-settings.php`). PHPUnit: resolver fallback, aislamiento por-tenant y por-branch, defaults seguros en tenant fresco. |
| **S8-E2** | Backend | `SettingsService` (resuelve grupos para el branch actual; persiste default; upload logo/favicon). `SettingsController` (show = todos los grupos resueltos + meta de catálogos; update por grupo). Form Requests por grupo con validación i18n-latam (hex, ISO 4217, country code, tax bps 0–9999, phone por país). `TenantSettingsResource`. `SettingsPolicy` (owner/admin). Rutas `/api/v1/settings`. PHPUnit + Playwright gates de auth. |
| **S8-E3** | Frontend | `SettingsService.ts` + tipos + `SettingsPage` real (shell + tabs). Tabs **tenant-wide**: Marca (logo/favicon upload, colores, nombre), Localización (país→moneda/tz/phone defaults i18n), Cotizaciones (defaults S7), Reservas (defaults S5). Save por tab, dirty-state, errores de validación. Playwright + QA visual light/dark. |
| **S8-E4** | Frontend | Tabs de **ejecución** (escriben el default de `branch_settings`): Contacto (phone/email/web/dirección), Impuestos (toggle IVA + tasa + tax id), Pedidos (config operativa), Notificaciones (toggles de eventos). Save por grupo. Playwright + QA visual. |
| **S8-E5** | Data/QA | Seeder: defaults realistas de `branch_settings` por tenant (contacto, impuestos, notificaciones) + valores de marca/locale ya seedeados por DemoTenantsSeeder. Pase `qa-engineer` (desktop+mobile+dark, regresiones). E2e full-flow (editar cada grupo → recargar → persiste) + suite de aislamiento cross-tenant. |

**Orden:** E1 → E2 (backend secuencial) → E3 → E4 (frontend) → E5 (seeder + cierre).

## Reglas no negociables
- Dinero en centavos; tax en bps. Validación entera.
- Multi-tenant: `branch_settings` lleva `tenant_id` + scope; el resolver nunca cruza tenants.
- Dual-layer testing (PHPUnit + Playwright) por épica con mutación.
- Sin emojis; Lucide icons; dark mode; responsive; español UI / inglés código.
- QA visual antes de cerrar cada PR de frontend.
