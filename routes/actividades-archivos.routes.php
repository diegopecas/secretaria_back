<?php
Flight::route('GET /actividades-archivos/actividad/@id', [ActividadesArchivosService::class, 'obtenerPorActividad']);
Flight::route('GET /actividades-archivos/@id', [ActividadesArchivosService::class, 'obtenerPorId']);
Flight::route('POST /actividades-archivos/buscar', [ActividadesArchivosService::class, 'buscarPorContenido']);
Flight::route('GET /actividades-archivos/estadisticas', [ActividadesArchivosService::class, 'obtenerEstadisticas']);
Flight::route('POST /actividades-archivos/procesar/@id', [ActividadesArchivosService::class, 'procesarParaIA']);