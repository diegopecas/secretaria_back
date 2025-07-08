<?php
class AuthService
{
    public static function login()
    {
        $db = Flight::db();
        
        // Obtener datos del request
        $data = Flight::request()->data->getData();
        
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        
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
            
            $sesionId = $db->lastInsertId();
            
            // AUDITORÍA - Registrar inicio de sesión
            $datosAuditoria = [
                'usuario_id' => $user['id'],
                'email' => $user['email'],
                'dispositivo' => $deviceInfo['device'],
                'ip' => $deviceInfo['ip'],
                'accion' => 'LOGIN_SUCCESS'
            ];
            
            AuditService::registrar('sesiones', $sesionId, 'CREATE', null, $datosAuditoria, $user);
            
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
            // AUDITORÍA - Registrar intento fallido de login
            $datosAuditoria = [
                'email_intento' => $email,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'accion' => 'LOGIN_FAILED'
            ];
            
            // Para intentos fallidos, usamos usuario_id = 0
            AuditService::registrar('intentos_login', 0, 'CREATE', null, $datosAuditoria);
            
            Flight::json(array('error' => 'Credenciales incorrectas'), 401);
        }
    }
    
    public static function logout()
    {
        $headers = getallheaders();
        $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;
        
        if ($token) {
            $db = Flight::db();
            
            // Obtener información de la sesión antes de eliminarla
            $selectSentence = $db->prepare("
                SELECT s.*, u.nombre, u.email 
                FROM sesiones s 
                INNER JOIN usuarios u ON s.usuario_id = u.id
                WHERE s.token = :token
            ");
            $selectSentence->bindParam(':token', $token);
            $selectSentence->execute();
            $sesion = $selectSentence->fetch();
            
            if ($sesion) {
                // Eliminar la sesión
                $sentence = $db->prepare("DELETE FROM sesiones WHERE token = :token");
                $sentence->bindParam(':token', $token);
                $sentence->execute();
                
                // AUDITORÍA - Registrar cierre de sesión
                $datosAuditoria = [
                    'usuario_id' => $sesion['usuario_id'],
                    'email' => $sesion['email'],
                    'dispositivo' => $sesion['dispositivo'],
                    'ip' => $sesion['ip_address'],
                    'accion' => 'LOGOUT'
                ];
                
                $usuario = [
                    'id' => $sesion['usuario_id'],
                    'nombre' => $sesion['nombre']
                ];
                
                AuditService::registrar('sesiones', $sesion['id'], 'DELETE', $datosAuditoria, null, $usuario);
            }
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
        $data = Flight::request()->data->getData();
        
        $nombre = $data['nombre'] ?? null;
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        
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
            
            // AUDITORÍA - Registrar registro de usuario
            $datosNuevos = [
                'id' => $userId,
                'nombre' => $nombre,
                'email' => $email,
                'roles' => ['usuario'],
                'origen' => 'SELF_REGISTER'
            ];
            
            // En el registro, el usuario se crea a sí mismo
            $usuario = [
                'id' => $userId,
                'nombre' => $nombre
            ];
            
            AuditService::registrar('usuarios', $userId, 'CREATE', null, $datosNuevos, $usuario);
            
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
            
            // AUDITORÍA - Registrar fallo en registro
            $datosAuditoria = [
                'email_intento' => $email,
                'error' => $e->getMessage(),
                'accion' => 'REGISTER_FAILED'
            ];
            
            AuditService::registrar('intentos_registro', 0, 'CREATE', null, $datosAuditoria);
            
            Flight::json(array('error' => 'Error al registrar usuario'), 500);
        }
    }
    
    // Obtener roles de un usuario
    public static function getUserRoles($userId)
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
            FROM permisos p
            INNER JOIN roles_permisos rp ON p.id = rp.permiso_id
            INNER JOIN roles r ON rp.rol_id = r.id
            INNER JOIN usuarios_roles ur ON r.id = ur.rol_id
            WHERE ur.usuario_id = :usuario_id 
            AND p.nombre = :permiso
            AND p.activo = 1 
            AND r.activo = 1
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
        $data = Flight::request()->data->getData();
        
        $refreshToken = $data['refresh_token'] ?? null;
        
        if (!$refreshToken) {
            Flight::json(array('error' => 'Refresh token no proporcionado'), 400);
            return;
        }
        
        // Validar refresh token
        $session = validateRefreshToken($refreshToken);
        
        if (!$session) {
            // AUDITORÍA - Intento de renovación con token inválido
            $datosAuditoria = [
                'refresh_token' => substr($refreshToken, 0, 10) . '...', // Solo primeros caracteres por seguridad
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'accion' => 'REFRESH_TOKEN_FAILED'
            ];
            
            AuditService::registrar('intentos_refresh', 0, 'CREATE', null, $datosAuditoria);
            
            Flight::json(array('error' => 'Refresh token inválido o expirado'), 401);
            return;
        }
        
        // Generar nuevos tokens
        $tokens = generateTokenPair($session['user_id']);
        $deviceInfo = getDeviceInfo();
        
        // Datos anteriores de la sesión
        $datosAnteriores = [
            'token' => substr($session['token'], 0, 10) . '...',
            'refresh_token' => substr($session['refresh_token'], 0, 10) . '...',
            'fecha_expiracion' => $session['fecha_expiracion']
        ];
        
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
        
        // Datos nuevos de la sesión
        $datosNuevos = [
            'token' => substr($tokens['access_token'], 0, 10) . '...',
            'refresh_token' => substr($tokens['refresh_token'], 0, 10) . '...',
            'ip' => $deviceInfo['ip'],
            'accion' => 'TOKEN_REFRESHED'
        ];
        
        // AUDITORÍA - Registrar renovación de token
        $usuario = [
            'id' => $session['user_id'],
            'nombre' => $session['nombre']
        ];
        
        AuditService::registrar('sesiones', $session['id'], 'UPDATE', $datosAnteriores, $datosNuevos, $usuario);
        
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
        
        // Obtener las sesiones que se van a eliminar para auditoría
        $selectSentence = $db->prepare("
            SELECT id, dispositivo, ip_address 
            FROM sesiones 
            WHERE usuario_id = :usuario_id 
            AND token != :current_token
        ");
        $selectSentence->bindParam(':usuario_id', $currentUser['id']);
        $selectSentence->bindParam(':current_token', $currentToken);
        $selectSentence->execute();
        $sesionesEliminadas = $selectSentence->fetchAll();
        
        // Eliminar las sesiones
        $sentence = $db->prepare("
            DELETE FROM sesiones 
            WHERE usuario_id = :usuario_id 
            AND token != :current_token
        ");
        $sentence->bindParam(':usuario_id', $currentUser['id']);
        $sentence->bindParam(':current_token', $currentToken);
        $sentence->execute();
        
        // AUDITORÍA - Registrar cierre masivo de sesiones
        if (count($sesionesEliminadas) > 0) {
            $datosAuditoria = [
                'usuario_id' => $currentUser['id'],
                'sesiones_cerradas' => count($sesionesEliminadas),
                'dispositivos' => array_column($sesionesEliminadas, 'dispositivo'),
                'accion' => 'LOGOUT_ALL_SESSIONS'
            ];
            
            AuditService::registrar('sesiones', 0, 'DELETE', $datosAuditoria, null);
        }
        
        Flight::json(array(
            'success' => true,
            'message' => 'Todas las demás sesiones han sido cerradas'
        ));
    }
}