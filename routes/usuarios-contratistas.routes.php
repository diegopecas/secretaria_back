<?php
Flight::route('POST /usuarios-contratistas', [UsuariosContratistasService::class, 'asignar']);
Flight::route('GET /usuarios-contratistas', [UsuariosContratistasService::class, 'obtenerPorUsuario']);
Flight::route('GET /usuarios-contratistas/mis-contratistas', [UsuariosContratistasService::class, 'obtenerMisContratistas']);
Flight::route('PUT /usuarios-contratistas', [UsuariosContratistasService::class, 'actualizar']);
Flight::route('DELETE /usuarios-contratistas', [UsuariosContratistasService::class, 'eliminar']);