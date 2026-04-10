<?php
// Mostrar información de conectividad
echo "<h2>Información de Conectividad del Servidor</h2>";
echo "<pre>";
echo "SERVER_ADDR: " . ($_SERVER['SERVER_ADDR'] ?? 'N/A') . "\n";
echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'N/A') . "\n";
echo "REMOTE_ADDR: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "\n";
echo "REMOTE_HOST: " . ($_SERVER['REMOTE_HOST'] ?? 'N/A') . "\n";

// Intentar obtener la IP real del servidor
$hostname = gethostname();
$ip = gethostbyname($hostname);
echo "Hostname: $hostname\n";
echo "IP (gethostbyname): $ip\n";

// Listar todas las IPs de red
$output = shell_exec('ipconfig');
echo "\nIPCONFIG Output:\n";
echo $output;
echo "</pre>";
?>
