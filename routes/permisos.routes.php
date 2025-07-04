<?php
// Rutas de permisos
Flight::route('GET /permisos', [PermisosService::class, 'obtenerTodos']);