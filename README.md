# Panel Financiero — PHP nativo + Tailwind CDN + JS Vanilla

Dashboard para pequeños negocios de **remodelación, construcción y limpieza**
(mercado EE. UU., montos en dólares USD, interfaz en español).

## Roles de usuario

| Rol | Qué ve |
|-----|--------|
| **Dueño** (`dueno`) | Modo Resumen (semáforo, ingresos/gastos/utilidad) y una **lista básica** de sus movimientos, sin alertas ni análisis. |
| **Superadmin** (`superadmin`) | Todo lo anterior, más el **Detalle de auditoría** (gastos atípicos, margen, filtros) y el módulo **Auditoría y capacitación**: KPIs y hallazgos (peligros, alertas y buenas prácticas) para exponerle al dueño sus falencias, con recomendaciones accionables. |

Cuentas creadas automáticamente en la primera visita a `login.php`:

- Dueño: `dueno@demo.com` / `dueno123`
- Superadmin: `super@demo.com` / `super123`

> El desarrollador (tú) tiene acceso al código y a la base de datos por fuera
> de la aplicación; el superadmin es un usuario con perfil de administración
> y finanzas, sin acceso técnico.

## Estructura de archivos

```
finanzas_dashboard/
├── login.php                  # Inicio de sesión (crea usuarios demo la 1ª vez)
├── logout.php                 # Cierra sesión
├── index.php                  # Dashboard (Resumen + Movimientos/Detalle según rol)
├── modulo.php                 # Router de módulos del menú lateral
├── db.php                     # Conexión PDO + moneda (USD)
├── includes/
│   ├── auth.php               # Sesión, roles y guardas de acceso
│   ├── functions.php          # Lógica de negocio + analizarNegocio() del superadmin
│   ├── sidebar.php            # Menú lateral (Auditoría solo para superadmin)
│   └── modulos/
│       ├── facturas.php       # Facturación (número auto, edición, clientes del directorio)
│       ├── planilla.php       # Colaboradores
│       ├── clientes.php       # Clientes / Proveedores (con edición)
│       ├── reportes.php       # Gráficas (Chart.js)
│       ├── auditoria.php      # SOLO superadmin: KPIs + hallazgos
│       └── configuracion.php  # Datos del negocio y tema
├── api/
│   ├── add_transaction.php    # AJAX: crear/editar/eliminar movimientos
│   ├── get_resumen.php        # AJAX: refresca el Resumen
│   ├── facturas.php           # CRUD de facturas (POST normal)
│   ├── clientes.php           # CRUD de contactos
│   ├── planilla.php           # CRUD de colaboradores
│   └── configuracion.php      # Guarda configuración
├── assets/
│   ├── css/styles.css         # Sistema de diseño (tokens, login, hallazgos)
│   └── js/                    # main.js, modulos.js
├── sql/
│   ├── schema.sql             # BD completa con datos de ejemplo (USD)
│   └── migracion_v2.sql       # Solo instalaciones anteriores
└── README.md
```

## Instalación local con XAMPP

1. Copia la carpeta dentro de `htdocs`.
2. Enciende **Apache** y **MySQL**.
3. Importa `sql/schema.sql` desde phpMyAdmin
   (si ya tenías una BD anterior, importa `sql/migracion_v2.sql` en su lugar).
4. Revisa credenciales en `db.php` (por defecto `root` sin contraseña).
5. Abre `http://localhost/finanzas_dashboard/login.php` y entra con las
   cuentas de ejemplo.

## Módulos

- **Dashboard**: semáforo del negocio (margen ≥20% verde, ≥5% amarillo,
  menos de eso rojo) + registro rápido de movimientos.
- **Movimientos / Detalle**: el dueño ve su lista simple; el superadmin ve
  la tabla de auditoría con gastos atípicos (>1.5× el promedio), margen y
  filtros. Ambos pueden editar y eliminar movimientos.
- **Facturas**: número autogenerado (`F-0005`…), autocompletado de clientes
  desde el directorio (queda vinculada a la ficha), edición, validación de
  número duplicado y fechas, y **al marcar "pagada" crea el ingreso
  automáticamente** (y lo elimina si se desmarca o se borra la factura).
- **Clientes / Proveedores**: crear, editar y eliminar; total facturado por
  cliente; gastos periódicos con suma mensual equivalente.
- **Planilla**: colaboradores por hora, quincena o mes. Los pagos del mes se
  materializan como gastos **Nómina** y entran a Reportes / dashboard /
  Gastos (desglose mensual · quincenal · por hora).
- **Reportes**: ingresos vs gastos, pastel por categoría, utilidad mensual,
  filtro por cliente (proyecto activo) y detalle de planilla por frecuencia.
- **Auditoría y capacitación** (superadmin): KPIs (margen, cartera por
  cobrar, ticket promedio, personal/ingresos, días sin registrar…) y una
  lista de hallazgos ordenada por severidad con recomendación para cada uno,
  más una guía de cómo conducir la capacitación con el dueño.
- **Configuración**: nombre del negocio, datos de contacto y tema.

## Notas de diseño / decisiones

- **Moneda**: USD (`const MONEDA` en `db.php`).
- **Categorías** alineadas al rubro: ingresos de Remodelación / Construcción /
  Limpieza; gastos de Materiales, Nómina, Contratistas, Transporte, Seguros y
  permisos, Servicios públicos, Marketing y Otros gastos (se ajustan en
  `includes/functions.php`, `categoriasDisponibles()`).
- **Seguridad**: contraseñas con `password_hash` (bcrypt), sesión con cookie
  `HttpOnly` + `SameSite=Lax`, regeneración de id al entrar, consultas
  preparadas (PDO) en todo, y guardas de rol en páginas y endpoints.
- **Migración suave**: `asegurarEsquemaAuth()` crea la tabla `usuarios`,
  agrega las columnas nuevas de `facturas` / `transacciones` (incl. origen
  planilla) y siembra los usuarios demo si la tabla está vacía, sin pasos
  manuales.

## Próximos pasos sugeridos

- Tokens CSRF en todos los formularios.
- Multi-negocio (SaaS): tabla `negocios` + `negocio_id` en todas las tablas.
- Usuarios adicionales por negocio (contador, gerente) con permisos propios.
- Exportar el detalle y los reportes a Excel/PDF.
- Recordatorios de cobro para facturas por vencer.
- Enviar facturas por correo electrónico.
