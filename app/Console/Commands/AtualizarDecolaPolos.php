<?php

namespace App\Console\Commands;

use App\Models\MlbImplementacao;
use App\Models\PoloDecolaStatus;
use App\Services\Polos\DecolaMercadoLivre;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pergunta ao Mercado Livre se o Programa Decola está ativo em cada empresa dos polos que
 * conectou a conta, e marca a ficha como "Sim" (TKT-0013). Regra em DecolaMercadoLivre.
 *
 * Sem `--apply` não grava nada: só mostra o que o ML respondeu por empresa e o que mudaria.
 * É o jeito de conferir a resposta real do ML antes de confiar na rotina. O agendamento
 * (08:30, routes/console.php) roda com `--apply`.
 *
 *   php artisan polos:decola-status            → só mostra
 *   php artisan polos:decola-status --apply    → grava
 */
class AtualizarDecolaPolos extends Command
{
    protected $signature = 'polos:decola-status
        {--apply : Grava de fato (padrão é só mostrar o que o Mercado Livre respondeu)}';

    protected $description = 'Marca Decola = Sim nas empresas dos polos em que o Mercado Livre informa o programa ativo.';

    public function handle(DecolaMercadoLivre $decola): int
    {
        $apply    = (bool) $this->option('apply');
        $inicio   = now();
        $empresas = DecolaMercadoLivre::empresas();

        $respostas      = [];   // "TIPO / STATUS" → quantas empresas
        $semResposta    = [];
        $contaDiferente = [];
        $promovidas     = [];   // empresa_id → nome

        foreach ($empresas as $empresa) {
            $rotulo = "#{$empresa->id} {$empresa->nome}";

            if (! $decola->contaConfere($empresa)) {
                $contaDiferente[] = $rotulo;
                continue;
            }

            $leitura = $decola->ler($empresa);
            if ($leitura === null) {
                $semResposta[] = $rotulo;
                continue;
            }

            $chave = ($leitura['tipo'] ?? '(sem tipo)') . ' / ' . $leitura['status'];
            $respostas[$chave] = ($respostas[$chave] ?? 0) + 1;

            $ativo = $leitura['tipo'] === PoloDecolaStatus::TIPO_DECOLA
                && in_array($leitura['status'], PoloDecolaStatus::STATUS_ATIVO, true);

            if (! $apply) {
                $fichaAtual = MlbImplementacao::where('empresa_id', $empresa->id)->value('decola');
                $this->line("  {$rotulo} — {$chave} — ficha: " . ($fichaAtual ?? '(vazio ou sem ficha)')
                    . ($ativo && $fichaAtual !== 'Sim' ? ' → viraria Sim' : ''));
                continue;
            }

            try {
                if ($decola->gravar($empresa, $leitura)) {
                    $promovidas[$empresa->id] = $empresa->nome;
                }
            } catch (\Throwable $e) {
                Log::error("[Polos Decola] empresa {$empresa->id} ({$empresa->nome}): falha ao gravar — {$e->getMessage()}");
                $semResposta[] = $rotulo;
            }
        }

        $agora = now()->format('Y-m-d H:i');
        $this->line("[{$agora}] polos:decola-status" . ($apply ? '' : ' (só leitura)') . ' — '
            . $empresas->count() . ' empresas com conta conectada; respostas: '
            . ($respostas ? collect($respostas)->map(fn ($n, $k) => "{$k}: {$n}")->implode('; ') : 'nenhuma') . '.');

        if ($apply) {
            // Resumo por RECONSULTA ao banco, não pelo retorno do loop.
            $gravadas = PoloDecolaStatus::where('verificado_em', '>=', $inicio)->count();
            $viraram  = $promovidas
                ? MlbImplementacao::whereIn('empresa_id', array_keys($promovidas))->where('decola', 'Sim')->count()
                : 0;
            $this->line("  Leituras gravadas: {$gravadas}. Fichas que viraram Sim: {$viraram}"
                . ($promovidas ? ' (' . implode(', ', $promovidas) . ')' : '') . '.');
        }
        if ($semResposta) {
            $this->line('  Sem resposta do ML (seguem como estavam): ' . implode(', ', $semResposta));
        }
        if ($contaDiferente) {
            $this->line('  Puladas, a conta conectada não é a do Cust ID cadastrado: ' . implode(', ', $contaDiferente));
        }

        return self::SUCCESS;
    }
}
