<?php

namespace App\Services\Publicador;

use App\Models\PubProduto;
use App\Models\PubRascunho;
use Illuminate\Support\Facades\Log;

/**
 * O estoque do kit acompanha o do produto base (§7 da ETAPA-3, Fase 175 plano
 * 09): gravar dados de variante no base propaga `floor(estoque do base ÷ N)`
 * para o rascunho de cada kit — por variante E por depósito.
 *
 * Chamado pelo gancho no fim de `EditorRascunhoService::salvarVariantes()`, que
 * é o ÚNICO momento em que o estoque do base muda.
 *
 * ═══ As quatro decisões que moldam este arquivo ══════════════════════════════
 *
 * 1. **UM `exists()` é o guarda de custo E o fim do laço.** O gancho roda em
 *    TODA gravação de variante do módulo (a grade salva a cada digitação), e a
 *    esmagadora maioria dos produtos não tem kit. Então a primeira e única coisa
 *    do caminho vazio é um `exists()` indexado por `produto_base_id`
 *    (`pubprod_base_ix`, migration do 175-01) — e sai (T-175-39). Ele é
 *    consultado por `produto_id` do rascunho, nunca pelo `PubProduto` carregado:
 *    carregar o produto seria uma segunda consulta, em todo salvamento do módulo.
 *
 *    O MESMO `exists()` é o fim do laço infinito (T-175-38): kit nunca é base da
 *    família (§2, "aponta para um base que não é kit"), então o recálculo que a
 *    gravação DO KIT dispara não encontra kit nenhum e devolve vazio na primeira
 *    volta. Como laço desse tipo só aparece em produção, há ainda um fusível de
 *    memória (`$emCurso`) para o caso de dados corrompidos criarem uma cadeia que
 *    o `CriarFaseService` (KIT-02) e o unique `pubprod_base_qtd_uq` recusam.
 *
 * 2. **Só kit com `estoque_calculado = true`.** Combo vinculado tem estoque
 *    próprio, digitado por uma pessoa, e sobrescrevê-lo seria apagar trabalho
 *    (T-175-37). Ele entra no filtro do `exists()` também: base cujo único kit é
 *    combo vinculado nem abre a segunda consulta.
 *
 * 3. **A escrita passa por `EditorRascunhoService::salvarVariantes()`, nunca por
 *    UPDATE cru.** É o caminho normal do módulo: trava de linha do rascunho do
 *    kit (WR-B02), derivação do total a partir dos depósitos, e `tocar()` — a
 *    conferência antiga do kit não vale para o estoque novo. E é por isso que
 *    este serviço roda FORA da transação do base: aninhar a trava do rascunho do
 *    base com a do kit é corrida garantida (T-175-40). Uma transação por kit.
 *
 * 4. **Só grava quando o número MUDA.** Sem essa comparação, cada digitação na
 *    grade do base subiria a revisão do kit e derrubaria a conferência dele por
 *    nada. `kits` devolve só os que realmente mudaram.
 *
 * ═══ Kit já publicado: o rascunho muda, o anúncio no ML não ═════════════════
 *
 * ⚠️ Achado medido: o editor NÃO tem trava para rascunho publicado (só os
 * criativos e a IA têm `INTOCAVEIS`). Então o estoque do base pode mudar depois
 * de publicado e este serviço reescreve o rascunho de um kit JÁ publicado — de
 * propósito: o rascunho é a verdade local. Atualizar o anúncio no Mercado Livre
 * está explicitamente fora desta etapa (§1, "Não entra"), e é para isso que
 * serve o aviso "Estoque no ML difere do calculado" do
 * `EditorRascunhoService::estado()` (T-175-41). `divergentes` é o que alimenta o
 * registro no log: kit publicado cujo estoque mudou nesta passada.
 *
 * ⚠️ A divisão NÃO é reimplementada aqui: `PreviaDaFaseService::estoqueDoKit()`
 * (175-05) é a fonte única do `floor(÷ N)`, e ela divide cada DEPÓSITO antes de
 * somar. As duas contas dão números diferentes (A=5, B=5, N=3: 1+1=2, e
 * floor(10÷3)=3) e só uma delas é a que o editor grava e o ML recebe.
 *
 * ⚠️ `pub_produtos.fase` é o NÚMERO da fase; `estrutura_ofertas.fase` é o TIPO da
 * oferta no Portal. Nada aqui faz JOIN entre as duas.
 */
class RecalculoEstoqueDoKitService
{
    /** Rascunho nesses status tem anúncio no ar: mudança de estoque aqui é divergência. */
    private const NO_AR = [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED];

    /**
     * Fusível de memória do laço (T-175-38): ids de rascunho em propagação neste
     * request. O `exists()` já corta a recursão normal; isto cobre o caso de uma
     * cadeia de kits que só existe com dados corrompidos — sem ele, um ciclo em
     * `produto_base_id` derrubaria o processo em vez de gravar errado.
     *
     * `static` de propósito: o serviço é resolvido do container a cada volta
     * (`app()`), então uma propriedade de instância não atravessaria a chamada.
     *
     * @var array<int, true>
     */
    private static array $emCurso = [];

    /**
     * ⚠️ `EditorRascunhoService` é resolvido do container SOB DEMANDA, nunca
     * injetado no construtor: ele injeta este serviço de volta (pelo gancho), e
     * as duas dependências no construtor fariam o container recursar na
     * resolução. O gancho de lá também usa `app()`, pelo mesmo motivo.
     */
    private ?EditorRascunhoService $editor = null;

    /**
     * Propaga o estoque do rascunho do base para os kits dele.
     *
     * @return array{kits: list<int>, divergentes: list<int>} ids de produto: os
     *     kits cujo estoque MUDOU, e, entre eles, os que já estão no ar
     */
    public function propagar(PubRascunho $rascunhoDoBase): array
    {
        $vazio = ['kits' => [], 'divergentes' => []];
        $rascunhoId = (int) $rascunhoDoBase->id;
        // `produto_id` é coluna do rascunho: usar `->produto` custaria uma
        // consulta a mais em TODA gravação de variante do módulo.
        $baseId = (int) $rascunhoDoBase->produto_id;

        if ($baseId === 0 || isset(self::$emCurso[$rascunhoId])) {
            return $vazio;
        }

        // A ÚNICA consulta do caminho vazio — e o fim do laço (decisão 1).
        if (! PubProduto::where('produto_base_id', $baseId)->where('estoque_calculado', true)->exists()) {
            return $vazio;
        }

        self::$emCurso[$rascunhoId] = true;

        try {
            $doBase = $this->estoquePorChave($rascunhoDoBase);
            $kits = [];
            $divergentes = [];

            foreach (PubProduto::where('produto_base_id', $baseId)->where('estoque_calculado', true)->orderBy('fase')->orderBy('id')->get() as $kit) {
                try {
                    if ($this->recalcular($kit, $doBase)) {
                        $kits[] = (int) $kit->id;
                        if (\in_array($kit->rascunho?->status, self::NO_AR, true)) {
                            $divergentes[] = (int) $kit->id;
                        }
                    }
                } catch (\Throwable $e) {
                    // Um kit que falha não impede o próximo — e nada disso pode
                    // chegar na gravação do base, que a pessoa pediu.
                    Log::error("[Publicador] recálculo do estoque do kit {$kit->id} (base {$baseId}) falhou: ".$e->getMessage());
                }
            }

            return ['kits' => $kits, 'divergentes' => $divergentes];
        } finally {
            unset(self::$emCurso[$rascunhoId]);
        }
    }

    /**
     * Um kit: calcula o estoque de cada variante a partir da variante de mesma
     * `combinacao_chave` no base e grava pelo caminho normal do editor.
     *
     * Variante do kit sem par no base é IGNORADA (não entra em `$porChave`, e o
     * `salvarVariantes` regrava o que já estava): as grades podem ter divergido
     * depois de uma edição de eixos, e nesse caso não há de onde dividir.
     *
     * @param  array<string, array{estoque: ?int, estoque_depositos: ?array}>  $doBase
     * @return bool se algum número mudou (e portanto houve escrita)
     */
    private function recalcular(PubProduto $kit, array $doBase): bool
    {
        $r = $kit->rascunho;
        if ($r === null) {
            return false;
        }

        $n = max(2, (int) $kit->quantidade_kit);
        $porChave = [];
        $antes = [];

        foreach ($r->variantes()->get(['combinacao_chave', 'estoque', 'estoque_depositos']) as $v) {
            $chave = (string) $v->combinacao_chave;
            if (! \array_key_exists($chave, $doBase)) {
                continue;
            }

            // A fonte única do floor(÷ N) — por depósito antes de somar (175-05).
            $calculado = PreviaDaFaseService::estoqueDoKit($doBase[$chave], $n);
            $porChave[$chave] = ['estoque' => $calculado['estoque'], 'estoque_depositos' => $calculado['depositos']];
            $antes[$chave] = ['estoque' => $v->estoque === null ? null : (int) $v->estoque, 'estoque_depositos' => $v->estoque_depositos];
        }

        // Decisão 4: sem mudança não se grava — a conferência do kit continua valendo.
        if ($porChave === [] || $this->iguais($antes, $porChave)) {
            return false;
        }

        $this->editor()->salvarVariantes($r, $porChave);

        Log::info("[Publicador] estoque do kit {$kit->id} (Kit {$n}, rascunho {$r->id}) recalculado do base {$kit->produto_base_id}: "
            .json_encode($porChave, JSON_UNESCAPED_UNICODE));

        return true;
    }

    /**
     * O estoque digitado de cada variante do base, no shape que
     * `PreviaDaFaseService::estoqueDoKit()` espera.
     *
     * @return array<string, array{estoque: ?int, estoque_depositos: ?array}>
     */
    private function estoquePorChave(PubRascunho $r): array
    {
        $mapa = [];
        foreach ($r->variantes()->get(['combinacao_chave', 'estoque', 'estoque_depositos']) as $v) {
            $mapa[(string) $v->combinacao_chave] = [
                'estoque' => $v->estoque === null ? null : (int) $v->estoque,
                'estoque_depositos' => $v->estoque_depositos,
            ];
        }

        return $mapa;
    }

    /**
     * Comparação por VALOR, chave a chave: `null` e `0` são coisas diferentes
     * aqui (nulo é "não informado"), e a ordem dos depósitos não conta.
     *
     * @param  array<string, array>  $antes
     * @param  array<string, array>  $depois
     */
    private function iguais(array $antes, array $depois): bool
    {
        foreach ($depois as $chave => $novo) {
            $velho = $antes[$chave] ?? null;
            if ($velho === null) {
                return false;
            }
            if (($velho['estoque'] ?? null) !== ($novo['estoque'] ?? null)) {
                return false;
            }
            $a = (array) ($velho['estoque_depositos'] ?? []);
            $b = (array) ($novo['estoque_depositos'] ?? []);
            ksort($a);
            ksort($b);
            if (array_map('intval', $a) !== array_map('intval', $b)) {
                return false;
            }
        }

        return true;
    }

    private function editor(): EditorRascunhoService
    {
        return $this->editor ??= app(EditorRascunhoService::class);
    }
}
