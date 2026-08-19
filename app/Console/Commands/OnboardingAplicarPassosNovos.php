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
 * Acrescenta a onboardings JÁ EXISTENTES os passos que entraram na régua depois
 * de eles nascerem. Imagem espelhada de
 * {@see OnboardingRemoverPassosForaDaRegua}.
 *
 * ### Por que precisa existir
 * `DefinicaoOnboarding` é COPIADA para `onboarding_passos` no nascimento — é o
 * que garante que o processo não muda debaixo de quem já está rodando. O efeito
 * colateral é simétrico ao da remoção: acrescentar um passo à definição não o
 * dá a ninguém que já existe. Sem este comando, uma régua nova valeria só para
 * empresas futuras, e o critério "a conclusão do checklist diz que a empresa
 * está pronta para operação" nasceria falso para quem está em processo hoje.
 *
 * ### Por que NÃO é só rodar `montarPassos()` de novo
 * `montarPassos()` faz um único `OnboardingPasso::insert()` em lote com TODOS
 * os passos da definição. Existe `unique(onboarding_id, chave)` desde a
 * migration `2026_08_12_190000`: na primeira chave repetida o INSERT inteiro
 * lança `QueryException` e **nenhuma linha entra** — inclusive as novas. Além
 * disso ele reescreveria `ordem`/`titulo`/`sla_dias` das linhas existentes,
 * quebrando o congelamento. Este comando insere passo a passo, só o que falta.
 *
 * ### Dry-run por padrão
 * Acrescentar passo MUDA o que a tela cobra de uma empresa em andamento e pode
 * reabrir um onboarding concluído. Sem `--apply` o comando só relata.
 *
 * ### A dependência invertida
 * Um passo novo pode declarar `depende_de` apontando para uma chave que aquele
 * onboarding não tem (porque nasceu antes dela, ou porque ela foi removida por
 * lá). Inserir assim deixaria o passo BLOQUEADO para sempre, esperando algo que
 * não existe — o mesmo bug que o comando de remoção resolve pelo outro lado.
 * Aqui o passo é PULADO, com aviso, em vez de entrar quebrado.
 *
 * Nenhuma chamada de rede: `reavaliar()` é banco local do começo ao fim.
 */
class OnboardingAplicarPassosNovos extends Command
{
    protected $signature = 'onboarding:aplicar-passos-novos
        {--apply                : Grava de verdade. Sem esta flag o comando só mostra o que faria}
        {--company=             : Restringe a um company_id}
        {--onboarding=          : Restringe a um onboarding_id}
        {--chave=*              : Chaves a acrescentar (repetível). Sem isto, todas as da definição que faltarem}
        {--incluir-concluidos   : Também mexe em onboarding já CONCLUÍDO (por padrão não reabre)}
        {--carimbar-versao      : Atualiza definicao_versao para a versão atual (por padrão NÃO mexe)}';

    protected $description = 'Acrescenta a onboardings existentes os passos que entraram na régua depois (dry-run por padrão)';

    public function handle(OnboardingEngineService $engine): int
    {
        $apply = (bool) $this->option('apply');
        $chavesPedidas = array_filter((array) $this->option('chave'));

        $onboardings = Onboarding::query()
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', $id))
            ->when($this->option('onboarding'), fn ($q, $id) => $q->where('id', $id))
            ->when(
                ! $this->option('incluir-concluidos'),
                fn ($q) => $q->where('status', '!=', Onboarding::STATUS_CONCLUIDO)
            )
            ->with(['company:id,name', 'servico:id,nome,setor', 'passos:id,onboarding_id,chave'])
            ->orderBy('id')
            ->get();

        if ($onboardings->isEmpty()) {
            $this->info('[Onboarding] nenhum onboarding no filtro — nada a fazer.');

            return self::SUCCESS;
        }

        $totalInseridos = 0;
        $totalPulados = 0;
        $linhas = [];

        foreach ($onboardings as $onboarding) {
            $definicao = $onboarding->servico
                ? DefinicaoOnboarding::paraServico($onboarding->servico)
                : null;

            if ($definicao === null) {
                continue;
            }

            $jaTem = $onboarding->passos->pluck('chave')->all();

            // Só o que falta. A comparação é por chave, nunca por contagem.
            $faltando = array_filter(
                $definicao,
                fn (array $p) => ! in_array($p['chave'], $jaTem, true)
                    && ($chavesPedidas === [] || in_array($p['chave'], $chavesPedidas, true))
            );

            if ($faltando === []) {
                continue;
            }

            // As chaves que existirão APÓS esta passada — um passo novo pode
            // depender de outro passo novo da mesma leva, e isso é legítimo.
            $chavesFinais = array_merge($jaTem, array_column($faltando, 'chave'));

            foreach ($faltando as $passo) {
                $dependenciasAusentes = array_diff($passo['depende_de'] ?? [], $chavesFinais);

                if ($dependenciasAusentes !== []) {
                    $totalPulados++;
                    $linhas[] = [
                        $onboarding->id,
                        $onboarding->company?->name ?? '—',
                        $passo['chave'],
                        'PULADO — depende de '.implode(', ', $dependenciasAusentes),
                    ];

                    continue;
                }

                $linhas[] = [
                    $onboarding->id,
                    $onboarding->company?->name ?? '—',
                    $passo['chave'],
                    $apply ? 'inserido' : 'seria inserido',
                ];

                if (! $apply) {
                    $totalInseridos++;

                    continue;
                }

                DB::transaction(function () use ($onboarding, $passo, &$totalInseridos) {
                    // `firstOrCreate` e não `insert`: duas execuções do comando
                    // não podem duplicar, e a unique (onboarding_id, chave) não
                    // deve ser o que descobre isso — exceção de banco no meio de
                    // um laço deixa o trabalho pela metade.
                    $criado = OnboardingPasso::firstOrCreate(
                        ['onboarding_id' => $onboarding->id, 'chave' => $passo['chave']],
                        [
                            'ordem'         => $passo['ordem'],
                            'etapa'         => $passo['etapa'] ?? null,
                            'natureza'      => $passo['natureza'] ?? OnboardingPasso::NATUREZA_ACAO,
                            'titulo'        => $passo['titulo'],
                            'dono'          => $passo['dono'],
                            'setor_id'      => $passo['setor_id'] ?? null,
                            'depende_de'    => $passo['depende_de'] ?? [],
                            'sla_dias'      => $passo['sla_dias'] ?? null,
                            'auto_fonte'    => $passo['auto_fonte'] ?? null,
                            'condicao'      => $passo['condicao'] ?? null,
                            // Nasce BLOQUEADO com `disponivel_em` nulo, como
                            // qualquer passo em montarPassos(): quem destrava e
                            // carimba a data é reavaliar(), no fim.
                            'status'        => OnboardingPasso::STATUS_BLOQUEADO,
                            'disponivel_em' => null,
                        ]
                    );

                    if ($criado->wasRecentlyCreated) {
                        $totalInseridos++;
                    }
                });
            }

            if ($apply) {
                // Fora do laço de passos: uma reavaliação por onboarding, não
                // uma por passo.
                $engine->reavaliar($onboarding->fresh());

                if ($this->option('carimbar-versao')) {
                    $onboarding->update(['definicao_versao' => DefinicaoOnboarding::VERSAO]);
                }

                activity('onboarding')
                    ->performedOn($onboarding)
                    ->withProperties(['chaves' => array_column($faltando, 'chave')])
                    ->log('Passos acrescentados por onboarding:aplicar-passos-novos');
            }
        }

        if ($linhas === []) {
            $this->info('[Onboarding] todos os onboardings do filtro já têm os passos da régua.');

            return self::SUCCESS;
        }

        $this->table(['Onboarding', 'Empresa', 'Chave', 'Situação'], $linhas);

        $verbo = $apply ? 'Inseridos' : 'Seriam inseridos';
        $this->info("[Onboarding] {$verbo}: {$totalInseridos} passo(s). Pulados por dependência ausente: {$totalPulados}.");

        if (! $apply) {
            $this->warn('Dry-run — nada foi gravado. Rode de novo com --apply.');

            return self::SUCCESS;
        }

        Log::info(
            "[Onboarding] aplicar-passos-novos: {$totalInseridos} passo(s) inserido(s), "
            . "{$totalPulados} pulado(s) por dependência ausente."
        );

        // `definicao_versao` NÃO sobe por padrão, e isso é deliberado: a coluna
        // registra sob qual receita a empresa ENTROU. Reescrevê-la faria o
        // registro mentir sobre a história do onboarding. O que mudou fica no
        // activity log acima.
        if (! $this->option('carimbar-versao')) {
            $this->line('  definicao_versao preservada (use --carimbar-versao para atualizar).');
        }

        return self::SUCCESS;
    }
}
