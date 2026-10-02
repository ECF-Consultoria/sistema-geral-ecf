<?php

namespace App\Support\Publicador\Schema;

use App\Support\Publicador\Variacao\Eixo;

/** O que {@see MigradorDeCategoria::migrar()} devolve. */
final class ResultadoMigracao
{
    /**
     * @param  array<string, array>  $atributos  os que valem na categoria nova (`origem = migrated`, `revisar = true`)
     * @param  list<array{id: string, nome: string, motivo: string, regra: string, mensagem: string}>  $descartados
     * @param  list<Eixo>  $eixos
     * @param  list<array{chave: string, nome: string, motivo: string}>  $eixosRemovidos
     */
    public function __construct(
        public readonly array $atributos,
        public readonly array $descartados,
        public readonly array $eixos,
        public readonly array $eixosRemovidos,
    ) {}
}
