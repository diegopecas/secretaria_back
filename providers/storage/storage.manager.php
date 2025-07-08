<?php
// providers/storage/storage.manager.php

require_once __DIR__ . '/storage.interface.php';
require_once __DIR__ . '/local.storage.php';

class StorageManager
{
    private static $instance = null;
    private $driver;
    private $config;
    
    // Configuración de tipos de archivo permitidos
    private $allowedExtensions = [
        'documento' => ['pdf', 'doc', 'docx', 'txt', 'odt'],
        'imagen' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'],
        'hoja_calculo' => ['xls', 'xlsx', 'csv', 'ods'],
        'presentacion' => ['ppt', 'pptx', 'odp'],
        'transcripcion' => ['txt', 'doc', 'docx', 'pdf']
    ];
    
    private $maxFileSize = 10 * 1024 * 1024; // 10MB
    
    private function __construct()
    {
        // Cargar configuración
        $this->config = [
            'driver' => 'local', // Por defecto usa local
            'local' => [
                'base_path' => __DIR__ . '/../../uploads/',
                'base_url' => '/uploads/'
            ],
            's3' => [
                'bucket' => $_ENV['S3_BUCKET'] ?? 'secretaria-files',
                'region' => $_ENV['S3_REGION'] ?? 'us-east-1',
                'access_key' => $_ENV['S3_ACCESS_KEY'] ?? '',
                'secret_key' => $_ENV['S3_SECRET_KEY'] ?? ''
            ]
        ];
        
        $this->initializeDriver();
    }
    
    /**
     * Obtener instancia única (Singleton)
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Inicializar el driver de storage
     */
    private function initializeDriver()
    {
        switch ($this->config['driver']) {
            case 's3':
                // Cuando implementes S3:
                // require_once __DIR__ . '/s3.storage.php';
                // $this->driver = new S3Storage($this->config['s3']);
                throw new Exception('S3 Storage aún no implementado');
                break;
                
            case 'local':
            default:
                $this->driver = new LocalStorage($this->config['local']);
                break;
        }
    }
    
    /**
     * Obtener el driver actual
     */
    public function getDriver(): StorageInterface
    {
        return $this->driver;
    }
    
    /**
     * Guardar archivo con validaciones completas
     * @param array $file Archivo desde $_FILES
     * @param string $categoria Categoría del archivo (actividades, documentos, etc)
     * @param string|null $subfolder Subcarpeta adicional opcional
     * @return array Información del archivo guardado
     */
    public function guardarArchivo(array $file, string $categoria = 'actividades', ?string $subfolder = null): array
    {
        // 1. Validar el archivo
        $this->validarArchivo($file);
        
        // 2. Generar nombre único y path
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $nombreUnico = $this->generarNombreUnico($extension);
        
        // Construir path: categoria/año/mes/[subfolder]/archivo
        $pathParts = [$categoria, date('Y'), date('m')];
        if ($subfolder) {
            $pathParts[] = $subfolder;
        }
        $pathParts[] = $nombreUnico;
        
        $path = implode('/', $pathParts);
        
        // 3. Guardar usando el driver
        try {
            $resultado = $this->driver->guardar($file, $path);
            
            // 4. Agregar información adicional
            $resultado['nombre_original'] = $file['name'];
            $resultado['tipo_archivo'] = $this->detectarTipoArchivo($extension);
            $resultado['mime_type'] = $file['type'];
            $resultado['extension'] = $extension;
            
            return $resultado;
            
        } catch (Exception $e) {
            error_log("Error al guardar archivo: " . $e->getMessage());
            throw new Exception('Error al guardar el archivo: ' . $e->getMessage());
        }
    }
    
    /**
     * Obtener archivo
     */
    public function obtenerArchivo(string $path): ?array
    {
        return $this->driver->obtener($path);
    }
    
    /**
     * Eliminar archivo
     */
    public function eliminarArchivo(string $path): bool
    {
        return $this->driver->eliminar($path);
    }
    
    /**
     * Obtener URL pública del archivo
     */
    public function getUrl(string $path): string
    {
        return $this->driver->getUrl($path);
    }
    
    /**
     * Validar archivo antes de guardar
     */
    private function validarArchivo(array $file)
    {
        // Verificar error de upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception($this->getUploadErrorMessage($file['error']));
        }
        
        // Verificar tamaño
        if ($file['size'] > $this->maxFileSize) {
            throw new Exception('El archivo excede el tamaño máximo permitido (10MB)');
        }
        
        // Verificar extensión
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!$this->esExtensionPermitida($extension)) {
            throw new Exception('Tipo de archivo no permitido: .' . $extension);
        }
        
        // Verificar seguridad (MIME type)
        if (!$this->esArchivoSeguro($file['tmp_name'])) {
            throw new Exception('El archivo no pasó las validaciones de seguridad');
        }
    }
    
    /**
     * Verificar si la extensión está permitida
     */
    private function esExtensionPermitida(string $extension): bool
    {
        foreach ($this->allowedExtensions as $tipo => $extensiones) {
            if (in_array($extension, $extensiones)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Detectar tipo de archivo por extensión
     */
    private function detectarTipoArchivo(string $extension): string
    {
        foreach ($this->allowedExtensions as $tipo => $extensiones) {
            if (in_array($extension, $extensiones)) {
                return $tipo;
            }
        }
        return 'otro';
    }
    
    /**
     * Validar seguridad del archivo
     */
    private function esArchivoSeguro(string $rutaArchivo): bool
    {
        $mimeType = mime_content_type($rutaArchivo);
        
        // Lista negra de MIME types peligrosos
        $blacklist = [
            'application/x-httpd-php',
            'application/x-php',
            'application/php',
            'text/x-php',
            'application/x-executable',
            'application/x-sharedlib',
            'application/x-elf',
            'application/x-mach-binary'
        ];
        
        return !in_array($mimeType, $blacklist);
    }
    
    /**
     * Generar nombre único para el archivo
     */
    private function generarNombreUnico(string $extension): string
    {
        return sprintf(
            '%s_%s.%s',
            date('Ymd_His'),
            substr(uniqid('', true), -8),
            $extension
        );
    }
    
    /**
     * Obtener mensaje de error de upload
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido por el servidor',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente',
            UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en el disco',
            UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida del archivo',
        ];
        
        return $errors[$errorCode] ?? 'Error desconocido al subir el archivo';
    }
    
    /**
     * Obtener tamaño máximo permitido
     */
    public function getMaxFileSize(): int
    {
        return $this->maxFileSize;
    }
    
    /**
     * Obtener extensiones permitidas
     */
    public function getExtensionesPermitidas(): array
    {
        $todas = [];
        foreach ($this->allowedExtensions as $extensiones) {
            $todas = array_merge($todas, $extensiones);
        }
        return array_unique($todas);
    }
}