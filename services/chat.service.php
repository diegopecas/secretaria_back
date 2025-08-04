<?php
require_once __DIR__ . '/embeddings.service.php';
require_once __DIR__ . '/configuracion.service.php';
require_once __DIR__ . '/../providers/ai/provider-manager.php';

class ChatService
{
    /**
     * Iniciar nueva conversación con IA
     */
    public static function iniciarConversacion()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            $data = Flight::request()->data->getData();
            $contrato_id = $data['contrato_id'] ?? null;
            $pregunta = $data['pregunta'] ?? null;
            $continuar_sesion = $data['continuar_sesion'] ?? false;
            $sesion_id = $data['sesion_id'] ?? null;

            if (!$contrato_id || !$pregunta) {
                responderJSON(['error' => 'Contrato y pregunta son requeridos'], 400);
                return;
            }

            // Verificar acceso al contrato
            if (!self::verificarAccesoContrato($currentUser['id'], $contrato_id)) {
                responderJSON(['error' => 'No tiene acceso a este contrato'], 403);
                return;
            }

            $db = Flight::db();
            $db->beginTransaction();

            try {
                // Crear o recuperar sesión
                if ($continuar_sesion && $sesion_id) {
                    $sesion = self::obtenerSesion($sesion_id, $currentUser['id']);
                    if (!$sesion || $sesion['contrato_id'] != $contrato_id) {
                        $sesion_id = self::crearNuevaSesion($currentUser['id'], $contrato_id, $pregunta);
                    }
                } else {
                    $sesion_id = self::crearNuevaSesion($currentUser['id'], $contrato_id, $pregunta);
                }

                // Obtener historial de la sesión
                $historial = self::obtenerHistorial($sesion_id);

                // Realizar búsqueda semántica
                $resultadosBusqueda = EmbeddingsService::busquedaSemantica($pregunta, $contrato_id);

                // Construir contexto
                $contexto = self::construirContextoCompleto($contrato_id, $resultadosBusqueda);

                // Construir mensajes para la IA
                $messages = self::construirMensajes($historial, $contexto, $pregunta);

                // Guardar pregunta del usuario
                self::guardarMensaje($sesion_id, 'user', $pregunta);

                // Consultar a GPT-4
                $respuestaIA = self::consultarIA($messages);

                // Guardar respuesta de la IA
                self::guardarMensaje($sesion_id, 'assistant', $respuestaIA['contenido'], $respuestaIA['tokens']);

                // Actualizar estadísticas de la sesión
                self::actualizarEstadisticasSesion($sesion_id);

                $db->commit();

                responderJSON([
                    'success' => true,
                    'sesion_id' => $sesion_id,
                    'respuesta' => $respuestaIA['contenido'],
                    'fuentes' => $resultadosBusqueda['actividades'],
                    'tokens_usados' => $respuestaIA['tokens'],
                    'puede_continuar' => true
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            error_log("Error en chat: " . $e->getMessage());
            responderJSON(['error' => 'Error al procesar conversación'], 500);
        }
    }

    public static function iniciarConversacionStream()
    {
        // Headers SSE - solo cambiar el origen si es necesario
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: http://localhost:4200'); // TODO: Hacer dinámico en producción
        header('Access-Control-Allow-Credentials: true');
        header('X-Accel-Buffering: no');

        // Deshabilitar buffering
        @ob_end_clean();
        @ob_implicit_flush(true);
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', false);

        // Flush inicial
        echo ":ok\n\n";
        flush();

        try {
            error_log("=== INICIO ChatService::iniciarConversacionStream ===");

            // Verificar autenticación por token
            $token = $_GET['token'] ?? null;
            if (!$token) {
                self::enviarEventoSSE('error', ['message' => 'Token no proporcionado']);
                exit();
            }

            $user = validateToken($token);
            if (!$user) {
                self::enviarEventoSSE('error', ['message' => 'Token inválido']);
                exit();
            }

            Flight::set('currentUser', $user);
            $currentUser = $user;

            // Obtener datos
            $contrato_id = $_GET['contrato_id'] ?? null;
            $pregunta = urldecode($_GET['pregunta'] ?? '');
            $continuar_sesion = $_GET['continuar_sesion'] === 'true';
            $sesion_id = $_GET['sesion_id'] ?? null;
            $proveedor = $_GET['proveedor'] ?? null; // NUEVO

            error_log("Proveedor solicitado: " . $proveedor);

            if (!$contrato_id || !$pregunta) {
                self::enviarEventoSSE('error', ['message' => 'Contrato y pregunta son requeridos']);
                exit();
            }

            // Verificar acceso al contrato
            if (!self::verificarAccesoContrato($currentUser['id'], $contrato_id)) {
                self::enviarEventoSSE('error', ['message' => 'No tiene acceso a este contrato']);
                exit();
            }

            // Crear o recuperar sesión
            if ($continuar_sesion && $sesion_id && $sesion_id !== 'null') {
                $sesion = self::obtenerSesion($sesion_id, $currentUser['id']);
                if (!$sesion || $sesion['contrato_id'] != $contrato_id) {
                    $sesion_id = self::crearNuevaSesion($currentUser['id'], $contrato_id, $pregunta);
                }
            } else {
                $sesion_id = self::crearNuevaSesion($currentUser['id'], $contrato_id, $pregunta);
            }

            // Enviar ID de sesión
            self::enviarEventoSSE('session', ['sesion_id' => $sesion_id]);

            // Obtener historial
            $historial = self::obtenerHistorial($sesion_id);

            // Realizar búsqueda semántica
            self::enviarEventoSSE('status', ['message' => 'Buscando información relevante...']);

            require_once __DIR__ . '/embeddings.service.php';
            $resultadosBusqueda = EmbeddingsService::busquedaSemantica($pregunta, $contrato_id);

            // Construir contexto
            $contexto = self::construirContextoCompleto($contrato_id, $resultadosBusqueda);

            // Construir mensajes
            $messages = self::construirMensajes($historial, $contexto, $pregunta);

            // Guardar pregunta del usuario
            self::guardarMensaje($sesion_id, 'user', $pregunta);

            // Enviar fuentes encontradas
            if (!empty($resultadosBusqueda['actividades'])) {
                self::enviarEventoSSE('sources', [
                    'actividades' => array_slice($resultadosBusqueda['actividades'], 0, 5)
                ]);
            }

            // Generar respuesta con IA
            self::enviarEventoSSE('status', ['message' => 'Generando respuesta...']);

            // Pasar el proveedor al método que genera la respuesta
            self::generarRespuestaConStreaming($messages, $sesion_id, $contrato_id, $proveedor);

            // Actualizar estadísticas
            self::actualizarEstadisticasSesion($sesion_id);

            // Enviar evento de finalización
            self::enviarEventoSSE('done', ['success' => true]);
        } catch (Exception $e) {
            error_log("ERROR en chat streaming: " . $e->getMessage());
            self::enviarEventoSSE('error', ['message' => 'Error al procesar conversación']);
        } finally {
            exit();
        }
    }

    /**
     * Generar respuesta con streaming usando proveedor específico
     */
    private static function generarRespuestaConStreaming($messages, $sesionId, $contratoId, $proveedor = null)
    {
        // Si se especificó un proveedor, usarlo
        if ($proveedor) {
            error_log("Intentando usar proveedor específico: " . $proveedor);
            // TODO: Aquí llamar al provider manager con el proveedor específico
        }

        // Por ahora, usar el método existente
        self::consultarIAStreaming($messages, $sesionId);
    }

    /**
     * Obtener historial de conversación
     */
    public static function obtenerHistorialConversacion()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            $sesion_id = Flight::request()->query['sesion_id'] ?? null;

            if (!$sesion_id) {
                responderJSON(['error' => 'Sesión ID requerida'], 400);
                return;
            }

            $db = Flight::db();

            // Verificar que la sesión pertenece al usuario
            $sesion = self::obtenerSesion($sesion_id, $currentUser['id']);
            if (!$sesion) {
                responderJSON(['error' => 'Sesión no encontrada'], 404);
                return;
            }

            // Obtener mensajes
            $stmt = $db->prepare("
                SELECT 
                    rol,
                    contenido,
                    fecha_mensaje,
                    tokens_usados
                FROM chat_historial
                WHERE sesion_id = :sesion_id
                AND activo = 1
                ORDER BY fecha_mensaje ASC
            ");
            $stmt->bindParam(':sesion_id', $sesion_id);
            $stmt->execute();
            $mensajes = $stmt->fetchAll();

            responderJSON([
                'success' => true,
                'sesion' => $sesion,
                'mensajes' => $mensajes
            ]);
        } catch (Exception $e) {
            error_log("Error obteniendo historial: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener historial'], 500);
        }
    }

    /**
     * Listar sesiones de chat del usuario
     */
    public static function listarSesiones()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            $contrato_id = Flight::request()->query['contrato_id'] ?? null;

            $db = Flight::db();

            $sql = "SELECT 
                    s.id,
                    s.titulo,
                    s.resumen,
                    s.mensajes_count,
                    s.fecha_inicio,
                    s.fecha_ultimo_mensaje,
                    c.numero_contrato,
                    e.nombre_corto as entidad
                FROM chat_sesiones s
                INNER JOIN contratos c ON s.contrato_id = c.id
                INNER JOIN entidades e ON c.entidad_id = e.id
                WHERE s.usuario_id = :usuario_id
                AND s.activa = 1";

            $params = [':usuario_id' => $currentUser['id']];

            if ($contrato_id) {
                $sql .= " AND s.contrato_id = :contrato_id";
                $params[':contrato_id'] = $contrato_id;
            }

            $sql .= " ORDER BY s.fecha_ultimo_mensaje DESC LIMIT 20";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $sesiones = $stmt->fetchAll();

            responderJSON([
                'success' => true,
                'sesiones' => $sesiones
            ]);
        } catch (Exception $e) {
            error_log("Error listando sesiones: " . $e->getMessage());
            responderJSON(['error' => 'Error al listar sesiones'], 500);
        }
    }

    /**
     * Generar resumen de contrato (para contexto base)
     */
    public static function generarResumenContrato()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos'], 403);
                return;
            }

            $contrato_id = Flight::request()->data['contrato_id'] ?? null;

            if (!$contrato_id) {
                responderJSON(['error' => 'Contrato ID requerido'], 400);
                return;
            }

            $db = Flight::db();

            // Obtener información del contrato
            $stmt = $db->prepare("
                SELECT 
                    c.*,
                    e.nombre as entidad_nombre,
                    cont.nombre_completo as contratista_nombre
                FROM contratos c
                INNER JOIN entidades e ON c.entidad_id = e.id
                INNER JOIN contratistas cont ON c.contratista_id = cont.id
                WHERE c.id = :contrato_id
            ");
            $stmt->bindParam(':contrato_id', $contrato_id);
            $stmt->execute();
            $contrato = $stmt->fetch();

            if (!$contrato) {
                responderJSON(['error' => 'Contrato no encontrado'], 404);
                return;
            }

            // Obtener obligaciones
            $stmt = $db->prepare("
                SELECT numero_obligacion, descripcion
                FROM obligaciones_contractuales
                WHERE contrato_id = :contrato_id
                ORDER BY numero_obligacion
            ");
            $stmt->bindParam(':contrato_id', $contrato_id);
            $stmt->execute();
            $obligaciones = $stmt->fetchAll();

            // Obtener resumen de actividades
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_actividades,
                    MIN(fecha_actividad) as primera_actividad,
                    MAX(fecha_actividad) as ultima_actividad
                FROM actividades
                WHERE contrato_id = :contrato_id
            ");
            $stmt->bindParam(':contrato_id', $contrato_id);
            $stmt->execute();
            $estadisticas = $stmt->fetch();

            // Generar resumen con IA
            $prompt = self::construirPromptResumen($contrato, $obligaciones, $estadisticas);

            $messages = [
                [
                    'role' => 'system',
                    'content' => 'Eres un experto en análisis de contratos. Genera resúmenes ejecutivos concisos y útiles.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ];

            $respuesta = self::consultarIA($messages);

            // Guardar resumen
            $stmt = $db->prepare("
                UPDATE contratos 
                SET resumen_ia = :resumen,
                    fecha_resumen_ia = NOW()
                WHERE id = :contrato_id
            ");
            $stmt->bindParam(':resumen', $respuesta['contenido']);
            $stmt->bindParam(':contrato_id', $contrato_id);
            $stmt->execute();

            responderJSON([
                'success' => true,
                'resumen' => $respuesta['contenido'],
                'tokens_usados' => $respuesta['tokens']
            ]);
        } catch (Exception $e) {
            error_log("Error generando resumen: " . $e->getMessage());
            responderJSON(['error' => 'Error al generar resumen'], 500);
        }
    }

    // MÉTODOS PRIVADOS AUXILIARES

    /**
     * Verificar acceso al contrato
     */
    private static function verificarAccesoContrato($usuarioId, $contratoId)
    {
        $db = Flight::db();

        // Verificar si es contratista
        $stmt = $db->prepare("
            SELECT COUNT(*) as es_contratista
            FROM usuarios_contratistas
            WHERE usuario_id = :usuario_id
        ");
        $stmt->bindParam(':usuario_id', $usuarioId);
        $stmt->execute();
        $result = $stmt->fetch();

        if ($result['es_contratista'] > 0) {
            // Si es contratista, verificar acceso específico
            $stmt = $db->prepare("
                SELECT COUNT(*) as tiene_acceso
                FROM contratos c
                INNER JOIN usuarios_contratistas uc ON c.contratista_id = uc.contratista_id
                WHERE c.id = :contrato_id AND uc.usuario_id = :usuario_id
            ");
            $stmt->bindParam(':contrato_id', $contratoId);
            $stmt->bindParam(':usuario_id', $usuarioId);
            $stmt->execute();
            $acceso = $stmt->fetch();

            return $acceso['tiene_acceso'] > 0;
        }

        // Si no es contratista, tiene acceso a todos
        return true;
    }

    /**
     * Crear nueva sesión de chat
     */
    private static function crearNuevaSesion($usuarioId, $contratoId, $primeraPregunta)
    {
        $db = Flight::db();

        $sesionId = uniqid('chat_', true);
        $titulo = substr($primeraPregunta, 0, 100) . '...';

        $stmt = $db->prepare("
            INSERT INTO chat_sesiones (
                id,
                usuario_id,
                contrato_id,
                titulo,
                mensajes_count,
                fecha_inicio,
                fecha_ultimo_mensaje
            ) VALUES (
                :id,
                :usuario_id,
                :contrato_id,
                :titulo,
                0,
                NOW(),
                NOW()
            )
        ");

        $stmt->bindParam(':id', $sesionId);
        $stmt->bindParam(':usuario_id', $usuarioId);
        $stmt->bindParam(':contrato_id', $contratoId);
        $stmt->bindParam(':titulo', $titulo);
        $stmt->execute();

        return $sesionId;
    }

    /**
     * Obtener sesión
     */
    private static function obtenerSesion($sesionId, $usuarioId)
    {
        $db = Flight::db();

        $stmt = $db->prepare("
            SELECT * FROM chat_sesiones
            WHERE id = :sesion_id
            AND usuario_id = :usuario_id
            AND activa = 1
        ");
        $stmt->bindParam(':sesion_id', $sesionId);
        $stmt->bindParam(':usuario_id', $usuarioId);
        $stmt->execute();

        return $stmt->fetch();
    }

    /**
     * Obtener historial de mensajes
     */
    private static function obtenerHistorial($sesionId, $limite = 10)
    {
        $db = Flight::db();

        // Obtener últimos N mensajes para mantener contexto manejable
        $stmt = $db->prepare("
            SELECT rol, contenido
            FROM chat_historial
            WHERE sesion_id = :sesion_id
            AND activo = 1
            AND rol IN ('user', 'assistant')
            ORDER BY fecha_mensaje DESC
            LIMIT :limite
        ");
        $stmt->bindParam(':sesion_id', $sesionId);
        $stmt->bindParam(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        $mensajes = $stmt->fetchAll();

        // Invertir para orden cronológico
        return array_reverse($mensajes);
    }

    /**
     * Construir contexto completo
     */
    private static function construirContextoCompleto($contratoId, $resultadosBusqueda)
    {
        $db = Flight::db();

        // Obtener resumen del contrato si existe
        $stmt = $db->prepare("
            SELECT resumen_ia 
            FROM contratos 
            WHERE id = :contrato_id 
            AND resumen_ia IS NOT NULL
        ");
        $stmt->bindParam(':contrato_id', $contratoId);
        $stmt->execute();
        $contrato = $stmt->fetch();

        $contexto = "";

        if ($contrato && $contrato['resumen_ia']) {
            $contexto .= "=== RESUMEN DEL CONTRATO ===\n";
            $contexto .= $contrato['resumen_ia'] . "\n\n";
        }

        $contexto .= "=== INFORMACIÓN RELEVANTE ENCONTRADA ===\n";
        $contexto .= $resultadosBusqueda['contexto'];

        return $contexto;
    }

    /**
     * Construir array de mensajes para la IA
     */
    private static function construirMensajes($historial, $contexto, $preguntaActual)
    {
        $systemPrompt = 'Eres un asistente especializado en analizar EXCLUSIVAMENTE las actividades, documentos y obligaciones del contrato específico que se te proporciona.

                        INSTRUCCIONES CRÍTICAS:
                        1. SOLO debes responder basándote en la información del contrato y sus actividades proporcionadas en el contexto.
                        2. Si la pregunta NO está relacionada con el contrato, actividades, documentos u obligaciones, debes responder: "Solo puedo ayudarte con información relacionada con el contrato, sus actividades, documentos y obligaciones. ¿Hay algo específico del contrato sobre lo que necesites información?"
                        3. SIEMPRE cita las fuentes exactas (fecha de actividad, nombre de archivo) cuando encuentres información.
                        4. Si no encuentras información específica en el contexto proporcionado, indica claramente que no se encontró esa información en las actividades del contrato.
                        5. NO proporciones información general o conocimientos externos que no estén en el contexto del contrato.
                        6. Mantén las respuestas concisas, profesionales y enfocadas en el contrato.

                        VALIDACIÓN DE RELEVANCIA:
                        Antes de responder, evalúa si la pregunta está relacionada con:
                        - El contrato específico y sus detalles
                        - Las actividades registradas
                        - Los documentos adjuntos
                        - Las obligaciones contractuales
                        - El contratista o la entidad mencionados
                        - Fechas, reuniones o eventos del contrato

                        Si la pregunta NO está relacionada con estos temas, aplica la respuesta estándar del punto 2.';

        $messages = [
            [
                'role' => 'system',
                'content' => $systemPrompt
            ]
        ];

        // Si no hay historial, agregar el contexto como primer mensaje
        if (empty($historial)) {
            $messages[] = [
                'role' => 'system',
                'content' => "Contexto del contrato y actividades:\n\n" . $contexto
            ];
        } else {
            // Agregar historial previo
            foreach ($historial as $msg) {
                $messages[] = [
                    'role' => $msg['rol'],
                    'content' => $msg['contenido']
                ];
            }

            // Agregar contexto actualizado
            $messages[] = [
                'role' => 'system',
                'content' => "Información adicional relevante para esta pregunta:\n\n" . $contexto
            ];
        }

        // Agregar pregunta actual
        $messages[] = [
            'role' => 'user',
            'content' => $preguntaActual
        ];

        return $messages;
    }

    /**
     * Consultar a la IA
     */
    private static function consultarIA($messages)
    {
        $apiKey = ConfiguracionService::get('openai_api_key', 'ia');

        if (!$apiKey) {
            throw new Exception('OpenAI API key no configurada');
        }

        $url = 'https://api.openai.com/v1/chat/completions';

        $data = [
            'model' => 'gpt-4-turbo-preview',
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 1500
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Error GPT-4 API: HTTP $httpCode - Response: $response");
            throw new Exception("Error consultando IA");
        }

        $resultado = json_decode($response, true);

        if (!isset($resultado['choices'][0]['message']['content'])) {
            throw new Exception('Respuesta inválida de GPT-4');
        }

        return [
            'contenido' => $resultado['choices'][0]['message']['content'],
            'tokens' => $resultado['usage']['total_tokens'] ?? 0,
            'modelo' => $resultado['model'] ?? 'gpt-4-turbo-preview'
        ];
    }

    /**
     * Guardar mensaje en el historial
     */
    private static function guardarMensaje($sesionId, $rol, $contenido, $tokens = 0)
    {
        $db = Flight::db();
        $usuarioId = Flight::get('currentUser')['id'];

        // Obtener contrato_id de la sesión
        $stmt = $db->prepare("SELECT contrato_id FROM chat_sesiones WHERE id = :sesion_id");
        $stmt->bindParam(':sesion_id', $sesionId);
        $stmt->execute();
        $sesion = $stmt->fetch();

        $stmt = $db->prepare("
            INSERT INTO chat_historial (
                usuario_id,
                contrato_id,
                sesion_id,
                rol,
                contenido,
                tokens_usados,
                modelo_usado,
                fecha_mensaje
            ) VALUES (
                :usuario_id,
                :contrato_id,
                :sesion_id,
                :rol,
                :contenido,
                :tokens,
                'gpt-4-turbo-preview',
                NOW()
            )
        ");

        $stmt->bindParam(':usuario_id', $usuarioId);
        $stmt->bindParam(':contrato_id', $sesion['contrato_id']);
        $stmt->bindParam(':sesion_id', $sesionId);
        $stmt->bindParam(':rol', $rol);
        $stmt->bindParam(':contenido', $contenido);
        $stmt->bindParam(':tokens', $tokens);
        $stmt->execute();
    }

    /**
     * Actualizar estadísticas de la sesión
     */
    private static function actualizarEstadisticasSesion($sesionId)
    {
        $db = Flight::db();

        $stmt = $db->prepare("
            UPDATE chat_sesiones 
            SET mensajes_count = (
                    SELECT COUNT(*) 
                    FROM chat_historial 
                    WHERE sesion_id = :sesion_id1
                ),
                tokens_totales = (
                    SELECT SUM(tokens_usados) 
                    FROM chat_historial 
                    WHERE sesion_id = :sesion_id2
                ),
                fecha_ultimo_mensaje = NOW()
            WHERE id = :sesion_id3
        ");

        $stmt->bindParam(':sesion_id1', $sesionId);
        $stmt->bindParam(':sesion_id2', $sesionId);
        $stmt->bindParam(':sesion_id3', $sesionId);
        $stmt->execute();
    }

    /**
     * Construir prompt para resumen de contrato
     */
    private static function construirPromptResumen($contrato, $obligaciones, $estadisticas)
    {
        $prompt = "Genera un resumen ejecutivo del siguiente contrato:\n\n";

        $prompt .= "INFORMACIÓN DEL CONTRATO:\n";
        $prompt .= "- Número: {$contrato['numero_contrato']}\n";
        $prompt .= "- Entidad: {$contrato['entidad_nombre']}\n";
        $prompt .= "- Contratista: {$contrato['contratista_nombre']}\n";
        $prompt .= "- Objeto: {$contrato['objeto_contrato']}\n";
        $prompt .= "- Valor Total: $" . number_format($contrato['valor_total'], 0, ',', '.') . "\n";
        $prompt .= "- Fecha Inicio: {$contrato['fecha_inicio']}\n";
        $prompt .= "- Fecha Fin: {$contrato['fecha_terminacion']}\n\n";

        $prompt .= "OBLIGACIONES CONTRACTUALES:\n";
        foreach ($obligaciones as $obl) {
            $prompt .= "- {$obl['numero_obligacion']}. {$obl['descripcion']}\n";
        }

        $prompt .= "\nESTADÍSTICAS:\n";
        $prompt .= "- Total de actividades registradas: {$estadisticas['total_actividades']}\n";
        $prompt .= "- Período de actividades: {$estadisticas['primera_actividad']} a {$estadisticas['ultima_actividad']}\n";

        $prompt .= "\nGenera un resumen ejecutivo que incluya:\n";
        $prompt .= "1. Propósito principal del contrato\n";
        $prompt .= "2. Obligaciones clave (resumidas)\n";
        $prompt .= "3. Estado actual de ejecución\n";
        $prompt .= "4. Puntos importantes a tener en cuenta\n";

        return $prompt;
    }

    /**
     * Consultar IA con streaming
     */
    private static function consultarIAStreaming($messages, $sesionId)
    {
        $apiKey = ConfiguracionService::get('openai_api_key', 'ia');

        if (!$apiKey) {
            throw new Exception('OpenAI API key no configurada');
        }

        $url = 'https://api.openai.com/v1/chat/completions';

        $data = [
            'model' => 'gpt-4-turbo-preview',
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 1500,
            'stream' => true
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($sesionId) {
            static $buffer = '';
            static $contenidoCompleto = '';
            static $tokensUsados = 0;

            $buffer .= $data;

            // Procesar líneas completas
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                // Ignorar líneas vacías
                if (trim($line) === '')
                    continue;

                // Verificar si es un mensaje de datos
                if (strpos($line, 'data: ') === 0) {
                    $jsonData = substr($line, 6);

                    // Verificar si es el mensaje final
                    if ($jsonData === '[DONE]') {
                        // Guardar mensaje completo en la BD
                        if (!empty($contenidoCompleto)) {
                            self::guardarMensaje($sesionId, 'assistant', $contenidoCompleto, $tokensUsados);
                        }
                        continue;
                    }

                    // Decodificar JSON
                    $parsed = json_decode($jsonData, true);
                    if ($parsed && isset($parsed['choices'][0]['delta']['content'])) {
                        $chunk = $parsed['choices'][0]['delta']['content'];
                        $contenidoCompleto .= $chunk;

                        // Enviar chunk al cliente
                        self::enviarEventoSSE('message', ['content' => $chunk]);
                    }

                    // Actualizar tokens si están disponibles
                    if (isset($parsed['usage']['total_tokens'])) {
                        $tokensUsados = $parsed['usage']['total_tokens'];
                    }
                }
            }

            return strlen($data);
        });

        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        // Configuración SSL mejorada - AGREGAR DESPUÉS DE TIMEOUT
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Para desarrollo
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Para desarrollo
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SecretariaApp/1.0 PHP-cURL');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Error GPT-4 API Streaming: HTTP $httpCode");
            self::enviarEventoSSE('error', ['message' => 'Error consultando IA']);
        }
    }

    /**
     * Enviar evento SSE
     */
    private static function enviarEventoSSE($event, $data)
    {
        $jsonData = json_encode($data);
        echo "event: $event\n";
        echo "data: $jsonData\n\n";

        // Log para depuración
        error_log("SSE enviado - evento: $event, datos: " . substr($jsonData, 0, 100) . "...");

        // Forzar envío inmediato
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
