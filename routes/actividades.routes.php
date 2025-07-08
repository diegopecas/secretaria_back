<?php
// Rutas de actividades
Flight::route('POST /actividades', [ActividadesService::class, 'crear']);
Flight::route('GET /actividades/periodo', [ActividadesService::class, 'obtenerPorPeriodo']);
Flight::route('POST /actividades/buscar', [ActividadesService::class, 'buscar']);
Flight::route('PUT /actividades', [ActividadesService::class, 'actualizar']);
Flight::route('DELETE /actividades', [ActividadesService::class, 'eliminar']);
Flight::route('GET /actividades/resumen', [ActividadesService::class, 'obtenerResumen']);