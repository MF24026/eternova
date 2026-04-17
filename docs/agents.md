# Guia de Agentes - Carol Creaciones

## Metodologia de Trabajo

Los agentes trabajan como un equipo de desarrollo profesional siguiendo metodologia agil:
- Cada agente es responsable de su dominio funcional
- Los agentes consultan `CLAUDE.md` para convenciones, `docs/architecture.md` para contratos de datos
- Cada agente debe respetar el design system documentado en `docs/features/.../pastel_bloom_gilt/DESIGN.md`
- Los modelos y migraciones son el contrato compartido entre agentes
- Commits en formato convencional: `feat|fix|refactor(scope): message`

## Orden de Ejecucion

```
Sprint 0: Agent 1 (Foundation) -- DEBE IR PRIMERO, SOLO
              |
              v
Sprints 1-7: Agents 2-5 en PARALELO
              |
              v  
Sprint 8-9:  Agent 1 (Dashboard + Polish)
```

---

## Agent 1: Foundation & Dashboard
**Dominio:** Infraestructura, auth, layouts, design system, dashboard, polish final

### Responsabilidades Sprint 0:
- Crear proyecto Laravel 12 con Sail (PHP 8.4, MySQL 8, Redis)
- Configurar docker-compose.yml basado en compose-example.yml
- Instalar y configurar Vue 3 + Inertia.js + Vite
- Instalar Tailwind CSS 4 con tokens del design system Ethereal Boutique
- Instalar Lucide Icons para Vue
- Crear layouts: AdminLayout (sidebar responsive) y StorefrontLayout (navbar + footer)
- Auth completo: login, registro, roles (admin/staff/customer) con Laravel Breeze/Fortify
- Componentes base reutilizables: Button, Input, Card, Slideover (con swipe-close), Table, Badge, Modal, Dropdown
- Dark mode con class-based strategy
- Todas las migraciones base del sistema (ver architecture.md)
- Seeders iniciales

### Responsabilidades Sprint 8:
- Dashboard administrativo con KPI cards
- Graficos de ventas (Chart.js)
- Entregas del dia
- Widget de resumen general

---

## Agent 2: Products & Inventory
**Dominio:** Todo lo relacionado con productos, categorias y stock

### Responsabilidades:
- CRUD de categorias con subcategorias y ordenamiento
- CRUD de productos completo (nombre, SKU, barcode, precio, costo, imagenes)
- Upload de imagenes con preview (Intervention Image para resize)
- Galeria de producto (multiples imagenes)
- Sistema de inventario: movimientos de entrada, salida, ajuste
- Calculo de stock actual por producto
- Alertas de stock bajo
- Timeline de movimientos recientes
- Busqueda y filtros en listados
- Paginacion server-side
- Seeders con productos de ejemplo (rosas, carteras, peluches, llaveros)

### Vistas Admin:
- `/admin/categories` - Listado y CRUD de categorias
- `/admin/products` - Listado, crear, editar productos
- `/admin/inventory` - Gestion de stock y movimientos

---

## Agent 3: Storefront & Cart
**Dominio:** Catalogo publico, carrito de compras, checkout WhatsApp, reservas del cliente

### Responsabilidades:
- Landing page del catalogo con hero section y colecciones
- Grid de productos con filtros por categoria (Bloom Chips)
- Vista detalle de producto
- Carrito de compras con Pinia (persistido en localStorage)
- Pagina de carrito con resumen y ajuste de cantidades
- Generacion de Order ID unico
- Boton "Enviar por WhatsApp" con mensaje formateado (wa.me link)
- Formulario de reserva personalizada (cliente-side)
- Responsive mobile-first
- Newsletter subscription (UI only)

### Vistas Storefront:
- `/` - Landing del catalogo
- `/catalog` - Grid de productos con filtros
- `/catalog/{slug}` - Detalle de producto
- `/cart` - Carrito y checkout
- `/reservations/create` - Formulario de reserva

---

## Agent 4: POS & Orders
**Dominio:** Punto de venta interno y gestion de pedidos

### Responsabilidades POS:
- Interfaz POS con grid de productos a la izquierda y carrito a la derecha
- Busqueda rapida por nombre/SKU/barcode
- Filtros por categoria (tabs horizontales)
- Selector de cliente (walk-in o busqueda)
- Seleccion de metodo de pago (efectivo, tarjeta)
- Checkout que crea order y descuenta stock automaticamente
- Tab de reserva desde POS (registrar adelanto)

### Responsabilidades Orders:
- Listado de pedidos con tabs por estado (All, Pending, Preparing, Dispatched, Delivered)
- Vista detalle de pedido con timeline de estados
- Cambio de estado con acciones rapidas (Mark Ready, Notify Client)
- Panel lateral de despacho (Shipping Details)
- Busqueda y filtros
- Tracking ID

### Vistas Admin:
- `/admin/pos` - Terminal punto de venta
- `/admin/orders` - Listado de pedidos
- `/admin/orders/{id}` - Detalle de pedido

---

## Agent 5: Finance (Expenses, Quotations, Reservations Admin)
**Dominio:** Gastos, cotizaciones PDF y gestion de reservas desde admin

### Responsabilidades Expenses:
- CRUD de categorias de gasto (operativos, productos, nomina, arriendo)
- Registro de gastos manual y con recibo
- Upload de imagen de factura/recibo
- OCR con Tesseract: extraccion automatica de datos
- Vista de "Digital Transcription" con progreso
- KPI cards: total gastos, nomina, arriendo
- Tabla de transacciones con filtros y paginacion
- Verificacion de gastos extraidos por OCR

### Responsabilidades Quotations:
- Crear cotizacion: buscar cliente, seleccionar fecha
- Agregar productos a la cotizacion con cantidad y precio
- Vista previa en vivo del PDF (panel derecho)
- Generacion de PDF con DomPDF (branded Carol Creaciones)
- Envio por email
- Listado de cotizaciones con estados (draft, sent, accepted, rejected)

### Responsabilidades Reservations (Admin):
- Listado de reservas con estados
- Detalle de reserva con historial de pagos
- Registrar pagos/adelantos parciales
- Cambiar estado de reserva
- Notas del admin

### Vistas Admin:
- `/admin/expenses` - Gestion de gastos
- `/admin/quotations` - Listado y creacion de cotizaciones
- `/admin/quotations/create` - Nueva cotizacion con preview PDF
- `/admin/reservations` - Gestion de reservas

---

## Reglas para TODOS los Agentes

1. **Leer CLAUDE.md** antes de escribir cualquier codigo
2. **Leer docs/architecture.md** para modelos y relaciones
3. **Leer docs/features/.../pastel_bloom_gilt/DESIGN.md** para el design system
4. **Revisar los screenshots** en docs/features/ para cada modulo que implementen
5. **No usar emojis** en la UI, solo Lucide Icons
6. **Composition API** con `<script setup>` en todos los componentes Vue
7. **Responsive** mobile-first en todas las vistas
8. **Dark mode** debe funcionar en todas las vistas
9. **Slideovers** con swipe-to-close donde corresponda
10. **Tailwind CSS** usando los tokens del design system (no colores arbitrarios)
11. **Form Requests** para validacion en el backend
12. **No hacer push** sin confirmacion del usuario
13. **Commits** en formato convencional en ingles
14. **Repository Pattern** obligatorio: Interface + EloquentRepository + Service + Controller
15. **PSR-12** coding style, `declare(strict_types=1)` en todos los archivos PHP
16. **Type hints** en todos los parametros y return types, no usar `mixed`
17. **Controllers delgados** -> Service -> Repository -> Model (nunca saltar capas)
