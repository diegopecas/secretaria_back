<?php

class ConfiguracionIAService
{
    /**
     * Obtener configuración de IA
     */
    public static function obtener()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            // Solo administradores
            if (!AuthService::checkPermission($currentUser['id'], 'sistema.configurar')) {
                responderJSON(['error' => 'No tiene permisos'], 403);
                return;
            }

            $configIA = ConfiguracionService::getCategoria('ia');

            // Ocultar las API keys parcialmente por seguridad
            foreach ($configIA as $clave => &$config) {
                if (strpos($clave, '_api_key') !== false && !empty($config['valor'])) {
                    $config['valor'] = substr($config['valor'], 0, 8) . '...' . substr($config['valor'], -4);
                    $config['oculto'] = true;
                }
            }

            responderJSON([
                'success' => true,
                'configuracion' => $configIA
            ]);
        } catch (Exception $e) {
            error_log("Error obteniendo configuración IA: " . $e->getMessage());
            responderJSON(['error' => 'Error al obtener configuración'], 500);
        }
    }

    /**
     * Actualizar configuración
     */
    public static function actualizar()
    {
        try {
            requireAuth();
            $currentUser = Flight::get('currentUser');

            // Solo administradores
            if (!AuthService::checkPermission($currentUser['id'], 'sistema.configurar')) {
                responderJSON(['error' => 'No tiene permisos'], 403);
                return;
            }

            $data = Flight::request()->data->getData();

            if (!isset($data['clave']) || !isset($data['valor'])) {
                responderJSON(['error' => 'Datos incompletos'], 400);
                return;
            }

            // No actualizar si es un valor oculto (contiene ...)
            if (strpos($data['valor'], '...') !== false) {
                responderJSON(['error' => 'No se puede actualizar con valor oculto'], 400);
                return;
            }

            $resultado = ConfiguracionService::set($data['clave'], $data['valor'], 'ia');

            if ($resultado) {
                // Reinicializar providers si se cambió una API key
                if (strpos($data['clave'], '_api_key') !== false) {
                    ProviderManager::getInstance()->reinitialize();
                }

                responderJSON([
                    'success' => true,
                    'message' => 'Configuración actualizada correctamente'
                ]);
            } else {
                responderJSON(['error' => 'Error al actualizar configuración'], 500);
            }
        } catch (Exception $e) {
            error_log("Error actualizando configuración IA: " . $e->getMessage());
            responderJSON(['error' => 'Error al actualizar'], 500);
        }
    }
}
