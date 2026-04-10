<?php
// Test de conexión a MySQL
try {
    $pdo = new PDO('mysql:host=localhost;port=3306', 'root', '');
    echo "✅ Conexión exitosa a MySQL!\n";
    
    // Verificar si la base de datos existe
    $resultado = $pdo->query("SHOW DATABASES LIKE 'corporat_radix_copia'");
    $base_existe = $resultado->fetch();
    
    if ($base_existe) {
        echo "✅ Base de datos 'corporat_radix_copia' existe\n";
        
        // Conectar a la base de datos
        $pdo = new PDO('mysql:host=localhost;dbname=corporat_radix_copia', 'root', '');
        
        // Verificar tabla usuarios
        $usuarios = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
        echo "✅ Tabla 'usuarios' tiene $usuarios registros\n";
        
        // Verificar tabla clientes
        $clientes = $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
        echo "✅ Tabla 'clientes' tiene $clientes registros\n";
        
    } else {
        echo "❌ Base de datos 'corporat_radix_copia' NO existe\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
