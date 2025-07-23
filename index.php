<?php
// index.php - Punto de entrada de la API

// DEBUGGING - Logs para ver qué está pasando
error_log("=== INICIO REQUEST ===");
error_log("REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'NO DEFINIDO'));
error_log("REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'NO DEFINIDO'));
error_log("HTTP_ORIGIN: " . ($_SERVER['HTTP_ORIGIN'] ?? 'NO DEFINIDO'));
error_log("CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? 'NO DEFINIDO'));

require 'vendor/autoload.php';

// Configurar CORS INMEDIATAMENTE - Antes de Flight
header('Access-Control-Allow-Origin: *');
error_log("CORS header establecido: Access-Control-Allow-Origin: *");

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-API-KEY');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Manejar petición OPTIONS inmediatamente
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    error_log("Petición OPTIONS recibida - respondiendo 200");
    http_response_code(200);
    exit();
}

// Función para convertir strings numéricas a números en arrays
function convertirNumerosEnArray(&$array) {
    if (!is_array($array)) return;
    
    foreach ($array as $key => &$value) {
        if (is_array($value)) {
            convertirNumerosEnArray($value);
        } elseif (is_string($value) && is_numeric($value)) {
            // Lista de campos que deben permanecer como string
            $camposString = ['telefono', 'celular', 'documento', 'ruc', 'codigo_postal', 'nit', 'clave', 'fecha', 'password', 'token', 'refresh_token'];
            
            if (!in_array($key, $camposString)) {
                // Convertir a número
                $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
            }
        }
    }
}

// Función helper global para responder JSON
function responderJSON($data, $code = 200) {
    // Convertir recursivamente los strings numéricos a números antes de enviar
    convertirNumerosEnArray($data);
    
    // Usar Flight::json en lugar de manejar la respuesta manualmente
    Flight::json($data, $code);
}

// Middleware para interceptar y convertir datos de entrada JSON
Flight::before('start', function(&$params, &$output) {
    $method = Flight::request()->method;
    
    // Solo procesar para POST, PUT, PATCH
    if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
        try {
            // Usar $_SERVER para obtener el Content-Type
            $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
            if (empty($contentType) && isset($_SERVER['HTTP_CONTENT_TYPE'])) {
                $contentType = $_SERVER['HTTP_CONTENT_TYPE'];
            }
            
            // Si es JSON
            if (stripos($contentType, 'application/json') !== false) {
                $body = Flight::request()->getBody();
                
                if (!empty($body)) {
                    $data = json_decode($body, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && $data !== null) {
                        // Convertir strings numéricas a números
                        convertirNumerosEnArray($data);
                        
                        // Sobrescribir los datos del request con los datos convertidos
                        Flight::request()->data->setData($data);
                    } else {
                        error_log("Error al decodificar JSON: " . json_last_error_msg());
                    }
                } else {
                    error_log("Body vacío");
                }
            }
        } catch (Exception $e) {
            error_log("ERROR en middleware: " . $e->getMessage());
        }
    }
});

// Sobreescribir Flight::json para que convierta números automáticamente
Flight::map('json', function($data, $code = 200, $encode = true) {
    // Ejecutamos la función de conversión
    convertirNumerosEnArray($data);
    
    // Configurar respuesta
    Flight::response()->status($code);
    Flight::response()->header('Content-Type', 'application/json; charset=utf-8');
    
    // Codificar y enviar
    $json = $encode ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data;
    Flight::response()->write($json);
});

require 'config.php';

// Cargar servicios automáticamente
// IMPORTANTE: Ordenar para que audit.service.php se cargue primero
$services = glob(__DIR__ . '/services/*.service.php');
sort($services);
foreach ($services as $serviceFile) {
    require_once $serviceFile;
}

// Configurar Flight
Flight::set('flight.base_url', '/');
Flight::set('flight.case_sensitive', false);

// Rutas separadas
foreach (glob(__DIR__ . '/routes/*.routes.php') as $routeFile) {
    require_once $routeFile;
}

// Ruta de prueba
Flight::route('GET /', function(){
    Flight::json(array(
        'message' => 'API Secretaría v1.0',
        'status' => 'OK',
        'version' => 1.0,
        'test_number' => "123",
        'test_string' => "password"
    ));
});

// Iniciar Flight
Flight::start();