<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Jobs\SyncMlAcervoCompanyJob;
use App\Models\MlAcervoItem;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingResolverResultado;
use Illuminate\Support\Facades\Log;

/**
 * Resolver do passo 8 do template de Gestão — "Acervo de anúncios coletado
 * (Meus Anúncios)".
 *
 * O passo mais protegido desta fase (SC-07). `mlb:sync-acervo` **apenas
 * enfileira** {@see SyncMlAcervoCompanyJob} — o job da camada barata tem
 * `timeout=1800` (30 minutos). Um resolver que despache a coleta e leia
 * `ml_acervo_itens` no mesmo ciclo sempre vai encontrar zero linhas e, se
 * concluísse ali, mentiria dizendo "zero anúncios" para uma empresa que
 * simplesmente ainda não foi coletada — a mesma armadilha já sofrida no
 * Shopee (conta nova fica vazia até o backfill, e "vazio" foi lido como
 * "zero").
 *
 * Por isso `exists()` roda ANTES de `count()`, e nessa ordem exata:
 *  - tabela **vazia** para a empresa (`exists() === false`) → NUNCA conclui.
 *    Dispara (ou constata em curso) a coleta assíncrona e devolve
 *    `nao_coletado` com a chave reservada
 *    {@see OnboardingResolverResultado::CHAVE_COLETA_EM_ANDAMENTO}. É o
 *    único resolver da fase autorizado a setar essa chave — o
 *    `OnboardingEngineService::aplicarResultado()` (Plano 04) é quem, ao
 *    ver a chave, transiciona o passo para `aguardando_coleta`.
 *  - tabela com **pelo menos 1 linha** → só agora "zero ativos" é uma
 *    leitura válida do dado real, nunca ausência de coleta.
 *
 * Redisparo controlado: só dispara `SyncMlAcervoCompanyJob` de novo quando
 * `coleta_iniciada_em` for `null` (nunca disparou) ou mais antigo que 30
 * minutos (o `timeout` do job) — sem isso, a passada periódica do comando
 * `onboarding:reavaliar-passos` (Task 2) empilharia um dispatch a cada 10
 * minutos enquanto a coleta de uma conta grande ainda estivesse em voo. O
 * `ShouldBeUnique`/`uniqueFor(3600)` do job é a segunda rede — esta checagem
 * local evita até o custo de tentar o dispatch.
 *
 * Guard antes de tudo: empresa sem `mlToken` ativo é pendência do CLIENTE,
 * não do sistema — `mlb:sync-acervo` recusaria a empresa mesmo assim
 * (`resolverEmpresas()` exige `mlToken.status=active`), disparar seria só
 * ruído no worker, e marcar coleta em andamento faria o passo sumir do
 * `passo_que_trava` do painel (SC-11) enquanto espera uma ação que só o
 * cliente pode tomar. Por isso este ramo devolve `nao_coletado` **sem** a
 * chave reservada — o passo continua `aberto`, visível como pendência
 * humana (é o par positivo do teste negativo do Plano 06).
 */
class AcervoColetadoResolver implements OnboardingResolver
{
    /** Janela de tolerância antes de redisparar — casada com o timeout do job (30min). */
    private const JANELA_COLETA_MINUTOS = 30;

    public function chave(): string
    {
        return OnboardingPasso::AUTO_FONTE_ACERVO;
    }

    public function label(): string
    {
        return 'Acervo de anúncios coletado (Meus Anúncios)';
    }

    public function ajuda(): string
    {
        return 'Dispara a coleta do acervo ML (assíncrono, roda em fila) e distingue "ainda não coletado" de "coletado com zero ativos" — tabela vazia nunca conclui.';
    }

    public function assincrono(): bool
    {
        return true;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        $company = $onboarding->company;

        if (! $company->mlToken || $company->mlToken->status !== 'active') {
            return OnboardingResolverResultado::naoColetado(
                'Aguardando o cliente autorizar o acesso'
            );
        }

        $existeAlgumaLinha = MlAcervoItem::where('company_id', $company->id)->exists();

        if (! $existeAlgumaLinha) {
            $coletaIniciadaEm = $passo->coleta_iniciada_em;
            $coletaAindaDentroDaJanela = $coletaIniciadaEm !== null
                && $coletaIniciadaEm->gt(now()->subMinutes(self::JANELA_COLETA_MINUTOS));

            if (! $coletaAindaDentroDaJanela) {
                SyncMlAcervoCompanyJob::dispatch($company);

                Log::info("[Onboarding] coleta de acervo disparada para empresa {$company->id}");
            }

            return OnboardingResolverResultado::naoColetado(
                'Coleta do acervo em andamento — aguardando o job assíncrono',
                [OnboardingResolverResultado::CHAVE_COLETA_EM_ANDAMENTO => true]
            );
        }

        $itens = MlAcervoItem::where('company_id', $company->id)->get(['status']);
        $porStatus = $itens->countBy('status')->all();
        $ativos = $porStatus['active'] ?? 0;
        $inativos = $itens->count() - $ativos;

        return OnboardingResolverResultado::concluido([
            'ativos'      => $ativos,
            'inativos'    => $inativos,
            'por_status'  => $porStatus,
            'coletado_em' => now()->toIso8601String(),
        ]);
    }
}
