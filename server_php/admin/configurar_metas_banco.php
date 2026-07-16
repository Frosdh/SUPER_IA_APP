<?php
// ============================================================
// admin/configurar_metas_banco.php
// Esta vista se fusionó dentro de metas.php (sección "Tipos de meta
// habilitados por banco/cooperativa"), para que el SuperAdmin tenga
// todo en un solo lugar: configurar los tipos de meta por banco Y ver
// las metas del equipo de ese banco, sin cambiar de página.
// Este archivo queda solo como redirección para no romper enlaces o
// marcadores antiguos.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

$banco_id = trim($_GET['banco_id'] ?? '');
$qs = $banco_id !== '' ? ('?banco_filtro=' . urlencode($banco_id)) : '';
header('Location: metas.php' . $qs);
exit;
