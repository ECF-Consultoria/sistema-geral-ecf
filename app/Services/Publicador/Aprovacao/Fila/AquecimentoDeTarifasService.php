<?php

namespace App\Services\Publicador\Aprovacao\Fila;

use App\Models\PubAprovacaoOferta;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use Illuminate\Support\Facades\Log;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * O PRÉ-AQUECIMENTO DA TARIFA DO MERCADO LIVRE — fora do request, para a fila nunca pagar a
 * consulta com alguém esperando a tela.
 *
 * ⚠️⚠️ **ELE NÃO MONTA CHAVE DE CACHE NENHUMA. ELE CHAMA
 * {@see ComposicaoFinanceiraService::para()}** — o MESMO método que a tela chama. É essa, e só
 * essa, a razão pela qual "a chave é a mesma": não existe segunda construção de chave que possa
 * divergir da primeira.
 *
 * ➡️ E a prova é **ESTRUTURAL**, não por inspeção: o método privado que consulta a tarifa é
 * `private` dentro do serviço da Fase 186 e o **único chamador dele é o próprio `para()`**
 * (medido em 17/10/2026: `grep -n` daquele nome devolve **duas** ocorrências no arquivo — a
 * declaração em `:462` e a chamada em `:238`). **Não há caminho para montar aquela chave de
 * fora.** Divergir exigiria editar um arquivo da Fase 186, que para esta fase é **intocável**.
 *
 * O precedente é literal, e está escrito no projeto: `WarmAdmanDiffCache` chama
 * `$this->diff->compute($c, $periodo)` e explica no próprio docblock — *"compute() faz
 * Cache::remember interno — em cache-miss popula; em cache-hit não custa nada (idempotente)"*.
 * Aqui é a mesma forma: o aquecimento é uma CHAMADA, não uma reimplementação.
 *
 * ═══ ⚠️⚠️ A TELA NUNCA DEGRADA — DECISÃO DO USUÁRIO DE 15/10/2026 ══════════════════════════════
 *
 * *"Limitar N consultas por abertura"* foi **oferecido e RECUSADO**, com a razão escrita:
 * **incompleto bloqueia a aprovação**, então limitar protegeria o servidor **travando o
 * trabalho**. ➡️ Este arquivo muda **QUANDO** a requisição acontece, **NUNCA QUANTAS**:
 *   · **zero cap por request**;
 *   · **zero** "sem tarifa por timeout";
 *   · **zero** "incompleto" inventado aqui.
 * **Cache frio continua produzindo o número CERTO, só mais devagar.** Qualquer outra coisa seria
 * o que o usuário recusou, entrando por outra porta.
 *
 * ═══ ⚠️ UMA OFERTA REPRESENTANTE POR TRIO, E POR QUÊ ═══════════════════════════════════════════
 *
 * O cache do serviço da 186 é por `(categoria, tipo de anúncio, preço)`. Chamar `para()` nas
 * **quatro** ofertas de **dois** trios não faria **nenhuma** requisição extra (a segunda de cada
 * trio é cache-hit), mas pagaria **duas composições inteiras** a mais — custo de CPU e de banco
 * sem nenhum ganho. ➡️ **Uma oferta representante por trio distinto.**
 *
 * ⚠️ A varredura dos trios existe para **CONTAR, ESCOLHER O REPRESENTANTE E RELATAR** — e a
 * string de agrupamento daqui **não é, e não pode virar, chave de cache**: ela nunca sai deste
 * arquivo, nunca chega ao `Cache` e tem outro formato de propósito. As **três fontes** que ela lê
 * são as MESMAS que `para()` lê (`rascunho->categoria_id`, `alvo->listing_type_id` e
 * `precoDoAlvo()`), porque agrupar por outra coisa escolheria o representante errado e deixaria
 * trio sem aquecer **em silêncio**.
 *
 * ═══ ⚠️ FILA VAZIA DEVOLVE "0 TRIOS", E ISSO **NÃO É ERRO** ═════════════════════════════════════
 *
 * Medido em 15/10/2026: **nada** cria `PubAprovacaoOferta` hoje — quem passa a criar é a **Fase
 * 194**. Até lá o relatório honesto é `trios=0`, e tratá-lo como falha faria o comando e o job
 * gritarem todos os dias por um estado correto.
 *
 * ═══ ⚠️ `para()` É LEITURA PURA — SEGURO DE CHAMAR DE JOB E DE COMANDO ══════════════════════════
 *
 * O docblock da Fase 186 é explícito: *"Nada é gravado aqui. Quem grava é `congelar()`"*. Há gate
 * conferindo as contagens de `pub_aprovacao_ofertas`, `pub_aprovacao_composicoes` e
 * `pub_aprovacao_eventos` **antes e depois** do aquecimento.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class AquecimentoDeTarifasService
{
    /** O prefixo de log do módulo — o mesmo nome que o relatório do comando usa. */
    public const TAG = '[AquecimentoTarifas]';

    public function __construct(
        private FilaDeAprovacaoService $fila,
        private ComposicaoFinanceiraService $financeira,
    ) {}

    /**
     * Aquece a tarifa de **uma oferta por trio distinto** da fila desta conta.
     *
     * ⚠️ **Propaga a `RegraViolada`** de `FilaDeAprovacaoService::paraAConta()` com as duas
     * âncoras nulas: não existe "aquecer a fila de todo mundo". Engolir a exceção aqui varreria a
     * fila de todas as empresas e gastaria chamadas ao Mercado Livre por conta própria.
     *
     * @param  bool  $simular  `--dry-run`: conta os trios e **não** chama `para()` — zero
     *                         requisição, provado por `Http::preventStrayRequests()` no teste.
     * @return array{trios: int, aquecidos: int, pulados: int, falhas: int, segundos: float}
     *
     * @throws \App\Support\Publicador\RegraViolada quando nenhuma âncora de conta é informada
     */
    public function paraAConta(?int $companyId = null, ?int $mlbEmpresaId = null, bool $simular = false): array
    {
        $inicio = microtime(true);

        // ⚠️ Eager loading das três relações que a varredura e a composição usam: sem ele uma
        // fila de 30 ofertas faria 90 consultas só para descobrir os trios.
        $ofertas = $this->fila->paraAConta($companyId, $mlbEmpresaId)
            ->with(['rascunho', 'alvo', 'produto'])
            ->get();

        /** @var array<string, PubAprovacaoOferta> $representantes */
        $representantes = [];
        $pulados = 0;

        foreach ($ofertas as $oferta) {
            $agrupador = $this->agrupadorDaOferta($oferta);

            // Sem categoria, sem tipo ou sem preço o serviço da 186 já recusa sem sair para a
            // rede (`:470`). Contar como PULADA é a leitura honesta: não é falha, e também não é
            // aquecimento.
            if ($agrupador === null) {
                $pulados++;

                continue;
            }

            if (! array_key_exists($agrupador, $representantes)) {
                $representantes[$agrupador] = $oferta;
            }
        }

        $aquecidos = 0;
        $falhas = 0;

        foreach ($representantes as $oferta) {
            if ($simular) {
                continue;
            }

            try {
                // ⚠️⚠️ **É AQUI QUE O AQUECIMENTO ACONTECE, E É UMA CHAMADA.** `para()` faz o
                // `Cache::remember` por dentro: em cache-miss popula, em cache-hit não custa nada
                // (idempotente). Montar a chave deste lado criaria duas verdades livres para
                // divergir — e o aquecimento aqueceria o lugar errado, em silêncio.
                $this->financeira->para($oferta);
                $aquecidos++;
            } catch (\Throwable $e) {
                // ⚠️ `Log then continue`, por OFERTA: uma oferta que falha não pode levar as
                // outras. E o log traz id + SKU + nome, porque sem o nome o log de produção
                // obriga a abrir o banco para saber de que produto se fala.
                $falhas++;
                Log::warning(sprintf(
                    '%s falhou na oferta %d (%s — %s): %s',
                    self::TAG,
                    (int) $oferta->id,
                    (string) ($oferta->produto?->sku ?? 'sem SKU'),
                    (string) ($oferta->produto?->nome ?? 'sem nome'),
                    $e->getMessage(),
                ));
            }
        }

        return [
            'trios' => count($representantes),
            'aquecidos' => $aquecidos,
            'pulados' => $pulados,
            'falhas' => $falhas,
            'segundos' => round(microtime(true) - $inicio, 2),
        ];
    }

    /**
     * As contas que TÊM oferta na fila — o universo do comando sem `--empresa`.
     *
     * ⚠️ Fila vazia devolve lista vazia, e aí o comando não varre conta nenhuma: varrer as ~84
     * empresas do sistema para descobrir que nenhuma tem oferta seria gastar consulta para
     * confirmar o óbvio.
     *
     * @return list<int>
     */
    public function contasComOferta(): array
    {
        return PubAprovacaoOferta::query()
            ->whereNotNull('company_id')
            ->distinct()
            ->orderBy('company_id')
            ->pluck('company_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * A string de AGRUPAMENTO da oferta — `null` quando não há o que aquecer.
     *
     * ⚠️⚠️ **ISTO NÃO É CHAVE DE CACHE, E NÃO PODE VIRAR.** Ela nunca sai deste arquivo e nunca
     * chega ao `Cache`: serve para escolher **uma** oferta representante por trio e para contar o
     * relatório. Quem monta a chave de verdade é o serviço da Fase 186, por dentro de `para()`.
     *
     * ⚠️ As três fontes são as MESMAS que `para()` usa, de propósito: agrupar por outra coisa
     * escolheria o representante errado e deixaria um trio frio sem ninguém saber.
     */
    private function agrupadorDaOferta(PubAprovacaoOferta $oferta): ?string
    {
        $categoria = (string) ($oferta->rascunho?->categoria_id ?? '');
        $listingType = (string) ($oferta->alvo?->listing_type_id ?? '');

        if ($categoria === '' || $listingType === '') {
            return null;
        }

        $preco = $this->financeira->precoDoAlvo($oferta)['preco'];

        if ($preco === null || $preco <= 0) {
            return null;
        }

        return $categoria.'|'.$listingType.'|'.$preco;
    }
}
