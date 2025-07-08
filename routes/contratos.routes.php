<?php
// Rutas de contratos
Flight::route('GET /contratos', [ContratosService::class, 'obtenerTodos']);
Flight::route('GET /contratos/@id', [ContratosService::class, 'obtenerPorId']);
Flight::route('POST /contratos', [ContratosService::class, 'crear']);
Flight::route('PUT /contratos', [ContratosService::class, 'actualizar']);
Flight::route('PATCH /contratos/estado', [ContratosService::class, 'cambiarEstado']);
Flight::route('POST /contratos/supervisores', [ContratosService::class, 'gestionarSupervisores']);
Flight::route('POST /contratos/obligaciones', [ContratosService::class, 'gestionarObligaciones']);
Flight::route('POST /contratos/valores-mensuales', [ContratosService::class, 'gestionarValoresMensuales']);
Flight::route('GET /contratos/buscar', [ContratosService::class, 'buscar']);