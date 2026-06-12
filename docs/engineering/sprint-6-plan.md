# Sprint 6 — Expenses con OCR

> Plan de Sprint 6. Registro de gastos del tenant con reconocimiento automático de facturas
> (OCR). El usuario sube una foto/PDF de la factura, un job en cola la procesa con Tesseract,
> y se le presenta un borrador pre-llenado que **revisa y corrige antes de guardar**.
>
> **OCR self-hosted (Tesseract), asistivo y desacoplado por driver** — decisión confirmada con
> el usuario (2026-06-12): nosotros gestionamos el OCR (gratis, privado), pero detrás de un
> `OcrDriverInterface` para poder enchufar un driver cloud premium (Textract/Mindee) más adelante,
> gateado por plan. El OCR NUNCA es la verdad final: pre-llena, el usuario confirma.
>
> Última actualización: 2026-06-12.

---

## Objetivo

Que el tenant registre gastos por categoría, suba la factura (foto/PDF), y que el sistema
extraiga automáticamente proveedor + monto + fecha para que el staff solo confirme/corrija.
Más un reporte mensual por categoría.

---

## Estado actual (auditoría)

| Pieza | Estado |
|---|---|
| `Expense` / `ExpenseCategory` models | Existen (prototipo), apuntan a tablas legacy rotas |
| Migración `expenses` (2026_04_17_000009) | **Legacy ROTA**: sin `tenant_id` (modelo usa BelongsToTenant), `amount` no `_cents`. PERO ya trae `receipt_image`, `ocr_data` (json), `is_verified`, `vendor` |
| `expense_categories` | Legacy: sin `tenant_id` (global), enum `type` (operating/products/payroll/rent/other) |
| Rutas `routes/api/v1/expenses.php` | **Vacías** |
| `ExpensesPage.vue` | Placeholder (198 líneas, mock) |
| Tesseract en Docker | **NO instalado** — tarea de devops |
| Upload de archivos | `ProductImageService` (S1-E4) sirve de referencia |
| Plan-gating | Plans module existe; el feature-gating es ligero → el OCR premium queda como abstracción, gating después |

**Conclusión:** retrofit del schema (como Orders/Reservations) + construir el pipeline OCR + UI.

---

## Cambios de schema (ERD)

### 1. Retrofit `expense_categories`
```
id          bigint PK
tenant_id   ulid FK tenants cascade   + BelongsToTenant   -- per-tenant (personalizable)
name        string
type        enum(operating, products, payroll, rent, other) default other
is_active   boolean default true
timestamps
UNIQUE(tenant_id, name)
```
Seedeado con un set default por tenant (Operación, Productos, Nómina, Renta, Otros).

### 2. Retrofit `expenses`
```
id              bigint PK
tenant_id       ulid FK tenants cascade   + BelongsToTenant
branch_id       ulid FK branches nullOnDelete  nullable
expense_category_id  bigint FK expense_categories nullOnDelete nullable
description     string
amount_cents    unsignedInteger default 0        -- _cents (era 'amount')
expense_date    date
vendor          string nullable
payment_method  enum(cash,card,transfer,other) nullable
receipt_path    string nullable                  -- archivo en storage (era receipt_image)
ocr_status      enum(none, pending, processing, done, failed) default none
ocr_data        json nullable                    -- { vendor, amount_cents, date, raw_text, confidence }
is_verified     boolean default false            -- false = borrador (OCR sin confirmar)
notes           text nullable
created_by      bigint FK users nullOnDelete nullable
timestamps + softDeletes
INDEX(tenant_id, expense_date), INDEX(tenant_id, expense_category_id), INDEX(tenant_id, ocr_status)
```

---

## Arquitectura OCR (desacoplada por driver)

```
OcrDriverInterface
  └─ extract(string $absolutePath): OcrResult     // {vendor, amountCents, date, rawText, confidence, lineItems[]}
TesseractOcrDriver  (default, self-hosted)
  └─ preprocesado (deskew/threshold/grayscale via Imagick) → tesseract → parseo heurístico
ReceiptTextParser   (regex/heurísticas: monto total, fecha, proveedor desde texto crudo)
config/ocr.php → driver = env('OCR_DRIVER', 'tesseract')
(futuro) TextractOcrDriver / MindeeOcrDriver → gateado por plan
```

- **Tesseract en Docker:** instalado en la imagen de la app (apt `tesseract-ocr` + `tesseract-ocr-spa`) o servicio aparte. PDF → imagen vía `pdftoppm`/Imagick antes de OCR.
- **Job en cola:** `ProcessReceiptOcrJob` (queued, Redis) — corre tras el upload, llama al driver, guarda `ocr_data` + setea `ocr_status`. Reintentos + `failed` en error. NO bloquea el upload.
- **Asistivo:** el job deja el gasto como **borrador** (`is_verified=false`) con sugerencias; el usuario las corrige y confirma. Tesseract impreciso es aceptable porque siempre hay revisión humana.
- **Tests:** un `FakeOcrDriver` (bind en tests) devuelve un `OcrResult` canónico — NO se corre Tesseract real en CI (lento/no determinista). El `ReceiptTextParser` se testea aparte con fixtures de texto crudo.

---

## Flujo de usuario

```
1. Subir factura (foto/PDF)
   → crea Expense borrador (is_verified=false, ocr_status=pending, receipt_path)
   → dispatch ProcessReceiptOcrJob
2. Job: OCR → ocr_data (vendor/monto/fecha sugeridos) + ocr_status=done
3. UI de verificación: campos pre-llenados desde ocr_data → usuario corrige → confirmar
   → is_verified=true, campos finales persistidos
4. (alternativa) Gasto manual sin factura: form directo, is_verified=true
5. Reporte mensual por categoría
```

---

## Desglose en epics (tickets GitHub)

| Epic | Capa | Entregable |
|---|---|---|
| **S6-E1** | Backend | Retrofit schema: `expense_categories` (tenant_id, type, unique) + `expenses` (tenant_id, branch_id, `_cents`, ocr_status, receipt_path, softDeletes). Models + factories + seeding de categorías default por tenant. PHPUnit (schema, scope, relations) |
| **S6-E2** | DevOps/Backend | Tesseract en Docker (app image + spa lang + pdftoppm) + abstracción OCR: `OcrDriverInterface`, `TesseractOcrDriver`, `ReceiptTextParser` (heurísticas monto/fecha/proveedor), `OcrResult`, `config/ocr.php`, binding. PHPUnit del parser con fixtures + FakeOcrDriver |
| **S6-E3** | Backend | Upload de factura + `ProcessReceiptOcrJob` (queued): endpoint de upload (guarda archivo, crea borrador, dispatch job), job procesa + puebla ocr_data + ocr_status, manejo de fallo/reintentos. PHPUnit (con FakeOcrDriver: job puebla data, falla → failed) |
| **S6-E4** | Backend | Expenses CRUD API (index+filtros, store manual, show, update/verify, destroy) + ExpenseCategory API (CRUD per-tenant) + Policy + Resources + Form Requests + rutas. PHPUnit + Playwright (gates) |
| **S6-E5** | Backend | Reporte mensual por categoría: endpoint agregado (totales por categoría por mes, rango de fechas). PHPUnit |
| **S6-E6** | Frontend | Lista de gastos + CRUD manual + gestión de categorías. Filtros (categoría/mes/fecha), form de gasto manual. `ExpenseService.ts`, types. Playwright |
| **S6-E7** | Frontend | Upload de factura + **UI de verificación OCR**: dropzone (foto/PDF), estado de procesamiento (polling del ocr_status), pantalla de verificación con campos pre-llenados desde ocr_data → corregir + confirmar. Playwright |
| **S6-E8** | Frontend + Data/QA | Vista de reporte mensual (chart por categoría, vue-chartjs como el dashboard) + seeder (gastos demo por categorías/meses, algunos con OCR) + pase `qa-engineer` + e2e full-flow (upload → OCR → verificar → guardar) |

**Orden:** E1 → E2 → E3 (backend OCR, secuencial) ; E4 → E5 (API) ; E6 → E7 (frontend) → E8 (cierre).
E2 es el epic novel/riesgoso (Tesseract + parsing) — candidato para `devops-integration-engineer`.

---

## Decisiones tomadas

- **Categorías per-tenant** (tabla con tenant_id, seedeada con defaults) — personalizable, consistente con el resto del SaaS. _(decidido)_
- **OCR self-hosted Tesseract por default, asistivo, desacoplado por driver**; cloud premium gateado por plan queda para después (solo la abstracción ahora). _(confirmado con el usuario)_
- **Tests con FakeOcrDriver** — nunca se corre Tesseract real en CI; el parser se testea con fixtures de texto. _(decidido)_
- **PDF soportado** vía conversión a imagen (pdftoppm/Imagick) antes del OCR. _(decidido)_
- **Reporte con vue-chartjs** (mismo stack que el dashboard). _(decidido)_

---

## Reglas no negociables

| Regla | Cómo se aplica |
|---|---|
| Retrofit, no parche | Drop legacy + recreate (como Orders S3 / Reservations S5) |
| Multi-tenant | `tenant_id` + `BelongsToTenant` en TODAS las tablas nuevas (era el bug del legacy) |
| Money en centavos | `amount_cents`; nunca decimal |
| OCR asistivo | Nunca la verdad final — el usuario revisa/corrige. Job en cola, no bloquea el upload |
| OCR desacoplado | `OcrDriverInterface` — Tesseract es el default, cloud es enchufable |
| Tests sin Tesseract real | `FakeOcrDriver` en tests; parser con fixtures de texto |
| Dual-layer testing | PHPUnit + Playwright (toda UI con upload/form/verificación) |
| No-Line / dark / sin emojis | Lucide icons, tiers de fondo, dark mode en todas las vistas nuevas |
| Privacidad | Las facturas se guardan en storage del tenant; con Tesseract no salen a terceros |
