// ============================================================
// cooperativa_buscador.js
// Combobox de búsqueda por palabra para elegir cooperativa/banco.
// Se usa en registro_admin.php, registro_supervisor.php y
// registro_asesor.php, donde la lista real (unidad_bancaria +
// seps_cooperativas importadas) puede tener cientos de entradas y un
// <select> plano se vuelve inmanejable.
//
// Uso:
//   initCooperativaBuscador({
//     inputId:  'cooperativa_buscar',   // <input type="text"> visible
//     hiddenId: 'cooperativa',          // <input type="hidden"> que se envía en el form
//     listId:   'cooperativa_lista',    // <div> donde se pintan las sugerencias
//     data:     [{id: '...', nombre: '...'}, ...],
//     onSelect: function(item) { ... }  // opcional, se dispara al elegir una opción
//   });
// ============================================================
function initCooperativaBuscador(opts) {
    var input   = document.getElementById(opts.inputId);
    var hidden  = document.getElementById(opts.hiddenId);
    var lista   = document.getElementById(opts.listId);
    var data    = opts.data || [];
    var onSelect = typeof opts.onSelect === 'function' ? opts.onSelect : function () {};

    if (!input || !hidden || !lista) return;

    var MAX_RESULTS = 200;

    function normaliza(s) {
        return (s || '').toString()
            .normalize('NFD').replace(/[̀-ͯ]/g, '') // quita tildes
            .toLowerCase();
    }

    function render(items) {
        lista.innerHTML = '';
        if (items.length === 0) {
            var vacio = document.createElement('div');
            vacio.className = 'coop-buscador-empty';
            vacio.textContent = 'No se encontraron cooperativas con ese nombre.';
            lista.appendChild(vacio);
            lista.style.display = 'block';
            return;
        }
        items.slice(0, MAX_RESULTS).forEach(function (item) {
            var opt = document.createElement('div');
            opt.className = 'coop-buscador-item';
            opt.textContent = item.nombre;
            opt.dataset.id = item.id;
            opt.addEventListener('mousedown', function (e) {
                // mousedown (no click) para que dispare antes del blur del input
                e.preventDefault();
                input.value = item.nombre;
                hidden.value = item.id;
                lista.style.display = 'none';
                onSelect(item);
            });
            lista.appendChild(opt);
        });
        lista.style.display = 'block';
    }

    function filtrar(q) {
        var qn = normaliza(q);
        if (!qn) return data;
        return data.filter(function (item) {
            return normaliza(item.nombre).indexOf(qn) !== -1;
        });
    }

    input.addEventListener('input', function () {
        hidden.value = ''; // obliga a elegir una opción real de la lista
        render(filtrar(input.value));
    });

    input.addEventListener('focus', function () {
        render(filtrar(input.value));
    });

    input.addEventListener('blur', function () {
        // pequeño delay para permitir que el mousedown del item se procese primero
        setTimeout(function () { lista.style.display = 'none'; }, 150);
    });

    // Si el formulario se envía sin haber elegido una opción real de la
    // lista, el input hidden queda vacío y el "required" del hidden
    // bloquea el submit nativo del navegador.
}
