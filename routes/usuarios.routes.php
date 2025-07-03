<?php
// Todas las rutas de usuarios requieren autenticación
// Se debe verificar con requireAuth() en cada método del servicio

// Rutas de usuarios
Flight::route('GET /usuarios', [UsuariosService::class, 'obtenerTodos']);
Flight::route('GET /usuarios/@id', [UsuariosService::class, 'obtenerPorId']);
Flight::route('POST /usuarios', [UsuariosService::class, 'crear']);
Flight::route('PUT /usuarios', [UsuariosService::class, 'actualizar']);
Flight::route('DELETE /usuarios', [UsuariosService::class, 'eliminar']);
Flight::route('PATCH /usuarios/estado', [UsuariosService::class, 'cambiarEstado']);
Flight::route('POST /usuarios/roles', [UsuariosService::class, 'asignarRoles']);