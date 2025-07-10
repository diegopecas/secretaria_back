<?php
// Rutas de modelos IA
Flight::route('GET /ia-modelos/@tipo', [IAModelosService::class, 'obtenerPorTipo']);
Flight::route('POST /ia-modelos/transcribir', [IAModelosService::class, 'transcribir']);
Flight::route('GET /ia-modelos/uso/resumen', [IAModelosService::class, 'obtenerResumenUso']);
Flight::route('PUT /ia-modelos/@id/predeterminado', [IAModelosService::class, 'establecerPredeterminado']);