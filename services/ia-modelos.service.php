<?php
class IAModelosService
{
    public static function obtenerModeloPorDefecto($tipo)
    {
        $db = Flight::db();
        $stmt = $db->prepare("
            SELECT * FROM ia_modelos_config 
            WHERE tipo = :tipo 
            AND activo = 1 
            AND es_predeterminado = 1
            LIMIT 1
        ");
        $stmt->bindParam(':tipo', $tipo);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    public static function registrarUsoModelo($modeloId, $tokensUsados, $costoUsd = null)
    {
        // Registrar uso para tracking de costos
        $db = Flight::db();
        $stmt = $db->prepare("
            INSERT INTO ia_modelos_uso (
                modelo_id,
                tokens_usados,
                costo_usd,
                fecha_uso
            ) VALUES (
                :modelo_id,
                :tokens_usados,
                :costo_usd,
                NOW()
            )
        ");
        $stmt->bindParam(':modelo_id', $modeloId);
        $stmt->bindParam(':tokens_usados', $tokensUsados);
        $stmt->bindParam(':costo_usd', $costoUsd);
        $stmt->execute();
    }
}