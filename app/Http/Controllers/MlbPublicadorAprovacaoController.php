<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PubAprovacaoOferta;
use App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService;
use App\Services\Publicador\Aprovacao\Fila\FiltrosDaFilaService;
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

        // ⚠️ A faixa é calculada DEPOIS dos filtros e sobre a `$base`: ela se move com busca,
        // modalidade, formato, responsável e motivo — e **não** com a etapa.
        $faixa = $fila->faixa($base);

        return Inertia::render('Mlb/Publicador/Aprovacao', [
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
            'faixa' => $faixa,
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
            'contagem' => [
                'exibidas' => $filtros->exibidasNaEtapa($faixa, $etapa),
                'total' => $filtros->totalDaConta($daConta),
            ],
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
        ]);
    }
}
