<?php
// Rutas de roles
Flight::route('GET /roles', [RolesService::class, 'obtenerTodos']);
Flight::route('GET /roles/@id/permisos', [RolesService::class, 'obtenerPermisos']);