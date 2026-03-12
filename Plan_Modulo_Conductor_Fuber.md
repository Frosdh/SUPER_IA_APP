# 🚗 FUBER — Plan Módulo Conductor
**Aplicación de transporte · Cuenca, Ecuador**
**Versión 1.0 · Marzo 2026**

---

## 📋 RESUMEN EJECUTIVO

El módulo del conductor es la contrapartida del módulo pasajero. Mientras los pasajeros solicitan viajes, los conductores:
- Se conectan y se marcan como disponibles
- Reciben solicitudes de viaje en su zona
- Aceptan/rechazan viajes
- Navegan al pasajero y completan la ruta
- Reciben calificaciones y ganancias

---

## 🎯 ARQUITECTURA GENERAL

```
┌─────────────────────────────────────────┐
│      CONDUCTOR (Flutter Mobile)         │
├─────────────────────────────────────────┤
│  • Login con cédula / teléfono + OTP   │
│  • Dashboard de viajes disponibles      │
│  • Mapa en tiempo real                  │
│  • Gestión de solicitudes               │
│  • Historial y ganancias                │
└─────────────────────────────────────────┘
              ↕ API REST
┌─────────────────────────────────────────┐
│      Backend PHP + MySQL (Fuber API)    │
├─────────────────────────────────────────┤
│  • Autenticación de conductores         │
│  • Asignación de viajes                 │
│  • Actualización de ubicación           │
│  • Gestión de ganancias                 │
│  • Historial y reportes                 │
└─────────────────────────────────────────┘
```

---

## 🔐 CUENTA Y PERFIL CONDUCTOR

| Estado | Funcionalidad | Descripción | Prioridad |
|--------|---------------|-------------|-----------|
| ⬜ | Registro con cédula | Registro con número de cédula + OTP | Alta |
| ⬜ | Inicio de sesión | Login para conductores ya registrados | Alta |
| ⬜ | Verificación de identidad | Upload de foto de cédula o licencia | Alta |
| ⬜ | Registro de vehículo | Ingresar marca, modelo, placa, color, año | Alta |
| ⬜ | Foto de vehículo | Subir foto frontal del vehículo | Alta |
| ⬜ | Editar perfil | Modificar nombre, teléfono, email | Media |
| ⬜ | Datos bancarios | Ingresar cuenta para recibir pagos | Media |
| ⬜ | Cambiar vehículo | Registrar un vehículo diferente | Media |
| ⬜ | Historial de verificación | Ver estado de documentos y verificación | Baja |

---

## 🟢 ANTES DEL VIAJE (Disponibilidad)

| Estado | Funcionalidad | Descripción | Prioridad |
|--------|---------------|-------------|-----------|
| ⬜ | Mapa con ubicación actual | GPS que detecta posición del conductor | Alta |
| ⬜ | Botón Conectar/Desconectar | Toggle para marcar como disponible/ocupado | Alta |
| ⬜ | Zona de servicio | Mostrar área donde opera (ej: Cuenca centro) | Alta |
| ⬜ | Filtro de categorías | Seleccionar qué categorías de viaje acepta | Media |
| ⬜ | Documentos en orden | Indicador visual de documentos verificados | Media |
| ⬜ | Estadísticas en vivo | Mostrar viajes disponibles, ocupado %, rating | Media |
| ⬜ | Sonido de solicitud | Alerta de viaje entrante con sonido | Media |
| ⬜ | Radiación en el mapa | Mostrar conductores cercanos (simulado o real) | Baja |

---

## 📲 SOLICITUD DE VIAJE ENTRANTE

| Estado | Funcionalidad | Descripción | Prioridad |
|--------|---------------|-------------|-----------|
| ⬜ | Modal de solicitud | Pantalla emergente con detalles del viaje | Alta |
| ⬜ | Info del pasajero | Nombre, foto, calificación promedio | Alta |
| ⬜ | Ubicación de recogida | Dirección del pickup en texto y mapa mini | Alta |
| ⬜ | Ubicación de destino | Dirección del destino en texto | Alta |
| ⬜ | Distancia estimada | Kilómetros entre pickup y destino | Alta |
| ⬜ | Duración estimada | Minutos estimados del viaje | Alta |
| ⬜ | Ganancia estimada | Monto que recibirá el conductor | Alta |
| ⬜ | Botones Aceptar/Rechazar | Confirmar o rechazar la solicitud | Alta |
| ⬜ | Contador regresivo | Mostrar segundos antes de expirar la oferta | Media |
| ⬜ | Historial rápido del pasajero | Últimos viajes y calificación | Media |
| ⬜ | Razón de rechazo | Opcional: explicar por qué rechaza | Baja |

---

## 🗺️ DURANTE EL VIAJE

| Estado | Funcionalidad | Descripción | Prioridad |
|--------|---------------|-------------|-----------|
| ⬜ | Mapa con ruta | Polilínea de ruta hacia pickup y destino | Alta |
| ⬜ | Info del pasajero | Nombre y calificación visible en pantalla | Alta |
| ⬜ | Navegación integrada | Botón para abrir Google Maps/Waze | Alta |
| ⬜ | Estado de viaje | Indicador: "Yendo por pasajero" / "En viaje" | Alta |
| ⬜ | Botón "He llegado" | Notificar al pasajero que está en el pickup | Alta |
| ⬜ | Confirmación de pasajero | Esperar a que pasajero confirme que subió | Media |
| ⬜ | Llamada al pasajero | Botón para llamar directamente | Media |
| ⬜ | Chat con pasajero | Mensajería rápida durante el viaje | Media |
| ⬜ | Botón SOS/Emergencia | Para casos de emergencia del conductor | Media |
| ⬜ | Compartir ubicación | Permitir que pasajero vea ubicación en vivo | Baja |
| ⬜ | Pausa de viaje | Parar el contador de tiempo temporalmente | Baja |
| ⬜ | Cancelar viaje | Con diálogo de confirmación y penalidad | Media |

---

## ✅ DESPUÉS DEL VIAJE

| Estado | Funcionalidad | Descripción | Prioridad |
|--------|---------------|-------------|-----------|
| ⬜ | Finalizar viaje | Botón para confirmar llegada a destino | Alta |
| ⬜ | Resumen de ganancias | Mostrar: tarifa base + km + tiempo - comisión | Alta |
| ⬜ | Guardar en historial | Viaje guardado en BD | Alta |
| ⬜ | Calificación del pasajero | Recibir rating de 1 a 5 estrellas | Alta |
| ⬜ | Comentario del pasajero | Leer reseña escrita del pasajero | Media |
| ⬜ | Propina | Recibir propina del pasajero (si aplica) | Media |
| ⬜ | Recibo detallado | Desglose de cálculo de ganancia | Media |
| ⬜ | Reportar problema | Formulario: pasajero grosero, problema técnico, etc. | Media |
| ⬜ | Recibo en PDF | Descargar comprobante del viaje | Baja |

---

## 📊 HISTORIAL Y GANANCIAS

| Estado | Funcionalidad | Descripción | Prioridad |
|--------|---------------|-------------|-----------|
| ⬜ | Lista de viajes | Historial cronológico de todos los viajes | Alta |
| ⬜ | Detalle del viaje | Ver pasajero, ganancia, calificación, fecha | Alta |
| ⬜ | Dashboard de ganancias | Total ganado hoy, esta semana, este mes | Alta |
| ⬜ | Desglose de ingresos | Base + km + tiempo, menos comisión Fuber | Media |
| ⬜ | Gráficos de rendimiento | Viajes por hora, ganancias por zona | Media |
| ⬜ | Filtrar historial | Por fecha, zona, categoría de viaje | Baja |
| ⬜ | Exportar reporte | Descargar historial en PDF/Excel | Baja |
| ⬜ | Predicción de ganancias | "Si trabajas 4 más horas: +$XX" | Baja |

---

## 💳 PAGOS Y TRANSFERENCIAS

| Estado | Funcionalidad | Descripción | Prioridad |
|--------|---------------|-------------|-----------|
| ⬜ | Saldo disponible | Mostrar dinero listo para retirar | Alta |
| ⬜ | Historial de pagos | Transferencias anteriores con fechas | Media |
| ⬜ | Solicitar retiro | Transferencia a cuenta bancaria | Media |
| ⬜ | Métodos de pago | Listar cuentas bancarias registradas | Media |
| ⬜ | Comisión Fuber | Mostrar % de comisión por cada viaje | Baja |
| ⬜ | Bonificaciones | Mostrar bonos por cantidad de viajes | Baja |
| ⬜ | Impuestos/retenciones | Desglose de impuestos (si aplica) | Baja |

---

## 🔔 NOTIFICACIONES Y ALERTAS

| Estado | Funcionalidad | Descripción | Prioridad |
|--------|---------------|-------------|-----------|
| ⬜ | Polling de estado | App consulta al servidor cada 5 seg | Alta |
| ⬜ | Push: solicitud llegó | Notificación de nuevo viaje disponible | Alta |
| ⬜ | Push: pasajero canceló | Alerta si el pasajero cancela | Media |
| ⬜ | Push: pasajero llegó | "Tu pasajero está aquí" | Media |
| ⬜ | Push: pago procesado | "Se depositaron $XX en tu cuenta" | Media |
| ⬜ | Push: documentos | "Tu cédula vence en 30 días" | Baja |
| ⬜ | Push: promociones | "Gana 2x viajes entre 6-8pm" | Baja |

---

## 🛡️ SEGURIDAD Y VERIFICACIÓN

| Estado | Funcionalidad | Descripción | Prioridad |
|--------|---------------|-------------|-----------|
| ⬜ | Verificación de identidad | Verificar que cédula es válida | Alta |
| ⬜ | Verificación de vehículo | Verificar que placa y documento son válidos | Alta |
| ⬜ | Antecedentes penales | Validación de récord criminal (si aplica) | Alta |
| ⬜ | Sesión persistente | Login guardado, sin re-login forzado | Alta |
| ⬜ | Botón SOS | Alerta de emergencia del conductor | Media |
| ⬜ | Compartir ubicación | Pasajero puede ver dónde está el conductor | Media |
| ⬜ | Histórico de seguridad | Registro de reportes/denuncias | Baja |
| ⬜ | Bloqueo de conductor | Disable de cuenta si hay problemas | Baja |

---

## ⭐ SOPORTE Y EXTRAS

| Estado | Funcionalidad | Descripción | Prioridad |
|--------|---------------|-------------|-----------|
| ⬜ | Pantalla de ayuda / FAQ | Preguntas frecuentes y contacto de soporte | Media |
| ⬜ | Tutorial de inicio | Guía para primeros conductores | Media |
| ⬜ | Requisitos de documentos | Listar qué se necesita para verificar | Media |
| ⬜ | Contactar soporte | Chat o teléfono de atención al cliente | Media |
| ⬜ | Programa de referidos | Código para invitar otros conductores | Baja |
| ⬜ | Rating y reputación | Ver cómo los pasajeros te califican | Baja |
| ⬜ | Encuesta de satisfacción | Feedback periódico del conductor | Baja |
| ⬜ | Accesibilidad | Soporte para lectores de pantalla | Baja |

---

## 📊 RESUMEN DEL MÓDULO

```
✅ Implementado:     0 funciones
🔄 En plan:         0 funciones
⬜ Pendiente:       71 funciones
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Total:              71 funciones
```

---

## 🚀 ORDEN DE IMPLEMENTACIÓN RECOMENDADO

### **FASE 1 — Funcionalidades Básicas (MVP)**
1. Registro y verificación de conductor
2. Registro de vehículo
3. Login/logout
4. Botón Conectar/Desconectar
5. Mapa con ubicación en vivo
6. Recibir solicitud de viaje (modal)
7. Aceptar/rechazar viaje
8. Ir hacia el pasajero (mapa con ruta)
9. Botón "He llegado"
10. Finalizar viaje
11. Ver ganancias del viaje
12. Historial de viajes
13. Perfil del conductor

### **FASE 2 — Funcionalidades de Experiencia**
14. Contacto con pasajero (llamada + chat)
15. Notificaciones push
16. Dashboard de ganancias (totales por período)
17. Saldo y transferencias
18. Calificaciones de pasajeros
19. Pantalla de ayuda / FAQ
20. Documentos en orden (estado de verificación)

### **FASE 3 — Funcionalidades Avanzadas (Post-MVP)**
21. Gráficos de rendimiento
22. Predicción de ganancias
23. Bonificaciones y promociones
24. Chat en tiempo real mejorado
25. Antecedentes penales (integración)
26. Reporte de problemas
27. Programa de referidos
28. Exportar reportes

---

## 🔗 INTEGRACIÓN CON MÓDULO PASAJERO

El módulo conductor debe estar sincronizado con el pasajero:

| Pasajero | Conductor | Acción |
|----------|-----------|--------|
| Solicita viaje | Recibe alerta | WebSocket/Push |
| Pasajero sube al auto | Conductor inicia viaje | API call |
| En viaje | En viaje | Ubicación en tiempo real (polling/sockets) |
| Viaje finaliza | Viaje finaliza | API update |
| Califica al conductor | Recibe calificación | Notificación + update BD |

---

## 💾 BASE DE DATOS — Tablas Necesarias

```sql
-- Conductores (ya existe con campos básicos)
-- Vehículos (ya existe)

-- Nuevas tablas necesarias:
CREATE TABLE solicitud_viajes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  conductor_id INT,
  viaje_id INT,
  estado ENUM('pendiente','aceptado','rechazado'),
  fecha_oferta TIMESTAMP,
  FOREIGN KEY (conductor_id) REFERENCES conductores(id),
  FOREIGN KEY (viaje_id) REFERENCES viajes(id)
);

CREATE TABLE transferencias_conductor (
  id INT PRIMARY KEY AUTO_INCREMENT,
  conductor_id INT,
  monto DECIMAL(10,2),
  estado ENUM('pendiente','completada','rechazada'),
  fecha_solicitud TIMESTAMP,
  fecha_deposito TIMESTAMP,
  numero_cuenta VARCHAR(20),
  FOREIGN KEY (conductor_id) REFERENCES conductores(id)
);

CREATE TABLE calificaciones_conductor (
  id INT PRIMARY KEY AUTO_INCREMENT,
  conductor_id INT,
  viaje_id INT,
  usuario_id INT,
  calificacion INT,
  comentario TEXT,
  fecha TIMESTAMP,
  FOREIGN KEY (conductor_id) REFERENCES conductores(id)
);

CREATE TABLE ubicacion_conductor (
  id INT PRIMARY KEY AUTO_INCREMENT,
  conductor_id INT,
  latitud DECIMAL(10,8),
  longitud DECIMAL(11,8),
  timestamp TIMESTAMP,
  FOREIGN KEY (conductor_id) REFERENCES conductores(id),
  INDEX idx_conductor_timestamp (conductor_id, timestamp)
);
```

---

## ⚠️ CONSIDERACIONES IMPORTANTES

1. **Asignación de viajes:** ¿Automática (algoritmo) o manual (aceptar/rechazar)?
   - Actual: El pasajero ve un conductor simulado
   - Propuesta: El conductor elige aceptar/rechazar

2. **Ubicación en tiempo real:**
   - Requiere WebSockets o polling rápido (cada 2-3 seg)
   - Consumo de batería alto en conductores

3. **Comisión Fuber:**
   - ¿Fija (%) o variable por zona/hora?
   - ¿Mostrar antes o después de cada viaje?

4. **Verificación de documentos:**
   - Manual (por admin) o automática (API externa)?
   - Tiempo de aprobación: horas/días

5. **Pago a conductores:**
   - ¿Diario, semanal o a demanda?
   - ¿Integración con sistema bancario real?

---

## 📈 MÉTRICAS CLAVE

Para el dashboard del conductor:
- Viajes completados hoy/semana/mes
- Calificación promedio (stars)
- Tasa de aceptación de viajes
- Ingresos brutos vs netos
- Horas conectado
- Zona de mayor actividad

---

**Documento Base: Fuber · Módulo Conductor · Plan v1.0 · Marzo 2026**

