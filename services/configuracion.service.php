<?php
class ConfiguracionService 
{
    private static $cache = [];
    
    /**
     * Obtener valor de configuración
     */
    public static function get($clave, $categoria = null, $default = null) 
    {
        $cacheKey = $categoria ? "$categoria.$clave" : $clave;
        
        // Verificar cache
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }
        
        try {
            $db = Flight::db();
            $sql = "SELECT valor, tipo FROM configuracion_sistema WHERE clave = :clave";
            $params = [':clave' => $clave];
            
            if ($categoria) {
                $sql .= " AND categoria = :categoria";
                $params[':categoria'] = $categoria;
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $config = $stmt->fetch();
            
            if (!$config) {
                return $default;
            }
            
            // Convertir según el tipo
            $valor = self::convertirValor($config['valor'], $config['tipo']);
            
            // Guardar en cache
            self::$cache[$cacheKey] = $valor;
            
            return $valor;
            
        } catch (Exception $e) {
            error_log("Error obteniendo configuración $clave: " . $e->getMessage());
            return $default;
        }
    }
    
    /**
     * Establecer valor de configuración
     */
    public static function set($clave, $valor, $categoria = null) 
    {
        try {
            $db = Flight::db();
            
            $sql = "UPDATE configuracion_sistema SET valor = :valor WHERE clave = :clave";
            $params = [':clave' => $clave, ':valor' => $valor];
            
            if ($categoria) {
                $sql .= " AND categoria = :categoria";
                $params[':categoria'] = $categoria;
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            // Limpiar cache
            $cacheKey = $categoria ? "$categoria.$clave" : $clave;
            unset(self::$cache[$cacheKey]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error actualizando configuración $clave: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener todas las configuraciones de una categoría
     */
    public static function getCategoria($categoria) 
    {
        try {
            $db = Flight::db();
            
            $stmt = $db->prepare("
                SELECT clave, valor, tipo, descripcion, editable
                FROM configuracion_sistema 
                WHERE categoria = :categoria
                ORDER BY clave
            ");
            $stmt->execute([':categoria' => $categoria]);
            
            $configs = [];
            while ($row = $stmt->fetch()) {
                $configs[$row['clave']] = [
                    'valor' => self::convertirValor($row['valor'], $row['tipo']),
                    'tipo' => $row['tipo'],
                    'descripcion' => $row['descripcion'],
                    'editable' => $row['editable']
                ];
            }
            
            return $configs;
            
        } catch (Exception $e) {
            error_log("Error obteniendo categoría $categoria: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Limpiar toda la cache
     */
    public static function clearCache() 
    {
        self::$cache = [];
    }
    
    /**
     * Convertir valor según tipo
     */
    private static function convertirValor($valor, $tipo) 
    {
        switch ($tipo) {
            case 'integer':
                return (int)$valor;
            case 'json':
                return json_decode($valor, true);
            case 'boolean':
                return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
            default:
                return $valor;
        }
    }
}