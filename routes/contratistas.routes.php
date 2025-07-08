<?php
// Rutas de contratistas
Flight::route('GET /contratistas', [ContratistasService::class, 'obtenerTodos']);
Flight::route('GET /contratistas/buscar', [ContratistasService::class, 'buscar']);
Flight::route('GET /contratistas/@id', [ContratistasService::class, 'obtenerPorId']);
Flight::route('POST /contratistas', [ContratistasService::class, 'crear']);
Flight::route('PUT /contratistas', [ContratistasService::class, 'actualizar']);
Flight::route('DELETE /contratistas', [ContratistasService::class, 'eliminar']);