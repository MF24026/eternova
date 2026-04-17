# Arquitectura - Carol Creaciones

## Vision General

Monolito modular con Laravel 12 + Inertia.js + Vue 3. La aplicacion tiene dos caras:
1. **Storefront (publico):** Catalogo digital, carrito, checkout via WhatsApp, reservas
2. **Admin Panel:** Dashboard, POS, inventario, pedidos, gastos, cotizaciones

## Diagrama de Modulos

```
                    +------------------+
                    |   StorefrontLayout   |
                    |  (Catalogo publico)  |
                    +--------+---------+
                             |
        +----------+---------+----------+-----------+
        |          |                    |            |
   Catalog    Cart/Checkout      Reservations   Customer
   (browse)   (WhatsApp)        (custom orders)  (profile)
        |          |                    |            |
        +----------+---------+----------+-----------+
                             |
                    +--------+---------+
                    |    AdminLayout       |
                    |  (Panel de gestion) |
                    +--------+---------+
                             |
   +--------+--------+------+------+--------+--------+
   |        |        |            |        |         |
Dashboard  POS   Inventory    Orders  Expenses  Quotations
           |        |            |        |         |
        Products  Stock      Dispatch   OCR       PDF
        Categories Movements Tracking  Upload   Generate
```

## Modelos de Datos (Entidades Principales)

### Users & Auth
```
users: id, name, email, password, role(admin|staff|customer), avatar, phone
```

### Products & Categories
```
categories: id, name, slug, description, image, parent_id, sort_order, is_active
products: id, name, slug, description, sku, barcode, price, cost_price, 
          category_id, image, gallery(json), is_active, is_featured, 
          min_stock_alert, tax_rate, created_at
```

### Inventory
```
inventory_movements: id, product_id, type(entry|exit|adjustment), 
                     quantity, reference_type, reference_id, 
                     notes, user_id, created_at
```
- Stock actual = SUM(entries) - SUM(exits) +/- adjustments
- Calculated via DB view o cached column en products.current_stock

### Orders
```
orders: id, order_number, customer_id, status(pending|preparing|ready|
        dispatched|delivered|cancelled), subtotal, tax, delivery_fee, 
        total, source(pos|catalog|reservation), payment_method(cash|card|transfer),
        payment_status(pending|partial|paid), notes, 
        shipping_address, tracking_id, dispatched_at, delivered_at
order_items: id, order_id, product_id, quantity, unit_price, total
```

### Reservations
```
reservations: id, customer_id, description, occasion, delivery_date,
              total_amount, deposit_amount, deposit_paid, 
              status(inquiry|confirmed|in_progress|ready|delivered|cancelled),
              special_instructions, admin_notes, created_at
reservation_payments: id, reservation_id, amount, payment_method, 
                      reference, paid_at
```

### Expenses
```
expense_categories: id, name, type(operating|products|payroll|rent|other)
expenses: id, expense_category_id, description, amount, date, 
          receipt_image, ocr_data(json), is_verified, vendor, 
          notes, user_id, created_at
```

### Quotations
```
quotations: id, quotation_number, customer_id, date, valid_until,
            subtotal, tax, total, status(draft|sent|accepted|rejected|expired),
            notes, created_at
quotation_items: id, quotation_id, product_id, description, 
                 quantity, unit_price, total
```

### Customers
```
customers: id, name, email, phone, whatsapp, address, 
           notes, total_purchases, last_purchase_at
```

### Settings (Configuracion del Negocio)
```
settings: id, group, key, value(json), type(string|integer|boolean|json|file), 
          description, created_at, updated_at
```

Grupos de configuracion:
- **brand:** business_name, logo, favicon, slogan, primary_color, secondary_color
- **contact:** phone, whatsapp, email, address, city, state, country
- **locale:** currency_code, currency_symbol, currency_position(before|after), 
              decimal_separator, thousands_separator, date_format, timezone, 
              country_code(CO|MX|AR|etc), phone_length
- **tax:** tax_enabled, tax_name(IVA), tax_rate(19), tax_included_in_price
- **orders:** order_prefix(CC), auto_generate_order_id, whatsapp_message_template
- **quotations:** quotation_prefix(QT), validity_days(7), terms_and_conditions
- **reservations:** deposit_percentage(30), min_advance_days
- **notifications:** whatsapp_notifications, email_notifications

La validacion de telefono se adapta segun country_code:
- CO (Colombia): 10 digitos
- MX (Mexico): 10 digitos
- AR (Argentina): 10-11 digitos
- etc.

Settings se cachean en Redis y se invalidan al actualizar.

## Flujos Principales

### 1. Catalogo -> Carrito -> WhatsApp
1. Cliente navega catalogo publico (sin auth requerido)
2. Agrega productos al carrito (localStorage + Vuex/Pinia)
3. Ve resumen con Order ID generado
4. Click "Enviar por WhatsApp" -> abre wa.me con mensaje formateado
5. Admin recibe pedido y lo registra en el sistema

### 2. POS (Venta Interna)
1. Staff busca productos por nombre/SKU/barcode
2. Agrega al carrito del POS
3. Selecciona cliente (o walk-in)
4. Elige metodo de pago (efectivo/tarjeta)
5. Completa venta -> genera order + descuenta inventario

### 3. Reserva Personalizada
1. Cliente describe su pedido (ej: "ramo de rosas eternas con chocolates")
2. Selecciona fecha de entrega y ocasion
3. Admin revisa, crea cotizacion, pide adelanto (30% default)
4. Se registran pagos parciales hasta completar
5. Se prepara y se entrega

### 4. Gastos con OCR
1. Admin sube foto de factura/recibo
2. OCR extrae: vendor, monto, fecha, items
3. Admin verifica/corrige datos extraidos
4. Se categoriza: operativo, productos, nomina, arriendo
5. Se registra en el ledger

### 5. Cotizaciones PDF
1. Admin selecciona cliente y productos
2. Ajusta cantidades y precios
3. Vista previa en vivo del PDF
4. Exporta PDF o envia por email/WhatsApp
5. Tracking de estado: draft -> sent -> accepted/rejected

## Patrones de Diseno

### Repository Pattern
Cada modulo sigue la estructura:
```
app/Modules/Products/
  Controllers/
    ProductController.php          # Delgado, delega a Service
  Services/
    ProductService.php             # Logica de negocio
  Repositories/
    ProductRepositoryInterface.php # Contrato
    EloquentProductRepository.php  # Implementacion Eloquent
  Models/
    Product.php
  Requests/
    StoreProductRequest.php
    UpdateProductRequest.php
  Resources/
    ProductResource.php
  Providers/
    ProductServiceProvider.php     # Bindings de interfaces
```

**Flujo de datos:**
```
Controller -> Service -> Repository -> Model -> DB
     |            |           |
FormRequest   Business    Query/CRUD
Validation     Logic      Operations
```

**Reglas:**
- Los Controllers NUNCA acceden directamente al Model ni al Repository
- Los Services reciben DTOs o arrays validados, no Request objects
- Los Repositories solo hacen operaciones CRUD y queries
- Las interfaces permiten intercambiar implementaciones (testing, cache)

### PSR Standards
- **PSR-1:** Basic coding standard (PascalCase classes, camelCase methods)
- **PSR-4:** Autoloading via Composer namespaces
- **PSR-12:** Extended coding style (enforced con PHP-CS-Fixer o Pint)
- Strict types declarados en todos los archivos: `declare(strict_types=1);`
- Type hints en todos los parametros y return types
- No usar `mixed` cuando se puede ser especifico

## Seguridad
- Autenticacion via Laravel Sanctum (session-based para SPA)
- Middleware de roles: admin, staff, customer
- CSRF protection via Inertia
- Rate limiting en endpoints publicos
- Validacion de uploads (imagenes max 5MB, solo jpg/png/webp)
- Sanitizacion de input en Form Requests

## Performance
- Eager loading de relaciones en queries
- Cache de dashboard KPIs (Redis, TTL 5min)
- Lazy loading de componentes Vue pesados
- Imagenes optimizadas via Intervention Image
- Paginacion server-side en listados
