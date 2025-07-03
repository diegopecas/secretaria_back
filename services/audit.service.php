<?php

class AuditService {
    
    /**
     * Registrar un cambio en la auditoría
     * 
     * @param string $tabla Nombre de la tabla afectada
     * @param int $registroId ID del registro afectado
     * @param string $operacion Tipo de operación: CREATE, UPDATE, DELETE
     * @param array|null $datosAnteriores Datos antes del cambio (null para CREATE)
     * @param array|null $datosNuevos Datos después del cambio (null para DELETE)
     * @param array|null $usuario Usuario que realiza la acción (opcional, se toma del contexto si no se proporciona)
     */
    public static function registrar($tabla, $registroId, $operacion, $datosAnteriores = null, $datosNuevos = null, $usuario = null) {
        try {
            $db = Flight::db();
            
            // Obtener usuario del contexto si no se proporciona
            if (!$usuario) {
                $usuario = Flight::get('currentUser');
            }
            
            // Obtener información adicional
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            // Calcular los cambios para operaciones UPDATE
            $cambios = null;
            if ($operacion === 'UPDATE' && $datosAnteriores && $datosNuevos) {
                $cambios = self::calcularCambios($datosAnteriores, $datosNuevos);
            }
            
            // Preparar la consulta
            $sql = "INSERT INTO auditoria (
                tabla, 
                registro_id, 
                operacion, 
                usuario_id, 
                usuario_nombre,
                datos_anteriores, 
                datos_nuevos, 
                cambios,
                ip_address,
                user_agent
            ) VALUES (
                :tabla,
                :registro_id,
                :operacion,
                :usuario_id,
                :usuario_nombre,
                :datos_anteriores,
                :datos_nuevos,
                :cambios,
                :ip_address,
                :user_agent
            )";
            
            $stmt = $db->prepare($sql);
            
            // Bind de parámetros
            $stmt->bindParam(':tabla', $tabla);
            $stmt->bindParam(':registro_id', $registroId);
            $stmt->bindParam(':operacion', $operacion);
            $stmt->bindParam(':usuario_id', $usuario['id']);
            $stmt->bindParam(':usuario_nombre', $usuario['nombre']);
            $stmt->bindValue(':datos_anteriores', $datosAnteriores ? json_encode($datosAnteriores) : null);
            $stmt->bindValue(':datos_nuevos', $datosNuevos ? json_encode($datosNuevos) : null);
            $stmt->bindValue(':cambios', $cambios ? json_encode($cambios) : null);
            $stmt->bindParam(':ip_address', $ipAddress);
            $stmt->bindParam(':user_agent', $userAgent);
            
            $stmt->execute();
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error en auditoría: " . $e->getMessage());
            // No lanzar excepción para no interrumpir la operación principal
            return false;
        }
    }
    
    /**
     * Calcular diferencias entre dos arrays
     */
    private static function calcularCambios($anterior, $nuevo) {
        $cambios = [];
        
        // Campos que cambiaron
        foreach ($nuevo as $key => $value) {
            if (!isset($anterior[$key]) || $anterior[$key] != $value) {
                $cambios[$key] = [
                    'anterior' => $anterior[$key] ?? null,
                    'nuevo' => $value
                ];
            }
        }
        
        // Campos que se eliminaron
        foreach ($anterior as $key => $value) {
            if (!isset($nuevo[$key])) {
                $cambios[$key] = [
                    'anterior' => $value,
                    'nuevo' => null
                ];
            }
        }
        
        return $cambios;
    }
    
    /**
     * Obtener historial de cambios de un registro
     */
    public static function obtenerHistorial($tabla, $registroId) {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos (opcional)
            // if (!AuthService::checkPermission($currentUser['id'], 'auditoria.ver')) {
            //     Flight::json(['error' => 'Sin permisos para ver auditoría'], 403);
            //     return;
            // }
            
            $db = Flight::db();
            
            $sql = "SELECT 
                    a.*,
                    DATE_FORMAT(a.fecha_hora, '%d/%m/%Y %H:%i:%s') as fecha_formateada
                FROM auditoria a
                WHERE a.tabla = :tabla 
                AND a.registro_id = :registro_id
                ORDER BY a.fecha_hora DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':tabla', $tabla);
            $stmt->bindParam(':registro_id', $registroId);
            $stmt->execute();
            
            $historial = $stmt->fetchAll();
            
            // Decodificar JSON
            foreach ($historial as &$registro) {
                $registro['datos_anteriores'] = $registro['datos_anteriores'] ? json_decode($registro['datos_anteriores'], true) : null;
                $registro['datos_nuevos'] = $registro['datos_nuevos'] ? json_decode($registro['datos_nuevos'], true) : null;
                $registro['cambios'] = $registro['cambios'] ? json_decode($registro['cambios'], true) : null;
            }
            
            Flight::json($historial);
            
        } catch (Exception $e) {
            error_log("Error obteniendo historial: " . $e->getMessage());
            Flight::json(['error' => 'Error al obtener historial'], 500);
        }
    }
    
    /**
     * Obtener auditoría filtrada
     */
    public static function buscar() {
        try {
            requireAuth();
            
            $tabla = Flight::request()->query['tabla'] ?? null;
            $usuarioId = Flight::request()->query['usuario_id'] ?? null;
            $operacion = Flight::request()->query['operacion'] ?? null;
            $fechaDesde = Flight::request()->query['fecha_desde'] ?? null;
            $fechaHasta = Flight::request()->query['fecha_hasta'] ?? null;
            
            $db = Flight::db();
            
            $sql = "SELECT 
                    a.*,
                    DATE_FORMAT(a.fecha_hora, '%d/%m/%Y %H:%i:%s') as fecha_formateada
                FROM auditoria a
                WHERE 1=1";
            
            $params = [];
            
            if ($tabla) {
                $sql .= " AND a.tabla = :tabla";
                $params[':tabla'] = $tabla;
            }
            
            if ($usuarioId) {
                $sql .= " AND a.usuario_id = :usuario_id";
                $params[':usuario_id'] = $usuarioId;
            }
            
            if ($operacion) {
                $sql .= " AND a.operacion = :operacion";
                $params[':operacion'] = $operacion;
            }
            
            if ($fechaDesde) {
                $sql .= " AND DATE(a.fecha_hora) >= :fecha_desde";
                $params[':fecha_desde'] = $fechaDesde;
            }
            
            if ($fechaHasta) {
                $sql .= " AND DATE(a.fecha_hora) <= :fecha_hasta";
                $params[':fecha_hasta'] = $fechaHasta;
            }
            
            $sql .= " ORDER BY a.fecha_hora DESC LIMIT 1000";
            
            $stmt = $db->prepare($sql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $resultados = $stmt->fetchAll();
            
            // Decodificar JSON
            foreach ($resultados as &$registro) {
                $registro['datos_anteriores'] = $registro['datos_anteriores'] ? json_decode($registro['datos_anteriores'], true) : null;
                $registro['datos_nuevos'] = $registro['datos_nuevos'] ? json_decode($registro['datos_nuevos'], true) : null;
                $registro['cambios'] = $registro['cambios'] ? json_decode($registro['cambios'], true) : null;
            }
            
            Flight::json($resultados);
            
        } catch (Exception $e) {
            error_log("Error buscando auditoría: " . $e->getMessage());
            Flight::json(['error' => 'Error al buscar auditoría'], 500);
        }
    }
}