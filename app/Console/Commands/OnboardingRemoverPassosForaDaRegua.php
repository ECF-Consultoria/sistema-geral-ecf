<?php

namespace App\Console\Commands;

use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingEngineService;
use App\Support\Onboarding\DefinicaoOnboarding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Remove dos onboardings JÁ EXISTENTES os passos que saíram da régua.
 *
 * ### Por que este comando precisa existir
 * `DefinicaoOnboarding` é copiada para `onboarding_passos` no NASCIMENTO — é o
 * que garante que o processo não muda debaixo de quem já está rodando. O efeito
 * colateral: tirar um passo da definição não o remove de ninguém que já existe.
 * Sem este comando, os 5 passos removidos na v10 continuariam aparecendo no
 * painel de toda empresa que entrou antes do deploy, para sempre.
 *
 * ### Dry-run por padrão
 * Apagar linha de passo é DESTRUTIVO e não tem volta: leva com ela `feito_por`,
 * `feito_em`, `auto_em` e o histórico de quem fez o quê.
 * Sem `--apply` o comando só relata. Com `--apply`, apaga dentro de transação.
 *
 * ### A cascata que este comando tem de resolver
 * Um passo removido pode ser DEPENDÊNCIA de um que fica. Foi o caso na v10:
 * `reuniao_realizada` dependia de `confirmacao_pagamento` e `relatorio_inicial`.
 * Apagar as linhas sem limpar o `depende_de` dos que ficam deixaria a reunião
 * BLOQUEADA para sempre, esperando um passo que não existe mais no banco —
 * exatamente o bug que a definição evitou em código. Por isso a limpeza do
 * `depende_de` roda junto, na mesma transação, e o engine reavalia depois.
 *
 * Nenhuma chamada de rede: `reavaliar()` é banco local do começo ao fim.
 */
class OnboardingRemoverPassosForaDaRegua extends Command
{
    protected $signature = 'onboarding:remover-passos-fora-da-regua
        {--apply         : Apaga de verdade. Sem esta flag o comando só mostra o que faria}
        {--company=      : Restringe a um company_id}
        {--chave=*       : Restringe a chaves específicas (repetível). Sem isto, usa todas as que saíram da régua}
        {--manter-concluidos : Não apaga passo já CONCLUÍDO — preserva o histórico de quem o fechou}';

    protected $description = 'Remove de onboardings existentes os passos que não estão mais na definição (dry-run por padrão)';

    /**
     * Chaves que saíram da régua na v10 e continuam vivas em quem nasceu antes.
     *
     * Lista EXPLÍCITA, nunca derivada de "está no banco e não na definição": um
     * serviço futuro com definição própria traria passos legítimos que a
     * comparação cega classificaria como órfãos e apagaria.
     */
    private const CHAVES_FORA_DA_REGUA = [
        'mensagem_boas_vindas',
        'confirmacao_pagamento',
        'excluir_anuncios_inativos',
        'grant_de_ads',
        'relatorio_inicial',
    ];

    public function handle(OnboardingEngineService $engine): int
    {
        $apply = (bool) $this->option('apply');
        $chaves = $this->option('chave') ?: self::CHAVES_FORA_DA_REGUA;
        $manterConcluidos = (bool) $this->option('manter-concluidos');

        $desconhecidas = array_diff($chaves, self::CHAVES_FORA_DA_REGUA);
        if ($desconhecidas) {
            $this->error('[Onboarding] chave(s) não reconhecida(s): ' . implode(', ', $desconhecidas));
            $this->line('Chaves aceitas: ' . implode(', ', self::CHAVES_FORA_DA_REGUA));

            return self::FAILURE;
        }

        // Guarda dupla: nunca apagar uma chave que a definição VIGENTE ainda
        // monta. Se alguém reintroduzir um destes passos na régua e esquecer de
        // atualizar a lista acima, o comando para em vez de apagar o que acabou
        // de nascer.
        $naReguaVigente = $this->chavesDaReguaVigente();
        $conflito = array_intersect($chaves, $naReguaVigente);
        if ($conflito) {
            $this->error(
                '[Onboarding] estas chaves VOLTARAM à definição vigente e não podem ser apagadas: '
                . implode(', ', $conflito)
            );

            return self::FAILURE;
        }

        $passos = OnboardingPasso::query()
            ->whereIn('chave', $chaves)
            ->when(
                $this->option('company'),
                fn ($q, $id) => $q->whereHas('onboarding', fn ($o) => $o->where('company_id', $id))
            )
            ->when($manterConcluidos, fn ($q) => $q->where('status', '!=', OnboardingPasso::STATUS_CONCLUIDO))
            ->with('onboarding.company:id,name')
            ->orderBy('onboarding_id')
            ->get();

        if ($passos->isEmpty()) {
            $this->info('[Onboarding] nenhum passo fora da régua encontrado — nada a fazer.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '[Onboarding] %d passo(s) fora da régua em %d onboarding(s).%s',
            $passos->count(),
            $passos->pluck('onboarding_id')->unique()->count(),
            $apply ? '' : ' MODO DRY-RUN — nada será apagado (use --apply).'
        ));

        $this->newLine();
        $this->table(
            ['chave', 'qtd', 'concluídos'],
            collect($chaves)->map(function (string $chave) use ($passos) {
                $doGrupo = $passos->where('chave', $chave);

                return [
                    $chave,
                    $doGrupo->count(),
                    $doGrupo->where('status', OnboardingPasso::STATUS_CONCLUIDO)->count(),
                ];
            })->all(),
        );

        $concluidos = $passos->where('status', OnboardingPasso::STATUS_CONCLUIDO)->count();
        if ($concluidos > 0 && ! $manterConcluidos) {
            $this->warn(sprintf(
                '%d passo(s) já CONCLUÍDO(S) serão apagados junto — quem os fechou e quando se perde. '
                . 'Use --manter-concluidos para preservá-los.',
                $concluidos
            ));
        }

        // Onboardings cujo `depende_de` aponta para uma chave que vai sair.
        $dependentes = $this->passosComDependenciaOrfa($passos->pluck('onboarding_id')->unique()->all(), $chaves);

        if ($dependentes->isNotEmpty()) {
            $this->newLine();
            $this->warn(sprintf(
                '%d passo(s) DEPENDEM de uma chave que vai sair e teriam a dependência limpa '
                . '(sem isso ficariam bloqueados para sempre):',
                $dependentes->count()
            ));
            foreach ($dependentes->take(10) as $passo) {
                $this->line(sprintf('  · %s (onboarding %d) depende de: %s',
                    $passo->chave, $passo->onboarding_id, implode(', ', $passo->depende_de ?? [])));
            }
            if ($dependentes->count() > 10) {
                $this->line(sprintf('  … e outros %d.', $dependentes->count() - 10));
            }
        }

        if (! $apply) {
            $this->newLine();
            $this->info('Dry-run concluído. Rode com --apply para efetivar.');

            return self::SUCCESS;
        }

        $onboardingIds = $passos->pluck('onboarding_id')->unique()->all();
        $passoIds = $passos->pluck('id')->all();

        DB::transaction(function () use ($passoIds, $dependentes, $chaves) {
            // 1) Limpa a dependência órfã ANTES de apagar — se a transação
            //    falhar no meio, ninguém fica com dependência apontando para o
            //    vazio.
            foreach ($dependentes as $passo) {
                $restante = array_values(array_diff($passo->depende_de ?? [], $chaves));
                $passo->depende_de = $restante ?: null;
                $passo->save();
            }

            // 2) Apaga as linhas dos passos que saíram.
            OnboardingPasso::whereIn('id', $passoIds)->delete();
        });

        Log::info(sprintf(
            '[Onboarding] %d passo(s) fora da régua apagados em %d onboarding(s); %d dependência(s) limpa(s). Chaves: %s',
            count($passoIds), count($onboardingIds), $dependentes->count(), implode(', ', $chaves)
        ));

        $this->newLine();
        $this->info(sprintf('%d passo(s) apagados. Reavaliando os onboardings afetados…', count($passoIds)));

        // 3) Reavalia: passo que estava travado por uma dependência agora
        //    removida precisa destravar na mesma passada, senão o painel segue
        //    mostrando "bloqueado" até a próxima rodada do scheduler.
        $reavaliados = 0;
        foreach (Onboarding::whereIn('id', $onboardingIds)->get() as $onboarding) {
            $engine = app(OnboardingEngineService::class);
            $engine->reavaliar($onboarding);
            $reavaliados++;
        }

        $this->info(sprintf('%d onboarding(s) reavaliado(s). Pronto.', $reavaliados));

        return self::SUCCESS;
    }

    /**
     * Chaves que a definição VIGENTE monta hoje, em todos os serviços com
     * onboarding definido.
     *
     * @return array<int, string>
     */
    private function chavesDaReguaVigente(): array
    {
        return \App\Models\Servico::query()
            ->where('ativo', true)
            ->get()
            ->flatMap(fn ($servico) => collect(DefinicaoOnboarding::paraServico($servico) ?? [])->pluck('chave'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Passos que FICAM mas cujo `depende_de` cita uma chave que vai sair.
     *
     * @param  array<int, int>  $onboardingIds
     * @param  array<int, string>  $chavesQueSaem
     * @return \Illuminate\Support\Collection<int, OnboardingPasso>
     */
    private function passosComDependenciaOrfa(array $onboardingIds, array $chavesQueSaem): \Illuminate\Support\Collection
    {
        return OnboardingPasso::query()
            ->whereIn('onboarding_id', $onboardingIds)
            ->whereNotIn('chave', $chavesQueSaem)
            ->whereNotNull('depende_de')
            ->get()
            // O filtro fica em PHP de propósito: `depende_de` é JSON e a busca
            // por elemento dentro dele divergiria entre MariaDB (produção) e
            // SQLite (testes) — o tipo de armadilha que o learnings §6 já
            // documenta nesta casa.
            ->filter(fn (OnboardingPasso $p) => array_intersect($p->depende_de ?? [], $chavesQueSaem) !== [])
            ->values();
    }
}
