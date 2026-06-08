# Sprint 2 — Storefront público + Carrito + Checkout WhatsApp

> Plan de Sprint 2. A diferencia de Sprint 1 (schema pesado), Sprint 2 es mayormente **frontend + endpoints públicos read-only**. No hay orders en DB: el carrito vive en localStorage y el checkout abre WhatsApp; el admin registra el pedido manualmente al recibir el mensaje (ver `architecture.md` §8.1).
>
> Última actualización: 2026-06-08.

---

## Objetivo

Catálogo público navegable por tenant (`{slug}.eternova.app`), sin auth, con carrito persistente y checkout vía WhatsApp. Cada tenant ve su propio catálogo con su marca.

---

## ADR-001 resuelto — SEO del storefront

**Decisión: SPA con meta tags dinámicos + Open Graph + sitemap.xml por tenant. SIN SSR.**

Razón: el tráfico de los tenants (florerías/regalerías LatAm) viene de Instagram/Facebook/WhatsApp, no de Google orgánico. Lo crítico es el **link preview** (Open Graph) cuando comparten el catálogo en redes/WhatsApp — eso se logra sin SSR inyectando meta tags por ruta. El SEO orgánico de Google (que exige SSR, semanas de trabajo) se posterga a un sprint dedicado si un tenant real lo pide.

Implementación: meta tags dinámicos vía `@vueuse/head` (o composable propio `useHead`), `og:title/og:description/og:image` por producto, `<link rel="canonical">`, y un endpoint `GET /{tenant}/sitemap.xml` server-rendered (Laravel route, no SPA) que lista productos públicos.

---

## Decisiones técnicas tomadas

| Tema | Decisión |
|---|---|
| Tenant resolution público | Las rutas storefront usan SOLO el middleware `tenant` (NO `auth:sanctum`). EnsureTenant resuelve por subdomain. Sin tenant → 404 |
| Scope público | Los endpoints storefront devuelven SOLO `is_active = true` products/categories. Variants con stock se marcan disponibles; sin stock se muestran "agotado" pero visibles |
| Carrito | Anónimo, localStorage puro (Pinia + persistencia). No requiere identificar al visitante. El carrito es por-tenant (key incluye tenant slug para no mezclar entre subdominios) |
| Checkout | NO crea Order en DB. Arma mensaje WhatsApp formateado con los items + total y abre `https://wa.me/{number}?text={msg}`. El número sale de `tenant.brand_extra.whatsapp_number` |
| Precios | Se muestran con `useFormatCurrency` según `tenant.currency` + `country_code` |
| Stock | El storefront lee `branch_inventory.available` del branch principal del tenant para mostrar disponibilidad |

---

## Épicas

| # | Épica | Owner sugerido | Depende de |
|---|---|---|---|
| S2-E1 | Public storefront API (catalog read-only, public scope) | backend-developer | — |
| S2-E2 | StorefrontLayout + HomePage (hero + featured + categorías) | frontend-developer + ui-ux | S2-E1 |
| S2-E3 | ProductsListPage pública (grid + Bloom Chips + search) | frontend-developer | S2-E1, S2-E2 |
| S2-E4 | ProductDetailPage (variant selector + galería + add-to-cart) | frontend-developer | S2-E1, S2-E2 |
| S2-E5 | Cart store (Pinia + localStorage) + CartSlideover | frontend-developer | S2-E4 |
| S2-E6 | Checkout WhatsApp (mensaje formateado + wa.me) | frontend-developer | S2-E5 |
| S2-E7 | SEO (meta dinámicos + Open Graph + sitemap.xml por tenant) | backend + frontend | S2-E3, S2-E4 |
| S2-E8 | Tests dual-layer + QA visual del storefront | backend + qa-engineer | todas |

---

## Endpoints públicos (S2-E1)

Todos bajo `routes/api/v1/storefront.php`, middleware `['tenant']` (NO auth):

```
GET /api/v1/storefront/products                  paginado (filtros: category_slug, tag_slug, search, sort)
GET /api/v1/storefront/products/featured         destacados para el home
GET /api/v1/storefront/products/{slug}           detalle con variants + galería + stock disponible
GET /api/v1/storefront/categories                árbol de categorías activas
GET /api/v1/storefront/tenant                     branding público (nombre, logo, colores, whatsapp, moneda)
```

Resources dedicados (`StorefrontProductResource`, etc.) que exponen SOLO campos públicos — nunca cost_price_cents, nunca datos internos.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Endpoint público filtra cost_price o datos internos | StorefrontResource separado del admin ProductResource; test que asserta que cost_price NO está en la respuesta pública |
| Carrito se mezcla entre tenants en el mismo browser | localStorage key incluye tenant slug |
| Variant sin stock se puede agregar al carrito | Frontend deshabilita add-to-cart si available = 0; backend no valida (no hay order en DB) |
| WhatsApp number ausente | Fallback: si tenant no tiene número, el botón checkout muestra "Contacto no configurado" en vez de romper |

---

## Próximos pasos

1. Issues S2-E1 a S2-E8 en GitHub
2. Arrancar S2-E1 (API pública) — desbloquea todo lo demás
3. Después de S2-E1: S2-E2 + S2-E3 + S2-E4 en cadena, luego S2-E5/E6 (carrito+checkout), S2-E7 (SEO), S2-E8 (tests)
