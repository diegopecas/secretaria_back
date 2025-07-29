<?php
// providers/ai/openai.provider.php
require_once __DIR__ . '/ai-provider.interface.php';

class OpenAIProvider implements AIProviderInterface
{
    private $apiKey;
    private $baseUrl = 'https://api.openai.com/v1';
    private $tokensUsados = 0;
    private $costoTotal = 0;

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Transcribir audio usando Whisper
     */
    public function transcribirAudio($audioPath, $configuracion = []): array
    {
        $url = $this->baseUrl . '/audio/transcriptions';

        // Log inicial para debugging
        error_log("=== INICIO TRANSCRIPCIÓN OPENAI ===");
        error_log("- Archivo: " . $audioPath);
        error_log("- Tamaño: " . (file_exists($audioPath) ? filesize($audioPath) . " bytes" : "ARCHIVO NO EXISTE"));
        error_log("- API Key presente: " . (!empty($this->apiKey) ? "SÍ" : "NO"));

        // Verificar que el archivo existe
        if (!file_exists($audioPath)) {
            throw new Exception("El archivo de audio no existe: $audioPath");
        }

        // Verificar que tenemos API Key
        if (empty($this->apiKey)) {
            throw new Exception("API Key de OpenAI no configurada");
        }

        // Detectar el tipo MIME del archivo de forma segura
        $mimeType = $this->detectarMimeTypeSeguro($audioPath);
        error_log("- MIME Type detectado: " . $mimeType);

        // Determinar la extensión basada en el MIME type
        $extension = $this->obtenerExtensionPorMime($mimeType);
        error_log("- Extensión determinada: " . $extension);

        // Crear el archivo con el nombre correcto para CURL
        try {
            $audioFile = new CURLFile($audioPath, $mimeType, 'audio.' . $extension);
            error_log("- CURLFile creado exitosamente");
        } catch (Exception $e) {
            error_log("- ERROR creando CURLFile: " . $e->getMessage());
            throw new Exception("Error preparando archivo para upload: " . $e->getMessage());
        }

        // Configuración por defecto
        $idioma = $configuracion['idioma'] ?? 'es';
        $temperatura = $configuracion['temperatura'] ?? 0;

        // Preparar datos del formulario
        $postData = [
            'file' => $audioFile,
            'model' => 'whisper-1',
            'language' => $idioma,
            'temperature' => $temperatura,
            'response_format' => 'verbose_json' // Incluye timestamps y más info
        ];

        error_log("- Configuración: idioma=$idioma, temperatura=$temperatura");

        // Realizar petición con configuración SSL mejorada
        $ch = curl_init($url);

        // Configuración básica
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutos de timeout

        // Configurar SSL desde base de datos
        $sslConfig = $this->configurarSSLDesdeBD($ch);
        $sslFallbackUsado = false;

        error_log("- Enviando petición a OpenAI...");

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlInfo = curl_getinfo($ch);

        // Log detalles de la petición
        error_log("- Código HTTP: " . $httpCode);
        error_log("- Error cURL: " . ($curlError ?: "ninguno"));
        error_log("- Tiempo total: " . round($curlInfo['total_time'], 2) . "s");

        // Fallback SSL solo si está habilitado en configuración
        if ($httpCode === 0 && !empty($curlError) && $sslConfig['ssl_fallback_enabled']) {
            error_log("- Reintentando sin verificación SSL (fallback habilitado en BD)...");

            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            $sslFallbackUsado = true;
            error_log("- Fallback SSL - HTTP: $httpCode, Error: " . ($curlError ?: "ninguno"));
        } elseif ($httpCode === 0 && !empty($curlError) && !$sslConfig['ssl_fallback_enabled']) {
            error_log("- ERROR SSL: Fallback deshabilitado en configuración");
        }

        curl_close($ch);

        // Verificar si hubo error de conexión
        if ($httpCode === 0) {
            error_log("=== ERROR CONEXIÓN ===");
            error_log("- cURL Error: " . $curlError);
            error_log("- Posibles causas: SSL, firewall, conectividad");
            throw new Exception("No se pudo conectar con OpenAI API. Error: " . $curlError);
        }

        // Verificar código HTTP
        if ($httpCode !== 200) {
            error_log("=== ERROR HTTP $httpCode ===");
            error_log("- Respuesta: " . substr($response, 0, 500));

            $errorData = json_decode($response, true);
            $errorMessage = 'Error desconocido';

            if ($errorData && isset($errorData['error']['message'])) {
                $errorMessage = $errorData['error']['message'];
            } elseif ($httpCode === 401) {
                $errorMessage = 'API Key inválida o sin permisos';
            } elseif ($httpCode === 413) {
                $errorMessage = 'Archivo demasiado grande (máximo 25MB)';
            } elseif ($httpCode === 415) {
                $errorMessage = 'Formato de archivo no soportado';
            }

            throw new Exception("Error en Whisper: $errorMessage");
        }

        // Procesar respuesta exitosa
        $resultado = json_decode($response, true);

        if (!$resultado) {
            error_log("=== ERROR JSON ===");
            error_log("- Respuesta no es JSON válido");
            error_log("- Contenido: " . $response);
            throw new Exception('Respuesta inválida de Whisper');
        }

        if (!isset($resultado['text'])) {
            error_log("=== ERROR ESTRUCTURA ===");
            error_log("- Respuesta no contiene 'text'");
            error_log("- Estructura: " . json_encode(array_keys($resultado)));
            throw new Exception('Respuesta inválida de Whisper');
        }

        // Calcular duración y costo
        $duracionSegundos = $resultado['duration'] ?? 0;
        $duracionMinutos = $duracionSegundos / 60;
        $costo = $duracionMinutos * 0.006; // $0.006 por minuto

        $this->costoTotal += $costo;

        // Log éxito
        error_log("=== TRANSCRIPCIÓN EXITOSA ===");
        error_log("- Texto transcrito: " . strlen($resultado['text']) . " caracteres");
        error_log("- Duración: " . round($duracionSegundos, 2) . " segundos");
        error_log("- Costo: $" . round($costo, 4));
        error_log("- SSL Fallback usado: " . ($sslFallbackUsado ? "SÍ" : "NO"));

        return [
            'texto' => $resultado['text'],
            'duracion_segundos' => $duracionSegundos,
            'idioma_detectado' => $resultado['language'] ?? $idioma,
            'segmentos' => $resultado['segments'] ?? [],
            'tokens_usados' => 0, // Whisper no reporta tokens
            'costo_usd' => $costo,
            'confianza' => 0.95 // Whisper no reporta confianza, usamos valor alto por defecto
        ];
    }

    /**
     * Detectar MIME type de forma segura
     */
    private function detectarMimeTypeSeguro($archivo): string
    {
        // Intentar con finfo primero
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $archivo);
                finfo_close($finfo);
                if ($mime !== false) {
                    return $mime;
                }
            }
        }

        // Fallback con mime_content_type
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($archivo);
            if ($mime !== false) {
                return $mime;
            }
        }

        // Último recurso: por extensión
        $extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
        $mimeMap = [
            'webm' => 'audio/webm',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'mp4' => 'audio/mp4'
        ];

        return $mimeMap[$extension] ?? 'audio/webm';
    }

    /**
     * Obtener extensión por MIME type
     */
    private function obtenerExtensionPorMime($mimeType): string
    {
        $mimeToExt = [
            'audio/webm' => 'webm',
            'video/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
            'audio/wav' => 'wav',
            'audio/wave' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/mp4' => 'mp4',
            'audio/m4a' => 'm4a'
        ];

        return $mimeToExt[$mimeType] ?? 'webm';
    }

    /**
     * Generar embeddings para búsqueda semántica
     */
    public function generarEmbeddings(string $texto): array
    {
        error_log("OpenAIProvider::generarEmbeddings() - INICIO");
        error_log("- API Key presente: " . (!empty($this->apiKey) ? 'SÍ' : 'NO'));
        error_log("- Texto longitud: " . strlen($texto));

        $url = $this->baseUrl . '/embeddings';

        $data = [
            'model' => 'text-embedding-3-small',
            'input' => $texto
        ];

        error_log("Enviando request a OpenAI...");
        error_log("- URL: " . $url);
        error_log("- Modelo: " . $data['model']);

        try {
            $response = $this->makeRequest($url, $data);

            if (isset($response['data'][0]['embedding'])) {
                $embedding = $response['data'][0]['embedding'];
                error_log("Embedding recibido exitosamente");
                error_log("- Dimensiones: " . count($embedding));
                error_log("- Tokens usados: " . ($response['usage']['total_tokens'] ?? 'N/A'));

                // Actualizar uso
                $this->tokensUsados += $response['usage']['total_tokens'] ?? 0;

                return $embedding;
            } else {
                error_log("ERROR: Respuesta no contiene embedding");
                error_log("Respuesta: " . json_encode($response));
            }

        } catch (Exception $e) {
            error_log("ERROR en generarEmbeddings: " . $e->getMessage());
            throw $e;
        }

        throw new Exception('Error generando embeddings con OpenAI');
    }
    /**
     * Analizar actividades y asociar con obligaciones
     */
    public function analizarActividades(array $actividades, array $obligaciones): array
    {
        $url = $this->baseUrl . '/chat/completions';

        $prompt = $this->construirPromptAnalisis($actividades, $obligaciones);

        $data = [
            'model' => 'gpt-4-turbo-preview',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Eres un asistente experto en análisis de actividades contractuales en Colombia. Siempre respondes en JSON válido.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.3,
            'response_format' => ['type' => 'json_object']
        ];

        $response = $this->makeRequest($url, $data);

        if (isset($response['choices'][0]['message']['content'])) {
            $resultado = $response['choices'][0]['message']['content'];

            // Actualizar uso
            $this->tokensUsados += $response['usage']['total_tokens'] ?? 0;

            $analisis = json_decode($resultado, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $analisis;
            }
        }

        throw new Exception('Error analizando actividades con OpenAI');
    }

    /**
     * Buscar actividades por pregunta en lenguaje natural
     */
    public function buscarPorPregunta(string $pregunta, array $embeddings): array
    {
        // Generar embedding de la pregunta
        $embeddingPregunta = $this->generarEmbeddings($pregunta);

        // Calcular similitud con cada embedding almacenado
        $resultados = [];

        foreach ($embeddings as $actividadId => $embeddingData) {
            $embeddingVector = json_decode($embeddingData['embedding_vector'], true);
            if ($embeddingVector) {
                $similitud = $this->calcularSimilitudCoseno($embeddingPregunta, $embeddingVector);
                $resultados[] = [
                    'actividad_id' => $actividadId,
                    'similitud' => $similitud
                ];
            }
        }

        // Ordenar por similitud descendente
        usort($resultados, function ($a, $b) {
            return $b['similitud'] <=> $a['similitud'];
        });

        return array_map(function ($r) {
            return $r['actividad_id'];
        }, $resultados);
    }

    /**
     * Generar cuenta de cobro basada en actividades
     */
    public function generarCuentaCobro(array $datosContrato, array $actividadesAnalizadas, array $cuentasAnteriores = []): array
    {
        $url = $this->baseUrl . '/chat/completions';

        $prompt = $this->construirPromptCuentaCobro($datosContrato, $actividadesAnalizadas, $cuentasAnteriores);

        $data = [
            'model' => 'gpt-4-turbo-preview',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Eres un experto en generación de cuentas de cobro para contratistas en Colombia. Generas documentos profesionales en formato JSON.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object']
        ];

        $response = $this->makeRequest($url, $data);

        if (isset($response['choices'][0]['message']['content'])) {
            $resultado = $response['choices'][0]['message']['content'];

            // Actualizar uso
            $this->tokensUsados += $response['usage']['total_tokens'] ?? 0;

            $cuentaCobro = json_decode($resultado, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $cuentaCobro;
            }
        }

        throw new Exception('Error generando cuenta de cobro con OpenAI');
    }

    /**
     * Obtener el nombre del proveedor
     */
    public function getNombre(): string
    {
        return 'openai';
    }

    /**
     * Obtener información de uso
     */
    public function getUsoInfo(): array
    {
        return [
            'tokens_usados' => $this->tokensUsados,
            'costo_estimado_usd' => $this->costoTotal,
            'proveedor' => 'OpenAI'
        ];
    }

    // MÉTODOS AUXILIARES PRIVADOS

    /**
     * Realizar petición HTTP genérica a OpenAI
     */
    private function makeRequest($url, $data)
    {
        // Log inicial para debugging
        error_log("=== INICIO makeRequest OpenAI ===");
        error_log("- URL: " . $url);
        error_log("- API Key presente: " . (!empty($this->apiKey) ? "SÍ" : "NO"));
        error_log("- Datos: " . json_encode($data, JSON_UNESCAPED_UNICODE));

        // Verificar que tenemos API Key
        if (empty($this->apiKey)) {
            throw new Exception("API Key de OpenAI no configurada");
        }

        $ch = curl_init($url);

        // Configuración básica
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        // Configurar SSL desde base de datos
        $sslConfig = $this->configurarSSLDesdeBD($ch);
        $sslFallbackUsado = false;

        error_log("- Enviando petición a OpenAI...");

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlInfo = curl_getinfo($ch);

        // Log detalles de la petición
        error_log("- Código HTTP: " . $httpCode);
        error_log("- Error cURL: " . ($curlError ?: "ninguno"));
        error_log("- Tiempo total: " . round($curlInfo['total_time'], 2) . "s");

        // Fallback SSL solo si está habilitado en configuración
        if ($httpCode === 0 && !empty($curlError) && $sslConfig['ssl_fallback_enabled']) {
            error_log("- Reintentando sin verificación SSL (fallback habilitado en BD)...");

            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            $sslFallbackUsado = true;
            error_log("- Fallback SSL - HTTP: $httpCode, Error: " . ($curlError ?: "ninguno"));
        } elseif ($httpCode === 0 && !empty($curlError) && !$sslConfig['ssl_fallback_enabled']) {
            error_log("- ERROR SSL: Fallback deshabilitado en configuración");
        }

        curl_close($ch);

        // Verificar si hubo error de conexión
        if ($httpCode === 0) {
            error_log("=== ERROR CONEXIÓN makeRequest ===");
            error_log("- cURL Error: " . $curlError);
            error_log("- URL: " . $url);
            error_log("- Posibles causas: SSL, firewall, conectividad");
            throw new Exception("No se pudo conectar con OpenAI API. Error: " . $curlError);
        }

        // Verificar código HTTP
        if ($httpCode !== 200) {
            error_log("=== ERROR HTTP $httpCode en makeRequest ===");
            error_log("- URL: " . $url);
            error_log("- Respuesta: " . substr($response, 0, 500));

            $errorData = json_decode($response, true);
            $errorMessage = 'Error desconocido';

            if ($errorData && isset($errorData['error']['message'])) {
                $errorMessage = $errorData['error']['message'];
            } elseif ($httpCode === 401) {
                $errorMessage = 'API Key inválida o sin permisos';
            } elseif ($httpCode === 429) {
                $errorMessage = 'Límite de rate exceeded';
            } elseif ($httpCode === 413) {
                $errorMessage = 'Payload demasiado grande';
            }

            throw new Exception("Error en API de OpenAI: HTTP $httpCode - $errorMessage");
        }

        // Procesar respuesta exitosa
        $decodedResponse = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("=== ERROR JSON en makeRequest ===");
            error_log("- JSON Error: " . json_last_error_msg());
            error_log("- Respuesta: " . $response);
            throw new Exception('Error decodificando respuesta de OpenAI');
        }

        // Log éxito
        error_log("=== makeRequest EXITOSO ===");
        error_log("- Tokens usados: " . ($decodedResponse['usage']['total_tokens'] ?? 'N/A'));
        error_log("- SSL Fallback usado: " . ($sslFallbackUsado ? "SÍ" : "NO"));

        // Actualizar costo estimado (GPT-4: ~$0.03 per 1K tokens)
        if (isset($decodedResponse['usage']['total_tokens'])) {
            $costoRequest = ($decodedResponse['usage']['total_tokens'] / 1000) * 0.03;
            $this->costoTotal += $costoRequest;
            error_log("- Costo request: $" . round($costoRequest, 4));
        }

        return $decodedResponse;
    }

    /**
     * Calcular similitud coseno entre dos vectores
     */
    private function calcularSimilitudCoseno($vector1, $vector2)
    {
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        $length = min(count($vector1), count($vector2));

        for ($i = 0; $i < $length; $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $magnitude1 += $vector1[$i] * $vector1[$i];
            $magnitude2 += $vector2[$i] * $vector2[$i];
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }

    /**
     * Construir prompt para análisis
     */
    private function construirPromptAnalisis($actividades, $obligaciones)
    {
        $prompt = "OBLIGACIONES CONTRACTUALES:\n";
        foreach ($obligaciones as $obl) {
            $prompt .= "- Obligación {$obl['numero_obligacion']}: {$obl['descripcion']}\n";
        }

        $prompt .= "\nACTIVIDADES REGISTRADAS:\n";
        foreach ($actividades as $act) {
            $prompt .= "Fecha: {$act['fecha_actividad']}\n";
            $prompt .= "Descripción: {$act['descripcion_actividad']}\n";
            if (!empty($act['transcripcion_texto'])) {
                $prompt .= "Transcripción: {$act['transcripcion_texto']}\n";
            }
            $prompt .= "---\n";
        }

        $prompt .= "\nAnaliza cada actividad y genera un JSON con esta estructura exacta:\n";
        $prompt .= '```json
{
  "asociaciones": [
    {
      "actividad_id": 123,
      "obligacion_id": 1,
      "confianza": 0.95,
      "justificacion": "Descripción clara de por qué se asocia"
    }
  ],
  "actividades_sin_asociar": [456, 789],
  "observaciones": "Observaciones generales del análisis"
}
```';

        return $prompt;
    }

    /**
     * Construir prompt para cuenta de cobro
     */
    private function construirPromptCuentaCobro($datosContrato, $actividadesAnalizadas, $cuentasAnteriores)
    {
        $prompt = "DATOS DEL CONTRATO:\n";
        $prompt .= "Número: {$datosContrato['numero']}\n";
        $prompt .= "Contratista: {$datosContrato['contratista_nombre']}\n";
        $prompt .= "Entidad: {$datosContrato['entidad_nombre']}\n";
        $prompt .= "Objeto: {$datosContrato['objeto']}\n";
        $prompt .= "Valor mensual: $" . number_format($datosContrato['valor_mensual'], 0, ',', '.') . "\n\n";

        $prompt .= "ACTIVIDADES ANALIZADAS:\n";
        $prompt .= json_encode($actividadesAnalizadas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        if (!empty($cuentasAnteriores)) {
            $prompt .= "FORMATO DE REFERENCIA (cuenta anterior):\n";
            $prompt .= json_encode($cuentasAnteriores[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
        }

        $prompt .= "Genera una cuenta de cobro profesional en formato JSON siguiendo el estándar colombiano.";

        return $prompt;
    }
    /**
     * Configurar SSL usando configuración de la base de datos
     */
    private function configurarSSLDesdeBD($ch)
    {
        require_once __DIR__ . '/../../services/configuracion.service.php';

        // Obtener configuración desde BD
        $sslVerifyPeer = ConfiguracionService::get('ssl_verify_peer', 'sistema', true);
        $sslVerifyHost = ConfiguracionService::get('ssl_verify_host', 'sistema', true);
        $sslFallbackEnabled = ConfiguracionService::get('ssl_fallback_enabled', 'sistema', false);
        $entorno = ConfiguracionService::get('entorno', 'sistema', 'desarrollo');

        error_log("=== CONFIGURACIÓN SSL DESDE BD ===");
        error_log("- Entorno configurado: $entorno");
        error_log("- SSL Verify Peer: " . ($sslVerifyPeer ? 'true' : 'false'));
        error_log("- SSL Verify Host: " . ($sslVerifyHost ? 'true' : 'false'));
        error_log("- SSL Fallback habilitado: " . ($sslFallbackEnabled ? 'true' : 'false'));

        // Aplicar configuración SSL
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerifyPeer);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerifyHost ? 2 : 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SecretariaApp/1.0 PHP-cURL');

        return [
            'ssl_verify_peer' => $sslVerifyPeer,
            'ssl_verify_host' => $sslVerifyHost,
            'ssl_fallback_enabled' => $sslFallbackEnabled,
            'entorno' => $entorno
        ];
    }
}