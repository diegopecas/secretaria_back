<?php
class ActividadesProyectosService
{
    // Asignar proyectos a una actividad
    public static function asignar($actividadId, $proyectosIds, $db = null)
    {
        $transaccionLocal = false;
        
        try {
            // Si no se proporciona conexión, usar la global
            if (!$db) {
                $db = Flight::db();
                $db->beginTransaction();
                $transaccionLocal = true;
            }
            
            // Primero eliminar los proyectos actuales
            $stmtDelete = $db->prepare("DELETE FROM actividades_proyectos WHERE actividad_id = :actividad_id");
            $stmtDelete->bindParam(':actividad_id', $actividadId);
            $stmtDelete->execute();
            
            // Insertar los nuevos proyectos
            if (!empty($proyectosIds)) {
                $stmtInsert = $db->prepare("
                    INSERT INTO actividades_proyectos (actividad_id, proyecto_id)
                    VALUES (:actividad_id, :proyecto_id)
                ");
                
                foreach ($proyectosIds as $proyectoId) {
                    $stmtInsert->bindParam(':actividad_id', $actividadId);
                    $stmtInsert->bindParam(':proyecto_id', $proyectoId);
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
            throw new Exception('Error al asignar proyectos: ' . $e->getMessage());
        }
    }
    
    // Obtener proyectos de una actividad
    public static function obtenerPorActividad($actividadId)
    {
        try {
            $db = Flight::db();
            
            $sql = "SELECT 
                    pc.id,
                    pc.numero_proyecto,
                    pc.titulo,
                    pc.descripcion,
                    ap.fecha_asignacion
                FROM actividades_proyectos ap
                INNER JOIN proyectos_contractuales pc ON ap.proyecto_id = pc.id
                WHERE ap.actividad_id = :actividad_id
                ORDER BY pc.numero_proyecto";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':actividad_id', $actividadId);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            throw new Exception('Error al obtener proyectos: ' . $e->getMessage());
        }
    }
    
    // Obtener actividades por proyecto
    public static function obtenerActividadesPorProyecto($proyectoId)
    {
        try {
            $db = Flight::db();
            
            $sql = "SELECT 
                    a.id,
                    a.fecha_actividad,
                    a.descripcion_actividad,
                    ap.fecha_asignacion
                FROM actividades_proyectos ap
                INNER JOIN actividades a ON ap.actividad_id = a.id
                WHERE ap.proyecto_id = :proyecto_id
                ORDER BY a.fecha_actividad DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':proyecto_id', $proyectoId);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            throw new Exception('Error al obtener actividades por proyecto: ' . $e->getMessage());
        }
    }
    
    // Verificar si una actividad tiene proyectos asignados
    public static function tieneProyectos($actividadId)
    {
        try {
            $db = Flight::db();
            
            $stmt = $db->prepare("
                SELECT COUNT(*) as total 
                FROM actividades_proyectos 
                WHERE actividad_id = :actividad_id
            ");
            $stmt->bindParam(':actividad_id', $actividadId);
            $stmt->execute();
            
            $resultado = $stmt->fetch();
            return $resultado['total'] > 0;
            
        } catch (Exception $e) {
            throw new Exception('Error al verificar proyectos: ' . $e->getMessage());
        }
    }
    
    // Obtener estadísticas de proyectos por contrato y período
    public static function obtenerEstadisticasPorPeriodo($contratoId, $mes, $anio)
    {
        try {
            $db = Flight::db();
            
            $sql = "SELECT 
                    pc.id as proyecto_id,
                    pc.numero_proyecto,
                    pc.titulo,
                    pc.descripcion,
                    COUNT(DISTINCT ap.actividad_id) as total_actividades,
                    COUNT(DISTINCT DATE(a.fecha_actividad)) as dias_trabajados
                FROM proyectos_contractuales pc
                LEFT JOIN actividades_proyectos ap ON pc.id = ap.proyecto_id
                LEFT JOIN actividades a ON ap.actividad_id = a.id 
                    AND MONTH(a.fecha_actividad) = :mes 
                    AND YEAR(a.fecha_actividad) = :anio
                WHERE pc.contrato_id = :contrato_id
                GROUP BY pc.id, pc.numero_proyecto, pc.titulo, pc.descripcion
                ORDER BY pc.numero_proyecto";
            
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
    
    // Eliminar todos los proyectos de una actividad (usado al eliminar actividad)
    public static function eliminarPorActividad($actividadId, $db = null)
    {
        $transaccionLocal = false;
        
        try {
            if (!$db) {
                $db = Flight::db();
                $transaccionLocal = true;
            }
            
            $stmt = $db->prepare("DELETE FROM actividades_proyectos WHERE actividad_id = :actividad_id");
            $stmt->bindParam(':actividad_id', $actividadId);
            $stmt->execute();
            
            return true;
            
        } catch (Exception $e) {
            throw new Exception('Error al eliminar proyectos: ' . $e->getMessage());
        }
    }
}