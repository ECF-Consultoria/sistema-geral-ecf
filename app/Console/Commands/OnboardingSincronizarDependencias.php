<?php

namespace App\Console\Commands;

use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingEngineService;
use App\Support\Onboarding\DefinicaoOnboarding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Alinha o `depende_de` de passos JÁ EXISTENTES com o da régua vigente.
 *
 * Terceira peça do trio, ao lado de {@see OnboardingAplicarPassosNovos}
 * (acrescenta o que entrou) e {@see OnboardingRemoverPassosForaDaRegua}
 * (remove o que saiu). Faltava a que trata o passo que CONTINUA na régua mas
 * mudou de dependência.
 *
 * ### Por que precisa existir
 * `DefinicaoOnboarding` é copiada para `onboarding_passos` no nascimento — é o
 * que garante que o processo não muda debaixo de quem já está rodando. O preço
 * é que soltar uma dependência na definição não solta nada para quem já
 * existe. Foi exatamente o caso da v13: `agendar_reuniao_onboarding` deixou de
 * depender do mapeamento, mas todo onboarding em curso seguia com o passo
 * BLOQUEADO, esperando dois passos que só o cliente destrava — ou seja, a
 * mudança valeria só para empresas futuras, e a primeira etapa da tela nasceria
 * travada justamente para quem ela foi feita.
 *
 * ### Por que é seguro por natureza, e mesmo assim é dry-run
 * A operação típica aqui é AFROUXAR (tirar dependência), o que só pode
 * destravar passo. Mas o comando também sabe APERTAR — se a régua passou a
 * exigir uma dependência nova, um passo hoje aberto pode virar bloqueado, e
 * isso muda o que a tela cobra de uma empresa em andamento. Por isso `--apply`
 * é obrigatório para gravar, igual às outras duas.
 *
 * ### A dependência ausente
 * Mesma armadilha das irmãs: a régua nova pode apontar para uma chave que
 * AQUELE onboarding não tem (nasceu antes dela, ou ela foi removida por lá).
 * Gravar assim deixaria o passo bloqueado para sempre, esperando algo que não
 * existe. O passo é PULADO, com aviso.
 *
 * Nenhuma chamada de rede: `reavaliar()` é banco local do começo ao fim.
 */
class OnboardingSincronizarDependencias extends Command
{
    protected $signature = 'onboarding:sincronizar-dependencias
        {--apply                : Grava de verdade. Sem esta flag o comando só mostra o que faria}
        {--company=             : Restringe a um company_id}
        {--onboarding=          : Restringe a um onboarding_id}
        {--chave=*              : Chaves a sincronizar (repetível). Sem isto, todas as divergentes}
        {--incluir-concluidos   : Também mexe em onboarding já CONCLUÍDO (por padrão não reabre)}';

    protected $description = 'Alinha o depende_de dos passos existentes com a régua vigente (dry-run por padrão)';

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
            ->with(['company:id,name', 'servico:id,nome,setor', 'passos'])
            ->orderBy('id')
            ->get();

        if ($onboardings->isEmpty()) {
            $this->info('[Onboarding] nenhum onboarding no filtro — nada a fazer.');

            return self::SUCCESS;
        }

        $totalAlterados = 0;
        $totalPulados = 0;
        $linhas = [];

        foreach ($onboardings as $onboarding) {
            $definicao = $onboarding->servico
                ? DefinicaoOnboarding::paraServico($onboarding->servico)
                : null;

            if ($definicao === null) {
                continue;
            }

            $porChave = collect($definicao)->keyBy('chave');
            $chavesDoOnboarding = $onboarding->passos->pluck('chave')->all();
            $mexeu = false;

            foreach ($onboarding->passos as $passo) {
                $naRegua = $porChave->get($passo->chave);

                // Passo que não está mais na régua é assunto do comando de
                // remoção, não deste.
                if ($naRegua === null) {
                    continue;
                }

                if ($chavesPedidas !== [] && ! in_array($passo->chave, $chavesPedidas, true)) {
                    continue;
                }

                $atual = array_values((array) ($passo->depende_de ?? []));
                $nova = array_values((array) ($naRegua['depende_de'] ?? []));

                sort($atual);
                sort($nova);

                if ($atual === $nova) {
                    continue;
                }

                $ausentes = array_diff($nova, $chavesDoOnboarding);

                if ($ausentes !== []) {
                    $totalPulados++;
                    $linhas[] = [
                        $onboarding->id,
                        $onboarding->company?->name ?? '—',
                        $passo->chave,
                        'PULADO — depende de '.implode(', ', $ausentes).' (inexistente aqui)',
                    ];

                    continue;
                }

                $linhas[] = [
                    $onboarding->id,
                    $onboarding->company?->name ?? '—',
                    $passo->chave,
                    sprintf(
                        '%s: [%s] → [%s]',
                        $apply ? 'alterado' : 'seria alterado',
                        implode(', ', $atual) ?: '—',
                        implode(', ', $nova) ?: '—',
                    ),
                ];

                $totalAlterados++;

                if (! $apply) {
                    continue;
                }

                DB::transaction(function () use ($passo, $naRegua) {
                    OnboardingPasso::where('id', $passo->id)
                        ->update(['depende_de' => $naRegua['depende_de'] ?? []]);
                });

                $mexeu = true;
            }

            // Reavaliar UMA vez por onboarding, depois de todas as alterações
            // dele. Um passo destravado pode ser dependência de outro, e
            // reavaliar a cada linha faria o motor rodar sobre estado
            // parcialmente atualizado.
            if ($apply && $mexeu) {
                $engine->reavaliar($onboarding->fresh());
            }
        }

        if ($linhas === []) {
            $this->info('[Onboarding] nenhuma divergência de dependência — nada a fazer.');

            return self::SUCCESS;
        }

        $this->table(['Onboarding', 'Empresa', 'Chave', 'Situação'], $linhas);

        $this->info(sprintf(
            '[Onboarding] %s: %d passo(s). Pulados por dependência ausente: %d.',
            $apply ? 'Alterados' : 'Seriam alterados',
            $totalAlterados,
            $totalPulados,
        ));

        if (! $apply) {
            $this->warn('Dry-run — nada foi gravado. Rode de novo com --apply.');
        }

        return self::SUCCESS;
    }
}
