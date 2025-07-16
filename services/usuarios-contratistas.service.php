<?php
require_once __DIR__ . '/../config.php';

class UsuariosContratistasService
{
    /**
     * Asignar contratista a usuario
     */
    public static function asignar()
    {
        try {
            $data = Flight::request()->data->getData();

            // Validar datos requeridos
            if (!isset($data['usuario_id']) || !isset($data['contratista_id'])) {
                Flight::json(['error' => 'Usuario y contratista son requeridos'], 400);
                return;
            }

            // Verificar permisos
            if (!AuthService::hasPermission('usuarios_contratistas.gestionar')) {
                Flight::json(['error' => 'No tiene permisos para gestionar asociaciones'], 403);
                return;
            }

            $db = Flight::db();

            // Verificar que no exista la asociación
            $existe = $db->fetchOne(
                "SELECT COUNT(*) FROM usuarios_contratistas 
                 WHERE usuario_id = ? AND contratista_id = ?",
                [$data['usuario_id'], $data['contratista_id']]
            );

            if ($existe > 0) {
                Flight::json(['error' => 'La asociación ya existe'], 400);
                return;
            }

            // Si es principal, quitar principal a otros
            if (isset($data['es_principal']) && $data['es_principal']) {
                $db->execute(
                    "UPDATE usuarios_contratistas SET es_principal = 0 WHERE usuario_id = ?",
                    [$data['usuario_id']]
                );
            }

            // Insertar asociación
            $db->execute(
                "INSERT INTO usuarios_contratistas (usuario_id, contratista_id, es_principal) 
                 VALUES (?, ?, ?)",
                [
                    $data['usuario_id'],
                    $data['contratista_id'],
                    $data['es_principal'] ?? 1
                ]
            );

            // Registrar en auditoría
            AuditService::registrar('usuarios_contratistas', $db->lastInsertId(), 'CREATE', null, [
                'usuario_id' => $data['usuario_id'],
                'contratista_id' => $data['contratista_id']
            ]);

            Flight::json([
                'success' => true,
                'message' => 'Contratista asignado correctamente'
            ]);
        } catch (Exception $e) {
            error_log("Error en asignar contratista: " . $e->getMessage());
            Flight::json(['error' => 'Error al asignar contratista'], 500);
        }
    }

    /**
     * Obtener contratistas por usuario
     */
    public static function obtenerPorUsuario()
    {
        try {
            $usuario_id = Flight::request()->query->usuario_id;

            if (!$usuario_id) {
                Flight::json(['error' => 'Usuario ID es requerido'], 400);
                return;
            }

            // Verificar permisos - solo admin o el mismo usuario
            $current_user_id = AuthService::getUserId();
            if ($usuario_id != $current_user_id && !AuthService::hasPermission('usuarios_contratistas.gestionar')) {
                Flight::json(['error' => 'No tiene permisos para ver esta información'], 403);
                return;
            }

            $db = Flight::db();

            $contratistas = $db->fetchAll(
                "SELECT 
                    c.id,
                    c.tipo_identificacion_id,
                    ti.codigo as tipo_identificacion_codigo,
                    ti.nombre as tipo_identificacion_nombre,
                    c.identificacion,
                    c.nombre_completo,
                    c.email,
                    c.telefono,
                    c.direccion,
                    c.activo,
                    uc.es_principal,
                    uc.fecha_asignacion,
                    (SELECT COUNT(*) FROM contratos WHERE contratista_id = c.id) as total_contratos,
                    (SELECT COUNT(*) FROM contratos WHERE contratista_id = c.id AND estado = 'activo') as contratos_activos
                FROM usuarios_contratistas uc
                JOIN contratistas c ON uc.contratista_id = c.id
                JOIN tipos_identificacion ti ON c.tipo_identificacion_id = ti.id
                WHERE uc.usuario_id = ?
                ORDER BY uc.es_principal DESC, c.nombre_completo",
                [$usuario_id]
            );

            Flight::json(['contratistas' => $contratistas]);
        } catch (Exception $e) {
            error_log("Error al obtener contratistas del usuario: " . $e->getMessage());
            Flight::json(['error' => 'Error al obtener contratistas'], 500);
        }
    }


    /**
     * Obtener contratistas del usuario actual
     */
    public static function obtenerMisContratistas()
    {
        try {
            $usuario_id = AuthService::getUserId();

            if (!$usuario_id) {
                Flight::json(['error' => 'Usuario no autenticado'], 401);
                return;
            }

            $db = Flight::db();

            // Si es admin, puede ver todos los contratistas
            if (AuthService::hasPermission('actividades.ver_todas')) {
                $stmt = $db->prepare("
                SELECT 
                    c.id,
                    c.tipo_identificacion_id,
                    ti.codigo as tipo_identificacion_codigo,
                    ti.nombre as tipo_identificacion_nombre,
                    c.identificacion,
                    c.nombre_completo,
                    c.email,
                    c.telefono,
                    c.direccion,
                    c.activo,
                    1 as es_principal,
                    NULL as fecha_asignacion,
                    (SELECT COUNT(*) FROM contratos WHERE contratista_id = c.id) as total_contratos,
                    (SELECT COUNT(*) FROM contratos WHERE contratista_id = c.id AND estado = 'activo') as contratos_activos
                FROM contratistas c
                JOIN tipos_identificacion ti ON c.tipo_identificacion_id = ti.id
                WHERE c.activo = 1
                ORDER BY c.nombre_completo
            ");
                $stmt->execute();
                $contratistas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Usuario normal, solo sus contratistas asignados
                $stmt = $db->prepare("
                SELECT 
                    c.id,
                    c.tipo_identificacion_id,
                    ti.codigo as tipo_identificacion_codigo,
                    ti.nombre as tipo_identificacion_nombre,
                    c.identificacion,
                    c.nombre_completo,
                    c.email,
                    c.telefono,
                    c.direccion,
                    c.activo,
                    uc.es_principal,
                    uc.fecha_asignacion,
                    (SELECT COUNT(*) FROM contratos WHERE contratista_id = c.id) as total_contratos,
                    (SELECT COUNT(*) FROM contratos WHERE contratista_id = c.id AND estado = 'activo') as contratos_activos
                FROM usuarios_contratistas uc
                JOIN contratistas c ON uc.contratista_id = c.id
                JOIN tipos_identificacion ti ON c.tipo_identificacion_id = ti.id
                WHERE uc.usuario_id = :usuario_id AND c.activo = 1
                ORDER BY uc.es_principal DESC, c.nombre_completo
            ");
                $stmt->bindParam(':usuario_id', $usuario_id);
                $stmt->execute();
                $contratistas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            Flight::json(['contratistas' => $contratistas]);
        } catch (Exception $e) {
            error_log("Error al obtener mis contratistas: " . $e->getMessage());
            Flight::json(['error' => 'Error al obtener contratistas'], 500);
        }
    }

    /**
     * Eliminar asociación
     */
    public static function eliminar()
    {
        try {
            $data = Flight::request()->data->getData();

            if (!isset($data['usuario_id']) || !isset($data['contratista_id'])) {
                Flight::json(['error' => 'Usuario y contratista son requeridos'], 400);
                return;
            }

            // Verificar permisos
            if (!AuthService::hasPermission('usuarios_contratistas.gestionar')) {
                Flight::json(['error' => 'No tiene permisos para eliminar asociaciones'], 403);
                return;
            }

            $db = Flight::db();

            // Registrar en auditoría antes de eliminar
            AuditService::registrar('usuarios_contratistas', 0, 'DELETE', [
                'usuario_id' => $data['usuario_id'],
                'contratista_id' => $data['contratista_id']
            ], null);

            // Eliminar asociación
            $result = $db->execute(
                "DELETE FROM usuarios_contratistas WHERE usuario_id = ? AND contratista_id = ?",
                [$data['usuario_id'], $data['contratista_id']]
            );

            if ($result) {
                Flight::json([
                    'success' => true,
                    'message' => 'Asociación eliminada correctamente'
                ]);
            } else {
                Flight::json(['error' => 'No se encontró la asociación'], 404);
            }
        } catch (Exception $e) {
            error_log("Error al eliminar asociación: " . $e->getMessage());
            Flight::json(['error' => 'Error al eliminar asociación'], 500);
        }
    }

    /**
     * Actualizar asociación (cambiar principal)
     */
    public static function actualizar()
    {
        try {
            $data = Flight::request()->data->getData();

            if (!isset($data['usuario_id']) || !isset($data['contratista_id'])) {
                Flight::json(['error' => 'Usuario y contratista son requeridos'], 400);
                return;
            }

            // Verificar permisos
            $current_user_id = AuthService::getUserId();
            if ($data['usuario_id'] != $current_user_id && !AuthService::hasPermission('usuarios_contratistas.gestionar')) {
                Flight::json(['error' => 'No tiene permisos para actualizar esta asociación'], 403);
                return;
            }

            $db = Flight::db();

            // Si se está marcando como principal
            if (isset($data['es_principal']) && $data['es_principal']) {
                // Quitar principal a otros
                $db->execute(
                    "UPDATE usuarios_contratistas SET es_principal = 0 WHERE usuario_id = ?",
                    [$data['usuario_id']]
                );
            }

            // Actualizar asociación
            $result = $db->execute(
                "UPDATE usuarios_contratistas SET es_principal = ? 
                 WHERE usuario_id = ? AND contratista_id = ?",
                [
                    $data['es_principal'] ?? 0,
                    $data['usuario_id'],
                    $data['contratista_id']
                ]
            );

            if ($result) {
                // Registrar en auditoría
                AuditService::registrar('usuarios_contratistas', 0, 'UPDATE', null, [
                    'usuario_id' => $data['usuario_id'],
                    'contratista_id' => $data['contratista_id'],
                    'es_principal' => $data['es_principal'] ?? 0
                ]);

                Flight::json([
                    'success' => true,
                    'message' => 'Asociación actualizada correctamente'
                ]);
            } else {
                Flight::json(['error' => 'No se encontró la asociación'], 404);
            }
        } catch (Exception $e) {
            error_log("Error al actualizar asociación: " . $e->getMessage());
            Flight::json(['error' => 'Error al actualizar asociación'], 500);
        }
    }
}
