<?php
class EmbeddingsService
{
    /**
     * Generar embedding para un texto
     */
    public static function generar($texto, $modeloId = null)
    {
        try {
            error_log("EmbeddingsService::generar() - INICIO");
            error_log("- Texto longitud: " . strlen($texto));
            error_log("- Modelo ID solicitado: " . ($modeloId ?? 'DEFAULT'));
            
            $tiempoInicio = microtime(true); // Para medir tiempo de respuesta
            
            if (empty($texto)) {
                throw new Exception('Texto vacío, no se puede generar embedding');
            }

            $db = Flight::db();

            // Obtener modelo de embeddings
            if (!$modeloId) {
                error_log("Buscando modelo predeterminado...");
                // Usar modelo predeterminado
                $stmt = $db->prepare("
                    SELECT im.* 
                    FROM ia_modelos im
                    INNER JOIN tipos_modelo_ia tm ON im.tipo_modelo_id = tm.id
                    WHERE tm.codigo = 'embedding' 
                    AND im.activo = 1 
                    AND im.es_predeterminado = 1
                    LIMIT 1
                ");
                $stmt->execute();
                $modelo = $stmt->fetch();
            } else {
                error_log("Buscando modelo específico ID: " . $modeloId);
                // Usar modelo específico
                $stmt = $db->prepare("
                    SELECT im.* 
                    FROM ia_modelos im
                    INNER JOIN tipos_modelo_ia tm ON im.tipo_modelo_id = tm.id
                    WHERE im.id = :modelo_id 
                    AND tm.codigo = 'embedding' 
                    AND im.activo = 1
                ");
                $stmt->bindParam(':modelo_id', $modeloId);
                $stmt->execute();
                $modelo = $stmt->fetch();
            }

            if (!$modelo) {
                error_log("ERROR: No se encontró modelo de embeddings");
                throw new Exception('No hay modelo de embeddings disponible');
            }

            error_log("Modelo encontrado: " . $modelo['proveedor'] . " - " . $modelo['modelo']);

            // Truncar texto si excede límite del modelo
            $limiteTokens = $modelo['limite_tokens'] ?? 8191;
            $texto = self::truncarTexto($texto, $limiteTokens);
            error_log("Texto truncado a tokens: " . self::estimarTokens($texto));

            // Generar embedding usando ProviderManager
            error_log("Cargando ProviderManager...");
            require_once __DIR__ . '/../providers/ai/provider-manager.php';
            $providerManager = ProviderManager::getInstance();

            if (!$providerManager->hasProvider($modelo['proveedor'])) {
                error_log("ERROR: Provider no disponible: " . $modelo['proveedor']);
                throw new Exception("Provider {$modelo['proveedor']} no configurado");
            }

            error_log("Obteniendo provider: " . $modelo['proveedor']);
            $provider = $providerManager->getProvider($modelo['proveedor']);
            
            error_log("Llamando a generarEmbeddings()...");
            $embedding = $provider->generarEmbeddings($texto);
            
            error_log("Embedding recibido. Dimensiones: " . count($embedding));
            error_log("Primeros valores: " . implode(', ', array_slice($embedding, 0, 5)) . "...");

            // Registrar uso con tiempo de respuesta
            self::registrarUso($modelo['id'], strlen($texto), $tiempoInicio);

            $resultado = [
                'vector' => $embedding,
                'modelo_id' => $modelo['id'],
                'modelo' => $modelo['modelo'],
                'proveedor' => $modelo['proveedor'],
                'dimensiones' => count($embedding),
                'tokens_estimados' => self::estimarTokens($texto)
            ];
            
            error_log("EmbeddingsService::generar() - FIN EXITOSO");
            return $resultado;

        } catch (Exception $e) {
            error_log("ERROR en EmbeddingsService::generar(): " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Calcular similitud coseno entre dos embeddings
     */
    public static function calcularSimilitud($embedding1, $embedding2)
    {
        // Si vienen como JSON, decodificar
        if (is_string($embedding1)) {
            $embedding1 = json_decode($embedding1, true);
        }
        if (is_string($embedding2)) {
            $embedding2 = json_decode($embedding2, true);
        }

        // Validar que sean arrays
        if (!is_array($embedding1) || !is_array($embedding2)) {
            return 0;
        }

        // Validar que tengan la misma dimensión
        if (count($embedding1) !== count($embedding2)) {
            error_log("Embeddings de diferentes dimensiones: " . count($embedding1) . " vs " . count($embedding2));
            return 0;
        }

        $productoPunto = 0;
        $magnitud1 = 0;
        $magnitud2 = 0;

        for ($i = 0; $i < count($embedding1); $i++) {
            $productoPunto += $embedding1[$i] * $embedding2[$i];
            $magnitud1 += $embedding1[$i] * $embedding1[$i];
            $magnitud2 += $embedding2[$i] * $embedding2[$i];
        }

        $magnitud1 = sqrt($magnitud1);
        $magnitud2 = sqrt($magnitud2);

        if ($magnitud1 == 0 || $magnitud2 == 0) {
            return 0;
        }

        return $productoPunto / ($magnitud1 * $magnitud2);
    }

    /**
     * Buscar por similitud en actividades
     */
    public static function buscarActividades($embeddingPregunta, $contratoId, $limite = 20)
    {
        try {
            $db = Flight::db();

            // Obtener actividades con embeddings
            $stmt = $db->prepare("
                SELECT 
                    id,
                    fecha_actividad,
                    descripcion_actividad,
                    embeddings,
                    embeddings_modelo_id
                FROM actividades
                WHERE contrato_id = :contrato_id
                AND embeddings IS NOT NULL
                AND procesado_ia = 1
                ORDER BY fecha_actividad DESC
            ");
            $stmt->bindParam(':contrato_id', $contratoId);
            $stmt->execute();

            $actividades = [];
            while ($row = $stmt->fetch()) {
                $similitud = self::calcularSimilitud($embeddingPregunta, $row['embeddings']);
                $actividades[] = [
                    'id' => $row['id'],
                    'fecha_actividad' => $row['fecha_actividad'],
                    'descripcion_actividad' => $row['descripcion_actividad'],
                    'similitud' => $similitud
                ];
            }

            // Ordenar por similitud descendente
            usort($actividades, function($a, $b) {
                return $b['similitud'] <=> $a['similitud'];
            });

            // Retornar solo el límite solicitado
            return array_slice($actividades, 0, $limite);

        } catch (Exception $e) {
            error_log("Error buscando actividades: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Buscar por similitud en archivos
     */
    public static function buscarArchivos($embeddingPregunta, $actividadesIds, $limitePorActividad = 3)
    {
        try {
            if (empty($actividadesIds)) {
                return [];
            }

            $db = Flight::db();

            // Crear placeholders para el IN
            $placeholders = array_fill(0, count($actividadesIds), '?');
            $sql = "
                SELECT 
                    aa.id,
                    aa.actividad_id,
                    aa.nombre_archivo,
                    aa.archivo_url,
                    aa.embeddings,
                    aa.texto_extraido,
                    a.fecha_actividad
                FROM actividades_archivos aa
                INNER JOIN actividades a ON aa.actividad_id = a.id
                WHERE aa.actividad_id IN (" . implode(',', $placeholders) . ")
                AND aa.embeddings IS NOT NULL
                AND aa.procesado = 1
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute($actividadesIds);

            $archivosPorActividad = [];
            while ($row = $stmt->fetch()) {
                $similitud = self::calcularSimilitud($embeddingPregunta, $row['embeddings']);
                
                if (!isset($archivosPorActividad[$row['actividad_id']])) {
                    $archivosPorActividad[$row['actividad_id']] = [];
                }
                
                $archivosPorActividad[$row['actividad_id']][] = [
                    'id' => $row['id'],
                    'nombre_archivo' => $row['nombre_archivo'],
                    'archivo_url' => $row['archivo_url'],
                    'fecha_actividad' => $row['fecha_actividad'],
                    'similitud' => $similitud,
                    'texto_preview' => substr($row['texto_extraido'], 0, 200) . '...'
                ];
            }

            // Ordenar archivos por similitud y limitar por actividad
            $archivosFinales = [];
            foreach ($archivosPorActividad as $actividadId => $archivos) {
                usort($archivos, function($a, $b) {
                    return $b['similitud'] <=> $a['similitud'];
                });
                
                $archivosLimitados = array_slice($archivos, 0, $limitePorActividad);
                $archivosFinales = array_merge($archivosFinales, $archivosLimitados);
            }

            return $archivosFinales;

        } catch (Exception $e) {
            error_log("Error buscando archivos: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Realizar búsqueda semántica completa
     */
    public static function busquedaSemantica($pregunta, $contratoId, $modeloId = null)
    {
        try {
            // 1. Generar embedding de la pregunta
            $embeddingResult = self::generar($pregunta, $modeloId);
            $embeddingPregunta = $embeddingResult['vector'];

            // 2. Buscar actividades similares
            $actividadesRelevantes = self::buscarActividades($embeddingPregunta, $contratoId, 15);

            // 3. Obtener IDs de actividades para buscar archivos
            $actividadesIds = array_column($actividadesRelevantes, 'id');

            // 4. Buscar archivos relacionados
            $archivosRelevantes = self::buscarArchivos($embeddingPregunta, $actividadesIds, 3);

            // 5. Construir contexto para GPT-4
            $contexto = self::construirContexto($actividadesRelevantes, $archivosRelevantes);

            return [
                'embedding_pregunta' => $embeddingResult,
                'actividades' => $actividadesRelevantes,
                'archivos' => $archivosRelevantes,
                'contexto' => $contexto,
                'total_resultados' => count($actividadesRelevantes) + count($archivosRelevantes)
            ];

        } catch (Exception $e) {
            error_log("Error en búsqueda semántica: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Construir contexto para GPT-4
     */
    private static function construirContexto($actividades, $archivos)
    {
        $contexto = "CONTEXTO DE ACTIVIDADES RELEVANTES:\n\n";

        // Agrupar archivos por actividad
        $archivosPorActividad = [];
        foreach ($archivos as $archivo) {
            $actId = $archivo['actividad_id'] ?? null;
            if ($actId) {
                if (!isset($archivosPorActividad[$actId])) {
                    $archivosPorActividad[$actId] = [];
                }
                $archivosPorActividad[$actId][] = $archivo;
            }
        }

        // Construir contexto con actividades y sus archivos
        foreach ($actividades as $actividad) {
            $contexto .= "=== Actividad del {$actividad['fecha_actividad']} ===\n";
            $contexto .= "Descripción: {$actividad['descripcion_actividad']}\n";
            $contexto .= "Relevancia: " . round($actividad['similitud'] * 100, 1) . "%\n";

            // Agregar archivos de esta actividad
            if (isset($archivosPorActividad[$actividad['id']])) {
                $contexto .= "\nArchivos relacionados:\n";
                foreach ($archivosPorActividad[$actividad['id']] as $archivo) {
                    $contexto .= "- {$archivo['nombre_archivo']} (Relevancia: " . 
                                round($archivo['similitud'] * 100, 1) . "%)\n";
                    if (isset($archivo['texto_preview'])) {
                        $contexto .= "  Preview: {$archivo['texto_preview']}\n";
                    }
                }
            }

            $contexto .= "\n---\n\n";
        }

        return $contexto;
    }

    /**
     * Estimar número de tokens en un texto
     */
    private static function estimarTokens($texto)
    {
        // Estimación aproximada: 1 token ≈ 4 caracteres en español
        return ceil(strlen($texto) / 4);
    }

    /**
     * Truncar texto para no exceder límite de tokens
     */
    private static function truncarTexto($texto, $limiteTokens)
    {
        $tokensEstimados = self::estimarTokens($texto);
        
        if ($tokensEstimados <= $limiteTokens) {
            return $texto;
        }

        // Calcular caracteres máximos (dejando margen de seguridad)
        $caracteresMax = ($limiteTokens * 4) * 0.9; // 90% del límite
        
        return substr($texto, 0, $caracteresMax) . '...';
    }

    /**
     * Registrar uso del modelo
     */
    private static function registrarUso($modeloId, $caracteresTexto, $tiempoInicio = null)
    {
        try {
            $db = Flight::db();
            
            $tokensEstimados = self::estimarTokens($caracteresTexto);
            
            // Obtener costo del modelo
            $stmt = $db->prepare("SELECT costo_por_1k_tokens FROM ia_modelos WHERE id = :id");
            $stmt->bindParam(':id', $modeloId);
            $stmt->execute();
            $modelo = $stmt->fetch();
            
            $costo = 0;
            if ($modelo && $modelo['costo_por_1k_tokens']) {
                $costo = ($tokensEstimados / 1000) * $modelo['costo_por_1k_tokens'];
            }

            // Calcular tiempo de respuesta si se proporcionó tiempo de inicio
            $tiempoRespuesta = null;
            if ($tiempoInicio !== null) {
                $tiempoRespuesta = round((microtime(true) - $tiempoInicio) * 1000); // en ms
            }

            // Insertar registro de uso con la estructura correcta de tu tabla
            $stmt = $db->prepare("
                INSERT INTO ia_modelos_uso (
                    modelo_config_id,
                    actividad_id,
                    analisis_id,
                    tokens_entrada,
                    tokens_salida,
                    tokens_total,
                    costo_usd,
                    tiempo_respuesta_ms,
                    exitoso,
                    error_mensaje,
                    fecha_uso
                ) VALUES (
                    :modelo_id,
                    NULL,
                    NULL,
                    :tokens_entrada,
                    0,
                    :tokens_total,
                    :costo,
                    :tiempo_respuesta,
                    1,
                    NULL,
                    NOW()
                )
            ");
            
            $stmt->bindParam(':modelo_id', $modeloId);
            $stmt->bindParam(':tokens_entrada', $tokensEstimados);
            $stmt->bindParam(':tokens_total', $tokensEstimados);
            $stmt->bindParam(':costo', $costo);
            $stmt->bindParam(':tiempo_respuesta', $tiempoRespuesta);
            $stmt->execute();
            
        } catch (Exception $e) {
            error_log("Error registrando uso de embedding: " . $e->getMessage());
            // No lanzar excepción para no interrumpir el proceso principal
        }
    }

    /**
     * Procesar actividades sin embeddings
     */
    public static function procesarPendientes($limite = 50)
    {
        try {
            $db = Flight::db();
            
            // Obtener actividades pendientes
            $stmt = $db->prepare("
                SELECT id, descripcion_actividad
                FROM actividades
                WHERE procesado_ia = 0
                AND descripcion_actividad IS NOT NULL
                ORDER BY fecha_registro DESC
                LIMIT :limite
            ");
            $stmt->bindParam(':limite', $limite, PDO::PARAM_INT);
            $stmt->execute();
            
            $procesadas = 0;
            $errores = 0;
            
            while ($actividad = $stmt->fetch()) {
                try {
                    // Generar embedding
                    $resultado = self::generar($actividad['descripcion_actividad']);
                    
                    // Actualizar actividad
                    $update = $db->prepare("
                        UPDATE actividades 
                        SET embeddings = :embeddings,
                            embeddings_modelo_id = :modelo_id,
                            procesado_ia = 1
                        WHERE id = :id
                    ");
                    $update->execute([
                        ':embeddings' => json_encode($resultado['vector']),
                        ':modelo_id' => $resultado['modelo_id'],
                        ':id' => $actividad['id']
                    ]);
                    
                    $procesadas++;
                    
                } catch (Exception $e) {
                    error_log("Error procesando actividad {$actividad['id']}: " . $e->getMessage());
                    $errores++;
                }
            }
            
            return [
                'procesadas' => $procesadas,
                'errores' => $errores
            ];
            
        } catch (Exception $e) {
            error_log("Error procesando pendientes: " . $e->getMessage());
            throw $e;
        }
    }
}