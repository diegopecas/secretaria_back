<?php
// index.php - Punto de entrada de la API
require 'vendor/autoload.php';
require 'config.php';

// Cargar servicios
require 'services/auth.service.php';
require 'services/usuarios.service.php';

// Configurar Flight
Flight::set('flight.base_url', '/');
Flight::set('flight.case_sensitive', false);

// AGREGAR ESTA SECCIÓN PARA CARGAR LAS RUTAS
// Rutas separadas
foreach (glob(__DIR__ . '/routes/*.routes.php') as $routeFile) {
    require_once $routeFile;
}

// Ruta de prueba
Flight::route('GET /', function(){
    Flight::json(array(
        'message' => 'API Secretaría v1.0',
        'status' => 'OK'
    ));
});

// Iniciar Flight
Flight::start();