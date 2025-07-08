<?php
// providers/storage/storage.interface.php

interface StorageInterface
{
    /**
     * Guardar archivo
     * @param array $file Archivo desde $_FILES
     * @param string $path Ruta relativa donde guardar
     * @return array Información del archivo guardado
     */
    public function guardar(array $file, string $path): array;
    
    /**
     * Obtener archivo
     * @param string $path Ruta relativa del archivo
     * @return array|null Información del archivo o null si no existe
     */
    public function obtener(string $path): ?array;
    
    /**
     * Eliminar archivo
     * @param string $path Ruta relativa del archivo
     * @return bool
     */
    public function eliminar(string $path): bool;
    
    /**
     * Obtener URL pública del archivo
     * @param string $path Ruta relativa del archivo
     * @return string URL pública
     */
    public function getUrl(string $path): string;
    
    /**
     * Verificar si el archivo existe
     * @param string $path Ruta relativa del archivo
     * @return bool
     */
    public function existe(string $path): bool;
}