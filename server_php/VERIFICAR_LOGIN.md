# ✅ Verificación - Cambio de Login: Usuario/Clave → Email/Contraseña

## Cambios Realizados

### 1. **index.php** - Página principal de login
✅ **MODIFICADO:**
- Campo: `usuario` → `email`
- Campo: `clave` → `password`
- Tipo hash: SHA256 → `password_verify()`
- Tabla: `usuario` (SUPER_IA LOGAN)
- Roles: Mapeo a ENUM `('jefe_agencia', 'supervisor', 'asesor')`

**Variables de sesión establecidas:**
```php
$_SESSION['id_usuario']  = $user['id']
$_SESSION['email']       = $user['email']
$_SESSION['nombre']      = $user['nombre']
$_SESSION['rol']         = $user['rol']          // ENUM value
$_SESSION['rol_display'] = $role                 // Display label
```

### 2. **dashboard.php** - Panel de control
✅ **ACTUALIZADO:**
- Lectura de sesión: `$_SESSION['usuario']` → `$_SESSION['email']`
- Lectura de sesión: `$_SESSION['nombres']` → `$_SESSION['nombre']`
- Lógica de rol: `$rol_id == 2` → `$rol === 'jefe_agencia'`
- Lógica de rol: `$rol_id == 3` → `$rol === 'supervisor'`
- Lógica de rol: `$rol_id == 4` → `$rol === 'asesor'`

### 3. **register_user.php** - Registro (ya estaba correcto)
✅ **VERIFICADO:**
- Ya usa `email` + `password_hash`
- Compatible con tabla `usuario`

---

## 📋 Tabla de Referencia - Base SUPER_IA LOGAN

```sql
usuario TABLE:
  id               CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID())
  nombre           VARCHAR(200) NOT NULL
  email            VARCHAR(200) NOT NULL UNIQUE
  password_hash    VARCHAR(255) NOT NULL
  rol              ENUM('gerente_general','jefe_regional','jefe_agencia','supervisor','asesor')
  activo           TINYINT(1) DEFAULT 1
  estado_aprobacion ENUM('pendiente','aprobado','denegado') DEFAULT 'pendiente'
  ultimo_login     DATETIME DEFAULT NULL
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
```

---

## 🔐 Flujo de Login Actual

1. Usuario selecciona rol: **Administrador** | **Supervisor** | **Asesor**
2. Ingresa: **Email** y **Contraseña**
3. Sistema busca en tabla `usuario` donde:
   - `email = ?`
   - `rol = ENUM_VALUE`
   - `activo = 1`
   - `estado_aprobacion = 'aprobado'`
4. Verifica password con `password_verify()`
5. Establece sesión y redirige a `dashboard.php`

---

## ✅ Checklist de Verificación

- [x] Formulario HTML: usuario → email
- [x] Formulario HTML: clave → password
- [x] Query: busca por email
- [x] Password verify: SHA256 → password_verify()
- [x] Tabla: usuarios → usuario (SUPER_IA)
- [x] Rol mapping: IDs → ENUM strings
- [x] Dashboard: variables de sesión actualizadas
- [x] Dashboard: lógica de rol condicional actualizada

---

## 🧪 Prueba Manual

1. Acceder a: `http://localhost/FUBER_APP/server_php/index.php`
2. Seleccionar rol: **Asesor**
3. Ingresar:
   - **Email:** (usuario en tabla `usuario` con rol='asesor')
   - **Contraseña:** (su contraseña)
4. Debe redirigir a `dashboard.php` con panel personalizado

---

## ⚠️ Notas Importantes

- La tabla antigua `usuarios` **NO se usa** en este nuevo login
- Todos los usuarios nuevos deben registrarse en tabla `usuario`
- Las contraseñas deben usar `password_hash()` para ser compatibles
- La aprobación de usuarios es manual: `estado_aprobacion = 'aprobado'`

---

## 📝 Próximos Pasos (OPCIONAL)

Si necesitas migrar usuarios antiguos:
```php
// Script para convertir usuarios antiguos a nuevo formato
UPDATE usuario SET 
  password_hash = SHA2(clave, 256)  // Si están en SHA256
  WHERE email IS NOT NULL
```

Pero es mejor que los usuarios cambien su contraseña después del login.
