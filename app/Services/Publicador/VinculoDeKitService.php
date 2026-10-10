<?php

namespace App\Services\Publicador;

use App\Models\PubProduto;
use App\Support\Publicador\RegraViolada;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * §6 da ETAPA-3 (Fase 175, plano 175-08): o combo que JÁ existe passa a ser a fase
 * de outro produto.
 *
 * ═══ A REGRA DE OURO DESTA CLASSE ═══════════════════════════════════════════
 *
 * **Ela grava três colunas e nada mais.** `produto_base_id`, `quantidade_kit` e
 * `fase` — mais o `estoque_calculado = false`, que é a mesma afirmação por outro
 * lado ("o combo mantém o próprio estoque").
 *
 * Nada de `PubRascunho::update`, nada de `touch` em rascunho, nenhuma validação
 * disparada, nenhuma foto, nenhum SELLER_SKU, nenhuma publicação, nenhum
 * `ml_item_id`. É exatamente a diferença entre as duas operações da spec:
 *
 * | §5 "Criar fase" (`CriarFaseService`) | §6 "Vincular" (esta classe)        |
 * |--------------------------------------|------------------------------------|
 * | CLONA o rascunho do base inteiro     | não lê o rascunho de ninguém       |
 * | nasce produto novo                   | nenhum produto nasce               |
 * | estoque = floor(base ÷ N), calculado | estoque é o que o combo já tinha   |
 * | `estoque_calculado = true`           | `estoque_calculado = false`        |
 *
 * O combo vinculado já está no ar com os anúncios dele: mexer no rascunho seria
 * reescrever um anúncio publicado para registrar um parentesco. O critério de
 * aceite da §9 ("Vincular não altera rascunho, estoque nem MLBs") é provado em
 * `tests/Feature/Publicador/VinculoDeKitTest.php` comparando o snapshot do
 * rascunho e as linhas de `pub_publicacao_itens` antes e depois.
 *
 * ⚠️ Consequência de `estoque_calculado = false`, de propósito: o
 * `RecalculoEstoqueDoKitService` NÃO recalcula este kit quando o estoque do base
 * muda. O cartão da fase mostra "estoque próprio" com o valor calculado ao lado
 * e a ação explícita "Usar estoque calculado" — a pessoa decide, não o sistema.
 * Essa ação é o `usarEstoqueCalculado()` desta classe (quick 261009-uec): ela
 * grava a coluna e SÓ ENTÃO chama o recálculo, porque o recálculo pula quem
 * ainda está em `false`. Nada aqui recalcula sozinho.
 *
 * ═══ Escopo (D-13) ══════════════════════════════════════════════════════════
 *
 * Esta classe NÃO resolve escopo: ela recebe dois `PubProduto` que o controller
 * já tirou de `ProgramasPublicadorService::produtosQuery()`. O `base_id` é o
 * único id de entidade que vem do corpo da requisição nesta fase, e quem o
 * resolve dentro do escopo da conta (404 fora dele) é o
 * `MlbPublicadorFaseController` (T-175-32).
 *
 * ═══ As recusas ═════════════════════════════════════════════════════════════
 *
 * | regra    | motivo                                                      |
 * |----------|-------------------------------------------------------------|
 * | KIT-06   | a base escolhida é um composto do Planejamento (regra do CriarFaseService) |
 * | VINC-01  | o base escolhido é o próprio produto                        |
 * | VINC-02  | o base escolhido já é kit de alguém (não existe cadeia)     |
 * | VINC-03  | quantidade < 2 (um "kit" de 1 unidade é o próprio produto)   |
 * | VINC-04  | a família do base já tem um Kit N com essa quantidade       |
 * | VINC-05  | o produto a vincular já é base de outras fases (cadeia)     |
 * | VINC-06  | o produto a vincular já está vinculado (desfaça primeiro)   |
 * | VINC-07  | "Usar estoque calculado" num produto que não é kit          |
 * | VINC-08  | "Usar estoque calculado" num kit cujo base foi apagado      |
 * | VINC-09  | "Usar estoque calculado" com o base ainda sem rascunho      |
 */
class VinculoDeKitService
{
    /**
     * Registra que `$produto` é o kit de `$quantidade` unidades de `$base`.
     *
     * @throws RegraViolada KIT-06, VINC-01..VINC-06
     */
    public function vincular(PubProduto $produto, PubProduto $base, int $quantidade): void
    {
        // KIT-06, a MESMA regra do `CriarFaseService::recusarComposto()` aplicada no outro
        // caminho (não é regra nova, por isso o código é o mesmo): o composto do Planejamento
        // é uma composição pronta do Portal, não a Fase 1 de um produto. A sugestão automática
        // já não propõe composto como base, mas `base_id` é o ÚNICO id de entidade que vem do
        // corpo da requisição nesta fase (D-13) — esconder não é impedir.
        //
        // ⚠️ Vale SÓ para a base: o composto pode perfeitamente ser o produto VINCULADO (o
        // kit da família), e é isso que o `CompostoDoPlanejamentoTest` prova.
        $tipoDaBase = PlanejamentoDaFaseService::tipoComposto($base);
        if ($tipoDaBase !== null) {
            throw new RegraViolada('KIT-06', PlanejamentoDaFaseService::motivoKit06($tipoDaBase));
        }
        if ((int) $base->id === (int) $produto->id) {
            throw new RegraViolada('VINC-01', 'Um produto não pode ser kit de si mesmo. Escolha o produto de 1 unidade.');
        }
        if ($base->produto_base_id !== null) {
            throw new RegraViolada('VINC-02', 'O produto escolhido já é um kit. Vincule ao produto base (1 unidade).');
        }
        if ($quantidade < 2) {
            throw new RegraViolada('VINC-03', 'Um kit tem 2 unidades ou mais.', ['campo' => 'quantidade']);
        }
        if ($produto->produto_base_id !== null) {
            throw new RegraViolada('VINC-06', 'Este produto já está vinculado a outro produto base. Desfaça o vínculo antes.');
        }
        // Cadeia pelo outro lado: quem já é base de alguém é o começo de uma família.
        if ($produto->kits()->exists()) {
            throw new RegraViolada('VINC-05', 'Este produto já é o base de outras fases. Um kit não pode ter kits.');
        }

        // A família do BASE decide a fase (max + 1) e recusa a quantidade repetida.
        $familia = $base->familia();
        $this->recusarQuantidadeRepetida($familia, $quantidade);

        $fase = PubProduto::proximaFase($familia->pluck('fase')->all());

        try {
            DB::transaction(function () use ($produto, $base, $quantidade, $fase) {
                $produto->produto_base_id = $base->id;
                $produto->quantidade_kit = $quantidade;
                $produto->fase = $fase;
                // §6: o combo mantém o próprio estoque — nunca é recalculado sozinho.
                $produto->estoque_calculado = false;

                // Guarda viva do contrato: se um dia alguém acrescentar uma escrita
                // aqui, o teste byte a byte falha — e esta asserção diz por quê.
                $sujas = array_keys($produto->getDirty());
                sort($sujas);
                $esperadas = ['estoque_calculado', 'fase', 'produto_base_id', 'quantidade_kit'];
                if (array_diff($sujas, $esperadas) !== []) {
                    throw new RegraViolada('VINC-00', 'Vincular só pode gravar as colunas da família.');
                }

                $produto->save();
            });
        } catch (QueryException $e) {
            // Corrida no unique `pubprod_base_qtd_uq`: outro processo criou o mesmo
            // Kit N entre a conferência e o update. Mesma mensagem do VINC-04.
            if ($e->getCode() === '23000') {
                throw new RegraViolada('VINC-04', "Já existe Kit {$quantidade} deste produto.", ['campo' => 'quantidade']);
            }

            throw $e;
        }
    }

    /**
     * "Usar estoque calculado" (§6): a pessoa decide que ESTE kit passa a
     * acompanhar o estoque do base — um clique, por kit.
     *
     * ═══ Por que a coluna é gravada ANTES do recálculo ══════════════════════
     *
     * O `RecalculoEstoqueDoKitService` **pula de propósito** quem tem
     * `estoque_calculado = false` (decisão 2 de lá: combo vinculado tem estoque
     * digitado por uma pessoa e sobrescrevê-lo seria apagar trabalho). Então
     * chamar o recálculo antes de gravar não faria nada: o `exists()` do caminho
     * vazio nem abriria a segunda consulta. Gravar primeiro é o que transforma
     * a decisão da pessoa em algo que o recálculo vê.
     *
     * ⚠️ Esse contrato continua intacto: o recálculo automático (disparado por
     * `EditorRascunhoService::salvarVariantes()` no base) segue pulando TODO kit
     * que ainda está em `false` — inclusive os irmãos deste.
     *
     * ⚠️ Grava UMA coluna e nada mais. A trava `VINC-00` é a mesma do
     * `vincular()` e a lista `$esperadas` NÃO é alargada: `estoque_calculado`
     * já está nela.
     *
     * ⚠️ O recálculo roda FORA da transação e é resolvido por `app()` sob
     * demanda, nunca injetado no construtor: ele abre uma transação por kit e
     * trava a linha do rascunho (decisão 3 de lá — aninhar as travas é corrida
     * garantida), e injetá-lo fecharia o ciclo conhecido deste módulo
     * (`EditorRascunhoService` ↔ recálculo) na resolução do container.
     *
     * @throws RegraViolada VINC-00, VINC-07..VINC-09
     */
    public function usarEstoqueCalculado(PubProduto $kit): void
    {
        if ((int) $kit->quantidade_kit < 2) {
            throw new RegraViolada('VINC-07', 'Este produto é a Fase 1, de 1 unidade: não existe um produto base de onde calcular o estoque dele.');
        }
        // `produto_base_id` nulo com quantidade >= 2 é o estado que o SET NULL
        // deixa quando o base é apagado — a mesma leitura do `base_excluido` da
        // tela do Produto.
        if ($kit->produto_base_id === null) {
            throw new RegraViolada('VINC-08', 'O produto base deste kit foi excluído, então não há de onde calcular o estoque. Vincule o kit a um produto base antes.');
        }

        // Já adotado: não grava de novo e não recalcula. Dois cliques no botão
        // não podem subir a revisão do rascunho do kit nem derrubar a
        // conferência dele por nada.
        if ((bool) $kit->estoque_calculado) {
            return;
        }

        $rascunhoDoBase = $kit->base?->rascunho;
        if ($rascunhoDoBase === null) {
            throw new RegraViolada('VINC-09', 'O produto base ainda não tem um anúncio preparado, então não há estoque de onde calcular. Abra a Fase 1 no editor antes.');
        }

        DB::transaction(function () use ($kit) {
            $kit->estoque_calculado = true;

            // Mesma guarda viva do `vincular()`: se um dia alguém acrescentar
            // uma escrita aqui, o teste byte a byte falha e esta asserção diz
            // por quê. A lista é a MESMA — nada de alargá-la.
            $sujas = array_keys($kit->getDirty());
            sort($sujas);
            $esperadas = ['estoque_calculado', 'fase', 'produto_base_id', 'quantidade_kit'];
            if (array_diff($sujas, $esperadas) !== []) {
                throw new RegraViolada('VINC-00', 'Adotar o estoque calculado só pode gravar as colunas da família.');
            }

            $kit->save();
        });

        app(RecalculoEstoqueDoKitService::class)->propagar($rascunhoDoBase);
    }

    /**
     * Desfaz o vínculo: o produto volta a ser base de fase 1, de 1 unidade.
     *
     * Também não toca em mais nada — e NÃO mexe em `kit_sugestao_recusada_em`:
     * "não é kit" é uma decisão separada, com carimbo próprio. Produto que nunca
     * foi kit passa por aqui sem efeito nenhum.
     */
    public function desvincular(PubProduto $produto): void
    {
        $produto->produto_base_id = null;
        $produto->quantidade_kit = 1;
        $produto->fase = 1;

        if ($produto->isDirty()) {
            $produto->save();
        }
    }

    /**
     * "Não é kit" (§6): carimba a recusa e o produto nunca mais recebe sugestão
     * (`SugestaoDeKitService::sugerirPara()` devolve null na primeira guarda).
     *
     * T-175-36: idempotente — a segunda recusa preserva a data da primeira, que é
     * o registro de QUANDO a pessoa decidiu.
     */
    public function recusar(PubProduto $produto): void
    {
        if ($produto->kit_sugestao_recusada_em !== null) {
            return;
        }

        $produto->kit_sugestao_recusada_em = now();
        $produto->save();
    }

    /** @param  \Illuminate\Support\Collection<int, PubProduto>  $familia */
    private function recusarQuantidadeRepetida($familia, int $quantidade): void
    {
        if ($familia->contains(fn (PubProduto $p) => (int) $p->quantidade_kit === $quantidade && $p->produto_base_id !== null)) {
            throw new RegraViolada('VINC-04', "Já existe Kit {$quantidade} deste produto.", ['campo' => 'quantidade']);
        }
    }
}
