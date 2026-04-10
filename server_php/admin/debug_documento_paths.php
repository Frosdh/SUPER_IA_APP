<?php
// debug_documento_paths.php — listar documento_path de asesores
require_once __DIR__ . '/../db_config.php';

$mysqli = new mysqli($db_host, $db_user, $db_password, $db_name);
if ($mysqli->connect_error) {
    die('Conexión fallida: ' . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

$query = "SELECT a.id, a.usuario_id, a.documento_path, u.email, u.nombre, u.created_at
          FROM asesor a
          LEFT JOIN usuario u ON u.id = a.usuario_id
          ORDER BY u.created_at DESC";

$res = $mysqli->query($query);
if (!$res) {
    die('Error en consulta: ' . $mysqli->error);
}

?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Debug documentos asesor</title>
</head>
<body>
<h2>Documentos de asesores</h2>
<table border="1" cellpadding="6" cellspacing="0">
<thead><tr><th>asesor.id</th><th>usuario_id</th><th>email</th><th>nombre</th><th>documento_path</th><th>Prueba enlace</th></tr></thead>
<tbody>
<?php while ($row = $res->fetch_assoc()):
    $path = $row['documento_path'];
    $display = htmlspecialchars($path);
    // construir posibles URLs para probar
    $urls = [];
    if ($path) {
        // si ya contiene 'uploads', usar relativa
        if (stripos($path, 'uploads/') !== false) {
            $sub = substr($path, stripos($path, 'uploads/'));
            $urls[] = '../../' . ltrim($sub, '/\\');
        }
        // legacy
        $urls[] = '../../uploads/asesor_credentials/' . rawurlencode(basename($path));
        // raw value
        $urls[] = $path;
    }
    ?>
    <tr>
        <td><?php echo htmlspecialchars($row['id']); ?></td>
        <td><?php echo htmlspecialchars($row['usuario_id']); ?></td>
        <td><?php echo htmlspecialchars($row['email']); ?></td>
        <td><?php echo htmlspecialchars($row['nombre']); ?></td>
        <td><?php echo $display; ?></td>
        <td>
            <?php if (!empty($urls)): foreach ($urls as $u): ?>
                <div><a href="<?php echo htmlspecialchars($u); ?>" target="_blank"><?php echo htmlspecialchars($u); ?></a></div>
            <?php endforeach; else: ?>
                (sin path)
            <?php endif; ?>
        </td>
    </tr>
<?php endwhile; ?>
</tbody>
</table>
</body>
</html>
<?php
$mysqli->close();
?>