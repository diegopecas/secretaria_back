<?php
class TiposIdentificacionService
{
    // Obtener todos los tipos de identificación activos
    public static function obtenerTodos()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para ver tipos de identificación'], 403);
                return;
            }
            
            $db = Flight::db();
            
            $sentence = $db->prepare("
                SELECT 
                    id,
                    codigo,
                    nombre,
                    aplica_personas,
                    aplica_empresas,
                    activo,
                    orden
                FROM tipos_identificacion
                WHERE activo = 1
                ORDER BY orden ASC, nombre ASC
            ");
            
            $sentence->execute();
            $tipos = $sentence->fetchAll(PDO::FETCH_ASSOC);
            
            Flight::json($tipos);
            
        } catch (Exception $e) {
            error_log("ERROR en obtenerTodos tipos_identificacion: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener tipos de identificación'], 500);
        }
    }
    
    // Obtener tipos por aplicación (personas o empresas)
    public static function obtenerPorAplicacion()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para ver tipos de identificación'], 403);
                return;
            }
            
            $tipo = Flight::request()->query['tipo'] ?? 'personas'; // 'personas' o 'empresas'
            
            $db = Flight::db();
            
            $campo = $tipo === 'empresas' ? 'aplica_empresas' : 'aplica_personas';
            
            $sentence = $db->prepare("
                SELECT 
                    id,
                    codigo,
                    nombre,
                    aplica_personas,
                    aplica_empresas,
                    activo,
                    orden
                FROM tipos_identificacion
                WHERE activo = 1 AND $campo = 1
                ORDER BY orden ASC, nombre ASC
            ");
            
            $sentence->execute();
            $tipos = $sentence->fetchAll(PDO::FETCH_ASSOC);
            
            responderJSON($tipos);
            
        } catch (Exception $e) {
            error_log("ERROR en obtenerPorAplicacion: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener tipos de identificación'], 500);
        }
    }
    
    // Obtener tipo por ID
    public static function obtenerPorId($id)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para ver tipos de identificación'], 403);
                return;
            }
            
            $db = Flight::db();
            
            $sentence = $db->prepare("
                SELECT 
                    id,
                    codigo,
                    nombre,
                    aplica_personas,
                    aplica_empresas,
                    activo,
                    orden
                FROM tipos_identificacion
                WHERE id = :id
            ");
            $sentence->bindParam(':id', $id);
            $sentence->execute();
            
            $tipo = $sentence->fetch(PDO::FETCH_ASSOC);
            
            if (!$tipo) {
                responderJSON(['error' => 'Tipo de identificación no encontrado'], 404);
                return;
            }
            
            responderJSON($tipo);
            
        } catch (Exception $e) {
            error_log("ERROR en obtenerPorId: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener tipo de identificación'], 500);
        }
    }
}