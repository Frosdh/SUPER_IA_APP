/**
 * validaciones.js — Super_IA
 * Validaciones client-side para formularios de asesor.
 * Incluye algoritmo oficial de cédula ecuatoriana.
 */

// ─────────────────────────────────────────────────────────────
// HELPERS DE UI
// ─────────────────────────────────────────────────────────────
function setFieldState(input, ok, msg) {
    const wrap = input.closest('.form-group') || input.parentElement;

    // Quitar estados previos
    input.classList.remove('field-ok', 'field-err');
    let hint = wrap.querySelector('.val-hint');
    if (!hint) {
        hint = document.createElement('div');
        hint.className = 'val-hint';
        // Insertar después del input (o del div de contraseña)
        const after = input.closest('.pass-wrap') || input;
        after.insertAdjacentElement('afterend', hint);
    }

    if (ok === null) {           // neutro / vacío
        hint.textContent = '';
        hint.className   = 'val-hint';
        return true;
    }

    input.classList.add(ok ? 'field-ok' : 'field-err');
    hint.className   = 'val-hint ' + (ok ? 'hint-ok' : 'hint-err');
    hint.textContent = (ok ? '✔ ' : '✖ ') + msg;
    return ok;
}

// ─────────────────────────────────────────────────────────────
// CÉDULA ECUATORIANA — algoritmo oficial del Registro Civil
// ─────────────────────────────────────────────────────────────
function validarCedulaEc(valor) {
    const cedula = valor.replace(/\s/g, '');

    // 1. Solo dígitos y exactamente 10 caracteres
    if (!/^\d{10}$/.test(cedula)) return { ok: false, msg: 'Debe tener exactamente 10 dígitos numéricos' };

    // 2. Código de provincia (primeros 2 dígitos: 01–24 o 30)
    const provincia = parseInt(cedula.substring(0, 2), 10);
    if ((provincia < 1 || provincia > 24) && provincia !== 30) {
        return { ok: false, msg: 'Código de provincia inválido (primeros 2 dígitos)' };
    }

    // 3. Tercer dígito: 0–5 persona natural, 6 entidad pública, 9 jurídica
    const tercero = parseInt(cedula[2], 10);
    if (tercero >= 7 && tercero !== 9) {
        return { ok: false, msg: 'Tercer dígito inválido para persona natural' };
    }

    // 4. Algoritmo módulo 10 (persona natural: tercero 0–6)
    if (tercero <= 6) {
        const coef       = [2, 1, 2, 1, 2, 1, 2, 1, 2];
        let   suma       = 0;
        for (let i = 0; i < 9; i++) {
            let val = parseInt(cedula[i], 10) * coef[i];
            if (val >= 10) val -= 9;
            suma += val;
        }
        const verificador = suma % 10 === 0 ? 0 : 10 - (suma % 10);
        if (verificador !== parseInt(cedula[9], 10)) {
            return { ok: false, msg: 'Cédula inválida (dígito verificador incorrecto)' };
        }
        return { ok: true, msg: 'Cédula válida' };
    }

    // 5. RUC persona jurídica (tercero = 9) — módulo 11
    if (tercero === 9) {
        const coefJ = [4, 3, 2, 7, 6, 5, 4, 3, 2];
        let   sumaJ = 0;
        for (let i = 0; i < 9; i++) sumaJ += parseInt(cedula[i], 10) * coefJ[i];
        const verJ = sumaJ % 11 === 0 ? 0 : 11 - (sumaJ % 11);
        if (verJ !== parseInt(cedula[9], 10)) {
            return { ok: false, msg: 'RUC jurídico inválido (dígito verificador)' };
        }
        return { ok: true, msg: 'RUC jurídico válido' };
    }

    return { ok: true, msg: 'Documento válido' };
}

// ─────────────────────────────────────────────────────────────
// EMAIL
// ─────────────────────────────────────────────────────────────
// NOTA: esta función solo valida FORMATO (sintaxis) + typos comunes. Un
// email con formato correcto puede tener un dominio inventado que no existe
// de verdad (ej. "maroa@gmailIIIIIII.com") — el navegador no puede resolver
// DNS, así que esa verificación real se hace en el servidor vía
// api_verificar_campo.php (ver bindEmailConDominio() más abajo, que la usa
// para el chequeo en vivo, y funciones_validacion.php::validarEmail() en PHP
// para el chequeo definitivo al enviar el formulario).
function validarEmail(valor) {
    if (!valor) return { ok: false, msg: 'El email es requerido' };
    // RFC 5322 simplificado
    const re = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/;
    if (!re.test(valor)) return { ok: false, msg: 'Formato de email inválido (ej: correo@dominio.com)' };
    // Dominios comunes mal escritos
    const typos = { 'gmai.com':'gmail.com','gmial.com':'gmail.com','gnail.com':'gmail.com','hotmial.com':'hotmail.com','hotmal.com':'hotmail.com','yaho.com':'yahoo.com' };
    const domain = valor.split('@')[1].toLowerCase();
    if (typos[domain]) return { ok: false, msg: `¿Quisiste decir @${typos[domain]}?` };
    return { ok: true, msg: 'Email válido' };
}

// ─────────────────────────────────────────────────────────────
// EMAIL + DOMINIO REAL — chequeo en vivo contra el servidor (DNS)
// Se conecta al mismo endpoint que ya verifica unicidad de email
// (api_verificar_campo.php), que ahora también valida el dominio con
// checkdnsrr(). Guarda el resultado en input.dataset.dominioValido para
// que el submit-handler de bindValidaciones() lo pueda usar.
// ─────────────────────────────────────────────────────────────
function bindEmailConDominio(form, endpointUrl) {
    const input = form.querySelector('[name="email"]');
    if (!input) return;

    let timer = null;

    function marcarEstado(ok, msg) {
        setFieldState(input, ok, msg);
    }

    function verificar() {
        const valor = input.value.trim();
        const rFormato = validarEmail(valor);
        if (!rFormato.ok) {
            input.dataset.dominioValido = 'false';
            marcarEstado(false, rFormato.msg);
            return;
        }

        input.dataset.dominioValido = 'checking';
        marcarEstado(null, '');
        const hint = (input.closest('.form-group') || input.parentElement).querySelector('.val-hint');
        if (hint) { hint.textContent = 'Verificando dominio…'; hint.style.color = '#6b7280'; hint.className = 'val-hint'; }

        clearTimeout(timer);
        timer = setTimeout(() => {
            fetch(`${endpointUrl}?campo=email&valor=${encodeURIComponent(valor)}`)
                .then(res => res.json())
                .then(data => {
                    if (input.value.trim() !== valor) return; // el usuario siguió escribiendo
                    if (data.valido === false) {
                        input.dataset.dominioValido = 'false';
                        marcarEstado(false, data.msg || 'El dominio del correo no existe o no puede recibir correos');
                    } else {
                        input.dataset.dominioValido = 'true';
                        marcarEstado(true, 'Email válido');
                    }
                })
                .catch(() => {
                    // Sin respuesta del servidor: no bloquear al usuario aquí,
                    // la validación definitiva ocurre igual en el servidor.
                    input.dataset.dominioValido = 'unknown';
                });
        }, 500);
    }

    input.addEventListener('blur', verificar);
    input.addEventListener('input', verificar);
}

// ─────────────────────────────────────────────────────────────
// TELÉFONO ECUADOR (fijo o celular)
// ─────────────────────────────────────────────────────────────
function validarTelefono(valor) {
    const tel = valor.replace(/[\s\-().+]/g, '');
    if (!tel) return { ok: false, msg: 'El teléfono es requerido' };
    if (!/^\d+$/.test(tel)) return { ok: false, msg: 'Solo se permiten dígitos' };

    // Celular Ecuador: empieza en 09, 10 dígitos
    if (/^09\d{8}$/.test(tel)) return { ok: true, msg: 'Número celular válido' };
    // Fijo Ecuador: empieza en 02-07, 9 dígitos (con indicativo)
    if (/^0[2-7]\d{7}$/.test(tel)) return { ok: true, msg: 'Número fijo válido' };
    // Con código país +593
    if (/^593\d{9}$/.test(tel)) return { ok: true, msg: 'Número válido con código de país' };

    return { ok: false, msg: 'Ingresa un número ecuatoriano válido (ej: 0987654321 o 022345678)' };
}

// ─────────────────────────────────────────────────────────────
// NOMBRES / APELLIDOS — solo letras y espacios
// ─────────────────────────────────────────────────────────────
function validarNombre(valor, campo) {
    if (!valor || valor.trim().length < 2) return { ok: false, msg: `${campo} debe tener al menos 2 caracteres` };
    if (valor.trim().length > 80)          return { ok: false, msg: `${campo} es demasiado largo` };
    if (!/^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s'-]+$/.test(valor)) {
        return { ok: false, msg: `${campo} solo puede contener letras` };
    }
    return { ok: true, msg: `${campo} válido` };
}

// ─────────────────────────────────────────────────────────────
// USUARIO
// ─────────────────────────────────────────────────────────────
function validarUsuario(valor) {
    if (!valor || valor.length < 4)  return { ok: false, msg: 'Mínimo 4 caracteres' };
    if (valor.length > 30)           return { ok: false, msg: 'Máximo 30 caracteres' };
    if (!/^[a-zA-Z0-9._-]+$/.test(valor)) {
        return { ok: false, msg: 'Solo letras, números, puntos, guiones o guión bajo' };
    }
    return { ok: true, msg: 'Usuario válido' };
}

// ─────────────────────────────────────────────────────────────
// CONTRASEÑA
// ─────────────────────────────────────────────────────────────
function validarPassword(valor) {
    if (!valor || valor.length < 6)  return { ok: false, msg: 'Mínimo 6 caracteres' };
    const tiene_mayus = /[A-Z]/.test(valor);
    const tiene_num   = /[0-9]/.test(valor);
    if (valor.length >= 8 && tiene_mayus && tiene_num) return { ok: true,  msg: 'Contraseña fuerte ✔' };
    if (valor.length >= 6)                              return { ok: true,  msg: 'Contraseña aceptable (recomendado: 8+ chars, mayúscula y número)' };
    return { ok: false, msg: 'Contraseña muy débil' };
}

function validarConfirmPassword(pass, confirm) {
    if (!confirm)              return { ok: false, msg: 'Repite la contraseña' };
    if (pass !== confirm)      return { ok: false, msg: 'Las contraseñas no coinciden' };
    return { ok: true, msg: 'Coinciden' };
}

// ─────────────────────────────────────────────────────────────
// BIND — conecta cada campo del formulario con su validador
// ─────────────────────────────────────────────────────────────
function bindValidaciones(formSelector) {
    const form = document.querySelector(formSelector);
    if (!form) return;

    // Helper: bind blur + input
    function on(name, fn) {
        const el = form.querySelector(`[name="${name}"]`);
        if (!el) return;
        const run = () => { const r = fn(el.value); setFieldState(el, r.ok, r.msg); };
        el.addEventListener('blur',  run);
        el.addEventListener('input', run);
    }

    on('nombres',          v => validarNombre(v, 'Nombres'));
    on('apellidos',        v => validarNombre(v, 'Apellidos'));
    on('cedula',           v => validarCedulaEc(v));
    on('telefono',         v => validarTelefono(v));
    // Email: formato instantáneo + verificación real del dominio contra el
    // servidor (ver bindEmailConDominio arriba) en vez de solo el regex.
    bindEmailConDominio(form, 'api_verificar_campo.php');
    on('usuario',          v => validarUsuario(v));
    on('password',         v => validarPassword(v));
    on('password_confirm', v => {
        const p = form.querySelector('[name="password"]');
        return validarConfirmPassword(p ? p.value : '', v);
    });

    // Re-validar confirmar cuando cambia el password
    const passEl    = form.querySelector('[name="password"]');
    const confirmEl = form.querySelector('[name="password_confirm"]');
    if (passEl && confirmEl) {
        passEl.addEventListener('input', () => {
            if (confirmEl.value) {
                const r = validarConfirmPassword(passEl.value, confirmEl.value);
                setFieldState(confirmEl, r.ok, r.msg);
            }
        });
    }

    // ── Validar TODO al submit ──────────────────────────────
    form.addEventListener('submit', function(e) {
        let valido = true;

        function check(name, fn, extra) {
            const el = form.querySelector(`[name="${name}"]`);
            if (!el) return;
            const r = fn(extra !== undefined ? extra : el.value);
            if (!setFieldState(el, r.ok, r.msg)) valido = false;
        }

        check('nombres',          v => validarNombre(v, 'Nombres'));
        check('apellidos',        v => validarNombre(v, 'Apellidos'));
        check('cedula',           v => validarCedulaEc(v));
        check('telefono',         v => validarTelefono(v));
        check('usuario',          v => validarUsuario(v));
        check('password',         v => validarPassword(v));

        // Email: formato + resultado del chequeo de dominio ya calculado
        // por bindEmailConDominio (dataset.dominioValido). Si el chequeo
        // aún no ha llegado ("checking"/"unknown"), no se bloquea aquí —
        // la validación definitiva del dominio la hace el servidor.
        const emailEl = form.querySelector('[name="email"]');
        if (emailEl) {
            const rEmail = validarEmail(emailEl.value);
            if (rEmail.ok && emailEl.dataset.dominioValido === 'false') {
                if (!setFieldState(emailEl, false, 'El dominio del correo no existe o no puede recibir correos')) valido = false;
            } else if (!setFieldState(emailEl, rEmail.ok, rEmail.msg)) {
                valido = false;
            }
        }

        const p  = form.querySelector('[name="password"]');
        const pc = form.querySelector('[name="password_confirm"]');
        if (p && pc) {
            const r = validarConfirmPassword(p.value, pc.value);
            if (!setFieldState(pc, r.ok, r.msg)) valido = false;
        }

        // Selects requeridos
        form.querySelectorAll('select[required]').forEach(sel => {
            if (!sel.value) {
                setFieldState(sel, false, 'Selecciona una opción');
                valido = false;
            } else {
                setFieldState(sel, true, '');
            }
        });

        if (!valido) {
            e.preventDefault();
            // Scroll al primer error
            const first = form.querySelector('.field-err');
            if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
}

// ─────────────────────────────────────────────────────────────
// ESTILOS INLINE (se inyectan una sola vez)
// ─────────────────────────────────────────────────────────────
(function injectStyles() {
    if (document.getElementById('val-styles')) return;
    const s = document.createElement('style');
    s.id = 'val-styles';
    s.textContent = `
        .val-hint          { font-size:12px; margin-top:4px; font-weight:600; min-height:16px; }
        .hint-ok           { color:#059669; }
        .hint-err          { color:#dc2626; }
        .field-ok          { border-color:#059669 !important; box-shadow:0 0 0 2px rgba(5,150,105,.12) !important; }
        .field-err         { border-color:#dc2626 !important; box-shadow:0 0 0 2px rgba(220,38,38,.12) !important; }
    `;
    document.head.appendChild(s);
})();
