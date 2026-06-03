<?php
// api_verificar_campo.php — Verifica si email, usuario o cédula ya existen
require_once 'db_admin.php';
header('Content-Type: application/json');

$campo = $_GET['campo'] ?? '';
$valor = trim($_GET['valor'] ?? '');

if (!$valor || !in_array($campo, ['email', 'usuario', 'cedula'])) {
    echo json_encode(['disponible' => true]);
    exit;
}

$existe = false;

try {
    if ($campo === 'email') {
        // Revisar en usuario (sistema principal) y en solicitudes_asesor (pendientes)
        $st = $pdo->prepare("SELECT 1 FROM usuario WHERE email = ? LIMIT 1");
        $st->execute([$valor]);
        if ($st->fetchColumn()) { $existe = true; }

        if (!$existe) {
            $st2 = $pdo->prepare("SELECT 1 FROM solicitudes_asesor WHERE email = ? AND estado != 'rechazada' LIMIT 1");
            $st2->execute([$valor]);
            if ($st2->fetchColumn()) { $existe = true; }
        }

    } elseif ($campo === 'usuario') {
        $st = $pdo->prepare("SELECT 1 FROM solicitudes_asesor WHERE usuario = ? AND estado != 'rechazada' LIMIT 1");
        $st->execute([$valor]);
        if ($st->fetchColumn()) { $existe = true; }

    } elseif ($campo === 'cedula') {
        // Revisar en solicitudes_asesor y en usuario no hay columna cedula, pero sí en solicitudes_asesor
        $st = $pdo->prepare("SELECT 1 FROM solicitudes_asesor WHERE cedula = ? AND estado != 'rechazada' LIMIT 1");
        $st->execute([$valor]);
        if ($st->fetchColumn()) { $existe = true; }
    }
} catch (\Throwable $e) {
    // Si falla la consulta, dejar pasar (la validación del servidor lo atrapará)
    echo json_encode(['disponible' => true]);
    exit;
}

echo json_encode(['disponible' => !$existe]);
