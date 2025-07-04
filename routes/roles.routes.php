<?php
// Rutas de roles
Flight::route('GET /roles', [RolesService::class, 'obtenerTodos']);
Flight::route('GET /roles/@id', [RolesService::class, 'obtenerPorId']);
Flight::route('POST /roles', [RolesService::class, 'crear']);
Flight::route('PUT /roles', [RolesService::class, 'actualizar']);
Flight::route('DELETE /roles', [RolesService::class, 'eliminar']);
Flight::route('GET /roles/@id/permisos', [RolesService::class, 'obtenerPermisos']);
Flight::route('POST /roles/@id/permisos', [RolesService::class, 'asignarPermisos']);