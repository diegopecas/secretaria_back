<?php
// providers/storage/local.storage.php

require_once __DIR__ . '/storage.interface.php';

class LocalStorage implements StorageInterface
{
    private $basePath;
    private $baseUrl;
    
    public function __construct($config = [])
    {
        $this->basePath = $config['base_path'] ?? __DIR__ . '/../../uploads/';
        $this->baseUrl = $config['base_url'] ?? '/uploads/';
    }
    
    /**
     * Guardar archivo en el sistema local
     */
    public function guardar(array $file, string $path): array
    {
        $fullPath = $this->basePath . $path;
        $directory = dirname($fullPath);
        
        // Crear directorio si no existe
        if (!file_exists($directory)) {
            if (!mkdir($directory, 0777, true)) {
                throw new Exception('No se pudo crear el directorio: ' . $directory);
            }
        }
        
        // Mover archivo
        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            throw new Exception('Error al guardar archivo en almacenamiento local');
        }
        
        // Retornar información del archivo guardado
        return [
            'path' => $path,
            'size' => $file['size'],
            'url' => $this->getUrl($path),
            'hash' => hash_file('sha256', $fullPath)
        ];
    }
    
    /**
     * Obtener archivo del sistema local
     */
    public function obtener(string $path): ?array
    {
        $fullPath = $this->basePath . $path;
        
        if (!file_exists($fullPath)) {
            return null;
        }
        
        return [
            'path' => $path,
            'content' => file_get_contents($fullPath),
            'size' => filesize($fullPath),
            'url' => $this->getUrl($path),
            'mime_type' => mime_content_type($fullPath)
        ];
    }
    
    /**
     * Eliminar archivo del sistema local
     */
    public function eliminar(string $path): bool
    {
        $fullPath = $this->basePath . $path;
        
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        
        return true; // Si no existe, consideramos que está "eliminado"
    }
    
    /**
     * Obtener URL pública del archivo
     */
    public function getUrl(string $path): string
    {
        // Esto asume que tienes configurado tu servidor para servir archivos desde /uploads/
        return $this->baseUrl . $path;
    }
    
    /**
     * Verificar si el archivo existe
     */
    public function existe(string $path): bool
    {
        return file_exists($this->basePath . $path);
    }
    
    /**
     * Obtener la ruta completa del archivo (uso interno)
     */
    public function getFullPath(string $path): string
    {
        return $this->basePath . $path;
    }
}