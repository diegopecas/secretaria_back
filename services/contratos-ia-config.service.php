<?php
class ContratosIAConfigService
{
    /**
     * Obtener configuración IA de un contrato
     */
    public static function obtenerConfiguracion($contratoId)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.ver_config_ia')) {
                responderJSON(['error' => 'No tiene permisos para ver configuración IA'], 403);
                return;
            }
            
            $db = Flight::db();
            
            // Obtener configuraciones del contrato
            $stmt = $db->prepare("
                SELECT 
                    cic.*,
                    c.numero_contrato,
                    c.embeddings_modelo_id,
                    im.modelo as modelo_nombre,
                    im.proveedor as modelo_proveedor
                FROM contratos c
                LEFT JOIN contratos_ia_config cic ON c.id = cic.contrato_id AND cic.activa = 1
                LEFT JOIN ia_modelos im ON c.embeddings_modelo_id = im.id
                WHERE c.id = :contrato_id
            ");
            
            $stmt->bindParam(':contrato_id', $contratoId);
            $stmt->execute();
            
            $configuraciones = [];
            $infoContrato = null;
            
            while ($row = $stmt->fetch()) {
                if (!$infoContrato) {
                    $infoContrato = [
                        'contrato_id' => $contratoId,
                        'numero_contrato' => $row['numero_contrato'],
                        'embeddings_modelo_id' => $row['embeddings_modelo_id'],
                        'modelo_nombre' => $row['modelo_nombre'],
                        'modelo_proveedor' => $row['modelo_proveedor']
                    ];
                }
                
                if ($row['id']) {
                    // Decodificar JSON de configuración (sin mostrar las keys completas)
                    $config = json_decode($row['configuracion_json'], true);
                    $configSegura = self::ocultarApiKeys($config);
                    
                    $configuraciones[] = [
                        'id' => $row['id'],
                        'proveedor' => $row['proveedor'],
                        'configuracion' => $configSegura,
                        'activa' => $row['activa'],
                        'fecha_creacion' => $row['fecha_creacion'],
                        'fecha_actualizacion' => $row['fecha_actualizacion']
                    ];
                }
            }
            
            responderJSON([
                'success' => true,
                'contrato' => $infoContrato,
                'configuraciones' => $configuraciones
            ]);
            
        } catch (Exception $e) {
            error_log("Error obteniendo configuración IA: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener configuración'], 500);
        }
    }
    
    /**
     * Guardar configuración IA
     */
    public static function guardarConfiguracion()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.configurar_ia')) {
                responderJSON(['error' => 'No tiene permisos para configurar IA'], 403);
                return;
            }
            
            $data = Flight::request()->data->getData();
            
            $contrato_id = $data['contrato_id'] ?? null;
            $proveedor = $data['proveedor'] ?? null;
            $configuracion = $data['configuracion'] ?? null;
            
            if (!$contrato_id || !$proveedor || !$configuracion) {
                responderJSON(['error' => 'Datos incompletos'], 400);
                return;
            }
            
            // Validar que el contrato existe
            $db = Flight::db();
            $stmt = $db->prepare("SELECT id FROM contratos WHERE id = :id");
            $stmt->bindParam(':id', $contrato_id);
            $stmt->execute();
            
            if (!$stmt->fetch()) {
                responderJSON(['error' => 'Contrato no encontrado'], 404);
                return;
            }
            
            // Validar proveedor
            $proveedoresValidos = ['openai', 'gemini', 'anthropic'];
            if (!in_array($proveedor, $proveedoresValidos)) {
                responderJSON(['error' => 'Proveedor no válido'], 400);
                return;
            }
            
            // Encriptar configuración sensible
            $configuracionJson = json_encode($configuracion);
            
            $db->beginTransaction();
            
            try {
                // Desactivar configuraciones anteriores del mismo proveedor
                $stmtDesactivar = $db->prepare("
                    UPDATE contratos_ia_config 
                    SET activa = 0 
                    WHERE contrato_id = :contrato_id 
                    AND proveedor = :proveedor
                ");
                $stmtDesactivar->bindParam(':contrato_id', $contrato_id);
                $stmtDesactivar->bindParam(':proveedor', $proveedor);
                $stmtDesactivar->execute();
                
                // Insertar nueva configuración
                $stmtInsertar = $db->prepare("
                    INSERT INTO contratos_ia_config (
                        contrato_id,
                        proveedor,
                        configuracion_json,
                        activa
                    ) VALUES (
                        :contrato_id,
                        :proveedor,
                        :configuracion_json,
                        1
                    )
                ");
                
                $stmtInsertar->bindParam(':contrato_id', $contrato_id);
                $stmtInsertar->bindParam(':proveedor', $proveedor);
                $stmtInsertar->bindParam(':configuracion_json', $configuracionJson);
                $stmtInsertar->execute();
                
                $db->commit();
                
                // Auditoría
                AuditService::registrar(
                    'contratos_ia_config',
                    $db->lastInsertId(),
                    'CREATE',
                    null,
                    ['contrato_id' => $contrato_id, 'proveedor' => $proveedor]
                );
                
                responderJSON([
                    'success' => true,
                    'message' => 'Configuración guardada correctamente'
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            error_log("Error guardando configuración IA: " . $e->getMessage());
            responderJSON(['error' => 'Error al guardar configuración'], 500);
        }
    }
    
    /**
     * Eliminar configuración
     */
    public static function eliminarConfiguracion()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.configurar_ia')) {
                responderJSON(['error' => 'No tiene permisos para configurar IA'], 403);
                return;
            }
            
            $data = Flight::request()->data->getData();
            $id = $data['id'] ?? null;
            
            if (!$id) {
                responderJSON(['error' => 'ID no proporcionado'], 400);
                return;
            }
            
            $db = Flight::db();
            
            // Verificar que existe y obtener datos para auditoría
            $stmt = $db->prepare("
                SELECT cic.*, c.numero_contrato 
                FROM contratos_ia_config cic
                INNER JOIN contratos c ON cic.contrato_id = c.id
                WHERE cic.id = :id
            ");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $config = $stmt->fetch();
            
            if (!$config) {
                responderJSON(['error' => 'Configuración no encontrada'], 404);
                return;
            }
            
            // Eliminar (soft delete - solo desactivar)
            $stmtUpdate = $db->prepare("
                UPDATE contratos_ia_config 
                SET activa = 0 
                WHERE id = :id
            ");
            $stmtUpdate->bindParam(':id', $id);
            $stmtUpdate->execute();
            
            // Auditoría
            AuditService::registrar(
                'contratos_ia_config',
                $id,
                'DELETE',
                $config,
                null
            );
            
            responderJSON([
                'success' => true,
                'message' => 'Configuración eliminada correctamente'
            ]);
            
        } catch (Exception $e) {
            error_log("Error eliminando configuración IA: " . $e->getMessage());
            responderJSON(['error' => 'Error al eliminar configuración'], 500);
        }
    }
    
    /**
     * Obtener configuración para uso interno (con keys completas)
     */
    public static function obtenerConfiguracionInterna($contratoId, $proveedor)
    {
        try {
            $db = Flight::db();
            
            $stmt = $db->prepare("
                SELECT configuracion_json
                FROM contratos_ia_config
                WHERE contrato_id = :contrato_id
                AND proveedor = :proveedor
                AND activa = 1
                LIMIT 1
            ");
            
            $stmt->bindParam(':contrato_id', $contratoId);
            $stmt->bindParam(':proveedor', $proveedor);
            $stmt->execute();
            
            $config = $stmt->fetch();
            
            if ($config) {
                return json_decode($config['configuracion_json'], true);
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("Error obteniendo configuración interna: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Ocultar API keys para mostrar al usuario
     */
    private static function ocultarApiKeys($config)
    {
        if (!is_array($config)) {
            return $config;
        }
        
        $configSegura = [];
        
        foreach ($config as $key => $value) {
            if (stripos($key, 'key') !== false || stripos($key, 'secret') !== false) {
                // Mostrar solo los primeros y últimos caracteres
                if (strlen($value) > 8) {
                    $configSegura[$key] = substr($value, 0, 4) . '...' . substr($value, -4);
                } else {
                    $configSegura[$key] = '********';
                }
            } else {
                $configSegura[$key] = $value;
            }
        }
        
        return $configSegura;
    }
}