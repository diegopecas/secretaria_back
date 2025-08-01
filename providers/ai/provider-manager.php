<?php
require_once __DIR__ . '/ai-provider.interface.php';
require_once __DIR__ . '/gemini.provider.php';
require_once __DIR__ . '/openai.provider.php';

class ProviderManager
{
    private static $instance = null;
    private $providers = [];
    private $providersContrato = []; // Cache de providers por contrato
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

        // Cargar configuración global desde la base de datos
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

        // OpenAI
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
     * Obtener un provider específico (con opción de usar configuración del contrato)
     */
    public function getProvider(string $nombre, $contratoId = null): ?AIProviderInterface
    {
        // Si no hay contrato, usar provider global
        if (!$contratoId) {
            return $this->providers[$nombre] ?? null;
        }

        // Verificar cache de providers por contrato
        $cacheKey = "{$contratoId}_{$nombre}";
        if (isset($this->providersContrato[$cacheKey])) {
            return $this->providersContrato[$cacheKey];
        }

        // Intentar obtener configuración específica del contrato
        require_once __DIR__ . '/../../services/contratos-ia-config.service.php';
        $configContrato = ContratosIAConfigService::obtenerConfiguracionInterna($contratoId, $nombre);

        if ($configContrato) {
            error_log("Usando configuración específica del contrato $contratoId para provider $nombre");
            
            try {
                // Crear instancia del provider con configuración del contrato
                $provider = $this->crearProviderConConfiguracion($nombre, $configContrato);
                
                // Guardar en cache
                $this->providersContrato[$cacheKey] = $provider;
                
                return $provider;
            } catch (Exception $e) {
                error_log("Error creando provider con config del contrato: " . $e->getMessage());
                error_log("Fallback a configuración global");
            }
        }

        // Fallback a provider global
        return $this->providers[$nombre] ?? null;
    }

    /**
     * Crear provider con configuración específica
     */
    private function crearProviderConConfiguracion($nombre, $config)
    {
        switch ($nombre) {
            case 'openai':
                if (!isset($config['api_key'])) {
                    throw new Exception("API key no encontrada en configuración");
                }
                return new OpenAIProvider($config['api_key']);

            case 'gemini':
                if (!isset($config['api_key'])) {
                    throw new Exception("API key no encontrada en configuración");
                }
                return new GeminiProvider($config['api_key']);

            case 'anthropic':
                // return new AnthropicProvider($config['api_key']);
                throw new Exception("Anthropic aún no está implementado");

            default:
                throw new Exception("Provider $nombre no soportado");
        }
    }

    /**
     * Verificar si un provider está disponible (global o para un contrato)
     */
    public function hasProvider(string $nombre, $contratoId = null): bool
    {
        if ($contratoId) {
            // Verificar si hay configuración específica del contrato
            require_once __DIR__ . '/../../services/contratos-ia-config.service.php';
            $configContrato = ContratosIAConfigService::obtenerConfiguracionInterna($contratoId, $nombre);
            
            if ($configContrato) {
                return true;
            }
        }
        
        // Verificar provider global
        return isset($this->providers[$nombre]);
    }

    /**
     * Obtener lista de providers disponibles (global o para un contrato)
     */
    public function getAvailableProviders($contratoId = null): array
    {
        $disponibles = array_keys($this->providers);
        
        if ($contratoId) {
            // Agregar providers configurados específicamente para el contrato
            $stmt = $this->db->prepare("
                SELECT DISTINCT proveedor
                FROM contratos_ia_config
                WHERE contrato_id = :contrato_id
                AND activa = 1
            ");
            $stmt->bindParam(':contrato_id', $contratoId);
            $stmt->execute();
            
            while ($row = $stmt->fetch()) {
                if (!in_array($row['proveedor'], $disponibles)) {
                    $disponibles[] = $row['proveedor'];
                }
            }
        }
        
        return $disponibles;
    }

    /**
     * Reinicializar providers (útil cuando se cambian API keys)
     */
    public function reinitialize(): void
    {
        $this->providers = [];
        $this->providersContrato = []; // Limpiar cache de contratos
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

            // Activar solo los modelos de providers disponibles globalmente
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
    public function transcribirAudio($audioPath, $modeloConfig, $contratoId = null): array
    {
        $proveedor = $modeloConfig['proveedor'];

        // Obtener provider (con posible configuración del contrato)
        $provider = $this->getProvider($proveedor, $contratoId);
        
        if (!$provider) {
            throw new Exception("Provider $proveedor no está configurado");
        }

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

        // WebM es soportado directamente por Whisper
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
     * Generar embeddings usando un modelo específico (actualizado para contrato)
     */
    public function generarEmbeddings($texto, $modeloConfig, $contratoId = null): array
    {
        $proveedor = $modeloConfig['proveedor'];

        // Obtener provider (con posible configuración del contrato)
        $provider = $this->getProvider($proveedor, $contratoId);
        
        if (!$provider) {
            throw new Exception("Provider $proveedor no está configurado");
        }

        // Usar el método de la interfaz
        return $provider->generarEmbeddings($texto);
    }

    /**
     * Obtener información de uso de todos los providers
     */
    public function getUsageInfo(): array
    {
        $info = [];

        // Info de providers globales
        foreach ($this->providers as $nombre => $provider) {
            $info['global'][$nombre] = $provider->getUsoInfo();
        }

        // Info de providers por contrato (del cache)
        foreach ($this->providersContrato as $cacheKey => $provider) {
            list($contratoId, $providerNombre) = explode('_', $cacheKey, 2);
            $info['contratos'][$contratoId][$providerNombre] = $provider->getUsoInfo();
        }

        return $info;
    }

    /**
     * Limpiar cache de un contrato específico
     */
    public function limpiarCacheContrato($contratoId): void
    {
        foreach ($this->providersContrato as $key => $provider) {
            if (strpos($key, "{$contratoId}_") === 0) {
                unset($this->providersContrato[$key]);
            }
        }
    }
}