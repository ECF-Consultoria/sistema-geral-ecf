<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PubAprovacaoOferta;
use App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService;
use App\Services\Publicador\Aprovacao\Fila\FiltrosDaFilaService;
use App\Services\Publicador\Aprovacao\Fila\GrupoDaFilaService;
use App\Services\Publicador\Aprovacao\Fila\LinhaDaOfertaService;
use App\Services\Publicador\Aprovacao\Fila\LoteDaFilaService;
use App\Services\Publicador\Aprovacao\Fila\ProximoARevisarService;
use App\Services\Publicador\CabecalhoPublicadorService;
use App\Services\Publicador\ProgramasPublicadorService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A FILA "AGUARDANDO APROVAÇÃO" (Fase 187, plano 02) — a METADE DO SERVIDOR.
 *
 * A decisão estrutural da milestone é que **a aba e a rota nascem juntas**: uma aba com contador
 * real que não leva a lugar nenhum é a definição de enfeite, e o usuário exerceu essa régua três
 * vezes na milestone anterior. Este controller é a metade do servidor dessa decisão — quando o
 * plano **187-03** montar a aba, a rota **já responde**.
 *
 * ⚠️ **O PLANO 187-02 NÃO DESENHOU TELA.** A página `Mlb/Publicador/Aprovacao` foi criada pelo
 * plano **187-04**. O teste de rota do 187-02 exercitava a action pelo caminho Inertia **sem
 * resolver o componente** (header `X-Inertia` + asserção sobre as props), que é o que a suíte do
 * projeto já faz e que **não** carrega o `.jsx`.
 *
 * ⚠️⚠️ **PENDÊNCIA FECHADA PELO PLANO 187-04**, e com o mecanismo escrito: enquanto o `.jsx` não
 * existia, um GET **sem** `X-Inertia` nesta rota ERRAVA, porque `resources/views/app.blade.php:38`
 * monta `@vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])` e o Vite
 * não achava a página no manifest. ➡️ `FilaDeAprovacaoRotaTest::
 * test_rota_da_fila_resolve_o_componente_da_pagina_187_04` faz exatamente esse GET e exige **200**,
 * com a isca de um componente inexistente provando que a sonda não está cega.
 *
 * ═══ ⚠️⚠️ A DIVERGÊNCIA DE ROTA, DECLARADA E MEDIDA ════════════════════════════════════════════
 *
 * `ABA-06` e o `design_handoff_publicador/README-v2.md` dizem `…/publicacoes` (lista) e
 * `…/publicacoes/aprovacao` (fila). **A rota real da lista neste sistema é
 * `/mlb/anuncios/meus/{company}`** (`routes/mlb_anuncios.php:445`, `name('meus')`), e o README
 * chama as dele de *"Rotas sugeridas"*. Renomear a rota da lista quebraria:
 *   (a) o critério 4 desta fase (*"a lista continua como está"*);
 *   (b) **TERR-08** (URL e filtro salvo continuam funcionando);
 *   (c) **22 testes** de `tests/Feature/Phase134/MeusAnunciosTest.php`, que usam `mlb.anuncios.meus`.
 * ➡️ **A fila pendura na rota que EXISTE**, com o nome `mlb.anuncios.meus.aprovacao`. A revisão
 * detalhada (`…/aprovacao/{oferta}/{aba}`) é da **Fase 190/191** e **não nasce aqui**.
 *
 * ═══ ⚠️ A ABA DO CABEÇALHO GLOBAL CONTINUA `publicacoes`, E ISSO É DECISÃO ══════════════════════
 *
 * `CabecalhoPublicadorService::ABAS` é lista FECHADA de **cinco**
 * (`visao-geral`, `produtos`, `publicacoes`, `alavancas`, `configuracoes`) e **não ganha um sexto
 * valor aqui**. A fila é uma aba **INTERNA** da tela de Publicações (as duas abas do protótipo
 * vivem dentro do mesmo header — `design_handoff_publicador/Publicacoes v2.dc.html:34`-`:37`), não
 * uma entrada nova do menu global. Mandar `'aprovacao'` faria o header global perder o destaque de
 * Publicações e exigiria alterar um serviço que este plano **não possui**.
 *
 * ═══ ⚠️ CUSTO: A TELA NUNCA DEGRADA ═══════════════════════════════════════════════════════════
 *
 * Decisão do usuário de 15/10/2026: a tarifa do ML é **pré-aquecida por job** (plano 187-10).
 * "Limitar N consultas por abertura" foi **oferecido e RECUSADO**, porque incompleto **bloqueia a
 * aprovação** — limitar protegeria o servidor travando o trabalho. ➡️ **Zero cap por request, zero
 * "sem tarifa", zero "incompleto por timeout".** Cache frio continua produzindo o número CERTO, só
 * mais devagar.
 *
 * ⚠️ **E este payload não chama `BloqueiosDaOfertaService::para()`**, então **nenhuma** requisição
 * ao Mercado Livre sai desta action — há gate provando ZERO chamada, com isca. Quando o payload
 * por oferta chegar (planos 187-05 e seguintes), o gate de "1 requisição por trio distinto" nasce
 * **com ele**; escrevê-lo agora seria gate sobre ZERO casos.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class MlbPublicadorAprovacaoController extends Controller
{
    /**
     * A etapa "sem filtro" — não é estado, e por isso não está em `ANDAMENTOS`.
     *
     * ⚠️ Plano **187-04**. O valor é o MESMO de `FAIXA_DE_ANDAMENTO[0].chave` em
     * `andamentoDoPortao.jsx`, e há gate comparando os dois lados.
     */
    public const ETAPA_TODAS = 'todas';

    /**
     * A etapa filtrada, SANEADA contra a lista FECHADA das 9 chaves da faixa (T-187-41).
     *
     * ⚠️⚠️ **A LISTA É LIDA de `PubAprovacaoOferta::ANDAMENTOS`, nunca escrita à mão** — é a mesma
     * fonte de `AndamentoDaOfertaService::faixaDeAndamento()`, então as duas pontas não podem
     * divergir. Escrever as 8 chaves aqui criaria uma segunda verdade, livre para ficar velha no
     * dia em que um 9º estado entrar.
     *
     * ⚠️⚠️ **`is_string` ANTES de `trim`, e NUNCA `(string) $valor`:** `?etapa[]=x` entrega um
     * ARRAY, e `(string) []` lança `Array to string conversion` — foi 500 nesta tela antes
     * (T-180-04-T). Qualquer coisa que não seja string de uma das 9 chaves cai no default.
     *
     * ⚠️ E o valor **nunca** é interpolado em SQL: ele só é comparado contra a lista fechada, e
     * quem consulta é o Eloquent com bind.
     */
    private function etapaSaneada(Request $request): string
    {
        $cru = $request->query('etapa');

        if (! is_string($cru)) {
            return self::ETAPA_TODAS;
        }

        $chave = trim($cru);

        $permitidas = array_merge([self::ETAPA_TODAS], array_keys(PubAprovacaoOferta::ANDAMENTOS));

        return in_array($chave, $permitidas, true) ? $chave : self::ETAPA_TODAS;
    }

    /**
     * **COL-03 / T-187-96** — a seleção do lote, lida da URL (`?sel=1,2,3`) e **SANEADA contra as
     * ofertas da conta**.
     *
     * ⚠️⚠️ **ID DE OUTRA CONTA É DESCARTADO, NUNCA APROVADO.** A querystring vem do navegador, e
     * um id de outra empresa na lista seria aprovar oferta alheia — vazamento **e** escrita
     * indevida, num fluxo que **não tem desfazer**. O saneamento é por `whereIn` contra
     * `FilaDeAprovacaoService::paraAConta()`, que é a MESMA porta que a tela usa para listar.
     *
     * ⚠️ **`is_string` ANTES de qualquer coisa:** `?sel[]=1` entrega ARRAY, e `(string) []` lança
     * `Array to string conversion` — já foi 500 nesta tela (T-180-04-T). ⚠️ E `ctype_digit` em vez
     * de `(int)`: `(int) '3abc'` é `3` em silêncio, e `(int) ''` é `0`.
     *
     * @return list<int> os ids pedidos que EXISTEM na conta, na ordem da fila
     */
    private function selecaoSaneada(Request $request, FilaDeAprovacaoService $fila, int $companyId, ?int $mlbEmpresaId): array
    {
        $cru = $request->query('sel');

        if (! is_string($cru) || trim($cru) === '') {
            return [];
        }

        $pedidos = [];

        foreach (explode(',', $cru) as $pedaco) {
            $limpo = trim($pedaco);

            // ⚠️ Flag DENTRO do laço, nunca calculada fora.
            if ($limpo !== '' && ctype_digit($limpo) && (int) $limpo > 0) {
                $pedidos[] = (int) $limpo;
            }
        }

        $pedidos = array_values(array_unique($pedidos));

        if ($pedidos === []) {
            return [];
        }

        // ⚠️ `pluck` no Builder DA CONTA: o que não é da conta simplesmente não volta. Nenhum
        // `in_array` sobre lista montada no PHP — a trava é a consulta.
        return $fila->paraAConta($companyId, $mlbEmpresaId)
            ->whereIn('id', $pedidos)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * A fila da conta: o contador da aba, a faixa de 9 recortes e a margem mínima com origem.
     *
     * ⚠️ `role:admin` vem do GRUPO de `routes/mlb_anuncios.php` — **nenhum middleware novo**
     * (T-187-12).
     */
    public function index(
        Request $request,
        Company $company,
        FilaDeAprovacaoService $fila,
        ProximoARevisarService $proximo,
        FiltrosDaFilaService $filtros,
        GrupoDaFilaService $gruposDaFila,
        LinhaDaOfertaService $linhaDaOferta,
        LoteDaFilaService $loteDaFila,
        ProgramasPublicadorService $programas,
        CabecalhoPublicadorService $cabecalhos,
    ) {
        // ⚠️ A MESMA trava de todas as outras actions do módulo, byte a byte (T-187-11). Empresa
        // de outra conta na URL é tentativa de ler fila alheia, e empresa sem conta ML conectada
        // não tem fila nenhuma para mostrar.
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');

        // O mesmo resolvedor que `meus()` usa — e é daqui que sai a SEGUNDA âncora de conta.
        $alvo = $programas->resolver('company-' . $company->id);
        $conta = $alvo !== null ? $programas->empresaParaTela($alvo) : null;

        // ⚠️ A fila é recortada pelas DUAS âncoras quando as duas existem. `mlb_empresa` é nula
        // para empresa de Gestão sem `MlbEmpresa` ligada (ver `ProgramasPublicadorService::
        // resolver()`), e aí o recorte é só `company_id` — nunca "sem recorte".
        $mlbEmpresaId = $alvo['mlb_empresa']?->id;

        // ═══ ⚠️⚠️ Plano 187-10 — O PRÉ-AQUECIMENTO DA TARIFA, FORA DO REQUEST ═════════════════
        //
        // ⚠️ Ele **não ajuda ESTE request**, e isso é sabido: a página já está calculando
        // `ComposicaoFinanceiraService::para()` por oferta no mesmo instante. Ele aquece a
        // **PRÓXIMA** abertura dentro da hora, e existe para quem chega por **URL direta** ou
        // **recarrega** — quem vem pelo caminho normal já foi aquecido por `meus()`, o passo
        // imediatamente anterior a clicar na aba.
        //
        // ⚠️⚠️ **NUNCA pode derrubar esta tela.** Todo o corpo de `despacharParaAConta()` é
        // `try/catch (\Throwable)` + `Log::warning`; há teste com a fila de jobs INDISPONÍVEL
        // exigindo **200** aqui e em Publicações. O retorno é descartado de propósito.
        //
        // ⚠️ Fila `high` com trava curta (ver o docblock do job): a `default` de produção é Redis
        // e já ficou congestionada — 179 jobs de acervo seguraram um webhook por horas em
        // 16/09/2026.
        //
        // ⚠️ E ele **muda QUANDO a requisição acontece, não QUANTAS**: o gate de custo dos planos
        // 187-06 e 187-08 continua valendo e **não** foi afrouxado. Nenhum cap por request,
        // nenhum "sem tarifa", nenhum "incompleto" — cache frio dá o número CERTO, só mais
        // devagar (decisão do usuário de 15/10/2026).
        \App\Jobs\AquecerTarifasDaFilaJob::despacharParaAConta($company->id, $mlbEmpresaId);

        // ═══ ⚠️⚠️ OS TRÊS RECORTES DA FASE 187, PLANO 06 — E ELES SÃO DIFERENTES DE PROPÓSITO ══
        //
        // `$daConta` = TODAS as ofertas da conta, **zero filtro** (o `all` do protótipo, `:1208`).
        //   É sobre ele que as contagens dos DOIS POPOVERS são feitas (`:1265`/`:1266`) e é dele
        //   que sai o **M** do "N de M" (divergência D8: o M inclui `preparando` e `publicado`).
        // `$base`    = `$daConta` + todos os filtros **MENOS a etapa** (`:1210`). É a base da faixa
        //   de 9 (FILA-03) e do "PRÓXIMO A REVISAR".
        // `lista`    = `$base` + a etapa (`:1214`), e ela é dos planos 187-07/08.
        //
        // ⚠️ Colapsar dois destes recortes é o bug que faz o card ativo mostrar o próprio total e
        // os outros oito zerarem — e o popover deixar de dizer quanto EXISTE.
        $daConta = $fila->paraAConta($company->id, $mlbEmpresaId);

        // ⚠️ A lista FECHADA do filtro de Responsável são os ids que a conta de fato tem: id de
        // outra conta na URL é descartado em silêncio, e NÃO zera a lista.
        $responsaveis = $filtros->responsaveisDaConta($daConta);
        $idsDeResponsavel = array_map(static fn (array $r): int => $r['id'], $responsaveis);

        // ⚠️⚠️ **UMA chamada a `BloqueiosDaOfertaService::para()` por oferta, e UM mapa só.** O
        // filtro por motivo e as 8 contagens leem o MESMO mapa — por isso o custo é mensurável, e
        // é por isso que o gate de custo deste plano conta requisições por trio distinto. O
        // pré-aquecimento fora do request é o plano 187-10 (decisão do usuário de 15/10/2026); ele
        // muda QUANDO a requisição acontece, não QUANTAS.
        $panorama = $filtros->panoramaDaConta($daConta);

        $escolhidos = $filtros->saneados($request, $idsDeResponsavel);
        $etapa = $this->etapaSaneada($request);

        $base = $filtros->aplicar($fila->paraAConta($company->id, $mlbEmpresaId), $escolhidos, $panorama);

        // ═══ ⚠️⚠️ Plano 187-08 — A OFERTA PRÓXIMA E A EM FOCO, RESOLVIDAS UMA VEZ ════════════
        //
        // ⚠️ `$proximo->para($base)` saiu de dentro do array do payload e virou VARIÁVEL — e isso
        // é deliberado e CONFERIDO: a linha da oferta precisa do **id** da próxima para pintar
        // `Revisar` de amarelo **só nela** (`isNext`, `:1240`), e chamá-lo duas vezes faria **duas**
        // leituras de `BloqueiosDaOfertaService::para()` (uma requisição extra ao Mercado Livre) e
        // abriria a chance de a faixa e a linha apontarem para ofertas DIFERENTES.
        //
        // ⚠️⚠️ **A `faixa` E O `proximo_a_revisar` CONTINUAM INLINE no payload, e isso é MEDIDO.**
        // `tests/js/publicador-fila-proximo-187.test.js` (wave 5) lê os DOIS textos no controller:
        // `'faixa' => $fila->faixa($base)` (FILA-03) e
        // `'proximo_a_revisar' => $proximo->para($base)`. Guardar qualquer um dos dois numa
        // variável **reprova aquele gate** — medido nesta sessão, em 16/10/2026, quando a primeira
        // redação desta wave hoisteou o segundo para pegar o id da próxima oferta.
        // ➡️ A saída é a MESMA que o `contagem.exibidas` já usava: ler do `$payload` **DEPOIS** de
        // montá-lo. Ver o bloco do `$payload['grupos']` no fim da action.

        // ⚠️ `?oferta=` dá FOCO a uma linha (plano 187-05, COL-03) — é o `focusId` do protótipo
        // (`:1250`). ⚠️ **`is_numeric` ANTES de `(int)`, e NUNCA `(int) $request->query()` cru:**
        // `?oferta[]=x` entrega um ARRAY e `(int) []` emite `Array to string conversion`, que já
        // foi **500** nesta tela (T-180-04-T). Valor que não é inteiro positivo cai em `null`, e
        // aí **nenhuma** linha recebe foco — nunca a linha errada.
        $ofertaCrua = $request->query('oferta');
        $ofertaEmFoco = is_string($ofertaCrua) && ctype_digit($ofertaCrua) && (int) $ofertaCrua > 0
            ? (int) $ofertaCrua
            : null;

        // ⚠️⚠️ **O PAYLOAD É MONTADO EM VARIÁVEL, e a `faixa` fica INLINE — as duas coisas de
        // propósito.**
        //
        // A `contagem.exibidas` precisa LER a faixa já calculada (o N do "N de M" é lido dela,
        // nunca recontado — ver o docblock de `FiltrosDaFilaService::exibidasNaEtapa`), e
        // recalculá-la custaria **nove** `count()` a mais por request.
        //
        // ⚠️ E a chamada fica ESCRITA como `'faixa' => $fila->faixa($base)` em vez de guardada
        // numa variável acima, porque é esse o texto que o CONTROLE de
        // `tests/js/publicador-fila-proximo-187.test.js` (wave 5, arquivo que esta wave **não
        // pode tocar**) lê para provar o invariante de FILA-03: *a faixa de 9 é calculada sobre a
        // `$base`*. Guardá-la numa variável reprovava aquele gate — medido em 16/10/2026 — e o
        // conserto certo era mudar ESTE arquivo, não o gate alheio.
        // ➡️ **Não "arrume" isto para uma variável sem antes conferir aquele controle.**
        $payload = [
            'empresa' => ['id' => $company->id, 'nome' => $company->name],
            'conta' => $conta,
            // ⚠️ `'publicacoes'` de propósito — ver o docblock da classe.
            'cabecalho' => $alvo !== null ? $cabecalhos->para($alvo, $conta, 'publicacoes') : null,
            // ⚠️ O contador vem do SERVIDOR, e é `PubAprovacaoOferta::LIVE` lido do modelo.
            'contador_da_aba' => $fila->contadorDaAba($company->id, $mlbEmpresaId),
            // ⚠️⚠️ As 9 entradas, na ordem literal de `ST`, com "Publicado hoje" como recorte DO
            // DIA — e contadas sobre a `$base`, que tem **todos os filtros MENOS a etapa**
            // (FILA-03). Medido no protótipo: `cnt` é calculado sobre `base` (`:1213`) e `list` é
            // `base.filter(stage)` (`:1214`).
            //
            // ⚠️ Contar sobre o recorte JÁ FILTRADO por etapa faria o card ativo mostrar o próprio
            // total e **todos os outros oito zerarem** — a pessoa perderia a navegação. É o
            // sintoma clássico, e por isso o gate deste plano prova as DUAS direções: a etapa NÃO
            // move a faixa, e um filtro da `$base` (a conta) MOVE.
            'faixa' => $fila->faixa($base),
            // ⚠️ Plano 187-04 — a etapa SANEADA contra a lista fechada das 9 chaves. É ela que
            // marca o card ativo; o filtro vive na URL (COL-03), nunca em `localStorage`.
            // ⚠️ A LISTA da fila ainda não existe nesta tela (ela chega nos planos 187-07/08), e
            // é por isso que a `etapa` ainda não recorta consulta nenhuma: ela só diz à faixa qual
            // card está aceso. Aplicá-la a uma lista que não existe seria código sem consumidor.
            'etapa' => $etapa,
            // ═══ ⚠️ Plano 187-06 — OS FILTROS, e todos eles VIVEM NA URL (COL-03) ═════════════
            //
            // ⚠️ **NADA de `localStorage`** (COL-02 / critério 13): o `KEY='ecf-aprovacao-v1'` do
            // protótipo (`:914`) é artefato dele. Filtro que mora no navegador não sobrevive a um
            // link compartilhado — a pessoa manda o link e o colega abre outra fila.
            //
            // ⚠️ Os cinco voltam JÁ SANEADOS, e é por isso que recarregar devolve o mesmo
            // recorte: a URL é a fonte, e o servidor é quem a valida contra as listas fechadas.
            //
            // ⚠️⚠️ O NOME É `filtros_ativos`, e **não** `filtros`, de propósito: `filtros` é o
            // SLOT de NÓ do plano 187-04 (quem compõe um nó manda o nó). Uma prop com DOIS
            // sentidos — nó de composição × dado — foi achada como **defeito grave** na
            // verificação da Fase 186, e o plano 187-05 já resolveu o caso gêmeo com o par
            // `proximo` (nó) × `proximo_a_revisar` (dado). Aqui o par é
            // `filtros` (nó) × `filtros_ativos` (dado).
            'filtros_ativos' => $escolhidos,
            // ⚠️⚠️ As OPÇÕES vêm do servidor porque três das quatro listas são DADO: os
            // responsáveis saem do banco (com nome e contagem) e os motivos saem de
            // `VocabularioDoPortao::ROTULOS`. As duas de modalidade leem `EstruturaAnuncio::TIPOS`
            // — "Clássico" e "Premium" têm UMA casa, e esta não é ela.
            //
            // ⚠️ As CONTAGENS de `responsaveis` e `motivos` são sobre **TODAS** as ofertas da
            // conta (FILA-06), e NÃO sobre o recorte filtrado: o popover diz quanto EXISTE, não
            // quanto sobrou. Recontá-las sobre a `$base` faria a pessoa marcar um motivo e ver a
            // própria contagem dele mudar — o sintoma clássico.
            'opcoes' => [
                'modalidades' => $filtros->opcoesDeModalidade(),
                'formatos' => $filtros->opcoesDeFormato(),
                'responsaveis' => $responsaveis,
                'motivos' => $filtros->motivosDaConta($panorama),
            ],
            // ⚠️ "Limpar filtros" só aparece com algum dos SEIS ativo — e a **etapa** é um deles
            // (`anyFilter`, `:1270`, inclui `S.stage !== 'all'`). ⚠️ O `?oferta=<id>` do plano
            // 187-05 **NÃO** entra: ele dá foco a uma linha, e se contasse, abrir uma oferta faria
            // "Limpar filtros" aparecer sem filtro nenhum.
            'algum_filtro' => $filtros->algumFiltro($escolhidos, $etapa !== self::ETAPA_TODAS),
            // ⚠️⚠️ O "N de M ofertas" (divergência **D8**): o **M** é o total de TODAS as ofertas
            // da conta — inclusive `preparando` e `publicado` —, não o das ao vivo (`:1276`:
            // `list.length + ' de ' + all.length`). **Reproduzido, não "consertado"**: o total da
            // fila tornaria o número incoerente com o card "Todas" da faixa.
            //
            // ⚠️ E o **N** é **LIDO da faixa**, nunca recontado: na etapa `publicado` o recorte é
            // "publicado HOJE" (`whereDate('updated_at', hoje)`), e uma segunda consulta aqui
            // poderia divergir do card. Conta e lista TÊM de bater (decisões 9 e 16 do usuário).
            // ⚠️ `exibidas` é preenchida ABAIXO, lendo `$payload['faixa']` — a MESMA array que a
            // tela recebe, calculada UMA vez. É assim que conta e lista não podem divergir.
            'contagem' => ['exibidas' => null, 'total' => $filtros->totalDaConta($daConta)],
            // ⚠️ FIN-09 — `{valor, origem}`. O número NUNCA é literal: cada número diz de onde vem.
            'margem_minima' => $fila->margemMinima($company->id),
            // ⚠️⚠️ Plano 187-05 — a faixa "PRÓXIMO A REVISAR", escolhida pela regra literal de
            // TRÊS DEGRAUS do protótipo (`Aprovacao.dc.html:1220`). `null` quando não há oferta
            // ao vivo, e aí a faixa INTEIRA não aparece — nunca uma casca amarela vazia.
            //
            // ⚠️ Ela é calculada sobre a `$base` (todos os filtros MENOS a etapa), igual à faixa
            // de 9: a "próxima a revisar" não muda porque alguém filtrou um recorte. Filtrar por
            // "Aprovado" não pode apagar a próxima da fila.
            //
            // ⚠️⚠️ **É AQUI QUE ESTA ACTION PASSA A PODER CHAMAR O MERCADO LIVRE**, e é
            // decisão do usuário de 15/10/2026: `BloqueiosDaOfertaService::para()` lê a
            // composição, que consulta `/sites/MLB/listing_prices` (cache de 1 h por
            // categoria+tipo+preço, pré-aquecido pelo job do plano 187-10). "Limitar N consultas
            // por abertura" foi **oferecido e RECUSADO** — limitar protegeria o servidor travando
            // o trabalho. ➡️ `para()` é chamado **UMA** vez, só para a oferta escolhida, e há
            // teste com espião contando as chamadas numa fila de 6 ao vivo (T-187-51).
            'proximo_a_revisar' => $proximo->para($base),
            // ═══ ⚠️⚠️ Plano 187-07 — A TABELA: OS TRÊS NÍVEIS DA LISTA ═══════════════════════
            //
            // ⚠️ A `lista` é o TERCEIRO recorte (`:1214`: `list = base.filter(stage)`), e ele é
            // DIFERENTE dos outros dois de propósito — ver o bloco dos três recortes acima.
            // `recortarPelaEtapa()` mora num lugar só e há gate comparando o `count()` dele com o
            // total do card correspondente **nas 9 chaves**: na etapa `publicado` o recorte é
            // "publicado HOJE", e uma segunda regra escrita de cabeça mostraria na tabela
            // publicações de ontem com o card dizendo outro número. ⚠️ **Conta e lista TÊM de
            // bater** (decisões 9 e 16 do usuário, que recusou número divergente DUAS vezes).
            //
            // ⚠️ `with()` nas três relações que a árvore usa, e `produto.rascunho` NÃO entra: o
            // `rascunho_id` já está na própria oferta, e o `GrupoDaFilaService` lê categoria e
            // ficha técnica por consulta AGREGADA (uma para a árvore inteira), nunca por produto.
            //
            // ⚠️⚠️ **ZERO chamada ao Mercado Livre sai daqui.** `GrupoDaFilaService::montar()` não
            // chama `BloqueiosDaOfertaService::para()` — há gate com espião contando ZERO, com
            // isca provando que o espião conta. As 11 células da linha (que PRECISAM da
            // composição) são o plano **187-08**, e é lá que o slot `linhaDaOferta` é preenchido.
        ];

        // ⚠️⚠️ O **N** do "N de M" é LIDO da faixa que acabou de ser calculada — a MESMA array
        // que a tela recebe. Recontar aqui produziria uma segunda consulta livre para divergir, e
        // é justamente na etapa `publicado` que ela divergiria: ali o recorte é "publicado HOJE"
        // (`whereDate('updated_at', hoje)`, `AndamentoDaOfertaService:294`-`:295`). O usuário
        // recusou número divergente entre conta e lista **duas vezes** (decisões 9 e 16).
        $payload['contagem']['exibidas'] = $filtros->exibidasNaEtapa($payload['faixa'], $etapa);

        // ═══ ⚠️⚠️ Plano 187-09 — A SELEÇÃO EM LOTE, LIDA DA URL ═══════════════════════════════
        //
        // ⚠️ **A seleção vive na URL** (COL-03, critério 13): `?sel=1,2,3`. É isso que a faz
        // sobreviver a recarregar, a voltar do painel e a compartilhar o link — e é a razão de
        // **não** haver `localStorage` nenhum nesta tela (o do protótipo, `KEY='ecf-aprovacao-v1'`
        // em `:914`, é recurso de protótipo e **não existe no produto**).
        //
        // ⚠️⚠️ **O VEREDICTO É DO SERVIDOR, e por isso ele está aqui e não no `.jsx`.** Quem
        // decide se uma oferta pode ser aprovada é `BloqueiosDaOfertaService::para()`, com a
        // composição financeira e a tarifa do ML. A tela **lê** o veredicto; ela não o calcula —
        // calcular no cliente daria duas verdades, e a divergente seria descoberta por alguém
        // clicando "Aprovar 3" e recebendo 1.
        //
        // ⚠️ **O CUSTO é recortado à SELEÇÃO, nunca à fila.** `painel()` chama
        // `BloqueiosDaOfertaService::para()` **uma vez por oferta SELECIONADA** — e só para as que
        // estão ao vivo. Sem seleção (o caso comum), `lote` é `null` e **nenhuma** chamada extra
        // acontece. Varrer a fila inteira para "já deixar pronto" seria a classe do incidente de
        // 30/07/2026, quando requisições lentas ocuparam os workers do php-fpm.
        $selecionados = $this->selecaoSaneada($request, $fila, $company->id, $mlbEmpresaId);

        $payload['selecionados'] = $selecionados;
        $payload['lote'] = $selecionados === []
            ? null
            : $loteDaFila->painel(
                $fila->ordenar(
                    PubAprovacaoOferta::query()
                        ->whereIn('id', $selecionados)
                        ->with(['alvo', 'produto', 'responsavel'])
                        ->get()
                )
            );

        // ═══ ⚠️⚠️ Plano 187-08 — A LISTA, COM AS 11 CÉLULAS DE CADA LINHA ════
        //
        // ⚠️⚠️ **MONTADA AQUI, DEPOIS DO PAYLOAD, e pela MESMA razão do `exibidas` acima:** a
        // linha precisa do **id da oferta próxima** para pintar `Revisar` de amarelo **só nela**
        // (`isNext`, `:1240`), e esse id tem de ser o da **MESMA array** que a faixa "PRÓXIMO A
        // REVISAR" recebeu. Recalculá-lo chamaria `ProximoARevisarService::para()` **duas vezes**
        // (uma leitura extra de `BloqueiosDaOfertaService::para()`, logo uma requisição extra ao
        // Mercado Livre) e abriria a chance de a faixa e a linha apontarem para ofertas
        // DIFERENTES — o usuário recusou número divergente entre conta e lista DUAS vezes.
        // ⚠️ E é isto que mantém `'proximo_a_revisar' => $proximo->para($base)` INLINE, que é o
        //    texto que o gate da wave 5 lê.
        $idDoProximo = is_array($payload['proximo_a_revisar'] ?? null) && isset($payload['proximo_a_revisar']['id'])
            ? (int) $payload['proximo_a_revisar']['id']
            : null;

        $payload['grupos'] = $gruposDaFila->montar(
                $fila->ordenar(
                    $gruposDaFila->recortarPelaEtapa(
                        $filtros->aplicar($fila->paraAConta($company->id, $mlbEmpresaId), $escolhidos, $panorama),
                        $etapa,
                    )
                        // ⚠️ Plano 187-08 — `aprovadoPor` entra porque a sub-linha de `aprovado` diz
                        // "aguardando publicação · <quem>" (`:1234`). Sem o eager loading, uma fila
                        // de 30 linhas faria 30 consultas só para aquela frase.
                        ->with(['alvo', 'produto', 'responsavel', 'aprovadoPor'])
                        ->get()
                ),
                // ═══ ⚠️⚠️ Plano 187-08 — O SLOT DA LINHA, PREENCHIDO ═══════════════════════════
                //
                // ⚠️ O slot é um PARÂMETRO de `montar()` e nasceu no plano **187-07** de propósito:
                // `GrupoDaFilaService.php` **não está** nos `files_modified` desta wave, então sem
                // o slot esta wave teria de abrir uma SEGUNDA varredura da mesma árvore. É a forma
                // literal do protótipo, que também injeta a linha (`rows: rows.map(rowVm)`, `:1262`).
                //
                // ⚠️⚠️ **`para()` é chamado UMA vez por oferta**, e é aqui que isso acontece: uma
                // chamada por linha da árvore, nunca uma por célula. Ele lê a composição, que
                // consulta `/sites/MLB/listing_prices` (cache de 1 h por categoria+tipo+preço) — e
                // há gate com espião contando as chamadas e as requisições por trio distinto.
                // ⚠️ O pré-aquecimento do plano **187-10** muda QUANDO a requisição acontece, não
                // QUANTAS: aquele plano não afrouxa este gate.
                fn (PubAprovacaoOferta $oferta): array => $linhaDaOferta->para(
                    $oferta,
                    // ⚠️ `Revisar` AMARELO **só** na oferta próxima (`isNext`, `:1240`): pintar
                    // todas de amarelo apagaria o sinal de "comece por esta" (T-187-85). O id vem
                    // da MESMA array que a faixa "PRÓXIMO A REVISAR" recebeu — nunca recalculado,
                    // senão a faixa e a linha poderiam apontar para ofertas diferentes.
                    $idDoProximo !== null && (int) $oferta->id === $idDoProximo,
                    [
                        // ⚠️ `?oferta=` dá FOCO a uma linha (COL-03, plano 187-05). Ele é o
                        // `focusId` do protótipo (`:1250`), e NÃO conta como filtro.
                        'foco' => $ofertaEmFoco !== null && (int) $oferta->id === $ofertaEmFoco,
                        // ⚠️⚠️ **Plano 187-09 LIGA `marcada`.** Ela é a seleção em lote, e vem da
                        // URL já SANEADA contra as ofertas da conta (`selecaoSaneada()`): id de
                        // outra empresa na querystring não acende linha nenhuma e não entra no
                        // lote. Sem o saneamento, a tela pintaria como selecionada uma oferta que
                        // o POST depois recusaria — duas verdades sobre a mesma seleção.
                        'marcada' => in_array((int) $oferta->id, $selecionados, true),
                        // ⚠️ `aberta` é o painel de revisão (**Fase 188**) e continua **nomeada e
                        // ausente**, nunca `true` por acidente: o serviço tem o ramo, conferido
                        // por prop direta, e é aquela fase que o liga.
                    ],
                ),
            );


        return Inertia::render('Mlb/Publicador/Aprovacao', $payload);
    }

    /**
     * **BLOQ-10 / FILA-15** — APROVAR EM LOTE. A **PRIMEIRA ESCRITA** da fila.
     *
     * ⚠️⚠️ **APROVAR JÁ PUBLICA, SEM SEGUNDO CLIQUE** (decisão 3 do usuário, 14/10/2026): não há
     * "confirmar publicação", não há "desfazer", não há segundo passo e não há cancelamento de
     * aprovação. A conferência acontece **antes**, no modal, que lista TODAS as selecionadas com
     * ✓/× e o **motivo** de cada exclusão. Por isso esta action **não** tem etapa intermediária:
     * ela recebe os ids, confere, aprova as aptas e volta com o resumo.
     *
     * ⚠️⚠️ **E NESTA FASE APROVAR ENFILEIRA.** `aprovarEmLote()` move o andamento, grava os três
     * campos de aprovação, marca `tocado` e congela a composição — **tudo no nosso banco**. O
     * gancho `aoAprovar`, que fala com o Mercado Livre, está **VAZIO** e é da **Fase 193**. Há
     * caso provando por **ausência de requisição HTTP**, não por leitura de código.
     *
     * ═══ O GATE DE ESCRITA PRÓPRIO DA ÁREA NOVA (T-187-91) ═════════════════════════════════════
     *   · **`role:admin`** — do grupo da rota, **sem middleware novo**;
     *   · **trava de conta** — `abort_unless($company->mlToken !== null, 404)`, byte a byte a das
     *     outras actions do módulo;
     *   · **as ofertas conferidas contra `FilaDeAprovacaoService::paraAConta()` ANTES de qualquer
     *     gravação** — id de outra conta no corpo do POST **não é aprovado** e nada dele é
     *     gravado (T-187-96);
     *   · **transação** — é a de `aprovar()`, uma por oferta, e **não se abre outra por fora**:
     *     uma que falhe não desfaz as já aprovadas, porque elas já foram enfileiradas;
     *   · **CSRF** — rota POST normal do módulo, sem exceção;
     *   · **throttle nomeado** — `throttle:30,1,publicador.aprovacao.lote`.
     *
     * ⚠️ `validate()` com `integer` e `min:1`: `"3abc"` é recusado com 422, nunca convertido em
     * `3` em silêncio. ⚠️ E `ofertas` vazio é recusado pelo `min:1` do array — "aprovei 0" não é
     * um pedido legítimo, é formulário quebrado.
     */
    public function aprovarLote(
        Request $request,
        Company $company,
        FilaDeAprovacaoService $fila,
        LoteDaFilaService $loteDaFila,
        ProgramasPublicadorService $programas,
    ) {
        // ⚠️ A MESMA trava de conta das outras actions, com a MESMA mensagem.
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');

        $dados = $request->validate([
            'ofertas' => ['required', 'array', 'min:1'],
            'ofertas.*' => ['required', 'integer', 'min:1'],
            // ⚠️⚠️ **A VERSÃO QUE A PESSOA VIU NO MODAL** (COL-04). Ela é o que torna
            // "aprovada na versão indicada" verdade: se alguém mexeu na oferta entre o
            // veredicto e o clique, ELA é recusada e as outras passam. ⚠️ `nullable` de
            // propósito — aprovação sem afirmação de versão (o botão da linha, Fase 188) não
            // tem o que divergir, e exigir o mapa quebraria aquele caminho.
            'versoes' => ['sometimes', 'array'],
            'versoes.*' => ['integer', 'min:1'],
        ]);

        $alvo = $programas->resolver('company-' . $company->id);
        $mlbEmpresaId = $alvo['mlb_empresa']?->id;

        $pedidos = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $dados['ofertas'])));

        // ⚠️⚠️ **A CONFERÊNCIA DE CONTA, ANTES DE QUALQUER GRAVAÇÃO.** O filtro é a consulta: o
        // que não é da conta **não volta** do `paraAConta()`, então não existe caminho em que uma
        // oferta alheia chegue a `aprovarEmLote()`. ⚠️ Nenhum `in_array` sobre lista montada no
        // PHP — é a consulta que trava.
        $ofertas = $fila->ordenar(
            $fila->paraAConta($company->id, $mlbEmpresaId)
                ->whereIn('id', $pedidos)
                ->with(['alvo', 'produto', 'responsavel'])
                ->get()
        );

        // ⚠️ O mapa `oferta_id => versão` sai do corpo com as CHAVES normalizadas para `int`:
        // a querystring/JSON entrega chave string (`"12"`), e `array_key_exists(12, ['12' => 3])`
        // é `true` em PHP só porque o engine já converte chave numérica — mas não dá para contar
        // com isso quando a chave vem de JSON aninhado. Normalizar é explícito.
        $versoes = [];
        foreach (($dados['versoes'] ?? []) as $chave => $valor) {
            if (ctype_digit((string) $chave) && is_int($valor)) {
                $versoes[(int) $chave] = $valor;
            }
        }

        $resumo = $loteDaFila->aprovar($ofertas, $request->user(), $versoes);

        // ⚠️ A resposta diz quantas foram aprovadas, **contadas**, nunca presumidas do tamanho do
        // pedido: lote só com inaptas devolve **0**, jamais um sucesso falso.
        return back()->with('lote_aprovado', [
            'pedidas' => count($pedidos),
            'selecionadas' => $resumo['selecionadas'],
            'aprovadas' => $resumo['aprovadas'],
            'recusadas_por_versao' => $resumo['recusadas_por_versao'],
        ]);
    }
}
