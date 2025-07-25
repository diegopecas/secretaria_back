<?php
class ActividadesArchivosService
{
    // Agregar archivos a una actividad
    public static function agregar($actividadId, $files, $usuarioId, $db = null)
    {
        $transaccionLocal = false;
        $archivosGuardados = [];

        try {
            if (!$db) {
                $db = Flight::db();
                $db->beginTransaction();
                $transaccionLocal = true;
            }

            require_once __DIR__ . '/../providers/storage/storage.manager.php';
            $storage = StorageManager::getInstance();

            foreach ($files as $key => $file) {
                // Si es un array de archivos múltiples
                if (is_array($file['error'])) {
                    for ($i = 0; $i < count($file['error']); $i++) {
                        if ($file['error'][$i] === UPLOAD_ERR_OK) {
                            $singleFile = [
                                'name' => $file['name'][$i],
                                'type' => $file['type'][$i],
                                'tmp_name' => $file['tmp_name'][$i],
                                'error' => $file['error'][$i],
                                'size' => $file['size'][$i]
                            ];
                            $resultado = self::procesarArchivoIndividual($actividadId, $singleFile, $usuarioId, $db, $storage);
                            if ($resultado) {
                                $archivosGuardados[] = $resultado;
                            }
                        }
                    }
                } else {
                    // Archivo único
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $resultado = self::procesarArchivoIndividual($actividadId, $file, $usuarioId, $db, $storage);
                        if ($resultado) {
                            $archivosGuardados[] = $resultado;
                        }
                    }
                }
            }

            if ($transaccionLocal) {
                $db->commit();
            }

            return $archivosGuardados;
        } catch (Exception $e) {
            if ($transaccionLocal && $db) {
                $db->rollBack();
            }

            // Intentar eliminar archivos físicos guardados si hay error
            foreach ($archivosGuardados as $archivo) {
                try {
                    $storage->eliminarArchivo($archivo['archivo_url']);
                } catch (Exception $ex) {
                    error_log("Error eliminando archivo en rollback: " . $ex->getMessage());
                }
            }

            throw new Exception('Error al agregar archivos: ' . $e->getMessage());
        }
    }


    // Procesar un archivo individual
    private static function procesarArchivoIndividual($actividadId, $file, $usuarioId, $db, $storage)
    {
        try {
            // Obtener información del contrato y contratista
            $stmtInfo = $db->prepare("
                SELECT 
                    c.id as contrato_id,
                    c.numero_contrato,
                    YEAR(c.fecha_inicio) as anio_contrato,
                    ct.id as contratista_id,
                    ct.nombre_completo,
                    a.fecha_actividad,
                    a.embeddings_modelo_id
                FROM actividades a
                INNER JOIN contratos c ON a.contrato_id = c.id
                INNER JOIN contratistas ct ON c.contratista_id = ct.id
                WHERE a.id = :actividad_id
            ");
            $stmtInfo->bindParam(':actividad_id', $actividadId);
            $stmtInfo->execute();
            $info = $stmtInfo->fetch();

            if (!$info) {
                throw new Exception("No se encontró información de la actividad");
            }

            // Limpiar nombres para la ruta
            $nombreContratistaLimpio = self::limpiarNombreParaRuta($info['nombre_completo']);
            $numeroContratoLimpio = self::limpiarNombreParaRuta($info['numero_contrato']);

            // Crear estructura de carpetas
            $carpetaContratista = $info['contratista_id'] . '_' . $nombreContratistaLimpio;
            $carpetaContrato = $info['anio_contrato'] . '_' . $numeroContratoLimpio;
            $anioActividad = date('Y', strtotime($info['fecha_actividad']));
            $mesActividad = date('m', strtotime($info['fecha_actividad']));

            $rutaCompleta = sprintf(
                'contratistas/%s/contratos/%s/actividades/%s/%s/act_%s',
                $carpetaContratista,
                $carpetaContrato,
                $anioActividad,
                $mesActividad,
                $actividadId
            );

            error_log("Guardando archivo en: " . $rutaCompleta);

            // Guardar archivo usando StorageManager
            $archivoInfo = $storage->guardarArchivo($file, $rutaCompleta);

            // Determinar tipo_archivo_id basado en la extensión
            $tipo_archivo_id = self::determinarTipoArchivo($archivoInfo['extension']);

            // Por defecto, almacenar archivo y extraer texto
            $almacenar_archivo = true;
            $extraer_texto = true;

            // Insertar en la BD con los nuevos campos
            $sql = "INSERT INTO actividades_archivos (
                actividad_id,
                nombre_archivo,
                archivo_url,
                tipo_archivo_id,
                mime_type,
                tamanio_bytes,
                hash_archivo,
                almacenar_archivo,
                extraer_texto,
                estado_extraccion,
                usuario_carga_id,
                procesado,
                texto_extraido
            ) VALUES (
                :actividad_id,
                :nombre_archivo,
                :archivo_url,
                :tipo_archivo_id,
                :mime_type,
                :tamanio_bytes,
                :hash_archivo,
                :almacenar_archivo,
                :extraer_texto,
                'pendiente',
                :usuario_carga_id,
                0,
                :texto_extraido
            )";

            // Extraer texto inmediatamente si es posible
            $textoExtraido = null;
            if (ExtractorService::puedeExtraerTexto($archivoInfo['extension'])) {
                try {
                    require_once __DIR__ . '/extractor.service.php';
                    $archivoPath = __DIR__ . '/../uploads/' . $archivoInfo['path'];
                    $textoExtraido = ExtractorService::extraerTexto($archivoPath, $archivoInfo['extension']);
                    error_log("Texto extraído exitosamente, longitud: " . ($textoExtraido ? strlen($textoExtraido) : 0));
                } catch (Exception $e) {
                    error_log("Error extrayendo texto: " . $e->getMessage());
                    $textoExtraido = null;
                }
            }

            $stmt = $db->prepare($sql);
            $stmt->bindParam(':actividad_id', $actividadId);
            $stmt->bindParam(':nombre_archivo', $archivoInfo['nombre_original']);
            $stmt->bindParam(':archivo_url', $archivoInfo['path']);
            $stmt->bindParam(':tipo_archivo_id', $tipo_archivo_id);
            $stmt->bindParam(':mime_type', $archivoInfo['mime_type']);
            $stmt->bindParam(':tamanio_bytes', $archivoInfo['size']);
            $stmt->bindParam(':hash_archivo', $archivoInfo['hash']);
            $stmt->bindParam(':almacenar_archivo', $almacenar_archivo, PDO::PARAM_BOOL);
            $stmt->bindParam(':extraer_texto', $extraer_texto, PDO::PARAM_BOOL);
            $stmt->bindParam(':usuario_carga_id', $usuarioId);
            $stmt->bindParam(':texto_extraido', $textoExtraido);
            $stmt->execute();

            $archivoInfo['id'] = $db->lastInsertId();
            $archivoInfo['tipo_archivo_id'] = $tipo_archivo_id;
            $archivoInfo['texto_extraido'] = $textoExtraido;

            // Intentar generar embeddings si hay texto extraído
            if (!empty($textoExtraido)) {
                try {
                    error_log("=== INICIO GENERACIÓN EMBEDDINGS ARCHIVO {$archivoInfo['id']} ===");
                    error_log("Texto ya extraído, longitud: " . strlen($textoExtraido));
                    
                    require_once __DIR__ . '/embeddings.service.php';
                    
                    // Usar el modelo de la actividad
                    $embedding_modelo_id = $info['embeddings_modelo_id'] ?? null;
                    error_log("Modelo ID de la actividad: " . ($embedding_modelo_id ?? 'DEFAULT'));
                    
                    // Generar embedding del texto ya extraído
                    $embeddingResult = EmbeddingsService::generar($textoExtraido, $embedding_modelo_id);
                    
                    error_log("Embedding generado exitosamente:");
                    error_log("- Modelo: " . $embeddingResult['modelo']);
                    error_log("- Dimensiones: " . $embeddingResult['dimensiones']);

                    // Actualizar archivo con embedding
                    $stmtUpdate = $db->prepare("
                    UPDATE actividades_archivos 
                    SET embeddings = :embeddings,
                        modelo_extraccion_id = :modelo_id,
                        estado_extraccion = 'completado',
                        procesado = 1,
                        fecha_procesamiento = NOW()
                    WHERE id = :id
                ");

                    $embeddingJson = json_encode($embeddingResult['vector']);
                    $stmtUpdate->bindParam(':embeddings', $embeddingJson);
                    $stmtUpdate->bindParam(':modelo_id', $embeddingResult['modelo_id']);
                    $stmtUpdate->bindParam(':id', $archivoInfo['id']);
                    $stmtUpdate->execute();

                    $archivoInfo['procesado'] = true;
                    error_log("=== FIN GENERACIÓN EMBEDDINGS ARCHIVO ===");
                } catch (Exception $e) {
                    error_log("ERROR generando embedding archivo {$archivoInfo['id']}:");
                    error_log("- Mensaje: " . $e->getMessage());
                    error_log("- Stack: " . $e->getTraceAsString());
                    // No lanzar el error para no interrumpir el proceso
                    // El archivo queda pendiente para procesar después
                }
            } else {
                error_log("Archivo {$archivoInfo['id']} sin texto extraído, queda pendiente");
            }

            return $archivoInfo;
        } catch (Exception $e) {
            throw new Exception("Error procesando archivo {$file['name']}: " . $e->getMessage());
        }
    }

    // Obtener archivos de una actividad
    public static function obtenerPorActividad($actividadId)
    {
        try {
            $db = Flight::db();

            $sql = "SELECT 
                    aa.*,
                    ta.nombre as tipo_archivo_nombre,
                    ta.codigo as tipo_archivo_codigo,
                    u.nombre as usuario_carga_nombre,
                    im.modelo as modelo_extraccion_nombre
                FROM actividades_archivos aa
                LEFT JOIN tipos_archivo ta ON aa.tipo_archivo_id = ta.id
                LEFT JOIN usuarios u ON aa.usuario_carga_id = u.id
                LEFT JOIN ia_modelos im ON aa.modelo_extraccion_id = im.id
                WHERE aa.actividad_id = :actividad_id
                ORDER BY aa.fecha_carga DESC";

            $stmt = $db->prepare($sql);
            $stmt->bindParam(':actividad_id', $actividadId);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (Exception $e) {
            throw new Exception('Error al obtener archivos: ' . $e->getMessage());
        }
    }

    // Obtener un archivo específico
    public static function obtenerPorId($archivoId)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            $db = Flight::db();

            $sql = "SELECT 
                    aa.*,
                    a.contrato_id,
                    c.contratista_id,
                    ta.nombre as tipo_archivo_nombre,
                    ta.codigo as tipo_archivo_codigo
                FROM actividades_archivos aa
                INNER JOIN actividades a ON aa.actividad_id = a.id
                INNER JOIN contratos c ON a.contrato_id = c.id
                LEFT JOIN tipos_archivo ta ON aa.tipo_archivo_id = ta.id
                WHERE aa.id = :archivo_id";

            $stmt = $db->prepare($sql);
            $stmt->bindParam(':archivo_id', $archivoId);
            $stmt->execute();
            $archivo = $stmt->fetch();

            if (!$archivo) {
                responderJSON(['error' => 'Archivo no encontrado'], 404);
                return;
            }

            // Verificar acceso usando usuarios_contratistas
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
                $stmtAcceso->bindParam(':contratista_id', $archivo['contratista_id']);
                $stmtAcceso->execute();
                $resultadoAcceso = $stmtAcceso->fetch();

                if ($resultadoAcceso['tiene_acceso'] == 0) {
                    responderJSON(['error' => 'No tiene acceso a este archivo'], 403);
                    return;
                }
            }

            responderJSON([
                'success' => true,
                'archivo' => $archivo
            ]);
        } catch (Exception $e) {
            error_log("Error obteniendo archivo: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener archivo'], 500);
        }
    }

    // Eliminar un archivo
    public static function eliminar($archivoId)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'actividades.registrar')) {
                responderJSON(['error' => 'No tiene permisos para eliminar archivos'], 403);
                return;
            }

            $db = Flight::db();

            // Obtener información del archivo con validación de acceso
            $sql = "SELECT 
                    aa.*,
                    a.contrato_id,
                    c.contratista_id
                FROM actividades_archivos aa
                INNER JOIN actividades a ON aa.actividad_id = a.id
                INNER JOIN contratos c ON a.contrato_id = c.id
                WHERE aa.id = :archivo_id";

            $stmt = $db->prepare($sql);
            $stmt->bindParam(':archivo_id', $archivoId);
            $stmt->execute();
            $archivo = $stmt->fetch();

            if (!$archivo) {
                responderJSON(['error' => 'Archivo no encontrado'], 404);
                return;
            }

            // Verificar acceso usando usuarios_contratistas
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
                $stmtAcceso->bindParam(':contratista_id', $archivo['contratista_id']);
                $stmtAcceso->execute();
                $resultadoAcceso = $stmtAcceso->fetch();

                if ($resultadoAcceso['tiene_acceso'] == 0) {
                    responderJSON(['error' => 'No tiene acceso a este archivo'], 403);
                    return;
                }
            }

            // Eliminar archivo físico si está marcado para almacenar
            if ($archivo['almacenar_archivo']) {
                require_once __DIR__ . '/../providers/storage/storage.manager.php';
                $storage = StorageManager::getInstance();

                try {
                    $storage->eliminarArchivo($archivo['archivo_url']);
                } catch (Exception $e) {
                    error_log("Error eliminando archivo físico: " . $e->getMessage());
                    // Continuar con la eliminación del registro
                }
            }

            // Eliminar registro de la BD
            $stmt = $db->prepare("DELETE FROM actividades_archivos WHERE id = :archivo_id");
            $stmt->bindParam(':archivo_id', $archivoId);
            $stmt->execute();

            responderJSON([
                'success' => true,
                'message' => 'Archivo eliminado correctamente'
            ]);
        } catch (Exception $e) {
            error_log("Error eliminando archivo: " . $e->getMessage());
            responderJSON(['error' => 'Error al eliminar archivo'], 500);
        }
    }

    // Eliminar todos los archivos de una actividad (usado internamente)
    public static function eliminarPorActividad($actividadId, $db = null)
    {
        try {
            if (!$db) {
                $db = Flight::db();
            }

            // Obtener archivos antes de eliminar
            $stmt = $db->prepare("
                SELECT id, archivo_url, almacenar_archivo 
                FROM actividades_archivos 
                WHERE actividad_id = :actividad_id
            ");
            $stmt->bindParam(':actividad_id', $actividadId);
            $stmt->execute();
            $archivos = $stmt->fetchAll();

            // Eliminar archivos físicos si están marcados para almacenar
            if (count($archivos) > 0) {
                require_once __DIR__ . '/../providers/storage/storage.manager.php';
                $storage = StorageManager::getInstance();

                foreach ($archivos as $archivo) {
                    if ($archivo['almacenar_archivo']) {
                        try {
                            $storage->eliminarArchivo($archivo['archivo_url']);
                        } catch (Exception $e) {
                            error_log("Error eliminando archivo físico: " . $e->getMessage());
                        }
                    }
                }
            }

            // Eliminar registros de la BD (se eliminan por CASCADE pero por consistencia)
            $stmt = $db->prepare("DELETE FROM actividades_archivos WHERE actividad_id = :actividad_id");
            $stmt->bindParam(':actividad_id', $actividadId);
            $stmt->execute();

            return true;
        } catch (Exception $e) {
            throw new Exception('Error al eliminar archivos: ' . $e->getMessage());
        }
    }

    // Buscar archivos por contenido con embeddings
    public static function buscarPorContenido()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            $contrato_id = Flight::request()->data['contrato_id'] ?? null;
            $busqueda = Flight::request()->data['busqueda'] ?? null;
            $usar_embeddings = Flight::request()->data['usar_embeddings'] ?? true;

            if (!$contrato_id || !$busqueda) {
                responderJSON(['error' => 'Contrato y búsqueda son requeridos'], 400);
                return;
            }

            $db = Flight::db();

            // Verificar acceso al contrato usando usuarios_contratistas
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

            $resultados = [];

            if ($usar_embeddings) {
                // Búsqueda con embeddings
                require_once __DIR__ . '/embeddings.service.php';

                // Generar embedding de la búsqueda
                $embeddingBusqueda = EmbeddingsService::generar($busqueda);

                // Buscar archivos con embeddings
                $sql = "SELECT 
                        aa.id,
                        aa.nombre_archivo,
                        aa.archivo_url,
                        aa.fecha_carga,
                        aa.embeddings,
                        aa.texto_extraido,
                        a.id as actividad_id,
                        a.fecha_actividad,
                        a.descripcion_actividad
                    FROM actividades_archivos aa
                    INNER JOIN actividades a ON aa.actividad_id = a.id
                    WHERE a.contrato_id = :contrato_id
                    AND aa.procesado = 1
                    AND aa.embeddings IS NOT NULL";

                $stmt = $db->prepare($sql);
                $stmt->bindParam(':contrato_id', $contrato_id);
                $stmt->execute();

                while ($row = $stmt->fetch()) {
                    $similitud = EmbeddingsService::calcularSimilitud(
                        $embeddingBusqueda['vector'],
                        $row['embeddings']
                    );

                    if ($similitud > 0.7) { // Umbral de similitud
                        $resultados[] = [
                            'id' => $row['id'],
                            'nombre_archivo' => $row['nombre_archivo'],
                            'archivo_url' => $row['archivo_url'],
                            'fecha_carga' => $row['fecha_carga'],
                            'actividad_id' => $row['actividad_id'],
                            'fecha_actividad' => $row['fecha_actividad'],
                            'descripcion_actividad' => $row['descripcion_actividad'],
                            'similitud' => $similitud,
                            'preview' => substr($row['texto_extraido'], 0, 200) . '...'
                        ];
                    }
                }

                // Ordenar por similitud
                usort($resultados, function ($a, $b) {
                    return $b['similitud'] <=> $a['similitud'];
                });
            } else {
                // Búsqueda con FULLTEXT
                $sql = "SELECT 
                        aa.id,
                        aa.nombre_archivo,
                        aa.archivo_url,
                        aa.fecha_carga,
                        a.id as actividad_id,
                        a.fecha_actividad,
                        a.descripcion_actividad,
                        MATCH(aa.texto_extraido) AGAINST(:busqueda IN NATURAL LANGUAGE MODE) as relevancia
                    FROM actividades_archivos aa
                    INNER JOIN actividades a ON aa.actividad_id = a.id
                    WHERE a.contrato_id = :contrato_id
                    AND aa.procesado = 1
                    AND aa.texto_extraido IS NOT NULL
                    AND MATCH(aa.texto_extraido) AGAINST(:busqueda2 IN NATURAL LANGUAGE MODE)
                    ORDER BY relevancia DESC
                    LIMIT 20";

                $stmt = $db->prepare($sql);
                $stmt->bindParam(':contrato_id', $contrato_id);
                $stmt->bindParam(':busqueda', $busqueda);
                $stmt->bindParam(':busqueda2', $busqueda);
                $stmt->execute();

                $resultados = $stmt->fetchAll();
            }

            responderJSON([
                'success' => true,
                'resultados' => $resultados,
                'total' => count($resultados),
                'metodo' => $usar_embeddings ? 'embeddings' : 'fulltext'
            ]);
        } catch (Exception $e) {
            error_log("Error buscando archivos: " . $e->getMessage());
            responderJSON(['error' => 'Error al buscar archivos'], 500);
        }
    }

    // Obtener estadísticas de archivos
    public static function obtenerEstadisticas()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            $contrato_id = Flight::request()->query['contrato_id'] ?? null;

            $db = Flight::db();

            $sql = "SELECT 
                    COUNT(*) as total_archivos,
                    SUM(aa.tamanio_bytes) as espacio_total,
                    COUNT(DISTINCT aa.actividad_id) as actividades_con_archivos,
                    SUM(CASE WHEN aa.procesado = 1 THEN 1 ELSE 0 END) as archivos_procesados,
                    SUM(CASE WHEN aa.estado_extraccion = 'completado' THEN 1 ELSE 0 END) as archivos_con_texto,
                    SUM(CASE WHEN aa.embeddings IS NOT NULL THEN 1 ELSE 0 END) as archivos_con_embeddings,
                    ta.nombre as tipo_archivo,
                    COUNT(*) as cantidad_por_tipo
                FROM actividades_archivos aa
                INNER JOIN actividades a ON aa.actividad_id = a.id
                LEFT JOIN tipos_archivo ta ON aa.tipo_archivo_id = ta.id";

            $params = [];

            if ($contrato_id) {
                // Verificar acceso al contrato si es contratista
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

                $sql .= " WHERE a.contrato_id = :contrato_id";
                $params[':contrato_id'] = $contrato_id;
            }

            $sql .= " GROUP BY ta.id, ta.nombre";

            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();

            $estadisticas = $stmt->fetchAll();

            responderJSON([
                'success' => true,
                'estadisticas' => $estadisticas
            ]);
        } catch (Exception $e) {
            error_log("Error obteniendo estadísticas: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener estadísticas'], 500);
        }
    }

    // Procesar archivo para extracción de texto y embeddings
    public static function procesarParaIA($archivoId)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'actividades.registrar')) {
                responderJSON(['error' => 'No tiene permisos para procesar archivos'], 403);
                return;
            }

            $db = Flight::db();

            // Obtener información del archivo
            $stmt = $db->prepare("
                SELECT aa.*, ta.codigo as tipo_codigo
                FROM actividades_archivos aa
                LEFT JOIN tipos_archivo ta ON aa.tipo_archivo_id = ta.id
                WHERE aa.id = :archivo_id
            ");
            $stmt->bindParam(':archivo_id', $archivoId);
            $stmt->execute();
            $archivo = $stmt->fetch();

            if (!$archivo) {
                responderJSON(['error' => 'Archivo no encontrado'], 404);
                return;
            }

            // Verificar que esté marcado para extraer texto
            if (!$archivo['extraer_texto']) {
                responderJSON(['error' => 'Este archivo no está marcado para extracción de texto'], 400);
                return;
            }

            // Actualizar estado a procesando
            $stmt = $db->prepare("
                UPDATE actividades_archivos 
                SET estado_extraccion = 'procesando'
                WHERE id = :archivo_id
            ");
            $stmt->bindParam(':archivo_id', $archivoId);
            $stmt->execute();

            try {
                require_once __DIR__ . '/extractor.service.php';
                require_once __DIR__ . '/embeddings.service.php';

                // Obtener path completo del archivo
                $archivoPath = __DIR__ . '/../' . $archivo['archivo_url'];

                // Extraer texto
                $textoExtraido = ExtractorService::extraerTexto($archivoPath, $archivo['tipo_codigo']);

                if ($textoExtraido) {
                    // Generar embedding
                    // Obtener el modelo de la actividad o usar el predeterminado
                    $stmtModelo = $db->prepare("
                        SELECT embeddings_modelo_id 
                        FROM actividades 
                        WHERE id = :actividad_id
                    ");
                    $stmtModelo->bindParam(':actividad_id', $actividadId);
                    $stmtModelo->execute();
                    $actividadModelo = $stmtModelo->fetch();

                    $modelo_id = $actividadModelo['embeddings_modelo_id'] ?? null;
                    try {
                        error_log("=== INICIO EMBEDDING ARCHIVO ===");
                        error_log("Texto a procesar (primeros 100 chars): " . substr($textoExtraido, 0, 100));

                        $embeddingResult = EmbeddingsService::generar($textoExtraido, $modelo_id);

                        error_log("Embedding generado exitosamente");
                        error_log("- Modelo: " . $embeddingResult['modelo']);
                        error_log("- Proveedor: " . $embeddingResult['proveedor']);
                        error_log("- Dimensiones: " . $embeddingResult['dimensiones']);
                    } catch (Exception $e) {
                        error_log("ERROR CRÍTICO en embedding:");
                        error_log("- Error: " . $e->getMessage());
                        error_log("- Trace: " . $e->getTraceAsString());

                        // Re-lanzar para ver el error completo
                        throw $e;
                    }

                    // Actualizar archivo
                    $stmt = $db->prepare("
                        UPDATE actividades_archivos 
                        SET texto_extraido = :texto,
                            embeddings = :embeddings,
                            modelo_extraccion_id = :modelo_id,
                            procesado = 1,
                            estado_extraccion = 'completado',
                            fecha_procesamiento = NOW()
                        WHERE id = :archivo_id
                    ");
                    $stmt->bindParam(':texto', $textoExtraido);
                    $stmt->bindParam(':embeddings', json_encode($embeddingResult['vector']));
                    $stmt->bindParam(':modelo_id', $embeddingResult['modelo_id']);
                    $stmt->bindParam(':archivo_id', $archivoId);
                    $stmt->execute();

                    responderJSON([
                        'success' => true,
                        'message' => 'Archivo procesado correctamente',
                        'texto_extraido' => strlen($textoExtraido) > 500 ?
                            substr($textoExtraido, 0, 500) . '...' : $textoExtraido
                    ]);
                } else {
                    // No se pudo extraer texto
                    $stmt = $db->prepare("
                        UPDATE actividades_archivos 
                        SET estado_extraccion = 'error',
                            fecha_procesamiento = NOW()
                        WHERE id = :archivo_id
                    ");
                    $stmt->bindParam(':archivo_id', $archivoId);
                    $stmt->execute();

                    responderJSON([
                        'success' => false,
                        'message' => 'No se pudo extraer texto del archivo'
                    ]);
                }
            } catch (Exception $e) {
                // Marcar como error
                $stmt = $db->prepare("
                    UPDATE actividades_archivos 
                    SET estado_extraccion = 'error',
                        fecha_procesamiento = NOW()
                    WHERE id = :archivo_id
                ");
                $stmt->bindParam(':archivo_id', $archivoId);
                $stmt->execute();

                throw $e;
            }
        } catch (Exception $e) {
            error_log("Error procesando archivo: " . $e->getMessage());
            responderJSON(['error' => 'Error al procesar archivo'], 500);
        }
    }

    // Procesar archivos pendientes (batch)
    public static function procesarPendientes()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'sistema.configurar')) {
                responderJSON(['error' => 'No tiene permisos para ejecutar este proceso'], 403);
                return;
            }

            $limite = Flight::request()->query['limite'] ?? 10;

            $db = Flight::db();

            // Obtener archivos pendientes
            $stmt = $db->prepare("
                SELECT aa.id
                FROM actividades_archivos aa
                WHERE aa.estado_extraccion = 'pendiente'
                AND aa.extraer_texto = 1
                ORDER BY aa.fecha_carga ASC
                LIMIT :limite
            ");
            $stmt->bindParam(':limite', $limite, PDO::PARAM_INT);
            $stmt->execute();

            $procesados = 0;
            $errores = 0;

            require_once __DIR__ . '/extractor.service.php';
            require_once __DIR__ . '/embeddings.service.php';

            while ($archivo = $stmt->fetch()) {
                try {
                    // Obtener información completa del archivo
                    $stmtArchivo = $db->prepare("
                        SELECT aa.*, ta.codigo as tipo_codigo
                        FROM actividades_archivos aa
                        LEFT JOIN tipos_archivo ta ON aa.tipo_archivo_id = ta.id
                        WHERE aa.id = :id
                    ");
                    $stmtArchivo->bindParam(':id', $archivo['id']);
                    $stmtArchivo->execute();
                    $archivoCompleto = $stmtArchivo->fetch();

                    // Marcar como procesando
                    $db->prepare("
                        UPDATE actividades_archivos 
                        SET estado_extraccion = 'procesando'
                        WHERE id = :id
                    ")->execute([':id' => $archivo['id']]);

                    // Obtener path completo
                    $archivoPath = __DIR__ . '/../' . $archivoCompleto['archivo_url'];

                    // Extraer texto
                    $textoExtraido = ExtractorService::extraerTexto($archivoPath, $archivoCompleto['tipo_codigo']);

                    if ($textoExtraido) {
                        // Generar embedding
                        $embeddingResult = EmbeddingsService::generar($textoExtraido);

                        // Actualizar archivo
                        $stmtUpdate = $db->prepare("
                            UPDATE actividades_archivos 
                            SET texto_extraido = :texto,
                                embeddings = :embeddings,
                                modelo_extraccion_id = :modelo_id,
                                procesado = 1,
                                estado_extraccion = 'completado',
                                fecha_procesamiento = NOW()
                            WHERE id = :id
                        ");
                        $stmtUpdate->execute([
                            ':texto' => $textoExtraido,
                            ':embeddings' => json_encode($embeddingResult['vector']),
                            ':modelo_id' => $embeddingResult['modelo_id'],
                            ':id' => $archivo['id']
                        ]);

                        $procesados++;
                    } else {
                        // No se pudo extraer texto
                        $db->prepare("
                            UPDATE actividades_archivos 
                            SET estado_extraccion = 'error',
                                fecha_procesamiento = NOW()
                            WHERE id = :id
                        ")->execute([':id' => $archivo['id']]);

                        $errores++;
                    }
                } catch (Exception $e) {
                    error_log("Error procesando archivo {$archivo['id']}: " . $e->getMessage());

                    // Marcar como error
                    $db->prepare("
                        UPDATE actividades_archivos 
                        SET estado_extraccion = 'error',
                            fecha_procesamiento = NOW()
                        WHERE id = :id
                    ")->execute([':id' => $archivo['id']]);

                    $errores++;
                }
            }

            responderJSON([
                'success' => true,
                'procesados' => $procesados,
                'errores' => $errores,
                'message' => "Se procesaron $procesados archivos con $errores errores"
            ]);
        } catch (Exception $e) {
            error_log("Error procesando pendientes: " . $e->getMessage());
            responderJSON(['error' => 'Error al procesar archivos pendientes'], 500);
        }
    }

    // Determinar tipo de archivo basado en extensión
    private static function determinarTipoArchivo($extension)
    {
        $db = Flight::db();

        // Obtener tipos de archivo de la BD
        $stmt = $db->prepare("SELECT id, codigo FROM tipos_archivo WHERE activo = 1");
        $stmt->execute();
        $tipos = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Mapeo de extensiones a códigos de tipo
        $mapeo = [
            'pdf' => 'documento',
            'doc' => 'documento',
            'docx' => 'documento',
            'txt' => 'documento',
            'odt' => 'documento',
            'jpg' => 'imagen',
            'jpeg' => 'imagen',
            'png' => 'imagen',
            'gif' => 'imagen',
            'bmp' => 'imagen',
            'webp' => 'imagen',
            'mp3' => 'audio',
            'wav' => 'audio',
            'ogg' => 'audio',
            'm4a' => 'audio',
            'mp4' => 'video',
            'avi' => 'video',
            'mov' => 'video',
            'wmv' => 'video',
            'xls' => 'hoja_calculo',
            'xlsx' => 'hoja_calculo',
            'csv' => 'hoja_calculo',
            'ods' => 'hoja_calculo',
            'ppt' => 'presentacion',
            'pptx' => 'presentacion',
            'odp' => 'presentacion',
        ];

        $codigo = $mapeo[strtolower($extension)] ?? 'otro';

        // Buscar ID del tipo
        foreach ($tipos as $id => $codigoTipo) {
            if ($codigoTipo === $codigo) {
                return $id;
            }
        }

        // Si no se encuentra, retornar el ID de 'otro'
        foreach ($tipos as $id => $codigoTipo) {
            if ($codigoTipo === 'otro') {
                return $id;
            }
        }

        return null;
    }
    /**
     * Limpiar nombre para uso en rutas de archivos
     * Maneja correctamente caracteres especiales del español
     */
    private static function limpiarNombreParaRuta($nombre)
    {
        // Tabla de conversión de caracteres especiales
        $conversiones = [
            // Vocales con tilde
            'á' => 'a',
            'Á' => 'A',
            'é' => 'e',
            'É' => 'E',
            'í' => 'i',
            'Í' => 'I',
            'ó' => 'o',
            'Ó' => 'O',
            'ú' => 'u',
            'Ú' => 'U',
            'ü' => 'u',
            'Ü' => 'U',

            // Eñes
            'ñ' => 'n',
            'Ñ' => 'N',

            // Otros caracteres comunes
            'ç' => 'c',
            'Ç' => 'C',
            ' ' => '_',  // Espacios a guiones bajos
            '-' => '_',  // Guiones a guiones bajos para consistencia
            '.' => '',   // Eliminar puntos
            ',' => '',   // Eliminar comas
            ';' => '',   // Eliminar punto y coma
            ':' => '',   // Eliminar dos puntos
            '(' => '',   // Eliminar paréntesis
            ')' => '',
            '[' => '',   // Eliminar corchetes
            ']' => '',
            '{' => '',   // Eliminar llaves
            '}' => '',
            '/' => '_',  // Barras a guiones bajos
            '\\' => '_', // Backslash a guiones bajos
            '"' => '',   // Eliminar comillas
            "'" => '',   // Eliminar apóstrofes
            '&' => 'y',  // Ampersand a 'y'
            '@' => 'a',  // Arroba a 'a'
            '#' => '',   // Eliminar numeral
            '$' => '',   // Eliminar signo de dólar
            '%' => '',   // Eliminar porcentaje
            '^' => '',   // Eliminar circunflejo
            '*' => '',   // Eliminar asterisco
            '!' => '',   // Eliminar exclamación
            '¡' => '',   // Eliminar exclamación invertida
            '?' => '',   // Eliminar interrogación
            '¿' => '',   // Eliminar interrogación invertida
        ];

        // Aplicar conversiones
        $nombreLimpio = strtr($nombre, $conversiones);

        // Convertir a minúsculas
        $nombreLimpio = strtolower($nombreLimpio);

        // Eliminar cualquier caracter no alfanumérico que haya quedado
        $nombreLimpio = preg_replace('/[^a-z0-9_-]/', '', $nombreLimpio);

        // Eliminar guiones bajos múltiples
        $nombreLimpio = preg_replace('/_+/', '_', $nombreLimpio);

        // Eliminar guiones bajos al inicio y final
        $nombreLimpio = trim($nombreLimpio, '_');

        // Si queda vacío, usar un nombre genérico
        if (empty($nombreLimpio)) {
            $nombreLimpio = 'sin_nombre';
        }

        // Limitar longitud (sistemas de archivos tienen límites)
        $nombreLimpio = substr($nombreLimpio, 0, 50);

        return $nombreLimpio;
    }
}
