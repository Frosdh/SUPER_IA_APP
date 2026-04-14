<?php
session_start();
require_once 'db_admin.php';

if (!isset($_SESSION['resubir_sec_id'])) {
    header('Location: login.php?role=secretary');
    exit;
}

$secId = $_SESSION['resubir_sec_id'];
$msg = '';
$msgType = 'danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $docBase64 = null;
    if (isset($_FILES['documento']) && $_FILES['documento']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['documento']['tmp_name'];
        $tipoMime = $_FILES['documento']['type'];
        if (strpos($tipoMime, 'image/') === 0 || $tipoMime === 'application/pdf') {
            $data = file_get_contents($tmp);
            $docBase64 = 'data:' . $tipoMime . ';base64,' . base64_encode($data);
            
            $stmt = $pdo->prepare("UPDATE secretarias SET documento_credencial = ?, verificado = 0 WHERE id = ?");
            if ($stmt->execute([$docBase64, $secId])) {
                $msg = "Nuevo documento enviado. El administrador debe aprobarlo nuevamente para que puedas acceder.";
                $msgType = "success";
                unset($_SESSION['resubir_sec_id']);
                // Redirigir al cabo de 4 segundos
                header("refresh:4;url=login.php?role=secretary");
            } else {
                $msg = "No se pudo actualizar la base de datos.";
            }
        } else {
            $msg = "El documento adjunto debe ser una imagen (JPG/PNG) o PDF.";
        }
    } else {
        $msg = "No has seleccionado ningún archivo válido.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resubir Nombramiento — GeoMove</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #110038 0%, #250070 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
        }
        .upload-card {
            background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px);
            border-radius: 20px; padding: 40px; width: 100%; max-width: 500px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
        }
        .icon-warn { font-size: 3rem; color: #dc3545; margin-bottom: 15px; }
        .btn-upload { background: linear-gradient(to right, #dc3545, #ec6b78); color: white; border: none; padding: 12px; width: 100%; border-radius: 12px; font-weight: bold; }
        .btn-upload:hover { background: #c82333; color: white; }
    </style>
</head>
<body>
    <div class="upload-card text-center">
        <i class="fas fa-exclamation-triangle icon-warn"></i>
        <h3 class="fw-bold text-dark">Documento Rechazado</h3>
        <p class="text-muted">El administrador ha revisado y rechazado el documento de tu última solicitud o cooperativa. Por favor sube una credencial clara o actualizada.</p>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?> rounded-3">
                <i class="fas fa-info-circle me-1"></i> <?= htmlspecialchars($msg) ?>
            </div>
            <?php if ($msgType === 'success'): ?>
                <div class="spinner-border text-success" role="status"></div><br>
                <small class="text-muted mt-2">Redireccionando a Inicio de sesión...</small>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($msgType !== 'success'): ?>
        <form method="POST" enctype="multipart/form-data" class="mt-4 text-start">
            <div class="mb-3">
                <label class="form-label fw-bold">Subir Nuevo Documento (Imagen o PDF)</label>
                <input type="file" name="documento" class="form-control" accept="image/*,application/pdf" required>
            </div>
            <button type="submit" class="btn btn-upload mt-2"><i class="fas fa-cloud-upload-alt me-2"></i>Enviar a revisión</button>
            <a href="login.php?role=secretary" class="btn btn-outline-secondary w-100 mt-2 rounded-3 border-0">Volver a Login</a>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>
