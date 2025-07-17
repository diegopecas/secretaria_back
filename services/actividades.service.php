<?php
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
           
           // Nuevos campos de transcripción
           $transcripcion_texto = $_POST['transcripcion_texto'] ?? null;
           $transcripcion_proveedor = $_POST['transcripcion_proveedor'] ?? null;
           $transcripcion_modelo = $_POST['transcripcion_modelo'] ?? null;
           $transcripcion_confianza = $_POST['transcripcion_confianza'] ?? null;

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
                       transcripcion_texto,
                       transcripcion_proveedor,
                       transcripcion_modelo,
                       transcripcion_confianza,
                       usuario_registro_id
                   ) VALUES (
                       :contrato_id,
                       :fecha_actividad,
                       :descripcion_actividad,
                       :transcripcion_texto,
                       :transcripcion_proveedor,
                       :transcripcion_modelo,
                       :transcripcion_confianza,
                       :usuario_registro_id
                   )";

               $stmt = $db->prepare($sql);
               $stmt->bindParam(':contrato_id', $contrato_id);
               $stmt->bindParam(':fecha_actividad', $fecha_actividad);
               $stmt->bindParam(':descripcion_actividad', $descripcion_actividad);
               $stmt->bindParam(':transcripcion_texto', $transcripcion_texto);
               $stmt->bindParam(':transcripcion_proveedor', $transcripcion_proveedor);
               $stmt->bindParam(':transcripcion_modelo', $transcripcion_modelo);
               $stmt->bindParam(':transcripcion_confianza', $transcripcion_confianza);
               $stmt->bindParam(':usuario_registro_id', $currentUser['id']);
               $stmt->execute();

               $actividadId = $db->lastInsertId();

               // Delegar asignación de obligaciones
               if (!empty($obligaciones)) {
                   require_once __DIR__ . '/actividades-obligaciones.service.php';
                   ActividadesObligacionesService::asignar($actividadId, $obligaciones, $db);
               }

               // Delegar procesamiento de archivos
               if (!empty($_FILES)) {
                   require_once __DIR__ . '/actividades-archivos.service.php';
                   ActividadesArchivosService::agregar($actividadId, $_FILES, $currentUser['id'], $db);
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

               // Campos actualizables
               $camposActualizables = [
                   'descripcion_actividad',
                   'fecha_actividad',
                   'transcripcion_texto',
                   'transcripcion_proveedor',
                   'transcripcion_modelo',
                   'transcripcion_confianza'
               ];

               foreach ($camposActualizables as $campo) {
                   if (isset($_POST[$campo])) {
                       $updates[] = "$campo = :$campo";
                       $params[":$campo"] = $_POST[$campo];
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
               if (isset($_POST['obligaciones'])) {
                   require_once __DIR__ . '/actividades-obligaciones.service.php';
                   $obligaciones = json_decode($_POST['obligaciones'], true);
                   ActividadesObligacionesService::asignar($id, $obligaciones, $db);
               }

               // Procesar nuevos archivos si se enviaron
               if (!empty($_FILES)) {
                   require_once __DIR__ . '/actividades-archivos.service.php';
                   ActividadesArchivosService::agregar($id, $_FILES, $currentUser['id'], $db);
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
}