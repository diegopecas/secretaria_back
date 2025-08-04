<?php
require_once __DIR__ . '/embeddings.service.php';

class ProyectosService
{
    /**
     * Generar embeddings para proyectos de un contrato
     */
    public static function generarEmbeddings($contratoId)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos'], 403);
                return;
            }
            
            $db = Flight::db();
            
            // Obtener proyectos sin procesar del contrato
            $stmt = $db->prepare("
                SELECT id, numero_proyecto, titulo, descripcion
                FROM proyectos_contractuales
                WHERE contrato_id = :contrato_id
                AND (procesado = 0 OR procesado IS NULL)
                AND activo = 1
            ");
            $stmt->bindParam(':contrato_id', $contratoId);
            $stmt->execute();
            
            $procesados = 0;
            $errores = 0;
            
            while ($proyecto = $stmt->fetch()) {
                try {
                    // Construir texto para embedding incluyendo título
                    $texto = "Proyecto {$proyecto['numero_proyecto']} - {$proyecto['titulo']}: {$proyecto['descripcion']}";
                    
                    // Generar embedding usando el modelo del contrato
                    $embeddingResult = EmbeddingsService::generar($texto, $contratoId);
                    
                    // Actualizar proyecto
                    $update = $db->prepare("
                        UPDATE proyectos_contractuales
                        SET embeddings = :embeddings,
                            procesado = 1,
                            fecha_procesamiento = NOW()
                        WHERE id = :id
                    ");
                    $update->bindParam(':embeddings', json_encode($embeddingResult['vector']));
                    $update->bindParam(':id', $proyecto['id']);
                    $update->execute();
                    
                    $procesados++;
                    
                    error_log("Embedding generado para proyecto {$proyecto['id']}");
                    
                } catch (Exception $e) {
                    error_log("Error procesando proyecto {$proyecto['id']}: " . $e->getMessage());
                    $errores++;
                }
            }
            
            responderJSON([
                'success' => true,
                'procesados' => $procesados,
                'errores' => $errores,
                'message' => "Se procesaron $procesados proyectos con $errores errores"
            ]);
            
        } catch (Exception $e) {
            error_log("Error generando embeddings de proyectos: " . $e->getMessage());
            responderJSON(['error' => 'Error al procesar proyectos'], 500);
        }
    }
    
    /**
     * Buscar proyectos por similitud semántica
     */
    public static function buscarPorSimilitud($embeddingPregunta, $contratoId, $limite = 10)
    {
        try {
            $db = Flight::db();
            
            // Obtener proyectos con embeddings
            $stmt = $db->prepare("
                SELECT 
                    id,
                    numero_proyecto,
                    titulo,
                    descripcion,
                    embeddings
                FROM proyectos_contractuales
                WHERE contrato_id = :contrato_id
                AND embeddings IS NOT NULL
                AND procesado = 1
                AND activo = 1
                ORDER BY numero_proyecto
            ");
            $stmt->bindParam(':contrato_id', $contratoId);
            $stmt->execute();
            
            $proyectos = [];
            while ($row = $stmt->fetch()) {
                $similitud = EmbeddingsService::calcularSimilitud($embeddingPregunta, $row['embeddings']);
                $proyectos[] = [
                    'id' => $row['id'],
                    'numero_proyecto' => $row['numero_proyecto'],
                    'titulo' => $row['titulo'],
                    'descripcion' => $row['descripcion'],
                    'similitud' => $similitud
                ];
            }
            
            // Ordenar por similitud descendente
            usort($proyectos, function($a, $b) {
                return $b['similitud'] <=> $a['similitud'];
            });
            
            // Retornar solo el límite solicitado
            return array_slice($proyectos, 0, $limite);
            
        } catch (Exception $e) {
            error_log("Error buscando proyectos por similitud: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Procesar proyectos pendientes (batch)
     */
    public static function procesarPendientes()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'sistema.configurar')) {
                responderJSON(['error' => 'No tiene permisos'], 403);
                return;
            }
            
            $limite = Flight::request()->query['limite'] ?? 50;
            
            $db = Flight::db();
            
            // Obtener proyectos pendientes con su contrato
            $stmt = $db->prepare("
                SELECT 
                    pc.id,
                    pc.numero_proyecto,
                    pc.titulo,
                    pc.descripcion,
                    pc.contrato_id
                FROM proyectos_contractuales pc
                INNER JOIN contratos c ON pc.contrato_id = c.id
                WHERE (pc.procesado = 0 OR pc.procesado IS NULL)
                AND pc.activo = 1
                AND c.embeddings_modelo_id IS NOT NULL
                ORDER BY pc.contrato_id, pc.numero_proyecto
                LIMIT :limite
            ");
            $stmt->bindParam(':limite', $limite, PDO::PARAM_INT);
            $stmt->execute();
            
            $procesados = 0;
            $errores = 0;
            $contratoActual = null;
            
            while ($proyecto = $stmt->fetch()) {
                try {
                    // Log si cambiamos de contrato
                    if ($contratoActual !== $proyecto['contrato_id']) {
                        $contratoActual = $proyecto['contrato_id'];
                        error_log("Procesando proyectos del contrato ID: $contratoActual");
                    }
                    
                    // Construir texto incluyendo título
                    $texto = "Proyecto {$proyecto['numero_proyecto']} - {$proyecto['titulo']}: {$proyecto['descripcion']}";
                    
                    // Generar embedding
                    $embeddingResult = EmbeddingsService::generar($texto, $proyecto['contrato_id']);
                    
                    // Actualizar
                    $update = $db->prepare("
                        UPDATE proyectos_contractuales
                        SET embeddings = :embeddings,
                            procesado = 1,
                            fecha_procesamiento = NOW()
                        WHERE id = :id
                    ");
                    $update->execute([
                        ':embeddings' => json_encode($embeddingResult['vector']),
                        ':id' => $proyecto['id']
                    ]);
                    
                    $procesados++;
                    
                } catch (Exception $e) {
                    error_log("Error procesando proyecto {$proyecto['id']}: " . $e->getMessage());
                    $errores++;
                }
            }
            
            responderJSON([
                'success' => true,
                'procesados' => $procesados,
                'errores' => $errores,
                'message' => "Se procesaron $procesados proyectos con $errores errores"
            ]);
            
        } catch (Exception $e) {
            error_log("Error procesando proyectos pendientes: " . $e->getMessage());
            responderJSON(['error' => 'Error al procesar pendientes'], 500);
        }
    }
}