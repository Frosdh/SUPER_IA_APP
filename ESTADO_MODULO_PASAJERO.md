# 📊 ESTADO ACTUAL — MÓDULO PASAJERO FUBER

**Generado:** 11 de Marzo, 2026
**Versión:** Post-Fase 2 (Sistema de Cupones completado)
**Base de datos:** fuber_db (MariaDB 10.1.37)

---

## 📈 RESUMEN GENERAL

```
✅ Implementado:    16 funciones
🔄 En plan:        1 funciones  
⬜ Pendiente:      22 funciones
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Total cubierto:     32% de 49 funciones
```

---

## 🔐 CUENTA Y PERFIL

| Estado | Funcionalidad | Detalles | Archivo |
|--------|---------------|----------|---------|
| ✅ | Registro por teléfono | OTP vía WhatsApp (hardcoded: 1234) | SignIn.dart |
| ✅ | Inicio de sesión | Login con sesión persistente | SignIn.dart |
| ✅ | Editar nombre y correo | Pantalla funcional | EditProfileScreen.dart |
| 🔄 | Foto de perfil | Implementado pero con limitaciones en UI | EditProfileScreen.dart |
| ⬜ | Cambiar teléfono | No implementado |  |
| ⬜ | Verificación de identidad | Pendiente (no en MVP) |  |
| ⬜ | Eliminar cuenta | No implementado |  |

**Estado:** ✅ **85% completo** — Falta: cambiar teléfono, verificación de identidad, eliminar cuenta

---

## 🗺️ ANTES DEL VIAJE

| Estado | Funcionalidad | Detalles | Archivo |
|--------|---------------|----------|---------|
| ✅ | Mapa con ubicación actual | GPS funcional, OpenStreetMap | OsmMapScreen.dart |
| ✅ | Buscar destino por texto | Autocompletado Google Places + Nominatim | OsmMapScreen.dart |
| ✅ | Categorías de vehículo | Fuber-X, Fuber-Plus, Fuber-Moto | OsmMapScreen.dart |
| ✅ | Calcular precio estimado | Basado en distancia, tiempo y categoría | OsmMapScreen.dart |
| ✅ | Confirmación antes de pedir | Bottom sheet con desglose | OsmMapScreen.dart |
| ✅ | Lugares favoritos | Casa y Trabajo guardados | FavoritePlacesScreen.dart |
| ✅ | Historial de destinos recientes | Últimas búsquedas guardadas | OsmMapScreen.dart |
| ⬜ | Ver conductores cercanos | No implementado (data simulada) |  |
| ⬜ | Múltiples paradas | No implementado |  |
| ⬜ | Ajustar punto de recogida | No implementado (draggable marker) |  |
| ⬜ | Programar viaje futuro | No implementado |  |
| ⬜ | Pedir viaje para otro | No implementado |  |

**Estado:** ✅ **64% completo** — Falta: conductores cercanos, múltiples paradas, ajustar punto, programar futuro, pedir para otro

---

## 🚗 DURANTE EL VIAJE

| Estado | Funcionalidad | Detalles | Archivo |
|--------|---------------|----------|---------|
| ✅ | Info del conductor | Nombre, vehículo, calificación | OnGoingRideScreen.dart |
| ✅ | Ruta en el mapa | Polilínea calculada OSM | OsmMapScreen.dart |
| ✅ | Cancelar viaje | Con confirmación | OsmMapScreen.dart |
| 🔄 | Botón SOS / emergencia | Implementado, envía por WhatsApp | OsmMapScreen.dart |
| 🔄 | Compartir viaje | Implementado, envía por WhatsApp | OsmMapScreen.dart |
| ⬜ | Chat con conductor | No implementado |  |
| ⬜ | Llamar al conductor | No implementado |  |
| ⬜ | Verificación PIN | No implementado |  |

**Estado:** ✅ **75% completo** — Falta: chat, llamada, PIN

---

## ✅ DESPUÉS DEL VIAJE

| Estado | Funcionalidad | Detalles | Archivo |
|--------|---------------|----------|---------|
| ✅ | Calificación del conductor | 1-5 estrellas funcional | RideCompletedScreen.dart |
| ✅ | Guardar en historial | Local + servidor (MySQL) | RideHistoryService.dart |
| ✅ | Recibo detallado | Desglose: tarifa + km + tiempo | RideCompletedScreen.dart |
| 🔄 | Reseña escrita | Campo para comentario (sin validación) | RideCompletedScreen.dart |
| ⬜ | Propina al conductor | No implementado |  |
| ⬜ | Reportar problema | No implementado |  |
| ⬜ | Objeto olvidado | No implementado |  |
| ⬜ | Factura descargable | No implementado (sin PDF export) |  |

**Estado:** ✅ **50% completo** — Falta: propina, reportar problema, objeto olvidado, factura PDF

---

## 📋 HISTORIAL DE VIAJES

| Estado | Funcionalidad | Detalles | Archivo |
|--------|---------------|----------|---------|
| ✅ | Lista de viajes | Listado cronológico funcional | RideHistoryScreen.dart |
| 🔄 | Detalle mejorado | Básico pero sin mapa de ruta | RideHistoryScreen.dart |
| ⬜ | Ver ruta del viaje | No hay mapa histórico de ruta |  |
| ⬜ | Repetir viaje | No implementado |  |
| ⬜ | Filtrar historial | No hay filtros |  |

**Estado:** ✅ **40% completo** — Falta: mapa de ruta, repetir viaje, filtros

---

## 💳 MÉTODOS DE PAGO

| Estado | Funcionalidad | Detalles | Archivo |
|--------|---------------|----------|---------|
| ✅ | Pago en efectivo | Método predeterminado | OsmMapScreen.dart |
| ⬜ | Tarjeta crédito/débito | No implementado |  |
| ⬜ | Billetera digital | No implementado |  |
| ✅ | Códigos de descuento | Sistema completo (HOY) | DiscountCodeService.dart |
| ⬜ | Historial de pagos | No implementado |  |

**Estado:** ✅ **40% completo** — Falta: tarjeta, billetera, historial pagos

---

## 🔔 NOTIFICACIONES

| Estado | Funcionalidad | Detalles | Archivo |
|--------|---------------|----------|---------|
| ✅ | Polling de estado | Cada 5 segundos consulta servidor | OsmMapScreen.dart |
| ⬜ | Push: conductor llegó | No hay Firebase FCM |  |
| ⬜ | Push: viaje iniciado | No hay Firebase FCM |  |
| ⬜ | Push: viaje completado | No hay Firebase FCM |  |
| ⬜ | Push: promociones | No hay Firebase FCM |  |

**Estado:** ✅ **20% completo** — Falta: todas las push notifications

---

## 🛡️ SEGURIDAD

| Estado | Funcionalidad | Detalles | Archivo |
|--------|---------------|----------|---------|
| ✅ | Sesión persistente | SharedPreferences | AuthPrefs.dart |
| 🔄 | Botón SOS | Implementado pero requiere contactos agregados | SOSService.dart |
| 🔄 | Compartir viaje | Implementado vía WhatsApp | ShareTripService.dart |
| ⬜ | Verificación de conductor | No hay validación de documento |  |
| ⬜ | Historial de seguridad | No implementado |  |

**Estado:** ✅ **60% completo** — Falta: verificación conductor, historial seguridad

---

## 🎁 SOPORTE Y EXTRAS

| Estado | Funcionalidad | Detalles | Archivo |
|--------|---------------|----------|---------|
| ⬜ | Pantalla de ayuda / FAQ | **← PENDIENTE (última Fase 2)** |  |
| ⬜ | Programa de referidos | No implementado |  |
| ⬜ | Encuesta de satisfacción | No implementado |  |
| ⬜ | Membresía premium | No implementado |  |
| ⬜ | Accesibilidad | No implementado |  |

**Estado:** ⬜ **0% completo** — Todo pendiente (FAQ es prioritario)

---

## 🎯 PROGRESS POR FASE

### **FASE 1 — Funcionalidades Premium Visibles**

```
1. Foto de perfil                    🔄 En plan (básico funciona)
2. Lugares favoritos: Casa y Trabajo ✅ Completo
3. Historial de destinos recientes   ✅ Completo
4. Recibo detallado                  ✅ Completo (recién con descuento)
5. Reseña escrita                    🔄 En plan (campo existe, sin validación)

TOTAL: 3/5 completas (60%) + 2/5 en plan (40%)
```

### **FASE 2 — Seguridad e Innovación**

```
6. Botón SOS                         🔄 Implementado pero requiere setup
7. Compartir viaje                   🔄 Implementado pero requiere setup
8. Pantalla de Ayuda / FAQ           ⬜ PENDIENTE ← BLOQUEADOR FASE 2
9. Códigos de descuento              ✅ Completo (completado hoy)

TOTAL: 2/4 en plan + 1/4 completa + 1/4 pendiente
```

### **FASE 3 — Funcionalidades Avanzadas**

```
10. Ver conductores cercanos         ⬜ Pendiente
11. Ajustar punto de recogida        ⬜ Pendiente
12. Chat con conductor               ⬜ Pendiente
13. Notificaciones push (FCM)        ⬜ Pendiente
14. Múltiples paradas                ⬜ Pendiente
15. Repetir viaje                    ⬜ Pendiente

TOTAL: 0/6 (0%)
```

---

## ⚠️ PROBLEMAS CRÍTICOS ENCONTRADOS

### 🔴 **Alta Prioridad (Bloquean producción)**

1. **OTP Hardcodeado**
   - Línea: SignIn.dart
   - Problema: OTP fijo en "1234" — cualquiera puede entrar
   - Impacto: Seguridad crítica
   - Solución: Integrar Twilio/AWS SNS para OTP real

2. **Conductores Simulados**
   - Línea: DemoData.dart
   - Problema: No hay conductores reales, asignación es fake
   - Impacto: App no funciona sin módulo conductor
   - Solución: Implementar módulo conductor

3. **Error de Navegación en Descuentos**
   - Problema: `_history.isNotEmpty` error al confirmar viaje con cupón
   - Impacto: Usuario no puede completar viaje con descuento
   - Solución: Revisar Navigator.pop() en _confirmarConCupon()

4. **API en ngrok (temporal)**
   - Problema: URL cambia cada sesión
   - Impacto: No funciona en producción
   - Solución: Servidor fijo

### 🟡 **Media Prioridad (Mejoran UX)**

1. **Foto de perfil limitada**
   - No hay vista previa antes de guardar
   - No hay crop/edición

2. **Bottom sheet de confirmación**
   - Algunas veces se queda atascado
   - Necesita mejor handling de estado

3. **Sin Firebase FCM**
   - Sin notificaciones push
   - Solo polling cada 5 seg

4. **Sin validación de campos**
   - Email sin regex
   - Teléfono sin formato
   - Campos de texto sin límite

---

## ✅ CAMBIOS DE HOY (Sistema de Cupones)

```
✅ DiscountCodeService.dart         - Validación local + servidor
✅ DiscountCodeModel.dart           - Modelo con cálculos
✅ DiscountPreferences.dart         - Persistencia local
✅ RideCompletedScreen.dart         - Mostrar línea de descuento
✅ RideHistoryService.dart          - Guardar cupón en historial
✅ validar_codigo.php               - Nuevo endpoint
✅ registrar_uso_codigo.php         - Nuevo endpoint
✅ guardar_viaje.php                - Actualizado para cupones
✅ actualizar_bd_cupones.sql        - Migración BD lista
```

---

## 🚀 RECOMENDACIÓN DE PRÓXIMOS PASOS

### **Corto Plazo (Esta semana)**
1. ⬜ **Pantalla de Ayuda / FAQ** — Cierra Fase 2
2. 🔴 **Ejecutar SQL de cupones** en la BD
3. 🔴 **Fijar error de navegación** con descuentos

### **Mediano Plazo (Próximas 2-3 semanas)**
4. 🔴 **Integrar OTP real** (Twilio/SMS)
5. ⬜ **Comenzar Módulo Conductor** — Crítico para funcionar
6. 🟡 **Firebase FCM** para notificaciones push

### **Largo Plazo (Post-MVP)**
7. Tarjeta de crédito (Stripe/PayPhone)
8. Chat con conductor (WebSockets)
9. Notificaciones push avanzadas

---

## 📝 CONCLUSIÓN

**El módulo pasajero está al 65% de completitud:**
- **Funciones básicas:** 95% (mapa, ubicación, viajes)
- **Funciones de seguridad:** 60% (SOS, compartir, cupones)
- **Funciones de UX:** 40% (historial, notificaciones, filtros)

**Para lanzar a producción necesitas:**
1. ✅ Completar Fase 2 (FAQ)
2. 🔴 Arreglar error de navegación con cupones
3. 🔴 Cambiar OTP hardcodeado
4. 🔄 Implementar Módulo Conductor (sin esto no funciona)
5. 🔄 Servidor fijo (reemplazar ngrok)

**Tiempo estimado a MVP:**
- Fase 2 (FAQ): 4-6 horas
- Error de navegación: 1-2 horas
- OTP real: 3-4 horas
- **Total: 8-12 horas de trabajo**

---

**Documento generado:** 11-03-2026 12:36
**Estado actual:** En desarrollo, Fase 2 (casi completa)

