<?php
// index.php - Punto de entrada de la API
require 'vendor/autoload.php';
require 'config.php';
require 'services/auth.service.php';
require 'services/usuarios.service.php';
// Configurar Flight
Flight::set('flight.base_url', '/');
Flight::set('flight.case_sensitive', false);

// Rutas de autenticación (públicas)
Flight::route('POST /auth/login', [AuthService::class, 'login']);
Flight::route('POST /auth/register', [AuthService::class, 'register']);
Flight::route('POST /auth/logout', [AuthService::class, 'logout']);
Flight::route('GET /auth/validate', [AuthService::class, 'validateSession']);
Flight::route('POST /auth/refresh', [AuthService::class, 'refreshToken']);

// Rutas protegidas
Flight::route('GET /auth/sessions', [AuthService::class, 'getSessions']);
Flight::route('POST /auth/logout-all', [AuthService::class, 'logoutAll']);

// Ruta de prueba
Flight::route('GET /', function(){
    Flight::json(array(
        'message' => 'API Secretaría v1.0',
        'status' => 'OK'
    ));
});
// Rutas de usuarios (protegidas)
Flight::route('GET /usuarios', [UsuariosService::class, 'obtenerTodos']);
Flight::route('GET /usuarios/@id', [UsuariosService::class, 'obtenerPorId']);
Flight::route('POST /usuarios', [UsuariosService::class, 'crear']);
Flight::route('PUT /usuarios', [UsuariosService::class, 'actualizar']);
Flight::route('DELETE /usuarios', [UsuariosService::class, 'eliminar']);
Flight::route('PATCH /usuarios/estado', [UsuariosService::class, 'cambiarEstado']);
Flight::route('POST /usuarios/roles', [UsuariosService::class, 'asignarRoles']);
// Iniciar Flight
Flight::start();