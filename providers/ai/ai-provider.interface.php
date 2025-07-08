<?php
// providers/ai/AIProviderInterface.php

interface AIProviderInterface
{
    /**
     * Generar embeddings para búsqueda semántica
     * @param string $texto Texto a procesar
     * @return array Vector de embeddings
     */
    public function generarEmbeddings(string $texto): array;
    
    /**
     * Analizar actividades y asociar con obligaciones
     * @param array $actividades Array de actividades del mes
     * @param array $obligaciones Array de obligaciones del contrato
     * @return array Análisis con asociaciones
     */
    public function analizarActividades(array $actividades, array $obligaciones): array;
    
    /**
     * Buscar actividades por pregunta en lenguaje natural
     * @param string $pregunta Pregunta del usuario
     * @param array $embeddings Array de embeddings de actividades
     * @return array IDs de actividades relevantes ordenadas por relevancia
     */
    public function buscarPorPregunta(string $pregunta, array $embeddings): array;
    
    /**
     * Generar cuenta de cobro basada en actividades
     * @param array $datosContrato Información del contrato
     * @param array $actividadesAnalizadas Actividades ya procesadas
     * @param array $cuentasAnteriores Cuentas de cobro anteriores para referencia
     * @return array Estructura de la cuenta de cobro
     */
    public function generarCuentaCobro(array $datosContrato, array $actividadesAnalizadas, array $cuentasAnteriores = []): array;
    
    /**
     * Obtener el nombre del proveedor
     * @return string
     */
    public function getNombre(): string;
    
    /**
     * Obtener información de uso (tokens, costo, etc)
     * @return array
     */
    public function getUsoInfo(): array;
}