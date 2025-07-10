<?php
class ActividadesService
{
    // Crear nueva actividad (solo la actividad principal)
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
            
            // Obtener datos del FormData
            $contrato_id = $_POST['contrato_id'] ?? null;
            $fecha_actividad = $_POST['fecha_actividad'] ?? null;
            $descripcion_actividad = $_POST['descripcion_actividad'] ?? null;
            $obligaciones_ids = isset($_POST['obligaciones_ids']) ? json_decode($_POST['obligaciones_ids'], true) : [];
            $metadata = isset($_POST['metadata']) ? json_decode($_POST['metadata'], true) : null;
            
            // Campos de transcripción
            $transcripcion_texto = $_POST['transcripcion_texto'] ?? null;
            $transcripcion_proveedor = $_POST['transcripcion_proveedor'] ?? null;
            $transcripcion_modelo = $_POST['transcripcion_modelo'] ?? null;
            $transcripcion_confianza = $_POST['transcripcion_confianza'] ?? null;
            
            // Audio narrado
            $audio_narrado_url = $_POST['audio_narrado_url'] ?? null;
            
            // Validaciones básicas
            if (!$contrato_id || !$fecha_actividad || !$descripcion_actividad) {
                responderJSON(['error' => 'Datos incompletos'], 400);
                return;
            }
            
            // Verificar contrato
            $contrato = self::verificarAccesoContrato($contrato_id, $currentUser);
            if (!$contrato) {
                return;
            }
            
            $db = Flight::db();
            $db->beginTransaction();
            
            try {
                // Insertar actividad principal
                $actividadId = self::insertarActividad([
                    'contrato_id' => $contrato_id,
                    'fecha_actividad' => $fecha_actividad,
                    'descripcion_actividad' => $descripcion_actividad,
                    'audio_narrado_url' => $audio_narrado_url,
                    'transcripcion_texto' => $transcripcion_texto,
                    'transcripcion_proveedor' => $transcripcion_proveedor,
                    'transcripcion_modelo' => $transcripcion_modelo,
                    'transcripcion_confianza' => $transcripcion_confianza,
                    'metadata' => $metadata,
                    'usuario_id' => $currentUser['id']
                ], $db);
                
                // Delegar asignación de obligaciones al servicio correspondiente
                if (!empty($obligaciones_ids)) {
                    require_once __DIR__ . '/actividades-obligaciones.service.php';
                    ActividadesObligacionesService::asignar($actividadId, $obligaciones_ids, $db);
                }
                
                // Delegar procesamiento de archivos al servicio correspondiente
                if (!empty($_FILES)) {
                    require_once __DIR__ . '/actividades-archivos.service.php';
                    ActividadesArchivosService::agregar($actividadId, $_FILES, $currentUser['id'], $db);
                }
                
                // Generar embeddings si hay texto
                $textoParaEmbeddings = $transcripcion_texto ?: $descripcion_actividad;
                if (!empty($textoParaEmbeddings)) {
                    self::generarEmbeddings($actividadId, $textoParaEmbeddings);
                }
                
                $db->commit();
                
                // AUDITORÍA
                $datosAuditoria = [
                    'id' => $actividadId,
                    'contrato_id' => $contrato_id,
                    'fecha_actividad' => $fecha_actividad,
                    'descripcion' => substr($descripcion_actividad, 0, 100) . '...',
                    'obligaciones' => count($obligaciones_ids),
                    'archivos' => count($_FILES)
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
    
    // Obtener actividades por período
    public static function obtenerPorPeriodo()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos
            if (!self::verificarPermisoLectura($currentUser['id'])) {
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
            
            // Verificar acceso al contrato
            $contrato = self::verificarAccesoContrato($contrato_id, $currentUser);
            if (!$contrato) {
                return;
            }
            
            $db = Flight::db();
            
            // Obtener actividades del período
            $sql = "SELECT 
                    ra.id,
                    ra.contrato_id,
                    ra.fecha_actividad,
                    ra.descripcion_actividad,
                    ra.metadata_json,
                    ra.procesado_ia,
                    ra.fecha_registro,
                    ra.transcripcion_texto,
                    ra.transcripcion_proveedor
                FROM registro_actividades ra
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
            
            // Enriquecer cada actividad con sus relaciones
            require_once __DIR__ . '/actividades-obligaciones.service.php';
            require_once __DIR__ . '/actividades-archivos.service.php';
            
            foreach ($actividades as &$actividad) {
                // Decodificar metadata JSON
                if ($actividad['metadata_json']) {
                    $actividad['metadata'] = json_decode($actividad['metadata_json'], true);
                }
                unset($actividad['metadata_json']);
                
                // Obtener obligaciones
                $actividad['obligaciones'] = ActividadesObligacionesService::obtenerPorActividad($actividad['id']);
                $actividad['total_obligaciones'] = count($actividad['obligaciones']);
                
                // Construir info de obligaciones para mostrar
                $actividad['obligaciones_info'] = implode(' | ', array_map(function($obl) {
                    return $obl['numero_obligacion'] . '. ' . substr($obl['descripcion'], 0, 50);
                }, $actividad['obligaciones']));
                
                // Obtener archivos
                $archivos = ActividadesArchivosService::obtenerPorActividad($actividad['id']);
                $actividad['total_archivos'] = count($archivos);
                $actividad['archivos'] = array_map(function($archivo) {
                    return [
                        'id' => $archivo['id'],
                        'nombre_archivo' => $archivo['nombre_archivo'],
                        'tipo_archivo_nombre' => $archivo['tipo_archivo_nombre'],
                        'tamanio_bytes' => $archivo['tamanio_bytes']
                    ];
                }, $archivos);
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
    
    // Obtener actividad por ID
    public static function obtenerPorId()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            $id = Flight::request()->query['id'] ?? null;
            
            if (!$id) {
                responderJSON(['error' => 'ID no proporcionado'], 400);
                return;
            }
            
            $db = Flight::db();
            
            // Obtener actividad con información del contrato
            $actividad = self::obtenerActividadConContrato($id);
            
            if (!$actividad) {
                responderJSON(['error' => 'Actividad no encontrada'], 404);
                return;
            }
            
            // Verificar acceso
            if (!self::verificarAccesoActividad($actividad, $currentUser)) {
                responderJSON(['error' => 'No tiene acceso a esta actividad'], 403);
                return;
            }
            
            // Decodificar metadata
            if ($actividad['metadata_json']) {
                $actividad['metadata'] = json_decode($actividad['metadata_json'], true);
            }
            unset($actividad['metadata_json']);
            
            // Obtener relaciones
            require_once __DIR__ . '/actividades-obligaciones.service.php';
            require_once __DIR__ . '/actividades-archivos.service.php';
            
            $actividad['obligaciones'] = ActividadesObligacionesService::obtenerPorActividad($id);
            $actividad['archivos'] = ActividadesArchivosService::obtenerPorActividad($id);
            
            responderJSON([
                'success' => true,
                'actividad' => $actividad
            ]);
            
        } catch (Exception $e) {
            error_log("ERROR en obtenerPorId: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener actividad'], 500);
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
            
            $id = $_POST['id'] ?? null;
            
            if (!$id) {
                responderJSON(['error' => 'ID no proporcionado'], 400);
                return;
            }
            
            // Obtener actividad actual
            $actividadActual = self::obtenerActividadConContrato($id);
            
            if (!$actividadActual) {
                responderJSON(['error' => 'Actividad no encontrada'], 404);
                return;
            }
            
            // Verificar acceso
            if (!self::verificarAccesoActividad($actividadActual, $currentUser)) {
                responderJSON(['error' => 'No tiene acceso a esta actividad'], 403);
                return;
            }
            
            $db = Flight::db();
            $db->beginTransaction();
            
            try {
                // Actualizar campos de la actividad
                self::actualizarCamposActividad($id, $_POST, $currentUser['id'], $db);
                
                // Actualizar obligaciones si se proporcionaron
                if (isset($_POST['obligaciones_ids'])) {
                    require_once __DIR__ . '/actividades-obligaciones.service.php';
                    $obligaciones_ids = json_decode($_POST['obligaciones_ids'], true);
                    ActividadesObligacionesService::asignar($id, $obligaciones_ids, $db);
                }
                
                // Procesar nuevos archivos si se enviaron
                if (!empty($_FILES)) {
                    require_once __DIR__ . '/actividades-archivos.service.php';
                    ActividadesArchivosService::agregar($id, $_FILES, $currentUser['id'], $db);
                }
                
                $db->commit();
                
                // AUDITORÍA
                AuditService::registrar('registro_actividades', $id, 'UPDATE', 
                    $actividadActual, 
                    array_merge(['id' => $id], $_POST)
                );
                
                responderJSON([
                    'success' => true,
                    'message' => 'Actividad actualizada correctamente'
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            
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
            
            // Verificar que existe y tiene acceso
            $actividad = self::obtenerActividadConContrato($id);
            
            if (!$actividad) {
                responderJSON(['error' => 'Actividad no encontrada'], 404);
                return;
            }
            
            if (!self::verificarAccesoActividad($actividad, $currentUser)) {
                responderJSON(['error' => 'No tiene acceso a esta actividad'], 403);
                return;
            }
            
            $db = Flight::db();
            $db->beginTransaction();
            
            try {
                // Eliminar archivos (el servicio se encarga de eliminar físicamente)
                require_once __DIR__ . '/actividades-archivos.service.php';
                ActividadesArchivosService::eliminarPorActividad($id, $db);
                
                // Eliminar obligaciones (se eliminan por CASCADE, pero por consistencia)
                require_once __DIR__ . '/actividades-obligaciones.service.php';
                ActividadesObligacionesService::eliminarPorActividad($id, $db);
                
                // Eliminar actividad
                $stmt = $db->prepare("DELETE FROM registro_actividades WHERE id = :id");
                $stmt->bindParam(':id', $id);
                $stmt->execute();
                
                $db->commit();
                
                // AUDITORÍA
                AuditService::registrar('registro_actividades', $id, 'DELETE', $actividad, null);
                
                responderJSON([
                    'success' => true,
                    'message' => 'Actividad eliminada correctamente'
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            error_log("ERROR en eliminar actividad: " . $e->getMessage());
            responderJSON(['error' => 'Error al eliminar actividad'], 500);
        }
    }
    
    // Eliminar archivo específico (delega al servicio de archivos)
    public static function eliminarArchivo()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'actividades.registrar')) {
                responderJSON(['error' => 'No tiene permisos para eliminar archivos'], 403);
                return;
            }
            
            $data = Flight::request()->data->getData();
            $archivo_id = $data['archivo_id'] ?? null;
            
            if (!$archivo_id) {
                responderJSON(['error' => 'ID de archivo no proporcionado'], 400);
                return;
            }
            
            // Delegar al servicio de archivos
            require_once __DIR__ . '/actividades-archivos.service.php';
            ActividadesArchivosService::eliminar($archivo_id);
            
            responderJSON([
                'success' => true,
                'message' => 'Archivo eliminado correctamente'
            ]);
            
        } catch (Exception $e) {
            error_log("ERROR en eliminarArchivo: " . $e->getMessage());
            responderJSON(['error' => $e->getMessage()], 500);
        }
    }
    
    // Buscar actividades
    public static function buscar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!self::verificarPermisoLectura($currentUser['id'])) {
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
            
            // Verificar acceso al contrato
            $contrato = self::verificarAccesoContrato($contrato_id, $currentUser);
            if (!$contrato) {
                return;
            }
            
            $db = Flight::db();
            
            // Búsqueda en actividades
            $sql = "SELECT DISTINCT
                    ra.id,
                    ra.fecha_actividad,
                    ra.descripcion_actividad,
                    MATCH(ra.descripcion_actividad) AGAINST(:pregunta IN NATURAL LANGUAGE MODE) as relevancia
                FROM registro_actividades ra
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
            
            // Enriquecer resultados con obligaciones
            require_once __DIR__ . '/actividades-obligaciones.service.php';
            
            foreach ($resultados as &$resultado) {
                $obligaciones = ActividadesObligacionesService::obtenerPorActividad($resultado['id']);
                $resultado['obligaciones'] = array_column($obligaciones, 'descripcion');
            }
            
            // TODO: Cuando esté implementada la búsqueda en archivos, incluirla aquí
            
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
    
    // Obtener resumen de actividades
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
            
            // Verificar acceso al contrato
            $contrato = self::verificarAccesoContrato($contrato_id, $currentUser);
            if (!$contrato) {
                return;
            }
            
            $db = Flight::db();
            
            // Obtener resumen combinando información de las tres tablas
            $sql = "SELECT 
                    YEAR(ra.fecha_actividad) as anio,
                    MONTH(ra.fecha_actividad) as mes,
                    COUNT(DISTINCT ra.id) as total_actividades,
                    COUNT(DISTINCT ao.obligacion_id) as obligaciones_cubiertas,
                    COUNT(DISTINCT DATE(ra.fecha_actividad)) as dias_trabajados,
                    COUNT(DISTINCT aa.id) as archivos_adjuntos
                FROM registro_actividades ra
                LEFT JOIN actividades_obligaciones ao ON ra.id = ao.actividad_id
                LEFT JOIN actividades_archivos aa ON ra.id = aa.actividad_id
                WHERE ra.contrato_id = :contrato_id
                GROUP BY YEAR(ra.fecha_actividad), MONTH(ra.fecha_actividad)
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
    
    // MÉTODOS AUXILIARES PRIVADOS
    
    private static function insertarActividad($datos, $db)
    {
        $metadata_json = $datos['metadata'] ? json_encode($datos['metadata']) : null;
        $transcripcion_fecha = !empty($datos['transcripcion_texto']) ? date('Y-m-d H:i:s') : null;
        
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
                :metadata_json,
                :usuario_registro_id
            )";
        
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':contrato_id', $datos['contrato_id']);
        $stmt->bindParam(':fecha_actividad', $datos['fecha_actividad']);
        $stmt->bindParam(':descripcion_actividad', $datos['descripcion_actividad']);
        $stmt->bindParam(':audio_narrado_url', $datos['audio_narrado_url']);
        $stmt->bindParam(':transcripcion_texto', $datos['transcripcion_texto']);
        $stmt->bindParam(':transcripcion_proveedor', $datos['transcripcion_proveedor']);
        $stmt->bindParam(':transcripcion_modelo', $datos['transcripcion_modelo']);
        $stmt->bindParam(':transcripcion_confianza', $datos['transcripcion_confianza']);
        $stmt->bindParam(':transcripcion_fecha', $transcripcion_fecha);
        $stmt->bindParam(':metadata_json', $metadata_json);
        $stmt->bindParam(':usuario_registro_id', $datos['usuario_id']);
        
        $stmt->execute();
        
        return $db->lastInsertId();
    }
    
    private static function actualizarCamposActividad($id, $datos, $usuarioId, $db)
    {
        $updates = [];
        $params = [':id' => $id];
        
        if (isset($datos['descripcion_actividad'])) {
            $updates[] = "descripcion_actividad = :descripcion_actividad";
            $params[':descripcion_actividad'] = $datos['descripcion_actividad'];
        }
        
        if (isset($datos['fecha_actividad'])) {
            $updates[] = "fecha_actividad = :fecha_actividad";
            $params[':fecha_actividad'] = $datos['fecha_actividad'];
        }
        
        if (isset($datos['metadata'])) {
            $updates[] = "metadata_json = :metadata_json";
            $params[':metadata_json'] = $datos['metadata'];
        }
        
        // Actualizar fecha y usuario de actualización
        $updates[] = "fecha_actualizacion = NOW()";
        $updates[] = "usuario_actualizacion_id = :usuario_actualizacion_id";
        $params[':usuario_actualizacion_id'] = $usuarioId;
        
        if (!empty($updates)) {
            $sql = "UPDATE registro_actividades SET " . implode(", ", $updates) . " WHERE id = :id";
            $stmt = $db->prepare($sql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
        }
    }
    
    private static function verificarAccesoContrato($contratoId, $currentUser)
    {
        $db = Flight::db();
        
        $stmtAcceso = $db->prepare("
            SELECT c.id, c.estado, ct.email 
            FROM contratos c
            INNER JOIN contratistas ct ON c.contratista_id = ct.id
            WHERE c.id = :contrato_id
        ");
        $stmtAcceso->bindParam(':contrato_id', $contratoId);
        $stmtAcceso->execute();
        $contrato = $stmtAcceso->fetch();
        
        if (!$contrato) {
            responderJSON(['error' => 'Contrato no encontrado'], 404);
            return false;
        }
        
        // Verificar acceso si es usuario básico
        $rolesUsuario = AuthService::getUserRoles($currentUser['id']);
        $esUsuarioBasico = in_array('usuario', array_column($rolesUsuario, 'codigo'));
        
        if ($esUsuarioBasico && $contrato['email'] !== $currentUser['email']) {
            responderJSON(['error' => 'No tiene acceso a este contrato'], 403);
            return false;
        }
        
        // Verificar que el contrato esté activo (solo para crear)
        if (Flight::request()->method === 'POST' && $contrato['estado'] !== 'activo') {
            responderJSON(['error' => 'El contrato no está activo'], 400);
            return false;
        }
        
        return $contrato;
    }
    
    private static function verificarAccesoActividad($actividad, $currentUser)
    {
        $rolesUsuario = AuthService::getUserRoles($currentUser['id']);
        $esUsuarioBasico = in_array('usuario', array_column($rolesUsuario, 'codigo'));
        
        return !$esUsuarioBasico || $actividad['contratista_email'] === $currentUser['email'];
    }
    
    private static function verificarPermisoLectura($usuarioId)
    {
        return AuthService::checkPermission($usuarioId, 'actividades.ver') || 
               AuthService::checkPermission($usuarioId, 'actividades.registrar');
    }
    
    private static function obtenerActividadConContrato($actividadId)
    {
        $db = Flight::db();
        
        $sql = "SELECT 
                ra.*,
                c.numero_contrato,
                c.contratista_id,
                c.entidad_id,
                e.nombre as entidad_nombre,
                ct.email as contratista_email
            FROM registro_actividades ra
            INNER JOIN contratos c ON ra.contrato_id = c.id
            INNER JOIN entidades e ON c.entidad_id = e.id
            INNER JOIN contratistas ct ON c.contratista_id = ct.id
            WHERE ra.id = :id";
        
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':id', $actividadId);
        $stmt->execute();
        
        return $stmt->fetch();
    }
    
    private static function generarEmbeddings($actividadId, $texto)
    {
        try {
            // TODO: Implementar cuando esté configurado el provider de IA
            error_log("Embeddings pendientes para actividad $actividadId");
            
        } catch (Exception $e) {
            error_log("Error generando embeddings: " . $e->getMessage());
        }
    }
}