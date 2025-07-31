<?php
// Generar embeddings para obligaciones de un contrato
Flight::route('POST /obligaciones/generar-embeddings/@contrato_id', [ObligacionesService::class, 'generarEmbeddings']);

// Procesar obligaciones pendientes (batch)
Flight::route('POST /obligaciones/procesar-pendientes', [ObligacionesService::class, 'procesarPendientes']);