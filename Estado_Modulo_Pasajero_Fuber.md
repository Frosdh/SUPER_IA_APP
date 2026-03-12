# FUBER - Estado Modulo Pasajero
**Aplicacion de transporte · Cuenca, Ecuador**
**Corte de estado: Marzo 12, 2026**

---

## Resumen

El modulo pasajero de Fuber se encuentra funcionalmente cerrado como MVP avanzado.

La mayoria de funcionalidades planificadas ya fueron implementadas y probadas dentro de la app. Los pendientes reales se reducen a:

1. `Chat con conductor`
2. `OTP por SMS real en produccion`

---

## Funcionalidades Completadas

### Fase 1

- Foto de perfil
- Lugares favoritos `Casa` y `Trabajo`
- Historial de destinos recientes
- Recibo detallado con desglose
- Resena escrita al calificar conductor

### Fase 2

- Boton SOS con WhatsApp
- Compartir viaje
- Pantalla de Ayuda / FAQ
- Codigos de descuento / cupones

### Fase 3

- Ver conductores cercanos en el mapa
- Ajustar punto de recogida arrastrando el mapa
- Multiples paradas en el viaje
- Repetir viaje desde el historial

### Integraciones y soporte

- Notificaciones push con Firebase FCM
- Flujo OTP integrado con Firebase Phone Auth

---

## Estado del OTP

El flujo OTP ya fue integrado dentro de la app de pasajero usando Firebase Phone Auth.

### Estado actual

- `Integracion Firebase`: completada
- `Phone Authentication`: habilitado
- `SHA-1 / SHA-256`: configurados
- `google-services.json`: actualizado
- `Flujo OTP dentro de la app`: funcionando
- `Numeros de prueba de Firebase`: funcionando correctamente

### Pendiente tecnico

- `SMS real con numeros normales`: no queda estable todavia en el stack actual

### Diagnostico actual

La integracion base funciona. El bloqueo restante no esta en la UI ni en el flujo principal de la app, sino en la validacion/envio de SMS reales con la combinacion actual de tecnologias:

- Flutter `1.22.6`
- `firebase_auth 0.18.x`

### Conclusion sobre OTP

El OTP queda `funcionalmente integrado` para el modulo pasajero, pero `no cerrado para produccion estricta` mientras no se estabilice el envio de SMS reales.

---

## Pendientes Reales

### 1. Chat con conductor

Estado: `Pendiente`

Motivo:
- depende del modulo conductor
- requiere backend de mensajes
- requiere estados del viaje conectados entre pasajero y conductor

### 2. OTP real por SMS en produccion

Estado: `Pendiente tecnico`

Motivo:
- el flujo ya funciona con numeros de prueba
- el envio real de SMS no queda estable con el stack actual

---

## Riesgos y Observaciones

- El modulo pasajero ya puede demostrarse como producto funcional.
- El OTP actual sirve para validacion tecnica y demo.
- Para produccion seria conviene resolver uno de estos caminos:
  - modernizar Flutter/Firebase y volver a validar Phone Auth real
  - migrar OTP a backend propio con proveedor SMS

---

## Estado Final Recomendado

### Modulo pasajero

- `Estado funcional`: cerrado
- `Estado MVP`: cerrado
- `Estado produccion estricta`: con observaciones

### Pendiente cruzado con otro modulo

- `Chat con conductor` depende del modulo conductor

### Pendiente tecnico documentado

- `OTP por SMS real en produccion`

---

## Siguiente Etapa Recomendada

Iniciar el `modulo conductor` y dejar documentado el OTP como deuda tecnica controlada.

Orden sugerido:

1. Login / sesion conductor
2. Boton Conectar / Desconectar
3. Mapa con ubicacion actual
4. Actualizacion de ubicacion en backend
5. Recepcion de solicitud de viaje
6. Aceptar / rechazar viaje

---

**Documento: Estado Modulo Pasajero Fuber**
**Version: 1.0**
**Fecha: Marzo 12, 2026**
