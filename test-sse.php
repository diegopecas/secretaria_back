<?php
// test-sse.php - Archivo de prueba para SSE
// Colócalo en la raíz del backend (secretaria_back/test-sse.php)

// Headers SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');

// Deshabilitar buffering
@ob_end_clean();
@ob_implicit_flush(true);
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);

// Enviar mensaje inicial
echo ":ok\n\n";
flush();

// Simular mensajes
for ($i = 1; $i <= 5; $i++) {
    echo "event: message\n";
    echo "data: " . json_encode(['content' => "Mensaje de prueba $i"]) . "\n\n";
    flush();
    sleep(1); // Esperar 1 segundo entre mensajes
}

// Mensaje final
echo "event: done\n";
echo "data: " . json_encode(['success' => true]) . "\n\n";
flush();
?>