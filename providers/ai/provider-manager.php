<?php
// providers/ai/provider-manager.php

require_once __DIR__ . '/ai-provider.interface.php';
require_once __DIR__ . '/gemini.provider.php';
require_once __DIR__ . '/openai.provider.php'; // NUEVA LÍNEA

class ProviderManager
{
    private static $instance = null;
    private $providers = [];
    private $db;

    private function __construct()
    {
        $this->db = Flight::db();
        $this->initializeProviders();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initializeProviders()
    {
        require_once __DIR__ . '/../../services/configuracion.service.php';

        // Cargar configuración desde la base de datos
        $configIA = ConfiguracionService::getCategoria('ia');

        // Gemini
        $geminiKey = $configIA['gemini_api_key']['valor'] ?? '';
        if (!empty($geminiKey)) {
            try {
                $this->providers['gemini'] = new GeminiProvider($geminiKey);
            } catch (Exception $e) {
                error_log("Error inicializando Gemini: " . $e->getMessage());
            }
        } else {
            error_log("Gemini API key no configurada en la base de datos");
        }

        // OpenAI - ACTUALIZADO
        $openaiKey = $configIA['openai_api_key']['valor'] ?? '';
        if (!empty($openaiKey)) {
            try {
                $this->providers['openai'] = new OpenAIProvider($openaiKey); 
            } catch (Exception $e) {
                error_log("Error inicializando OpenAI: " . $e->getMessage());
            }
        } else {
            error_log("OpenAI API key no configurada en la base de datos");
        }

        // Anthropic
        $anthropicKey = $configIA['anthropic_api_key']['valor'] ?? '';
        if (!empty($anthropicKey)) {
            try {
                // $this->providers['anthropic'] = new AnthropicProvider($anthropicKey);
                error_log("Provider Anthropic pendiente de implementación");
            } catch (Exception $e) {
                error_log("Error inicializando Anthropic: " . $e->getMessage());
            }
        }
    }

    /**
     * Obtener un provider específico
     */
    public function getProvider(string $nombre): ?AIProviderInterface
    {
        return $this->providers[$nombre] ?? null;
    }

    /**
     * Verificar si un provider está disponible
     */
    public function hasProvider(string $nombre): bool
    {
        return isset($this->providers[$nombre]);
    }

    /**
     * Obtener lista de providers disponibles
     */
    public function getAvailableProviders(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Reinicializar providers (útil cuando se cambian API keys)
     */
    public function reinitialize(): void
    {
        $this->providers = [];
        $this->initializeProviders();
        $this->syncModelAvailability();
    }

    /**
     * Actualizar disponibilidad de modelos en la BD según providers configurados
     */
    public function syncModelAvailability(): void
    {
        try {
            // Primero, desactivar todos los modelos de IA (excepto navegador)
            $stmt = $this->db->prepare("
                UPDATE ia_modelos 
                SET activo = 0 
                WHERE proveedor != 'navegador'
            ");
            $stmt->execute();

            // Activar solo los modelos de providers disponibles
            $providersDisponibles = $this->getAvailableProviders();

            if (!empty($providersDisponibles)) {
                $placeholders = array_fill(0, count($providersDisponibles), '?');
                $sql = "
                    UPDATE ia_modelos 
                    SET activo = 1 
                    WHERE proveedor IN (" . implode(',', $placeholders) . ")
                ";

                $stmt = $this->db->prepare($sql);
                $stmt->execute($providersDisponibles);
            }

            // Siempre mantener activo el modelo del navegador
            $stmt = $this->db->prepare("
                UPDATE ia_modelos 
                SET activo = 1 
                WHERE proveedor = 'navegador'
            ");
            $stmt->execute();
        } catch (Exception $e) {
            error_log("Error sincronizando disponibilidad de modelos: " . $e->getMessage());
        }
    }

    /**
     * Transcribir audio usando un modelo específico
     */
    public function transcribirAudio($audioPath, $modeloConfig): array
    {
        $proveedor = $modeloConfig['proveedor'];

        if (!$this->hasProvider($proveedor)) {
            throw new Exception("Provider $proveedor no está configurado");
        }

        $provider = $this->getProvider($proveedor);

        // Delegar la transcripción al provider específico
        switch ($proveedor) {
            case 'gemini':
                return $this->transcribirConGemini($provider, $audioPath, $modeloConfig);

            case 'openai':
                return $this->transcribirConOpenAI($provider, $audioPath, $modeloConfig);

            case 'anthropic':
                throw new Exception("Anthropic aún no está implementado");

            default:
                throw new Exception("Provider $proveedor no soportado");
        }
    }

    /**
     * Transcribir con OpenAI Whisper
     */
    private function transcribirConOpenAI($provider, $audioPath, $modeloConfig): array
    {
        // Parsear configuración JSON si existe
        $configuracion = [];
        if (isset($modeloConfig['configuracion_json'])) {
            $configuracion = json_decode($modeloConfig['configuracion_json'], true) ?? [];
        }

        // Verificar tamaño del archivo
        $fileSize = filesize($audioPath);
        $maxSize = ($configuracion['max_file_size_mb'] ?? 25) * 1024 * 1024;
        
        if ($fileSize > $maxSize) {
            throw new Exception("El archivo excede el tamaño máximo permitido de 25MB");
        }

        // WebM es soportado directamente por Whisper, no necesitamos convertir
        // Simplemente llamar al método del provider
        return $provider->transcribirAudio($audioPath, $configuracion);
    }

    /**
     * Transcribir con Gemini (placeholder)
     */
    private function transcribirConGemini($provider, $audioPath, $modeloConfig): array
    {
        // Gemini no soporta transcripción directa de audio actualmente
        throw new Exception("Gemini no soporta transcripción de audio. Use OpenAI Whisper o Web Speech API.");
    }

    /**
     * Generar embeddings usando un modelo específico
     */
    public function generarEmbeddings($texto, $modeloConfig): array
    {
        $proveedor = $modeloConfig['proveedor'];

        if (!$this->hasProvider($proveedor)) {
            throw new Exception("Provider $proveedor no está configurado");
        }

        $provider = $this->getProvider($proveedor);

        // Usar el método de la interfaz
        return $provider->generarEmbeddings($texto);
    }

    /**
     * Obtener información de uso de todos los providers
     */
    public function getUsageInfo(): array
    {
        $info = [];

        foreach ($this->providers as $nombre => $provider) {
            $info[$nombre] = $provider->getUsoInfo();
        }

        return $info;
    }
}