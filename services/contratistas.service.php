<?php
class ContratistasService
{
    // Obtener todos los contratistas
    public static function obtenerTodos()
    {
        try {
            error_log("Entrando a obtenerTodos de ContratistasService");

            requireAuth();
            $currentUser = Flight::get('currentUser');

            error_log("Usuario actual: " . json_encode($currentUser));

            // Verificar que el usuario existe
            if (!$currentUser || !isset($currentUser['id'])) {
                error_log("No se encontró usuario actual");
                responderJSON(['error' => 'Usuario no autenticado'], 401);
                return;
            }

            // Verificar permisos
            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                error_log("Usuario sin permisos para contratos.gestionar");
                responderJSON(['error' => 'No tiene permisos para ver contratistas'], 403);
                return;
            }

            $db = Flight::db();

            $sentence = $db->prepare("
                SELECT 
                    c.id,
                    c.tipo_identificacion_id,
                    ti.codigo as tipo_identificacion_codigo,
                    ti.nombre as tipo_identificacion_nombre,
                    c.identificacion,
                    c.nombre_completo,
                    c.email,
                    c.telefono,
                    c.direccion,
                    c.activo,
                    (SELECT COUNT(*) FROM contratos WHERE contratista_id = c.id) as total_contratos,
                    (SELECT COUNT(*) FROM contratos WHERE contratista_id = c.id AND estado = 'activo') as contratos_activos
                FROM contratistas c
                INNER JOIN tipos_identificacion ti ON c.tipo_identificacion_id = ti.id
                ORDER BY c.nombre_completo ASC
            ");

            $sentence->execute();
            $contratistas = $sentence->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($contratistas)) {
                $contratistas = [];
            }
            error_log("Contratistas encontrados: " . count($contratistas));

            responderJSON($contratistas);
        } catch (Exception $e) {
            error_log("ERROR en obtenerTodos contratistas: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            responderJSON(['error' => 'Error al obtener contratistas'], 500);
        }
    }

    // Obtener contratista por ID
    public static function obtenerPorId($id)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para ver contratistas'], 403);
                return;
            }

            $db = Flight::db();

            $sentence = $db->prepare("
                SELECT 
                    c.id,
                    c.tipo_identificacion_id,
                    ti.codigo as tipo_identificacion_codigo,
                    ti.nombre as tipo_identificacion_nombre,
                    c.identificacion,
                    c.nombre_completo,
                    c.email,
                    c.telefono,
                    c.direccion,
                    c.activo
                FROM contratistas c
                INNER JOIN tipos_identificacion ti ON c.tipo_identificacion_id = ti.id
                WHERE c.id = :id
            ");
            $sentence->bindParam(':id', $id);
            $sentence->execute();

            $contratista = $sentence->fetch(PDO::FETCH_ASSOC);

            if (!$contratista) {
                responderJSON(['error' => 'Contratista no encontrado'], 404);
                return;
            }

            // Obtener contratos del contratista
            $stmtContratos = $db->prepare("
                SELECT 
                    c.id,
                    c.numero_contrato,
                    c.fecha_inicio,
                    c.fecha_terminacion,
                    c.valor_total,
                    c.estado,
                    e.nombre as entidad_nombre
                FROM contratos c
                INNER JOIN entidades e ON c.entidad_id = e.id
                WHERE c.contratista_id = :contratista_id
                ORDER BY c.fecha_inicio DESC
            ");
            $stmtContratos->bindParam(':contratista_id', $id);
            $stmtContratos->execute();

            $contratista['contratos'] = $stmtContratos->fetchAll(PDO::FETCH_ASSOC);

            responderJSON($contratista);
        } catch (Exception $e) {
            error_log("ERROR en obtenerPorId contratista: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener contratista'], 500);
        }
    }

    // Crear contratista
    public static function crear()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para crear contratistas'], 403);
                return;
            }

            $data = Flight::request()->data->getData();

            // Validaciones
            $tipo_identificacion_id = $data['tipo_identificacion_id'] ?? null;
            $identificacion = $data['identificacion'] ?? null;
            $nombre_completo = $data['nombre_completo'] ?? null;
            $email = $data['email'] ?? null;
            $telefono = $data['telefono'] ?? null;
            $direccion = $data['direccion'] ?? null;
            $activo = isset($data['activo']) ? (bool)$data['activo'] : true;

            if (!$tipo_identificacion_id || !$identificacion || !$nombre_completo) {
                responderJSON(['error' => 'Datos incompletos'], 400);
                return;
            }

            $db = Flight::db();

            // Verificar que no exista un contratista con la misma identificación
            $checkSentence = $db->prepare("
                SELECT id FROM contratistas 
                WHERE tipo_identificacion_id = :tipo_identificacion_id 
                AND identificacion = :identificacion
            ");
            $checkSentence->bindParam(':tipo_identificacion_id', $tipo_identificacion_id);
            $checkSentence->bindParam(':identificacion', $identificacion);
            $checkSentence->execute();

            if ($checkSentence->fetch()) {
                responderJSON(['error' => 'Ya existe un contratista con esa identificación'], 400);
                return;
            }

            // Insertar contratista
            $sentence = $db->prepare("
                INSERT INTO contratistas (
                    tipo_identificacion_id, 
                    identificacion, 
                    nombre_completo, 
                    email, 
                    telefono, 
                    direccion, 
                    activo
                ) VALUES (
                    :tipo_identificacion_id,
                    :identificacion,
                    :nombre_completo,
                    :email,
                    :telefono,
                    :direccion,
                    :activo
                )
            ");

            $sentence->bindParam(':tipo_identificacion_id', $tipo_identificacion_id);
            $sentence->bindParam(':identificacion', $identificacion);
            $sentence->bindParam(':nombre_completo', $nombre_completo);
            $sentence->bindParam(':email', $email);
            $sentence->bindParam(':telefono', $telefono);
            $sentence->bindParam(':direccion', $direccion);
            $sentence->bindParam(':activo', $activo, PDO::PARAM_BOOL);

            $sentence->execute();
            $contratistaId = $db->lastInsertId();

            // AUDITORÍA
            $datosNuevos = [
                'id' => $contratistaId,
                'tipo_identificacion_id' => $tipo_identificacion_id,
                'identificacion' => $identificacion,
                'nombre_completo' => $nombre_completo,
                'email' => $email,
                'telefono' => $telefono,
                'direccion' => $direccion,
                'activo' => $activo
            ];

            AuditService::registrar('contratistas', $contratistaId, 'CREATE', null, $datosNuevos);

            responderJSON([
                'success' => true,
                'id' => $contratistaId,
                'message' => 'Contratista creado correctamente'
            ]);
        } catch (Exception $e) {
            error_log("ERROR en crear contratista: " . $e->getMessage());
            responderJSON(['error' => 'Error al crear contratista'], 500);
        }
    }

    // Actualizar contratista
    public static function actualizar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para editar contratistas'], 403);
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
            $stmtAnterior = $db->prepare("SELECT * FROM contratistas WHERE id = :id");
            $stmtAnterior->bindParam(':id', $id);
            $stmtAnterior->execute();
            $datosAnteriores = $stmtAnterior->fetch(PDO::FETCH_ASSOC);

            if (!$datosAnteriores) {
                responderJSON(['error' => 'Contratista no encontrado'], 404);
                return;
            }

            // Construir query dinámicamente
            $updates = [];
            $params = [':id' => $id];

            if (isset($data['tipo_identificacion_id'])) {
                $updates[] = "tipo_identificacion_id = :tipo_identificacion_id";
                $params[':tipo_identificacion_id'] = $data['tipo_identificacion_id'];
            }

            if (isset($data['identificacion'])) {
                // Verificar que no exista otro contratista con la misma identificación
                if ($data['identificacion'] !== $datosAnteriores['identificacion']) {
                    $checkSentence = $db->prepare("
                        SELECT id FROM contratistas 
                        WHERE tipo_identificacion_id = :tipo_identificacion_id 
                        AND identificacion = :identificacion 
                        AND id != :id
                    ");
                    $tipo_id = $data['tipo_identificacion_id'] ?? $datosAnteriores['tipo_identificacion_id'];
                    $checkSentence->bindParam(':tipo_identificacion_id', $tipo_id);
                    $checkSentence->bindParam(':identificacion', $data['identificacion']);
                    $checkSentence->bindParam(':id', $id);
                    $checkSentence->execute();

                    if ($checkSentence->fetch()) {
                        responderJSON(['error' => 'Ya existe un contratista con esa identificación'], 400);
                        return;
                    }
                }

                $updates[] = "identificacion = :identificacion";
                $params[':identificacion'] = $data['identificacion'];
            }

            if (isset($data['nombre_completo'])) {
                $updates[] = "nombre_completo = :nombre_completo";
                $params[':nombre_completo'] = $data['nombre_completo'];
            }

            if (isset($data['email'])) {
                $updates[] = "email = :email";
                $params[':email'] = $data['email'];
            }

            if (isset($data['telefono'])) {
                $updates[] = "telefono = :telefono";
                $params[':telefono'] = $data['telefono'];
            }

            if (isset($data['direccion'])) {
                $updates[] = "direccion = :direccion";
                $params[':direccion'] = $data['direccion'];
            }

            if (isset($data['activo'])) {
                $updates[] = "activo = :activo";
                $params[':activo'] = (bool)$data['activo'];
            }

            if (!empty($updates)) {
                $sql = "UPDATE contratistas SET " . implode(", ", $updates) . " WHERE id = :id";
                $sentence = $db->prepare($sql);

                foreach ($params as $key => $value) {
                    $sentence->bindValue($key, $value);
                }

                $sentence->execute();

                // Obtener datos nuevos para auditoría
                $stmtNuevo = $db->prepare("SELECT * FROM contratistas WHERE id = :id");
                $stmtNuevo->bindParam(':id', $id);
                $stmtNuevo->execute();
                $datosNuevos = $stmtNuevo->fetch(PDO::FETCH_ASSOC);

                // AUDITORÍA
                AuditService::registrar('contratistas', $id, 'UPDATE', $datosAnteriores, $datosNuevos);
            }

            responderJSON([
                'success' => true,
                'message' => 'Contratista actualizado correctamente'
            ]);
        } catch (Exception $e) {
            error_log("ERROR en actualizar contratista: " . $e->getMessage());
            responderJSON(['error' => 'Error al actualizar contratista'], 500);
        }
    }

    // Eliminar contratista (soft delete)
    public static function eliminar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para eliminar contratistas'], 403);
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
                WHERE contratista_id = :id 
                AND estado = 'activo'
            ");
            $checkSentence->bindParam(':id', $id);
            $checkSentence->execute();
            $result = $checkSentence->fetch();

            if ($result['total'] > 0) {
                responderJSON(['error' => 'No se puede eliminar un contratista con contratos activos'], 400);
                return;
            }

            // Obtener datos para auditoría
            $stmtAnterior = $db->prepare("SELECT * FROM contratistas WHERE id = :id");
            $stmtAnterior->bindParam(':id', $id);
            $stmtAnterior->execute();
            $datosAnteriores = $stmtAnterior->fetch(PDO::FETCH_ASSOC);

            if (!$datosAnteriores) {
                responderJSON(['error' => 'Contratista no encontrado'], 404);
                return;
            }

            // Soft delete
            $sentence = $db->prepare("UPDATE contratistas SET activo = 0 WHERE id = :id");
            $sentence->bindParam(':id', $id);
            $sentence->execute();

            // AUDITORÍA
            AuditService::registrar('contratistas', $id, 'DELETE', $datosAnteriores, null);

            responderJSON([
                'success' => true,
                'message' => 'Contratista eliminado correctamente'
            ]);
        } catch (Exception $e) {
            error_log("ERROR en eliminar contratista: " . $e->getMessage());
            responderJSON(['error' => 'Error al eliminar contratista'], 500);
        }
    }

    // Buscar contratistas
    public static function buscar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para buscar contratistas'], 403);
                return;
            }

            $q = Flight::request()->query['q'] ?? '';
            $activo = Flight::request()->query['activo'] ?? null;

            $db = Flight::db();

            $sql = "
                SELECT 
                    c.id,
                    c.tipo_identificacion_id,
                    ti.codigo as tipo_identificacion_codigo,
                    c.identificacion,
                    c.nombre_completo,
                    c.email,
                    c.telefono,
                    c.activo
                FROM contratistas c
                INNER JOIN tipos_identificacion ti ON c.tipo_identificacion_id = ti.id
                WHERE 1=1
            ";

            $params = [];

            if ($q) {
                $sql .= " AND (
                    c.identificacion LIKE :q 
                    OR c.nombre_completo LIKE :q2 
                    OR c.email LIKE :q3
                )";
                $searchTerm = '%' . $q . '%';
                $params[':q'] = $searchTerm;
                $params[':q2'] = $searchTerm;
                $params[':q3'] = $searchTerm;
            }

            if ($activo !== null) {
                $sql .= " AND c.activo = :activo";
                $params[':activo'] = (bool)$activo;
            }

            $sql .= " ORDER BY c.nombre_completo ASC LIMIT 50";

            $sentence = $db->prepare($sql);

            foreach ($params as $key => $value) {
                $sentence->bindValue($key, $value);
            }

            $sentence->execute();
            $contratistas = $sentence->fetchAll(PDO::FETCH_ASSOC);

            responderJSON($contratistas);
        } catch (Exception $e) {
            error_log("ERROR en buscar contratistas: " . $e->getMessage());
            responderJSON(['error' => 'Error al buscar contratistas'], 500);
        }
    }
}
