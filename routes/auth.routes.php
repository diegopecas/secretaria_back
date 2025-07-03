<?php
// Rutas de autenticación (públicas)
Flight::route('POST /auth/login', [AuthService::class, 'login']);
Flight::route('POST /auth/register', [AuthService::class, 'register']);
Flight::route('POST /auth/logout', [AuthService::class, 'logout']);
Flight::route('GET /auth/validate', [AuthService::class, 'validateSession']);
Flight::route('POST /auth/refresh', [AuthService::class, 'refreshToken']);

// Rutas protegidas de autenticación
Flight::route('GET /auth/sessions', [AuthService::class, 'getSessions']);
Flight::route('POST /auth/logout-all', [AuthService::class, 'logoutAll']);