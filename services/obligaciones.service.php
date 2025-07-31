<?php
require_once __DIR__ . '/embeddings.service.php';

class ObligacionesService
{
    /**
     * Generar embeddings para obligaciones de un contrato
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
            
            // Obtener obligaciones sin procesar del contrato
            $stmt = $db->prepare("
                SELECT id, numero_obligacion, descripcion
                FROM obligaciones_contractuales
                WHERE contrato_id = :contrato_id
                AND (procesado = 0 OR procesado IS NULL)
                AND activo = 1
            ");
            $stmt->bindParam(':contrato_id', $contratoId);
            $stmt->execute();
            
            $procesadas = 0;
            $errores = 0;
            
            while ($obligacion = $stmt->fetch()) {
                try {
                    // Construir texto para embedding
                    $texto = "Obligación {$obligacion['numero_obligacion']}: {$obligacion['descripcion']}";
                    
                    // Generar embedding usando el modelo del contrato
                    $embeddingResult = EmbeddingsService::generar($texto, $contratoId);
                    
                    // Actualizar obligación
                    $update = $db->prepare("
                        UPDATE obligaciones_contractuales
                        SET embeddings = :embeddings,
                            procesado = 1,
                            fecha_procesamiento = NOW()
                        WHERE id = :id
                    ");
                    $update->bindParam(':embeddings', json_encode($embeddingResult['vector']));
                    $update->bindParam(':id', $obligacion['id']);
                    $update->execute();
                    
                    $procesadas++;
                    
                    error_log("Embedding generado para obligación {$obligacion['id']}");
                    
                } catch (Exception $e) {
                    error_log("Error procesando obligación {$obligacion['id']}: " . $e->getMessage());
                    $errores++;
                }
            }
            
            responderJSON([
                'success' => true,
                'procesadas' => $procesadas,
                'errores' => $errores,
                'message' => "Se procesaron $procesadas obligaciones con $errores errores"
            ]);
            
        } catch (Exception $e) {
            error_log("Error generando embeddings de obligaciones: " . $e->getMessage());
            responderJSON(['error' => 'Error al procesar obligaciones'], 500);
        }
    }
    
    /**
     * Buscar obligaciones por similitud semántica
     */
    public static function buscarPorSimilitud($embeddingPregunta, $contratoId, $limite = 10)
    {
        try {
            $db = Flight::db();
            
            // Obtener obligaciones con embeddings
            $stmt = $db->prepare("
                SELECT 
                    id,
                    numero_obligacion,
                    descripcion,
                    embeddings
                FROM obligaciones_contractuales
                WHERE contrato_id = :contrato_id
                AND embeddings IS NOT NULL
                AND procesado = 1
                AND activo = 1
                ORDER BY numero_obligacion
            ");
            $stmt->bindParam(':contrato_id', $contratoId);
            $stmt->execute();
            
            $obligaciones = [];
            while ($row = $stmt->fetch()) {
                $similitud = EmbeddingsService::calcularSimilitud($embeddingPregunta, $row['embeddings']);
                $obligaciones[] = [
                    'id' => $row['id'],
                    'numero_obligacion' => $row['numero_obligacion'],
                    'descripcion' => $row['descripcion'],
                    'similitud' => $similitud
                ];
            }
            
            // Ordenar por similitud descendente
            usort($obligaciones, function($a, $b) {
                return $b['similitud'] <=> $a['similitud'];
            });
            
            // Retornar solo el límite solicitado
            return array_slice($obligaciones, 0, $limite);
            
        } catch (Exception $e) {
            error_log("Error buscando obligaciones por similitud: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Procesar obligaciones pendientes (batch)
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
            
            // Obtener obligaciones pendientes con su contrato
            $stmt = $db->prepare("
                SELECT 
                    oc.id,
                    oc.numero_obligacion,
                    oc.descripcion,
                    oc.contrato_id
                FROM obligaciones_contractuales oc
                INNER JOIN contratos c ON oc.contrato_id = c.id
                WHERE (oc.procesado = 0 OR oc.procesado IS NULL)
                AND oc.activo = 1
                AND c.embeddings_modelo_id IS NOT NULL
                ORDER BY oc.contrato_id, oc.numero_obligacion
                LIMIT :limite
            ");
            $stmt->bindParam(':limite', $limite, PDO::PARAM_INT);
            $stmt->execute();
            
            $procesadas = 0;
            $errores = 0;
            $contratoActual = null;
            
            while ($obligacion = $stmt->fetch()) {
                try {
                    // Log si cambiamos de contrato
                    if ($contratoActual !== $obligacion['contrato_id']) {
                        $contratoActual = $obligacion['contrato_id'];
                        error_log("Procesando obligaciones del contrato ID: $contratoActual");
                    }
                    
                    // Construir texto
                    $texto = "Obligación {$obligacion['numero_obligacion']}: {$obligacion['descripcion']}";
                    
                    // Generar embedding
                    $embeddingResult = EmbeddingsService::generar($texto, $obligacion['contrato_id']);
                    
                    // Actualizar
                    $update = $db->prepare("
                        UPDATE obligaciones_contractuales
                        SET embeddings = :embeddings,
                            procesado = 1,
                            fecha_procesamiento = NOW()
                        WHERE id = :id
                    ");
                    $update->execute([
                        ':embeddings' => json_encode($embeddingResult['vector']),
                        ':id' => $obligacion['id']
                    ]);
                    
                    $procesadas++;
                    
                } catch (Exception $e) {
                    error_log("Error procesando obligación {$obligacion['id']}: " . $e->getMessage());
                    $errores++;
                }
            }
            
            responderJSON([
                'success' => true,
                'procesadas' => $procesadas,
                'errores' => $errores,
                'message' => "Se procesaron $procesadas obligaciones con $errores errores"
            ]);
            
        } catch (Exception $e) {
            error_log("Error procesando obligaciones pendientes: " . $e->getMessage());
            responderJSON(['error' => 'Error al procesar pendientes'], 500);
        }
    }
}