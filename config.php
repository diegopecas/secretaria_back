<?php
// config.php
session_start();

// Configuración de base de datos
define('DB_HOST', '92.205.2.161');
define('DB_NAME', 'secretaria');
define('DB_USER', 'user_secretaria');
define('DB_PASS', 'pw_secretaria');

// Configuración de tokens
define('JWT_SECRET', 'tu_clave_secreta_aqui_cambiar_en_produccion');
define('TOKEN_EXPIRATION', 3600); // 1 hora para access token
define('REFRESH_TOKEN_EXPIRATION', 604800); // 7 días para refresh token

// Configurar zona horaria
date_default_timezone_set('America/Bogota');

// Configurar Flight
Flight::register('db', 'PDO', array('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS), 
    function($db){
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
);

// Configurar CORS
Flight::before('start', function(&$params, &$output){
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=UTF-8');
    
    if (Flight::request()->method == 'OPTIONS') {
        Flight::halt(200);
    }
});

// Funciones mejoradas de tokens
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length));
}

function generateTokenPair($userId) {
    $accessToken = generateSecureToken();
    $refreshToken = generateSecureToken();
    
    return [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_in' => TOKEN_EXPIRATION,
        'refresh_expires_in' => REFRESH_TOKEN_EXPIRATION
    ];
}

function validateToken($token) {
    if (empty($token)) {
        return false;
    }
    
    $db = Flight::db();
    $sentence = $db->prepare("
        SELECT u.id, u.nombre, u.email, s.id as sesion_id
        FROM usuarios u
        INNER JOIN sesiones s ON u.id = s.usuario_id
        WHERE s.token = :token 
        AND s.fecha_expiracion > NOW()
        AND u.activo = 1
    ");
    $sentence->bindParam(':token', $token);
    $sentence->execute();
    $user = $sentence->fetch();
    
    if ($user) {
        // Actualizar último uso
        $updateSentence = $db->prepare("
            UPDATE sesiones 
            SET fecha_ultimo_uso = NOW() 
            WHERE id = :sesion_id
        ");
        $updateSentence->bindParam(':sesion_id', $user['sesion_id']);
        $updateSentence->execute();
        
        return $user;
    }
    
    return false;
}

function validateRefreshToken($refreshToken) {
    if (empty($refreshToken)) {
        return false;
    }
    
    $db = Flight::db();
    $sentence = $db->prepare("
        SELECT s.*, u.id as user_id, u.nombre, u.email
        FROM sesiones s
        INNER JOIN usuarios u ON s.usuario_id = u.id
        WHERE s.refresh_token = :refresh_token 
        AND s.fecha_expiracion_refresh > NOW()
        AND u.activo = 1
    ");
    $sentence->bindParam(':refresh_token', $refreshToken);
    $sentence->execute();
    
    return $sentence->fetch();
}

// Middleware de autenticación mejorado
function requireAuth() {
    $headers = getallheaders();
    $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;
    
    if (!$token) {
        Flight::json(['error' => 'Token no proporcionado'], 401);
        Flight::stop();
    }
    
    $user = validateToken($token);
    
    if (!$user) {
        Flight::json(['error' => 'Token inválido o expirado'], 401);
        Flight::stop();
    }
    
    // Hacer disponible el usuario para las rutas
    Flight::set('currentUser', $user);
}

// Obtener información del dispositivo
function getDeviceInfo() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    // Simplificar user agent
    if (strpos($userAgent, 'Mobile') !== false) {
        $device = 'Mobile';
    } elseif (strpos($userAgent, 'Tablet') !== false) {
        $device = 'Tablet';
    } else {
        $device = 'Desktop';
    }
    
    // Detectar navegador
    if (strpos($userAgent, 'Chrome') !== false) {
        $device .= ' - Chrome';
    } elseif (strpos($userAgent, 'Firefox') !== false) {
        $device .= ' - Firefox';
    } elseif (strpos($userAgent, 'Safari') !== false) {
        $device .= ' - Safari';
    } elseif (strpos($userAgent, 'Edge') !== false) {
        $device .= ' - Edge';
    }
    
    return [
        'device' => $device,
        'ip' => $ip
    ];
}