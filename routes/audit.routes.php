<?php
// Rutas de auditoría (todas protegidas)
Flight::route('GET /auditoria/historial/@tabla/@id', function($tabla, $id) {
    AuditService::obtenerHistorial($tabla, $id);
});

Flight::route('GET /auditoria/buscar', [AuditService::class, 'buscar']);