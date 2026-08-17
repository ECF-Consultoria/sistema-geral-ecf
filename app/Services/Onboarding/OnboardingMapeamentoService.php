<?php

namespace App\Services\Onboarding;

use App\Jobs\ResolveOnboardingPassoJob;
use App\Models\Onboarding;
use App\Models\OnboardingMapeamento;
use App\Models\OnboardingPasso;
use App\Models\User;

/**
 * OnboardingMapeamentoService — o "Sincronizar" do Mapeamento Inicial e a
 * conferência do que foi apurado.
 *
 * O apurado tem UMA fonte: `onboarding_passos.valor` dos passos automáticos.
 * Este service LÊ dali e monta a visão; nunca copia para outra tabela.
 *
 * ─── Por que sincronizar não devolve o dado na hora ─────────────────────────
 * Os resolvers de rede (`metricas_conta`, `acervo_coletado`) rodam em fila por
 * obrigação: `fetchUserInfo()`/`fetchPerformance()` têm timeout de 120s e a
 * coleta de acervo leva até 30 minutos. Chamar isso dentro da request derruba
 * a página com 504 (Pitfall 2 da fase, e regra do CLAUDE.md). Então
 * "Sincronizar" DESPACHA e a tela passa a mostrar "buscando" — nunca um
 * spinner que trava esperando resposta.
 */
class OnboardingMapeamentoService
{
    /** Passos que compõem o Mapeamento Inicial e se resolvem sozinhos. */
    public const CHAVES_APURADAS = ['metricas_da_conta', 'anuncios_ativos_inativos'];

    /**
     * Janela mínima entre duas sincronizações do mesmo passo.
     *
     * O portal é público e sem login: sem isto, um cliente clicando repetidas
     * vezes empilharia sondas contra a Adman, que tem `ADMAN_RATE_LIMIT_RPM =
     * 10`. O `ShouldBeUnique` do Job já impede duas do MESMO passo em voo, mas
     * não impede uma nova a cada segundo assim que a anterior termina.
     */
    public const COOLDOWN_MINUTOS = 5;

    public function paraOnboarding(Onboarding $onboarding): OnboardingMapeamento
    {
        return OnboardingMapeamento::firstOrCreate(['onboarding_id' => $onboarding->id]);
    }

    /**
     * Dispara a reapuração dos passos do mapeamento que ainda não concluíram.
     *
     * Passo já `concluido` não é redisparado: o dado está lá, e refazer a
     * chamada gastaria quota da Adman para reescrever o mesmo valor.
     *
     * @return int Quantos passos foram efetivamente despachados.
     */
    public function sincronizar(Onboarding $onboarding): int
    {
        $passos = $onboarding->passos()
            ->whereIn('chave', self::CHAVES_APURADAS)
            ->whereNotNull('auto_fonte')
            ->whereNotIn('status', [
                OnboardingPasso::STATUS_CONCLUIDO,
                OnboardingPasso::STATUS_NAO_APLICAVEL,
                OnboardingPasso::STATUS_BLOQUEADO,
            ])
            ->get();

        $despachados = 0;

        foreach ($passos as $passo) {
            if ($this->emCooldown($passo)) {
                continue;
            }

            ResolveOnboardingPassoJob::dispatch($passo);
            $despachados++;
        }

        return $despachados;
    }

    /**
     * `true` quando o passo foi tocado há menos de {@see self::COOLDOWN_MINUTOS}
     * — protege a quota da Adman de cliques repetidos no portal público.
     */
    private function emCooldown(OnboardingPasso $passo): bool
    {
        $referencia = $passo->coleta_iniciada_em ?? $passo->updated_at;

        return $referencia !== null
            && $referencia->diffInMinutes(now()) < self::COOLDOWN_MINUTOS;
    }

    /**
     * A visão do mapeamento montada a partir da ÚNICA fonte do apurado.
     *
     * `estado` responde a pergunta que a tela precisa fazer, e distingue os
     * três casos que D-11 existe para separar:
     *  - `bloqueado`   — o grant ainda não saiu; não há o que buscar
     *  - `buscando`    — coleta em voo, a tela mostra "buscando seus dados"
     *  - `indisponivel`— sonda indeterminada (429/timeout); NÃO é "zero"
     *  - `pronto`      — apurado disponível
     *
     * @return array<string, mixed>
     */
    public function visao(Onboarding $onboarding): array
    {
        $passos = $onboarding->passos()
            ->whereIn('chave', self::CHAVES_APURADAS)
            ->get()
            ->keyBy('chave');

        $metricas = $passos->get('metricas_da_conta');
        $acervo   = $passos->get('anuncios_ativos_inativos');
        $mapa     = OnboardingMapeamento::where('onboarding_id', $onboarding->id)->first();

        $valorMetricas = $metricas?->status === OnboardingPasso::STATUS_CONCLUIDO ? ($metricas->valor ?? []) : [];
        $valorAcervo   = $acervo?->status === OnboardingPasso::STATUS_CONCLUIDO ? ($acervo->valor ?? []) : [];

        return [
            'estado' => $this->estado($metricas, $acervo),

            'conta' => [
                'nickname'            => $valorMetricas['nickname'] ?? null,
                'marketplace'         => $onboarding->company?->marketplace,
                'faturamento_3_meses' => $valorMetricas['faturamento_3_meses'] ?? null,
                'full_ativo'          => $valorMetricas['full'] ?? null,
                'reputacao_level'     => $valorMetricas['reputacao']['level_id'] ?? null,
                // As duas medalhas, com nome próprio cada uma.
                'medalha_conta'       => $valorMetricas['medalha_conta'] ?? null,
                'medalha_parceiro'    => $valorMetricas['medalha_parceiro'] ?? null,
                'nao_obtidos'         => $valorMetricas['nao_obtidos'] ?? [],
            ],

            'anuncios' => [
                'ativos'   => $valorAcervo['ativos'] ?? null,
                'inativos' => $valorAcervo['inativos'] ?? null,
            ],

            // O único campo digitado — sem API que o entregue.
            'full_pontuacao' => $mapa?->full_pontuacao,

            'confirmacao' => [
                'confirmado'  => (bool) $mapa?->confirmado(),
                'em'          => $mapa?->confirmado_em?->toISOString(),
                'canal'       => $mapa?->confirmado_canal,
                'canal_label' => $mapa?->confirmado_canal
                    ? (OnboardingMapeamento::CANAL_LABELS[$mapa->confirmado_canal] ?? null)
                    : null,
            ],

            'observacoes' => $mapa?->observacoes,
        ];
    }

    private function estado(?OnboardingPasso $metricas, ?OnboardingPasso $acervo): string
    {
        $statuses = collect([$metricas, $acervo])->filter()->pluck('status');

        if ($statuses->isEmpty()) {
            return 'bloqueado';
        }

        if ($statuses->contains(OnboardingPasso::STATUS_AGUARDANDO_COLETA)) {
            return 'buscando';
        }

        if ($statuses->contains(OnboardingPasso::STATUS_INDETERMINADO)) {
            return 'indisponivel';
        }

        if ($statuses->every(fn (string $s) => $s === OnboardingPasso::STATUS_BLOQUEADO)) {
            return 'bloqueado';
        }

        if ($statuses->contains(OnboardingPasso::STATUS_CONCLUIDO)) {
            return 'pronto';
        }

        return 'buscando';
    }

    /**
     * Registra que o apurado foi conferido, e por qual canal.
     *
     * `$por` é `null` quando quem confirmou foi o próprio cliente pelo portal
     * — não existe usuário autenticado ali, e inventar um mentiria sobre a
     * origem do dado.
     */
    public function confirmar(
        Onboarding $onboarding,
        string $canal,
        ?User $por = null,
        ?int $fullPontuacao = null,
        ?string $observacoes = null,
    ): OnboardingMapeamento {
        if (! in_array($canal, OnboardingMapeamento::CANAIS, true)) {
            throw new \DomainException("Canal de confirmação desconhecido: {$canal}");
        }

        $mapa = $this->paraOnboarding($onboarding);

        $mapa->confirmado_em = now();
        $mapa->confirmado_canal = $canal;
        $mapa->confirmado_por = $por?->id;

        // Só sobrescreve o que veio preenchido — confirmar de novo sem digitar
        // a pontuação não pode apagar a que já estava lá.
        if ($fullPontuacao !== null) {
            $mapa->full_pontuacao = $fullPontuacao;
        }
        if ($observacoes !== null) {
            $mapa->observacoes = $observacoes;
        }

        $mapa->save();

        activity('onboarding')
            ->performedOn($onboarding)
            ->withProperties([
                'canal'          => $canal,
                'full_pontuacao' => $mapa->full_pontuacao,
                'por'            => $por?->id,
            ])
            ->log('Mapeamento inicial conferido — '.(OnboardingMapeamento::CANAL_LABELS[$canal] ?? $canal));

        return $mapa;
    }
}
