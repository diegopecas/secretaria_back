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
            // Guardar archivo usando StorageManager
            $archivoInfo = $storage->guardarArchivo($file, 'actividades', (string)$actividadId);
            
            // Determinar tipo_archivo_id basado en la extensión
            $tipo_archivo_id = self::determinarTipoArchivo($archivoInfo['extension']);
            
            // Insertar en la BD
            $sql = "INSERT INTO actividades_archivos (
                    actividad_id,
                    nombre_archivo,
                    archivo_url,
                    tipo_archivo_id,
                    mime_type,
                    tamanio_bytes,
                    hash_archivo,
                    usuario_carga_id
                ) VALUES (
                    :actividad_id,
                    :nombre_archivo,
                    :archivo_url,
                    :tipo_archivo_id,
                    :mime_type,
                    :tamanio_bytes,
                    :hash_archivo,
                    :usuario_carga_id
                )";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':actividad_id', $actividadId);
            $stmt->bindParam(':nombre_archivo', $archivoInfo['nombre_original']);
            $stmt->bindParam(':archivo_url', $archivoInfo['path']);
            $stmt->bindParam(':tipo_archivo_id', $tipo_archivo_id);
            $stmt->bindParam(':mime_type', $archivoInfo['mime_type']);
            $stmt->bindParam(':tamanio_bytes', $archivoInfo['size']);
            $stmt->bindParam(':hash_archivo', $archivoInfo['hash']);
            $stmt->bindParam(':usuario_carga_id', $usuarioId);
            $stmt->execute();
            
            $archivoInfo['id'] = $db->lastInsertId();
            $archivoInfo['tipo_archivo_id'] = $tipo_archivo_id;
            
            error_log("Archivo guardado: {$archivoInfo['nombre_original']} para actividad $actividadId");
            
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
                    u.nombre as usuario_carga_nombre
                FROM actividades_archivos aa
                LEFT JOIN tipos_archivo ta ON aa.tipo_archivo_id = ta.id
                LEFT JOIN usuarios u ON aa.usuario_carga_id = u.id
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
            $db = Flight::db();
            
            $sql = "SELECT 
                    aa.*,
                    ra.contrato_id,
                    c.contratista_id,
                    ct.email as contratista_email
                FROM actividades_archivos aa
                INNER JOIN registro_actividades ra ON aa.actividad_id = ra.id
                INNER JOIN contratos c ON ra.contrato_id = c.id
                INNER JOIN contratistas ct ON c.contratista_id = ct.id
                WHERE aa.id = :archivo_id";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':archivo_id', $archivoId);
            $stmt->execute();
            
            return $stmt->fetch();
            
        } catch (Exception $e) {
            throw new Exception('Error al obtener archivo: ' . $e->getMessage());
        }
    }
    
    // Eliminar un archivo
    public static function eliminar($archivoId, $verificarAcceso = true)
    {
        try {
            $db = Flight::db();
            
            // Obtener información del archivo
            $archivo = self::obtenerPorId($archivoId);
            
            if (!$archivo) {
                throw new Exception('Archivo no encontrado');
            }
            
            // Verificar acceso si es necesario
            if ($verificarAcceso) {
                $currentUser = Flight::get('currentUser');
                $rolesUsuario = AuthService::getUserRoles($currentUser['id']);
                $esUsuarioBasico = in_array('usuario', array_column($rolesUsuario, 'codigo'));
                
                if ($esUsuarioBasico && $archivo['contratista_email'] !== $currentUser['email']) {
                    throw new Exception('No tiene acceso a este archivo');
                }
            }
            
            // Eliminar archivo físico
            require_once __DIR__ . '/../providers/storage/storage.manager.php';
            $storage = StorageManager::getInstance();
            
            try {
                $storage->eliminarArchivo($archivo['archivo_url']);
            } catch (Exception $e) {
                error_log("Error eliminando archivo físico: " . $e->getMessage());
                // Continuar con la eliminación del registro
            }
            
            // Eliminar registro de la BD
            $stmt = $db->prepare("DELETE FROM actividades_archivos WHERE id = :archivo_id");
            $stmt->bindParam(':archivo_id', $archivoId);
            $stmt->execute();
            
            // Auditoría
            AuditService::registrar('actividades_archivos', $archivoId, 'DELETE', $archivo, null);
            
            return true;
            
        } catch (Exception $e) {
            throw new Exception('Error al eliminar archivo: ' . $e->getMessage());
        }
    }
    
    // Eliminar todos los archivos de una actividad
    public static function eliminarPorActividad($actividadId, $db = null)
    {
        $transaccionLocal = false;
        
        try {
            if (!$db) {
                $db = Flight::db();
                $transaccionLocal = true;
            }
            
            // Obtener archivos antes de eliminar
            $archivos = self::obtenerPorActividad($actividadId);
            
            // Eliminar archivos físicos
            require_once __DIR__ . '/../providers/storage/storage.manager.php';
            $storage = StorageManager::getInstance();
            
            foreach ($archivos as $archivo) {
                try {
                    $storage->eliminarArchivo($archivo['archivo_url']);
                } catch (Exception $e) {
                    error_log("Error eliminando archivo físico: " . $e->getMessage());
                }
            }
            
            // Eliminar registros de la BD
            $stmt = $db->prepare("DELETE FROM actividades_archivos WHERE actividad_id = :actividad_id");
            $stmt->bindParam(':actividad_id', $actividadId);
            $stmt->execute();
            
            return true;
            
        } catch (Exception $e) {
            throw new Exception('Error al eliminar archivos: ' . $e->getMessage());
        }
    }
    
    // Procesar archivo para extracción de texto (para IA)
    public static function procesarParaIA($archivoId)
    {
        try {
            $db = Flight::db();
            
            // Obtener información del archivo
            $stmt = $db->prepare("SELECT * FROM actividades_archivos WHERE id = :archivo_id");
            $stmt->bindParam(':archivo_id', $archivoId);
            $stmt->execute();
            $archivo = $stmt->fetch();
            
            if (!$archivo) {
                throw new Exception('Archivo no encontrado');
            }
            
            // TODO: Implementar extracción de texto según tipo
            // - PDFs: usar librería PDF
            // - Imágenes: usar OCR
            // - Documentos Office: usar librerías específicas
            
            // Por ahora, marcar como procesado
            $stmt = $db->prepare("
                UPDATE actividades_archivos 
                SET procesado = 1, 
                    fecha_procesamiento = NOW() 
                WHERE id = :archivo_id
            ");
            $stmt->bindParam(':archivo_id', $archivoId);
            $stmt->execute();
            
            return true;
            
        } catch (Exception $e) {
            throw new Exception('Error al procesar archivo: ' . $e->getMessage());
        }
    }
    
    // Buscar archivos por contenido (cuando esté implementado el texto extraído)
    public static function buscarPorContenido($contratoId, $busqueda)
    {
        try {
            $db = Flight::db();
            
            $sql = "SELECT 
                    aa.*,
                    ra.fecha_actividad,
                    ra.descripcion_actividad,
                    MATCH(aa.texto_extraido) AGAINST(:busqueda IN NATURAL LANGUAGE MODE) as relevancia
                FROM actividades_archivos aa
                INNER JOIN registro_actividades ra ON aa.actividad_id = ra.id
                WHERE ra.contrato_id = :contrato_id
                AND aa.procesado = 1
                AND MATCH(aa.texto_extraido) AGAINST(:busqueda2 IN NATURAL LANGUAGE MODE)
                ORDER BY relevancia DESC
                LIMIT 20";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':contrato_id', $contratoId);
            $stmt->bindParam(':busqueda', $busqueda);
            $stmt->bindParam(':busqueda2', $busqueda);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            throw new Exception('Error al buscar archivos: ' . $e->getMessage());
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
            'pdf' => 'documento', 'doc' => 'documento', 'docx' => 'documento', 'txt' => 'documento', 'odt' => 'documento',
            'jpg' => 'imagen', 'jpeg' => 'imagen', 'png' => 'imagen', 'gif' => 'imagen', 'bmp' => 'imagen', 'webp' => 'imagen',
            'mp3' => 'audio', 'wav' => 'audio', 'ogg' => 'audio', 'm4a' => 'audio',
            'mp4' => 'video', 'avi' => 'video', 'mov' => 'video', 'wmv' => 'video',
            'xls' => 'hoja_calculo', 'xlsx' => 'hoja_calculo', 'csv' => 'hoja_calculo', 'ods' => 'hoja_calculo',
            'ppt' => 'presentacion', 'pptx' => 'presentacion', 'odp' => 'presentacion',
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
    
    // Obtener estadísticas de archivos
    public static function obtenerEstadisticas($contratoId = null)
    {
        try {
            $db = Flight::db();
            
            $sql = "SELECT 
                    COUNT(*) as total_archivos,
                    SUM(tamanio_bytes) as espacio_total,
                    COUNT(DISTINCT actividad_id) as actividades_con_archivos,
                    ta.nombre as tipo_archivo,
                    COUNT(*) as cantidad_por_tipo
                FROM actividades_archivos aa
                INNER JOIN registro_actividades ra ON aa.actividad_id = ra.id
                LEFT JOIN tipos_archivo ta ON aa.tipo_archivo_id = ta.id";
            
            if ($contratoId) {
                $sql .= " WHERE ra.contrato_id = :contrato_id";
            }
            
            $sql .= " GROUP BY ta.id, ta.nombre";
            
            $stmt = $db->prepare($sql);
            if ($contratoId) {
                $stmt->bindParam(':contrato_id', $contratoId);
            }
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            throw new Exception('Error al obtener estadísticas: ' . $e->getMessage());
        }
    }
}