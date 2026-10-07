<?php

namespace App\Services\Publicador\Criativos;

use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubRascunho;
use App\Services\Creative\CreativeSlotCatalog;
use Illuminate\Support\Collection;

/**
 * Fase 165 — o contrato JSON do kit de criativos para a tela do Publicador
 * (`MlbPublicadorCriativoController`).
 *
 * É o MOLDE do `criativoKitStatus()` antigo (`MlbAnuncioController`, Fases 161
 * e 162) — MAS endereçado pelo `id` numérico do kit, escopado por
 * `pub_rascunho_id`, nunca pelo token (kit, slot ou portador). Nenhum
 * alfanumérico de 32 caracteres sai por aqui (D-13 do `165-04-PLAN.md`), e
 * `ml_picture_url` nunca aparece: o Publicador nunca envia direto ao Mercado
 * Livre (D-04/D-11) — quem decide isso é a conferência/publicação do
 * Publicador.
 *
 * A Fase 162 (validador Gemini-juiz) já acrescentou ao contrato antigo os
 * campos `validacao_status`/`validacao_mensagem`/`validacao_problemas`/
 * `pode_aprovar`/`exige_confirmacao_risco` — este presenter os reproduz
 * (whitelist fechada, T-162-18: nada do json cru de `validacao` vaza, nem
 * `fidelidade`/`tipo`/`override`/`motivo_curto` por item), porque o gate de
 * validação do controller (165-04 Task 3) depende desses campos para nunca
 * deixar o Publicador aprovar em silêncio uma imagem reprovada pelo juiz.
 *
 * Quick 261007-rmv: `pode_ter_texto`/`faltam` vêm de
 * `$kit->plano` (gravados por `CreativePlanner::planejar()` no momento do
 * planejamento, via `CreativePlan::paraAuditoria()`) — nunca recalculados
 * aqui. É o que sobra do bloco "pontos fortes e medidas" (169-02/03,
 * removido): a tela avisa quando nenhuma imagem do kit pôde ter texto,
 * sempre apontando o cadastro do Mercado Livre como único caminho.
 */
class PublicadorCriativoKitPresenter
{
    public function __construct(
        private CreativeSlotCatalog $catalogo,
        private PublicadorCriativoAprovacaoService $aprovacao,
    ) {}

    /**
     * Status de TELA do kit, calculado pelos SLOTS (decisão 3 do
     * `165-04-PLAN.md`) — NUNCA o `status` gravado puro quando há slots: o
     * `recalcularStatus()` do motor devolve `gerando` com um slot `aprovado`
     * misturado com `pronto` (achado (a) do 165-01), o que deixaria a tela
     * perguntando "gerando?" para um kit que já pode ser revisado.
     *
     * Ordem fechada (nunca reordenar — cada passo pressupõe que os
     * anteriores não bateram):
     * 1. kit `aprovado` → 'aprovado'
     * 2. kit `planejando` → 'planejando'
     * 3. nenhum slot ainda → `status` gravado (ou 'erro' se for o caso)
     * 4. kit `planejado` e todos os slots `pendente` → 'planejado'
     * 5. algum slot `pendente`/`rodando` → 'gerando'
     * 6. todos os slots `erro` → 'erro'
     * 7. algum slot `erro` → 'parcial'
     * 8. senão → 'pronto'
     */
    public function statusEfetivo(MlAnuncioCriativoKit $kit, Collection $slots): string
    {
        if ($kit->status === MlAnuncioCriativoKit::STATUS_APROVADO) {
            return MlAnuncioCriativoKit::STATUS_APROVADO;
        }

        if ($kit->status === MlAnuncioCriativoKit::STATUS_PLANEJANDO) {
            return MlAnuncioCriativoKit::STATUS_PLANEJANDO;
        }

        if ($slots->isEmpty()) {
            return $kit->status === MlAnuncioCriativoKit::STATUS_ERRO
                ? MlAnuncioCriativoKit::STATUS_ERRO
                : $kit->status;
        }

        $statusDosSlots = $slots->pluck('status');
        $total = $statusDosSlots->count();

        if ($kit->status === MlAnuncioCriativoKit::STATUS_PLANEJADO
            && $statusDosSlots->every(fn ($s) => $s === MlAnuncioCriativo::STATUS_PENDENTE)) {
            return MlAnuncioCriativoKit::STATUS_PLANEJADO;
        }

        if ($statusDosSlots->contains(fn ($s) => in_array($s, MlAnuncioCriativo::STATUS_EM_ANDAMENTO, true))) {
            return MlAnuncioCriativoKit::STATUS_GERANDO;
        }

        $erros = $statusDosSlots->filter(fn ($s) => $s === MlAnuncioCriativo::STATUS_ERRO)->count();

        if ($erros === $total) {
            return MlAnuncioCriativoKit::STATUS_ERRO;
        }

        if ($erros > 0) {
            return MlAnuncioCriativoKit::STATUS_PARCIAL;
        }

        return MlAnuncioCriativoKit::STATUS_PRONTO;
    }

    /** `statusEfetivo()` ∈ {planejando, gerando} — é isso que decide se o controller chama `encerrarSeTravado()`. */
    public function emAndamento(MlAnuncioCriativoKit $kit): bool
    {
        $status = $this->statusEfetivo($kit, $kit->slots()->get());

        return in_array($status, [MlAnuncioCriativoKit::STATUS_PLANEJANDO, MlAnuncioCriativoKit::STATUS_GERANDO], true);
    }

    /**
     * O kit inteiro, pronto para a tela — `kit_id` é o ÚNICO id do kit que
     * sai daqui; nenhum `token`/`kit_token` em lugar nenhum da árvore.
     */
    public function paraTela(MlAnuncioCriativoKit $kit, PubRascunho $r): array
    {
        $slots = $kit->slots()->get();
        $naMesa = $this->aprovacao->noAnuncioEmLote($slots, (string) $kit->pub_grupo);
        $status = $this->statusEfetivo($kit, $slots);
        $portador = $kit->criativoReferencia;

        return [
            'kit_id' => $kit->id,
            'grupo' => $kit->pub_grupo,
            'status' => $status,
            'etapa' => $kit->etapa,
            'em_andamento' => in_array($status, [MlAnuncioCriativoKit::STATUS_PLANEJANDO, MlAnuncioCriativoKit::STATUS_GERANDO], true),
            'erro' => $this->semToken($kit->erro_mensagem),
            'estrategia' => $kit->plano['estrategia'] ?? null,
            'pode_ter_texto' => $kit->plano['pode_ter_texto'] ?? null,
            'faltam' => $kit->plano['faltam'] ?? [],
            'minimo_aprovadas' => $kit->minimo_aprovadas,
            'prontas' => $kit->prontas(),
            'aprovadas' => $kit->aprovadas(),
            'prontas_sem_risco' => $kit->prontasSemRisco(),
            'reprovadas' => $kit->reprovadas(),
            'referencias' => $portador === null ? [] : collect($portador->referenciasVivas())
                ->map(fn ($ref) => [
                    'indice' => $ref['indice'],
                    'nome' => $ref['nome'],
                    'url' => route('mlb.anuncios.publicador.criativos.kit.referencia', [
                        'produto' => $r->produto_id,
                        'kit' => $kit->id,
                        'indice' => $ref['indice'],
                    ]),
                ])
                ->values()
                ->all(),
            'slots' => $slots->map(function (MlAnuncioCriativo $slot) use ($kit, $r, $naMesa) {
                $padrao = $this->catalogo->padraoDe((string) $slot->slot) ?? [];

                // Fase 162 (APROV-04/VAL-03) — flags calculadas no SERVIDOR;
                // a tela nunca recalcula a régua de validação, mesma
                // disciplina do gate real em
                // `MlbPublicadorCriativoController::aprovar()` (165-04 Task 3).
                $podeAprovar = $slot->status === MlAnuncioCriativo::STATUS_PRONTO
                    && $slot->validacao_status !== MlAnuncioCriativo::VALIDACAO_PENDENTE
                    && $slot->validacao_status !== MlAnuncioCriativo::VALIDACAO_REPROVADA;

                $exigeConfirmacaoRisco = $slot->status === MlAnuncioCriativo::STATUS_PRONTO
                    && $slot->validacao_status === MlAnuncioCriativo::VALIDACAO_REPROVADA;

                return [
                    'indice' => $slot->slot_indice,
                    'tipo' => $slot->slot,
                    'rotulo' => $padrao['rotulo'] ?? $slot->slot,
                    'objetivo' => $slot->slot_plano['objetivo'] ?? ($padrao['objetivo_padrao'] ?? null),
                    'status' => $slot->status,
                    'etapa' => $slot->etapa,
                    'erro' => $this->semToken($slot->erro_mensagem),
                    'imagem_url' => $slot->imagem_path !== null
                        ? route('mlb.anuncios.publicador.criativos.slot.imagem', [
                            'produto' => $r->produto_id,
                            'kit' => $kit->id,
                            'indice' => $slot->slot_indice,
                        ])
                        : null,
                    'modelo' => $slot->modelo,
                    'latencia_ms' => $slot->latencia_ms,
                    'regeneracoes' => $slot->regeneracoes,
                    'regeneracoes_restantes' => $kit->regeneracoesRestantesAsset($slot),
                    'validacao_status' => $slot->validacao_status,
                    'validacao_mensagem' => $this->semToken($slot->validacaoMensagem()),
                    'validacao_problemas' => collect($slot->validacao['problemas'] ?? [])
                        ->map(fn ($problema) => [
                            'gravidade' => $problema['gravidade'] ?? null,
                            'explicacao' => $problema['explicacao'] ?? null,
                        ])
                        ->values()
                        ->all(),
                    'pode_aprovar' => $podeAprovar,
                    'exige_confirmacao_risco' => $exigeConfirmacaoRisco,
                    'no_anuncio' => $naMesa[$slot->id] ?? false,
                    'imagem_id' => $slot->pub_imagem_id !== null ? (string) $slot->pub_imagem_id : null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Troca qualquer sequência de 32 caracteres alfanuméricos (token de kit,
     * slot ou portador) por "…" — os jobs gravam `$e->getMessage()` em
     * `erro_mensagem`, que pode trazer o caminho `creative-geradas/{token}/…`
     * (D-13). Aplicado também em `validacao_mensagem`, por cautela: o veredito
     * do juiz é texto livre e não há garantia de que nunca cite um caminho.
     */
    private function semToken(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        return preg_replace('/(?<![A-Za-z0-9])[A-Za-z0-9]{32}(?![A-Za-z0-9])/', '…', $texto);
    }
}
