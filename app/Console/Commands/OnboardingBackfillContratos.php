<?php

namespace App\Console\Commands;

use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * onboarding:backfill-contratos — cria o onboarding que falta para contratos
 * de serviço ATIVOS que já existiam antes do motor.
 *
 * Por que precisa existir: o {@see \App\Observers\ContratoServicoObserver}
 * dispara em `created`, e só em `created`. Quem já tinha contrato quando a
 * Fase 135 entrou nunca ganhou onboarding — no dia do deploy em produção isso
 * vale para a base INTEIRA, não para um caso de borda.
 *
 * Seguro por construção, em três camadas:
 *  - **Dry-run é o padrão.** Sem `--apply` o comando só lista o que faria.
 *  - **Nasce em rascunho**, como qualquer onboarding criado pelo Observer.
 *    Rascunho não corre SLA e não expõe passo nenhum no portal do cliente
 *    (D-05/SC-04) — backfillar mil empresas não dispara nada para ninguém
 *    até alguém confirmar o responsável, uma a uma.
 *  - **`criarParaContrato()` é idempotente** (guard por `contrato_servico_id`
 *    e pelo par empresa × serviço não concluído): rodar duas vezes não
 *    duplica.
 *
 * Nenhuma chamada de rede: `criarParaContrato()` é banco local do começo ao
 * fim. Quem resolve dado de fora é `onboarding:reavaliar-passos`, e só depois
 * que o onboarding sai de rascunho.
 */
class OnboardingBackfillContratos extends Command
{
    protected $signature = 'onboarding:backfill-contratos
        {--apply       : Grava de verdade. Sem esta flag o comando só mostra o que faria}
        {--servico=    : Restringe a um servico_id}
        {--company=    : Restringe a um company_id}
        {--limite=500  : Máximo de contratos processados nesta passada}';

    protected $description = 'Cria onboarding em rascunho para contratos de serviço ativos que ficaram sem (dry-run por padrão)';

    public function handle(OnboardingEngineService $engine): int
    {
        $apply = (bool) $this->option('apply');
        $limite = max(1, (int) $this->option('limite'));

        $comOnboarding = Onboarding::query()->pluck('contrato_servico_id')->filter()->all();

        $contratos = ContratoServico::query()
            ->where('ativo', true)
            ->when($this->option('servico'), fn ($q, $id) => $q->where('servico_id', $id))
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', $id))
            ->when($comOnboarding, fn ($q) => $q->whereNotIn('id', $comOnboarding))
            // `setor` é obrigatório na projeção: DefinicaoOnboarding::eGestao()
            // decide por `setor` + nome. Sem ele todo serviço chega com
            // `setor = null` e o comando classificaria a base inteira como
            // "serviço sem definição" — silenciosamente, sem erro nenhum.
            ->with(['company:id,name', 'servico:id,nome,setor'])
            ->orderBy('id')
            ->limit($limite)
            ->get();

        if ($contratos->isEmpty()) {
            $this->info('[Onboarding] nenhum contrato ativo sem onboarding — nada a fazer.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '[Onboarding] %d contrato(s) ativo(s) sem onboarding.%s',
            $contratos->count(),
            $apply ? '' : ' MODO DRY-RUN — nada será gravado (use --apply).'
        ));

        $criados = 0;
        $semDefinicao = 0;
        $jaExistia = 0;
        $falhas = 0;
        $linhas = [];

        foreach ($contratos as $contrato) {
            $empresa = $contrato->company?->name ?? "company #{$contrato->company_id}";
            $servico = $contrato->servico?->nome ?? "servico #{$contrato->servico_id}";

            if (! $apply) {
                // No dry-run não se chama o engine: perguntar "o serviço tem
                // definição?" é a única coisa que precisa ser sabida, e ela é
                // respondida sem escrever nada.
                $temDefinicao = $contrato->servico
                    && \App\Support\Onboarding\DefinicaoOnboarding::paraServico($contrato->servico) !== null;

                $temDefinicao ? $criados++ : $semDefinicao++;
                $linhas[] = [$contrato->id, $empresa, $servico, $temDefinicao ? 'criaria' : 'serviço sem definição'];

                continue;
            }

            try {
                $onboarding = $engine->criarParaContrato($contrato);

                if ($onboarding === null) {
                    $semDefinicao++;
                    $linhas[] = [$contrato->id, $empresa, $servico, 'serviço sem definição'];

                    continue;
                }

                if (! $onboarding->wasRecentlyCreated) {
                    // O guard do par empresa × serviço pegou: já havia um
                    // onboarding não concluído para essa combinação, vindo de
                    // outro contrato.
                    $jaExistia++;
                    $linhas[] = [$contrato->id, $empresa, $servico, "já existia (onboarding #{$onboarding->id})"];

                    continue;
                }

                $criados++;
                $linhas[] = [$contrato->id, $empresa, $servico, "criado #{$onboarding->id} (rascunho)"];

                activity('onboarding')
                    ->performedOn($onboarding)
                    ->withProperties(['contrato_servico_id' => $contrato->id, 'origem' => 'backfill'])
                    ->log("Onboarding criado em rascunho por backfill do contrato #{$contrato->id}");
            } catch (\Throwable $e) {
                $falhas++;
                $linhas[] = [$contrato->id, $empresa, $servico, 'FALHA: '.$e->getMessage()];

                Log::error(
                    "[Onboarding] falha no backfill do contrato {$contrato->id} "
                    . "(empresa {$contrato->company_id}, serviço {$contrato->servico_id}): {$e->getMessage()}"
                );
            }
        }

        $this->table(['Contrato', 'Empresa', 'Serviço', 'Resultado'], $linhas);

        $this->info(sprintf(
            '[Onboarding] backfill %s — %s=%d, serviço sem definição=%d, já existia=%d, falhas=%d.',
            $apply ? 'aplicado' : 'simulado',
            $apply ? 'criados' : 'criaria',
            $criados,
            $semDefinicao,
            $jaExistia,
            $falhas
        ));

        if (! $apply && $criados > 0) {
            $this->warn('Nada foi gravado. Rode de novo com --apply para criar.');
        }

        if ($apply && $criados > 0) {
            $this->line('Os onboardings nascem em RASCUNHO: não correm SLA e não aparecem para nenhum cliente até alguém confirmar o responsável em /onboarding.');
        }

        return self::SUCCESS;
    }
}
