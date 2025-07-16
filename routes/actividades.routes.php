<?php
Flight::route('GET /actividades', [ActividadesService::class, 'obtenerTodas']);
Flight::route('GET /actividades/por-contratista', [ActividadesService::class, 'obtenerPorContratista']);
Flight::route('POST /actividades', [ActividadesService::class, 'crear']);
Flight::route('GET /actividades/periodo', [ActividadesService::class, 'obtenerPorPeriodo']);
Flight::route('GET /actividades/detalle', [ActividadesService::class, 'obtenerPorId']);
Flight::route('POST /actividades/buscar', [ActividadesService::class, 'buscar']);
Flight::route('POST /actividades/actualizar', [ActividadesService::class, 'actualizar']);
Flight::route('DELETE /actividades', [ActividadesService::class, 'eliminar']);
Flight::route('DELETE /actividades/archivo', [ActividadesService::class, 'eliminarArchivo']);
Flight::route('GET /actividades/resumen', [ActividadesService::class, 'obtenerResumen']);