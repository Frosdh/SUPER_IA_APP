/**
 * GeoMove Admin — Premium JS Core
 * Maneja Modo Oscuro, Notificaciones Toast y Helpers AJAX
 */

class GeoMoveUI {
    constructor() {
        this.initDarkMode();
        this.createToastContainer();
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
window.GeoMove = new GeoMoveUI();
