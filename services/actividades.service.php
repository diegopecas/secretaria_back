<?php
require_once __DIR__ . '/../providers/ai/provider-manager.php';
require_once __DIR__ . '/embeddings.service.php';
require_once __DIR__ . '/extractor.service.php';
require_once __DIR__ . '/actividades-obligaciones.service.php';
require_once __DIR__ . '/actividades-archivos.service.php';

class ActividadesService
{
    // Obtener todas las actividades con filtros
    public static function obtenerTodas()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            // Verificar permisos
            if (!self::verificarPermisoLectura($currentUser['id'])) {
                responderJSON(['error' => 'No tiene permisos para ver actividades'], 403);
                return;
            }

            // Parámetros requeridos
            $contrato_id = Flight::request()->query['contrato_id'] ?? null;
            $mes = Flight::request()->query['mes'] ?? null;
            $anio = Flight::request()->query['anio'] ?? null;

            if (!$contrato_id || !$mes || !$anio) {
                responderJSON(['error' => 'Contrato, mes y año son requeridos'], 400);
                return;
            }

            $db = Flight::db();

            // Verificar si el usuario es un contratista
            $stmtContratista = $db->prepare("
               SELECT COUNT(*) as es_contratista
               FROM usuarios_contratistas
               WHERE usuario_id = :usuario_id
           ");
            $stmtContratista->bindParam(':usuario_id', $currentUser['id']);
            $stmtContratista->execute();
            $resultContratista = $stmtContratista->fetch();

            // Si es contratista, validar que tenga acceso al contrato específico
            if ($resultContratista['es_contratista'] > 0) {
                $stmtAcceso = $db->prepare("
                   SELECT COUNT(*) as tiene_acceso
                   FROM contratos c
                   INNER JOIN usuarios_contratistas uc ON c.contratista_id = uc.contratista_id
                   WHERE c.id = :contrato_id AND uc.usuario_id = :usuario_id
               ");
                $stmtAcceso->bindParam(':contrato_id', $contrato_id);
                $stmtAcceso->bindParam(':usuario_id', $currentUser['id']);
                $stmtAcceso->execute();
                $resultadoAcceso = $stmtAcceso->fetch();

                if ($resultadoAcceso['tiene_acceso'] == 0) {
                    responderJSON(['error' => 'No tiene acceso a este contrato'], 403);
                    return;
                }
            }
            // Si NO es contratista, puede ver todas las actividades

            // Consulta simplificada - solo datos básicos
            $sql = "SELECT 
                   a.id,
                   a.contrato_id,
                   a.fecha_actividad,
                   a.descripcion_actividad,
                   a.procesado_ia,
                   a.fecha_registro,
                   c.numero_contrato,
                   e.nombre as entidad_nombre,
                   e.nombre_corto as entidad_nombre_corto,
                   cont.nombre_completo as contratista_nombre
               FROM actividades a
               JOIN contratos c ON a.contrato_id = c.id
               JOIN contratistas cont ON c.contratista_id = cont.id
               JOIN entidades e ON c.entidad_id = e.id
               WHERE a.contrato_id = :contrato_id
               AND MONTH(a.fecha_actividad) = :mes
               AND YEAR(a.fecha_actividad) = :anio
               ORDER BY a.fecha_actividad DESC, a.fecha_registro DESC";

            $stmt = $db->prepare($sql);
            $stmt->bindParam(':contrato_id', $contrato_id);
            $stmt->bindParam(':mes', $mes);
            $stmt->bindParam(':anio', $anio);
            $stmt->execute();
            $actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);

            responderJSON([
                'success' => true,
                'actividades' => $actividades,
                'total' => count($actividades)
            ]);
        } catch (Exception $e) {
            error_log("Error al obtener actividades: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener actividades: ' . $e->getMessage()], 500);
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

            $actividad = self::obtenerActividadConContrato($id);

            if (!$actividad) {
                responderJSON(['error' => 'Actividad no encontrada'], 404);
                return;
            }

            // Verificar si el usuario es un contratista
            $stmtContratista = $db->prepare("
               SELECT COUNT(*) as es_contratista
               FROM usuarios_contratistas
               WHERE usuario_id = :usuario_id
           ");
            $stmtContratista->bindParam(':usuario_id', $currentUser['id']);
            $stmtContratista->execute();
            $resultContratista = $stmtContratista->fetch();

            // Si es contratista, validar acceso
            if ($resultContratista['es_contratista'] > 0) {
                $stmtAcceso = $db->prepare("
                   SELECT COUNT(*) as tiene_acceso
                   FROM usuarios_contratistas uc
                   WHERE uc.usuario_id = :usuario_id 
                   AND uc.contratista_id = :contratista_id
               ");
                $stmtAcceso->bindParam(':usuario_id', $currentUser['id']);
                $stmtAcceso->bindParam(':contratista_id', $actividad['contratista_id']);
                $stmtAcceso->execute();
                $resultadoAcceso = $stmtAcceso->fetch();

                if ($resultadoAcceso['tiene_acceso'] == 0) {
                    responderJSON(['error' => 'No tiene acceso a esta actividad'], 403);
                    return;
                }
            }

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

    // Crear nueva actividad
    public static function crear()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'actividades.registrar')) {
                responderJSON(['error' => 'No tiene permisos para registrar actividades'], 403);
                return;
            }

            // Obtener datos del FormData
            $contrato_id = $_POST['contrato_id'] ?? null;
            $fecha_actividad = $_POST['fecha_actividad'] ?? null;
            $descripcion_actividad = $_POST['descripcion_actividad'] ?? null;
            $obligaciones = isset($_POST['obligaciones']) ? json_decode($_POST['obligaciones'], true) : [];
            $embedding_modelo_id = $_POST['embedding_modelo_id'] ?? null;

            // Validaciones básicas
            if (!$contrato_id || !$fecha_actividad || !$descripcion_actividad) {
                responderJSON(['error' => 'Datos incompletos'], 400);
                return;
            }

            $db = Flight::db();

            // Verificar si el usuario es un contratista
            $stmtContratista = $db->prepare("
            SELECT COUNT(*) as es_contratista
            FROM usuarios_contratistas
            WHERE usuario_id = :usuario_id
        ");
            $stmtContratista->bindParam(':usuario_id', $currentUser['id']);
            $stmtContratista->execute();
            $resultContratista = $stmtContratista->fetch();

            // Si es contratista, validar que tenga acceso al contrato
            if ($resultContratista['es_contratista'] > 0) {
                $stmtAcceso = $db->prepare("
                SELECT COUNT(*) as tiene_acceso
                FROM contratos c
                INNER JOIN usuarios_contratistas uc ON c.contratista_id = uc.contratista_id
                WHERE c.id = :contrato_id AND uc.usuario_id = :usuario_id
            ");
                $stmtAcceso->bindParam(':contrato_id', $contrato_id);
                $stmtAcceso->bindParam(':usuario_id', $currentUser['id']);
                $stmtAcceso->execute();
                $resultadoAcceso = $stmtAcceso->fetch();

                if ($resultadoAcceso['tiene_acceso'] == 0) {
                    responderJSON(['error' => 'No tiene acceso a este contrato'], 403);
                    return;
                }
            }

            // Verificar que el contrato esté activo
            $stmtContrato = $db->prepare("SELECT estado FROM contratos WHERE id = :contrato_id");
            $stmtContrato->bindParam(':contrato_id', $contrato_id);
            $stmtContrato->execute();
            $contrato = $stmtContrato->fetch();

            if (!$contrato || $contrato['estado'] !== 'activo') {
                responderJSON(['error' => 'El contrato no está activo'], 400);
                return;
            }

            $db->beginTransaction();

            try {
                // Insertar actividad principal
                $sql = "INSERT INTO actividades (
                    contrato_id,
                    fecha_actividad,
                    descripcion_actividad,
                    usuario_registro_id,
                    procesado_ia
                ) VALUES (
                    :contrato_id,
                    :fecha_actividad,
                    :descripcion_actividad,
                    :usuario_registro_id,
                    0
                )";

                $stmt = $db->prepare($sql);
                $stmt->bindParam(':contrato_id', $contrato_id);
                $stmt->bindParam(':fecha_actividad', $fecha_actividad);
                $stmt->bindParam(':descripcion_actividad', $descripcion_actividad);
                $stmt->bindParam(':usuario_registro_id', $currentUser['id']);
                $stmt->execute();

                $actividadId = $db->lastInsertId();

                // Delegar asignación de obligaciones
                if (!empty($obligaciones)) {
                    require_once __DIR__ . '/actividades-obligaciones.service.php';
                    ActividadesObligacionesService::asignar($actividadId, $obligaciones, $db);
                }

                // Delegar procesamiento de archivos
                $archivosGuardados = [];
                if (!empty($_FILES)) {
                    require_once __DIR__ . '/actividades-archivos.service.php';
                    $archivosGuardados = ActividadesArchivosService::agregar($actividadId, $_FILES, $currentUser['id'], $db);
                }

                // Generar embeddings de la actividad
                try {
                    require_once __DIR__ . '/embeddings.service.php';
                    require_once __DIR__ . '/extractor.service.php';
                    
                    // Construir texto completo para embeddings
                    $textoCompleto = $descripcion_actividad;
                    
                    // Agregar texto extraído de archivos
                    if (!empty($archivosGuardados)) {
                        foreach ($archivosGuardados as $archivo) {
                            // Intentar extraer texto del archivo
                            $archivoPath = __DIR__ . '/../uploads/' . $archivo['path'];
                            $textoExtraido = ExtractorService::extraerTexto($archivoPath, $archivo['tipo_archivo']);
                            
                            if ($textoExtraido) {
                                // Actualizar archivo con texto extraído
                                $stmtUpdateArchivo = $db->prepare("
                                    UPDATE actividades_archivos 
                                    SET texto_extraido = :texto,
                                        estado_extraccion = 'completado',
                                        fecha_procesamiento = NOW()
                                    WHERE id = :id
                                ");
                                $stmtUpdateArchivo->bindParam(':texto', $textoExtraido);
                                $stmtUpdateArchivo->bindParam(':id', $archivo['id']);
                                $stmtUpdateArchivo->execute();
                                
                                // Agregar al texto completo (limitado)
                                $textoCompleto .= "\n\n--- Archivo: {$archivo['nombre_original']} ---\n";
                                $textoCompleto .= ExtractorService::obtenerResumen($textoExtraido, 2000);
                            }
                        }
                    }
                    
                    // Generar embedding
                    $embeddingResult = EmbeddingsService::generar($textoCompleto, $embedding_modelo_id);
                    
                    // Actualizar actividad con embedding
                    $stmtUpdate = $db->prepare("
                        UPDATE actividades 
                        SET embeddings = :embeddings,
                            embeddings_modelo_id = :modelo_id,
                            procesado_ia = 1
                        WHERE id = :id
                    ");
                    $stmtUpdate->bindParam(':embeddings', json_encode($embeddingResult['vector']));
                    $stmtUpdate->bindParam(':modelo_id', $embeddingResult['modelo_id']);
                    $stmtUpdate->bindParam(':id', $actividadId);
                    $stmtUpdate->execute();
                    
                } catch (Exception $e) {
                    // Si falla el embedding, no fallar toda la operación
                    error_log("Error generando embedding para actividad $actividadId: " . $e->getMessage());
                    // La actividad queda marcada con procesado_ia = 0
                }

                $db->commit();

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

            $db = Flight::db();

            // Obtener la actividad actual
            $actividad = self::obtenerActividadConContrato($id);
            if (!$actividad) {
                responderJSON(['error' => 'Actividad no encontrada'], 404);
                return;
            }

            // Guardar descripción anterior para comparar
            $descripcionAnterior = $actividad['descripcion_actividad'];

            // Verificar si el usuario es un contratista
            $stmtContratista = $db->prepare("
            SELECT COUNT(*) as es_contratista
            FROM usuarios_contratistas
            WHERE usuario_id = :usuario_id
        ");
            $stmtContratista->bindParam(':usuario_id', $currentUser['id']);
            $stmtContratista->execute();
            $resultContratista = $stmtContratista->fetch();

            // Si es contratista, validar acceso
            if ($resultContratista['es_contratista'] > 0) {
                $stmtAcceso = $db->prepare("
                SELECT COUNT(*) as tiene_acceso
                FROM usuarios_contratistas uc
                WHERE uc.usuario_id = :usuario_id 
                AND uc.contratista_id = :contratista_id
            ");
                $stmtAcceso->bindParam(':usuario_id', $currentUser['id']);
                $stmtAcceso->bindParam(':contratista_id', $actividad['contratista_id']);
                $stmtAcceso->execute();
                $resultadoAcceso = $stmtAcceso->fetch();

                if ($resultadoAcceso['tiene_acceso'] == 0) {
                    responderJSON(['error' => 'No tiene acceso a esta actividad'], 403);
                    return;
                }
            }

            $db->beginTransaction();

            try {
                // Actualizar campos básicos
                $updates = [];
                $params = [':id' => $id];
                $necesitaRegenerarEmbedding = false;

                // Campos actualizables
                $camposActualizables = [
                    'descripcion_actividad',
                    'fecha_actividad'
                ];

                foreach ($camposActualizables as $campo) {
                    if (isset($_POST[$campo])) {
                        $updates[] = "$campo = :$campo";
                        $params[":$campo"] = $_POST[$campo];
                        
                        // Si cambió la descripción, necesitamos regenerar embeddings
                        if ($campo === 'descripcion_actividad' && $_POST[$campo] !== $descripcionAnterior) {
                            $necesitaRegenerarEmbedding = true;
                        }
                    }
                }

                if (!empty($updates)) {
                    $sql = "UPDATE actividades SET " . implode(", ", $updates) . " WHERE id = :id";
                    $stmt = $db->prepare($sql);
                    foreach ($params as $key => $value) {
                        $stmt->bindValue($key, $value);
                    }
                    $stmt->execute();
                }

                // Actualizar obligaciones si se proporcionaron
                if (isset($_POST['obligaciones'])) {
                    require_once __DIR__ . '/actividades-obligaciones.service.php';
                    $obligaciones = json_decode($_POST['obligaciones'], true);
                    ActividadesObligacionesService::asignar($id, $obligaciones, $db);
                }

                // Procesar nuevos archivos si se enviaron
                $nuevosArchivos = false;
                if (!empty($_FILES)) {
                    require_once __DIR__ . '/actividades-archivos.service.php';
                    ActividadesArchivosService::agregar($id, $_FILES, $currentUser['id'], $db);
                    $nuevosArchivos = true;
                }

                // Regenerar embeddings si es necesario
                if ($necesitaRegenerarEmbedding || $nuevosArchivos || !$actividad['procesado_ia']) {
                    try {
                        require_once __DIR__ . '/embeddings.service.php';
                        require_once __DIR__ . '/extractor.service.php';
                        require_once __DIR__ . '/actividades-archivos.service.php';
                        
                        // Obtener descripción actualizada
                        $descripcionActual = $_POST['descripcion_actividad'] ?? $descripcionAnterior;
                        $textoCompleto = $descripcionActual;
                        
                        // Obtener todos los archivos de la actividad
                        $archivos = ActividadesArchivosService::obtenerPorActividad($id);
                        
                        foreach ($archivos as $archivo) {
                            if ($archivo['texto_extraido']) {
                                $textoCompleto .= "\n\n--- Archivo: {$archivo['nombre_archivo']} ---\n";
                                $textoCompleto .= ExtractorService::obtenerResumen($archivo['texto_extraido'], 2000);
                            } elseif ($archivo['extraer_texto'] && $archivo['estado_extraccion'] === 'pendiente') {
                                // Intentar extraer texto si está pendiente
                                $archivoPath = __DIR__ . '/../' . $archivo['archivo_url'];
                                if (file_exists($archivoPath)) {
                                    $textoExtraido = ExtractorService::extraerTexto($archivoPath, $archivo['tipo_archivo_codigo'] ?? '');
                                    
                                    if ($textoExtraido) {
                                        // Actualizar archivo con texto extraído
                                        $stmtUpdateArchivo = $db->prepare("
                                            UPDATE actividades_archivos 
                                            SET texto_extraido = :texto,
                                                estado_extraccion = 'completado',
                                                fecha_procesamiento = NOW()
                                            WHERE id = :id
                                        ");
                                        $stmtUpdateArchivo->bindParam(':texto', $textoExtraido);
                                        $stmtUpdateArchivo->bindParam(':id', $archivo['id']);
                                        $stmtUpdateArchivo->execute();
                                        
                                        $textoCompleto .= "\n\n--- Archivo: {$archivo['nombre_archivo']} ---\n";
                                        $textoCompleto .= ExtractorService::obtenerResumen($textoExtraido, 2000);
                                    }
                                }
                            }
                        }
                        
                        // Generar nuevo embedding
                        $embedding_modelo_id = $_POST['embedding_modelo_id'] ?? null;
                        $embeddingResult = EmbeddingsService::generar($textoCompleto, $embedding_modelo_id);
                        
                        // Actualizar actividad con nuevo embedding
                        $stmtUpdate = $db->prepare("
                            UPDATE actividades 
                            SET embeddings = :embeddings,
                                embeddings_modelo_id = :modelo_id,
                                procesado_ia = 1
                            WHERE id = :id
                        ");
                        $stmtUpdate->bindParam(':embeddings', json_encode($embeddingResult['vector']));
                        $stmtUpdate->bindParam(':modelo_id', $embeddingResult['modelo_id']);
                        $stmtUpdate->bindParam(':id', $id);
                        $stmtUpdate->execute();
                        
                    } catch (Exception $e) {
                        error_log("Error regenerando embedding para actividad $id: " . $e->getMessage());
                    }
                }

                $db->commit();

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

            $db = Flight::db();

            // Obtener la actividad
            $actividad = self::obtenerActividadConContrato($id);
            if (!$actividad) {
                responderJSON(['error' => 'Actividad no encontrada'], 404);
                return;
            }

            // Verificar si el usuario es un contratista
            $stmtContratista = $db->prepare("
               SELECT COUNT(*) as es_contratista
               FROM usuarios_contratistas
               WHERE usuario_id = :usuario_id
           ");
            $stmtContratista->bindParam(':usuario_id', $currentUser['id']);
            $stmtContratista->execute();
            $resultContratista = $stmtContratista->fetch();

            // Si es contratista, validar acceso
            if ($resultContratista['es_contratista'] > 0) {
                $stmtAcceso = $db->prepare("
                   SELECT COUNT(*) as tiene_acceso
                   FROM usuarios_contratistas uc
                   WHERE uc.usuario_id = :usuario_id 
                   AND uc.contratista_id = :contratista_id
               ");
                $stmtAcceso->bindParam(':usuario_id', $currentUser['id']);
                $stmtAcceso->bindParam(':contratista_id', $actividad['contratista_id']);
                $stmtAcceso->execute();
                $resultadoAcceso = $stmtAcceso->fetch();

                if ($resultadoAcceso['tiene_acceso'] == 0) {
                    responderJSON(['error' => 'No tiene acceso a esta actividad'], 403);
                    return;
                }
            }

            $db->beginTransaction();

            try {
                // Los archivos y obligaciones se eliminan por CASCADE
                $stmt = $db->prepare("DELETE FROM actividades WHERE id = :id");
                $stmt->bindParam(':id', $id);
                $stmt->execute();

                $db->commit();

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

    // Buscar con IA
    public static function buscarConIA()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            $data = Flight::request()->data->getData();
            $pregunta = $data['pregunta'] ?? null;
            $contrato_id = $data['contrato_id'] ?? null;
            $modelo_id = $data['modelo_id'] ?? null;

            if (!$pregunta || !$contrato_id) {
                responderJSON(['error' => 'Pregunta y contrato son requeridos'], 400);
                return;
            }

            // Verificar acceso al contrato
            $db = Flight::db();
            
            $stmtContratista = $db->prepare("
                SELECT COUNT(*) as es_contratista
                FROM usuarios_contratistas
                WHERE usuario_id = :usuario_id
            ");
            $stmtContratista->bindParam(':usuario_id', $currentUser['id']);
            $stmtContratista->execute();
            $resultContratista = $stmtContratista->fetch();

            if ($resultContratista['es_contratista'] > 0) {
                $stmtAcceso = $db->prepare("
                    SELECT COUNT(*) as tiene_acceso
                    FROM contratos c
                    INNER JOIN usuarios_contratistas uc ON c.contratista_id = uc.contratista_id
                    WHERE c.id = :contrato_id AND uc.usuario_id = :usuario_id
                ");
                $stmtAcceso->bindParam(':contrato_id', $contrato_id);
                $stmtAcceso->bindParam(':usuario_id', $currentUser['id']);
                $stmtAcceso->execute();
                $resultadoAcceso = $stmtAcceso->fetch();

                if ($resultadoAcceso['tiene_acceso'] == 0) {
                    responderJSON(['error' => 'No tiene acceso a este contrato'], 403);
                    return;
                }
            }

            require_once __DIR__ . '/embeddings.service.php';
            
            // Realizar búsqueda semántica
            $resultados = EmbeddingsService::busquedaSemantica($pregunta, $contrato_id, $modelo_id);

            // Si hay resultados, usar GPT-4 para generar respuesta
            if ($resultados['total_resultados'] > 0) {
                require_once __DIR__ . '/../providers/ai/provider-manager.php';
                $providerManager = ProviderManager::getInstance();
                
                // Por ahora usar OpenAI directamente
                if ($providerManager->hasProvider('openai')) {
                    $openai = $providerManager->getProvider('openai');
                    
                    // Construir prompt
                    $prompt = $resultados['contexto'] . "\n\n";
                    $prompt .= "Pregunta del usuario: " . $pregunta . "\n\n";
                    $prompt .= "Basándote en el contexto proporcionado, responde la pregunta de forma clara y precisa. ";
                    $prompt .= "Si encuentras información específica, cita la fuente (actividad y/o archivo). ";
                    $prompt .= "Si no encuentras información relevante, indícalo claramente.";
                    
                    // Usar GPT-4 para responder
                    $respuestaIA = self::consultarGPT4($prompt);
                    
                    $resultados['respuesta_ia'] = $respuestaIA;
                } else {
                    $resultados['respuesta_ia'] = null;
                    $resultados['mensaje'] = 'OpenAI no está configurado. Se muestran solo los resultados de búsqueda.';
                }
            } else {
                $resultados['respuesta_ia'] = 'No encontré información relevante para tu pregunta en las actividades registradas.';
            }

            responderJSON([
                'success' => true,
                'resultados' => $resultados
            ]);

        } catch (Exception $e) {
            error_log("Error en búsqueda con IA: " . $e->getMessage());
            responderJSON(['error' => 'Error al realizar búsqueda'], 500);
        }
    }

    // Procesar actividades pendientes (para cron o manual)
    public static function procesarPendientes()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'sistema.configurar')) {
                responderJSON(['error' => 'No tiene permisos para ejecutar este proceso'], 403);
                return;
            }

            require_once __DIR__ . '/embeddings.service.php';
            
            $resultado = EmbeddingsService::procesarPendientes();

            responderJSON([
                'success' => true,
                'procesadas' => $resultado['procesadas'],
                'errores' => $resultado['errores'],
                'message' => "Se procesaron {$resultado['procesadas']} actividades con {$resultado['errores']} errores"
            ]);

        } catch (Exception $e) {
            error_log("Error procesando pendientes: " . $e->getMessage());
            responderJSON(['error' => 'Error al procesar pendientes'], 500);
        }
    }

    // MÉTODOS AUXILIARES PRIVADOS
    private static function obtenerActividadConContrato($actividadId)
    {
        $db = Flight::db();

        $sql = "SELECT 
               a.*,
               c.numero_contrato,
               c.contratista_id,
               c.entidad_id,
               e.nombre as entidad_nombre,
               ct.email as contratista_email,
               ct.nombre_completo as contratista_nombre
           FROM actividades a
           INNER JOIN contratos c ON a.contrato_id = c.id
           INNER JOIN entidades e ON c.entidad_id = e.id
           INNER JOIN contratistas ct ON c.contratista_id = ct.id
           WHERE a.id = :id";

        $stmt = $db->prepare($sql);
        $stmt->bindParam(':id', $actividadId);
        $stmt->execute();

        return $stmt->fetch();
    }

    private static function verificarPermisoLectura($usuarioId)
    {
        return AuthService::checkPermission($usuarioId, 'actividades.ver') ||
            AuthService::checkPermission($usuarioId, 'actividades.registrar');
    }

    private static function consultarGPT4($prompt)
    {
        try {
            $db = Flight::db();
            
            // Obtener configuración de OpenAI
            require_once __DIR__ . '/configuracion.service.php';
            $apiKey = ConfiguracionService::get('openai_api_key', 'ia');
            
            if (!$apiKey) {
                throw new Exception('OpenAI API key no configurada');
            }
            
            $url = 'https://api.openai.com/v1/chat/completions';
            
            $data = [
                'model' => 'gpt-4-turbo-preview',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Eres un asistente útil que ayuda a encontrar información en actividades y documentos. Siempre citas las fuentes cuando encuentras información específica.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 1000
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                error_log("Error GPT-4 API: HTTP $httpCode - Response: $response");
                throw new Exception("Error consultando GPT-4");
            }
            
            $resultado = json_decode($response, true);
            
            if (isset($resultado['choices'][0]['message']['content'])) {
                return $resultado['choices'][0]['message']['content'];
            }
            
            throw new Exception('Respuesta inválida de GPT-4');
            
        } catch (Exception $e) {
            error_log("Error consultando GPT-4: " . $e->getMessage());
            throw $e;
        }
    }
}