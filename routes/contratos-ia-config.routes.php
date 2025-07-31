<?php
// Obtener configuración de un contrato
Flight::route('GET /contratos-ia-config/@contrato_id', [ContratosIAConfigService::class, 'obtenerConfiguracion']);

// Guardar/actualizar configuración
Flight::route('POST /contratos-ia-config', [ContratosIAConfigService::class, 'guardarConfiguracion']);

// Eliminar configuración
Flight::route('DELETE /contratos-ia-config', [ContratosIAConfigService::class, 'eliminarConfiguracion']);