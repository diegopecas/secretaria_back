<?php
class PermisosService
{
    // Obtener todos los permisos activos
    public static function obtenerTodos()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'roles.ver')) {
                Flight::json(array('error' => 'No tiene permisos para ver permisos'), 403);
                return;
            }

            $db = Flight::db();

            $sentence = $db->prepare("
                SELECT 
                    id,
                    nombre,
                    modulo,
                    descripcion,
                    activo
                FROM permisos
                WHERE activo = 1
                ORDER BY modulo, nombre
            ");

            $sentence->execute();
            $permisos = $sentence->fetchAll(PDO::FETCH_ASSOC);

            Flight::json($permisos);
        } catch (Exception $e) {
            error_log("ERROR en obtenerTodos permisos: " . $e->getMessage());
            Flight::json(array('error' => 'Error al obtener permisos'), 500);
        }
    }
}
