<?php
// Iniciar o continuar conversación
Flight::route('POST /chat/conversar', [ChatService::class, 'iniciarConversacion']);
Flight::route('GET /chat/conversar-stream', [ChatService::class, 'iniciarConversacionStream']);

// Obtener historial de una conversación
Flight::route('GET /chat/historial', [ChatService::class, 'obtenerHistorialConversacion']);

// Listar sesiones de chat
Flight::route('GET /chat/sesiones', [ChatService::class, 'listarSesiones']);

// Generar resumen de contrato
Flight::route('POST /chat/generar-resumen', [ChatService::class, 'generarResumenContrato']);
