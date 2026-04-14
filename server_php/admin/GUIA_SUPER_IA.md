# Super_IA Logan — Panel Web Supervisor
## Guía de Implementación y Uso

---

## 📋 Archivos Creados/Modificados

### 1. **Configuración y Base de Datos**
- `db_admin_superIA.php` — Clase con todas las consultas SQL
- `kpi_queries.sql` — Consultas avanzadas para reportes
- `admin.css` — Estilos con colores amarillo

### 2. **Páginas Principales**
- `index_supervisor.php` — Dashboard principal del supervisor
- `mis_asesores.php` — Gestión de equipo de asesores
- `reportes.php` — Reportes y KPIs avanzados
- `mapa_vivo_superIA.php` — Mapa en tiempo real con GPS
- `_sidebar.php` — Navegación actualizada

### 3. **API REST**
- `api_super_ia.php` — Endpoints AJAX para operaciones

---

## 🎨 Colores Implementados

```css
Primary:       #FBBF24 (Amarillo)
Secondary:     #F59E0B (Ámbar)
Sidebar:       #92400E → #B45309 (Marrón dorado)
Accent:        #FCD34D (Amarillo claro)
Background:    #FFFBF0 (Crema)
```

---

## 🚀 Cómo Usar

### 1. Dashboard Principal (`index_supervisor.php`)
Muestra:
- 4 Cards: Asesores | Clientes | Tareas | Alertas
- Gráficos: KPIs del mes + Penetración de mercado
- Tabla: Desempeño comparativo de asesores

**Acceso:** `/admin/index_supervisor.php`

### 2. Mi Equipo (`mis_asesores.php`)
- Listar todos los asesores
- Ver estadísticas por asesor
- Editar/Remover asesores
- Crear nuevo asesor

**Acceso:** `/admin/mis_asesores.php`

### 3. Reportes (`reportes.php`)
- Selector de mes/año
- Gráficos de actividades
- Tabla de desempeño detallado
- Opción de imprimir

**Acceso:** `/admin/reportes.php`

### 4. Mapa en Vivo (`mapa_vivo_superIA.php`)
- Ubicaciones GPS en tiempo real
- Actualización cada 30 segundos
- Información del cliente visitado
- Marcadores con gradiente amarillo

**Acceso:** `/admin/mapa_vivo_superIA.php`

---

## 📱 API Endpoints (`api_super_ia.php`)

### GET Requests
```bash
# Obtener asesores
GET /api_super_ia.php?action=get_asesores&page=1&limit=15

# Obtener tareas de hoy
GET /api_super_ia.php?action=get_tareas_hoy

# Obtener alertas pendientes
GET /api_super_ia.php?action=get_alertas

# Obtener KPIs de un período
GET /api_super_ia.php?action=get_kpis&mes=4&anio=2026
```

### POST Requests
```bash
# Marcar alerta como vista
POST /api_super_ia.php
{
  "action": "marcar_alerta_vista",
  "alerta_id": "..."
}

# Eliminar asesor
POST /api_super_ia.php
{
  "action": "eliminar_asesor",
  "asesor_id": "..."
}

# Crear cliente
POST /api_super_ia.php
{
  "action": "crear_cliente",
  "asesor_id": "...",
  "nombre": "Juan Pérez",
  "cedula": "1234567890",
  "telefono": "0987654321",
  "email": "juan@email.com",
  "ciudad": "Quito"
}

# Crear tarea
POST /api_super_ia.php
{
  "action": "crear_tarea",
  "asesor_id": "...",
  "cliente_id": "...",
  "tipo_tarea": "visita_frio",
  "fecha_programada": "2026-04-15",
  "hora_programada": "10:00"
}
```

---

## 🔐 Seguridad

- Todas las operaciones requieren sesión autenticada
- Prepared statements contra SQL injection
- Validación de permisos (supervisor solo ve sus asesores)
- Try-catch para manejo de errores

---

## 📊 Datos Mostrados

### Dashboard
- Asesores activos de este mes
- Total de clientes/prospectos
- Tareas programadas
- Alertas sin revisar

### Reportes
- Prospectos captados
- Visitas en frío realizadas
- Evaluaciones completadas
- Operaciones desembolsadas
- Cumplimiento de metas (%)
- Tiempo promedio: prospección a desembolso

### Comparativas
- Desempeño superior/igual/inferior vs equipo
- Gráfico de penetración por producto
- Tabla de KPI detallada

---

## 🗄️ Tablas BD Requeridas

```sql
-- usuario — Datos de autenticación
-- supervisor — Supervisor con meta_asesores
-- asesor — Asesor con meta_tareas_diarias
-- cliente_prospecto — Clientes/Prospectos
-- tarea — Tareas programadas
-- kpi_asesor — Metrics mensuales
-- alerta_modificacion — Cambios sin revisar
-- ubicacion_asesor — GPS en tiempo real
-- encuesta_comercial — Captación bancaria
-- encuesta_crediticia — Microcrédito
-- credito_proceso — Créditos en gestión
```

---

## 🎯 Flujo de Datos

```
Supervisor Login
    ↓
index_supervisor.php (Dashboard)
    ├→ mis_asesores.php (Equipo)
    ├→ reportes.php (KPIs)
    ├→ mapa_vivo_superIA.php (GPS)
    └→ api_super_ia.php (AJAX ops)
        ├→ Crear cliente
        ├→ Crear tarea
        ├→ Marcar alerta
        └→ Eliminar asesor
```

---

## 📈 Próximas Mejoras

- [ ] Export a PDF de reportes
- [ ] Notificaciones en tiempo real (WebSocket)
- [ ] Gráficos más avanzados (predictivos)
- [ ] Integración con Twilio (SMS alerts)
- [ ] App móvil del supervisor
- [ ] Análisis de rutas optimales
- [ ] Scoring de desempeño

---

## 🐛 Troubleshooting

### "Sesión inválida"
- Verificar que `$_SESSION['supervisor_logged_in']` está activo
- Revisar que `login.php` está configurando la sesión correctamente

### "Sin datos de KPIs"
- Verificar que existen registros en tabla `kpi_asesor`
- Asegurar que la tabla tiene datos del mes actual

### "Mapa no carga"
- Verificar que Leaflet.js está cargado
- Revisar que hay registros en `ubicacion_asesor` con latitud/longitud válidas

### "Error 401 en API"
- Verificar token/sesión en headers
- Asegurar que el usuario está autenticado

---

## 📞 Soporte

Para errores, revisar:
- Logs: `error_log` en PHP
- BD: Query con `describe usuario;`
- Red: Console del navegador (F12)

---

**Versión:** 1.0 - Super_IA Logan  
**Última actualización:** 10 Abril 2026  
**Autor:** Sistema Automático  
