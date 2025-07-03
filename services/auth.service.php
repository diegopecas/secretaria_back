<?php
class AuthService
{
    public static function login()
    {
        $db = Flight::db();
        
        // Obtener datos del request
        $rawData = file_get_contents('php://input');
        $jsonData = json_decode($rawData, true);
        
        $email = Flight::request()->data['email'] ?? $jsonData['email'] ?? null;
        $password = Flight::request()->data['password'] ?? $jsonData['password'] ?? null;
        
        // Buscar usuario
        $sentence = $db->prepare("SELECT id, nombre, email, password FROM usuarios WHERE email = :email AND activo = 1");
        $sentence->bindParam(':email', $email);
        $sentence->execute();
        $user = $sentence->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            // Generar tokens
            $tokens = generateTokenPair($user['id']);
            $deviceInfo = getDeviceInfo();
            
            // Eliminar sesiones anteriores del mismo dispositivo
            $deleteSentence = $db->prepare("
                DELETE FROM sesiones 
                WHERE usuario_id = :usuario_id 
                AND dispositivo = :dispositivo
            ");
            $deleteSentence->bindParam(':usuario_id', $user['id']);
            $deleteSentence->bindParam(':dispositivo', $deviceInfo['device']);
            $deleteSentence->execute();
            
            // Crear nueva sesión con refresh token
            $insertSentence = $db->prepare("
                INSERT INTO sesiones (
                    usuario_id, token, refresh_token, 
                    fecha_expiracion, fecha_expiracion_refresh,
                    dispositivo, ip_address
                ) VALUES (
                    :usuario_id, :token, :refresh_token,
                    DATE_ADD(NOW(), INTERVAL :token_exp SECOND),
                    DATE_ADD(NOW(), INTERVAL :refresh_exp SECOND),
                    :dispositivo, :ip
                )
            ");
            $insertSentence->bindParam(':usuario_id', $user['id']);
            $insertSentence->bindParam(':token', $tokens['access_token']);
            $insertSentence->bindParam(':refresh_token', $tokens['refresh_token']);
            $insertSentence->bindParam(':token_exp', $tokens['expires_in']);
            $insertSentence->bindParam(':refresh_exp', $tokens['refresh_expires_in']);
            $insertSentence->bindParam(':dispositivo', $deviceInfo['device']);
            $insertSentence->bindParam(':ip', $deviceInfo['ip']);
            $insertSentence->execute();
            
            // Obtener roles del usuario
            $roles = self::getUserRoles($user['id']);
            
            // Obtener permisos del usuario
            $permisos = self::getUserPermissions($user['id']);
            
            // Retornar datos del usuario, tokens, roles y permisos
            Flight::json(array(
                'success' => true,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_in' => $tokens['expires_in'],
                'user' => array(
                    'id' => $user['id'],
                    'nombre' => $user['nombre'],
                    'email' => $user['email'],
                    'roles' => $roles,
                    'permisos' => $permisos
                )
            ));
        } else {
            Flight::json(array('error' => 'Credenciales incorrectas'), 401);
        }
    }
    
    public static function logout()
    {
        $headers = getallheaders();
        $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;
        
        if ($token) {
            $db = Flight::db();
            $sentence = $db->prepare("DELETE FROM sesiones WHERE token = :token");
            $sentence->bindParam(':token', $token);
            $sentence->execute();
        }
        
        Flight::json(array('success' => true, 'message' => 'Sesión cerrada correctamente'));
    }
    
    public static function validateSession()
    {
        $headers = getallheaders();
        $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;
        
        if ($token) {
            $user = validateToken($token);
            if ($user) {
                // Agregar roles y permisos
                $user['roles'] = self::getUserRoles($user['id']);
                $user['permisos'] = self::getUserPermissions($user['id']);
                
                Flight::json(array('success' => true, 'user' => $user));
                return;
            }
        }
        
        Flight::json(array('error' => 'Sesión inválida'), 401);
    }
    
    public static function register()
    {
        $db = Flight::db();
        
        // Obtener datos del request
        $rawData = file_get_contents('php://input');
        $jsonData = json_decode($rawData, true);
        
        $nombre = Flight::request()->data['nombre'] ?? $jsonData['nombre'] ?? null;
        $email = Flight::request()->data['email'] ?? $jsonData['email'] ?? null;
        $password = Flight::request()->data['password'] ?? $jsonData['password'] ?? null;
        
        // Validar que el email no exista
        $checkSentence = $db->prepare("SELECT id FROM usuarios WHERE email = :email");
        $checkSentence->bindParam(':email', $email);
        $checkSentence->execute();
        
        if ($checkSentence->fetch()) {
            Flight::json(array('error' => 'El email ya está registrado'), 400);
            return;
        }
        
        // Encriptar contraseña
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Iniciar transacción
        $db->beginTransaction();
        
        try {
            // Insertar usuario
            $sentence = $db->prepare("INSERT INTO usuarios (nombre, email, password) VALUES (:nombre, :email, :password)");
            $sentence->bindParam(':nombre', $nombre);
            $sentence->bindParam(':email', $email);
            $sentence->bindParam(':password', $hashedPassword);
            $sentence->execute();
            
            $userId = $db->lastInsertId();
            
            // Asignar rol por defecto (usuario)
            $rolSentence = $db->prepare("
                INSERT INTO usuarios_roles (usuario_id, rol_id) 
                VALUES (:usuario_id, (SELECT id FROM roles WHERE nombre = 'usuario'))
            ");
            $rolSentence->bindParam(':usuario_id', $userId);
            $rolSentence->execute();
            
            $db->commit();
            
            Flight::json(array(
                'success' => true,
                'message' => 'Usuario registrado correctamente',
                'user' => array(
                    'id' => $userId,
                    'nombre' => $nombre,
                    'email' => $email,
                    'roles' => ['usuario']
                )
            ));
        } catch (Exception $e) {
            $db->rollBack();
            Flight::json(array('error' => 'Error al registrar usuario'), 500);
        }
    }
    
    // Obtener roles de un usuario
    private static function getUserRoles($userId)
    {
        $db = Flight::db();
        $sentence = $db->prepare("
            SELECT r.nombre, r.descripcion 
            FROM roles r
            INNER JOIN usuarios_roles ur ON r.id = ur.rol_id
            WHERE ur.usuario_id = :usuario_id AND r.activo = 1
        ");
        $sentence->bindParam(':usuario_id', $userId);
        $sentence->execute();
        
        $roles = [];
        while ($row = $sentence->fetch(PDO::FETCH_ASSOC)) {
            $roles[] = $row['nombre'];
        }

        
        return $roles;
    }
    
    // Obtener permisos de un usuario
    private static function getUserPermissions($userId)
    {
        $db = Flight::db();
        $sentence = $db->prepare("
            SELECT DISTINCT p.nombre, p.modulo
            FROM permisos p
            INNER JOIN roles_permisos rp ON p.id = rp.permiso_id
            INNER JOIN roles r ON rp.rol_id = r.id
            INNER JOIN usuarios_roles ur ON r.id = ur.rol_id
            WHERE ur.usuario_id = :usuario_id 
            AND p.activo = 1 
            AND r.activo = 1
        ");
        $sentence->bindParam(':usuario_id', $userId);
        $sentence->execute();
        
        $permisos = [];
        while ($row = $sentence->fetch(PDO::FETCH_ASSOC)) {
            $permisos[] = $row['nombre'];
        }
        
        return $permisos;
    }
    
    // Verificar si un usuario tiene un permiso específico
    public static function checkPermission($userId, $permiso)
    {
        $db = Flight::db();
        $sentence = $db->prepare("
            SELECT COUNT(*) as tiene_permiso
            FROM v_usuarios_permisos
            WHERE usuario_id = :usuario_id AND permiso_nombre = :permiso
        ");
        $sentence->bindParam(':usuario_id', $userId);
        $sentence->bindParam(':permiso', $permiso);
        $sentence->execute();
        
        $result = $sentence->fetch(PDO::FETCH_ASSOC);
        return $result['tiene_permiso'] > 0;
    }
    
    // Renovar token usando refresh token
    public static function refreshToken()
    {
        $db = Flight::db();
        
        // Obtener refresh token del request
        $rawData = file_get_contents('php://input');
        $jsonData = json_decode($rawData, true);
        
        $refreshToken = Flight::request()->data['refresh_token'] ?? $jsonData['refresh_token'] ?? null;
        
        if (!$refreshToken) {
            Flight::json(array('error' => 'Refresh token no proporcionado'), 400);
            return;
        }
        
        // Validar refresh token
        $session = validateRefreshToken($refreshToken);
        
        if (!$session) {
            Flight::json(array('error' => 'Refresh token inválido o expirado'), 401);
            return;
        }
        
        // Generar nuevos tokens
        $tokens = generateTokenPair($session['user_id']);
        $deviceInfo = getDeviceInfo();
        
        // Actualizar sesión con nuevos tokens
        $updateSentence = $db->prepare("
            UPDATE sesiones SET
                token = :token,
                refresh_token = :refresh_token,
                fecha_expiracion = DATE_ADD(NOW(), INTERVAL :token_exp SECOND),
                fecha_expiracion_refresh = DATE_ADD(NOW(), INTERVAL :refresh_exp SECOND),
                ip_address = :ip,
                fecha_ultimo_uso = NOW()
            WHERE id = :sesion_id
        ");
        $updateSentence->bindParam(':token', $tokens['access_token']);
        $updateSentence->bindParam(':refresh_token', $tokens['refresh_token']);
        $updateSentence->bindParam(':token_exp', $tokens['expires_in']);
        $updateSentence->bindParam(':refresh_exp', $tokens['refresh_expires_in']);
        $updateSentence->bindParam(':ip', $deviceInfo['ip']);
        $updateSentence->bindParam(':sesion_id', $session['id']);
        $updateSentence->execute();
        
        // Obtener roles y permisos actualizados
        $roles = self::getUserRoles($session['user_id']);
        $permisos = self::getUserPermissions($session['user_id']);
        
        Flight::json(array(
            'success' => true,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => $tokens['expires_in'],
            'user' => array(
                'id' => $session['user_id'],
                'nombre' => $session['nombre'],
                'email' => $session['email'],
                'roles' => $roles,
                'permisos' => $permisos
            )
        ));
    }
    
    // Obtener sesiones activas del usuario
    public static function getSessions()
    {
        requireAuth();
        $currentUser = Flight::get('currentUser');
        
        $db = Flight::db();
        $sentence = $db->prepare("
            SELECT 
                dispositivo,
                ip_address,
                fecha_ultimo_uso,
                CASE 
                    WHEN token = :current_token THEN 1
                    ELSE 0
                END as es_actual
            FROM sesiones
            WHERE usuario_id = :usuario_id
            AND fecha_expiracion_refresh > NOW()
            ORDER BY fecha_ultimo_uso DESC
        ");
        
        $headers = getallheaders();
        $currentToken = str_replace('Bearer ', '', $headers['Authorization']);
        
        $sentence->bindParam(':usuario_id', $currentUser['id']);
        $sentence->bindParam(':current_token', $currentToken);
        $sentence->execute();
        
        $sessions = $sentence->fetchAll();
        
        Flight::json(array(
            'success' => true,
            'sessions' => $sessions
        ));
    }
    
    // Cerrar todas las sesiones excepto la actual
    public static function logoutAll()
    {
        requireAuth();
        $currentUser = Flight::get('currentUser');
        
        $headers = getallheaders();
        $currentToken = str_replace('Bearer ', '', $headers['Authorization']);
        
        $db = Flight::db();
        $sentence = $db->prepare("
            DELETE FROM sesiones 
            WHERE usuario_id = :usuario_id 
            AND token != :current_token
        ");
        $sentence->bindParam(':usuario_id', $currentUser['id']);
        $sentence->bindParam(':current_token', $currentToken);
        $sentence->execute();
        
        Flight::json(array(
            'success' => true,
            'message' => 'Todas las demás sesiones han sido cerradas'
        ));
    }
}