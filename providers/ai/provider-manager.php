<?php
// providers/ai/provider-manager.php

require_once __DIR__ . '/ai-provider.interface.php';
require_once __DIR__ . '/gemini.provider.php';
// require_once __DIR__ . '/openai.provider.php'; // Cuando se implemente
// require_once __DIR__ . '/anthropic.provider.php'; // Cuando se implemente

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
        // Cargar configuración desde config.php
        
        // Gemini
        if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '' && GEMINI_API_KEY !== 'tu-gemini-api-key-aqui') {
            try {
                $this->providers['gemini'] = new GeminiProvider(GEMINI_API_KEY);
                error_log("Provider Gemini inicializado correctamente");
            } catch (Exception $e) {
                error_log("Error inicializando Gemini: " . $e->getMessage());
            }
        } else {
            error_log("Gemini API key no configurada");
        }
        
        // OpenAI (cuando lo implementes)
        if (defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '' && OPENAI_API_KEY !== 'tu-openai-api-key-aqui') {
            try {
                // $this->providers['openai'] = new OpenAIProvider(OPENAI_API_KEY);
                error_log("Provider OpenAI pendiente de implementación");
            } catch (Exception $e) {
                error_log("Error inicializando OpenAI: " . $e->getMessage());
            }
        }
        
        // Anthropic (cuando lo implementes)
        if (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '' && ANTHROPIC_API_KEY !== 'tu-anthropic-api-key-aqui') {
            try {
                // $this->providers['anthropic'] = new AnthropicProvider(ANTHROPIC_API_KEY);
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
     * Actualizar disponibilidad de modelos en la BD según providers configurados
     */
    public function syncModelAvailability(): void
    {
        try {
            // Primero, desactivar todos los modelos de IA (excepto navegador)
            $stmt = $this->db->prepare("
                UPDATE ia_modelos_config 
                SET activo = 0 
                WHERE proveedor != 'navegador'
            ");
            $stmt->execute();
            
            // Activar solo los modelos de providers disponibles
            $providersDisponibles = $this->getAvailableProviders();
            
            if (!empty($providersDisponibles)) {
                $placeholders = array_fill(0, count($providersDisponibles), '?');
                $sql = "
                    UPDATE ia_modelos_config 
                    SET activo = 1 
                    WHERE proveedor IN (" . implode(',', $placeholders) . ")
                ";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute($providersDisponibles);
                
                error_log("Modelos activados para providers: " . implode(', ', $providersDisponibles));
            }
            
            // Siempre mantener activo el modelo del navegador
            $stmt = $this->db->prepare("
                UPDATE ia_modelos_config 
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
                // return $this->transcribirConOpenAI($provider, $audioPath, $modeloConfig);
                throw new Exception("OpenAI aún no está implementado");
                
            case 'anthropic':
                // return $this->transcribirConAnthropic($provider, $audioPath, $modeloConfig);
                throw new Exception("Anthropic aún no está implementado");
                
            default:
                throw new Exception("Provider $proveedor no soportado");
        }
    }
    
    /**
     * Transcribir con Gemini
     */
    private function transcribirConGemini($provider, $audioPath, $modeloConfig): array
    {
        // Por ahora retornar transcripción de prueba
        // TODO: Implementar transcripción real cuando tengamos el método en GeminiProvider
        
        $duracionSegundos = 60; // Estimado para pruebas
        
        return [
            'texto' => "Esta es una transcripción de prueba usando Gemini. En producción aquí aparecería el texto real transcrito del audio.",
            'duracion_segundos' => $duracionSegundos,
            'tokens_usados' => $duracionSegundos * 10, // Estimación
            'confianza' => 0.95
        ];
        
        // Cuando esté implementado en GeminiProvider:
        // return $provider->transcribirAudio($audioPath);
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