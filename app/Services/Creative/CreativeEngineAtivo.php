<?php

namespace App\Services\Creative;

use App\Models\Configuracao;

/**
 * CreativeEngineAtivo — interruptor liga/desliga do Creative Engine (OPS-03).
 *
 * ⚠️ A chave OPERACIONAL é o registro em `configuracoes` — ligar em produção
 * é `Configuracao::set(self::CHAVE, '1')`, SEM deploy e SEM `config:cache`.
 * O `CREATIVE_ENGINE_ENABLED` do `.env` (lido pelo spike em
 * `config('services.creative.enabled')`) fica só como DEFAULT de ambiente,
 * usado quando ainda não existe registro em `configuracoes` — nunca a fonte
 * de verdade em produção. Mesmo padrão de `FechamentoRegraTabela` (Fase 141).
 *
 * NÃO criar seed nem migration gravando valor aqui: "desligado" é a
 * AUSÊNCIA de registro (cai no default do config, hoje `false`).
 */
class CreativeEngineAtivo
{
    /**
     * Chave em `configuracoes`. Contrato entre este leitor, o controller
     * (`MlbAnuncioController`) e o front (`AnunciarML.jsx`/`PainelCriativosIa.jsx`)
     * — não renomear sem atualizar as três pontas.
     */
    public const CHAVE = 'creative_engine_ativo';

    /**
     * Memória da leitura desta instância. `null` = ainda não lida; não
     * confundir com "lida e desligada" (`false`).
     */
    private ?bool $memoria = null;

    /**
     * Lê a flag, memoizando o resultado na instância.
     *
     * Convenção do projeto: booleano persistido como string '1'/'0' — qualquer
     * valor diferente de '1' (inclusive 'true', '' e '0') é lido como desligado.
     */
    public function ativa(): bool
    {
        if ($this->memoria === null) {
            $valor = Configuracao::get(self::CHAVE, null);

            $this->memoria = $valor === null
                ? (bool) config('services.creative.enabled', false)
                : $valor === '1';
        }

        return $this->memoria;
    }

    /** Limpa a memória desta instância (testes e execuções que viram a flag no meio). */
    public function esquecer(): void
    {
        $this->memoria = null;
    }

    /**
     * Força o valor que `ativa()` devolve, SEM tocar em `configuracoes` —
     * uso exclusivo de testes. Ligar a flag de verdade é
     * `Configuracao::set(self::CHAVE, '1')`.
     */
    public function forcar(?bool $valor): void
    {
        $this->memoria = $valor;
    }
}
