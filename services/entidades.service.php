<?php
class EntidadesService
{
    // Obtener todas las entidades
    public static function obtenerTodos()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para ver entidades'], 403);
                return;
            }
            
            $db = Flight::db();
            
            $sentence = $db->prepare("
                SELECT 
                    e.id,
                    e.nombre,
                    e.nombre_corto,
                    e.tipo_identificacion_id,
                    ti.codigo as tipo_identificacion_codigo,
                    ti.nombre as tipo_identificacion_nombre,
                    e.identificacion,
                    e.direccion,
                    e.telefono,
                    e.email,
                    e.descripcion,
                    e.activo,
                    (SELECT COUNT(*) FROM contratos WHERE entidad_id = e.id) as total_contratos,
                    (SELECT COUNT(*) FROM contratos WHERE entidad_id = e.id AND estado = 'activo') as contratos_activos
                FROM entidades e
                INNER JOIN tipos_identificacion ti ON e.tipo_identificacion_id = ti.id
                ORDER BY e.nombre ASC
            ");
            
            $sentence->execute();
            $entidades = $sentence->fetchAll(PDO::FETCH_ASSOC);
            
            responderJSON($entidades);
            
        } catch (Exception $e) {
            error_log("ERROR en obtenerTodos entidades: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener entidades'], 500);
        }
    }
    
    // Obtener entidad por ID
    public static function obtenerPorId($id)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para ver entidades'], 403);
                return;
            }
            
            $db = Flight::db();
            
            $sentence = $db->prepare("
                SELECT 
                    e.id,
                    e.nombre,
                    e.nombre_corto,
                    e.tipo_identificacion_id,
                    ti.codigo as tipo_identificacion_codigo,
                    ti.nombre as tipo_identificacion_nombre,
                    e.identificacion,
                    e.direccion,
                    e.telefono,
                    e.email,
                    e.descripcion,
                    e.activo
                FROM entidades e
                INNER JOIN tipos_identificacion ti ON e.tipo_identificacion_id = ti.id
                WHERE e.id = :id
            ");
            $sentence->bindParam(':id', $id);
            $sentence->execute();
            
            $entidad = $sentence->fetch(PDO::FETCH_ASSOC);
            
            if (!$entidad) {
                responderJSON(['error' => 'Entidad no encontrada'], 404);
                return;
            }
            
            // Obtener contratos de la entidad
            $stmtContratos = $db->prepare("
                SELECT 
                    c.id,
                    c.numero_contrato,
                    c.fecha_inicio,
                    c.fecha_terminacion,
                    c.valor_total,
                    c.estado,
                    ct.nombre_completo as contratista_nombre
                FROM contratos c
                INNER JOIN contratistas ct ON c.contratista_id = ct.id
                WHERE c.entidad_id = :entidad_id
                ORDER BY c.fecha_inicio DESC
            ");
            $stmtContratos->bindParam(':entidad_id', $id);
            $stmtContratos->execute();
            
            $entidad['contratos'] = $stmtContratos->fetchAll(PDO::FETCH_ASSOC);
            
            responderJSON($entidad);
            
        } catch (Exception $e) {
            error_log("ERROR en obtenerPorId entidad: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener entidad'], 500);
        }
    }
    
    // Crear entidad
    public static function crear()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para crear entidades'], 403);
                return;
            }
            
            $data = Flight::request()->data->getData();
            
            // Validaciones
            $nombre = $data['nombre'] ?? null;
            $nombre_corto = $data['nombre_corto'] ?? null;
            $tipo_identificacion_id = $data['tipo_identificacion_id'] ?? null;
            $identificacion = $data['identificacion'] ?? null;
            $direccion = $data['direccion'] ?? null;
            $telefono = $data['telefono'] ?? null;
            $email = $data['email'] ?? null;
            $descripcion = $data['descripcion'] ?? null;
            $activo = isset($data['activo']) ? (bool)$data['activo'] : true;
            
            if (!$nombre || !$tipo_identificacion_id || !$identificacion) {
                responderJSON(['error' => 'Datos incompletos'], 400);
                return;
            }
            
            $db = Flight::db();
            
            // Verificar que no exista una entidad con la misma identificación
            $checkSentence = $db->prepare("
                SELECT id FROM entidades 
                WHERE identificacion = :identificacion
            ");
            $checkSentence->bindParam(':identificacion', $identificacion);
            $checkSentence->execute();
            
            if ($checkSentence->fetch()) {
                responderJSON(['error' => 'Ya existe una entidad con esa identificación'], 400);
                return;
            }
            
            // Insertar entidad
            $sentence = $db->prepare("
                INSERT INTO entidades (
                    nombre,
                    nombre_corto,
                    tipo_identificacion_id,
                    identificacion,
                    direccion,
                    telefono,
                    email,
                    descripcion,
                    activo
                ) VALUES (
                    :nombre,
                    :nombre_corto,
                    :tipo_identificacion_id,
                    :identificacion,
                    :direccion,
                    :telefono,
                    :email,
                    :descripcion,
                    :activo
                )
            ");
            
            $sentence->bindParam(':nombre', $nombre);
            $sentence->bindParam(':nombre_corto', $nombre_corto);
            $sentence->bindParam(':tipo_identificacion_id', $tipo_identificacion_id);
            $sentence->bindParam(':identificacion', $identificacion);
            $sentence->bindParam(':direccion', $direccion);
            $sentence->bindParam(':telefono', $telefono);
            $sentence->bindParam(':email', $email);
            $sentence->bindParam(':descripcion', $descripcion);
            $sentence->bindParam(':activo', $activo, PDO::PARAM_BOOL);
            
            $sentence->execute();
            $entidadId = $db->lastInsertId();
            
            // AUDITORÍA
            $datosNuevos = [
                'id' => $entidadId,
                'nombre' => $nombre,
                'nombre_corto' => $nombre_corto,
                'tipo_identificacion_id' => $tipo_identificacion_id,
                'identificacion' => $identificacion,
                'direccion' => $direccion,
                'telefono' => $telefono,
                'email' => $email,
                'descripcion' => $descripcion,
                'activo' => $activo
            ];
            
            AuditService::registrar('entidades', $entidadId, 'CREATE', null, $datosNuevos);
            
            responderJSON([
                'success' => true,
                'id' => $entidadId,
                'message' => 'Entidad creada correctamente'
            ]);
            
        } catch (Exception $e) {
            error_log("ERROR en crear entidad: " . $e->getMessage());
            responderJSON(['error' => 'Error al crear entidad'], 500);
        }
    }
    
    // Actualizar entidad
    public static function actualizar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para editar entidades'], 403);
                return;
            }
            
            $data = Flight::request()->data->getData();
            $id = $data['id'] ?? null;
            
            if (!$id) {
                responderJSON(['error' => 'ID no proporcionado'], 400);
                return;
            }
            
            $db = Flight::db();
            
            // Obtener datos anteriores para auditoría
            $stmtAnterior = $db->prepare("SELECT * FROM entidades WHERE id = :id");
            $stmtAnterior->bindParam(':id', $id);
            $stmtAnterior->execute();
            $datosAnteriores = $stmtAnterior->fetch(PDO::FETCH_ASSOC);
            
            if (!$datosAnteriores) {
                responderJSON(['error' => 'Entidad no encontrada'], 404);
                return;
            }
            
            // Construir query dinámicamente
            $updates = [];
            $params = [':id' => $id];
            
            if (isset($data['nombre'])) {
                $updates[] = "nombre = :nombre";
                $params[':nombre'] = $data['nombre'];
            }
            
            if (isset($data['nombre_corto'])) {
                $updates[] = "nombre_corto = :nombre_corto";
                $params[':nombre_corto'] = $data['nombre_corto'];
            }
            
            if (isset($data['tipo_identificacion_id'])) {
                $updates[] = "tipo_identificacion_id = :tipo_identificacion_id";
                $params[':tipo_identificacion_id'] = $data['tipo_identificacion_id'];
            }
            
            if (isset($data['identificacion'])) {
                // Verificar que no exista otra entidad con la misma identificación
                if ($data['identificacion'] !== $datosAnteriores['identificacion']) {
                    $checkSentence = $db->prepare("
                        SELECT id FROM entidades 
                        WHERE identificacion = :identificacion 
                        AND id != :id
                    ");
                    $checkSentence->bindParam(':identificacion', $data['identificacion']);
                    $checkSentence->bindParam(':id', $id);
                    $checkSentence->execute();
                    
                    if ($checkSentence->fetch()) {
                        responderJSON(['error' => 'Ya existe una entidad con esa identificación'], 400);
                        return;
                    }
                }
                
                $updates[] = "identificacion = :identificacion";
                $params[':identificacion'] = $data['identificacion'];
            }
            
            if (isset($data['direccion'])) {
                $updates[] = "direccion = :direccion";
                $params[':direccion'] = $data['direccion'];
            }
            
            if (isset($data['telefono'])) {
                $updates[] = "telefono = :telefono";
                $params[':telefono'] = $data['telefono'];
            }
            
            if (isset($data['email'])) {
                $updates[] = "email = :email";
                $params[':email'] = $data['email'];
            }
            
            if (isset($data['descripcion'])) {
                $updates[] = "descripcion = :descripcion";
                $params[':descripcion'] = $data['descripcion'];
            }
            
            if (isset($data['activo'])) {
                $updates[] = "activo = :activo";
                $params[':activo'] = (bool)$data['activo'];
            }
            
            if (!empty($updates)) {
                $sql = "UPDATE entidades SET " . implode(", ", $updates) . " WHERE id = :id";
                $sentence = $db->prepare($sql);
                
                foreach ($params as $key => $value) {
                    $sentence->bindValue($key, $value);
                }
                
                $sentence->execute();
                
                // Obtener datos nuevos para auditoría
                $stmtNuevo = $db->prepare("SELECT * FROM entidades WHERE id = :id");
                $stmtNuevo->bindParam(':id', $id);
                $stmtNuevo->execute();
                $datosNuevos = $stmtNuevo->fetch(PDO::FETCH_ASSOC);
                
                // AUDITORÍA
                AuditService::registrar('entidades', $id, 'UPDATE', $datosAnteriores, $datosNuevos);
            }
            
            responderJSON([
                'success' => true,
                'message' => 'Entidad actualizada correctamente'
            ]);
            
        } catch (Exception $e) {
            error_log("ERROR en actualizar entidad: " . $e->getMessage());
            responderJSON(['error' => 'Error al actualizar entidad'], 500);
        }
    }
    
    // Eliminar entidad (soft delete)
    public static function eliminar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para eliminar entidades'], 403);
                return;
            }
            
            $data = Flight::request()->data->getData();
            $id = $data['id'] ?? null;
            
            if (!$id) {
                responderJSON(['error' => 'ID no proporcionado'], 400);
                return;
            }
            
            $db = Flight::db();
            
            // Verificar si tiene contratos activos
            $checkSentence = $db->prepare("
                SELECT COUNT(*) as total 
                FROM contratos 
                WHERE entidad_id = :id 
                AND estado = 'activo'
            ");
            $checkSentence->bindParam(':id', $id);
            $checkSentence->execute();
            $result = $checkSentence->fetch();
            
            if ($result['total'] > 0) {
                responderJSON(['error' => 'No se puede eliminar una entidad con contratos activos'], 400);
                return;
            }
            
            // Obtener datos para auditoría
            $stmtAnterior = $db->prepare("SELECT * FROM entidades WHERE id = :id");
            $stmtAnterior->bindParam(':id', $id);
            $stmtAnterior->execute();
            $datosAnteriores = $stmtAnterior->fetch(PDO::FETCH_ASSOC);
            
            if (!$datosAnteriores) {
                responderJSON(['error' => 'Entidad no encontrada'], 404);
                return;
            }
            
            // Soft delete
            $sentence = $db->prepare("UPDATE entidades SET activo = 0 WHERE id = :id");
            $sentence->bindParam(':id', $id);
            $sentence->execute();
            
            // AUDITORÍA
            AuditService::registrar('entidades', $id, 'DELETE', $datosAnteriores, null);
            
            responderJSON([
                'success' => true,
                'message' => 'Entidad eliminada correctamente'
            ]);
            
        } catch (Exception $e) {
            error_log("ERROR en eliminar entidad: " . $e->getMessage());
            responderJSON(['error' => 'Error al eliminar entidad'], 500);
        }
    }
    
    // Buscar entidades
    public static function buscar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');
            
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para buscar entidades'], 403);
                return;
            }
            
            $q = Flight::request()->query['q'] ?? '';
            $activo = Flight::request()->query['activo'] ?? null;
            
            $db = Flight::db();
            
            $sql = "
                SELECT 
                    e.id,
                    e.nombre,
                    e.nombre_corto,
                    e.tipo_identificacion_id,
                    ti.codigo as tipo_identificacion_codigo,
                    e.identificacion,
                    e.telefono,
                    e.email,
                    e.activo
                FROM entidades e
                INNER JOIN tipos_identificacion ti ON e.tipo_identificacion_id = ti.id
                WHERE 1=1
            ";
            
            $params = [];
            
            if ($q) {
                $sql .= " AND (
                    e.nombre LIKE :q 
                    OR e.nombre_corto LIKE :q2
                    OR e.identificacion LIKE :q3 
                    OR e.email LIKE :q4
                )";
                $searchTerm = '%' . $q . '%';
                $params[':q'] = $searchTerm;
                $params[':q2'] = $searchTerm;
                $params[':q3'] = $searchTerm;
                $params[':q4'] = $searchTerm;
            }
            
            if ($activo !== null) {
                $sql .= " AND e.activo = :activo";
                $params[':activo'] = (bool)$activo;
            }
            
            $sql .= " ORDER BY e.nombre ASC LIMIT 50";
            
            $sentence = $db->prepare($sql);
            
            foreach ($params as $key => $value) {
                $sentence->bindValue($key, $value);
            }
            
            $sentence->execute();
            $entidades = $sentence->fetchAll(PDO::FETCH_ASSOC);
            
            responderJSON($entidades);
            
        } catch (Exception $e) {
            error_log("ERROR en buscar entidades: " . $e->getMessage());
            responderJSON(['error' => 'Error al buscar entidades'], 500);
        }
    }
}