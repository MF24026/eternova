# Roadmap — Eternova

> **Estado:** producto en construcción. Sprint 0 en curso. MVP objetivo: Sprint 8.
>
> Esta es la vista de **producto** del roadmap. Para el plan de ingeniería detallado (épicas, tickets, owners, skills) ver `docs/engineering/engineering-process.md`.

---

## Visión

Eternova es una plataforma SaaS multi-tenant para negocios físicos pequeños de Latinoamérica: florerías, regalerías, boutiques de regalos, peluches y accesorios. Cada negocio (tenant) recibe en una sola aplicación web:

- Catálogo público con checkout vía WhatsApp
- Punto de venta interno (POS)
- Inventario por sucursal en tiempo real
- Gestión de pedidos y despachos
- Reservas personalizadas con adelantos
- Gastos con reconocimiento de facturas (OCR)
- Cotizaciones con generación PDF
- Dashboard con KPIs en vivo
- Configuración de marca, moneda y país propios

La plataforma se monetiza con tres planes (Básico / Pro / Enterprise) cobrados vía Wompi (El Salvador + Colombia) con 30 días de prueba sin tarjeta.

---

## Metodología

- Sprints de **2 semanas** (Scrumban Lite).
- Cada sprint entrega funcionalidad **testeable end-to-end** (PHPUnit + Playwright).
- Los agentes de Claude Code trabajan en paralelo por dominio, sincronizándose en los contratos de datos (migraciones y modelos).
- Ver `docs/engineering/engineering-process.md` para el detalle del proceso, agentes y skills disponibles.

---

## Sprint 0 — Foundation multi-tenant

**Objetivo:** plataforma con multi-tenancy operativa, auth, layouts y schema base de Plans/Billing.

Resultado esperado:
- Resolución de tenant por subdomain (`*.eternova.localhost`) + custom domain + fallback path-based
- Schema completo de Tenants, Branches, Plans, Subscriptions, Invoices
- Roles dentro del tenant: owner, admin, staff, customer
- Layouts: AdminLayout, StorefrontLayout, MarketingLayout, OnboardingLayout, SuperAdminLayout
- Onboarding signup → elegir plan → crear tenant → redirect al subdomain
- 2 tenants demo seedados ("Rosa Eterna", "Tatiana") con sus owners y staff

---

## Sprint 1 — Catalog & Inventory

**Objetivo:** módulo de catálogo completo con variants tipo Shopify, e inventario por sucursal.

Resultado esperado:
- CRUD Categorías (con subcategorías, drag-sort)
- CRUD Productos con product_variants, product_options, M2M a categories, tags
- Upload de imágenes (single + galería) con resize automático
- Inventario por sucursal: ajustes, transferencias, movimientos
- Alertas de stock bajo (event + email)
- 20 productos seedados en cada tenant demo

---

## Sprint 2 — Storefront público + Carrito + Checkout WhatsApp

**Objetivo:** catálogo público navegable con carrito persistente y checkout vía WhatsApp.

Resultado esperado:
- Storefront público en `/`, `/products`, `/products/{slug}`
- Carrito persistente (Pinia + localStorage)
- Checkout arma mensaje WhatsApp formateado y abre `wa.me`
- Filtros por categoría con Bloom Chips
- Responsive mobile-first
- SEO básico: meta tags, sitemap por tenant

---

## Sprint 3 — POS interno

**Objetivo:** terminal de venta presencial para staff del negocio.

Resultado esperado:
- Grid de productos + carrito lateral
- Búsqueda rápida nombre/SKU/barcode
- Selector de cliente (walk-in o registrado)
- Métodos de pago: efectivo, tarjeta (manual)
- Checkout descuenta inventario automáticamente
- Receipt HTML imprimible

---

## Sprint 4 — Orders y Despachos

**Objetivo:** ciclo de vida completo de pedidos del tenant.

Resultado esperado:
- Listado con tabs por estado
- Workflow: pending → preparing → ready → dispatched → delivered
- Vista detalle con timeline de cambios
- Tracking público read-only para clientes (link compartible)
- Asignación de pedidos a staff

---

## Sprint 5 — Reservations

**Objetivo:** reservas personalizadas para eventos (arreglos custom, bodas, etc).

Resultado esperado:
- Captura de reserva: descripción, fecha de entrega, ocasión, monto
- Adelantos parciales (30% default, configurable en Settings del tenant)
- Estados: inquiry → confirmed → in_progress → ready → delivered
- Conversión a Order al entregar

---

## Sprint 6 — Expenses con OCR

**Objetivo:** registro de gastos con reconocimiento automático de facturas.

Resultado esperado:
- CRUD gastos por categoría (operating, products, payroll, rent, other)
- Upload de foto o PDF de factura
- OCR vía Tesseract (queued job) extrae vendor, monto, fecha, ítems
- UI de verificación: usuario corrige antes de guardar
- Reporte mensual por categoría

---

## Sprint 7 — Quotations PDF

**Objetivo:** cotizaciones profesionales con PDF descargable.

Resultado esperado:
- CRUD cotizaciones (draft → sent → accepted/rejected/expired)
- Editor con items, ajustes de precio
- Preview en vivo del PDF (DomPDF / Browsershot)
- Envío por email/WhatsApp con link de aceptación
- Conversión de cotización aceptada → Order

---

## Sprint 8 — Dashboard + Polish + Plan Gating

**Objetivo:** MVP listo para mostrar a tenants reales.

Resultado esperado:
- Dashboard con KPI cards reales (ventas, pedidos, stock bajo, gastos del mes)
- Gráficos Chart.js: ventas últimos 14/30 días, top productos
- Feature gating por plan: features de Pro/Enterprise visibles pero bloqueadas con CTA en plan Básico
- Onboarding tour para nuevos tenants
- Pulido visual, accesibilidad básica WCAG AA, performance budget

**Fin del MVP.** A partir de acá, el producto está listo para tenants reales (con billing manual mientras Sprint 9 cierra Wompi).

---

## Sprint 9+ — Post-MVP

| Sprint | Tema |
|---|---|
| 9 | Billing real con Wompi: webhooks, dunning, downgrade/upgrade, prorrateo |
| 10 | Custom domains end-to-end: verificación CNAME/TXT, SSL automático con Let's Encrypt DNS-01 |
| 11 | Multi-idioma (es/en) + i18n LatAm completo (RUT/NIT/RFC, validación teléfono por país) |
| 12 | Reportes avanzados: P&L mensual, ABC de productos, churn, cohorts |
| 13 | Integraciones: email marketing (Mailchimp/Brevo), contabilidad (Alegra/Siigo) |
| 14 | PWA del storefront para clientes finales |
| 15+ | App móvil nativa para owners/staff (Flutter o React Native) |

Estas prioridades pueden moverse según feedback de los primeros tenants reales.

---

## Riesgos del roadmap

| Riesgo | Mitigación |
|---|---|
| Tenants reales encuentran fricciones que no anticipamos en MVP | Sprint 8 con beta cerrada de 3-5 tenants antes de open signup |
| Wompi no aprueba la cuenta a tiempo | Plan B: facturación manual + Stripe como gateway secundario |
| Multi-tenancy genera bugs de leak entre tenants en producción | Tests de aislamiento obligatorios en cada PR + auditoría externa antes de Sprint 9 |
| Performance de queries con global scope crece linealmente con tenants | Benchmark al final de Sprint 1 + plan de sharding documentado si supera umbral |

---

## Histórico

| Fecha | Evento |
|---|---|
| 2026-05-15 | Pivote de "Carol Creaciones" (single-tenant) a "Eternova" (SaaS multi-tenant) |
| 2026-06-04 | Roadmap reescrito para reflejar el modelo SaaS y los sprints multi-tenant |
