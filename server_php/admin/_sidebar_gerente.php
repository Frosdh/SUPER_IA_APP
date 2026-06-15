<?php
/**
 * _sidebar_gerente.php — Sidebar para el panel del Gerente
 *
 * Variables que DEBEN estar definidas antes de hacer require:
 *   $currentPage  (string) — página activa: dashboard | mapa | clientes | operaciones |
 *                             alertas | supervisores | reportes | solicitudes
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_admin.php';

$currentPage    = trim((string)($currentPage ?? ''));
$navTitle       = $navTitle ?? 'Super_IA — Gerente';
$navIcon        = $navIcon  ?? 'fas fa-user-shield';

switch ($currentPage) {
    case 'solicitudes_asesor':
    case 'solicitudes_supervisor':
    case 'solicitudes_admin':
        $currentPage = 'solicitudes'; break;
    case 'asesores':
    case 'administrar_asesores':
    case 'administrar_supervisores':
        $currentPage = 'supervisores'; break;
    case 'gerente_dashboard':
        $currentPage = 'dashboard'; break;
    case 'mapa_vivo':
        $currentPage = 'mapa'; break;
}

$gerente_nombre = $gerente_nombre ?? $_SESSION['admin_nombre'] ?? 'Gerente';
$gerente_rol    = $gerente_rol    ?? $_SESSION['admin_rol']    ?? 'Gerente';

// Alertas sin ver — filtradas por supervisores del gerente según su rol
$alertas_pendientes = 0;
try {
    $gUserId = $_SESSION['admin_id'] ?? null;
    $gRol    = $_SESSION['admin_rol'] ?? 'jefe_agencia';
    $jaIdsSB = [];

    if ($gUserId) {
        require_once __DIR__ . '/helper_ja_ids.php';
        $jaIdsSB = resolver_ja_ids($pdo, $gUserId);
        if (!empty($jaIdsSB)) {
            $phJaSB = implode(',', array_fill(0, count($jaIdsSB), '?'));
            $stSB   = $pdo->prepare("SELECT id FROM supervisor WHERE jefe_agencia_id IN ($phJaSB)");
            $stSB->execute($jaIdsSB);
            $supIdsSB = $stSB->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($supIdsSB)) {
                $phSB = implode(',', array_fill(0, count($supIdsSB), '?'));
                $stA  = $pdo->prepare("SELECT COUNT(*) FROM alerta_modificacion WHERE supervisor_id IN ($phSB) AND vista_supervisor=0");
                $stA->execute($supIdsSB);
                $alertas_pendientes = (int)$stA->fetchColumn();
            }
        }
    }
} catch (Throwable $e) {}
?>
<link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">

<!-- ======== SIDEBAR GERENTE ======== -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-user-shield" style="color:#60a5fa;"></i>
        <span>Super_IA</span>
    </div>

    <!-- PRINCIPAL -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">PRINCIPAL</div>
        <a href="gerente_index.php"
           class="sidebar-link <?= ($currentPage === 'dashboard') ? 'active' : '' ?>">
            <i class="fas fa-gauge-high"></i> <span>Dashboard</span>
        </a>
        <a href="mapa_vivo_superIA.php"
           class="sidebar-link <?= ($currentPage === 'mapa') ? 'active' : '' ?>">
            <i class="fas fa-map-marked-alt"></i> <span>Mapa en Vivo</span>
        </a>
    </div>

    <!-- CLIENTES -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">CLIENTES</div>
        <a href="clientes.php"
           class="sidebar-link <?= ($currentPage === 'clientes') ? 'active' : '' ?>">
            <i class="fas fa-address-book"></i> <span>Clientes</span>
        </a>
        <a href="recuperacion.php"
           class="sidebar-link <?= ($currentPage === 'recuperacion') ? 'active' : '' ?>">
            <i class="fas fa-user-clock"></i> <span>Recuperación</span>
        </a>
    </div>

    <!-- OPERACIONES -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">OPERACIONES</div>
        <a href="operaciones.php"
           class="sidebar-link <?= ($currentPage === 'operaciones') ? 'active' : '' ?>">
            <i class="fas fa-handshake"></i> <span>Operaciones</span>
        </a>
        <a href="metas.php"
           class="sidebar-link <?= ($currentPage === 'metas') ? 'active' : '' ?>">
            <i class="fas fa-bullseye"></i> <span>Metas / Tareas</span>
        </a>
    </div>

    <!-- ANÁLISIS -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">ANÁLISIS</div>
        <a href="kpi_penetracion.php"
           class="sidebar-link <?= ($currentPage === 'reportes_penetracion') ? 'active' : '' ?>">
            <i class="fas fa-chart-bar"></i> <span>Reportes KPIs</span>
        </a>
        <a href="alertas.php"
           class="sidebar-link <?= ($currentPage === 'alertas') ? 'active' : '' ?>">
            <i class="fas fa-bell"></i> <span>Alertas</span>
            <?php if ($alertas_pendientes > 0): ?>
                <span class="badge bg-danger ms-auto sidebar-alert-badge" style="font-size:10px;padding:3px 7px;border-radius:10px;"><?= $alertas_pendientes ?></span>
            <?php endif; ?>
        </a>
    </div>

    <!-- MI EQUIPO -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">MI EQUIPO</div>
        <a href="mis_supervisores.php"
           class="sidebar-link <?= ($currentPage === 'supervisores') ? 'active' : '' ?>">
            <i class="fas fa-users-gear"></i> <span>Mis Supervisores</span>
        </a>
    </div>

    <!-- CONFIGURACIÓN -->
    <div class="sidebar-section">
        <div class="sidebar-section-title">ADMINISTRACIÓN</div>
        <a href="administrar_solicitudes_supervisor.php"
           class="sidebar-link <?= ($currentPage === 'solicitudes') ? 'active' : '' ?>">
            <i class="fas fa-clipboard-list"></i> <span>Solicitudes</span>
        </a>
    </div>
</div>

<!-- ======== NOTIFICACIONES FLOTANTES ======== -->
<style>
#sn-container{position:fixed;bottom:24px;right:24px;z-index:99999;display:flex;flex-direction:column-reverse;gap:10px;width:340px;pointer-events:none;}
.sn-card{pointer-events:all;background:#fff;border-radius:14px;box-shadow:0 8px 32px rgba(18,58,109,.18),0 2px 8px rgba(0,0,0,.08);border-left:4px solid #3b82f6;padding:0;overflow:hidden;animation:sn-slide-in .38s cubic-bezier(.34,1.56,.64,1) both;cursor:pointer;transition:transform .18s,box-shadow .18s;position:relative;}
.sn-card:hover{transform:translateY(-2px) scale(1.01);box-shadow:0 14px 40px rgba(18,58,109,.22),0 2px 8px rgba(0,0,0,.10);}
.sn-card.sn-exit{animation:sn-slide-out .28s ease-in both;}
.sn-card.sn-new{border-left-color:#60a5fa;animation:sn-slide-in .38s cubic-bezier(.34,1.56,.64,1) both,sn-pulse-border .8s ease-out .38s;}
.sn-header{background:linear-gradient(90deg,#eff6ff,#f0f9ff);padding:9px 14px 7px;display:flex;align-items:center;gap:8px;}
.sn-badge{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:800;color:#2563eb;text-transform:uppercase;letter-spacing:.5px;}
.sn-dot{width:7px;height:7px;border-radius:50%;background:#3b82f6;display:inline-block;animation:sn-blink 1.4s infinite;flex-shrink:0;}
.sn-time{font-size:10px;color:#9ca3af;font-weight:600;margin-left:auto;}
.sn-close{width:22px;height:22px;border-radius:50%;background:rgba(0,0,0,.07);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;color:#6b7280;flex-shrink:0;transition:background .15s,color .15s;padding:0;line-height:1;}
.sn-close:hover{background:#3b82f6;color:#fff;}
.sn-body{padding:10px 14px 10px;}
.sn-asesor{font-size:13px;font-weight:800;color:#0a2748;margin-bottom:3px;display:flex;align-items:center;gap:6px;}
.sn-asesor i{color:#6366f1;font-size:12px;}
.sn-msg{font-size:12px;color:#4b5563;line-height:1.4;margin-bottom:6px;}
.sn-footer{padding:5px 14px 9px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #f1f5f9;}
.sn-btn-ver{font-size:11px;font-weight:800;color:#2563eb;text-decoration:none;display:flex;align-items:center;gap:4px;transition:gap .15s;}
.sn-btn-ver:hover{gap:7px;color:#1d4ed8;}
.sn-counter{font-size:10px;color:#9ca3af;}
@keyframes sn-slide-in{from{opacity:0;transform:translateX(60px) scale(.92);}to{opacity:1;transform:translateX(0) scale(1);}}
@keyframes sn-slide-out{from{opacity:1;transform:translateX(0) scale(1);max-height:200px;}to{opacity:0;transform:translateX(70px) scale(.9);max-height:0;}}
@keyframes sn-blink{0%,100%{opacity:1;}50%{opacity:.25;}}
@keyframes sn-pulse-border{0%{border-left-color:#60a5fa;box-shadow:0 0 0 0 rgba(96,165,250,.5);}70%{box-shadow:0 0 0 8px rgba(96,165,250,0);}100%{border-left-color:#3b82f6;box-shadow:none;}}
</style>
<div id="sn-container"></div>

<script>
(function(){
    'use strict';
    var POLL_MS=12000, MAX_NOTIF=3, SK='sn_cerradas_gerente', _activas=[];
    var container=document.getElementById('sn-container');
    function getCerradas(){try{return JSON.parse(sessionStorage.getItem(SK)||'{}')}catch(e){return{}}}
    function addCerrada(id){var c=getCerradas();c[id]=1;try{sessionStorage.setItem(SK,JSON.stringify(c))}catch(e){}}
    function labelCampo(campo){
        var mapa={'fecha_programada':'Fecha de visita','estado':'Estado de tarea','hora_inicio':'Hora inicio','hora_fin':'Hora fin','nota':'Nota','encuesta':'Encuesta','credito':'Credito','monto':'Monto solicitado','acuerdo':'Acuerdo de visita'};
        if(!campo)return'tarea';var k=campo.toLowerCase();
        for(var c in mapa){if(k.indexOf(c)!==-1)return mapa[c];}return campo;
    }
    function timeAgo(ts){
        var diff=Math.floor((Date.now()-new Date(ts).getTime())/1000);
        if(diff<60)return'hace '+diff+'s';if(diff<3600)return'hace '+Math.floor(diff/60)+' min';
        if(diff<86400)return'hace '+Math.floor(diff/3600)+' h';
        var d=new Date(ts);return d.getDate()+'/'+(d.getMonth()+1)+' '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
    }
    function crearCard(a,esNueva){
        var card=document.createElement('div');
        card.className='sn-card'+(esNueva?' sn-new':'');card.dataset.id=a.id_alerta;
        var campo=labelCampo(a.campo_modificado||''),cliente=String(a.cliente_nombre||'cliente').replace(/[<>]/g,''),asesor=String(a.asesor_nombre||'Asesor').replace(/[<>]/g,''),tiempo=timeAgo(a.created_at),url='alertas_detalle.php?id='+encodeURIComponent(a.id_alerta);
        card.innerHTML=['<div class="sn-header">','<span class="sn-badge"><span class="sn-dot"></span><i class="fas fa-bell"></i>&nbsp;Alerta</span>','<span class="sn-time">'+tiempo+'</span>','<button class="sn-close" title="Cerrar"><i class="fas fa-times"></i></button>','</div>','<div class="sn-body">','<div class="sn-asesor"><i class="fas fa-user-circle"></i>'+asesor+'</div>','<div class="sn-msg">Modificó <strong>'+campo+'</strong></div>','</div>','<div class="sn-footer">','<a href="'+url+'" class="sn-btn-ver"><i class="fas fa-arrow-right"></i>&nbsp;Ver detalle</a>','<span class="sn-counter">#'+a.id_alerta+'</span>','</div>'].join('');
        card.querySelector('.sn-close').addEventListener('click',function(e){e.stopPropagation();cerrarCard(card,a.id_alerta);});
        card.addEventListener('click',function(e){if(e.target.closest&&e.target.closest('.sn-close'))return;window.location.href=url;});
        return card;
    }
    function cerrarCard(card,id){addCerrada(id);marcarVista(id);card.classList.add('sn-exit');setTimeout(function(){if(card.parentNode)card.parentNode.removeChild(card);_activas=_activas.filter(function(x){return x.id!=id;});},300);}
    function marcarVista(id){try{fetch('api_alertas_flotantes.php',{method:'POST',keepalive:true,headers:{'Content-Type':'application/json'},body:JSON.stringify({accion:'marcar_vista',alerta_id:parseInt(id)})}).catch(function(){});}catch(e){}}
    function agregarNotif(a,esNueva){
        if(_activas.length>=MAX_NOTIF){var vieja=_activas.shift();var elViejo=container.querySelector('[data-id="'+vieja.id+'"]');if(elViejo){elViejo.classList.add('sn-exit');setTimeout(function(){if(elViejo.parentNode)elViejo.parentNode.removeChild(elViejo);},300);}}
        var card=crearCard(a,esNueva);container.appendChild(card);_activas.push({id:a.id_alerta});
        if(esNueva){var t=setTimeout(function(){if(card.parentNode)cerrarCard(card,a.id_alerta);},20000);card.addEventListener('mouseenter',function(){clearTimeout(t);});}
    }
    var _primerPoll=true;
    function poll(){
        fetch('api_alertas_flotantes.php?_='+Date.now()).then(function(r){return r.json();}).then(function(data){
            if(!data.ok||!data.alertas)return;
            var cerradas=getCerradas(),serverIds={};
            data.alertas.forEach(function(a){serverIds[a.id_alerta]=true;});
            _activas.slice().forEach(function(item){if(!serverIds[item.id]){var el=container.querySelector('[data-id="'+item.id+'"]');if(el){el.classList.add('sn-exit');setTimeout(function(){if(el.parentNode)el.parentNode.removeChild(el);},300);}_activas=_activas.filter(function(x){return x.id!=item.id;});}});
            var activasIds={};_activas.forEach(function(x){activasIds[x.id]=true;});
            var mostrar=data.alertas.filter(function(a){return!cerradas[a.id_alerta]&&!activasIds[a.id_alerta];});
            mostrar.slice().reverse().forEach(function(a){agregarNotif(a,!_primerPoll);});
            _primerPoll=false;
        }).catch(function(){});
    }
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',function(){poll();setInterval(poll,POLL_MS);});}
    else{poll();setInterval(poll,POLL_MS);}
})();
</script>

<!-- ======== MAIN WRAPPER ======== -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <div class="nav-title-group"></div>
        <div class="user-info">
            <div>
                <strong><?= htmlspecialchars($gerente_nombre) ?></strong><br>
                <small><?= htmlspecialchars(ucfirst($gerente_rol)) ?></small>
            </div>
            <a href="logout.php" style="background:rgba(239,68,68,.18);color:#fca5a5;border:1px solid rgba(239,68,68,.4);padding:7px 14px;border-radius:10px;text-decoration:none;font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px;transition:.2s;" onmouseover="this.style.background='rgba(239,68,68,.35)'" onmouseout="this.style.background='rgba(239,68,68,.18)'">
                <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
            </a>
        </div>
    </div>

    <!-- CONTENT START -->
    <div class="content-area">
