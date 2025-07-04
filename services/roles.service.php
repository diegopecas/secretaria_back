<?php
class RolesService
{
    // Obtener todos los roles activos
    public static function obtenerTodos()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'usuarios.roles')) {
                Flight::json(array('error' => 'No tiene permisos para ver roles'), 403);
                return;
            }

            $db = Flight::db();

            $sentence = $db->prepare("
                SELECT 
                    id,
                    nombre,
                    descripcion,
                    activo
                FROM roles
                WHERE activo = 1
                ORDER BY nombre ASC
            ");

            $sentence->execute();
            $roles = $sentence->fetchAll(PDO::FETCH_ASSOC);

            Flight::json($roles);
        } catch (Exception $e) {
            error_log("ERROR en obtenerTodos roles: " . $e->getMessage());
            Flight::json(array('error' => 'Error al obtener roles'), 500);
        }
    }

    // Obtener permisos de un rol
    public static function obtenerPermisos($rolId)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'usuarios.roles')) {
                Flight::json(array('error' => 'No tiene permisos para ver permisos de roles'), 403);
                return;
            }

            $db = Flight::db();

            $sentence = $db->prepare("
                SELECT 
                    p.id,
                    p.nombre,
                    p.modulo,
                    p.descripcion
                FROM permisos p
                INNER JOIN roles_permisos rp ON p.id = rp.permiso_id
                WHERE rp.rol_id = :rol_id
                AND p.activo = 1
                ORDER BY p.modulo, p.nombre
            ");
            $sentence->bindParam(':rol_id', $rolId);
            $sentence->execute();

            $permisos = $sentence->fetchAll(PDO::FETCH_ASSOC);

            Flight::json($permisos);
        } catch (Exception $e) {
            error_log("ERROR en obtenerPermisos: " . $e->getMessage());
            Flight::json(array('error' => 'Error al obtener permisos'), 500);
        }
    }
    // Obtener rol por ID
    public static function obtenerPorId($id)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'roles.ver')) {
                Flight::json(array('error' => 'No tiene permisos para ver roles'), 403);
                return;
            }

            $db = Flight::db();

            $sentence = $db->prepare("
            SELECT 
                id,
                nombre,
                descripcion,
                activo
            FROM roles
            WHERE id = :id
        ");
            $sentence->bindParam(':id', $id);
            $sentence->execute();

            $rol = $sentence->fetch(PDO::FETCH_ASSOC);

            if (!$rol) {
                Flight::json(array('error' => 'Rol no encontrado'), 404);
                return;
            }

            Flight::json($rol);
        } catch (Exception $e) {
            error_log("ERROR en obtenerPorId: " . $e->getMessage());
            Flight::json(array('error' => 'Error al obtener rol'), 500);
        }
    }

    // Crear nuevo rol
    public static function crear()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'roles.crear')) {
                Flight::json(array('error' => 'No tiene permisos para crear roles'), 403);
                return;
            }

            $data = Flight::request()->data->getData();

            $nombre = $data['nombre'] ?? null;
            $descripcion = $data['descripcion'] ?? null;

            if (!$nombre || !$descripcion) {
                Flight::json(array('error' => 'Datos incompletos'), 400);
                return;
            }

            $db = Flight::db();

            // Verificar que el nombre no exista
            $checkSentence = $db->prepare("SELECT id FROM roles WHERE nombre = :nombre");
            $checkSentence->bindParam(':nombre', $nombre);
            $checkSentence->execute();

            if ($checkSentence->fetch()) {
                Flight::json(array('error' => 'Ya existe un rol con ese nombre'), 400);
                return;
            }

            // Crear el rol
            $sentence = $db->prepare("
            INSERT INTO roles (nombre, descripcion, activo) 
            VALUES (:nombre, :descripcion, 1)
        ");
            $sentence->bindParam(':nombre', $nombre);
            $sentence->bindParam(':descripcion', $descripcion);
            $sentence->execute();

            $rolId = $db->lastInsertId();

            // AUDITORÍA
            $datosNuevos = [
                'id' => $rolId,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'activo' => true
            ];

            AuditService::registrar('roles', $rolId, 'CREATE', null, $datosNuevos);

            Flight::json(array(
                'success' => true,
                'id' => $rolId,
                'message' => 'Rol creado correctamente'
            ));
        } catch (Exception $e) {
            error_log("ERROR en crear rol: " . $e->getMessage());
            Flight::json(array('error' => 'Error al crear rol'), 500);
        }
    }

    // Actualizar rol
    public static function actualizar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'roles.editar')) {
                Flight::json(array('error' => 'No tiene permisos para editar roles'), 403);
                return;
            }

            $data = Flight::request()->data->getData();

            $id = $data['id'] ?? null;
            $nombre = $data['nombre'] ?? null;
            $descripcion = $data['descripcion'] ?? null;

            if (!$id || !$nombre || !$descripcion) {
                Flight::json(array('error' => 'Datos incompletos'), 400);
                return;
            }

            $db = Flight::db();

            // Obtener datos anteriores para auditoría
            $stmtAnterior = $db->prepare("SELECT * FROM roles WHERE id = :id");
            $stmtAnterior->bindParam(':id', $id);
            $stmtAnterior->execute();
            $datosAnteriores = $stmtAnterior->fetch();

            if (!$datosAnteriores) {
                Flight::json(array('error' => 'Rol no encontrado'), 404);
                return;
            }

            // Verificar si es un rol del sistema (solo se puede cambiar descripción)
            $rolesSistema = ['admin', 'secretaria', 'supervisor', 'usuario'];
            $esRolSistema = in_array(strtolower($datosAnteriores['nombre']), $rolesSistema);

            if ($esRolSistema && strtolower($nombre) !== strtolower($datosAnteriores['nombre'])) {
                Flight::json(array('error' => 'No se puede cambiar el nombre de un rol del sistema'), 400);
                return;
            }

            // Si no es rol del sistema, verificar que el nuevo nombre no exista
            if (!$esRolSistema && strtolower($nombre) !== strtolower($datosAnteriores['nombre'])) {
                $checkSentence = $db->prepare("SELECT id FROM roles WHERE nombre = :nombre AND id != :id");
                $checkSentence->bindParam(':nombre', $nombre);
                $checkSentence->bindParam(':id', $id);
                $checkSentence->execute();

                if ($checkSentence->fetch()) {
                    Flight::json(array('error' => 'Ya existe un rol con ese nombre'), 400);
                    return;
                }
            }

            // Actualizar el rol
            $sentence = $db->prepare("
            UPDATE roles 
            SET nombre = :nombre, descripcion = :descripcion 
            WHERE id = :id
        ");
            $sentence->bindParam(':nombre', $nombre);
            $sentence->bindParam(':descripcion', $descripcion);
            $sentence->bindParam(':id', $id);
            $sentence->execute();

            // AUDITORÍA
            $datosNuevos = [
                'id' => $id,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'activo' => $datosAnteriores['activo']
            ];

            AuditService::registrar('roles', $id, 'UPDATE', $datosAnteriores, $datosNuevos);

            Flight::json(array(
                'success' => true,
                'message' => 'Rol actualizado correctamente'
            ));
        } catch (Exception $e) {
            error_log("ERROR en actualizar rol: " . $e->getMessage());
            Flight::json(array('error' => 'Error al actualizar rol'), 500);
        }
    }

    // Eliminar rol
    public static function eliminar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'roles.eliminar')) {
                Flight::json(array('error' => 'No tiene permisos para eliminar roles'), 403);
                return;
            }

            $data = Flight::request()->data->getData();
            $id = $data['id'] ?? null;

            if (!$id) {
                Flight::json(array('error' => 'ID no proporcionado'), 400);
                return;
            }

            $db = Flight::db();

            // Obtener información del rol
            $stmtRol = $db->prepare("SELECT * FROM roles WHERE id = :id");
            $stmtRol->bindParam(':id', $id);
            $stmtRol->execute();
            $rol = $stmtRol->fetch();

            if (!$rol) {
                Flight::json(array('error' => 'Rol no encontrado'), 404);
                return;
            }

            // No permitir eliminar roles del sistema
            $rolesSistema = ['admin', 'secretaria', 'supervisor', 'usuario'];
            if (in_array(strtolower($rol['nombre']), $rolesSistema)) {
                Flight::json(array('error' => 'No se pueden eliminar roles del sistema'), 400);
                return;
            }

            // Verificar si hay usuarios con este rol
            $stmtUsuarios = $db->prepare("SELECT COUNT(*) as total FROM usuarios_roles WHERE rol_id = :rol_id");
            $stmtUsuarios->bindParam(':rol_id', $id);
            $stmtUsuarios->execute();
            $resultado = $stmtUsuarios->fetch();

            if ($resultado['total'] > 0) {
                Flight::json(array('error' => 'No se puede eliminar un rol que está asignado a usuarios'), 400);
                return;
            }

            $db->beginTransaction();

            try {
                // Eliminar permisos del rol
                $deletePermisos = $db->prepare("DELETE FROM roles_permisos WHERE rol_id = :rol_id");
                $deletePermisos->bindParam(':rol_id', $id);
                $deletePermisos->execute();

                // Marcar el rol como inactivo (soft delete)
                $sentence = $db->prepare("UPDATE roles SET activo = 0 WHERE id = :id");
                $sentence->bindParam(':id', $id);
                $sentence->execute();

                $db->commit();

                // AUDITORÍA
                AuditService::registrar('roles', $id, 'DELETE', $rol, null);

                Flight::json(array(
                    'success' => true,
                    'message' => 'Rol eliminado correctamente'
                ));
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            error_log("ERROR en eliminar rol: " . $e->getMessage());
            Flight::json(array('error' => 'Error al eliminar rol'), 500);
        }
    }

    // Asignar permisos a un rol
    public static function asignarPermisos($rolId)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'roles.editar')) {
                Flight::json(array('error' => 'No tiene permisos para asignar permisos'), 403);
                return;
            }

            $data = Flight::request()->data->getData();
            $permisos = $data['permisos'] ?? [];

            $db = Flight::db();

            // Verificar que el rol existe
            $stmtRol = $db->prepare("SELECT id, nombre FROM roles WHERE id = :id");
            $stmtRol->bindParam(':id', $rolId);
            $stmtRol->execute();
            $rol = $stmtRol->fetch();

            if (!$rol) {
                Flight::json(array('error' => 'Rol no encontrado'), 404);
                return;
            }

            // Obtener permisos anteriores para auditoría
            $stmtAnteriores = $db->prepare("
            SELECT permiso_id 
            FROM roles_permisos 
            WHERE rol_id = :rol_id
        ");
            $stmtAnteriores->bindParam(':rol_id', $rolId);
            $stmtAnteriores->execute();
            $permisosAnteriores = $stmtAnteriores->fetchAll(PDO::FETCH_COLUMN);

            $db->beginTransaction();

            try {
                // Eliminar permisos actuales
                $deleteSentence = $db->prepare("DELETE FROM roles_permisos WHERE rol_id = :rol_id");
                $deleteSentence->bindParam(':rol_id', $rolId);
                $deleteSentence->execute();

                // Insertar nuevos permisos
                if (!empty($permisos)) {
                    $insertSentence = $db->prepare("
                    INSERT INTO roles_permisos (rol_id, permiso_id) 
                    VALUES (:rol_id, :permiso_id)
                ");

                    foreach ($permisos as $permisoId) {
                        $insertSentence->bindParam(':rol_id', $rolId);
                        $insertSentence->bindParam(':permiso_id', $permisoId);
                        $insertSentence->execute();
                    }
                }

                $db->commit();

                // AUDITORÍA
                $datosAuditoria = [
                    'rol' => $rol['nombre'],
                    'permisos_anteriores' => $permisosAnteriores,
                    'permisos_nuevos' => $permisos
                ];

                AuditService::registrar(
                    'roles_permisos',
                    $rolId,
                    'UPDATE',
                    ['permisos' => $permisosAnteriores],
                    ['permisos' => $permisos]
                );

                Flight::json(array(
                    'success' => true,
                    'message' => 'Permisos actualizados correctamente'
                ));
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            error_log("ERROR en asignarPermisos: " . $e->getMessage());
            Flight::json(array('error' => 'Error al asignar permisos'), 500);
        }
    }
}
