# Correcciones aplicadas — 12 Abril 2026

## Resumen de problemas encontrados y corregidos

---

## Bug 1 — `server_php/actualizar_ubicacion_asesor.php`
**Problema:** El `asesor_id` era tratado como entero (`(int)$_POST['asesor_id']`), pero en la base de datos es un UUID (`char(36)`). Al convertir un UUID a `int` PHP devuelve `0`, y la validación `$asesor_id <= 0` hacía que se rechazara la solicitud inmediatamente.

**Efecto:** La app móvil jamás guardaba la ubicación del asesor en la BD.

**Corrección:**
- Cambiar `(int)$_POST['asesor_id']` → `trim((string)$_POST['asesor_id'])`
- Cambiar `(int)$_POST['usuario_id']` → `trim((string)$_POST['usuario_id'])`
- Cambiar `bind_param('iddd', ...)` → `bind_param('sddd', ...)` (tipo `s` para UUID string)
- Actualizar la lógica de fallback para usar comparación `=== ''` en vez de `<= 0`

---

## Bug 2 — `lib/Core/Services/BackgroundLocationService.dart`
**Problema:** En Flutter, el `asesor_id` (que es un UUID como `"3f25-..."`) se intentaba convertir a entero:
```dart
final int asesorId = int.tryParse(asesorIdRaw) ?? 0;
```
`int.tryParse` de un UUID devuelve `null`, entonces `asesorId = 0`.  
Luego `if (!isDriver && asesorId <= 0) return;` **salía sin enviar nada**.

**Efecto:** El servicio de ubicación en segundo plano nunca enviaba las coordenadas del asesor al servidor. El supervisor no veía a ningún asesor en el mapa.

**Corrección:**
- Usar `asesorId` como `String` directamente (no convertir a int)
- Enviar el UUID tal cual en el body del POST: `'asesor_id': asesorId`
- Agregar `usuarioId` como campo adicional de respaldo
- Cambiar la guarda a `if (asesorId.isEmpty && usuarioId.isEmpty) return;`

---

## Bug 3 — `server_php/guardar_cliente_encuesta.php`
**Problema 1 (UPDATE cliente):** La cadena de tipos en `bind_param` contenía `'q'` que es un tipo **inválido** en PHP MySQLi. Solo son válidos: `s` (string), `i` (int), `d` (double/float), `b` (blob).
```php
// ANTES (roto):
$stmt->bind_param('sssssssiiqs', ...);
// DESPUÉS (correcto):
$stmt->bind_param('sssssssiiss', ...);  // asesor_id es UUID string → 's'
```

**Problema 2 (INSERT tarea):** La cadena de tipos tenía 10 `s` para 9 campos string + 4 floats. Se corrigió a `sssssssssddds`.

**Efecto:** Al intentar guardar/finalizar una tarea cuando la cédula del cliente ya existía en BD, PHP lanzaba un error fatal y la app mostraba "error al guardar".

---

## Bug 4 — `lib/UI/views/NuevaEncuestaScreen.dart`
**Problema:** Si `usuario_id` estaba vacío en SharedPreferences (ej. sesión antigua antes de que se guardara este campo), el PHP rechazaba con `"usuario_id requerido"` pero la app no mostraba un mensaje claro.

**Corrección:** Se agregó validación explícita antes de enviar: si `usuarioId.isEmpty`, se muestra un SnackBar informativo al usuario pidiéndole cerrar sesión y volver a ingresar.

---

## Mapa del Supervisor (sin cambio de código)
El `mapa_vivo_superIA.php` **ya tenía** auto-refresh cada 10 segundos con `setInterval(updateLocations, 10000)`. El problema era que los asesores nunca enviaban su ubicación (Bug 2). Al corregir los Bugs 1 y 2, el mapa del supervisor empezará a mostrar las ubicaciones correctamente sin modificaciones adicionales.

---

## Archivos modificados

| Archivo | Tipo |
|---------|------|
| `server_php/actualizar_ubicacion_asesor.php` | PHP (servidor) |
| `lib/Core/Services/BackgroundLocationService.dart` | Dart (Flutter mobile) |
| `server_php/guardar_cliente_encuesta.php` | PHP (servidor) |
| `lib/UI/views/NuevaEncuestaScreen.dart` | Dart (Flutter mobile) |

---

## Cómo verificar que funciona

1. **Ubicación en mapa (supervisor):**
   - Compilar la app Flutter y hacer login como asesor
   - El servicio de fondo iniciará y enviará ubicación cada 15 seg
   - El supervisor al entrar a `mapa_vivo_superIA.php` verá el marcador del asesor

2. **Guardar tarea/encuesta:**
   - Completar el flujo en `NuevaEncuestaScreen`
   - Al presionar "Finalizar", debe guardar sin error
   - Si hay error de sesión, aparecerá el mensaje informativo claro

3. **Rebuild Flutter requerido:** Después de los cambios en Dart, ejecutar:
   ```bash
   flutter clean && flutter pub get && flutter run
   ```
