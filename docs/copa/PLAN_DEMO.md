# Plan de Demo — Grabacion COPA 2026

> Coreografia de pantallas que Claude ejecuta en el navegador (Playwright MCP) mientras vos grabas la ventana con OBS Studio. Sincronizado con `GUION_NARRACION.md`.

---

## Preparacion ANTES de grabar (checklist)

1. **Entorno levantado:**
   ```bash
   echo "developer" | sudo -S ./vendor/bin/sail up -d
   echo "developer" | sudo -S ./vendor/bin/sail npm run dev   # Vite en background
   ```
   Verificar: `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080` → 200

2. **Datos demo presentes:** 1 tenant (Atelier Demo), 3 planes, 1 usuario (`carolina@eternova.app` / `password`). Si falta:
   ```bash
   echo "developer" | sudo -S ./vendor/bin/sail artisan db:seed --class=TenancySeeder
   ```

3. **Navegador:** Claude controla un Chrome via Playwright MCP a **1440x900** (desktop). Esa es la ventana que grabas con OBS.

4. **OBS:** capturar SOLO la ventana del Chrome de Playwright (Window Capture). Resolucion de salida 1920x1080 o 1280x720.

5. **Modo:** arrancar en **light mode**. (Opcional: mostrar dark mode en el cierre como bonus visual.)

6. **Limpiar la pantalla:** sin pestanas extra, sin barra de marcadores visible si se puede.

---

## Flujo de pantallas (coreografia)

Cada paso indica: **bloque del guion**, **URL/accion**, y **que se ve**. Claude espera tu senal para avanzar (o lo corremos de corrido con pausas fijas).

| # | Bloque guion | Accion en navegador | Que se ve | Tiempo aprox |
|---|---|---|---|---|
| 1 | BLOQUE 0 | Navegar a `/login` | Login con petalos + marca Eternova | 0:00–0:20 |
| 2 | BLOQUE 1 | Mostrar login, luego ir a `/admin/dashboard` | Transicion al panel | 0:20–0:45 |
| 3 | BLOQUE 2 | Permanecer en `/admin/dashboard`, scroll suave | KPIs + grafica + entregas | 0:45–1:15 |
| 4 | BLOQUE 3 | Navegar a `/admin/pos`, agregar 2–3 productos al carrito | Split-view, total sube, metodos de pago | 1:15–1:45 |
| 5 | BLOQUE 4 | Navegar a `/admin/expenses`, click "Nuevo gasto" → mostrar panel OCR | Lista de gastos + panel "Subir factura" | 1:45–2:20 |
| 6 | BLOQUE 5 | Navegar a `/` (tienda), entrar a un producto, abrir carrito | Storefront + producto + carrito WhatsApp | 2:20–2:55 |
| 7 | BLOQUE 6 | Navegar a `/admin/settings`, mostrar pestanas | Configuracion de marca/localizacion | 2:55–3:25 |
| 8 | BLOQUE 7 | Volver a `/admin/dashboard` o `/login` | Imagen estable de cierre | 3:25–3:50 |

---

## Detalle de interacciones por paso

### Paso 1 — Login (BLOQUE 0)
- `browser_navigate` → `http://localhost:8080/login`
- Dejar la imagen quieta ~3s para que se vean los petalos animados.

### Paso 2 — Entrada al panel (BLOQUE 1)
- Opcion A (real): escribir credenciales y hacer login real.
- Opcion B (demo limpia, recomendada): `browser_navigate` directo a `/admin/dashboard` (evita mostrar el typing de password).

### Paso 3 — Dashboard (BLOQUE 2)
- `browser_navigate` → `/admin/dashboard`
- Scroll lento de arriba a abajo: KPIs → grafica → entregas → mas vendidos → actividad reciente.
- Resaltar visualmente (mover el cursor) sobre los 4 KPI cards.

### Paso 4 — POS (BLOQUE 3)
- `browser_navigate` → `/admin/pos`
- Click en 2–3 tarjetas de producto del grid → se agregan al carrito de la derecha.
- Mostrar como sube el Subtotal → IVA → Total.
- Pasar el cursor por los metodos de pago (Efectivo / Tarjeta / Transferencia).
- NO hacer click en "Cobrar" (es mock, no queremos que falle en vivo).

### Paso 5 — Gastos + OCR (BLOQUE 4)
- `browser_navigate` → `/admin/expenses`
- Mostrar la lista filtrable + el panel de presupuesto a la derecha (~3s).
- Click en "Nuevo gasto" → se abre el panel lateral "Captura inteligente / Subir factura".
- Mostrar el dropzone (no subir archivo real; el flujo OCR es simulado).
- Cerrar el panel.

### Paso 6 — Tienda publica (BLOQUE 5)
- `browser_navigate` → `/` (storefront Home)
- Scroll por el hero + categorias + grid de productos.
- Click en un producto → `/product/p1` (detalle).
- Click en "Anadir al carrito".
- Abrir el carrito (icono bolsa arriba a la derecha) → mostrar el boton "Confirmar por WhatsApp".
- NO hacer click en WhatsApp (abriria una app externa en vivo).

### Paso 7 — Ajustes / Modelo SaaS (BLOQUE 6)
- `browser_navigate` → `/admin/settings`
- Click entre las pestanas: Marca → Localizacion → Impuestos.
- Mostrar que el negocio personaliza su nombre, moneda, pais.

### Paso 8 — Cierre (BLOQUE 7)
- `browser_navigate` → `/admin/dashboard` (o `/login` para terminar con la marca).
- Imagen estable. Fin.

### Bonus opcional — Dark mode
- En cualquier vista admin, click en el icono de luna (topbar) → toda la UI cambia a modo oscuro.
- Buen recurso visual de "calidad de producto" si sobra tiempo.

---

## Modo de ejecucion (como lo corremos juntos)

**Opcion 1 — Paso a paso (recomendada para primera toma):**
Vos arrancas OBS, me decis "dale" y yo ejecuto el Paso 1. Cuando termines de narrar ese bloque, me decis "siguiente" y avanzo. Control total, cero apuro.

**Opcion 2 — Corrido con pausas fijas:**
Me decis "corre la demo completa" y yo ejecuto los 8 pasos con pausas de ~25–30s entre cada uno. Vos narras encima en tiempo real. Mas fluido pero requiere que calcemos el ritmo.

**Opcion 3 — Sin audio en vivo:**
Yo corro la demo completa, vos solo grabas el video. Despues grabas el audio por separado leyendo el guion y los unis en edicion. La mas segura para que quede perfecto.

> **Recomendacion:** Opcion 3. Grabas el video del recorrido limpio, y el audio lo grabas tranquilo despues leyendo `GUION_NARRACION.md`. En edicion (CapCut, DaVinci, etc.) los unis. Asi si te trabas narrando no arruinas la toma del sistema.

---

## Que NO hacer en vivo (para evitar errores en camara)

- NO hacer click en "Cobrar" del POS (mock).
- NO hacer click en "Confirmar por WhatsApp" (abre app externa).
- NO subir una factura real en el OCR (el flujo es simulado, podria quedar colgado).
- NO entrar a vistas con bugs si los hubiera — nos quedamos en las verificadas: Login, Dashboard, POS, Gastos, Settings, Storefront Home, Product Detail.

## Vistas verificadas y listas para camara

- ✅ `/login` — Login Eternova
- ✅ `/` — Storefront Home
- ✅ `/product/p1` — Detalle de producto
- ✅ `/admin/dashboard` — Panel con KPIs + grafica
- ✅ `/admin/pos` — Punto de venta
- ✅ `/admin/expenses` — Gastos + OCR
- ✅ `/admin/settings` — Configuracion
- ✅ Dark mode en todas las admin
