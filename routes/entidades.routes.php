<?php
// Rutas de entidades
Flight::route('GET /entidades', [EntidadesService::class, 'obtenerTodos']);
Flight::route('GET /entidades/buscar', [EntidadesService::class, 'buscar']);
Flight::route('GET /entidades/@id', [EntidadesService::class, 'obtenerPorId']);
Flight::route('POST /entidades', [EntidadesService::class, 'crear']);
Flight::route('PUT /entidades', [EntidadesService::class, 'actualizar']);
Flight::route('DELETE /entidades', [EntidadesService::class, 'eliminar']);