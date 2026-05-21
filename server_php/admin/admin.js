/**
 * Super_IA Admin — Premium JS Core
 * Maneja Modo Oscuro, Notificaciones Toast y Helpers AJAX
 */

class Super_IAUI {
    constructor() {
        this.initDarkMode();
        this.createToastContainer();
        this.initAlertQueue();
    }

    // ── Modo Oscuro ───────────────────────────────────────────
    initDarkMode() {
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        
        // Sincronizar icono si existe el botón
        window.addEventListener('DOMContentLoaded', () => {
            this.updateDarkModeIcon(savedTheme);
        });
    }

    toggleDarkMode() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        this.updateDarkModeIcon(newTheme);
        
        this.showToast('Tema Actualizado', `Modo ${newTheme === 'dark' ? 'oscuro' : 'claro'} activado.`);
    }

    updateDarkModeIcon(theme) {
        const icon = document.getElementById('dark-mode-icon');
        if (!icon) return;
        icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }

    // ── Notificaciones Toast ──────────────────────────────────
    createToastContainer() {
        if (document.getElementById('toast-container')) return;
        const container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }

    // ── Alertas (cola / toasts en esquina inferior derecha) ─────
    initAlertQueue() {
        this.alertQueue = []; // newest first
        if (!document.getElementById('alert-toast-container')) {
            const c = document.createElement('div');
            c.id = 'alert-toast-container';
            document.body.appendChild(c);
        }
        // comenzar polling de alertas cada 8 segundos
        this._knownAlertIds = new Set();
        this.pollInterval = 8000;
        this._pollHandle = setInterval(() => this.pollAlerts(), this.pollInterval);
        // Ejecutar un primer poll inmediato para mostrar alertas sin esperar el intervalo
        setTimeout(() => this.pollAlerts(), 200);
    }

    /**
     * Añadir una alerta a la cola y renderizar hasta 3 recientes.
     * alert: { id, title, message }
     */
    pushAlert(alert) {
        if (!alert || !alert.id) return;
        // Evitar duplicados: eliminar si ya existe
        this.alertQueue = this.alertQueue.filter(a => a.id !== alert.id);
        // Insertar al inicio (más reciente)
        this.alertQueue.unshift(alert);
        // Mantener una cola razonable
        if (this.alertQueue.length > 50) this.alertQueue.length = 50;
        this.renderAlerts();
    }

    removeAlertById(id) {
        this.alertQueue = this.alertQueue.filter(a => a.id !== id);
        this.renderAlerts();
    }

    renderAlerts() {
        const container = document.getElementById('alert-toast-container');
        if (!container) return;
        // Mostrar hasta 3 alertas (las más recientes)
        const toShow = this.alertQueue.slice(0, 3);
        container.innerHTML = '';
        toShow.forEach(alert => {
            const el = document.createElement('div');
            el.className = 'alert-toast';
            el.innerHTML = `
                <div class="alert-avatar">${this.escapeHtml(alert.initials || '')}</div>
                <div class="alert-body">
                    <div class="alert-title">${this.escapeHtml(alert.title || 'Alerta')}</div>
                    <div class="alert-meta">${this.escapeHtml(alert.author || '')}</div>
                    <div class="alert-msg">${this.escapeHtml(alert.message || '')}</div>
                </div>
                <button class="alert-close" aria-label="Cerrar">&times;</button>
            `;

            // Click en el toast → marcar vista y abrir detalle de la alerta
            el.addEventListener('click', async (ev) => {
                // si el click fue en el botón cerrar, ignora navegación
                if (ev.target.closest('.alert-close')) return;
                if (!alert.id) return;
                try {
                    await fetch('api_marcar_alerta_vista.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'id=' + encodeURIComponent(alert.id)
                    });
                
                    // actualizar badge inmediatamente
                    this.decrementSidebarBadge(1);
                } catch (e) { /* ignore */ }
                window.location.href = 'alertas_detalle.php?id=' + encodeURIComponent(alert.id);
            });

            // Cerrar manual
            el.querySelector('.alert-close').addEventListener('click', async (ev) => {
                ev.stopPropagation();
                el.classList.add('hide');
                setTimeout(() => el.remove(), 300);
                this.removeAlertById(alert.id);
                // marcar como vista en servidor
                try {
                    await fetch('api_marcar_alerta_vista.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'id=' + encodeURIComponent(alert.id)
                    });
                    // actualizar badge inmediatamente
                    this.decrementSidebarBadge(1);
                } catch (e) { /* ignore */ }
            });

            container.appendChild(el);

            // Auto-dismiss pasado un tiempo (12s)
            setTimeout(() => {
                if (!document.body.contains(el)) return;
                el.classList.add('hide');
                setTimeout(() => el.remove(), 300);
                // también quitar de la cola si aún está
                this.alertQueue = this.alertQueue.filter(a => a.id !== alert.id);
                this.renderAlerts();
            }, 12000);
        });
    }

    async pollAlerts() {
        try {
            console.debug('[Super_IA] pollAlerts -> requesting api_alertas_recientes.php');
            const res = await fetch('api_alertas_recientes.php?_ts=' + Date.now(), { credentials: 'same-origin' });
            console.debug('[Super_IA] pollAlerts -> http status', res.status);
            if (!res.ok) return console.warn('[Super_IA] pollAlerts -> non-ok response');
            const data = await res.json();
            console.debug('[Super_IA] pollAlerts -> data', data);
            if (!data || !Array.isArray(data.alerts)) return console.warn('[Super_IA] pollAlerts -> invalid payload');

            // recorrer en orden (más reciente primero)
            let unseen = 0;
            for (const a of data.alerts) {
                if ((a.vista === 0 || a.vista === '0' || a.vista === null) ) unseen++;
                if (!this._knownAlertIds.has(a.id)) {
                    console.debug('[Super_IA] New alert:', a.id, a.title, a.message);
                    this.pushAlert({ id: a.id, title: a.title, message: a.message, author: a.author, initials: a.initials });
                    this._knownAlertIds.add(a.id);
                }
            }
            // actualizar badge en sidebar
            this.setSidebarAlertBadge(unseen);
            // limpiar ids antiguos para evitar memoria infinita
            if (this._knownAlertIds.size > 200) {
                this._knownAlertIds = new Set(Array.from(this._knownAlertIds).slice(0,100));
            }
        } catch (err) {
            console.warn('[Super_IA] pollAlerts error', err);
        }
    }

    setSidebarAlertBadge(count) {
        try {
            // Buscar el enlace de alertas en el sidebar
            const sel = document.querySelectorAll('a[href*="alertas.php"], a[href*="alertas"]');
            if (!sel || sel.length === 0) return;
            sel.forEach(a => {
                let badge = a.querySelector('.sidebar-alert-badge');
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'sidebar-alert-badge badge bg-danger ms-auto';
                    badge.style.fontSize = '11px';
                    badge.style.padding = '3px 8px';
                    badge.style.borderRadius = '10px';
                    badge.style.marginLeft = '8px';
                    a.appendChild(badge);
                }
                if (count > 0) {
                    // if currently hidden, ensure visible and animate pop
                    badge.classList.remove('badge-fade');
                    badge.textContent = String(count);
                    badge.style.display = '';
                    // trigger pop animation
                    badge.classList.remove('badge-pop');
                    // force reflow
                    void badge.offsetWidth;
                    badge.classList.add('badge-pop');
                    setTimeout(() => badge.classList.remove('badge-pop'), 380);
                } else {
                    // animate fade then hide
                    badge.classList.add('badge-fade');
                    setTimeout(() => { try { badge.style.display = 'none'; } catch(e){} }, 260);
                }
            });
        } catch (e) { /* ignore */ }
    }

    // Helpers to manage badge count locally
    getSidebarBadgeElement() {
        const sel = document.querySelectorAll('a[href*="alertas.php"], a[href*="alertas"]');
        if (!sel || sel.length === 0) return null;
        // prefer first
        const a = sel[0];
        let badge = a.querySelector('.sidebar-alert-badge');
        return badge || null;
    }

    getSidebarBadgeCount() {
        const b = this.getSidebarBadgeElement();
        if (!b) return 0;
        const n = parseInt(b.textContent || '0', 10);
        return isNaN(n) ? 0 : n;
    }

    decrementSidebarBadge(by = 1) {
        try {
            const current = this.getSidebarBadgeCount();
            const next = Math.max(0, current - by);
            this.setSidebarAlertBadge(next);
        } catch (e) { /* ignore */ }
    }

    escapeHtml(s) { return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[c]; }); }

    showToast(title, message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast-item toast-${type}`;
        
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-times-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };

        toast.innerHTML = `
            <div class="toast-icon"><i class="fas ${icons[type]} text-${type}"></i></div>
            <div class="toast-content">
                <h6>${title}</h6>
                <p>${message}</p>
            </div>
        `;

        container.appendChild(toast);
        
        // Forzar reflow para animación
        setTimeout(() => toast.classList.add('show'), 10);

        // Auto eliminar
        setTimeout(() => {
            toast.classList.add('hide');
            setTimeout(() => toast.remove(), 500);
        }, 4000);
    }

    // ── Skeleton Loader Helper ────────────────────────────────
    showSkeletons(containerId, count = 5) {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        let html = '';
        for (let i = 0; i < count; i++) {
            html += `
                <tr class="skeleton-row">
                    <td colspan="100%">
                        <div class="skeleton skeleton-text" style="width: 80%"></div>
                        <div class="skeleton skeleton-text" style="width: 40%"></div>
                    </td>
                </tr>
            `;
        }
        container.innerHTML = html;
    }

    // ── AJAX Helper con Skeleton ─────────────────────────────
    async fetchWithSkeleton(url, containerId, skeletonRows = 5) {
        const container = document.getElementById(containerId);
        if (!container) return;

        // Guardar contenido original por si falla
        const originalContent = container.innerHTML;
        
        // Mostrar esqueletos
        this.showSkeletons(containerId, skeletonRows);

        try {
            const response = await fetch(url + (url.includes('?') ? '&' : '?') + 'ajax=1');
            if (!response.ok) throw new Error('Error en la red');
            
            const html = await response.text();
            container.innerHTML = html;
            this.showToast('Actualizado', 'Datos cargados correctamente', 'success');
        } catch (error) {
            console.error(error);
            container.innerHTML = originalContent;
            this.showToast('Error', 'No se pudieron sincronizar los datos', 'error');
        }
    }
}

// Instancia global
window.Super_IA = new Super_IAUI();
