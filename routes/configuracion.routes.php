<?php
Flight::route('GET /configuracion/ia', [ConfiguracionIAService::class, 'obtener']);
Flight::route('PUT /configuracion/ia', [ConfiguracionIAService::class, 'actualizar']);