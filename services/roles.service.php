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
}