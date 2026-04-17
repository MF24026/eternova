# Roadmap - Carol Creaciones

## Metodologia
Desarrollo agil con sprints de 1 semana. Cada sprint entrega funcionalidad completa y testeable. Los agentes trabajan en paralelo por dominio, sincronizandose en los contratos de datos (migraciones y modelos).

---

## Sprint 0 - Fundacion (Prerequisito)
**Objetivo:** Proyecto funcional con Docker, auth, layouts base y design system.

- [x] Crear proyecto Laravel 12 con Sail
- [ ] Configurar Docker (MySQL, Redis, PHP 8.4)
- [ ] Instalar Vue 3 + Inertia.js + Tailwind CSS 4
- [ ] Configurar Vite con HMR
- [ ] Instalar Lucide Icons para Vue
- [ ] Implementar design system "Ethereal Boutique" (tokens Tailwind)
- [ ] Crear AdminLayout (sidebar, topbar, responsive)
- [ ] Crear StorefrontLayout (navbar, footer, responsive)
- [ ] Auth: login, registro, roles (admin/staff/customer)
- [ ] Dark mode toggle (class-based strategy)
- [ ] Componentes base: Button, Input, Card, Slideover, Table, Badge, Modal
- [ ] Migraciones base: users, categories, products, customers

**Entregable:** App corriendo en Docker con login funcional y layouts navegables.

---

## Sprint 1 - Productos e Inventario
**Objetivo:** CRUD completo de productos con gestion de stock.

- [ ] CRUD Categorias (con subcategorias, drag-sort)
- [ ] CRUD Productos (nombre, SKU, precio, costo, imagen, galeria)
- [ ] Upload de imagenes con preview y crop
- [ ] Listado de inventario con busqueda y filtros
- [ ] Movimientos de stock: entradas, salidas, ajustes
- [ ] Alertas de stock bajo
- [ ] Panel lateral de movimientos recientes
- [ ] Seeders con datos de ejemplo

**Entregable:** Gestion completa de productos e inventario desde el admin.

---

## Sprint 2 - Catalogo Digital y Carrito
**Objetivo:** Storefront publico con carrito y checkout WhatsApp.

- [ ] Landing/Hero del catalogo con colecciones destacadas
- [ ] Grid de productos con filtros por categoria
- [ ] Vista detalle de producto
- [ ] Carrito de compras (Pinia + localStorage)
- [ ] Resumen de pedido con Order ID
- [ ] Boton "Enviar por WhatsApp" con mensaje formateado
- [ ] Responsive mobile-first
- [ ] Bloom Chips para categorias

**Entregable:** Catalogo navegable donde clientes pueden armar pedidos y enviarlos por WhatsApp.

---

## Sprint 3 - POS (Punto de Venta)
**Objetivo:** Terminal de venta interna para el negocio.

- [ ] Interfaz POS con grid de productos y carrito lateral
- [ ] Busqueda rapida por nombre/SKU
- [ ] Filtros por categoria (tabs)
- [ ] Selector de cliente (walk-in o registrado)
- [ ] Metodos de pago: efectivo, tarjeta
- [ ] Completar checkout -> crear order + descontar stock
- [ ] Registro de adelantos de reservas desde POS
- [ ] Receipt/ticket basico

**Entregable:** POS funcional para ventas presenciales.

---

## Sprint 4 - Pedidos y Despachos
**Objetivo:** Gestion completa del ciclo de vida de pedidos.

- [ ] Listado de pedidos con tabs de estado
- [ ] Vista detalle de pedido con timeline
- [ ] Cambio de estados: pending -> preparing -> ready -> dispatched -> delivered
- [ ] Panel lateral de despacho (direccion, tracking)
- [ ] Notificacion al cliente (placeholder para WhatsApp API)
- [ ] Filtros y busqueda de pedidos
- [ ] Acciones rapidas: Mark Ready, Notify Client

**Entregable:** Seguimiento completo de pedidos desde creacion hasta entrega.

---

## Sprint 5 - Reservas Personalizadas
**Objetivo:** Sistema de reservas con adelantos y seguimiento.

- [ ] Formulario de reserva (cliente): descripcion, fecha, ocasion
- [ ] Listado de reservas (admin)
- [ ] Registro de adelantos/pagos parciales
- [ ] Estados: inquiry -> confirmed -> in_progress -> ready -> delivered
- [ ] Integracion desde catalogo ("Reservar Pieza")
- [ ] Integracion desde POS ("Reservation" tab)
- [ ] Historial de pagos por reserva

**Entregable:** Clientes pueden solicitar arreglos personalizados y el admin gestiona los adelantos.

---

## Sprint 6 - Gastos y Finanzas
**Objetivo:** Control de gastos con OCR e ingreso manual.

- [ ] CRUD de gastos con categorias (operativos, productos, nomina, arriendo)
- [ ] Upload de recibos/facturas
- [ ] OCR con Tesseract: extraccion de vendor, monto, fecha
- [ ] Vista de transcripcion digital con estado de procesamiento
- [ ] KPI cards: total gastos, nomina, arriendo
- [ ] Tabla de transacciones con filtros
- [ ] Ingreso manual cuando no hay factura
- [ ] Paginacion y busqueda

**Entregable:** Control financiero de todos los gastos del negocio.

---

## Sprint 7 - Cotizaciones y PDF
**Objetivo:** Generar cotizaciones profesionales con preview y exportacion PDF.

- [ ] Crear cotizacion: seleccionar cliente, fecha, productos
- [ ] Agregar items con cantidad y precio
- [ ] Vista previa en vivo del PDF (panel lateral)
- [ ] Exportar a PDF (DomPDF)
- [ ] Enviar por email
- [ ] Listado de cotizaciones con estados
- [ ] Cotizaciones recientes

**Entregable:** Cotizaciones profesionales con PDF branded de Carol Creaciones.

---

## Sprint 8 - Dashboard y Analytics
**Objetivo:** Panel principal con KPIs y metricas del negocio.

- [ ] KPI Cards: ventas totales, pedidos activos, alertas stock, gastos
- [ ] Grafico de ventas semanales (Chart.js o similar)
- [ ] Entregas del dia con estados
- [ ] Notas rapidas
- [ ] Resumen de rendimiento vs metas
- [ ] Export de datos

**Entregable:** Vista ejecutiva del estado del negocio.

---

## Sprint 9 - Polish y Produccion
**Objetivo:** Pulir UX, testing, y preparar para produccion.

- [ ] Tests de integracion para flujos criticos
- [ ] Optimizacion de queries (N+1, indexes)
- [ ] PWA manifest para mobile
- [ ] SEO basico en catalogo
- [ ] Error handling global
- [ ] Loading states y skeleton screens
- [ ] Validacion final de responsive en mobile
- [ ] Documentacion de deploy
