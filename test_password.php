<?php
// test_password.php
// Ejecuta este archivo para generar un hash correcto

$passwords = ['123456', 'admin123', 'password'];

echo "=== GENERADOR DE HASH DE PASSWORDS ===\n\n";

foreach ($passwords as $password) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    echo "Password: $password\n";
    echo "Hash: $hash\n";
    echo "-----------------------------------\n";
}

// Verificar el hash actual de la BD
$hashEnBD = '$2y$10$32lXUNp6jO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
echo "\n=== VERIFICANDO HASH DE LA BD ===\n";
echo "Hash en BD: $hashEnBD\n\n";

foreach ($passwords as $password) {
    $resultado = password_verify($password, $hashEnBD);
    echo "¿'$password' coincide con el hash de la BD? " . ($resultado ? "SÍ" : "NO") . "\n";
}