<?php
Flight::route('GET /actividades-obligaciones/actividad/@id', [ActividadesObligacionesService::class, 'obtenerPorActividad']);
Flight::route('GET /actividades-obligaciones/obligacion/@id', [ActividadesObligacionesService::class, 'obtenerActividadesPorObligacion']);
Flight::route('GET /actividades-obligaciones/estadisticas', [ActividadesObligacionesService::class, 'obtenerEstadisticasPorPeriodo']);