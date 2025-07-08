<?php
// providers/ai/gemini.provider.php
require_once __DIR__ . '/ai-provider.interface.php';

class GeminiProvider implements AIProviderInterface
{
    private $apiKey;
    private $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    private $tokensUsados = 0;
    private $costoTotal = 0;
    
    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }
    
    /**
     * Generar embeddings para búsqueda semántica
     */
    public function generarEmbeddings(string $texto): array
    {
        $url = $this->baseUrl . '/models/text-embedding-004:embedContent?key=' . $this->apiKey;
        
        $data = [
            'model' => 'models/text-embedding-004',
            'content' => [
                'parts' => [
                    ['text' => $texto]
                ]
            ],
            'taskType' => 'RETRIEVAL_DOCUMENT'
        ];
        
        $response = $this->makeRequest($url, $data);
        
        if (isset($response['embedding']['values'])) {
            return $response['embedding']['values'];
        }
        
        throw new Exception('Error generando embeddings con Gemini');
    }
    
    /**
     * Analizar actividades y asociar con obligaciones
     */
    public function analizarActividades(array $actividades, array $obligaciones): array
    {
        $url = $this->baseUrl . '/models/gemini-1.5-pro:generateContent?key=' . $this->apiKey;
        
        // Preparar el prompt
        $prompt = $this->construirPromptAnalisis($actividades, $obligaciones);
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'topK' => 1,
                'topP' => 1,
                'maxOutputTokens' => 2048,
            ]
        ];
        
        $response = $this->makeRequest($url, $data);
        
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            $resultado = $response['candidates'][0]['content']['parts'][0]['text'];
            
            // Intentar parsear como JSON
            $analisis = json_decode($resultado, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $analisis;
            }
            
            // Si no es JSON válido, devolver estructura básica
            return [
                'actividades_analizadas' => count($actividades),
                'asociaciones' => [],
                'confianza' => 0.5,
                'observaciones' => $resultado
            ];
        }
        
        throw new Exception('Error analizando actividades con Gemini');
    }
    
    /**
     * Buscar actividades por pregunta en lenguaje natural
     */
    public function buscarPorPregunta(string $pregunta, array $embeddings): array
    {
        // Primero, generar embedding de la pregunta
        $embeddingPregunta = $this->generarEmbeddings($pregunta);
        
        // Calcular similitud coseno con cada embedding almacenado
        $resultados = [];
        
        foreach ($embeddings as $actividadId => $embeddingData) {
            $embeddingVector = json_decode($embeddingData['embedding_vector'], true);
            if ($embeddingVector) {
                $similitud = $this->calcularSimilitudCoseno($embeddingPregunta, $embeddingVector);
                $resultados[] = [
                    'actividad_id' => $actividadId,
                    'similitud' => $similitud,
                    'proveedor' => $embeddingData['proveedor'],
                    'modelo' => $embeddingData['modelo']
                ];
            }
        }
        
        // Ordenar por similitud descendente
        usort($resultados, function($a, $b) {
            return $b['similitud'] <=> $a['similitud'];
        });
        
        // Retornar solo los IDs ordenados por relevancia
        return array_map(function($r) { return $r['actividad_id']; }, $resultados);
    }
    
    /**
     * Generar cuenta de cobro basada en actividades
     */
    public function generarCuentaCobro(array $datosContrato, array $actividadesAnalizadas, array $cuentasAnteriores = []): array
    {
        $url = $this->baseUrl . '/models/gemini-1.5-pro:generateContent?key=' . $this->apiKey;
        
        // Construir prompt para generación
        $prompt = $this->construirPromptCuentaCobro($datosContrato, $actividadesAnalizadas, $cuentasAnteriores);
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'topK' => 1,
                'topP' => 1,
                'maxOutputTokens' => 4096,
            ]
        ];
        
        $response = $this->makeRequest($url, $data);
        
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            $resultado = $response['candidates'][0]['content']['parts'][0]['text'];
            
            // Intentar parsear como JSON
            $cuentaCobro = json_decode($resultado, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $cuentaCobro;
            }
            
            // Si no es JSON, estructurar manualmente
            return [
                'formato' => 'texto',
                'contenido' => $resultado,
                'requiere_revision' => true
            ];
        }
        
        throw new Exception('Error generando cuenta de cobro con Gemini');
    }
    
    /**
     * Obtener el nombre del proveedor
     */
    public function getNombre(): string
    {
        return 'gemini';
    }
    
    /**
     * Obtener información de uso
     */
    public function getUsoInfo(): array
    {
        return [
            'tokens_usados' => $this->tokensUsados,
            'costo_estimado_usd' => $this->costoTotal,
            'proveedor' => 'Google Gemini'
        ];
    }
    
    // MÉTODOS AUXILIARES PRIVADOS
    
    /**
     * Realizar petición HTTP a la API de Gemini
     */
    private function makeRequest($url, $data)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("Error Gemini API: HTTP $httpCode - Response: $response");
            throw new Exception("Error en API de Gemini: HTTP $httpCode");
        }
        
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Error decodificando respuesta de Gemini');
        }
        
        // Actualizar contadores de uso
        if (isset($decodedResponse['usageMetadata'])) {
            $this->tokensUsados += $decodedResponse['usageMetadata']['totalTokenCount'] ?? 0;
            // Gemini 1.5 Pro: ~$0.035 per 1K tokens
            $this->costoTotal += ($this->tokensUsados / 1000) * 0.035;
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
        
        for ($i = 0; $i < count($vector1); $i++) {
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
     * Construir prompt para análisis de actividades
     */
    private function construirPromptAnalisis($actividades, $obligaciones)
    {
        $prompt = "Eres un asistente experto en análisis de actividades contractuales en Colombia.\n\n";
        
        $prompt .= "OBLIGACIONES CONTRACTUALES:\n";
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
        
        $prompt .= "\nAnaliza cada actividad y determina a qué obligación contractual corresponde. ";
        $prompt .= "Responde en formato JSON con la siguiente estructura:\n";
        $prompt .= '```json
{
  "asociaciones": [
    {
      "actividad_id": 123,
      "obligacion_id": 1,
      "confianza": 0.95,
      "justificacion": "La actividad menciona reunión del comité técnico"
    }
  ],
  "actividades_sin_asociar": [456, 789],
  "observaciones": "Texto con observaciones generales"
}
```';
        
        return $prompt;
    }
    
    /**
     * Construir prompt para generación de cuenta de cobro
     */
    private function construirPromptCuentaCobro($datosContrato, $actividadesAnalizadas, $cuentasAnteriores)
    {
        $prompt = "Eres un experto en generación de cuentas de cobro para contratistas en Colombia.\n\n";
        
        $prompt .= "DATOS DEL CONTRATO:\n";
        $prompt .= "Número: {$datosContrato['numero']}\n";
        $prompt .= "Contratista: {$datosContrato['contratista_nombre']}\n";
        $prompt .= "Entidad: {$datosContrato['entidad_nombre']}\n";
        $prompt .= "Objeto: {$datosContrato['objeto']}\n";
        $prompt .= "Valor mensual: $" . number_format($datosContrato['valor_mensual'], 0, ',', '.') . "\n\n";
        
        $prompt .= "ACTIVIDADES DEL MES ANALIZADAS:\n";
        $prompt .= json_encode($actividadesAnalizadas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
        
        if (!empty($cuentasAnteriores)) {
            $prompt .= "EJEMPLO DE CUENTAS ANTERIORES:\n";
            $prompt .= json_encode($cuentasAnteriores[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        
        $prompt .= "Genera una cuenta de cobro en formato JSON que incluya:\n";
        $prompt .= "1. Relación de actividades por obligación\n";
        $prompt .= "2. Productos/entregables del mes\n";
        $prompt .= "3. Valor a cobrar\n";
        $prompt .= "4. Formato profesional estándar colombiano\n\n";
        
        $prompt .= "Responde SOLO con el JSON de la cuenta de cobro, sin explicaciones adicionales.";
        
        return $prompt;
    }
}