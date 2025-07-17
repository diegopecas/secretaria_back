<?php
class IAModelosService
{
    /**
     * Obtener modelos por tipo
     */
    public static function obtenerPorTipo($tipo)
    {
        try {
            requireAuth();
            
            $tiposValidos = ['transcripcion', 'embedding', 'analisis', 'generacion'];
            if (!in_array($tipo, $tiposValidos)) {
                responderJSON(['error' => 'Tipo de modelo no válido'], 400);
                return;
            }
            
            $db = Flight::db();
            
            $sql = "
                SELECT 
                    im.id,
                    im.proveedor,
                    im.modelo,
                    im.tipo_modelo_id,
                    im.dimensiones,
                    im.costo_por_1k_tokens,
                    im.limite_tokens,
                    im.limite_requests_hora,
                    im.activo,
                    im.es_predeterminado,
                    im.configuracion_json,
                    tm.nombre as tipo_nombre,
                    tm.codigo as tipo_codigo,
                    tm.requiere_dimensiones
                FROM ia_modelos im
                INNER JOIN tipos_modelo_ia tm ON im.tipo_modelo_id = tm.id
                WHERE tm.codigo = :tipo AND im.activo = 1
                ORDER BY im.es_predeterminado DESC, im.costo_por_1k_tokens ASC
            ";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':tipo', $tipo);
            $stmt->execute();
            
            $modelos = $stmt->fetchAll();
            
            // Decodificar JSON de configuración
            foreach ($modelos as &$modelo) {
                if (isset($modelo['configuracion_json'])) {
                    $modelo['configuracion'] = json_decode($modelo['configuracion_json'], true);
                    unset($modelo['configuracion_json']);
                }
            }
            
            responderJSON([
                'success' => true,
                'tipo' => $tipo,
                'modelos' => $modelos
            ]);
            
        } catch (Exception $e) {
            error_log("Error obteniendo modelos IA: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener modelos'], 500);
        }
    }

    /**
     * Transcribir audio
     */
    public static function transcribir()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'actividades.registrar')) {
                responderJSON(['error' => 'No tiene permisos para transcribir'], 403);
                return;
            }
            
            // Validar archivo de audio
            if (!isset($_FILES['audio'])) {
                responderJSON(['error' => 'No se recibió archivo de audio'], 400);
                return;
            }
            
            $audioFile = $_FILES['audio'];
            $modeloId = $_POST['modelo_id'] ?? null;
            
            if (!$modeloId) {
                // Obtener modelo predeterminado de transcripción
                $db = Flight::db();
                $stmt = $db->prepare("
                    SELECT im.id 
                    FROM ia_modelos im
                    INNER JOIN tipos_modelo_ia tm ON im.tipo_modelo_id = tm.id
                    WHERE tm.codigo = 'transcripcion' 
                    AND im.activo = 1 
                    AND im.es_predeterminado = 1
                    LIMIT 1
                ");
                $stmt->execute();
                $modeloDefault = $stmt->fetch();
                
                if ($modeloDefault) {
                    $modeloId = $modeloDefault['id'];
                } else {
                    responderJSON(['error' => 'No hay modelo de transcripción disponible'], 400);
                    return;
                }
            }
            
            $db = Flight::db();
            
            // Obtener configuración del modelo
            $stmt = $db->prepare("
                SELECT im.*, tm.codigo as tipo_codigo
                FROM ia_modelos im
                INNER JOIN tipos_modelo_ia tm ON im.tipo_modelo_id = tm.id
                WHERE im.id = :modelo_id AND im.activo = 1
            ");
            $stmt->bindParam(':modelo_id', $modeloId);
            $stmt->execute();
            $modelo = $stmt->fetch();
            
            if (!$modelo) {
                responderJSON(['error' => 'Modelo no encontrado o no disponible'], 404);
                return;
            }
            
            // Verificar que sea un modelo de transcripción
            if ($modelo['tipo_codigo'] !== 'transcripcion') {
                responderJSON(['error' => 'El modelo seleccionado no es de transcripción'], 400);
                return;
            }
            
            // Para el navegador, no necesitamos hacer nada especial
            if ($modelo['proveedor'] === 'navegador') {
                responderJSON([
                    'success' => true,
                    'message' => 'Use la API Web Speech del navegador para transcribir'
                ]);
                return;
            }
            
            // Para otros proveedores, usar ProviderManager
            require_once __DIR__ . '/../providers/ai/provider-manager.php';
            require_once __DIR__ . '/../providers/storage/storage.manager.php';
            
            $providerManager = ProviderManager::getInstance();
            $storage = StorageManager::getInstance();
            
            try {
                // Guardar audio temporalmente
                $audioInfo = $storage->guardarArchivo($audioFile, 'temp', 'transcripciones');
                
                // Obtener el archivo para transcribir
                $archivoData = $storage->obtenerArchivo($audioInfo['path']);
                
                // Crear archivo temporal para el provider
                $tempFile = tempnam(sys_get_temp_dir(), 'audio_');
                file_put_contents($tempFile, $archivoData['content']);
                
                // Transcribir usando el provider
                $resultado = $providerManager->transcribirAudio($tempFile, $modelo);
                
                // Limpiar archivo temporal local
                unlink($tempFile);
                
                // Registrar uso
                self::registrarUsoModelo($modeloId, null, $resultado['tokens_usados'] ?? 0, $modelo);
                
                // Limpiar archivo temporal del storage
                $storage->eliminarArchivo($audioInfo['path']);
                
                responderJSON([
                    'success' => true,
                    'texto' => $resultado['texto'],
                    'proveedor' => $modelo['proveedor'],
                    'modelo' => $modelo['modelo'],
                    'confianza' => $resultado['confianza'] ?? 0.95,
                    'duracion_segundos' => $resultado['duracion_segundos'] ?? null
                ]);
                
            } catch (Exception $e) {
                // Limpiar archivo temporal si existe
                if (isset($audioInfo)) {
                    $storage->eliminarArchivo($audioInfo['path']);
                }
                
                error_log("Error en transcripción con {$modelo['proveedor']}: " . $e->getMessage());
                responderJSON(['error' => 'Error al transcribir: ' . $e->getMessage()], 500);
            }
            
        } catch (Exception $e) {
            error_log("Error en transcripción: " . $e->getMessage());
            responderJSON(['error' => 'Error al transcribir audio'], 500);
        }
    }

    /**
     * Obtener resumen de uso de modelos IA
     */
    public static function obtenerResumenUso()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Solo administradores pueden ver el resumen de uso
            if (!AuthService::checkPermission($currentUser['id'], 'sistema.configurar')) {
                responderJSON(['error' => 'No tiene permisos para ver esta información'], 403);
                return;
            }
            
            $db = Flight::db();
            
            // Obtener resumen por modelo
            $sql = "
                SELECT 
                    im.proveedor,
                    im.modelo,
                    tm.nombre as tipo_modelo,
                    COUNT(mu.id) as total_usos,
                    SUM(mu.tokens_total) as tokens_totales,
                    SUM(mu.costo_usd) as costo_total_usd,
                    AVG(mu.tiempo_respuesta_ms) as tiempo_promedio_ms,
                    SUM(CASE WHEN mu.exitoso = 1 THEN 1 ELSE 0 END) as usos_exitosos,
                    SUM(CASE WHEN mu.exitoso = 0 THEN 1 ELSE 0 END) as usos_fallidos,
                    MAX(mu.fecha_uso) as ultimo_uso
                FROM ia_modelos_uso mu
                INNER JOIN ia_modelos im ON mu.modelo_config_id = im.id
                INNER JOIN tipos_modelo_ia tm ON im.tipo_modelo_id = tm.id
                GROUP BY im.id
                ORDER BY costo_total_usd DESC
            ";
            
            $stmt = $db->query($sql);
            $resumen = $stmt->fetchAll();
            
            // Calcular totales
            $totales = [
                'total_usos' => array_sum(array_column($resumen, 'total_usos')),
                'tokens_totales' => array_sum(array_column($resumen, 'tokens_totales')),
                'costo_total_usd' => array_sum(array_column($resumen, 'costo_total_usd'))
            ];
            
            responderJSON([
                'success' => true,
                'resumen' => $resumen,
                'totales' => $totales
            ]);
            
        } catch (Exception $e) {
            error_log("Error obteniendo resumen de uso: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener resumen de uso'], 500);
        }
    }

    /**
     * Establecer modelo predeterminado
     */
    public static function establecerPredeterminado($id)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Solo administradores
            if (!AuthService::checkPermission($currentUser['id'], 'sistema.configurar')) {
                responderJSON(['error' => 'No tiene permisos para cambiar configuración'], 403);
                return;
            }
            
            $db = Flight::db();
            
            // Obtener el modelo y su tipo
            $stmt = $db->prepare("
                SELECT im.*, tm.codigo as tipo_codigo
                FROM ia_modelos im
                INNER JOIN tipos_modelo_ia tm ON im.tipo_modelo_id = tm.id
                WHERE im.id = :id
            ");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $modelo = $stmt->fetch();
            
            if (!$modelo) {
                responderJSON(['error' => 'Modelo no encontrado'], 404);
                return;
            }
            
            $db->beginTransaction();
            
            try {
                // Quitar predeterminado de otros modelos del mismo tipo
                $stmtUpdate = $db->prepare("
                    UPDATE ia_modelos im
                    INNER JOIN tipos_modelo_ia tm ON im.tipo_modelo_id = tm.id
                    SET im.es_predeterminado = 0
                    WHERE tm.codigo = :tipo_codigo
                ");
                $stmtUpdate->bindParam(':tipo_codigo', $modelo['tipo_codigo']);
                $stmtUpdate->execute();
                
                // Establecer como predeterminado
                $stmtSet = $db->prepare("
                    UPDATE ia_modelos
                    SET es_predeterminado = 1
                    WHERE id = :id
                ");
                $stmtSet->bindParam(':id', $id);
                $stmtSet->execute();
                
                $db->commit();
                
                responderJSON([
                    'success' => true,
                    'message' => 'Modelo establecido como predeterminado'
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            error_log("Error estableciendo modelo predeterminado: " . $e->getMessage());
            responderJSON(['error' => 'Error al actualizar configuración'], 500);
        }
    }

    /**
     * Registrar uso de un modelo (método privado)
     */
    private static function registrarUsoModelo($modeloId, $actividadId, $tokensUsados, $modeloConfig)
    {
        try {
            $db = Flight::db();
            
            // Calcular costo
            $costo = 0;
            if ($modeloConfig['costo_por_1k_tokens'] > 0) {
                $costo = ($tokensUsados / 1000) * $modeloConfig['costo_por_1k_tokens'];
            }
            
            // Para modelos de transcripción, si tienen costo por minuto
            if (isset($modeloConfig['configuracion']['costo_por_minuto'])) {
                $duracionMinutos = $tokensUsados / 150; // Estimación: 150 palabras por minuto
                $costo = $duracionMinutos * $modeloConfig['configuracion']['costo_por_minuto'];
            }
            
            $stmt = $db->prepare("
                INSERT INTO ia_modelos_uso (
                    modelo_config_id,
                    actividad_id,
                    tokens_total,
                    costo_usd,
                    exitoso
                ) VALUES (
                    :modelo_id,
                    :actividad_id,
                    :tokens,
                    :costo,
                    1
                )
            ");
            
            $stmt->bindParam(':modelo_id', $modeloId);
            $stmt->bindParam(':actividad_id', $actividadId);
            $stmt->bindParam(':tokens', $tokensUsados);
            $stmt->bindParam(':costo', $costo);
            $stmt->execute();
            
        } catch (Exception $e) {
            error_log("Error registrando uso de modelo: " . $e->getMessage());
            // No lanzamos excepción para no interrumpir el proceso principal
        }
    }
}