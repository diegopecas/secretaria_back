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
        
        // Detectar el tipo MIME del archivo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $audioPath);
        finfo_close($finfo);
        
        // Determinar la extensión basada en el MIME type
        $extension = 'webm'; // Por defecto
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
        
        if (isset($mimeToExt[$mimeType])) {
            $extension = $mimeToExt[$mimeType];
        }
        
        // Crear el archivo con el nombre correcto para CURL
        $audioFile = new CURLFile($audioPath, $mimeType, 'audio.' . $extension);
        
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
        
        // Realizar petición
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutos de timeout
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("Error Whisper API: HTTP $httpCode - Response: $response");
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? 'Error desconocido';
            throw new Exception("Error en Whisper: $errorMessage");
        }
        
        $resultado = json_decode($response, true);
        
        if (!isset($resultado['text'])) {
            throw new Exception('Respuesta inválida de Whisper');
        }
        
        // Calcular duración y costo
        $duracionSegundos = $resultado['duration'] ?? 0;
        $duracionMinutos = $duracionSegundos / 60;
        $costo = $duracionMinutos * 0.006;
        
        $this->costoTotal += $costo;
        
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
        usort($resultados, function($a, $b) {
            return $b['similitud'] <=> $a['similitud'];
        });
        
        return array_map(function($r) { return $r['actividad_id']; }, $resultados);
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
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("Error OpenAI API: HTTP $httpCode - Response: $response");
            throw new Exception("Error en API de OpenAI: HTTP $httpCode");
        }
        
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Error decodificando respuesta de OpenAI');
        }
        
        // Actualizar costo estimado (GPT-4: ~$0.03 per 1K tokens)
        if (isset($decodedResponse['usage']['total_tokens'])) {
            $this->costoTotal += ($decodedResponse['usage']['total_tokens'] / 1000) * 0.03;
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
}