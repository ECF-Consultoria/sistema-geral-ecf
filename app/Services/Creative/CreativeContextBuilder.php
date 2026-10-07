<?php

namespace App\Services\Creative;

use App\Models\MlAnuncioCriativo;
use App\Models\PubRascunho;
use App\Services\Creative\Dto\CreativeContext;
use App\Services\Publicador\Criativos\ContextoCriativoDoPublicador;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;

/**
 * ÚNICA fronteira entre o publicador (`ml_anuncio_rascunhos.payload`) e o
 * Creative Engine (CTX-02). Mudança de nome de campo ou relação no
 * publicador deve exigir ajuste SÓ AQUI — nenhum outro arquivo do Creative
 * Engine (`ProductTruthBuilder`, `CreativePromptBuilder`, `GerarCriativoIaJob`)
 * pode ler `ml_anuncio_rascunhos.payload` direto. Eles só recebem o
 * `CreativeContext` já montado.
 *
 * Nada de formulário novo: tudo vem do que já está cadastrado no rascunho
 * (CTX-01) — título, categoria, descrição, atributos, variações — mais a
 * loja (conta ML da empresa) e as fotos de referência já enviadas (160-01).
 *
 * Fase 165 (Creative Engine no Publicador novo, D-02): duas fontes, nunca
 * ambas no mesmo criativo. `pubRascunhoIdEfetivo()` escolhe: não nulo vai
 * para `paraPublicador()`, que lê o rascunho do Publicador (`pub_rascunhos`)
 * pelo adaptador `ContextoCriativoDoPublicador` — a MESMA fronteira única
 * (CTX-02), só que do outro lado. O caminho do `payload` antigo (abaixo)
 * fica intocado.
 */
class CreativeContextBuilder
{
    public function __construct(private ReferenciaEfemeraService $referenciaEfemera) {}

    /**
     * Monta o contexto a partir do rascunho ligado ao criativo.
     *
     * As fotos de referência são lidas do PORTADOR
     * (`$criativo->portadorDeReferencia()`, Fase 161) — no fluxo sem kit
     * (Fase 160) o portador é o próprio criativo; nos 7 slots de um kit, as
     * fotos vivem só no criativo que recebeu o upload, nunca em cada slot.
     *
     * @throws \RuntimeException em pt-BR quando falta rascunho ou referência
     *                            viva — não dá para gerar nada sem os dois.
     */
    public function paraCriativo(MlAnuncioCriativo $criativo): CreativeContext
    {
        $portador = $criativo->portadorDeReferencia();

        if ($portador->referenciasVivas() === []) {
            throw new \RuntimeException(
                'Este criativo não tem foto de referência viva — suba uma foto do produto antes de gerar.'
            );
        }

        // Fase 165 (D-02): o Publicador é identificado por `pub_rascunho_id`
        // — no próprio criativo, no kit, ou no portador (resolvido por
        // `pubRascunhoIdEfetivo()`, Fase 165-01). Quando presente, o
        // contexto vem do rascunho do Publicador, nunca do `payload`.
        $pubRascunhoId = $criativo->pubRascunhoIdEfetivo();
        if ($pubRascunhoId !== null) {
            return $this->paraPublicador($criativo, $portador, $pubRascunhoId);
        }

        $rascunho = $criativo->rascunho;

        if ($rascunho === null) {
            throw new \RuntimeException('O rascunho deste criativo não existe mais — não há contexto para gerar a partir dele.');
        }

        $payload = (array) ($rascunho->payload ?? []);

        // TRUTH-01/CTX-02: só `attributes` com `value_name` não vazio entra no
        // mapa id→valor. `value_id` sem `value_name` (seleção por lista do ML
        // sem rótulo resolvido) não vira fato verificado — não há texto para
        // descrever ao modelo de imagem.
        $atributos = [];
        foreach ((array) ($payload['attributes'] ?? []) as $attr) {
            $id    = $attr['id'] ?? null;
            $valor = $attr['value_name'] ?? null;

            if ($id !== null && $valor !== null && trim((string) $valor) !== '') {
                $atributos[(string) $id] = trim((string) $valor);
            }
        }

        $variacoesPayload = (array) ($payload['variations'] ?? []);
        $variacoes = [
            'quantidade'  => count($variacoesPayload),
            'combinacoes' => collect($variacoesPayload)
                ->map(fn ($v) => collect((array) ($v['attribute_combinations'] ?? []))
                    ->map(fn ($c) => $c['value_name'] ?? null)
                    ->filter()
                    ->values()
                    ->all())
                ->values()
                ->all(),
        ];

        return new CreativeContext(
            rascunhoId: $rascunho->id,
            produto: (string) ($payload['title'] ?? ''),
            marca: $atributos['BRAND'] ?? null,
            modelo: $atributos['MODEL'] ?? null,
            categoriaId: $payload['category_id'] ?? null,
            descricao: isset($payload['description']) ? (string) $payload['description'] : null,
            atributos: $atributos,
            variacoes: $variacoes,
            loja: $criativo->company?->nomeContaMl(),
            imagensReferencia: $this->referenciaEfemera->bytesDe($portador),
            referenciasMeta: collect($portador->referenciasVivas())
                ->map(fn ($ref) => [
                    'indice' => $ref['indice'] ?? null,
                    'mime'   => $ref['mime'] ?? null,
                    'bytes'  => $ref['bytes'] ?? null,
                    'nome'   => $ref['nome'] ?? null,
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * Fase 165 (D-02/D-14): contexto do Publicador, a partir do rascunho
     * `pub_rascunhos` identificado por `$pubRascunhoId` — nunca do `payload`
     * do rascunho antigo. Lido pelo adaptador `ContextoCriativoDoPublicador`,
     * que é quem conhece `RascunhoRepository`/`DadosEfetivosService`; este
     * builder continua sendo a ÚNICA porta do DTO `CreativeContext` (CTX-02).
     */
    private function paraPublicador(MlAnuncioCriativo $criativo, MlAnuncioCriativo $portador, int $pubRascunhoId): CreativeContext
    {
        $rascunho = PubRascunho::with('produto')->find($pubRascunhoId);

        if ($rascunho === null) {
            // Só acontece na corrida (o id ainda aponta para um rascunho que
            // sumiu entre a leitura do criativo e a montagem do contexto):
            // apagado de verdade, o `nullOnDelete` já teria zerado a coluna
            // e o criativo cairia no ramo antigo, com a mensagem de sempre.
            throw new \RuntimeException(
                'O rascunho do Publicador deste criativo não existe mais — não há contexto para gerar a partir dele.'
            );
        }

        $grupo = $criativo->pubGrupoEfetivo() ?? ResolvedorGruposImagem::GERAL;
        $dados = app(ContextoCriativoDoPublicador::class)->montar($rascunho, $grupo);

        return new CreativeContext(
            rascunhoId: 0,
            produto: $dados['produto'],
            marca: $dados['atributos']['BRAND'] ?? null,
            modelo: $dados['atributos']['MODEL'] ?? null,
            categoriaId: $dados['categoria_id'],
            descricao: $dados['descricao'],
            atributos: $dados['atributos'],
            variacoes: $dados['variacoes'],
            loja: $dados['loja'],
            imagensReferencia: $this->referenciaEfemera->bytesDe($portador),
            referenciasMeta: collect($portador->referenciasVivas())
                ->map(fn ($ref) => [
                    'indice' => $ref['indice'] ?? null,
                    'mime'   => $ref['mime'] ?? null,
                    'bytes'  => $ref['bytes'] ?? null,
                    'nome'   => $ref['nome'] ?? null,
                ])
                ->values()
                ->all(),
            pubRascunhoId: $rascunho->id,
        );
    }
}
