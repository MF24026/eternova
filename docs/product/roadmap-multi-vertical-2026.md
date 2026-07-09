# Eternova — Roadmap multi-vertical (2026)

**Fecha:** 2026-07-09
**Autores:** Erick + Carolina (producto), con audit técnico
**Estado:** Visión aprobada; Fase 1 en spec.

## Por qué este documento

Eternova nació como software de una florería ("Carol Creaciones") y evolucionó a
SaaS multi-tenant. La ambición real —hablada con Carolina— es dejar de ser
"software de florería" y volverse un **sistema de gestión para cualquier
comerciante** de LatAm: florerías, boutiques, ropa (venta por código de barras),
accesorios, mini-markets. La referencia de mercado es **Treinta (treinta.co)**
—que empezó como libreta digital y creció a super-app de gestión— con la meta de
superarlo en profundidad operativa (POS real, OCR de gastos, cotizaciones PDF,
multi-tenant con aislamiento estricto).

## Audit retrospectivo — POSLatam (restaurant-inventory) vs Eternova

| Eje | POSLatam | Eternova (hoy) |
|---|---|---|
| Branding admin | Minimalista fijo (`brand = emerald`, neutros slate). Neutro por diseño, no configurable. | Paleta fija "Ethereal Boutique" rosa (`--primary #7c545d`). Opinada, lee femenina, no tematizable. |
| Branding cliente | Menú digital inyecta `--brand-*` desde settings del tenant. | Ya existe: `useStorefrontBranding` aplica color del tenant en la vitrina. |
| Impresión / caja | `useQzTray.js`: térmica silenciosa + pulso de caja ESC/POS, firma server-side, fallback PDF (scaffold, sin validar en hardware). | Solo recibo en pantalla/PDF (`PosReceiptSlideover`). Sin térmica ni caja. |
| Código de barras | No tiene. | No tiene. |
| Vertical / nicho | Enfocado a restaurantes. | Sin concepto de `business_type`. Implícito florería/regalos. |

**Hallazgo arquitectónico clave:** el admin de Eternova ya corre 100% sobre un
sistema de **tokens CSS de 2 capas** (runtime `--surface-*`/`--primary-*` en
`:root`, volteados en `.dark`, mapeados a `@theme --color-*`). Cambiar de paleta
es barato: el mecanismo existe; falta productizar la elección.

## Modelo de branding (decisión de producto)

| Superficie | Branding | Estado |
|---|---|---|
| **Vitrina (storefront)** | Custom: el tenant elige su color, la tienda pública se re-tematiza. | Ya existe |
| **Admin / POS** | 2 temas fijos (Ethereal rosa / Minimalista), default per-tenant. Sin custom. | Fase 1 |
| **Claro / oscuro** | Per-usuario (localStorage), ortogonal al tema. | Ya existe |

Racional: la vitrina es la cara al cliente y merece color propio; el admin es el
espacio de trabajo del comerciante y solo necesita **no imponer rosa** a quien no
le va (ej. comerciantes hombres). Dos paletas escritas a mano, sin derivar colores.

## Fases

Cada fase es un sub-proyecto independiente y entregable por sí solo. Cada una se
detalla en su propio spec en `docs/superpowers/specs/` cuando se aborda.

### Fase 1 — Sistema de temas del admin (rosa + minimalista)
**Dolor inmediato.** Segunda paleta neutra tipo POSLatam + selector per-tenant en
Settings > Apariencia. Reusa el motor de tokens existente. Peso: chico.
Spec: `docs/superpowers/specs/2026-07-09-admin-theme-system-design.md`.

### Fase 2 — Concepto de vertical / nicho
`business_type` por tenant (florería / ropa / accesorios / mini-market) que ajusta
terminología (ej. "arreglo" vs "prenda") y activa defaults de features por rubro.
Sienta la base para que el mismo software sirva a nichos distintos sin bifurcar el
código. Peso: medio.

### Fase 3 — Código de barras en POS
Lector USB-HID (se comporta como teclado): capturar el escaneo en el POS, resolver
la variante por `barcode` y agregarla al carrito. Requiere un campo de búsqueda
"focus-trap" y matching por barcode exacto. Net-new (ni Eternova ni POSLatam lo
tienen). Habilita el nicho de ropa/retail. Peso: medio.

### Fase 4 — Impresión térmica + cajón monedero (QZ)
Portar el patrón de `useQzTray` de POSLatam: impresión silenciosa vía QZ Tray,
pulso de caja ESC/POS (`1B700019FA`), firma server-side para conexión sin diálogo,
y fallback a PDF si QZ no está. Guía: skill `saas-thermal-printing-pipeline`.
Requiere validación en hardware real. Peso: grande.

### Fase 5 — Profundidad tipo Treinta (horizonte largo)
Fiado / crédito de cliente (cuenta corriente por cliente), reportes avanzados,
y lo que el mercado pida. Se define cuando lleguemos. Peso: grande.

## Orden y criterio

El orden por defecto es 1 → 5, priorizando dolor percibido y valor sobre esfuerzo.
Fase 1 primero porque es el dolor vivo y el de menor riesgo. El orden de 2 y 3 es
intercambiable según a qué nicho querramos entrar primero (ropa empuja barcode
antes que nicho genérico). No se arranca una fase sin su spec aprobado.
