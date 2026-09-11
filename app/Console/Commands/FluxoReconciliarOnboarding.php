<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\User;
use App\Services\FluxoEntrada\EtapaTransicaoService;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Destrava empresa que foi distribuída ANTES de a distribuição passar a
 * entregar os responsáveis ao onboarding (D-157-B).
 *
 * Duas contradições, as duas medidas na empresa 428 em produção:
 *
 * 1. Empresa parada em `aguardando_onboarding` com um onboarding correndo. A
 *    transição 6→7 mora na virada rascunho→andamento; num onboarding que já
 *    estava em `andamento` antes da máquina de estados existir, essa virada
 *    nunca mais acontece e a empresa fica presa para sempre.
 * 2. `company_users` (o que o líder escolheu na distribuição) e
 *    `onboardings.responsavel_*` (o que a tela do onboarding mostra) apontando
 *    para pessoas diferentes.
 *
 * Daqui para a frente o próprio `DistribuicaoService` resolve as duas no ato de
 * distribuir; este comando é para o acervo que ficou atrás. É idempotente —
 * rodar duas vezes não faz nada na segunda.
 *
 * Sem `--apply` só relata. `--por` é obrigatório porque
 * `EtapaTransicaoService` exige um ator real: histórico de etapa atribuído a
 * quem não agiu é pior que histórico ausente.
 */
class FluxoReconciliarOnboarding extends Command
{
    protected $signature = 'fluxo:reconciliar-onboarding
                            {--por= : id ou e-mail do usuário responsável pelo ajuste (obrigatório com --apply)}
                            {--company= : limita a uma empresa}
                            {--apply : grava; sem esta flag o comando só relata}';

    protected $description = 'Destrava empresas presas em "Aguardando Onboarding" com onboarding já em andamento';

    public function handle(EtapaTransicaoService $etapas, OnboardingEngineService $engine): int
    {
        $aplicar = (bool) $this->option('apply');
        $por     = $this->resolverAtor();

        if ($aplicar && $por === null) {
            $this->error('--apply exige --por=<id|e-mail> de um usuário existente.');

            return self::FAILURE;
        }

        $companies = Company::query()
            ->where('etapa', Company::ETAPA_AGUARDANDO_ONBOARDING)
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->info('Nenhuma empresa em "Aguardando Onboarding". Nada a reconciliar.');

            return self::SUCCESS;
        }

        $etapasMovidas = 0;
        $donosAjustados = 0;

        foreach ($companies as $company) {
            $onboardings = Onboarding::where('company_id', $company->id)
                ->where('status', Onboarding::STATUS_ANDAMENTO)
                ->get();

            if ($onboardings->isEmpty()) {
                // Etapa 6 sem onboarding correndo é repouso legítimo — a empresa
                // está esperando o onboarding começar. Não é o caso desta
                // reconciliação.
                continue;
            }

            $this->line("empresa {$company->id} — {$company->name}");

            [$analista, $estrategista, $ambiguo] = $this->donosDistribuidos($company);

            foreach ($onboardings as $onboarding) {
                $divergem = $onboarding->responsavel_analista_id !== $analista?->id
                    || $onboarding->responsavel_estrategista_id !== $estrategista?->id;

                if (! $divergem) {
                    continue;
                }

                if ($ambiguo) {
                    $this->warn("  onboarding {$onboarding->id}: responsáveis divergem, mas a distribuição"
                        . ' tem mais de uma pessoa por função — ajuste manual.');

                    continue;
                }

                if ($analista === null && $estrategista === null) {
                    $this->warn("  onboarding {$onboarding->id}: divergem, mas a empresa não tem ninguém"
                        . ' vinculado em company_users — nada de onde copiar.');

                    continue;
                }

                $this->line(sprintf(
                    '  onboarding %d: dono %s → %s',
                    $onboarding->id,
                    $this->par($onboarding->responsavel_estrategista_id, $onboarding->responsavel_analista_id),
                    $this->par($estrategista?->id, $analista?->id)
                ));

                $donosAjustados++;

                if ($aplicar) {
                    try {
                        $engine->definirResponsaveis($onboarding, $estrategista, $analista, $por);
                    } catch (\Throwable $e) {
                        $this->error("  onboarding {$onboarding->id}: {$e->getMessage()}");
                        $donosAjustados--;
                    }
                }
            }

            $this->line('  etapa: aguardando_onboarding → onboarding_andamento');
            $etapasMovidas++;

            if (! $aplicar) {
                continue;
            }

            $company->refresh();

            // O `definirResponsaveis()` acima pode já ter movido a etapa quando o
            // onboarding estava em rascunho; reconsultar evita empurrar de novo.
            if ($company->etapa !== Company::ETAPA_AGUARDANDO_ONBOARDING) {
                continue;
            }

            $r = $etapas->transicionar($company, Company::ETAPA_ONBOARDING_ANDAMENTO, $por);

            if ($r['status'] !== 'transicionado') {
                $this->error("  etapa recusada: {$r['requisito_faltante']}");
                $etapasMovidas--;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s — %d etapa(s) 6→7, %d onboarding(s) com dono ajustado.',
            $aplicar ? 'APLICADO' : 'SIMULAÇÃO (use --apply para gravar)',
            $etapasMovidas,
            $donosAjustados
        ));

        return self::SUCCESS;
    }

    /**
     * Quem a distribuição escolheu, lido de `company_users`. Devolve
     * `[analista, estrategista, ambiguo]` — `ambiguo` marca empresa com mais de
     * uma pessoa distinta na mesma função (um par por serviço, serviços com
     * donos diferentes), caso em que copiar para o onboarding seria um chute.
     *
     * @return array{0: ?User, 1: ?User, 2: bool}
     */
    private function donosDistribuidos(Company $company): array
    {
        $porFuncao = DB::table('company_users')
            ->where('company_id', $company->id)
            ->whereIn('role', ['analista', 'estrategista'])
            ->get(['role', 'user_id'])
            ->groupBy('role')
            ->map(fn ($linhas) => $linhas->pluck('user_id')->unique()->values());

        $analistaIds     = $porFuncao->get('analista', collect());
        $estrategistaIds = $porFuncao->get('estrategista', collect());

        return [
            $analistaIds->count() === 1 ? User::find($analistaIds->first()) : null,
            $estrategistaIds->count() === 1 ? User::find($estrategistaIds->first()) : null,
            $analistaIds->count() > 1 || $estrategistaIds->count() > 1,
        ];
    }

    private function par(?int $estrategistaId, ?int $analistaId): string
    {
        $nome = fn (?int $id) => $id === null ? '—' : (User::find($id)?->name ?? "#{$id}");

        return 'estr. '.$nome($estrategistaId).' / anal. '.$nome($analistaId);
    }

    private function resolverAtor(): ?User
    {
        $chave = $this->option('por');

        if (blank($chave)) {
            return null;
        }

        return is_numeric($chave)
            ? User::find((int) $chave)
            : User::where('email', $chave)->first();
    }
}
