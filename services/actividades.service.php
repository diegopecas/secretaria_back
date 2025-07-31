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
                    error_log("=== INICIANDO GENERACIÓN DE EMBEDDINGS ===");
                    error_log("Actividad ID: " . $actividadId);

                    require_once __DIR__ . '/embeddings.service.php';
                    require_once __DIR__ . '/extractor.service.php';

                    // Construir texto completo para embeddings 
                    $textoCompleto = "Fecha: {$fecha_actividad}\n";
                    $textoCompleto = $descripcion_actividad;
                    error_log("Texto inicial: " . substr($textoCompleto, 0, 100) . "...");

                    // Agregar obligaciones seleccionadas al texto
                    if (!empty($obligaciones)) {
                        $textoCompleto .= "\n\n--- OBLIGACIONES ASOCIADAS ---\n";

                        // Obtener descripción de las obligaciones
                        $placeholders = array_fill(0, count($obligaciones), '?');
                        $sqlOblig = "SELECT numero_obligacion, descripcion 
                    FROM obligaciones_contractuales 
                    WHERE id IN (" . implode(',', $placeholders) . ")";
                        $stmtOblig = $db->prepare($sqlOblig);
                        $stmtOblig->execute($obligaciones);

                        while ($oblig = $stmtOblig->fetch()) {
                            $textoCompleto .= "Obligación {$oblig['numero_obligacion']}: {$oblig['descripcion']}\n";
                        }
                    }

                    error_log("Texto completo longitud: " . strlen($textoCompleto));

                    // CAMBIAR ESTA LÍNEA:
                    // $embeddingResult = EmbeddingsService::generar($textoCompleto, $embedding_modelo_id);

                    // POR ESTA:
                    $embeddingResult = EmbeddingsService::generar($textoCompleto, $contrato_id);

                    error_log("Embedding generado exitosamente:");
                    error_log("- Modelo ID: " . $embeddingResult['modelo_id']);
                    error_log("- Dimensiones: " . $embeddingResult['dimensiones']);

                    // Actualizar actividad con embedding
                    $embeddingJson = json_encode($embeddingResult['vector']);

                    // CAMBIAR ESTA CONSULTA - YA NO NECESITAMOS embeddings_modelo_id:
                    $stmtUpdate = $db->prepare("
                        UPDATE actividades 
                        SET embeddings = :embeddings,
                            procesado_ia = 1
                        WHERE id = :id
                    ");
                    $stmtUpdate->bindParam(':embeddings', $embeddingJson);
                    $stmtUpdate->bindParam(':id', $actividadId);
                    $stmtUpdate->execute();

                    error_log("=== FIN GENERACIÓN DE EMBEDDINGS ===");
                } catch (Exception $e) {
                    error_log("ERROR generando embedding: " . $e->getMessage());
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
                $obligacionesActualizadas = false;
                if (isset($_POST['obligaciones'])) {
                    require_once __DIR__ . '/actividades-obligaciones.service.php';
                    $obligaciones = json_decode($_POST['obligaciones'], true);
                    ActividadesObligacionesService::asignar($id, $obligaciones, $db);
                    $obligacionesActualizadas = true;
                }

                // Procesar nuevos archivos si se enviaron
                if (!empty($_FILES)) {
                    require_once __DIR__ . '/actividades-archivos.service.php';
                    ActividadesArchivosService::agregar($id, $_FILES, $currentUser['id'], $db);
                }

                // Regenerar embeddings si es necesario
                if ($necesitaRegenerarEmbedding || $obligacionesActualizadas || !$actividad['procesado_ia']) {
                    try {
                        require_once __DIR__ . '/embeddings.service.php';

                        // Obtener descripción actualizada
                        $descripcionActual = $_POST['descripcion_actividad'] ?? $descripcionAnterior;
                        $fechaActual = $_POST['fecha_actividad'] ?? $actividad['fecha_actividad'];
                        $textoCompleto = "Fecha: {$fechaActual}\n";
                        $textoCompleto .= $descripcionActual;

                        // Obtener obligaciones actuales de la actividad
                        $stmtObligActuales = $db->prepare("
            SELECT oc.numero_obligacion, oc.descripcion
            FROM actividades_obligaciones ao
            INNER JOIN obligaciones_contractuales oc ON ao.obligacion_id = oc.id
            WHERE ao.actividad_id = :actividad_id
        ");
                        $stmtObligActuales->bindParam(':actividad_id', $id);
                        $stmtObligActuales->execute();

                        if ($stmtObligActuales->rowCount() > 0) {
                            $textoCompleto .= "\n\n--- OBLIGACIONES ASOCIADAS ---\n";
                            while ($oblig = $stmtObligActuales->fetch()) {
                                $textoCompleto .= "Obligación {$oblig['numero_obligacion']}: {$oblig['descripcion']}\n";
                            }
                        }

                        // CAMBIAR ESTA LÍNEA:
                        // $embedding_modelo_id = $_POST['embedding_modelo_id'] ?? null;
                        // $embeddingResult = EmbeddingsService::generar($textoCompleto, $embedding_modelo_id);

                        // POR ESTAS:
                        // Obtener el contrato_id de la actividad
                        $stmtContrato = $db->prepare("SELECT contrato_id FROM actividades WHERE id = :id");
                        $stmtContrato->bindParam(':id', $id);
                        $stmtContrato->execute();
                        $actividadData = $stmtContrato->fetch();

                        $embeddingResult = EmbeddingsService::generar($textoCompleto, $actividadData['contrato_id']);

                        // CAMBIAR ESTA CONSULTA - YA NO NECESITAMOS embeddings_modelo_id:
                        $stmtUpdate = $db->prepare("
            UPDATE actividades 
            SET embeddings = :embeddings,
                procesado_ia = 1
            WHERE id = :id
        ");
                        $stmtUpdate->bindParam(':embeddings', json_encode($embeddingResult['vector']));
                        $stmtUpdate->bindParam(':id', $id);
                        $stmtUpdate->execute();
                    } catch (Exception $e) {
                        error_log("Error regenerando embedding: " . $e->getMessage());
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

            // DEPRECADO: Usar ChatService::iniciarConversacion() en su lugar
            // Este método se mantiene por compatibilidad

            require_once __DIR__ . '/chat.service.php';

            // Redirigir a la nueva implementación
            ChatService::iniciarConversacion();
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
