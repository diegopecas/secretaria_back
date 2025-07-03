<?php
class UsuariosService
{
    // Obtener todos los usuarios
    public static function obtenerTodos()
    {
        try {
            // Debug: Log de inicio
            error_log("UsuariosService::obtenerTodos - Iniciando");
            
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Debug: Usuario actual
            error_log("Usuario actual: " . json_encode($currentUser));
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'usuarios.ver')) {
                error_log("Usuario sin permisos para ver usuarios");
                Flight::json(array('error' => 'No tiene permisos para ver usuarios'), 403);
                return;
            }
            
            $db = Flight::db();
            
            // Query simplificada para debug
            $sentence = $db->prepare("
                SELECT 
                    u.id,
                    u.nombre,
                    u.email,
                    u.activo,
                    u.fecha_creacion,
                    u.fecha_actualizacion
                FROM usuarios u
                ORDER BY u.nombre ASC
            ");
            
            error_log("Ejecutando query de usuarios");
            $sentence->execute();
            $usuarios = $sentence->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Usuarios encontrados: " . count($usuarios));
            
            // Agregar roles a cada usuario
            foreach ($usuarios as &$usuario) {
                $usuario['roles'] = self::obtenerRolesUsuario($usuario['id']);
                // Convertir activo a booleano
                $usuario['activo'] = (bool)$usuario['activo'];
                
                // Agregar ultimo_acceso (puede ser null)
                $usuario['ultimo_acceso'] = self::obtenerUltimoAcceso($usuario['id']);
            }
            
            error_log("Retornando usuarios con roles");
            Flight::json($usuarios);
            
        } catch (Exception $e) {
            error_log("ERROR en obtenerTodos: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            Flight::json(array('error' => 'Error al obtener usuarios: ' . $e->getMessage()), 500);
        }
    }
    
    // Obtener el último acceso de un usuario
    private static function obtenerUltimoAcceso($userId)
    {
        try {
            $db = Flight::db();
            $sentence = $db->prepare("
                SELECT MAX(fecha_ultimo_uso) as ultimo_acceso 
                FROM sesiones 
                WHERE usuario_id = :usuario_id
            ");
            $sentence->bindParam(':usuario_id', $userId);
            $sentence->execute();
            $result = $sentence->fetch(PDO::FETCH_ASSOC);
            
            return $result['ultimo_acceso'];
        } catch (Exception $e) {
            error_log("Error obteniendo último acceso para usuario $userId: " . $e->getMessage());
            return null;
        }
    }
    
    // Obtener usuario por ID
    public static function obtenerPorId($id)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // El ID viene como parámetro de la ruta, no del body
            error_log("Obteniendo usuario con ID: " . $id);
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'usuarios.ver')) {
                Flight::json(array('error' => 'No tiene permisos para ver usuarios'), 403);
                return;
            }
            
            $db = Flight::db();
            
            $sentence = $db->prepare("
                SELECT 
                    u.id,
                    u.nombre,
                    u.email,
                    u.activo,
                    u.fecha_creacion,
                    u.fecha_actualizacion
                FROM usuarios u
                WHERE u.id = :id
            ");
            $sentence->bindParam(':id', $id);
            $sentence->execute();
            $usuario = $sentence->fetch(PDO::FETCH_ASSOC);
            
            if (!$usuario) {
                Flight::json(array('error' => 'Usuario no encontrado'), 404);
                return;
            }
            
            // Agregar roles y último acceso
            $usuario['roles'] = self::obtenerRolesUsuario($usuario['id']);
            $usuario['activo'] = (bool)$usuario['activo'];
            $usuario['ultimo_acceso'] = self::obtenerUltimoAcceso($usuario['id']);
            
            Flight::json($usuario);
            
        } catch (Exception $e) {
            error_log("Error al obtener usuario por ID: " . $e->getMessage());
            Flight::json(array('error' => 'Error al obtener usuario'), 500);
        }
    }
    
    // Crear usuario
    public static function crear()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'usuarios.crear')) {
                Flight::json(array('error' => 'No tiene permisos para crear usuarios'), 403);
                return;
            }
            
            // Obtener datos del request
            $rawData = file_get_contents('php://input');
            $data = json_decode($rawData, true);
            
            error_log("Datos recibidos para crear usuario: " . json_encode($data));
            
            $nombre = $data['nombre'] ?? null;
            $email = $data['email'] ?? null;
            $password = $data['password'] ?? null;
            $roles = $data['roles'] ?? ['usuario'];
            $activo = isset($data['activo']) ? (bool)$data['activo'] : true;
            
            // Validaciones
            if (!$nombre || !$email || !$password) {
                Flight::json(array('error' => 'Datos incompletos'), 400);
                return;
            }
            
            $db = Flight::db();
            
            // Verificar que el email no exista
            $checkSentence = $db->prepare("SELECT id FROM usuarios WHERE email = :email");
            $checkSentence->bindParam(':email', $email);
            $checkSentence->execute();
            
            if ($checkSentence->fetch()) {
                Flight::json(array('error' => 'El email ya está registrado'), 400);
                return;
            }
            
            // Encriptar contraseña
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            $db->beginTransaction();
            
            // Insertar usuario
            $sentence = $db->prepare("
                INSERT INTO usuarios (nombre, email, password, activo) 
                VALUES (:nombre, :email, :password, :activo)
            ");
            $sentence->bindParam(':nombre', $nombre);
            $sentence->bindParam(':email', $email);
            $sentence->bindParam(':password', $hashedPassword);
            $sentence->bindParam(':activo', $activo, PDO::PARAM_BOOL);
            $sentence->execute();
            
            $userId = $db->lastInsertId();
            
            // Asignar roles
            self::asignarRolesUsuario($userId, $roles);
            
            $db->commit();
            
            Flight::json(array(
                'success' => true,
                'id' => $userId,
                'message' => 'Usuario creado correctamente'
            ));
            
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            error_log("Error al crear usuario: " . $e->getMessage());
            Flight::json(array('error' => 'Error al crear usuario: ' . $e->getMessage()), 500);
        }
    }
    
    // Actualizar usuario
    public static function actualizar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'usuarios.editar')) {
                Flight::json(array('error' => 'No tiene permisos para editar usuarios'), 403);
                return;
            }
            
            // Obtener datos del request
            $rawData = file_get_contents('php://input');
            $data = json_decode($rawData, true);
            
            error_log("Datos para actualizar: " . json_encode($data));
            
            $id = $data['id'] ?? null;
            
            if (!$id) {
                Flight::json(array('error' => 'ID no proporcionado'), 400);
                return;
            }
            
            $db = Flight::db();
            $db->beginTransaction();
            
            // Construir query dinámicamente
            $updates = [];
            $params = [':id' => $id];
            
            if (isset($data['nombre'])) {
                $updates[] = "nombre = :nombre";
                $params[':nombre'] = $data['nombre'];
            }
            
            if (isset($data['email'])) {
                // Verificar que el email no esté en uso por otro usuario
                $checkSentence = $db->prepare("SELECT id FROM usuarios WHERE email = :email AND id != :id");
                $checkSentence->bindParam(':email', $data['email']);
                $checkSentence->bindParam(':id', $id);
                $checkSentence->execute();
                
                if ($checkSentence->fetch()) {
                    $db->rollBack();
                    Flight::json(array('error' => 'El email ya está en uso'), 400);
                    return;
                }
                
                $updates[] = "email = :email";
                $params[':email'] = $data['email'];
            }
            
            if (isset($data['password']) && !empty($data['password'])) {
                $updates[] = "password = :password";
                $params[':password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            
            if (isset($data['activo'])) {
                $updates[] = "activo = :activo";
                $params[':activo'] = (bool)$data['activo'];
            }
            
            if (!empty($updates)) {
                $sql = "UPDATE usuarios SET " . implode(", ", $updates) . ", fecha_actualizacion = NOW() WHERE id = :id";
                $sentence = $db->prepare($sql);
                
                foreach ($params as $key => $value) {
                    $sentence->bindValue($key, $value);
                }
                
                $sentence->execute();
            }
            
            // Actualizar roles si se proporcionaron
            if (isset($data['roles']) && is_array($data['roles'])) {
                self::asignarRolesUsuario($id, $data['roles']);
            }
            
            $db->commit();
            
            Flight::json(array(
                'success' => true,
                'id' => $id,
                'message' => 'Usuario actualizado correctamente'
            ));
            
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            error_log("Error al actualizar usuario: " . $e->getMessage());
            Flight::json(array('error' => 'Error al actualizar usuario: ' . $e->getMessage()), 500);
        }
    }
    
    // Eliminar usuario
    public static function eliminar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'usuarios.eliminar')) {
                Flight::json(array('error' => 'No tiene permisos para eliminar usuarios'), 403);
                return;
            }
            
            // Obtener datos del request
            $rawData = file_get_contents('php://input');
            $data = json_decode($rawData, true);
            
            $id = $data['id'] ?? null;
            
            error_log("Intentando eliminar usuario ID: " . $id);
            
            if (!$id) {
                Flight::json(array('error' => 'ID no proporcionado'), 400);
                return;
            }
            
            // No permitir eliminar el usuario actual
            if ($id == $currentUser['id']) {
                Flight::json(array('error' => 'No puede eliminar su propio usuario'), 400);
                return;
            }
            
            $db = Flight::db();
            
            // En lugar de eliminar físicamente, desactivar el usuario
            $sentence = $db->prepare("UPDATE usuarios SET activo = 0 WHERE id = :id");
            $sentence->bindParam(':id', $id);
            $sentence->execute();
            
            // Cerrar todas las sesiones del usuario
            $deleteSessions = $db->prepare("DELETE FROM sesiones WHERE usuario_id = :id");
            $deleteSessions->bindParam(':id', $id);
            $deleteSessions->execute();
            
            Flight::json(array(
                'success' => true,
                'id' => $id,
                'message' => 'Usuario eliminado correctamente'
            ));
            
        } catch (Exception $e) {
            error_log("Error al eliminar usuario: " . $e->getMessage());
            Flight::json(array('error' => 'Error al eliminar usuario: ' . $e->getMessage()), 500);
        }
    }
    
    // Cambiar estado de usuario
    public static function cambiarEstado()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'usuarios.editar')) {
                Flight::json(array('error' => 'No tiene permisos para cambiar estado de usuarios'), 403);
                return;
            }
            
            // Obtener datos del request
            $rawData = file_get_contents('php://input');
            $data = json_decode($rawData, true);
            
            error_log("Cambiar estado - datos recibidos: " . json_encode($data));
            
            $id = $data['id'] ?? null;
            $activo = isset($data['activo']) ? (bool)$data['activo'] : null;
            
            if (!$id || $activo === null) {
                Flight::json(array('error' => 'Datos incompletos'), 400);
                return;
            }
            
            $db = Flight::db();
            
            $sentence = $db->prepare("UPDATE usuarios SET activo = :activo WHERE id = :id");
            $sentence->bindParam(':id', $id);
            $sentence->bindParam(':activo', $activo, PDO::PARAM_BOOL);
            $sentence->execute();
            
            // Si se desactiva, cerrar sesiones
            if (!$activo) {
                $deleteSessions = $db->prepare("DELETE FROM sesiones WHERE usuario_id = :id");
                $deleteSessions->bindParam(':id', $id);
                $deleteSessions->execute();
            }
            
            Flight::json(array(
                'success' => true,
                'message' => $activo ? 'Usuario activado' : 'Usuario desactivado'
            ));
            
        } catch (Exception $e) {
            error_log("Error al cambiar estado: " . $e->getMessage());
            Flight::json(array('error' => 'Error al cambiar estado: ' . $e->getMessage()), 500);
        }
    }
    
    // Asignar roles a usuario
    public static function asignarRoles()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'usuarios.roles')) {
                Flight::json(array('error' => 'No tiene permisos para gestionar roles'), 403);
                return;
            }
            
            // Obtener datos del request
            $rawData = file_get_contents('php://input');
            $data = json_decode($rawData, true);
            
            $id = $data['id'] ?? null;
            $roles = $data['roles'] ?? [];
            
            if (!$id) {
                Flight::json(array('error' => 'ID no proporcionado'), 400);
                return;
            }
            
            self::asignarRolesUsuario($id, $roles);
            
            Flight::json(array(
                'success' => true,
                'message' => 'Roles asignados correctamente'
            ));
            
        } catch (Exception $e) {
            error_log("Error al asignar roles: " . $e->getMessage());
            Flight::json(array('error' => 'Error al asignar roles: ' . $e->getMessage()), 500);
        }
    }
    
    // Funciones auxiliares
    private static function obtenerRolesUsuario($userId)
    {
        try {
            $db = Flight::db();
            $sentence = $db->prepare("
                SELECT r.nombre
                FROM roles r
                INNER JOIN usuarios_roles ur ON r.id = ur.rol_id
                WHERE ur.usuario_id = :usuario_id
            ");
            $sentence->bindParam(':usuario_id', $userId);
            $sentence->execute();
            
            $roles = [];
            while ($row = $sentence->fetch(PDO::FETCH_ASSOC)) {
                $roles[] = $row['nombre'];
            }
            
            return $roles;
        } catch (Exception $e) {
            error_log("Error obteniendo roles para usuario $userId: " . $e->getMessage());
            return [];
        }
    }
    
    private static function asignarRolesUsuario($userId, $roles)
    {
        try {
            $db = Flight::db();
            
            // Eliminar roles actuales
            $deleteSentence = $db->prepare("DELETE FROM usuarios_roles WHERE usuario_id = :usuario_id");
            $deleteSentence->bindParam(':usuario_id', $userId);
            $deleteSentence->execute();
            
            // Asignar nuevos roles
            if (!empty($roles)) {
                $insertSentence = $db->prepare("
                    INSERT INTO usuarios_roles (usuario_id, rol_id)
                    SELECT :usuario_id, id FROM roles WHERE nombre = :rol
                ");
                
                foreach ($roles as $rol) {
                    $insertSentence->bindParam(':usuario_id', $userId);
                    $insertSentence->bindParam(':rol', $rol);
                    $insertSentence->execute();
                }
            }
        } catch (Exception $e) {
            error_log("Error asignando roles a usuario $userId: " . $e->getMessage());
            throw $e;
        }
    }
}