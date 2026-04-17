# Carol Creaciones - Software de Gestion de Negocio

## Proyecto
Sistema integral de gestion para negocio de arreglos florales, accesorios y regalos (carteras, peluches, rosas, llaveros, etc.). Incluye catalogo digital, POS, inventario, pedidos, reservas, gastos con OCR, cotizaciones PDF y dashboard administrativo.

## Stack Tecnologico
- **Backend:** Laravel 12.x (PHP 8.4)
- **Frontend:** Vue.js 3.x + Inertia.js
- **CSS:** Tailwind CSS 4.x
- **Base de datos:** MySQL 8.0
- **Cache/Queue:** Redis
- **Contenedores:** Docker via Laravel Sail
- **Iconos:** Lucide Icons (consistente, limpio, sin emojis)
- **Fuentes:** Noto Serif (headlines) + Plus Jakarta Sans (body)
- **PDF:** DomPDF / Browsershot para cotizaciones
- **OCR:** Tesseract OCR via API para facturas de gastos

## Arquitectura
- Monolito modular con Laravel + Inertia.js + Vue 3
- SPA-like con SSR opcional
- API interna via Inertia (no REST separado para el frontend)
- Modulos organizados por dominio en app/Modules/
- Ver `docs/architecture.md` para detalles completos

## Estructura de Directorios
```
app/
  Modules/
    Auth/          # Autenticacion y roles
    Dashboard/     # Panel administrativo y KPIs
    Products/      # Productos, categorias, imagenes
    Inventory/     # Stock, entradas, salidas, alertas
    Catalog/       # Catalogo publico, carrito, checkout WhatsApp
    POS/           # Punto de venta interno
    Orders/        # Pedidos, seguimiento, despachos
    Reservations/  # Reservas personalizadas, adelantos
    Expenses/      # Gastos, OCR, categorias de gasto
    Quotations/    # Cotizaciones, generacion PDF
    Customers/     # Clientes y datos de contacto
    Settings/      # Configuracion del negocio
resources/
  js/
    Components/    # Componentes Vue reutilizables
    Layouts/       # AdminLayout, StorefrontLayout
    Pages/         # Paginas por modulo (Admin/, Storefront/)
    Composables/   # Logica reactiva compartida
  css/
    app.css        # Tailwind + design tokens
```

## Convenciones de Codigo

### Backend (Laravel)
- **Repository Pattern:** Toda la logica de acceso a datos va en Repositories
  - Interface en `app/Modules/{Module}/Repositories/{Model}RepositoryInterface.php`
  - Implementacion en `app/Modules/{Module}/Repositories/Eloquent{Model}Repository.php`
  - Binding en el ServiceProvider del modulo
- Controladores delgados -> llaman Services -> Services llaman Repositories
- Services contienen logica de negocio, Repositories solo acceso a datos
- Form Requests para validacion
- API Resources para transformar respuestas
- Policies para autorizacion
- Observers para efectos secundarios del modelo
- Migrations con rollback funcional
- Seeders con datos realistas para desarrollo
- **PSR Compliance:**
  - PSR-1: Basic Coding Standard
  - PSR-4: Autoloading (ya por defecto en Laravel)
  - PSR-12: Extended Coding Style (enforced via PHP-CS-Fixer)
  - PSR-7/PSR-18: HTTP interfaces donde aplique

### Frontend (Vue 3)
- Composition API exclusivamente (no Options API)
- `<script setup>` en todos los componentes
- Props tipados con defineProps
- Componentes en PascalCase
- Slideovers con soporte swipe-to-close via touch events
- Dark mode con clase CSS strategy (class-based)
- NO usar emojis en ninguna parte de la UI
- Lucide Icons para toda iconografia

### CSS / Design System
- Tailwind CSS con tokens del design system "Ethereal Boutique"
- Paleta pastel: surface #fff8f7, primary #7c545d, secondary #5a4b71
- Sin bordes de 1px (regla "No-Line") - separar con fondos
- Esquinas redondeadas lg (1rem) o xl (1.5rem) minimo
- Sombras difusas tipo "ambient glow", nunca drop-shadow duro
- Texto nunca en negro puro - usar on-surface #3d2f32
- Gradientes signature: primary a primary-container a 135deg
- Glassmorphism para navegacion flotante

### Git
- **Repositorio:** https://github.com/MF24026/carol-creaciones.git
- Commits en ingles, formato convencional: feat|fix|refactor|docs(scope): message
- **PROHIBIDO** incluir Co-Authored-By de Claude o cualquier IA en los commits
- **Branching strategy:** Git Flow simplificado
  - `main` - produccion, solo merge via PR
  - `develop` - rama de integracion, base para features
  - `feature/{module-name}` - nuevas funcionalidades (ej: feature/products, feature/pos)
  - `fix/{issue-description}` - correccion de bugs
  - `hotfix/{description}` - fixes urgentes directo a main
- Crear rama desde `develop` antes de trabajar en cualquier feature
- No hacer push sin confirmar con el usuario
- No hacer merge a main sin PR revisado

### Docker
- Todo se ejecuta via Laravel Sail
- `./vendor/bin/sail up -d` para levantar
- `./vendor/bin/sail artisan` para comandos Artisan
- `./vendor/bin/sail npm` para comandos npm

## Comandos Frecuentes
```bash
# Levantar entorno
./vendor/bin/sail up -d

# Migraciones
./vendor/bin/sail artisan migrate

# Seeders
./vendor/bin/sail artisan db:seed

# Frontend dev
./vendor/bin/sail npm run dev

# Tests
./vendor/bin/sail artisan test

# Linting
./vendor/bin/sail npm run lint
```

## Reglas Importantes
- Responsive web design en todas las vistas (mobile-first)
- Slideovers en lugar de modales donde sea posible, con swipe-to-close
- Dark mode en toda la aplicacion
- Sin emojis en la interfaz - usar Lucide Icons
- Moneda, pais, telefono, logo, nombre de marca: TODO configurable desde modulo Settings
- Validacion de telefono dinamica segun pais configurado
- Idioma de la UI: Espanol
- Idioma del codigo: Ingles
