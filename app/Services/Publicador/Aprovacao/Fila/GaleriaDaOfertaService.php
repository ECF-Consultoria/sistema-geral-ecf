<?php

namespace App\Services\Publicador\Aprovacao\Fila;

use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use Illuminate\Support\Collection;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A GALERIA FINAL DE UM RASCUNHO, ORDENADA — a fonte dos MINI-QUADRADOS da fila (Fase 187, 07).
 *
 * ═══ ⚠️⚠️ POR QUE ESTE ARQUIVO EXISTE, E POR QUE ELE NÃO COPIA NADA ═══════════════════════════
 *
 * A definição de "galeria final" e a **ordem dos grupos** vivem em `galeriaDoRascunho()`, que é um
 * método **PRIVADO** de {@see \App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService} — e
 * aquele arquivo é da Fase 186 e **INTOCÁVEL** nesta fase. Reimplementar o corpo dele aqui criaria
 * **duas verdades** sobre a mesma coisa, e a divergência apareceria na tela como
 * *"5 de 5 fotos aprovadas"* **com um bloqueio de fotos de pé** — o pior tipo de bug de dado,
 * porque parece completo (é o que o docblock de `contagemDeFotos()` já escreveu).
 *
 * ➡️ A saída, em três movimentos:
 *   1. ler pelos **MODELOS** (`PubAprovacaoConjunto::doRascunho()` + `->imagens()` + `alertas`),
 *      que são API **pública**;
 *   2. usar as **CONSTANTES públicas** (`PubAprovacaoImagem::NO_CONJUNTO_FINAL`, `::TIPO_IA`,
 *      `PubAprovacaoConjunto::GERAL`, `PubAprovacaoImagemAlerta::OK`) — **nunca** literais;
 *   3. **PROVAR POR COMPARAÇÃO** que os quatro totais daqui são IDÊNTICOS aos de
 *      `BloqueiosDaOfertaService::contagemDeFotos()` em **6 cenários**
 *      (`GrupoDaFilaServiceTest::test_os_totais_da_galeria_sao_IDENTICOS_aos_do_bloqueio_187_07`).
 *      É isso que substitui o compartilhamento de código: no dia em que os dois divergirem, o gate
 *      acusa — e ele tem **isca invertida** (galeria de rascunho COM conjuntos é NÃO-vazia), para
 *      que "deu tudo igual" não possa ser a varredura cega.
 *
 * ═══ ⚠️ O QUE ENTRA NA GALERIA, UM ESTADO POR VEZ ═════════════════════════════════════════════
 *
 * `PubAprovacaoImagem::noConjuntoFinal()` exige as **três** condições: estado em
 * `NO_CONJUNTO_FINAL` (`pending` ∪ `approved`), `posicao` **não** nula (NULL = fora da publicação)
 * e `fora` falso. Então, dos cinco estados de revisão:
 *   · `pending`      → ENTRA, e conta como pendente;
 *   · `approved`     → ENTRA, e conta como aprovada;
 *   · `rejected`     → **fora**;
 *   · `replaced`     → **fora**;
 *   · `regenerating` → **fora**.
 *
 * ⚠️⚠️ **DIVERGÊNCIA DECLARADA (regenerando), e ela é de VERDADE, não de forma.** O protótipo
 * tem um quadrado **próprio** para `regenerating` — borda `1.5px dashed #71717A`
 * (`design_handoff_publicador/Aprovacao.dc.html:1119`) —, mas aqui `NO_CONJUNTO_FINAL` **não** o
 * inclui, então **o servidor nunca emite esse estado por este caminho**. O quadrado tracejado
 * existe no componente (`QuadradosDeFoto.jsx`) e é conferido por props diretas, com o `title`
 * literal; o que **não** foi feito é alargar a galeria para fabricá-lo, porque isso faria a
 * contagem dos quadrados divergir de `contagemDeFotos()` — exatamente o bug que o gate de
 * comparação existe para impedir. ➡️ Mudar isso é decisão de produto e mexe em
 * `PubAprovacaoImagem::NO_CONJUNTO_FINAL` (Fase 186, intocável).
 *
 * ⚠️ **`com_alerta` é alerta acima de `ok` numa imagem PENDENTE.** O MESMO alerta numa imagem já
 * APROVADA **não conta** — é a definição de `PubAprovacaoConjunto::comAlerta()`, e o porquê está
 * no docblock dela: *"quem olhou já decidiu com o alerta na tela"*. **Lido do modelo, nunca
 * redefinido.**
 *
 * ═══ ⚠️ A ORDEM: `GENERAL` PRIMEIRO, DEPOIS POR `id` ══════════════════════════════════════════
 *
 * Não há coluna `posicao` em `pub_aprovacao_conjuntos` (medido pela Fase 186), então a ordem dos
 * GRUPOS é essa; dentro do grupo, é `posicao` e depois `id`. É a MESMA ordem de
 * `galeriaDoRascunho()`, e o teste 2 a prova com isca (trocando a ordem dos ids, a saída muda).
 *
 * ═══ ⚠️ CUSTO: TRÊS CONSULTAS FIXAS, QUE NÃO CRESCEM COM NADA ═════════════════════════════════
 *
 * Conjuntos (1) · imagens por `whereIn` (1) · alertas por eager loading (1). ⚠️ `temAlerta()`
 * faria **uma consulta por imagem** — o N+1 clássico, e no lote isso é a fila inteira. Há gate de
 * contagem FIXA de consultas, provando que **N ofertas do mesmo rascunho** não multiplicam nada
 * (o rascunho é UM: `pub_rascunhos.produto_id` é UNIQUE e as duas ofertas do produto leem o mesmo
 * conjunto — o compartilhamento Clássico↔Premium é ESTRUTURAL).
 *
 * ⚠️ **Nada aqui é escrito.** Este serviço só LÊ: a escrita de aprovação de foto tem uma porta
 * (`RevisaoDeImagemService`, Fase 186) e a escrita no Mercado Livre é da **Fase 193**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class GaleriaDaOfertaService
{
    /** `sq()` (`:1117`) — o separador do `title` do quadrado: U+00B7, com espaço nos dois lados. */
    public const SEPARADOR = ' · ';

    /** `sq()` (`:1117`) — o veredicto de uma foto já aprovada. */
    public const ESTADO_APROVADA = 'aprovada';

    /** `sq()` (`:1117`) — o veredicto de uma pendente COM alerta automático. */
    public const ESTADO_COM_ALERTA = 'com alerta';

    /**
     * `sq()` (`:1117`) — o veredicto de todo o resto.
     *
     * ⚠️⚠️ **DIVERGÊNCIA D7 do `187-BASELINES.md`, reproduzida e NÃO "consertada":** o ternário do
     * protótipo só distingue `approved` e "com alerta", então `regenerating` cai AQUI — diz
     * *"a conferir"* embora a BORDA do quadrado seja tracejada (`:1119`). O protótipo ganha nos
     * rótulos; a divergência está registrada na SUMMARY com `arquivo:linha`.
     */
    public const ESTADO_A_CONFERIR = 'a conferir';

    /**
     * O rótulo da foto de capa — `posicao` 0 (IMG-07: *"a principal é sempre a `posicao` 0"*).
     *
     * ⚠️⚠️ **DIVERGÊNCIA DECLARADA — a FORMA do protótipo é preservada, a FONTE é outra.** O
     * `label` do protótipo é uma DESCRIÇÃO humana da foto (*"Frente · fundo branco"*,
     * *"Infográfico de medidas"* — `Aprovacao.dc.html:921`-`:933`), e **este sistema não guarda
     * essa descrição**: não há coluna `label` em `pub_imagens` nem em `pub_aprovacao_imagens`
     * (medido no schema). Inventar a descrição seria a tela afirmando o que não sabe.
     * ➡️ O `title` mantém a forma `<rótulo> · <veredicto>` de `sq()` (`:1117`) e o rótulo passa a
     * dizer algo **VERDADEIRO e derivado do dado**: a posição da foto na publicação. É o mesmo
     * tipo de decisão que `BloqueiosDaOfertaService::freteOuCusto()` já tomou e declarou.
     */
    public const ROTULO_CAPA = 'Capa';

    /** O rótulo das demais, com o número **humano** (`posicao` + 1). */
    public const PREFIXO_FOTO = 'Foto ';

    /**
     * A galeria final do rascunho, ORDENADA, com os quatro totais e o de IA.
     *
     * ⚠️ Recebe o **id do rascunho** (e não a oferta) de propósito: o conjunto é do RASCUNHO, as
     * duas ofertas dele leem o MESMO conjunto e **não existe coluna onde elas pudessem divergir**.
     * Receber a oferta convidaria a chamar isto uma vez por oferta.
     *
     * @return array{
     *     imagens: list<array{id: int, estado: string, de_ia: bool, com_alerta: bool, rotulo: string, titulo: string}>,
     *     total: int, aprovadas: int, pendentes: int, com_alerta: int, de_ia: int
     * }
     */
    public function doRascunho(int $rascunhoId): array
    {
        $conjuntos = PubAprovacaoConjunto::doRascunho($rascunhoId)->get(['id', 'grupo_chave']);

        if ($conjuntos->isEmpty()) {
            // ⚠️ Rascunho sem conjunto nenhum é o caso `sem_fotos` (IMG-12): a galeria sai VAZIA e
            // os totais ZERO. Nunca tela muda, e nunca quadrado inventado.
            return $this->vazia();
        }

        // ⚠️ `GENERAL` primeiro, depois por `id` — ver o docblock da classe.
        $ordemDoGrupo = [];
        foreach ($conjuntos as $conjunto) {
            $ordemDoGrupo[(int) $conjunto->id] = [
                $conjunto->grupo_chave === PubAprovacaoConjunto::GERAL ? 0 : 1,
                (int) $conjunto->id,
            ];
        }

        $imagens = PubAprovacaoImagem::query()
            ->with('alertas')
            ->whereIn('conjunto_id', $conjuntos->pluck('id')->all())
            ->orderBy('conjunto_id')
            ->orderBy('posicao')
            ->orderBy('id')
            ->get();

        /** @var Collection<int, PubAprovacaoImagem> $naGaleria */
        $naGaleria = $imagens
            ->filter(fn (PubAprovacaoImagem $i): bool => $i->noConjuntoFinal())
            ->sortBy(fn (PubAprovacaoImagem $i): array => [
                $ordemDoGrupo[(int) $i->conjunto_id][0] ?? 2,
                $ordemDoGrupo[(int) $i->conjunto_id][1] ?? 0,
                $i->posicao ?? 0,
                (int) $i->id,
            ])
            ->values();

        $aprovadas = 0;
        $pendentes = 0;
        $comAlerta = 0;
        $deIa = 0;
        $lista = [];

        foreach ($naGaleria as $indice => $imagem) {
            $estaAprovada = $imagem->estado === PubAprovacaoImagem::APROVADA;

            // ⚠️ A definição de `PubAprovacaoConjunto::comAlerta()`: alerta acima de `ok` numa
            // imagem **PENDENTE**. O mesmo alerta numa aprovada NÃO conta.
            $temAlerta = $imagem->estado === PubAprovacaoImagem::PENDENTE && $this->alertou($imagem);

            $ehDeIa = $imagem->ehDeIa();

            $estaAprovada ? $aprovadas++ : $pendentes++;
            $temAlerta and $comAlerta++;
            $ehDeIa and $deIa++;

            $rotulo = $this->rotuloDaFoto($imagem->posicao, $indice);

            $lista[] = [
                'id' => (int) $imagem->id,
                'estado' => (string) $imagem->estado,
                'de_ia' => $ehDeIa,
                'com_alerta' => $temAlerta,
                'rotulo' => $rotulo,
                'titulo' => $this->tituloDoQuadrado($rotulo, (string) $imagem->estado, $temAlerta),
            ];
        }

        return [
            'imagens' => $lista,
            'total' => count($lista),
            'aprovadas' => $aprovadas,
            'pendentes' => $pendentes,
            'com_alerta' => $comAlerta,
            'de_ia' => $deIa,
        ];
    }

    /**
     * O `title` do mini-quadrado — o ternário literal de `sq()` (`:1117`).
     *
     * ⚠️ **Público de propósito**, e isso é o que torna a divergência D7 MEDÍVEL: o estado
     * `regenerating` nunca chega pela galeria (ver o docblock da classe), e sem um ponto de entrada
     * público o literal dele não poderia ser conferido em teste nenhum — seria divergência
     * declarada sem medição.
     */
    public function tituloDoQuadrado(string $rotulo, string $estado, bool $comAlerta): string
    {
        if ($estado === PubAprovacaoImagem::APROVADA) {
            return $rotulo.self::SEPARADOR.self::ESTADO_APROVADA;
        }

        if ($comAlerta) {
            return $rotulo.self::SEPARADOR.self::ESTADO_COM_ALERTA;
        }

        // ⚠️ D7: `regenerating` cai aqui, e isso é o protótipo, não descuido.
        return $rotulo.self::SEPARADOR.self::ESTADO_A_CONFERIR;
    }

    /**
     * O rótulo da foto pela POSIÇÃO — ver `ROTULO_CAPA` para a divergência declarada.
     *
     * ⚠️ `posicao` nula não chega aqui (`noConjuntoFinal()` já a recusa), mas o fallback para o
     * índice da galeria existe porque "rótulo vazio" produziria um `title` começando com o
     * separador — e `title` que não diz nada é `title` ausente.
     */
    private function rotuloDaFoto(mixed $posicao, int $indice): string
    {
        $numero = is_int($posicao) ? $posicao : $indice;

        return $numero === 0 ? self::ROTULO_CAPA : self::PREFIXO_FOTO.($numero + 1);
    }

    /** A imagem tem verificação automática acima de `ok`? Lê a relação JÁ CARREGADA. */
    private function alertou(PubAprovacaoImagem $imagem): bool
    {
        return $imagem->alertas->contains(
            fn (PubAprovacaoImagemAlerta $alerta): bool => $alerta->severidade !== PubAprovacaoImagemAlerta::OK
        );
    }

    /** @return array{imagens: list<array{id: int, estado: string, de_ia: bool, com_alerta: bool, rotulo: string, titulo: string}>, total: int, aprovadas: int, pendentes: int, com_alerta: int, de_ia: int} */
    private function vazia(): array
    {
        return ['imagens' => [], 'total' => 0, 'aprovadas' => 0, 'pendentes' => 0, 'com_alerta' => 0, 'de_ia' => 0];
    }
}
