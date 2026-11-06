<?php

namespace App\Console\Commands;

use App\Models\Configuracao;
use App\Services\Renovacao\RenovacaoConfig;
use Illuminate\Console\Command;

/**
 * Fase 202 (REN-07) — liga/desliga a renovação automática e o modo simulação
 * sem deploy. As chaves vivem em `configuracoes`.
 */
class ChaveRenovacao extends Command
{
    protected $signature = 'renovacao:chave {acao : status|ligar|desligar|simulacao-ligar|simulacao-desligar} {--antecedencia= : Antecedência da janela em meses (1 a 12)}';

    protected $description = 'Liga/desliga a renovação contratual automática e o modo simulação';

    public function handle(): int
    {
        $acao = (string) $this->argument('acao');

        if (! in_array($acao, ['status', 'ligar', 'desligar', 'simulacao-ligar', 'simulacao-desligar'], true)) {
            $this->error("Ação inválida: {$acao}. Use status|ligar|desligar|simulacao-ligar|simulacao-desligar.");

            return self::FAILURE;
        }

        $antecedencia = $this->option('antecedencia');
        if ($antecedencia !== null) {
            if (! ctype_digit((string) $antecedencia) || (int) $antecedencia < 1 || (int) $antecedencia > 12) {
                $this->error('--antecedencia deve ser um número de 1 a 12.');

                return self::FAILURE;
            }
            Configuracao::set(RenovacaoConfig::CHAVE_ANTECEDENCIA, (string) (int) $antecedencia);
        }

        match ($acao) {
            'ligar'              => Configuracao::set(RenovacaoConfig::CHAVE_ATIVA, '1'),
            'desligar'           => Configuracao::set(RenovacaoConfig::CHAVE_ATIVA, '0'),
            'simulacao-ligar'    => Configuracao::set(RenovacaoConfig::CHAVE_SIMULACAO, '1'),
            'simulacao-desligar' => Configuracao::set(RenovacaoConfig::CHAVE_SIMULACAO, '0'),
            default              => null,
        };

        $ativa     = RenovacaoConfig::ativa();
        $simulacao = RenovacaoConfig::simulacao();

        $this->line(RenovacaoConfig::CHAVE_ATIVA . ': ' . ($ativa ? 'LIGADA' : 'desligada'));
        $this->line(RenovacaoConfig::CHAVE_SIMULACAO . ': ' . ($simulacao ? 'ligada (nada é escrito no HubSpot)' : 'desligada (escreve de verdade)'));
        $this->line(RenovacaoConfig::CHAVE_ANTECEDENCIA . ': ' . RenovacaoConfig::antecedenciaMeses() . ' meses');
        $this->line('O agendador diário: ' . (! $ativa
            ? 'não faz nada (chave desligada).'
            : ($simulacao ? 'detecta e registra decisões em SIMULAÇÃO.' : 'cria negócios no HubSpot de verdade.')));

        return self::SUCCESS;
    }
}
