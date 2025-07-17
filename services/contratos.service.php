<?php
class ContratosService
{
    // Obtener todos los contratos
    public static function obtenerTodos()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            // Verificar permisos
            if (
                !AuthService::checkPermission($currentUser['id'], 'contratos.gestionar') &&
                !AuthService::checkPermission($currentUser['id'], 'cuentas_cobro.ver')
            ) {
                responderJSON(['error' => 'No tiene permisos para ver contratos'], 403);
                return;
            }

            $db = Flight::db();

            // Si es rol usuario, solo ver sus propios contratos
            $isUsuarioRole = in_array('usuario', $currentUser['roles'] ?? []);
            $whereClause = "";
            $params = [];

            if ($isUsuarioRole) {
                // Necesitamos asociar el usuario con un contratista
                // Por ahora asumimos que el email del usuario coincide con el del contratista
                $whereClause = " WHERE ct.email = :user_email ";
                $params[':user_email'] = $currentUser['email'];
            }

            $sql = "
                SELECT 
                    c.id,
                    c.numero_contrato,
                    c.contratista_id,
                    ct.nombre_completo as contratista_nombre,
                    ct.identificacion as contratista_identificacion,
                    c.entidad_id,
                    e.nombre as entidad_nombre,
                    e.nombre_corto as entidad_nombre_corto,
                    c.fecha_suscripcion,
                    c.fecha_inicio,
                    c.fecha_terminacion,
                    c.plazo_dias,
                    c.objeto_contrato,
                    c.valor_total,
                    c.dependencia,
                    c.unidad_operativa,
                    c.estado,
                    DATEDIFF(c.fecha_terminacion, CURDATE()) as dias_restantes,
                    (SELECT COUNT(*) FROM obligaciones_contractuales WHERE contrato_id = c.id AND activo = 1) as total_obligaciones,
                    (SELECT COUNT(*) FROM contratos_supervisores WHERE contrato_id = c.id AND activo = 1) as total_supervisores
                FROM contratos c
                INNER JOIN contratistas ct ON c.contratista_id = ct.id
                INNER JOIN entidades e ON c.entidad_id = e.id
                $whereClause
                ORDER BY c.fecha_inicio DESC
            ";

            $sentence = $db->prepare($sql);

            foreach ($params as $key => $value) {
                $sentence->bindValue($key, $value);
            }

            $sentence->execute();
            $contratos = $sentence->fetchAll(PDO::FETCH_ASSOC);

            responderJSON($contratos);
        } catch (Exception $e) {
            error_log("ERROR en obtenerTodos contratos: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener contratos'], 500);
        }
    }

    // Obtener contrato por ID con toda su información
    public static function obtenerPorId($id)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (
                !AuthService::checkPermission($currentUser['id'], 'contratos.gestionar') &&
                !AuthService::checkPermission($currentUser['id'], 'cuentas_cobro.ver')
            ) {
                responderJSON(['error' => 'No tiene permisos para ver contratos'], 403);
                return;
            }

            $db = Flight::db();

            // Obtener información básica del contrato
            $sentence = $db->prepare("
                SELECT 
                    c.*,
                    ct.nombre_completo as contratista_nombre,
                    ct.identificacion as contratista_identificacion,
                    ct.email as contratista_email,
                    ct.telefono as contratista_telefono,
                    e.nombre as entidad_nombre,
                    e.identificacion as entidad_identificacion,
                    DATEDIFF(c.fecha_terminacion, CURDATE()) as dias_restantes
                FROM contratos c
                INNER JOIN contratistas ct ON c.contratista_id = ct.id
                INNER JOIN entidades e ON c.entidad_id = e.id
                WHERE c.id = :id
            ");
            $sentence->bindParam(':id', $id);
            $sentence->execute();

            $contrato = $sentence->fetch(PDO::FETCH_ASSOC);

            if (!$contrato) {
                responderJSON(['error' => 'Contrato no encontrado'], 404);
                return;
            }

            // Verificar acceso si es usuario
            $isUsuarioRole = in_array('usuario', $currentUser['roles'] ?? []);
            if ($isUsuarioRole && $contrato['contratista_email'] !== $currentUser['email']) {
                responderJSON(['error' => 'No tiene permisos para ver este contrato'], 403);
                return;
            }

            // Obtener supervisores
            $stmtSupervisores = $db->prepare("
                SELECT 
                    id,
                    nombre,
                    cargo,
                    tipo,
                    activo,
                    fecha_asignacion
                FROM contratos_supervisores
                WHERE contrato_id = :contrato_id
                ORDER BY tipo ASC, nombre ASC
            ");
            $stmtSupervisores->bindParam(':contrato_id', $id);
            $stmtSupervisores->execute();
            $contrato['supervisores'] = $stmtSupervisores->fetchAll(PDO::FETCH_ASSOC);

            // Obtener obligaciones
            $stmtObligaciones = $db->prepare("
                SELECT 
                    id,
                    numero_obligacion,
                    descripcion,
                    activo
                FROM obligaciones_contractuales
                WHERE contrato_id = :contrato_id
                ORDER BY numero_obligacion ASC
            ");
            $stmtObligaciones->bindParam(':contrato_id', $id);
            $stmtObligaciones->execute();
            $contrato['obligaciones'] = $stmtObligaciones->fetchAll(PDO::FETCH_ASSOC);

            // Obtener valores mensuales
            $stmtValores = $db->prepare("
                SELECT 
                    id,
                    mes,
                    anio,
                    valor,
                    porcentaje_avance_fisico,
                    porcentaje_avance_financiero
                FROM valores_mensuales
                WHERE contrato_id = :contrato_id
                ORDER BY anio DESC, mes DESC
            ");
            $stmtValores->bindParam(':contrato_id', $id);
            $stmtValores->execute();
            $contrato['valores_mensuales'] = $stmtValores->fetchAll(PDO::FETCH_ASSOC);

            responderJSON($contrato);
        } catch (Exception $e) {
            error_log("ERROR en obtenerPorId contrato: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener contrato'], 500);
        }
    }

    // Crear contrato con toda su información
    public static function crear()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para crear contratos'], 403);
                return;
            }

            $data = Flight::request()->data->getData();

            // Validaciones básicas
            $numero_contrato = $data['numero_contrato'] ?? null;
            $contratista_id = $data['contratista_id'] ?? null;
            $entidad_id = $data['entidad_id'] ?? null;
            $fecha_suscripcion = $data['fecha_suscripcion'] ?? null;
            $fecha_inicio = $data['fecha_inicio'] ?? null;
            $fecha_terminacion = $data['fecha_terminacion'] ?? null;
            $objeto_contrato = $data['objeto_contrato'] ?? null;
            $valor_total = $data['valor_total'] ?? null;

            if (
                !$numero_contrato || !$contratista_id || !$entidad_id ||
                !$fecha_suscripcion || !$fecha_inicio || !$fecha_terminacion ||
                !$objeto_contrato || !$valor_total
            ) {
                responderJSON(['error' => 'Datos incompletos'], 400);
                return;
            }

            // Calcular plazo en días
            $fechaInicio = new DateTime($fecha_inicio);
            $fechaFin = new DateTime($fecha_terminacion);
            $plazo_dias = $fechaInicio->diff($fechaFin)->days;

            $db = Flight::db();

            // Verificar que no exista un contrato con el mismo número
            $checkSentence = $db->prepare("SELECT id FROM contratos WHERE numero_contrato = :numero_contrato");
            $checkSentence->bindParam(':numero_contrato', $numero_contrato);
            $checkSentence->execute();

            if ($checkSentence->fetch()) {
                responderJSON(['error' => 'Ya existe un contrato con ese número'], 400);
                return;
            }

            $db->beginTransaction();

            try {
                // Variables para bindParam
                $dependencia = $data['dependencia'] ?? null;
                $unidad_operativa = $data['unidad_operativa'] ?? null;
                $estado = $data['estado'] ?? 'activo';

                // Insertar contrato
                $sentence = $db->prepare("
                    INSERT INTO contratos (
                        numero_contrato,
                        contratista_id,
                        entidad_id,
                        fecha_suscripcion,
                        fecha_inicio,
                        fecha_terminacion,
                        plazo_dias,
                        objeto_contrato,
                        valor_total,
                        dependencia,
                        unidad_operativa,
                        estado
                    ) VALUES (
                        :numero_contrato,
                        :contratista_id,
                        :entidad_id,
                        :fecha_suscripcion,
                        :fecha_inicio,
                        :fecha_terminacion,
                        :plazo_dias,
                        :objeto_contrato,
                        :valor_total,
                        :dependencia,
                        :unidad_operativa,
                        :estado
                    )
                ");

                $sentence->bindParam(':numero_contrato', $numero_contrato);
                $sentence->bindParam(':contratista_id', $contratista_id);
                $sentence->bindParam(':entidad_id', $entidad_id);
                $sentence->bindParam(':fecha_suscripcion', $fecha_suscripcion);
                $sentence->bindParam(':fecha_inicio', $fecha_inicio);
                $sentence->bindParam(':fecha_terminacion', $fecha_terminacion);
                $sentence->bindParam(':plazo_dias', $plazo_dias);
                $sentence->bindParam(':objeto_contrato', $objeto_contrato);
                $sentence->bindParam(':valor_total', $valor_total);
                $sentence->bindParam(':dependencia', $dependencia);
                $sentence->bindParam(':unidad_operativa', $unidad_operativa);
                $sentence->bindParam(':estado', $estado);

                $sentence->execute();
                $contratoId = $db->lastInsertId();

                // Insertar supervisores si se proporcionaron
                if (!empty($data['supervisores'])) {
                    foreach ($data['supervisores'] as $supervisor) {
                        $stmtSupervisor = $db->prepare("
                            INSERT INTO contratos_supervisores (
                                contrato_id,
                                nombre,
                                cargo,
                                tipo,
                                fecha_asignacion
                            ) VALUES (
                                :contrato_id,
                                :nombre,
                                :cargo,
                                :tipo,
                                :fecha_asignacion
                            )
                        ");

                        $cargo = $supervisor['cargo'] ?? null;
                        $tipo = $supervisor['tipo'] ?? 'principal';

                        $stmtSupervisor->bindParam(':contrato_id', $contratoId);
                        $stmtSupervisor->bindParam(':nombre', $supervisor['nombre']);
                        $stmtSupervisor->bindParam(':cargo', $cargo);
                        $stmtSupervisor->bindParam(':tipo', $tipo);
                        $stmtSupervisor->bindParam(':fecha_asignacion', $fecha_suscripcion);
                        $stmtSupervisor->execute();
                    }
                }

                // Insertar obligaciones si se proporcionaron
                if (!empty($data['obligaciones'])) {
                    foreach ($data['obligaciones'] as $index => $obligacion) {
                        $stmtObligacion = $db->prepare("
                            INSERT INTO obligaciones_contractuales (
                                contrato_id,
                                numero_obligacion,
                                descripcion
                            ) VALUES (
                                :contrato_id,
                                :numero_obligacion,
                                :descripcion
                            )
                        ");

                        $numero = $obligacion['numero_obligacion'] ?? ($index + 1);
                        $stmtObligacion->bindParam(':contrato_id', $contratoId);
                        $stmtObligacion->bindParam(':numero_obligacion', $numero);
                        $stmtObligacion->bindParam(':descripcion', $obligacion['descripcion']);
                        $stmtObligacion->execute();
                    }
                }

                // Insertar valores mensuales si se proporcionaron
                if (!empty($data['valores_mensuales'])) {
                    foreach ($data['valores_mensuales'] as $valorMensual) {
                        $stmtValor = $db->prepare("
                            INSERT INTO valores_mensuales (
                                contrato_id,
                                mes,
                                anio,
                                valor,
                                porcentaje_avance_fisico,
                                porcentaje_avance_financiero
                            ) VALUES (
                                :contrato_id,
                                :mes,
                                :anio,
                                :valor,
                                :porcentaje_avance_fisico,
                                :porcentaje_avance_financiero
                            )
                        ");

                        $porcentaje_fisico = $valorMensual['porcentaje_avance_fisico'] ?? null;
                        $porcentaje_financiero = $valorMensual['porcentaje_avance_financiero'] ?? null;

                        $stmtValor->bindParam(':contrato_id', $contratoId);
                        $stmtValor->bindParam(':mes', $valorMensual['mes']);
                        $stmtValor->bindParam(':anio', $valorMensual['anio']);
                        $stmtValor->bindParam(':valor', $valorMensual['valor']);
                        $stmtValor->bindParam(':porcentaje_avance_fisico', $porcentaje_fisico);
                        $stmtValor->bindParam(':porcentaje_avance_financiero', $porcentaje_financiero);
                        $stmtValor->execute();
                    }
                }

                $db->commit();

                // AUDITORÍA
                $datosNuevos = [
                    'id' => $contratoId,
                    'numero_contrato' => $numero_contrato,
                    'contratista_id' => $contratista_id,
                    'entidad_id' => $entidad_id,
                    'fecha_suscripcion' => $fecha_suscripcion,
                    'fecha_inicio' => $fecha_inicio,
                    'fecha_terminacion' => $fecha_terminacion,
                    'plazo_dias' => $plazo_dias,
                    'objeto_contrato' => $objeto_contrato,
                    'valor_total' => $valor_total,
                    'supervisores' => count($data['supervisores'] ?? []),
                    'obligaciones' => count($data['obligaciones'] ?? [])
                ];

                AuditService::registrar('contratos', $contratoId, 'CREATE', null, $datosNuevos);

                responderJSON([
                    'success' => true,
                    'id' => $contratoId,
                    'message' => 'Contrato creado correctamente'
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            error_log("ERROR en crear contrato: " . $e->getMessage());
            responderJSON(['error' => 'Error al crear contrato: ' . $e->getMessage()], 500);
        }
    }

    // Actualizar contrato
    public static function actualizar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para editar contratos'], 403);
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
            $stmtAnterior = $db->prepare("SELECT * FROM contratos WHERE id = :id");
            $stmtAnterior->bindParam(':id', $id);
            $stmtAnterior->execute();
            $datosAnteriores = $stmtAnterior->fetch(PDO::FETCH_ASSOC);

            if (!$datosAnteriores) {
                responderJSON(['error' => 'Contrato no encontrado'], 404);
                return;
            }

            $db->beginTransaction();

            try {
                // Construir query dinámicamente
                $updates = [];
                $params = [':id' => $id];

                if (isset($data['numero_contrato'])) {
                    // Verificar que no exista otro contrato con el mismo número
                    if ($data['numero_contrato'] !== $datosAnteriores['numero_contrato']) {
                        $checkSentence = $db->prepare("
                            SELECT id FROM contratos 
                            WHERE numero_contrato = :numero_contrato 
                            AND id != :id
                        ");
                        $checkSentence->bindParam(':numero_contrato', $data['numero_contrato']);
                        $checkSentence->bindParam(':id', $id);
                        $checkSentence->execute();

                        if ($checkSentence->fetch()) {
                            $db->rollBack();
                            responderJSON(['error' => 'Ya existe un contrato con ese número'], 400);
                            return;
                        }
                    }

                    $updates[] = "numero_contrato = :numero_contrato";
                    $params[':numero_contrato'] = $data['numero_contrato'];
                }

                // Actualizar fechas y recalcular plazo
                if (isset($data['fecha_inicio']) || isset($data['fecha_terminacion'])) {
                    $fecha_inicio = $data['fecha_inicio'] ?? $datosAnteriores['fecha_inicio'];
                    $fecha_terminacion = $data['fecha_terminacion'] ?? $datosAnteriores['fecha_terminacion'];

                    $fechaInicio = new DateTime($fecha_inicio);
                    $fechaFin = new DateTime($fecha_terminacion);
                    $plazo_dias = $fechaInicio->diff($fechaFin)->days;

                    if (isset($data['fecha_inicio'])) {
                        $updates[] = "fecha_inicio = :fecha_inicio";
                        $params[':fecha_inicio'] = $data['fecha_inicio'];
                    }

                    if (isset($data['fecha_terminacion'])) {
                        $updates[] = "fecha_terminacion = :fecha_terminacion";
                        $params[':fecha_terminacion'] = $data['fecha_terminacion'];
                    }

                    $updates[] = "plazo_dias = :plazo_dias";
                    $params[':plazo_dias'] = $plazo_dias;
                }

                // Otros campos
                $camposActualizables = [
                    'fecha_suscripcion',
                    'objeto_contrato',
                    'valor_total',
                    'dependencia',
                    'unidad_operativa',
                    'estado'
                ];

                foreach ($camposActualizables as $campo) {
                    if (isset($data[$campo])) {
                        $updates[] = "$campo = :$campo";
                        $params[":$campo"] = $data[$campo];
                    }
                }

                if (!empty($updates)) {
                    $sql = "UPDATE contratos SET " . implode(", ", $updates) . " WHERE id = :id";
                    $sentence = $db->prepare($sql);

                    foreach ($params as $key => $value) {
                        $sentence->bindValue($key, $value);
                    }

                    $sentence->execute();
                }

                $db->commit();

                // Obtener datos nuevos para auditoría
                $stmtNuevo = $db->prepare("SELECT * FROM contratos WHERE id = :id");
                $stmtNuevo->bindParam(':id', $id);
                $stmtNuevo->execute();
                $datosNuevos = $stmtNuevo->fetch(PDO::FETCH_ASSOC);

                // AUDITORÍA
                AuditService::registrar('contratos', $id, 'UPDATE', $datosAnteriores, $datosNuevos);

                responderJSON([
                    'success' => true,
                    'message' => 'Contrato actualizado correctamente'
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            error_log("ERROR en actualizar contrato: " . $e->getMessage());
            responderJSON(['error' => 'Error al actualizar contrato'], 500);
        }
    }

    // Cambiar estado del contrato
    public static function cambiarEstado()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para cambiar estado de contratos'], 403);
                return;
            }

            $data = Flight::request()->data->getData();
            $id = $data['id'] ?? null;
            $estado = $data['estado'] ?? null;

            if (!$id || !$estado) {
                responderJSON(['error' => 'Datos incompletos'], 400);
                return;
            }

            $estadosValidos = ['activo', 'suspendido', 'finalizado', 'liquidado'];
            if (!in_array($estado, $estadosValidos)) {
                responderJSON(['error' => 'Estado inválido'], 400);
                return;
            }

            $db = Flight::db();

            // Obtener estado anterior
            $stmtAnterior = $db->prepare("SELECT id, numero_contrato, estado FROM contratos WHERE id = :id");
            $stmtAnterior->bindParam(':id', $id);
            $stmtAnterior->execute();
            $datosAnteriores = $stmtAnterior->fetch(PDO::FETCH_ASSOC);

            if (!$datosAnteriores) {
                responderJSON(['error' => 'Contrato no encontrado'], 404);
                return;
            }

            // Actualizar estado
            $sentence = $db->prepare("UPDATE contratos SET estado = :estado WHERE id = :id");
            $sentence->bindParam(':id', $id);
            $sentence->bindParam(':estado', $estado);
            $sentence->execute();

            // AUDITORÍA
            $datosNuevos = $datosAnteriores;
            $datosNuevos['estado'] = $estado;

            AuditService::registrar('contratos', $id, 'UPDATE', $datosAnteriores, $datosNuevos);

            responderJSON([
                'success' => true,
                'message' => 'Estado del contrato actualizado correctamente'
            ]);
        } catch (Exception $e) {
            error_log("ERROR en cambiar estado contrato: " . $e->getMessage());
            responderJSON(['error' => 'Error al cambiar estado'], 500);
        }
    }

    // Gestionar supervisores del contrato
    public static function gestionarSupervisores()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para gestionar supervisores'], 403);
                return;
            }

            $data = Flight::request()->data->getData();
            $contrato_id = $data['contrato_id'] ?? null;
            $supervisores = $data['supervisores'] ?? [];

            if (!$contrato_id) {
                responderJSON(['error' => 'ID de contrato no proporcionado'], 400);
                return;
            }

            $db = Flight::db();

            // Verificar que el contrato existe
            $checkContrato = $db->prepare("SELECT id FROM contratos WHERE id = :id");
            $checkContrato->bindParam(':id', $contrato_id);
            $checkContrato->execute();

            if (!$checkContrato->fetch()) {
                responderJSON(['error' => 'Contrato no encontrado'], 404);
                return;
            }

            $db->beginTransaction();

            try {
                // Desactivar supervisores actuales
                $stmtDesactivar = $db->prepare("
                    UPDATE contratos_supervisores 
                    SET activo = 0 
                    WHERE contrato_id = :contrato_id
                ");
                $stmtDesactivar->bindParam(':contrato_id', $contrato_id);
                $stmtDesactivar->execute();

                // Insertar o actualizar supervisores
                foreach ($supervisores as $supervisor) {
                    if (isset($supervisor['id']) && $supervisor['id']) {
                        // Actualizar existente
                        $stmtUpdate = $db->prepare("
                            UPDATE contratos_supervisores 
                            SET nombre = :nombre,
                                cargo = :cargo,
                                tipo = :tipo,
                                activo = 1
                            WHERE id = :id AND contrato_id = :contrato_id
                        ");

                        $cargo = $supervisor['cargo'] ?? null;
                        $tipo = $supervisor['tipo'] ?? 'principal';

                        $stmtUpdate->bindParam(':id', $supervisor['id']);
                        $stmtUpdate->bindParam(':contrato_id', $contrato_id);
                        $stmtUpdate->bindParam(':nombre', $supervisor['nombre']);
                        $stmtUpdate->bindParam(':cargo', $cargo);
                        $stmtUpdate->bindParam(':tipo', $tipo);
                        $stmtUpdate->execute();
                    } else {
                        // Insertar nuevo
                        $stmtInsert = $db->prepare("
                            INSERT INTO contratos_supervisores (
                                contrato_id,
                                nombre,
                                cargo,
                                tipo,
                                fecha_asignacion,
                                activo
                            ) VALUES (
                                :contrato_id,
                                :nombre,
                                :cargo,
                                :tipo,
                                CURDATE(),
                                1
                            )
                        ");

                        $cargo = $supervisor['cargo'] ?? null;
                        $tipo = $supervisor['tipo'] ?? 'principal';

                        $stmtInsert->bindParam(':contrato_id', $contrato_id);
                        $stmtInsert->bindParam(':nombre', $supervisor['nombre']);
                        $stmtInsert->bindParam(':cargo', $cargo);
                        $stmtInsert->bindParam(':tipo', $tipo);
                        $stmtInsert->execute();
                    }
                }

                $db->commit();

                // AUDITORÍA
                AuditService::registrar(
                    'contratos_supervisores',
                    $contrato_id,
                    'UPDATE',
                    ['accion' => 'actualizar_supervisores'],
                    ['supervisores_actualizados' => count($supervisores)]
                );

                responderJSON([
                    'success' => true,
                    'message' => 'Supervisores actualizados correctamente'
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            error_log("ERROR en gestionar supervisores: " . $e->getMessage());
            responderJSON(['error' => 'Error al gestionar supervisores'], 500);
        }
    }

    // Gestionar obligaciones del contrato
    public static function gestionarObligaciones()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para gestionar obligaciones'], 403);
                return;
            }

            $data = Flight::request()->data->getData();
            $contrato_id = $data['contrato_id'] ?? null;
            $obligaciones = $data['obligaciones'] ?? [];

            if (!$contrato_id) {
                responderJSON(['error' => 'ID de contrato no proporcionado'], 400);
                return;
            }

            $db = Flight::db();

            // Verificar que el contrato existe
            $checkContrato = $db->prepare("SELECT id FROM contratos WHERE id = :id");
            $checkContrato->bindParam(':id', $contrato_id);
            $checkContrato->execute();

            if (!$checkContrato->fetch()) {
                responderJSON(['error' => 'Contrato no encontrado'], 404);
                return;
            }

            $db->beginTransaction();

            try {
                // Desactivar obligaciones actuales
                $stmtDesactivar = $db->prepare("
                    UPDATE obligaciones_contractuales 
                    SET activo = 0 
                    WHERE contrato_id = :contrato_id
                ");
                $stmtDesactivar->bindParam(':contrato_id', $contrato_id);
                $stmtDesactivar->execute();

                // Insertar o actualizar obligaciones
                foreach ($obligaciones as $obligacion) {
                    if (isset($obligacion['id']) && $obligacion['id']) {
                        // Actualizar existente
                        $stmtUpdate = $db->prepare("
                            UPDATE obligaciones_contractuales 
                            SET numero_obligacion = :numero_obligacion,
                                descripcion = :descripcion,
                                activo = 1
                            WHERE id = :id AND contrato_id = :contrato_id
                        ");

                        $stmtUpdate->bindParam(':id', $obligacion['id']);
                        $stmtUpdate->bindParam(':contrato_id', $contrato_id);
                        $stmtUpdate->bindParam(':numero_obligacion', $obligacion['numero_obligacion']);
                        $stmtUpdate->bindParam(':descripcion', $obligacion['descripcion']);
                        $stmtUpdate->execute();
                    } else {
                        // Insertar nueva
                        $stmtInsert = $db->prepare("
                            INSERT INTO obligaciones_contractuales (
                                contrato_id,
                                numero_obligacion,
                                descripcion,
                                activo
                            ) VALUES (
                                :contrato_id,
                                :numero_obligacion,
                                :descripcion,
                                1
                            )
                        ");

                        $stmtInsert->bindParam(':contrato_id', $contrato_id);
                        $stmtInsert->bindParam(':numero_obligacion', $obligacion['numero_obligacion']);
                        $stmtInsert->bindParam(':descripcion', $obligacion['descripcion']);
                        $stmtInsert->execute();
                    }
                }

                $db->commit();

                // AUDITORÍA
                AuditService::registrar(
                    'obligaciones_contractuales',
                    $contrato_id,
                    'UPDATE',
                    ['accion' => 'actualizar_obligaciones'],
                    ['obligaciones_actualizadas' => count($obligaciones)]
                );

                responderJSON([
                    'success' => true,
                    'message' => 'Obligaciones actualizadas correctamente'
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            error_log("ERROR en gestionar obligaciones: " . $e->getMessage());
            responderJSON(['error' => 'Error al gestionar obligaciones'], 500);
        }
    }

    // Gestionar valores mensuales del contrato
    public static function gestionarValoresMensuales()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!AuthService::checkPermission($currentUser['id'], 'contratos.gestionar')) {
                responderJSON(['error' => 'No tiene permisos para gestionar valores mensuales'], 403);
                return;
            }

            $data = Flight::request()->data->getData();
            $contrato_id = $data['contrato_id'] ?? null;
            $valores = $data['valores_mensuales'] ?? [];

            if (!$contrato_id) {
                responderJSON(['error' => 'ID de contrato no proporcionado'], 400);
                return;
            }

            $db = Flight::db();

            // Verificar que el contrato existe
            $checkContrato = $db->prepare("SELECT id FROM contratos WHERE id = :id");
            $checkContrato->bindParam(':id', $contrato_id);
            $checkContrato->execute();

            if (!$checkContrato->fetch()) {
                responderJSON(['error' => 'Contrato no encontrado'], 404);
                return;
            }

            $db->beginTransaction();

            try {
                foreach ($valores as $valor) {
                    // Verificar si ya existe un valor para ese mes/anio
                    $checkExiste = $db->prepare("
                        SELECT id FROM valores_mensuales 
                        WHERE contrato_id = :contrato_id 
                        AND mes = :mes 
                        AND anio = :anio
                    ");
                    $checkExiste->bindParam(':contrato_id', $contrato_id);
                    $checkExiste->bindParam(':mes', $valor['mes']);
                    $checkExiste->bindParam(':anio', $valor['anio']);
                    $checkExiste->execute();

                    $existe = $checkExiste->fetch();

                    if ($existe) {
                        // Actualizar
                        $stmtUpdate = $db->prepare("
                            UPDATE valores_mensuales 
                            SET valor = :valor,
                                porcentaje_avance_fisico = :porcentaje_avance_fisico,
                                porcentaje_avance_financiero = :porcentaje_avance_financiero
                            WHERE id = :id
                        ");

                        $porcentaje_fisico = $valor['porcentaje_avance_fisico'] ?? null;
                        $porcentaje_financiero = $valor['porcentaje_avance_financiero'] ?? null;

                        $stmtUpdate->bindParam(':id', $existe['id']);
                        $stmtUpdate->bindParam(':valor', $valor['valor']);
                        $stmtUpdate->bindParam(':porcentaje_avance_fisico', $porcentaje_fisico);
                        $stmtUpdate->bindParam(':porcentaje_avance_financiero', $porcentaje_financiero);
                        $stmtUpdate->execute();
                    } else {
                        // Insertar
                        $stmtInsert = $db->prepare("
                            INSERT INTO valores_mensuales (
                                contrato_id,
                                mes,
                                anio,
                                valor,
                                porcentaje_avance_fisico,
                                porcentaje_avance_financiero
                            ) VALUES (
                                :contrato_id,
                                :mes,
                                :anio,
                                :valor,
                                :porcentaje_avance_fisico,
                                :porcentaje_avance_financiero
                            )
                        ");

                        $porcentaje_fisico = $valor['porcentaje_avance_fisico'] ?? null;
                        $porcentaje_financiero = $valor['porcentaje_avance_financiero'] ?? null;

                        $stmtInsert->bindParam(':contrato_id', $contrato_id);
                        $stmtInsert->bindParam(':mes', $valor['mes']);
                        $stmtInsert->bindParam(':anio', $valor['anio']);
                        $stmtInsert->bindParam(':valor', $valor['valor']);
                        $stmtInsert->bindParam(':porcentaje_avance_fisico', $porcentaje_fisico);
                        $stmtInsert->bindParam(':porcentaje_avance_financiero', $porcentaje_financiero);
                        $stmtInsert->execute();
                    }
                }

                $db->commit();

                // AUDITORÍA
                AuditService::registrar(
                    'valores_mensuales',
                    $contrato_id,
                    'UPDATE',
                    ['accion' => 'actualizar_valores_mensuales'],
                    ['valores_actualizados' => count($valores)]
                );

                responderJSON([
                    'success' => true,
                    'message' => 'Valores mensuales actualizados correctamente'
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            error_log("ERROR en gestionar valores mensuales: " . $e->getMessage());
            responderJSON(['error' => 'Error al gestionar valores mensuales'], 500);
        }
    }

    // Buscar contratos
    public static function buscar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (
                !AuthService::checkPermission($currentUser['id'], 'contratos.gestionar') &&
                !AuthService::checkPermission($currentUser['id'], 'cuentas_cobro.ver')
            ) {
                responderJSON(['error' => 'No tiene permisos para buscar contratos'], 403);
                return;
            }

            $q = Flight::request()->query['q'] ?? '';
            $estado = Flight::request()->query['estado'] ?? null;
            $contratista_id = Flight::request()->query['contratista_id'] ?? null;
            $entidad_id = Flight::request()->query['entidad_id'] ?? null;

            $db = Flight::db();

            $sql = "
                SELECT 
                    c.id,
                    c.numero_contrato,
                    c.contratista_id,
                    ct.nombre_completo as contratista_nombre,
                    c.entidad_id,
                    e.nombre as entidad_nombre,
                    c.fecha_inicio,
                    c.fecha_terminacion,
                    c.valor_total,
                    c.estado
                FROM contratos c
                INNER JOIN contratistas ct ON c.contratista_id = ct.id
                INNER JOIN entidades e ON c.entidad_id = e.id
                WHERE 1=1
            ";

            $params = [];

            // Si es usuario, solo sus contratos
            $isUsuarioRole = in_array('usuario', $currentUser['roles'] ?? []);
            if ($isUsuarioRole) {
                $sql .= " AND ct.email = :user_email ";
                $params[':user_email'] = $currentUser['email'];
            }

            if ($q) {
                $sql .= " AND (
                    c.numero_contrato LIKE :q 
                    OR c.objeto_contrato LIKE :q2 
                    OR ct.nombre_completo LIKE :q3
                    OR e.nombre LIKE :q4
                )";
                $searchTerm = '%' . $q . '%';
                $params[':q'] = $searchTerm;
                $params[':q2'] = $searchTerm;
                $params[':q3'] = $searchTerm;
                $params[':q4'] = $searchTerm;
            }

            if ($estado) {
                $sql .= " AND c.estado = :estado";
                $params[':estado'] = $estado;
            }

            if ($contratista_id) {
                $sql .= " AND c.contratista_id = :contratista_id";
                $params[':contratista_id'] = $contratista_id;
            }

            if ($entidad_id) {
                $sql .= " AND c.entidad_id = :entidad_id";
                $params[':entidad_id'] = $entidad_id;
            }

            $sql .= " ORDER BY c.fecha_inicio DESC LIMIT 100";

            $sentence = $db->prepare($sql);

            foreach ($params as $key => $value) {
                $sentence->bindValue($key, $value);
            }

            $sentence->execute();
            $contratos = $sentence->fetchAll(PDO::FETCH_ASSOC);

            responderJSON($contratos);
        } catch (Exception $e) {
            error_log("ERROR en buscar contratos: " . $e->getMessage());
            responderJSON(['error' => 'Error al buscar contratos'], 500);
        }
    }


    /**
     * Obtener contratos por contratista
     */
    public static function obtenerPorContratista($contratista_id)
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            if (!$contratista_id) {
                Flight::json(['error' => 'Contratista ID es requerido'], 400);
                return;
            }

            $db = Flight::db();

            // Verificar permisos
            if (!AuthService::hasPermission('contratos.gestionar')) {
                // Si no es admin, verificar que tenga acceso al contratista
                $stmt = $db->prepare("
                SELECT COUNT(*) 
                FROM usuarios_contratistas 
                WHERE usuario_id = :usuario_id AND contratista_id = :contratista_id
            ");
                $stmt->bindParam(':usuario_id', $currentUser['id']);
                $stmt->bindParam(':contratista_id', $contratista_id);
                $stmt->execute();
                $tiene_acceso = $stmt->fetchColumn();

                if ($tiene_acceso == 0) {
                    Flight::json(['error' => 'No tiene permisos para ver estos contratos'], 403);
                    return;
                }
            }

            // Obtener contratos del contratista
            $stmt = $db->prepare("
        SELECT 
            c.id,
            c.numero_contrato,
            c.contratista_id,
            cont.nombre_completo as contratista_nombre,
            c.entidad_id,
            e.nombre as entidad_nombre,
            e.nombre_corto as entidad_nombre_corto,
            c.fecha_suscripcion,
            c.fecha_inicio,
            c.fecha_terminacion,
            c.plazo_dias,
            c.objeto_contrato,
            c.valor_total,
            c.dependencia,
            c.unidad_operativa,
            c.estado,
            -- Información del supervisor principal
            (SELECT CONCAT(nombre, ' - ', cargo) 
            FROM contratos_supervisores 
            WHERE contrato_id = c.id AND tipo = 'principal' AND activo = 1 
            LIMIT 1) as supervisor_principal,
            -- Total de obligaciones
            (SELECT COUNT(*) 
            FROM obligaciones_contractuales 
            WHERE contrato_id = c.id AND activo = 1) as total_obligaciones,
            -- Total de actividades
            (SELECT COUNT(*) 
            FROM actividades 
            WHERE contrato_id = c.id) as total_actividades,
            -- Progreso (basado en fechas)
            CASE 
                WHEN c.estado = 'finalizado' THEN 100
                WHEN c.estado = 'liquidado' THEN 100
                WHEN CURDATE() < c.fecha_inicio THEN 0
                WHEN CURDATE() > c.fecha_terminacion THEN 100
                ELSE ROUND(
                    (DATEDIFF(CURDATE(), c.fecha_inicio) * 100.0) / 
                    DATEDIFF(c.fecha_terminacion, c.fecha_inicio), 2
                )
            END as progreso_porcentaje
        FROM contratos c
        JOIN contratistas cont ON c.contratista_id = cont.id
        JOIN entidades e ON c.entidad_id = e.id
        WHERE c.contratista_id = :contratista_id
        ORDER BY 
            FIELD(c.estado, 'activo', 'suspendido', 'finalizado', 'liquidado'),
            c.fecha_inicio DESC
        ");

            $stmt->bindParam(':contratista_id', $contratista_id);
            $stmt->execute();
            $contratos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Flight::json([
                'success' => true,
                'contratos' => $contratos,
                'total' => count($contratos)
            ]);
        } catch (Exception $e) {
            error_log("Error al obtener contratos por contratista: " . $e->getMessage());
            Flight::json(['error' => 'Error al obtener contratos'], 500);
        }
    }
}
