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


// Búsqueda con IA
Flight::route('POST /actividades/buscar-ia', [ActividadesService::class, 'buscarConIA']);

// Procesar pendientes
Flight::route('POST /actividades/procesar-pendientes', [ActividadesService::class, 'procesarPendientes']);

// Archivos
Flight::route('POST /actividades/archivos/procesar-ia', [ActividadesArchivosService::class, 'procesarParaIA']);
Flight::route('POST /actividades/archivos/procesar-pendientes', [ActividadesArchivosService::class, 'procesarPendientes']);
Flight::route('POST /actividades/archivos/buscar', [ActividadesArchivosService::class, 'buscarPorContenido']);
Flight::route('GET /actividades/archivos/estadisticas', [ActividadesArchivosService::class, 'obtenerEstadisticas']);

