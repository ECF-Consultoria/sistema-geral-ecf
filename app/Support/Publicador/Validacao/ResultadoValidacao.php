<?php

namespace App\Support\Publicador\Validacao;

/**
 * Os problemas de uma validação, prontos para a tela: agrupados por etapa
 * (`08` §3) e com o que bloqueia separado do que é só aviso.
 */
final class ResultadoValidacao
{
    /** @param list<Problema> $problemas */
    public function __construct(public readonly array $problemas) {}

    public function temBloqueio(): bool
    {
        foreach ($this->problemas as $p) {
            if ($p->bloqueia()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Problema> */
    public function bloqueios(): array
    {
        return array_values(array_filter($this->problemas, fn (Problema $p) => $p->bloqueia()));
    }

    /** @return array<string, list<Problema>> etapa (E0…E10) → problemas */
    public function porEtapa(): array
    {
        $grupos = [];
        foreach ($this->problemas as $p) {
            $grupos[$p->alvo['etapa'] ?? 'OUTROS'][] = $p;
        }
        ksort($grupos, SORT_NATURAL);

        return $grupos;
    }
}
