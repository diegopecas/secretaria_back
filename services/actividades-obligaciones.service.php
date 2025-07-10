<?php
class ActividadesObligacionesService
{
    // Asignar obligaciones a una actividad
    public static function asignar($actividadId, $obligacionesIds, $db = null)
    {
        $transaccionLocal = false;
        
        try {
            // Si no se proporciona conexión, usar la global
            if (!$db) {
                $db = Flight::db();
                $db->beginTransaction();
                $transaccionLocal = true;
            }
            
            // Primero eliminar las obligaciones actuales
            $stmtDelete = $db->prepare("DELETE FROM actividades_obligaciones WHERE actividad_id = :actividad_id");
            $stmtDelete->bindParam(':actividad_id', $actividadId);
            $stmtDelete->execute();
            
            // Insertar las nuevas obligaciones
            if (!empty($obligacionesIds)) {
                $stmtInsert = $db->prepare("
                    INSERT INTO actividades_obligaciones (actividad_id, obligacion_id)
                    VALUES (:actividad_id, :obligacion_id)
                ");
                
                foreach ($obligacionesIds as $obligacionId) {
                    $stmtInsert->bindParam(':actividad_id', $actividadId);
                    $stmtInsert->bindParam(':obligacion_id', $obligacionId);
                    $stmtInsert->execute();
                }
            }
            
            if ($transaccionLocal) {
                $db->commit();
            }
            
            return true;
            
        } catch (Exception $e) {
            if ($transaccionLocal && $db) {
                $db->rollBack();
            }
            throw new Exception('Error al asignar obligaciones: ' . $e->getMessage());
        }
    }
    
    // Obtener obligaciones de una actividad
    public static function obtenerPorActividad($actividadId)
    {
        try {
            $db = Flight::db();
            
            $sql = "SELECT 
                    oc.id,
                    oc.numero_obligacion,
                    oc.descripcion,
                    ao.fecha_asignacion
                FROM actividades_obligaciones ao
                INNER JOIN obligaciones_contractuales oc ON ao.obligacion_id = oc.id
                WHERE ao.actividad_id = :actividad_id
                ORDER BY oc.numero_obligacion";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':actividad_id', $actividadId);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            throw new Exception('Error al obtener obligaciones: ' . $e->getMessage());
        }
    }
    
    // Obtener actividades por obligación
    public static function obtenerActividadesPorObligacion($obligacionId)
    {
        try {
            $db = Flight::db();
            
            $sql = "SELECT 
                    ra.id,
                    ra.fecha_actividad,
                    ra.descripcion_actividad,
                    ao.fecha_asignacion
                FROM actividades_obligaciones ao
                INNER JOIN registro_actividades ra ON ao.actividad_id = ra.id
                WHERE ao.obligacion_id = :obligacion_id
                ORDER BY ra.fecha_actividad DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':obligacion_id', $obligacionId);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            throw new Exception('Error al obtener actividades por obligación: ' . $e->getMessage());
        }
    }
    
    // Verificar si una actividad tiene obligaciones asignadas
    public static function tieneObligaciones($actividadId)
    {
        try {
            $db = Flight::db();
            
            $stmt = $db->prepare("
                SELECT COUNT(*) as total 
                FROM actividades_obligaciones 
                WHERE actividad_id = :actividad_id
            ");
            $stmt->bindParam(':actividad_id', $actividadId);
            $stmt->execute();
            
            $resultado = $stmt->fetch();
            return $resultado['total'] > 0;
            
        } catch (Exception $e) {
            throw new Exception('Error al verificar obligaciones: ' . $e->getMessage());
        }
    }
    
    // Obtener estadísticas de obligaciones por contrato y período
    public static function obtenerEstadisticasPorPeriodo($contratoId, $mes, $anio)
    {
        try {
            $db = Flight::db();
            
            $sql = "SELECT 
                    oc.id as obligacion_id,
                    oc.numero_obligacion,
                    oc.descripcion,
                    COUNT(DISTINCT ao.actividad_id) as total_actividades,
                    COUNT(DISTINCT DATE(ra.fecha_actividad)) as dias_cumplidos
                FROM obligaciones_contractuales oc
                LEFT JOIN actividades_obligaciones ao ON oc.id = ao.obligacion_id
                LEFT JOIN registro_actividades ra ON ao.actividad_id = ra.id 
                    AND MONTH(ra.fecha_actividad) = :mes 
                    AND YEAR(ra.fecha_actividad) = :anio
                WHERE oc.contrato_id = :contrato_id
                GROUP BY oc.id, oc.numero_obligacion, oc.descripcion
                ORDER BY oc.numero_obligacion";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':contrato_id', $contratoId);
            $stmt->bindParam(':mes', $mes);
            $stmt->bindParam(':anio', $anio);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            throw new Exception('Error al obtener estadísticas: ' . $e->getMessage());
        }
    }
    
    // Eliminar todas las obligaciones de una actividad (usado al eliminar actividad)
    public static function eliminarPorActividad($actividadId, $db = null)
    {
        $transaccionLocal = false;
        
        try {
            if (!$db) {
                $db = Flight::db();
                $transaccionLocal = true;
            }
            
            $stmt = $db->prepare("DELETE FROM actividades_obligaciones WHERE actividad_id = :actividad_id");
            $stmt->bindParam(':actividad_id', $actividadId);
            $stmt->execute();
            
            return true;
            
        } catch (Exception $e) {
            throw new Exception('Error al eliminar obligaciones: ' . $e->getMessage());
        }
    }
}