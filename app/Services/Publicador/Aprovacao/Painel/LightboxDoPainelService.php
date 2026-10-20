<?php

namespace App\Services\Publicador\Aprovacao\Painel;

use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\PubImagemReferencia;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A ORIGINAL DE REFERÊNCIA DE CADA IMAGEM DO LIGHTBOX (AMPL-05 / IMG-13) — Fase 189, plano 02.
 *
 * É o **ÚNICO dado novo de servidor** da Fase 189. `FotosDoPainelService` (188-03) entrega a
 * galeria do painel; ele **não** entrega a original de que a imagem de IA nasceu, e a lateral do
 * lightbox precisa dela. Este serviço preenche só esse buraco e **não altera nada** das Fases
 * 186/187/188 — ele **CHAMA** o que elas já resolvem.
 *
 * ═══ ⚠️⚠️ ESTE SERVIÇO SÓ LÊ — E A ESCRITA DO VÍNCULO É DAS FASES 192 E 194 ════════════════════
 *
 * ⚠️⚠️ **NADA AQUI ESCREVE `pub_imagem_referencias`.** A escrita é da **Fase 192** (a regeneração
 * de UMA imagem, onde a escolha das originais acontece) e da **Fase 194** (a automação atrás do
 * portão) — está nomeado em `PubImagemReferencia` e há gate varrendo **`app/` inteiro** por
 * `PubImagemReferencia::create|insert|updateOrCreate|…`
 * ({@see \Tests\Feature\Publicador\Aprovacao\VinculoDuravelDaOriginalTest}, id
 * `186-06 dado-15-sem-escrita`), rodado como não-regressão por este plano.
 *
 * ⚠️ **Nenhum backfill é possível** (o dado do acervo antigo já foi apagado pela retenção de 48 h
 * do Creative Engine). ➡️ **Na estreia TODA imagem de IA cai no estado declarado abaixo** — e isso
 * é o comportamento certo, não defeito.
 *
 * ═══ ⚠️ O ESTADO DECLARADO DE IMG-13 VEM DO MODELO, UMA FONTE SÓ ═══════════════════════════════
 *
 * `estado`, `rotulo`, `apoio` e `icone` são **lidos** de {@see PubImagemReferencia::SEM_ORIGINAL},
 * `SEM_ORIGINAL_ROTULO`, `SEM_ORIGINAL_APOIO` e `SEM_ORIGINAL_ICONE`. ⚠️ **O texto não é
 * redigitado em lugar nenhum**: duas cópias do mesmo rótulo divergem, e a divergente é descoberta
 * por alguém vendo dois textos diferentes para a mesma ausência. Nunca quadro vazio, nunca zero,
 * nunca a própria imagem de IA no lugar da original.
 *
 * ═══ ⚠️ POR QUE `para()` RECEBE AS `$imagens` EM VEZ DE RECARREGAR A GALERIA ═══════════════════
 *
 * A lista é a **`painel.fotos.imagens`** que o `FotosDoPainelService` já montou, e as chaves do
 * retorno casam por **`id`** (`pub_aprovacao_imagens.id`). Recarregar a galeria aqui abriria uma
 * **segunda consulta livre para divergir** da que a tela já viu — o mesmo argumento pelo qual o
 * painel recebe a `$base` em vez de remontá-la —, e a divergência apareceria como *"a lateral
 * mostra a original de outra foto"*.
 *
 * ═══ ⚠️ O CUSTO É FIXO: UMA consulta, nunca uma por imagem ═════════════════════════════════════
 *
 * `PubImagemReferencia::originaisDe()` faz **uma consulta por imagem** — correto para o caso de
 * uma, N+1 para a coluna inteira. Aqui a leitura é **UM `whereIn`** sobre os `pub_imagens.id` das
 * imagens de IA, com `with('imagemOriginal')` e a **MESMA ordem** (`posicao`, depois `id`), e o
 * agrupamento é em PHP. ⚠️ **As duas leituras da mesma verdade têm de bater**, e há caso
 * comparando este resultado com o de `originaisDe()` imagem por imagem na mesma fixture — se
 * divergirem, **a do modelo ganha**.
 * ⚠️ Galeria sem nenhuma imagem de IA **não dispara consulta nenhuma** (`whereIn` com lista vazia
 * ainda é uma ida ao banco).
 *
 * ═══ ⚠️⚠️ A ORIGINAL TEM DE SER DO MESMO RASCUNHO (T-189-10) ═══════════════════════════════════
 *
 * `arquivoFoto` acha a foto pelo rascunho do produto autorizado e daria **404** para a original de
 * outro rascunho — mas **o id dela não pode nem aparecer no payload**: id que volta é existência
 * confirmada, e DADO-14 é textual em que nenhuma leitura cruza empresa. ➡️ Original de outro
 * rascunho é tratada como **ausente** (o estado declarado), e não há como distingui-la de fora.
 *
 * ═══ ⚠️⚠️ A URL DA ORIGINAL: A ROTA JÁ EXISTE — E CARREGÁ-LA MARCA O EDITOR EM USO ═════════════
 *
 * O byte vem da **MESMA** rota que a miniatura do painel já usa,
 * **`mlb.anuncios.publicador.fotos.arquivo`** (`routes/mlb_anuncios.php`, prefixo
 * `publicador/produtos/{produto}` mais `/fotos/{imagem}/arquivo`, que aponta para
 * `MlbPublicadorController::arquivoFoto`), com `{produto} = pub_produtos.id`
 * (`PubAprovacaoOferta::$produto_id`) e `{imagem}` = o id da **ORIGINAL** em `pub_imagens` —
 * **nunca** o `imagem_id` da revisão de IA (seria a imagem de IA repetida no lugar da original, o
 * que IMG-13 proíbe).
 *
 * ⚠️ **REUSAR NÃO É EDITAR:** `App\Support\Publicador\Imagem\ArquivosDeImagem`, `Acervo*` e
 * `ImagemAssetService` ficam **intocados** — a v28 virou o acervo para o **R2 privado** em
 * produção (16/10/2026) e o bucket **não serve URL pública**; a rota serve **BYTES**, que é o que
 * o `<img src>` precisa. A regra de `FotosDoPainelService::urlDoArquivo()` (privado, **não** foi
 * tornado público nem chamado por reflexão) é **reproduzida** aqui: `caminho === null` → **`null`**,
 * ⚠️ **nunca string vazia**, porque `<img src="">` faz o navegador recarregar a própria página.
 *
 * ⚠️⚠️ **PERGUNTA-188-C — O EFEITO COLATERAL DO REUSO, ACEITO PELO USUÁRIO EM 19/10/2026, E ELE
 * VALE IGUAL AQUI.** A cadeia é `arquivoFoto` → `rascunho()` → `produto()` →
 * **`EditorEmUso::marcar`** (em **`MlbPublicadorController`**, método `produto`), que marca o
 * produto como *"com o editor aberto"* por **3 minutos** (`config/publicador.php`, chave
 * `publicador.preparo_ia.editor_em_uso_min`).
 *
 * ➡️ **CONSEQUÊNCIA, por extenso: abrir a original no lightbox SUSPENDE o agendamento e o preparo
 * por IA daquele produto enquanto alguém revisa.** Os **CINCO arquivos leitores** do sinal, um por
 * um — ⚠️ **ancorados por NOME de arquivo e de MÉTODO, nunca por número de linha** (o gate que
 * fixava as linhas reprovou trabalho correto em 20/10/2026, quando outra sessão editou acima
 * delas; `tests/gates/contagens-188-03.txt` registra a remedição):
 *
 *   1. **`AgendadorDaFila`**, em `checar()` — `EditorEmUso::emUso` devolve *"esperar"*: o produto
 *      não é agendado nesta passada;
 *   2. **`PreparoIaDoRascunhoService`**, em `preparar()` — o preparo por IA do lote pula o produto;
 *   3. **`PreparoIaDoRascunhoService`**, em `sincronizarAgora()` — idem, no segundo laço;
 *   4. **`PreparoIaDoRascunhoService`**, em `executarEtapa()` — idem, na escrita do rascunho;
 *   5. **`GarantiaPadrao`**, em `aplicarNaConta()` — a garantia padrão não é escrita por baixo de
 *      quem edita;
 *   6. **`ExcluirProdutoService`**, em `impedimento()` — a exclusão do produto espera.
 *
 * ⚠️ **A RAZÃO DO USUÁRIO: *é o que o portão quer*.** `AgendadorDaFila` diz, no próprio comentário,
 * *"Gente com o editor aberto: não publica por baixo dela"* — e **revisar é trabalhar no produto**.
 * ⚠️ **"Servir a foto sem marcar" foi OFERECIDO e RECUSADO**, porque exigiria **rota nova sobre
 * `App\Support\Publicador`** — território de outra sessão — e coordenação que o ganho não paga.
 * ➡️ **Não há nada a consertar, e não se abre rota nova.** Fica **declarado**, não escondido.
 *
 * ═══ ⚠️ APROVAR ENFILEIRA, E ESTE SERVIÇO NEM APROVA ═══════════════════════════════════════════
 *
 * A leitura do lightbox é **leitura**: zero requisição ao Mercado Livre sai dela (provado por
 * ausência, com isca que lança). Aprovar/Reprovar foto reusam as rotas do 188-05 pelo helper único
 * `escreverNoPainel`, e **a escrita no Mercado Livre é da Fase 193**. A regeneração ("Gerar
 * novamente com IA") é da **Fase 192** e **não é antecipada**.
 *
 * ⚠️ Este payload **não tem chave de andamento**: a trava `live` é lida do `painel`, que já a
 * entrega. Duas verdades sobre o mesmo estado divergiriam, e a porta única de escrita do andamento
 * é {@see \App\Services\Publicador\Aprovacao\AndamentoDaOfertaService}.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class LightboxDoPainelService
{
    /**
     * A original de referência de cada imagem da galeria do painel.
     *
     * ⚠️ `$imagens` é a lista `painel.fotos.imagens` do {@see FotosDoPainelService} — **uma fonte
     * só**. A chave do retorno é o `id` da **revisão** (`pub_aprovacao_imagens.id`), que é o mesmo
     * `id` que a tela já usa para identificar a foto.
     *
     * O bloco de cada imagem:
     *   · imagem **Original** (upload, `de_ia !== true`) → **`null`**: ela É uma original, e o
     *     bloco da lateral não existe para ela (C9 do protótipo, `LB.hasRef`);
     *   · imagem de **IA com** vínculo no mesmo rascunho →
     *     `['disponivel' => true, 'url_arquivo' => ?string, 'quantas' => int]`;
     *   · imagem de **IA sem** vínculo utilizável →
     *     `['disponivel' => false, 'estado' => …, 'rotulo' => …, 'apoio' => …, 'icone' => …,
     *       'url_arquivo' => null]`, com os quatro textos **LIDOS** de `PubImagemReferencia`.
     *
     * ⚠️ Item sem `id`, ou com `id` que não é inteiro, **não gera entrada** — e não lança: o
     * payload do servidor sempre manda `int`, e aceitar `'7'` aqui abriria duas chaves para a
     * mesma foto.
     *
     * @param  list<array<string, mixed>>  $imagens
     * @return array{originais: array<int, array<string, mixed>|null>}
     */
    public function para(PubAprovacaoOferta $oferta, array $imagens): array
    {
        /** @var array<int, array<string, mixed>|null> $originais */
        $originais = [];

        /** @var array<int, int> $deIa  id da revisão => pub_imagens.id */
        $deIa = [];

        foreach ($imagens as $item) {
            // ⚠️ Flag DENTRO do laço.
            if (! is_array($item) || ! array_key_exists('id', $item) || ! is_int($item['id'])) {
                continue;
            }

            $id = $item['id'];

            // ⚠️ `=== true`, nunca truthiness: `'1'`, `1` e `'sim'` não são "gerada por IA".
            if (($item['de_ia'] ?? false) !== true) {
                $originais[$id] = null;

                continue;
            }

            $imagemId = $item['imagem_id'] ?? null;

            // ⚠️ Imagem de IA sem a linha crua de `pub_imagens` não tem de onde ler o vínculo —
            // e o estado DECLARADO é a resposta certa, nunca o bloco escondido (IMG-13).
            if (! is_int($imagemId)) {
                $originais[$id] = $this->semOriginal();

                continue;
            }

            $deIa[$id] = $imagemId;
            // Reserva o lugar para a ORDEM de `$imagens` ser preservada no retorno.
            $originais[$id] = null;
        }

        if ($deIa === []) {
            return ['originais' => $originais];
        }

        $porImagem = $this->originaisDoRascunho(
            array_values(array_unique($deIa)),
            $oferta->rascunho_id === null ? null : (int) $oferta->rascunho_id,
        );

        foreach ($deIa as $id => $imagemId) {
            // ⚠️ Flag DENTRO do laço.
            $originais[$id] = $this->bloco($oferta, $porImagem[$imagemId] ?? []);
        }

        return ['originais' => $originais];
    }

    /**
     * As originais de várias imagens geradas em **UMA** consulta, já recortadas ao rascunho.
     *
     * ⚠️ A ordem é a MESMA de `PubImagemReferencia::originaisDe()` (`posicao`, depois `id`): é ela
     * que define qual original a lateral mostra quando há mais de uma.
     *
     * @param  list<int>  $imagemIds
     * @return array<int, list<PubImagem>>
     */
    private function originaisDoRascunho(array $imagemIds, ?int $rascunhoId): array
    {
        $linhas = PubImagemReferencia::query()
            ->whereIn('imagem_id', $imagemIds)
            ->with('imagemOriginal')
            ->orderBy('posicao')
            ->orderBy('id')
            ->get();

        $porImagem = [];

        foreach ($linhas as $linha) {
            // ⚠️ Flag DENTRO do laço.
            $original = $linha->imagemOriginal;

            if (! $original instanceof PubImagem) {
                continue;
            }

            // ⚠️⚠️ T-189-10 — a original de OUTRO rascunho é tratada como AUSENTE, e o id dela
            // não entra no payload: id que volta é existência confirmada.
            if ($rascunhoId === null || (int) $original->rascunho_id !== $rascunhoId) {
                continue;
            }

            $porImagem[(int) $linha->imagem_id][] = $original;
        }

        return $porImagem;
    }

    /**
     * O bloco da lateral para UMA imagem de IA.
     *
     * @param  list<PubImagem>  $originais
     * @return array<string, mixed>
     */
    private function bloco(PubAprovacaoOferta $oferta, array $originais): array
    {
        if ($originais === []) {
            return $this->semOriginal();
        }

        return [
            'disponivel' => true,
            'url_arquivo' => $this->urlDaOriginal($oferta, $originais[0]),
            'quantas' => count($originais),
        ];
    }

    /**
     * O estado declarado de IMG-13, **LIDO** do modelo — nunca redigitado.
     *
     * @return array<string, mixed>
     */
    private function semOriginal(): array
    {
        return [
            'disponivel' => false,
            'estado' => PubImagemReferencia::SEM_ORIGINAL,
            'rotulo' => PubImagemReferencia::SEM_ORIGINAL_ROTULO,
            'apoio' => PubImagemReferencia::SEM_ORIGINAL_APOIO,
            'icone' => PubImagemReferencia::SEM_ORIGINAL_ICONE,
            'url_arquivo' => null,
        ];
    }

    /**
     * A URL que serve os **bytes da ORIGINAL** — ou `null` quando não há arquivo.
     *
     * ⚠️ O parâmetro `imagem` é o id da **ORIGINAL**, nunca o da imagem de IA. ⚠️ `null`, nunca
     * `''`. ⚠️ E carregá-la marca o editor em uso por 3 minutos — ver o docblock da classe.
     */
    private function urlDaOriginal(PubAprovacaoOferta $oferta, PubImagem $original): ?string
    {
        $produtoId = $oferta->produto_id === null ? null : (int) $oferta->produto_id;

        if ($produtoId === null || $original->caminho === null) {
            return null;
        }

        return route('mlb.anuncios.publicador.fotos.arquivo', [
            'produto' => $produtoId,
            'imagem' => (int) $original->id,
        ]);
    }
}
