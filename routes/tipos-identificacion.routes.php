<?php
// Rutas de tipos de identificación
Flight::route('GET /tipos-identificacion', [TiposIdentificacionService::class, 'obtenerTodos']);
Flight::route('GET /tipos-identificacion/aplicacion', [TiposIdentificacionService::class, 'obtenerPorAplicacion']);
Flight::route('GET /tipos-identificacion/@id', [TiposIdentificacionService::class, 'obtenerPorId']);