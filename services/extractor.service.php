<?php
class ExtractorService
{
    /**
     * Extraer texto de un archivo según su tipo
     */
    public static function extraerTexto($archivoPath, $tipoArchivo)
    {
        try {
            // Verificar que el archivo existe
            if (!file_exists($archivoPath)) {
                throw new Exception("Archivo no encontrado: $archivoPath");
            }

            $extension = strtolower(pathinfo($archivoPath, PATHINFO_EXTENSION));
            
            switch ($extension) {
                case 'txt':
                    return self::extraerTXT($archivoPath);
                    
                case 'pdf':
                    return self::extraerPDF($archivoPath);
                    
                case 'doc':
                case 'docx':
                    return self::extraerWord($archivoPath);
                    
                case 'csv':
                    return self::extraerCSV($archivoPath);
                    
                case 'json':
                    return self::extraerJSON($archivoPath);
                    
                case 'html':
                case 'htm':
                    return self::extraerHTML($archivoPath);
                    
                // Tipos que requieren procesamiento especial
                case 'jpg':
                case 'jpeg':
                case 'png':
                case 'gif':
                case 'bmp':
                    // Por ahora retornar null, en el futuro usar OCR/GPT-4 Vision
                    return null;
                    
                case 'mp3':
                case 'wav':
                case 'ogg':
                case 'webm':
                case 'm4a':
                    // Audio ya se maneja con Whisper en otro flujo
                    return null;
                    
                default:
                    // Intentar leer como texto plano
                    $contenido = @file_get_contents($archivoPath);
                    if ($contenido !== false && mb_check_encoding($contenido, 'UTF-8')) {
                        return $contenido;
                    }
                    return null;
            }
            
        } catch (Exception $e) {
            error_log("Error extrayendo texto de $archivoPath: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extraer texto de archivo TXT
     */
    private static function extraerTXT($archivoPath)
    {
        $contenido = file_get_contents($archivoPath);
        
        // Detectar y convertir encoding si es necesario
        $encoding = mb_detect_encoding($contenido, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', $encoding);
        }
        
        // Limpiar caracteres no imprimibles
        $contenido = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $contenido);
        
        return trim($contenido);
    }

    /**
     * Extraer texto de PDF
     * Requiere: composer require smalot/pdfparser
     */
    private static function extraerPDF($archivoPath)
    {
        try {
            // Verificar si la librería está instalada
            if (!class_exists('\Smalot\PdfParser\Parser')) {
                error_log("PDFParser no instalado. Ejecute: composer require smalot/pdfparser");
                return null;
            }

            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($archivoPath);
            
            $texto = $pdf->getText();
            
            // Limpiar texto
            $texto = preg_replace('/\s+/', ' ', $texto); // Normalizar espacios
            $texto = trim($texto);
            
            // Si el PDF está protegido o no tiene texto
            if (empty($texto)) {
                return null;
            }
            
            return $texto;
            
        } catch (Exception $e) {
            error_log("Error extrayendo PDF: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Extraer texto de Word
     * Requiere: composer require phpoffice/phpword
     */
    private static function extraerWord($archivoPath)
    {
        try {
            // Verificar si la librería está instalada
            if (!class_exists('\PhpOffice\PhpWord\IOFactory')) {
                error_log("PHPWord no instalado. Ejecute: composer require phpoffice/phpword");
                return null;
            }

            $phpWord = \PhpOffice\PhpWord\IOFactory::load($archivoPath);
            
            $texto = '';
            
            // Recorrer todas las secciones
            foreach ($phpWord->getSections() as $section) {
                // Obtener elementos de la sección
                $elements = $section->getElements();
                
                foreach ($elements as $element) {
                    // Manejar diferentes tipos de elementos
                    if (method_exists($element, 'getText')) {
                        $texto .= $element->getText() . "\n";
                    } elseif (method_exists($element, 'getElements')) {
                        // Elementos complejos como tablas
                        $texto .= self::extraerElementosWord($element) . "\n";
                    }
                }
            }
            
            return trim($texto);
            
        } catch (Exception $e) {
            error_log("Error extrayendo Word: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Extraer elementos complejos de Word
     */
    private static function extraerElementosWord($element)
    {
        $texto = '';
        
        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $subelemento) {
                if (method_exists($subelemento, 'getText')) {
                    $texto .= $subelemento->getText() . " ";
                } else {
                    $texto .= self::extraerElementosWord($subelemento);
                }
            }
        }
        
        return $texto;
    }

    /**
     * Extraer texto de CSV
     */
    private static function extraerCSV($archivoPath)
    {
        try {
            $contenido = '';
            
            if (($handle = fopen($archivoPath, 'r')) !== false) {
                // Detectar delimitador
                $firstLine = fgets($handle);
                rewind($handle);
                
                $delimitadores = [',', ';', "\t", '|'];
                $delimiter = ',';
                
                foreach ($delimitadores as $d) {
                    if (substr_count($firstLine, $d) > 0) {
                        $delimiter = $d;
                        break;
                    }
                }
                
                // Leer CSV
                while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                    $contenido .= implode(' ', $data) . "\n";
                }
                
                fclose($handle);
            }
            
            return trim($contenido);
            
        } catch (Exception $e) {
            error_log("Error extrayendo CSV: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Extraer texto de JSON
     */
    private static function extraerJSON($archivoPath)
    {
        try {
            $json = file_get_contents($archivoPath);
            $data = json_decode($json, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
            
            // Convertir estructura a texto legible
            return self::jsonATexto($data);
            
        } catch (Exception $e) {
            error_log("Error extrayendo JSON: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Convertir estructura JSON a texto
     */
    private static function jsonATexto($data, $nivel = 0)
    {
        $texto = '';
        $indentacion = str_repeat('  ', $nivel);
        
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $texto .= $indentacion . "$key:\n";
                    $texto .= self::jsonATexto($value, $nivel + 1);
                } else {
                    $texto .= $indentacion . "$key: $value\n";
                }
            }
        } elseif (is_object($data)) {
            $texto .= self::jsonATexto((array)$data, $nivel);
        } else {
            $texto .= $indentacion . $data . "\n";
        }
        
        return $texto;
    }

    /**
     * Extraer texto de HTML
     */
    private static function extraerHTML($archivoPath)
    {
        try {
            $html = file_get_contents($archivoPath);
            
            // Eliminar scripts y estilos
            $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $html);
            $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $html);
            
            // Convertir saltos de línea HTML
            $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
            $html = preg_replace('/<\/p>/i', "\n\n", $html);
            $html = preg_replace('/<\/div>/i', "\n", $html);
            
            // Eliminar todas las etiquetas HTML
            $texto = strip_tags($html);
            
            // Limpiar espacios múltiples
            $texto = preg_replace('/\s+/', ' ', $texto);
            $texto = preg_replace('/\n\s+/', "\n", $texto);
            $texto = preg_replace('/\n+/', "\n\n", $texto);
            
            // Decodificar entidades HTML
            $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            return trim($texto);
            
        } catch (Exception $e) {
            error_log("Error extrayendo HTML: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verificar si un archivo puede ser procesado
     */
    public static function puedeExtraerTexto($extension)
    {
        $extensionesTexto = [
            'txt', 'pdf', 'doc', 'docx', 
            'csv', 'json', 'html', 'htm',
            'rtf', 'odt', 'xml', 'log'
        ];
        
        return in_array(strtolower($extension), $extensionesTexto);
    }

    /**
     * Obtener resumen de texto largo
     */
    public static function obtenerResumen($texto, $longitudMaxima = 1000)
    {
        if (strlen($texto) <= $longitudMaxima) {
            return $texto;
        }
        
        // Intentar cortar en un punto natural (punto, salto de línea)
        $textoCortado = substr($texto, 0, $longitudMaxima);
        
        // Buscar el último punto o salto de línea
        $ultimoPunto = strrpos($textoCortado, '.');
        $ultimoSalto = strrpos($textoCortado, "\n");
        
        $puntoCorte = max($ultimoPunto, $ultimoSalto);
        
        if ($puntoCorte > $longitudMaxima * 0.8) {
            return substr($texto, 0, $puntoCorte + 1) . '...';
        }
        
        return $textoCortado . '...';
    }

    /**
     * Estimar tiempo de lectura
     */
    public static function estimarTiempoLectura($texto)
    {
        // Promedio de palabras por minuto: 200
        $palabras = str_word_count($texto);
        $minutos = ceil($palabras / 200);
        
        return [
            'palabras' => $palabras,
            'minutos' => $minutos,
            'texto' => $minutos == 1 ? '1 minuto' : "$minutos minutos"
        ];
    }
}