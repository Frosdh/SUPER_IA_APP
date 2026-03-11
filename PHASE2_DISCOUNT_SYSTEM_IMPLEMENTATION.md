# 🎟️ FASE 2: Discount Code System Implementation
**Sistema de Cupones/Códigos de Descuento - Funcionalidad Implementada**

---

## ✅ Resumen de Implementación

Se ha implementado un **Sistema Completo de Cupones y Códigos de Descuento** que permite a los pasajeros aplicar códigos promocionales para obtener descuentos en sus viajes. El sistema incluye validación en servidor, cálculo automático de descuentos, y registro de uso.

---

## 📁 Archivos Creados/Modificados

### 1. **DiscountCodeModel.dart** (NUEVO)
**Ubicación:** `lib/Core/Models/DiscountCodeModel.dart`

**Clase principal:** `DiscountCode`
- Propiedades:
  - `id` - ID del cupón
  - `codigo` - Código promocional (ej: FUBER20)
  - `tipo` - 'porcentaje' o 'monto_fijo'
  - `valor` - Valor del descuento (20 para 20% o 5.00 para $5)
  - `minimoViaje` - Monto mínimo del viaje para usar el cupón
  - `maximoUsos` - Máximo de veces que se puede usar
  - `usosActuales` - Veces ya usado
  - `fechaInicio` / `fechaFin` - Vigencia del cupón
  - `activo` - Estado del cupón

**Métodos:**
- `esValido()` - Verifica si el código está disponible (vigencia, máximo de usos, etc.)
- `calcularDescuento(precio)` - Calcula el monto del descuento
- `calcularPrecioFinal(precio)` - Calcula precio con descuento aplicado
- `obtenerDescuentoTexto()` - Retorna string formateado del descuento

---

### 2. **DiscountCodeService.dart** (NUEVO)
**Ubicación:** `lib/Core/Services/DiscountCodeService.dart`

**Métodos principales:**
- `validarCodigo(codigo)` - Contacta servidor para validar código
  - Retorna objeto DiscountCode si es válido
  - Valida vigencia, máximo de usos, etc.

- `guardarCuponUsado(codigo)` - Guarda cupón como usado localmente

- `fueUsado(codigo)` - Verifica si ya fue usado

- `aplicarDescuento(precio, cupom)` - Calcula precio con descuento

- `obtenerMontoDescuento(precio, cupom)` - Obtiene monto del descuento

- `cumpleMinimoViaje(cupom, precio)` - Verifica si cumple monto mínimo

- `registrarUsoEnServidor(codigo, viajeId)` - Registra uso en backend

---

### 3. **DiscountPreferences.dart** (NUEVO)
**Ubicación:** `lib/Core/Preferences/DiscountPreferences.dart`

**Métodos para persistencia local:**
- `guardarCuponUsado(codigo)` - Guarda cupón como usado en SharedPreferences
- `fueUsado(codigo)` - Verifica si ya fue usado
- `obtenerCuponesUsados()` - Lista de todos los cupones usados
- `guardarCuponActual(codigo)` - Guarda cupón durante el viaje
- `obtenerCuponActual()` - Obtiene cupón actual
- `guardarDescuentoActual(descuento)` - Guarda monto del descuento
- `obtenerDescuentoActual()` - Obtiene descuento actual
- `limpiarCuponActual()` - Limpia después de confirmar viaje

---

### 4. **OsmMapScreen.dart** (MODIFICADO)
**Cambios realizados:**

#### a) **Imports agregados:**
```dart
import 'package:fu_uber/Core/Services/DiscountCodeService.dart';
import 'package:fu_uber/Core/Models/DiscountCodeModel.dart';
import 'package:fu_uber/Core/Preferences/DiscountPreferences.dart';
```

#### b) **Variables de estado:**
```dart
// ── Descuentos ────────────────────────────────────
double _descuentoAplicado = 0.0;
String _codigoAplicado = '';
```

#### c) **Métodos modificados:**
- `_mostrarConfirmacion()` - Ahora pasa callback `onCuponAplicado`
- `_finalizarViaje()` - Registra cupón como usado y limpia estado

#### d) **_ConfirmacionSheet** convertida a **StatefulWidget**
- Anteriormente: StatelessWidget
- Ahora: StatefulWidget con manejo de cupones

---

## 🎨 UI/UX del Sistema de Cupones

### Pantalla de Confirmación (Bottom Sheet)

**Estructura:**
```
┌─────────────────────────────────────────┐
│ CONFIRMAR VIAJE                         │
├─────────────────────────────────────────┤
│ Origen → Destino                        │
│ Categoría + Precio Base                 │
├─────────────────────────────────────────┤
│ 🎟️  ¿Tienes código de descuento?       │
│ ┌────────────────────────────────────┐ │
│ │ Ingresa código    [VALIDAR]        │ │
│ └────────────────────────────────────┘ │
│ (Si hay error)                          │
│ ❌ Mensaje de error                     │
├─────────────────────────────────────────┤
│ ✅ ¡Código aplicado!                   │
│ FUBER20                  [X]            │
├─────────────────────────────────────────┤
│ Descuento:      -$2.00                  │
│ Total:          $8.00 ✓                 │
├─────────────────────────────────────────┤
│ [CONFIRMAR · $8.00]                     │
│ [VOLVER]                                │
└─────────────────────────────────────────┘
```

**Estados:**
1. **Sin cupón:** Input para ingresar código
2. **Validando:** Spinner + "Validando código..."
3. **Código válido:** Mostrar cupón aplicado con botón X para remover
4. **Código inválido:** Mensaje de error en rojo

---

## 💰 Cálculo de Descuentos

### Tipo 1: Porcentaje
```
Precio original: $10.00
Código: FUBER20 (20%)
Descuento: 10.00 × 0.20 = $2.00
Precio final: 10.00 - 2.00 = $8.00
```

### Tipo 2: Monto Fijo
```
Precio original: $15.00
Código: WELCOME5 ($5 fijo)
Descuento: $5.00
Precio final: 15.00 - 5.00 = $10.00
```

### Validaciones Automáticas:
✓ Código existe y está activo
✓ No está expirado
✓ No alcanzó máximo de usos
✓ Cumple monto mínimo del viaje
✓ No fue usado antes (locally)

---

## 🔄 Flujo Completo

```
1. Usuario en confirmación de viaje
   ↓
2. Presiona "Ingresa código" (si tiene uno)
   ↓
3. Digita código (ej: FUBER20)
   ↓
4. Presiona botón "VALIDAR"
   ↓
5. Sistema valida en servidor:
   ├─ Verifica que existe
   ├─ Verifica vigencia
   ├─ Verifica máximo de usos
   └─ Verifica monto mínimo
   ↓
6. Si es válido:
   ├─ Calcula descuento
   ├─ Actualiza precio total
   ├─ Muestra cupón aplicado
   └─ Permite confirmar viaje
   ↓
7. Usuario presiona "CONFIRMAR"
   ↓
8. Se crea el viaje en servidor:
   ├─ Guarda código usado
   ├─ Incluye descuento en registro
   └─ Incrementa contador de usos
   ↓
9. Viaje completado
   ├─ Cupón guardado como usado (localmente)
   └─ No se puede reutilizar en este dispositivo
```

---

## 📡 API Endpoints Necesarios

### 1. Validar Código
**POST** `/validar_codigo.php`

**Parámetros:**
```php
codigo = "FUBER20"
```

**Respuesta (Éxito):**
```json
{
  "status": "success",
  "codigo": {
    "id": 1,
    "codigo": "FUBER20",
    "tipo": "porcentaje",
    "valor": 20,
    "minimo_viaje": 5.00,
    "maximo_usos": 100,
    "usos_actuales": 45,
    "fecha_inicio": "2026-03-01T00:00:00",
    "fecha_fin": "2026-12-31T23:59:59",
    "activo": 1
  }
}
```

**Respuesta (Error):**
```json
{
  "status": "error",
  "message": "Código no encontrado o expirado"
}
```

### 2. Registrar Uso
**POST** `/registrar_uso_codigo.php`

**Parámetros:**
```php
codigo = "FUBER20"
viaje_id = 123
```

**Respuesta:**
```json
{
  "status": "success",
  "message": "Uso registrado correctamente"
}
```

---

## 🧪 Testing Manual

### Requisitos:
- Cupones creados en base de datos
- App compilada con código actualizado

### Casos de prueba:

#### 1. Código válido con porcentaje
- Código: FUBER20
- Precio: $10.00
- Esperado: Descuento de $2.00, Total: $8.00

#### 2. Código válido con monto fijo
- Código: WELCOME5
- Precio: $15.00
- Esperado: Descuento de $5.00, Total: $10.00

#### 3. Código con monto mínimo no cumplido
- Código: MINIMO10 (mínimo $10)
- Precio: $5.00
- Esperado: Error "Viaje mínimo requerido: $10.00"

#### 4. Código expirado
- Código: FUBEROLD (fecha_fin en el pasado)
- Esperado: Error "Código expirado"

#### 5. Código sin usos disponibles
- Código: LIMITADO (maximo_usos = 1, usos_actuales = 1)
- Esperado: Error "Código no disponible"

#### 6. Reutilización local
- Usar FUBER20 en viaje 1 ✓
- Intentar usar FUBER20 en viaje 2
- Esperado: No aparecerá como guardado en preferencias

---

## 🔐 Seguridad Implementada

✓ **Validación en servidor**
- No se confía solo en validación local
- Servidor valida cada código

✓ **Prevención de duplicados**
- SharedPreferences guarda localmente
- Verificación en servidor de usos

✓ **Monto mínimo**
- Evita uso de cupones en viajes no elegibles

✓ **Vigencia temporal**
- Códigos con fecha de inicio y fin
- Validación automática en servidor

✓ **Límite de usos**
- Control de máximo de usos por código
- Contador en servidor

---

## 📊 Base de Datos: Tabla `codigos_descuento`

```sql
CREATE TABLE `codigos_descuento` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `codigo` VARCHAR(50) UNIQUE NOT NULL,
  `tipo` VARCHAR(20) NOT NULL,           -- 'porcentaje' o 'monto_fijo'
  `valor` DECIMAL(10, 2) NOT NULL,      -- 20 (20%) o 5.00 ($5)
  `minimo_viaje` DECIMAL(10, 2),        -- Monto mínimo
  `maximo_usos` INT,                    -- NULL = ilimitado
  `usos_actuales` INT DEFAULT 0,
  `fecha_inicio` DATETIME,
  `fecha_fin` DATETIME,
  `activo` TINYINT(1) DEFAULT 1,
  INDEX idx_codigo (codigo),
  INDEX idx_activo (activo),
  INDEX idx_fecha (fecha_fin)
);
```

---

## 🚀 Próximos Pasos

- [ ] Compilar y probar en dispositivo SM A035M
- [ ] Verificar validación de códigos
- [ ] Probar aplicación y cálculo de descuentos
- [ ] Implementar Help/FAQ Screen (última funcionalidad Fase 2)
- [ ] Testing completo de Fase 2

---

## 📝 Notas Técnicas

1. **Pre-null-safety:** Proyecto compatible con Dart 2.x
2. **SharedPreferences:** Para persistencia local de cupones usados
3. **Validación dual:** Local + Servidor para seguridad
4. **Error handling:** Mensajes amigables para cada tipo de error
5. **UX:** Interfaz intuitiva sin necesidad de confirmaciones extras

---

## ✨ Características Principales

✅ Ingreso de códigos en confirmación de viaje
✅ Validación en tiempo real
✅ Cálculo automático de descuentos
✅ Dos tipos de descuentos (% y monto fijo)
✅ Monto mínimo de viaje
✅ Vigencia temporal
✅ Límite de usos
✅ Prevención de reutilización local
✅ Registro en servidor
✅ UI atractiva y responsive

---

## 🎯 Información de Implementación

- **Implementado por:** Claude
- **Fecha:** 2026-03-11
- **Versión:** Fase 2 - Discount System
- **Estado:** ✅ Completo - Listo para testing

---

