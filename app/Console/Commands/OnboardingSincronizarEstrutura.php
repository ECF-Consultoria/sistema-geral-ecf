<?php

namespace App\Console\Commands;

use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingEngineService;
use App\Support\Onboarding\DefinicaoOnboarding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Alinha `dono`, `natureza` e `ordem` de passos JÁ EXISTENTES com a régua vigente.
 *
 * ### Por que este comando precisa existir
 * A definição é COPIADA para `onboarding_passos` no nascimento — é o que
 * garante que o processo não muda debaixo de quem já está rodando. O efeito
 * colateral aparece quando a mudança é justamente de VISIBILIDADE: na v15 os
 * sete itens de publicidade e ADMAN passaram de `dono=interno` para
 * `dono=cliente`, porque o cliente passou a poder se informar pelo portal e
 * confirmar sozinho.
 *
 * `passosDoCliente()` filtra por `dono=cliente`. Sem este comando, a régua
 * nova valeria só para empresas que entrarem depois do deploy, e todo cliente
 * já em andamento continuaria sem ver as seções — a feature ficaria invisível
 * exatamente para quem já está no processo.
 *
 * ### `ordem` entrou depois, e por um motivo concreto
 * O onboarding 3 tinha `grant_sistema_ecf` congelado em ordem 8 enquanto a
 * régua o define como 3 — resultado: o portal listava "Grant com a Consultoria
 * (Adman)" ANTES do "Grant com o Sistema ECF", que é o oposto da sequência que
 * o negócio combinou. `ordem` é apresentação pura: mudar não desfaz nada nem
 * reabre passo, só corrige a leitura.
 *
 * ### É uma exceção CONSCIENTE ao congelamento, e por isso é estreita
 * Só toca `dono`, `natureza` e `ordem`, e só quando divergem da régua. Não
 * mexe em `status`, `feito_por`, `feito_em` nem `sla_dias`: alinhar estrutura
 * não pode desfazer trabalho feito nem reabrir o que já fechou. Um passo
 * concluído continua concluído.
 *
 * Dry-run por padrão, como os outros comandos da família.
 */
class OnboardingSincronizarEstrutura extends Command
{
    protected $signature = 'onboarding:sincronizar-estrutura
        {--apply     : Grava de verdade. Sem esta flag o comando só mostra o que faria}
        {--company=  : Restringe a um company_id}';

    protected $description = 'Alinha dono, natureza e ordem dos passos existentes com a régua vigente (dry-run por padrão)';

    public function handle(OnboardingEngineService $engine): int
    {
        $apply = (bool) $this->option('apply');
        $companyId = $this->option('company');

        $onboardings = Onboarding::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->with(['passos', 'servico', 'company:id,name'])
            ->get();

        $mudancas = [];

        foreach ($onboardings as $onboarding) {
            $regua = DefinicaoOnboarding::paraServico($onboarding->servico);

            if (! $regua) {
                continue;
            }

            $porChave = collect($regua)->keyBy('chave');

            foreach ($onboarding->passos as $passo) {
                $naRegua = $porChave->get($passo->chave);

                // Passo fora da régua é assunto de
                // `onboarding:remover-passos-fora-da-regua`, não deste.
                if (! $naRegua) {
                    continue;
                }

                $donoNovo = $naRegua['dono'];
                $naturezaNova = $naRegua['natureza'] ?? OnboardingPasso::NATUREZA_ACAO;
                $naturezaAtual = $passo->natureza ?? OnboardingPasso::NATUREZA_ACAO;
                $ordemNova = (int) $naRegua['ordem'];

                if ($passo->dono === $donoNovo
                    && $naturezaAtual === $naturezaNova
                    && (int) $passo->ordem === $ordemNova) {
                    continue;
                }

                $mudancas[] = [
                    'passo'    => $passo,
                    'linha'    => [
                        $onboarding->id,
                        $onboarding->company->name,
                        $passo->chave,
                        $passo->dono === $donoNovo ? '=' : "{$passo->dono} → {$donoNovo}",
                        $naturezaAtual === $naturezaNova ? '=' : "{$naturezaAtual} → {$naturezaNova}",
                        (int) $passo->ordem === $ordemNova ? '=' : "{$passo->ordem} → {$ordemNova}",
                    ],
                    'dono'     => $donoNovo,
                    'natureza' => $naturezaNova,
                    'ordem'    => $ordemNova,
                ];
            }
        }

        if ($mudancas === []) {
            $this->info('[Onboarding] nenhuma divergência de estrutura — nada a fazer.');

            return self::SUCCESS;
        }

        $this->table(
            ['Onboarding', 'Empresa', 'Chave', 'Dono', 'Natureza', 'Ordem'],
            array_column($mudancas, 'linha')
        );

        $this->info('[Onboarding] '.count($mudancas).' passo(s) divergentes da régua.');

        if (! $apply) {
            $this->warn('Dry-run — nada foi gravado. Rode de novo com --apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($mudancas) {
            foreach ($mudancas as $m) {
                OnboardingPasso::whereKey($m['passo']->id)->update([
                    'dono'     => $m['dono'],
                    'natureza' => $m['natureza'],
                    'ordem'    => $m['ordem'],
                ]);
            }
        });

        // Reavaliar depois: nenhum destes campos muda dependência, mas o passo pode ter
        // ficado disponível por outro motivo enquanto isto rodava, e sair daqui
        // com o estado fresco é mais barato que descobrir depois.
        foreach ($onboardings->whereIn('id', array_unique(array_map(
            fn (array $m) => $m['passo']->onboarding_id,
            $mudancas
        ))) as $onboarding) {
            $engine->reavaliar($onboarding->fresh());
        }

        $this->info(count($mudancas).' passo(s) alinhados. Pronto.');

        return self::SUCCESS;
    }
}
