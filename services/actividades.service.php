<?php
class ActividadesService
{
    // Crear nueva actividad
    public static function crear()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'actividades.registrar')) {
                responderJSON(['error' => 'No tiene permisos para registrar actividades'], 403);
                return;
            }
            
            $data = Flight::request()->data->getData();
            
            // Validaciones básicas
            $contrato_id = $data['contrato_id'] ?? null;
            $fecha_actividad = $data['fecha_actividad'] ?? null;
            $descripcion_actividad = $data['descripcion_actividad'] ?? null;
            
            if (!$contrato_id || !$fecha_actividad || !$descripcion_actividad) {
                responderJSON(['error' => 'Datos incompletos'], 400);
                return;
            }
            
            $db = Flight::db();
            
            // Verificar que el contrato existe y el usuario tiene acceso
            $stmtContrato = $db->prepare("
                SELECT c.id, c.estado, ct.email 
                FROM contratos c
                INNER JOIN contratistas ct ON c.contratista_id = ct.id
                WHERE c.id = :contrato_id
            ");
            $stmtContrato->bindParam(':contrato_id', $contrato_id);
            $stmtContrato->execute();
            $contrato = $stmtContrato->fetch();
            
            if (!$contrato) {
                responderJSON(['error' => 'Contrato no encontrado'], 404);
                return;
            }
            
            // Si es rol usuario, verificar que sea su contrato
            $rolesUsuario = AuthService::getUserRoles($currentUser['id']);
            $esUsuarioBasico = in_array('usuario', array_column($rolesUsuario, 'codigo'));
            
            if ($esUsuarioBasico && $contrato['email'] !== $currentUser['email']) {
                responderJSON(['error' => 'No tiene acceso a este contrato'], 403);
                return;
            }
            
            // Verificar que el contrato esté activo
            if ($contrato['estado'] !== 'activo') {
                responderJSON(['error' => 'El contrato no está activo'], 400);
                return;
            }
            
            $db->beginTransaction();
            
            try {
                // Preparar datos adicionales
                $obligacion_id = $data['obligacion_id'] ?? null;
                $metadata_json = isset($data['metadata']) ? json_encode($data['metadata']) : null;
                
                // Campos de transcripción
                $transcripcion_texto = $data['transcripcion_texto'] ?? null;
                $transcripcion_proveedor = $data['transcripcion_proveedor'] ?? null;
                $transcripcion_modelo = $data['transcripcion_modelo'] ?? null;
                $transcripcion_confianza = $data['transcripcion_confianza'] ?? null;
                $transcripcion_fecha = !empty($transcripcion_texto) ? date('Y-m-d H:i:s') : null;
                
                // Audio narrado
                $audio_narrado_url = $data['audio_narrado_url'] ?? null;
                
                // Insertar actividad
                $sql = "INSERT INTO registro_actividades (
                    contrato_id,
                    fecha_actividad,
                    descripcion_actividad,
                    audio_narrado_url,
                    transcripcion_texto,
                    transcripcion_proveedor,
                    transcripcion_modelo,
                    transcripcion_confianza,
                    transcripcion_fecha,
                    obligacion_id,
                    metadata_json,
                    usuario_registro_id
                ) VALUES (
                    :contrato_id,
                    :fecha_actividad,
                    :descripcion_actividad,
                    :audio_narrado_url,
                    :transcripcion_texto,
                    :transcripcion_proveedor,
                    :transcripcion_modelo,
                    :transcripcion_confianza,
                    :transcripcion_fecha,
                    :obligacion_id,
                    :metadata_json,
                    :usuario_registro_id
                )";
                
                $stmt = $db->prepare($sql);
                $stmt->bindParam(':contrato_id', $contrato_id);
                $stmt->bindParam(':fecha_actividad', $fecha_actividad);
                $stmt->bindParam(':descripcion_actividad', $descripcion_actividad);
                $stmt->bindParam(':audio_narrado_url', $audio_narrado_url);
                $stmt->bindParam(':transcripcion_texto', $transcripcion_texto);
                $stmt->bindParam(':transcripcion_proveedor', $transcripcion_proveedor);
                $stmt->bindParam(':transcripcion_modelo', $transcripcion_modelo);
                $stmt->bindParam(':transcripcion_confianza', $transcripcion_confianza);
                $stmt->bindParam(':transcripcion_fecha', $transcripcion_fecha);
                $stmt->bindParam(':obligacion_id', $obligacion_id);
                $stmt->bindParam(':metadata_json', $metadata_json);
                $stmt->bindParam(':usuario_registro_id', $currentUser['id']);
                
                $stmt->execute();
                
                $actividadId = $db->lastInsertId();
                
                // Si hay archivos adjuntos, procesarlos
                if (!empty($_FILES)) {
                    self::procesarArchivosAdjuntos($actividadId, $_FILES);
                }
                
                // Si hay transcripción, generar embeddings
                if (!empty($transcripcion_texto) || !empty($descripcion_actividad)) {
                    self::generarEmbeddings($actividadId, $transcripcion_texto ?: $descripcion_actividad);
                }
                
                $db->commit();
                
                // AUDITORÍA
                $datosAuditoria = [
                    'id' => $actividadId,
                    'contrato_id' => $contrato_id,
                    'fecha_actividad' => $fecha_actividad,
                    'descripcion' => substr($descripcion_actividad, 0, 100) . '...'
                ];
                
                AuditService::registrar('registro_actividades', $actividadId, 'CREATE', null, $datosAuditoria);
                
                responderJSON([
                    'success' => true,
                    'id' => $actividadId,
                    'message' => 'Actividad registrada correctamente'
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            error_log("ERROR en crear actividad: " . $e->getMessage());
            responderJSON(['error' => 'Error al crear actividad: ' . $e->getMessage()], 500);
        }
    }
    
    // Método privado para generar embeddings
    private static function generarEmbeddings($actividadId, $texto)
    {
        try {
            // Obtener configuración del modelo de embeddings predeterminado
            $db = Flight::db();
            $stmtModelo = $db->prepare("
                SELECT mc.*, tm.codigo as tipo_codigo
                FROM ia_modelos_config mc
                INNER JOIN tipos_modelo_ia tm ON mc.tipo_modelo_id = tm.id
                WHERE tm.codigo = 'embedding' 
                AND mc.activo = 1 
                AND mc.es_predeterminado = 1
                LIMIT 1
            ");
            $stmtModelo->execute();
            $modeloConfig = $stmtModelo->fetch();
            
            if (!$modeloConfig) {
                error_log("No hay modelo de embeddings configurado");
                return;
            }
            
            // TODO: Aquí se integraría con el provider real
            // Por ahora solo marcamos como pendiente
            error_log("Embeddings pendientes para actividad $actividadId con modelo {$modeloConfig['modelo']}");
            
        } catch (Exception $e) {
            error_log("Error generando embeddings: " . $e->getMessage());
            // No lanzamos la excepción para no interrumpir el guardado
        }
    }
    
    // Obtener actividades por contrato y período
    public static function obtenerPorPeriodo()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'actividades.ver') && 
                !AuthService::checkPermission($currentUser['id'], 'actividades.registrar')) {
                responderJSON(['error' => 'No tiene permisos para ver actividades'], 403);
                return;
            }
            
            // Parámetros
            $contrato_id = Flight::request()->query['contrato_id'] ?? null;
            $mes = Flight::request()->query['mes'] ?? date('n');
            $anio = Flight::request()->query['anio'] ?? date('Y');
            
            if (!$contrato_id) {
                responderJSON(['error' => 'Contrato no especificado'], 400);
                return;
            }
            
            $db = Flight::db();
            
            // Verificar acceso al contrato
            $stmtAcceso = $db->prepare("
                SELECT c.id, ct.email 
                FROM contratos c
                INNER JOIN contratistas ct ON c.contratista_id = ct.id
                WHERE c.id = :contrato_id
            ");
            $stmtAcceso->bindParam(':contrato_id', $contrato_id);
            $stmtAcceso->execute();
            $contratoAcceso = $stmtAcceso->fetch();
            
            if (!$contratoAcceso) {
                responderJSON(['error' => 'Contrato no encontrado'], 404);
                return;
            }
            
            // Verificar acceso si es usuario
            $isUsuarioRole = in_array('usuario', $currentUser['roles'] ?? []);
            if ($isUsuarioRole && $contratoAcceso['email'] !== $currentUser['email']) {
                responderJSON(['error' => 'No tiene acceso a este contrato'], 403);
                return;
            }
            
            // Obtener actividades del período
            $sql = "SELECT 
                    ra.id,
                    ra.fecha_actividad,
                    ra.descripcion_actividad,
                    ra.obligacion_id,
                    ra.metadata_json,
                    ra.procesado_ia,
                    ra.fecha_registro,
                    oc.numero_obligacion,
                    oc.descripcion as obligacion_descripcion,
                    (SELECT COUNT(*) FROM registro_actividades_archivos WHERE actividad_id = ra.id) as total_archivos
                FROM registro_actividades ra
                LEFT JOIN obligaciones_contractuales oc ON ra.obligacion_id = oc.id
                WHERE ra.contrato_id = :contrato_id
                AND MONTH(ra.fecha_actividad) = :mes
                AND YEAR(ra.fecha_actividad) = :anio
                ORDER BY ra.fecha_actividad DESC, ra.fecha_registro DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':contrato_id', $contrato_id);
            $stmt->bindParam(':mes', $mes);
            $stmt->bindParam(':anio', $anio);
            $stmt->execute();
            
            $actividades = $stmt->fetchAll();
            
            // Decodificar metadata JSON
            foreach ($actividades as &$actividad) {
                if ($actividad['metadata_json']) {
                    $actividad['metadata'] = json_decode($actividad['metadata_json'], true);
                }
                unset($actividad['metadata_json']);
            }
            
            responderJSON([
                'success' => true,
                'periodo' => [
                    'mes' => (int)$mes,
                    'anio' => (int)$anio
                ],
                'total' => count($actividades),
                'actividades' => $actividades
            ]);
            
        } catch (Exception $e) {
            error_log("ERROR en obtenerPorPeriodo: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener actividades'], 500);
        }
    }
    
    // Buscar actividades usando IA
    public static function buscar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'actividades.ver')) {
                responderJSON(['error' => 'No tiene permisos para buscar actividades'], 403);
                return;
            }
            
            $data = Flight::request()->data->getData();
            
            $pregunta = $data['pregunta'] ?? null;
            $contrato_id = $data['contrato_id'] ?? null;
            
            if (!$pregunta || !$contrato_id) {
                responderJSON(['error' => 'Debe proporcionar una pregunta y contrato'], 400);
                return;
            }
            
            // TODO: Implementar búsqueda con IA cuando esté configurada
            // Por ahora, búsqueda básica con FULLTEXT
            
            $db = Flight::db();
            
            $sql = "SELECT 
                    ra.id,
                    ra.fecha_actividad,
                    ra.descripcion_actividad,
                    ra.obligacion_id,
                    oc.descripcion as obligacion_descripcion,
                    MATCH(ra.descripcion_actividad) AGAINST(:pregunta IN NATURAL LANGUAGE MODE) as relevancia
                FROM registro_actividades ra
                LEFT JOIN obligaciones_contractuales oc ON ra.obligacion_id = oc.id
                WHERE ra.contrato_id = :contrato_id
                AND MATCH(ra.descripcion_actividad) AGAINST(:pregunta2 IN NATURAL LANGUAGE MODE)
                ORDER BY relevancia DESC
                LIMIT 10";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':contrato_id', $contrato_id);
            $stmt->bindParam(':pregunta', $pregunta);
            $stmt->bindParam(':pregunta2', $pregunta);
            $stmt->execute();
            
            $resultados = $stmt->fetchAll();
            
            responderJSON([
                'success' => true,
                'pregunta' => $pregunta,
                'resultados' => $resultados,
                'mensaje' => count($resultados) > 0 
                    ? 'Se encontraron ' . count($resultados) . ' actividades relacionadas'
                    : 'No se encontraron actividades relacionadas con tu pregunta'
            ]);
            
        } catch (Exception $e) {
            error_log("ERROR en buscar: " . $e->getMessage());
            responderJSON(['error' => 'Error al buscar actividades'], 500);
        }
    }
    
    // Actualizar actividad
    public static function actualizar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'actividades.registrar')) {
                responderJSON(['error' => 'No tiene permisos para actualizar actividades'], 403);
                return;
            }
            
            $data = Flight::request()->data->getData();
            $id = $data['id'] ?? null;
            
            if (!$id) {
                responderJSON(['error' => 'ID no proporcionado'], 400);
                return;
            }
            
            $db = Flight::db();
            
            // Obtener actividad actual
            $stmtActual = $db->prepare("
                SELECT ra.*, c.contratista_id, ct.email
                FROM registro_actividades ra
                INNER JOIN contratos c ON ra.contrato_id = c.id
                INNER JOIN contratistas ct ON c.contratista_id = ct.id
                WHERE ra.id = :id
            ");
            $stmtActual->bindParam(':id', $id);
            $stmtActual->execute();
            $actividadActual = $stmtActual->fetch();
            
            if (!$actividadActual) {
                responderJSON(['error' => 'Actividad no encontrada'], 404);
                return;
            }
            
            // Verificar acceso
            $isUsuarioRole = in_array('usuario', $currentUser['roles'] ?? []);
            if ($isUsuarioRole && $actividadActual['email'] !== $currentUser['email']) {
                responderJSON(['error' => 'No tiene acceso a esta actividad'], 403);
                return;
            }
            
            // Construir query de actualización
            $updates = [];
            $params = [':id' => $id];
            
            if (isset($data['descripcion_actividad'])) {
                $updates[] = "descripcion_actividad = :descripcion_actividad";
                $params[':descripcion_actividad'] = $data['descripcion_actividad'];
            }
            
            if (isset($data['fecha_actividad'])) {
                $updates[] = "fecha_actividad = :fecha_actividad";
                $params[':fecha_actividad'] = $data['fecha_actividad'];
            }
            
            if (isset($data['obligacion_id'])) {
                $updates[] = "obligacion_id = :obligacion_id";
                $params[':obligacion_id'] = $data['obligacion_id'] ?: null;
            }
            
            if (isset($data['metadata'])) {
                $updates[] = "metadata_json = :metadata_json";
                $params[':metadata_json'] = json_encode($data['metadata']);
            }
            
            if (!empty($updates)) {
                $sql = "UPDATE registro_actividades SET " . implode(", ", $updates) . " WHERE id = :id";
                $stmt = $db->prepare($sql);
                
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value);
                }
                
                $stmt->execute();
                
                // AUDITORÍA
                AuditService::registrar('registro_actividades', $id, 'UPDATE', 
                    $actividadActual, 
                    array_merge(['id' => $id], $data)
                );
            }
            
            responderJSON([
                'success' => true,
                'message' => 'Actividad actualizada correctamente'
            ]);
            
        } catch (Exception $e) {
            error_log("ERROR en actualizar actividad: " . $e->getMessage());
            responderJSON(['error' => 'Error al actualizar actividad'], 500);
        }
    }
    
    // Eliminar actividad
    public static function eliminar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'actividades.registrar')) {
                responderJSON(['error' => 'No tiene permisos para eliminar actividades'], 403);
                return;
            }
            
            $data = Flight::request()->data->getData();
            $id = $data['id'] ?? null;
            
            if (!$id) {
                responderJSON(['error' => 'ID no proporcionado'], 400);
                return;
            }
            
            $db = Flight::db();
            
            // Verificar que existe y tiene acceso
            $stmtVerificar = $db->prepare("
                SELECT ra.*, c.contratista_id, ct.email
                FROM registro_actividades ra
                INNER JOIN contratos c ON ra.contrato_id = c.id
                INNER JOIN contratistas ct ON c.contratista_id = ct.id
                WHERE ra.id = :id
            ");
            $stmtVerificar->bindParam(':id', $id);
            $stmtVerificar->execute();
            $actividad = $stmtVerificar->fetch();
            
            if (!$actividad) {
                responderJSON(['error' => 'Actividad no encontrada'], 404);
                return;
            }
            
            // Verificar acceso
            $isUsuarioRole = in_array('usuario', $currentUser['roles'] ?? []);
            if ($isUsuarioRole && $actividad['email'] !== $currentUser['email']) {
                responderJSON(['error' => 'No tiene acceso a esta actividad'], 403);
                return;
            }
            
            // Eliminar archivos asociados primero
            self::eliminarArchivosActividad($id);
            
            // Eliminar actividad
            $stmt = $db->prepare("DELETE FROM registro_actividades WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            // AUDITORÍA
            AuditService::registrar('registro_actividades', $id, 'DELETE', $actividad, null);
            
            responderJSON([
                'success' => true,
                'message' => 'Actividad eliminada correctamente'
            ]);
            
        } catch (Exception $e) {
            error_log("ERROR en eliminar actividad: " . $e->getMessage());
            responderJSON(['error' => 'Error al eliminar actividad'], 500);
        }
    }
    
    // Métodos auxiliares privados
    private static function procesarArchivosAdjuntos($actividadId, $files)
    {
        require_once __DIR__ . '/../providers/storage/storage.manager.php';
        
        $db = Flight::db();
        $storage = StorageManager::getInstance();
        
        foreach ($files as $key => $file) {
            if ($file['error'] === UPLOAD_ERR_OK) {
                try {
                    // Guardar archivo usando StorageManager
                    $archivoInfo = $storage->guardarArchivo($file, 'actividades', (string)$actividadId);
                    
                    // Actualizar registro_actividades con info del archivo
                    $stmt = $db->prepare("
                        UPDATE registro_actividades 
                        SET tiene_adjunto = 1,
                            tipo_archivo = :tipo_archivo,
                            nombre_archivo = :nombre_archivo,
                            archivo_url = :archivo_url,
                            tamanio_bytes = :tamanio_bytes,
                            mime_type = :mime_type,
                            hash_archivo = :hash_archivo
                        WHERE id = :id
                    ");
                    
                    $stmt->bindParam(':id', $actividadId);
                    $stmt->bindParam(':tipo_archivo', $archivoInfo['tipo_archivo']);
                    $stmt->bindParam(':nombre_archivo', $archivoInfo['nombre_original']);
                    $stmt->bindParam(':archivo_url', $archivoInfo['path']);
                    $stmt->bindParam(':tamanio_bytes', $archivoInfo['size']);
                    $stmt->bindParam(':mime_type', $archivoInfo['mime_type']);
                    $stmt->bindParam(':hash_archivo', $archivoInfo['hash']);
                    $stmt->execute();
                    
                    error_log("Archivo guardado para actividad $actividadId: " . $archivoInfo['nombre_original']);
                    
                } catch (Exception $e) {
                    error_log("Error procesando archivo para actividad $actividadId: " . $e->getMessage());
                    // No lanzamos la excepción para no interrumpir el proceso
                }
            }
        }
    }
    
    private static function eliminarArchivosActividad($actividadId)
    {
        require_once __DIR__ . '/../providers/storage/storage.manager.php';
        
        $db = Flight::db();
        $storage = StorageManager::getInstance();
        
        // Obtener archivo de la actividad si existe
        $stmt = $db->prepare("
            SELECT archivo_url 
            FROM registro_actividades 
            WHERE id = :id AND tiene_adjunto = 1
        ");
        $stmt->bindParam(':id', $actividadId);
        $stmt->execute();
        $archivo = $stmt->fetch();
        
        if ($archivo && $archivo['archivo_url']) {
            try {
                $storage->eliminarArchivo($archivo['archivo_url']);
                error_log("Archivo eliminado de actividad $actividadId");
            } catch (Exception $e) {
                error_log("Error eliminando archivo de actividad $actividadId: " . $e->getMessage());
            }
        }
    }
    
    // Obtener resumen de actividades por contrato
    public static function obtenerResumen()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            $contrato_id = Flight::request()->query['contrato_id'] ?? null;
            
            if (!$contrato_id) {
                responderJSON(['error' => 'Contrato no especificado'], 400);
                return;
            }
            
            $db = Flight::db();
            
            // Obtener resumen por meses
            $sql = "SELECT 
                    YEAR(fecha_actividad) as anio,
                    MONTH(fecha_actividad) as mes,
                    COUNT(*) as total_actividades,
                    COUNT(DISTINCT obligacion_id) as obligaciones_cubiertas,
                    COUNT(DISTINCT DATE(fecha_actividad)) as dias_trabajados
                FROM registro_actividades
                WHERE contrato_id = :contrato_id
                GROUP BY YEAR(fecha_actividad), MONTH(fecha_actividad)
                ORDER BY anio DESC, mes DESC
                LIMIT 12";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':contrato_id', $contrato_id);
            $stmt->execute();
            
            $resumen = $stmt->fetchAll();
            
            responderJSON([
                'success' => true,
                'resumen' => $resumen
            ]);
            
        } catch (Exception $e) {
            error_log("ERROR en obtenerResumen: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener resumen'], 500);
        }
    }
}