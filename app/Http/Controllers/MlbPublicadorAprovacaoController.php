<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoFrete;
use App\Models\PubAprovacaoOferta;
use App\Services\Publicador\Aprovacao\AjustesDaOfertaService;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\AprovacaoDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService;
use App\Services\Publicador\Aprovacao\Fila\FiltrosDaFilaService;
use App\Services\Publicador\Aprovacao\Fila\GrupoDaFilaService;
use App\Services\Publicador\Aprovacao\Fila\LinhaDaOfertaService;
use App\Services\Publicador\Aprovacao\Fila\LoteDaFilaService;
use App\Services\Publicador\Aprovacao\Fila\ProximoARevisarService;
use App\Services\Publicador\Aprovacao\HistoricoDaOfertaService;
use App\Services\Publicador\Aprovacao\Painel\LightboxDoPainelService;
use App\Services\Publicador\Aprovacao\Painel\PainelDeRevisaoService;
use App\Services\Publicador\Aprovacao\Painel\PrecoDoPainelService;
use App\Services\Publicador\Aprovacao\PublicacaoDoPortaoService;
use App\Services\Publicador\Aprovacao\RevisaoDeImagemService;
use App\Services\Publicador\CabecalhoPublicadorService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\RegraViolada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        PainelDeRevisaoService $painelDeRevisao,
        // ⚠️ Fase 189, plano 02 — method injection, como o `$painelDeRevisao` logo acima: o
        // serviço do lightbox é da 189 e **não** entra no construtor do painel (que é da 188).
        LightboxDoPainelService $lightboxDoPainel,
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
        // ═══ ⚠️⚠️ Fase 188, plano 02 — O PAINEL DE REVISÃO, MONTADO NO SERVIDOR ══════════════
        //
        // ⚠️⚠️ **O VEREDICTO É DO SERVIDOR, e por isso o painel nasce aqui e não no `.jsx`.** Quem
        // decide se uma oferta pode ser aprovada é `BloqueiosDaOfertaService::para()`, com a
        // composição financeira e a tarifa real do Mercado Livre. A tela **LÊ** o veredicto; ela
        // não o calcula — calcular no cliente daria duas verdades, e a divergente seria descoberta
        // por alguém clicando "Aprovar para publicação" e sendo recusado, num fluxo em que
        // **aprovar JÁ PUBLICA e não há desfazer**.
        //
        // ═══ ⚠️ O CAMINHO ESCOLHIDO, E A ALTERNATIVA RECUSADA ════════════════════════════════
        //
        // O painel é montado **na mesma action**, recortado à oferta do `?oferta=`, e a navegação
        // ↑/↓ da tela é um **recarregamento parcial do Inertia**
        // (`router.get(…, { only: ['painel'], preserveState: true, preserveScroll: true })`, que é
        // tarefa do plano **188-07**).
        //
        // ⚠️ **A alternativa — um endpoint JSON próprio para o painel — foi CONSIDERADA e
        // RECUSADA**, por três razões medidas:
        //   (1) criaria uma **segunda verdade** sobre o view-model do painel, fora do payload que a
        //       página já recebe;
        //   (2) **COL-03 exige a oferta aberta na URL** de qualquer forma, então a ida ao servidor
        //       **não é evitável**;
        //   (3) ir ao servidor em cada ↑/↓ é **exatamente** o que resolve **COL-04**: a `versao`
        //       volta fresca, e a tela não pode sobrescrever em silêncio uma oferta que mudou
        //       debaixo de quem revisa.
        // ➡️ **Sem esta razão escrita, o executor seguinte "otimiza" para JSON e perde a
        // concorrência.** Está escrito para ser achado pelo grep, não por bisseção.
        //
        // ═══ ⚠️⚠️ O CUSTO É RECORTADO À OFERTA, NUNCA À FILA ═════════════════════════════════
        //
        // `PainelDeRevisaoService::para()` é chamado **UMA** vez, e **só** quando há `?oferta=`
        // válido. Sem `?oferta=`, `painel` é `null` e **nenhuma** chamada extra ao Mercado Livre
        // acontece — há gate com espião contando ZERO, com isca provando que o espião conta.
        // ⚠️ Varrer a fila "para já deixar pronto" é a classe do incidente de **30/07/2026**, quando
        // requisições lentas ocuparam os workers do php-fpm e **até o login parou**.
        //
        // ═══ ⚠️ A TRAVA DE CONTA, E POR QUE ELA É SILENCIOSA ════════════════════════════════
        //
        // ⚠️ `$ofertaEmFoco` é **REUSADO** (ver o bloco de `?oferta=` acima), com a guarda
        // `is_string && ctype_digit && > 0` — **nunca `(int) $request->query()` cru**: `?oferta[]=x`
        // entrega ARRAY e `(int) []` emite `Array to string conversion`, que já foi **500** nesta
        // tela (T-180-04-T).
        // ⚠️⚠️ **O recorte é `paraAConta()` + `where('id', …)`, e a MESMA porta que a tela usa para
        // listar.** Id de **outra conta** cai em `null` **em silêncio** — e **nunca** 403, que
        // confirmaria a existência da oferta alheia (T-188-10). Nenhum `in_array` sobre lista
        // montada no PHP: a trava é a **consulta**.
        //
        // ⚠️ E a fronteira do critério 18 continua: o painel **APROVA** (e aprovar **ENFILEIRA**),
        // **NÃO PUBLICA**. A escrita no Mercado Livre é da **Fase 193**.
        $ofertaDoPainel = $ofertaEmFoco === null
            ? null
            : $fila->paraAConta($company->id, $mlbEmpresaId)
                ->where('id', $ofertaEmFoco)
                // ⚠️ As três relações que a identificação usa, por eager loading: sem elas o
                // view-model faria quatro consultas só para produto, título e responsável.
                ->with(['alvo', 'produto', 'responsavel', 'aprovadoPor'])
                ->first();

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
            //
            // ═══ ⚠️⚠️ Fase 188, plano 02 — A PROP `painel` ═════════════════════════════════
            //
            // ⚠️ Ela recebe a **`$base`** — a conta mais os filtros, **menos a etapa** —, que é o
            // MESMO recorte da faixa de 9 e do "PRÓXIMO A REVISAR". ➡️ É isso que impede o ↑/↓ de
            // pular para fora do recorte em que a pessoa está, e é por isso que o serviço recebe o
            // **Builder** em vez de remontá-lo (remontar abriria uma segunda consulta livre para
            // divergir da que a tela já viu).
            //
            // ⚠️ E ela fica **INLINE no literal**, como a `faixa` e o `proximo_a_revisar`: é a
            // forma que o `key_links` do plano declara (`'painel' =>`), e guardá-la numa
            // atribuição depois do array esconderia a prop de quem lê o payload de cima a baixo.
            'painel' => $ofertaDoPainel === null
                ? null
                // ⚠️ O **TERCEIRO** argumento (plano 188-06) são os autorizadores do modal de
                // exceção, e ele é a MESMA array que o filtro de Responsável da fila já usa
                // (`$responsaveis`, logo acima): **zero consulta nova e zero segunda verdade**
                // sobre "quem é desta conta". Um 6º argumento no CONSTRUTOR quebraria o gate de
                // aridade do plano 188-04 (`PrecoDoPainelServiceTest:1481`), e um `app()` lá
                // dentro seria service locator disfarçado de injeção.
                : $painelDeRevisao->para($ofertaDoPainel, $base, $responsaveis),
        ];

        // ⚠️⚠️ O **N** do "N de M" é LIDO da faixa que acabou de ser calculada — a MESMA array
        // que a tela recebe. Recontar aqui produziria uma segunda consulta livre para divergir, e
        // é justamente na etapa `publicado` que ela divergiria: ali o recorte é "publicado HOJE"
        // (`whereDate('updated_at', hoje)`, `AndamentoDaOfertaService:294`-`:295`). O usuário
        // recusou número divergente entre conta e lista **duas vezes** (decisões 9 e 16).
        $payload['contagem']['exibidas'] = $filtros->exibidasNaEtapa($payload['faixa'], $etapa);

        // ═══ ⚠️⚠️ Fase 189, plano 02 — A PROP `lightbox` ═══════════════════════════════════
        //
        // ⚠️ **POR QUE ELA É SEPARADA DO `painel`, e não mais uma chave dentro dele:** o
        // `PainelDeRevisaoService` é da **Fase 188** e esta fase **não altera serviço da 188**
        // (`189-REGUA.md` §3). A original de referência é o ÚNICO dado novo de servidor da 189, e
        // ela entra por um serviço NOVO — `LightboxDoPainelService` —, sem encostar no da 188.
        //
        // ⚠️ **POR QUE ELA É CALCULADA DEPOIS DO LITERAL**, ao contrário da `faixa`, do
        // `proximo_a_revisar` e do `painel`: ela **LÊ** o `painel` já montado (`fotos.imagens`),
        // que é a MESMA galeria que a tela recebe. Montá-la dentro do literal exigiria chamar o
        // painel duas vezes ou remontar a galeria — a segunda consulta livre para divergir da que
        // a pessoa está vendo, e a divergência apareceria como *"a lateral mostra a original de
        // outra foto"*. O precedente é o `$payload['contagem']['exibidas']` logo acima.
        //
        // ⚠️ **RECORTADA AO `?oferta=`:** sem oferta em foco ela é **`null`** — nenhuma consulta a
        // `pub_imagem_referencias` acontece na abertura comum da fila, e há gate contando as
        // consultas nas duas direções. ⚠️ E nada daqui vai ao Mercado Livre: a leitura do lightbox
        // é LEITURA, e **aprovar ENFILEIRA** (a escrita no ML é da **Fase 193**).
        //
        // ⚠️ **Guarda de tipo** antes de ler `fotos`/`imagens`: lista vazia quando ausente, nunca
        // um `??` que entregue `null` ao serviço.
        $imagensDoPainel = is_array($payload['painel']['fotos']['imagens'] ?? null)
            ? $payload['painel']['fotos']['imagens']
            : [];
        $payload['lightbox'] = $payload['painel'] === null ? null : $lightboxDoPainel->para($ofertaDoPainel, $imagensDoPainel);

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

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ FASE 188, PLANO 05 — AS SEIS ESCRITAS DO PAINEL DE REVISÃO
    //
    // Três de imagem (coluna de fotos), duas de oferta (rodapé) e a de retry.
    //
    // ═══ A FRONTEIRA, DECLARADA ═══════════════════════════════════════════════════════════════
    //
    // ⚠️⚠️ **APROVAR JÁ PUBLICA, SEM SEGUNDO CLIQUE** (decisão 1 do usuário), com o rótulo
    // "Aprovar para publicação" inalterado. Não existe nestas actions nenhuma etapa intermediária
    // e nenhum caminho de volta: a conferência acontece ANTES do clique, no painel, que mostra as
    // pendências com o motivo de cada uma. Há gate de ausência com isca lendo o código SEM
    // COMENTÁRIO, válido para as Fases 187 a 194.
    //
    // ⚠️⚠️ **E APROVAR ENFILEIRA — A ESCRITA NO MERCADO LIVRE É DA FASE 193** (critério 18).
    // `AprovacaoDaOfertaService::aprovar()` move o andamento pela única porta, grava os três
    // campos de aprovação e congela a composição: **tudo no nosso banco**. O gancho `aoAprovar`,
    // que fala com o ML, está VAZIO e é daquela fase. ⚠️ E nenhum `MLB…` é gravado aqui (AND-04
    // exige MLB confirmado pelo Mercado Livre).
    //
    // ⚠️ **O QUE SAI PELA REDE, MEDIDO em 19/10/2026 — e é LEITURA:** as actions que leem a
    // composição (`aprovar`, por `BloqueiosDaOfertaService::para()`) disparam o GET público
    // `/sites/MLB/listing_prices`, que é a **tarifa real** da decisão 9 do usuário e tem cache de
    // 1 h pré-aquecido por job. ➡️ A asserção honesta é **zero requisição de ESCRITA** (nenhum
    // método diferente de GET, nenhuma URL de `/items`), não "zero requisição". Prometer zero
    // absoluto aqui seria gate que o próprio desenho contradiz — e as três actions de imagem, que
    // não leem composição, são provadas com **zero requisição de qualquer tipo**.
    //
    // ═══ O GATE DE ESCRITA, O MESMO DAS OUTRAS ACTIONS DA ÁREA (T-188-40) ═════════════════════
    //   · **`role:admin`** — do grupo da rota, **sem middleware novo**;
    //   · **trava de conta** — `abort_unless($company->mlToken !== null, 404)`, byte a byte;
    //   · **a oferta conferida contra `FilaDeAprovacaoService::paraAConta()` ANTES de gravar**, e
    //     a imagem/conjunto conferidos contra o rascunho DAQUELA oferta;
    //   · **404, nunca 403** — um 403 confirma que o id existe, que é metade do vazamento;
    //   · **CSRF** — rotas POST normais do módulo, sem exceção;
    //   · **throttle nomeado** — `throttle:30,1,publicador.aprovacao.painel.*`.
    //
    // ═══ A FORMA DA RESPOSTA, e por que ela é MISTA ═══════════════════════════════════════════
    //
    // Sucesso devolve `back()->with(...)`: o painel inteiro tem de **renascer** da prop, porque
    // aprovar uma foto muda progresso, bloqueios, rodapé e selo de uma vez — devolver só o delta
    // criaria uma segunda verdade sobre o mesmo estado. É o que `aprovarLote()` já faz.
    // Recusa devolve `response()->json(['message', 'regra'], 422)`, que é o formato que o módulo
    // já usa (`MlbPublicadorLoteController:233`, `MlbPublicadorFaseController::recusa()`): a tela
    // precisa do **id estável da regra**, não só do texto.
    //
    // ⚠️ **NENHUMA destas actions escreve `andamento`.** Quem move é
    // `AndamentoDaOfertaService::mover()`, sempre, por dentro dos serviços da 186 — e por isso
    // este arquivo **não entrou** na lista de EXPOSITORES da camada B: ele não casa a sonda larga
    // (`'andamento' =>` / `->andamento =`), então entrar nela seria linha morta. Medido.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * **IMG-04 / DADO-04** — aprova UMA foto: grava quem e quando, marca o conjunto e TODAS as
     * ofertas dele como tocados, e registra na linha do tempo das duas.
     *
     * ⚠️ A action é FINA: trava de conta → resolução da imagem → **uma** chamada ao serviço →
     * retorno. As 11 regras de imagem são de {@see RevisaoDeImagemService}, que está EM PRODUÇÃO
     * desde a Fase 186. Reimplementar qualquer uma criaria a segunda verdade, e a divergente só
     * apareceria no caso raro — que aqui é justamente o que mais importa.
     *
     * ⚠️ Imagem em `regenerating` é recusada pelo serviço (IMG-09), com `REGRA_REGENERANDO`.
     */
    public function aprovarImagem(
        Request $request,
        Company $company,
        int $oferta,
        int $imagem,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        RevisaoDeImagemService $revisao,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);
        $daGaleria = $this->imagemDoPainel($daConta, $imagem);

        try {
            $aprovada = $revisao->aprovar($daGaleria, $request->user());
        } catch (RegraViolada $e) {
            return $this->recusaDoPainel($e);
        }

        return back()->with('painel_foto_aprovada', [
            'oferta_id' => (int) $daConta->id,
            'aprovacao_imagem_id' => (int) $aprovada->id,
        ]);
    }

    /**
     * **IMG-05** — "Aprovar N sem alerta": aprova as `pending` **sem** verificação automática
     * acima de `ok`, em **TODOS os grupos do rascunho**, e devolve quantas foram.
     *
     * ⚠️⚠️ **O LAÇO PELOS CONJUNTOS NÃO É ESTILO — É O QUE FAZ O BOTÃO NÃO MENTIR.** O rótulo
     * "Aprovar N sem alerta" vem de `FotosDoPainelService::para()` → `sem_alerta.quantidade`, e
     * aquele N sai de {@see BloqueiosDaOfertaService::contagemDeFotos()}, que cobre **todos** os
     * grupos (`PubAprovacaoConjunto::doRascunho()`). Já
     * {@see RevisaoDeImagemService::aprovarSemAlerta()} recebe **UM conjunto por chamada**.
     * ➡️ Chamar o serviço para um conjunto só aprovaria **menos do que o botão promete**: com
     * `GENERAL → 2` e `COR:Azul → 1`, o admin clicaria em "Aprovar 3 sem alerta" e **uma ficaria
     * de pé**, sem nada na tela explicando por quê.
     * ⚠️ É a mesma razão da **decisão 5 do usuário**: o bloqueio de fotos cobre TODOS os grupos, e
     * **aprovação cor por cor foi RECUSADA** — aprovar já publica e não há desfazer.
     *
     * ⚠️ **Decisão 8 do usuário:** *"tudo de uma vez, e só libera com todas conferidas"*. Aprovar
     * silenciosamente só as que passam foi **RECUSADO**, e a tela **diz quem ficou de fora e por
     * quê**. ➡️ Por isso a resposta leva `com_alerta_restantes` junto de `aprovadas` — os dois da
     * **MESMA** `contagemDeFotos()`, nunca de duas contagens que poderiam somar diferente.
     *
     * ⚠️ **O TEXTO do aviso NÃO é desta action, e não é desta fase.** `VOC-08` fecha o vocabulário
     * em **6** toasts (`vocabularioDoPortao.jsx:213`-`:220`) e o par do protótipo (`:1151`)
     * **NÃO está entre eles** — ver a PERGUNTA-188-D, com dono no usuário, na SUMMARY deste
     * plano. Cunhar uma 7ª frase aqui seria decidir o que não é meu.
     */
    public function aprovarFotosSemAlerta(
        Request $request,
        Company $company,
        int $oferta,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        RevisaoDeImagemService $revisao,
        BloqueiosDaOfertaService $bloqueios,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);

        // ⚠️ `orderBy('id')` explícito: a ordem de uma lista que o servidor PERCORRE não pode vir
        // do índice do banco. MariaDB e SQLite divergem aí, e aqui a divergência mudaria a ordem
        // dos registros na linha do tempo sem nenhuma asserção acusar.
        $conjuntos = PubAprovacaoConjunto::doRascunho((int) $daConta->rascunho_id)
            ->orderBy('id')
            ->get();

        $aprovadas = 0;

        try {
            foreach ($conjuntos as $conjunto) {
                $aprovadas += $revisao->aprovarSemAlerta($conjunto, $request->user());
            }
        } catch (RegraViolada $e) {
            return $this->recusaDoPainel($e);
        }

        $contagem = $bloqueios->contagemDeFotos($daConta->refresh());

        return back()->with('painel_fotos_sem_alerta', [
            'oferta_id' => (int) $daConta->id,
            'aprovadas' => $aprovadas,
            'grupos_percorridos' => $conjuntos->count(),
            // ⚠️ Quem ficou de fora, para a tela poder dizer (decisão 8). MESMA fonte do N.
            'com_alerta_restantes' => (int) $contagem['com_alerta'],
            'pendentes' => (int) $contagem['pendentes'],
        ]);
    }

    /**
     * **IMG-01 / IMG-06 / IMG-10 / BLOQ-06** — reprova a foto. Os **cinco** efeitos são de
     * {@see RevisaoDeImagemService::reprovar()}, numa **transação única**: a foto sai do conjunto
     * final na hora, vai para "Fora da publicação", a próxima vira principal se era a principal, o
     * motivo é registrado, e a aprovação de **TODAS** as ofertas do conjunto é reaberta.
     *
     * ⚠️⚠️ **O MOTIVO É OBRIGATÓRIO, E A TRAVA REAL É DO SERVIÇO** (`REGRA_SEM_MOTIVO`), que faz
     * `trim()` antes de conferir: "só espaço" não é motivo, e a recusa é **no-op completo**.
     * O `required|string` daqui é a porta da frente (recusa a ausência da chave e a string vazia);
     * o piso de conteúdo é de lá.
     *
     * ⚠️ **MEDIDO, e é divergência declarada:** o protótipo exige `> 2` caracteres (`:1402`, RECONFERIDO por `sed` — a prosa do
     * plano dizia `:1404`, que é o ramo `regen`) e o
     * serviço da 186 aceita **qualquer motivo não-vazio**. Um `min:3` aqui criaria uma segunda
     * regra sobre o mesmo campo, mais estrita que a do serviço que está EM PRODUÇÃO e que tem
     * outros chamadores — duas verdades sobre "motivo válido". ➡️ O `> 2` do protótipo é o
     * `disabled=` do **modal MOD-04** (plano 188-11/12), que é conforto de tela; o piso do
     * servidor é não-vazio. Está provado nos dois sentidos no gate `imagem-reprovar`.
     */
    public function reprovarImagem(
        Request $request,
        Company $company,
        int $oferta,
        int $imagem,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        RevisaoDeImagemService $revisao,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);
        $daGaleria = $this->imagemDoPainel($daConta, $imagem);

        $dados = $request->validate([
            'motivo' => ['required', 'string'],
        ]);

        try {
            $reprovada = $revisao->reprovar($daGaleria, $request->user(), $dados['motivo']);
        } catch (RegraViolada $e) {
            return $this->recusaDoPainel($e);
        }

        return back()->with('painel_foto_reprovada', [
            'oferta_id' => (int) $daConta->id,
            'aprovacao_imagem_id' => (int) $reprovada->id,
        ]);
    }

    /**
     * **COL-01 / COL-02 / AND-06** — salva a revisão parcial: `aguardando_aprovacao →
     * em_revisao_ecf` **pela única porta**, com o registro *"Salvou revisão · x de y fotos
     * aprovadas"*.
     *
     * ⚠️⚠️ **A REVISÃO PARCIAL FICA NO SERVIDOR, NÃO EM `localStorage`** (COL-02): outra pessoa
     * continua de onde esta parou, e reler a oferta em outra instância devolve o mesmo estado.
     * Guardar isso no navegador faria a revisão existir só para quem a começou — e o painel é
     * explicitamente um trabalho que troca de mãos.
     *
     * ⚠️ O `x de y` é de `contagemDeFotos()`, a MESMA definição que o bloqueio `fotos_a_conferir`
     * usa — por dentro de `salvarRevisao()`, nunca recontado aqui.
     *
     * ⚠️ **A RECUSA FORA DO `live` É DAQUI, e isso está medido:** `salvarRevisao()` não tem essa
     * guarda (ele só promove a partir de `aguardando_aprovacao` e registra sempre), e em
     * `publicado` ou `publicando` "salvar revisão" registraria um fato que não aconteceu numa
     * oferta que ninguém pode mais revisar. ➡️ A guarda usa o **id de regra que já existe**
     * ({@see AprovacaoDaOfertaService::REGRA_FORA_DO_AO_VIVO}), nunca um id novo: a tela já sabe
     * ler aquele, e cunhar um segundo para a mesma recusa espalharia o vocabulário.
     * ⚠️ E ela é **ANTES** de qualquer gravação.
     */
    public function salvarRevisao(
        Request $request,
        Company $company,
        int $oferta,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        AprovacaoDaOfertaService $aprovacao,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);

        if (! $daConta->ehLive()) {
            return $this->recusaDoPainel(new RegraViolada(
                AprovacaoDaOfertaService::REGRA_FORA_DO_AO_VIVO,
                'Esta oferta não está aberta para revisão.',
                ['oferta_id' => (int) $daConta->id]
            ));
        }

        try {
            $salva = $aprovacao->salvarRevisao($daConta, $request->user());
        } catch (RegraViolada $e) {
            return $this->recusaDoPainel($e);
        }

        return back()->with('painel_revisao_salva', [
            'oferta_id' => (int) $salva->id,
            'versao' => (int) $salva->versao,
        ]);
    }

    /**
     * **BLOQ-04 / COL-04 / AND-05** — aprova para publicação. ⚠️ **E ISTO JÁ PUBLICA** (decisão 1),
     * sem segundo clique e sem desfazer; ⚠️ **mas nesta fase APROVAR ENFILEIRA** — ver o bloco de
     * fronteira acima.
     *
     * ⚠️⚠️ **A TRAVA É DO SERVIDOR, E É POR ISSO QUE ESTA ROTA NASCE ANTES DO RODAPÉ** (plano
     * 188-10). O `disabled=` do botão é conveniência; quem recusa é
     * {@see AprovacaoDaOfertaService::aprovar()}, nos **três** eixos:
     *   · qualquer bloqueio de pé → `REGRA_COM_BLOQUEIO`;
     *   · andamento fora dos três `live` → `REGRA_FORA_DO_AO_VIVO`;
     *   · `versao_esperada` divergente → `REGRA_VERSAO_DIVERGENTE`.
     * Há caso chamando esta rota **direto, com bloqueio de pé**, sem passar pela tela — é o único
     * jeito de provar que a trava não é cosmética. Confiar no cliente aqui seria elevação de
     * privilégio: aprovar publica e não há volta.
     *
     * ⚠️ `versao_esperada` é **opcional no corpo** (`nullable`) e, quando vem, é **repassada**: é a
     * metade da escrita do critério 17 (o aviso na tela é do plano 188-12). ⚠️ **Nunca
     * sobrescrever em silêncio** — se alguém mexeu na oferta entre o que a tela mostrou e o
     * clique, a aprovação é RECUSADA.
     *
     * ⚠️ O `with exceção` **não** é acrescentado ao registro por esta action, e isso é medido:
     * `HistoricoDaOfertaService:169` grava *"Aprovou para publicação (versão N)"* e a exceção tem
     * **evento próprio** (*"Autorizou exceção (…): …"*, de `AjustesDaOfertaService:319`) na MESMA
     * linha do tempo, gravado no momento em que ela foi autorizada e **com quem a liberou**
     * (decisão 6 do usuário). Emendar a frase aqui exigiria alterar um serviço da 186 em produção
     * **e** recriaria o defeito que o docblock daquele arquivo registra como consertado em
     * 15/10/2026: dois eventos para o mesmo fato. Ver a divergência declarada na SUMMARY.
     */
    public function aprovar(
        Request $request,
        Company $company,
        int $oferta,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        AprovacaoDaOfertaService $aprovacao,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);

        $dados = $request->validate([
            'versao_esperada' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $esperada = $dados['versao_esperada'] ?? null;

        try {
            $aprovada = $aprovacao->aprovar($daConta, $request->user(), $esperada === null ? null : (int) $esperada);
        } catch (RegraViolada $e) {
            return $this->recusaDoPainel($e);
        }

        return back()->with('painel_oferta_aprovada', [
            'oferta_id' => (int) $aprovada->id,
            'versao_aprovada' => (int) $aprovada->versao_aprovada,
        ]);
    }

    /**
     * **PUB-02** — a fila de publicação recusou pôr o produto desta oferta na fila **agora**.
     *
     * ⚠️ **id NOVO porque a recusa é NOVA, e não havia equivalente**: `REGRA_FORA_DO_AO_VIVO` é
     * *"não está aberta para revisão"* e `REGRA_TRANSICAO_ILEGAL` é *"este par de estados não
     * existe"* — nenhuma das duas diz *"a fila não aceitou"*, e reusá-las faria a tela marcar o erro
     * no lugar errado. ⚠️ Segue o padrão `<REQ>-<SLUG>` dos outros 21 ids do módulo, e o
     * `recusaDoPainel()` o devolve no campo `regra`, ao lado da `message` com o motivo REAL.
     */
    public const REGRA_FILA_RECUSOU_O_RETRY = 'PUB-02-FILA-RECUSOU';

    /**
     * **REVS-05 / BLOQ-11 / AND-10** — "Tentar publicar novamente", da caixa de falha.
     *
     * ⚠️ Chama {@see AprovacaoDaOfertaService::tentarDeNovo()} e **nada mais**: `falha →
     * publicando` pela única porta, **sem** mexer na `versao` e **preservando** `versao_aprovada`,
     * `aprovado_por` e `aprovado_em`.
     *
     * ⚠️⚠️ **E ELA NÃO PUBLICA.** Move o andamento e registra; a escrita no Mercado Livre é da
     * **Fase 193**. Há caso provando por ausência de requisição de escrita, com isca.
     *
     * ⚠️ **A GUARDA DE "SÓ A PARTIR DE `falha`" É DAQUI, e isso está MEDIDO:**
     * `PubAprovacaoOferta::TRANSICOES` tem `aprovado => [publicando, aguardando_aprovacao]`, então
     * `mover(…, publicando)` a partir de `aprovado` é transição **legal** e `tentarDeNovo()` não a
     * recusaria. Sem esta guarda, "tentar de novo" numa oferta recém-aprovada a empurraria para
     * `publicando` sem nenhuma falha ter acontecido — e aí a Fase 193 publicaria por cima.
     * ⚠️ Reusa `REGRA_FORA_DO_AO_VIVO`? **Não** — aqui o destino legítimo não é `live`, é `falha`.
     * O id é o do serviço de andamento para transição recusada, e a mensagem nomeia o estado.
     *
     * ═══ ⚠️⚠️ A FASE 193 COMPLETOU ESTA ACTION (plano 193-04): AGORA ELA REPUBLICA ════════════
     *
     * ⚠️⚠️ **A ORDEM É `enfileirar()` → `tentarDeNovo()`, E ELA RESOLVE UMA AMBIGUIDADE REAL — NÃO
     * É ESTILO.** Se o retry movesse primeiro, a oferta ficaria em `publicando` com `fila_item_id`
     * apontando para o item **TERMINADO** da tentativa anterior — e o reconciliador do portão, na
     * passada do minuto seguinte, **não teria como distinguir** *"acabou de falhar, feche"* de
     * *"acabou de ser retentada, espere"*: ele leria o item terminado e fecharia a oferta como
     * `falha` **sem ela nunca ter tentado de novo** (T-193-33).
     * ➡️ **Enfileirar antes elimina o estado ambíguo POR CONSTRUÇÃO**, e é exatamente por isso que
     * o plano 193-03 pôde tratar *"`publicando` com `fila_item_id` nulo"* como **impossível**, com
     * um `Log::warning` e **nada mais** — observabilidade, não ramo de produto. Inverter a ordem
     * aqui reintroduz o estado que aquele serviço declarou impossível.
     *
     * ⚠️ **AS DUAS EM UMA TRANSAÇÃO SÓ:** recusa do enfileiramento ⇒ **não move** e a resposta leva
     * o **motivo REAL** da fila (o botão não finge que tentou — T-193-35); e falha do `mover()`
     * (corrida: alguém tirou a oferta de `falha` entre a guarda e aqui) ⇒ a ligação nova **volta
     * atrás**, em vez de deixar o produto na fila com a oferta parada em `falha`, que o
     * reconciliador **não** olha (`RECONCILIAVEIS` não tem `falha`, de propósito: sair de `falha` é
     * ato de PESSOA).
     * ═══ ⚠️⚠️ DECISÃO 26 DO USUÁRIO (21/10/2026): O MOTIVO DA RECUSA **PARA DE VOLTAR ATRÁS** ══
     *
     * ⚠️ **O que estava medido e declarado como consequência aceita (pergunta `193-04/B`):** na
     * recusa, a linha que o `enfileirar()` escreve no histórico **voltava atrás** com a transação.
     * A pessoa via o motivo no `422` e **nada ficava registrado** — e isso acontecia justamente no
     * caso em que a oferta **fica parada**, que é quando alguém vai perguntar *"por que isto não
     * saiu?"*.
     *
     * ➡️ **A decisão do usuário:** *"o motivo real fica REGISTRADO, não só num aviso que passa"*.
     * ⚠️⚠️ **E A TRANSAÇÃO NÃO MUDOU: ela não foi removida nem dividida em duas.** A razão dela está
     * escrita acima e é o que torna *"`publicando` com o item terminado"* impossível. O que mudou é
     * **onde** a linha é escrita: no `catch`, **FORA** da transação, **depois** de o `rollback` já
     * ter acontecido — por {@see PublicacaoDoPortaoService::registrarRecusaForaDaTransacao()}, que é
     * o **mesmo mecanismo** das decisões 21, 23 e 24 (linha de histórico com **ator Sistema**, com
     * o mesmo prefixo e a mesma de-duplicação **contra a última linha**).
     * ⚠️ **O `422` continua saindo** — o registro **acrescenta** um canal, não troca o que existia.
     * ⚠️ **Nenhum toast novo** (os 6 de VOC-08 são fechados), nenhum estado, rótulo ou rota novos.
     *
     * ⚠️ O motivo REAL é capturado **por referência** antes do `throw`: `$motivoDaFila` é memória do
     * processo, não linha de banco, então ele **sobrevive ao `rollback`**. ⚠️ E ele fica **nulo**
     * quando quem lançou foi o `tentarDeNovo()` (a corrida de andamento) — aí **não** se grava
     * recusa de fila, porque não houve recusa de fila.
     *
     * ⚠️ **ESTA ACTION NÃO ESCREVE NO MERCADO LIVRE, e isso continua verdade:** enfileirar é **só
     * banco**. Quem publica é o `PublicarRascunhoJob`, na fila **`high`**, minutos depois — e essa
     * fila **já era assim** (a de produção é Redis; em 16/09/2026 179 jobs de acervo na `default`
     * seguraram um webhook por horas). ⚠️ Nenhum job é despachado daqui.
     */
    public function tentarDeNovo(
        Request $request,
        Company $company,
        int $oferta,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        AprovacaoDaOfertaService $aprovacao,
        PublicacaoDoPortaoService $portao,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);

        if ($daConta->andamento !== PubAprovacaoOferta::FALHA) {
            return $this->recusaDoPainel(new RegraViolada(
                AndamentoDaOfertaService::REGRA_TRANSICAO_ILEGAL,
                'Só é possível tentar de novo uma publicação que falhou.',
                ['oferta_id' => (int) $daConta->id]
            ));
        }

        // ⚠️ DECISÃO 26 — capturado por REFERÊNCIA de dentro da transação: é memória do processo,
        // logo **sobrevive ao `rollback`** que apaga a linha de histórico.
        $motivoDaFila = null;

        try {
            $retentada = DB::transaction(function () use ($daConta, $request, $aprovacao, $portao, &$motivoDaFila) {
                // ⚠️ PRIMEIRO a fila, depois o andamento — ver o docblock.
                $naFila = $portao->enfileirar($daConta, $request->user());

                if (! $naFila['enfileirada']) {
                    // ⚠️ O MOTIVO REAL da fila (uma das 11 frases da prontidão, ou o da guarda da
                    // decisão 23), nunca um "algo deu errado": é o que a pessoa precisa para
                    // resolver. O `regra` é o id ESTÁVEL que a tela usa para saber onde marcar.
                    $motivoDaFila = (string) ($naFila['motivo'] ?? 'a fila de publicação não aceitou este produto agora.');

                    throw new RegraViolada(
                        self::REGRA_FILA_RECUSOU_O_RETRY,
                        'Não foi possível pôr este produto na fila de publicação agora: ' . $motivoDaFila,
                        ['oferta_id' => (int) $daConta->id]
                    );
                }

                return $aprovacao->tentarDeNovo($daConta->fresh(), $request->user());
            });
        } catch (RegraViolada $e) {
            // ⚠️⚠️ DECISÃO 26 DO USUÁRIO — ver o docblock. Aqui o `rollback` **já aconteceu**, e é
            // por isso que a linha sobrevive. ⚠️ **A transação acima não foi dividida nem removida.**
            if ($motivoDaFila !== null) {
                $portao->registrarRecusaForaDaTransacao($daConta, $motivoDaFila);
            }

            return $this->recusaDoPainel($e);
        }

        return back()->with('painel_retry', [
            'oferta_id' => (int) $retentada->id,
            // ⚠️ A versão aprovada é PRESERVADA — é o que o protótipo diz com todas as letras.
            'versao_aprovada' => (int) $retentada->versao_aprovada,
        ]);
    }


    // ═══ ⚠️⚠️ Fase 188, plano 06 — PREÇO, CONFERIR ALTERAÇÃO E FRETE ══════════════════════════
    //
    // Cinco actions, o MESMO gate de escrita das seis do plano 188-05 (T-188-40): `role:admin` do
    // grupo, trava de `ml_token` (404), a oferta conferida contra `paraAConta()` ANTES de gravar,
    // 404 e nunca 403, CSRF normal, throttle nomeado.
    //
    // ⚠️⚠️ **DUAS DELAS NÃO GRAVAM: `simularPreco()` e `previaDoFrete()`.** São LEITURAS com
    // entrada no corpo, e existem porque o critério 6 e o 14 **não tinham caminho nenhum** — o
    // cálculo é do servidor (o plano 188-09 proíbe aritmética de taxa no cliente) e nenhuma rota
    // recebia o valor digitado. Há gate de ausência de ESCRITA nas duas.
    //
    // ⚠️ **O QUE SAI PELA REDE, E A RÉGUA HONESTA:** as actions que leem a composição disparam o
    // GET público `/sites/MLB/listing_prices` — a **tarifa real** da decisão 9 do usuário, com
    // cache de 1 h pré-aquecido por job. ➡️ A asserção é **zero requisição de ESCRITA** (nenhum
    // método diferente de GET, nenhuma URL de `/items`), nunca "zero requisição": prometer zero
    // absoluto aqui seria gate que o próprio desenho contradiz, e o executor seguinte o
    // "consertaria" pré-aquecendo o cache.
    //
    // ⚠️ **NENHUMA destas actions escreve `andamento`.** `approved → awaiting` ao salvar preço ou
    // confirmar frete é de `AprovacaoDaOfertaService::registrarMudanca()`, que move pela ÚNICA
    // PORTA. E nenhuma delas EXPÕE `andamento` numa chave de saída, então este arquivo continua
    // **fora** da lista de EXPOSITORES da camada B: entrar nela seria linha morta.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * **FIN-11 / COL-04 / BLOQ-06 — salva o preço simulado no anúncio.** Os **três** efeitos, numa
     * transação: a `versao` sobe, o aviso de alteração é limpo, e o ato vai para o histórico.
     *
     * ⚠️⚠️ **OS TRÊS, SEPARADAMENTE — e isso não é zelo.** Um gate que só olhasse a versão passaria
     * com o aviso de alteração esquecido de pé, e aí a oferta ficaria bloqueada por uma alteração
     * que a pessoa **acabou de fazer**. ⚠️ E a NARRAÇÃO do ato é desta action porque
     * {@see AprovacaoDaOfertaService::registrarPrecoSalvo()} **não a escreve**: ele registra a
     * CONSEQUÊNCIA (*"Aprovação reaberta: preço alterado na revisão · de X para Y"*) e **só quando
     * a oferta estava `aprovado`** — numa oferta ao vivo o histórico ficaria **sem nenhuma linha**.
     * É a régua que `HistoricoDaOfertaService:160`-`:162` escreve: *"quem narra o ATO é quem o
     * executa"*, a mesma de `RevisaoDeImagemService`.
     *
     * ⚠️ **O PREÇO EM SI NÃO É GRAVADO AQUI, e isso é território:** o preço efetivo é LIDO
     * (`DadosEfetivosService` / `pub_variante_precos`) e quem o escreve é o **editor**, que esta
     * fase não toca. O que pertence ao portão é a consequência — e é ela que está no serviço da
     * 186. **DIVERGÊNCIA DECLARADA** do `applyPrice` do protótipo (`:1169`), que faz `x.price =
     * r2(p)` por ser um protótipo com o banco em memória.
     *
     * ⚠️ **`aprovado` É ACEITO, de propósito** (`:1169`: `if (x.status === 'approved') x.status =
     * 'awaiting'`): salvar preço numa oferta aprovada **reabre** a aprovação pela única porta, o
     * que **impede** a Fase 193 de publicar um preço velho. É estritamente mais seguro que
     * recusar. ⚠️ A chave `pode_salvar` do payload é só `live` (plano 188-04) — ela é o
     * **conforto** da tela; a trava é esta, e é um estado mais permissiva **na direção segura**.
     *
     * ⚠️ `versao_esperada` é **opcional no corpo** e, quando vem, é EXIGIDA: omiti-la equivale a
     * sobrescrever em silêncio, que é o que o critério 17 proíbe.
     */
    public function salvarPreco(
        Request $request,
        Company $company,
        int $oferta,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        AprovacaoDaOfertaService $aprovacao,
        ComposicaoFinanceiraService $financeira,
        HistoricoDaOfertaService $historico,
        PrecoDoPainelService $preco,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);

        $dados = $request->validate([
            'versao_esperada' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        if (($recusa = $this->recusaDeRevisao($daConta, $dados['versao_esperada'] ?? null)) !== null) {
            return $recusa;
        }

        // ⚠️ `num()` (`:1048`), só `> 0`, com GUARDA DE TIPO antes de qualquer cast: `?preco[]=x`
        // chega ARRAY e `(float) []` é a família do `(int) []` que já foi **500** nesta tela.
        $novo = PrecoDoPainelService::sanear($request->input('preco'));

        if ($novo === null) {
            return $this->recusaDoPainel(new RegraViolada(
                PrecoDoPainelService::REGRA_PRECO_INVALIDO,
                'Informe o novo preço de venda.',
                ['oferta_id' => (int) $daConta->id]
            ));
        }

        // ⚠️ O preço de ONDE veio, para a frase do histórico. `precoDoAlvo()` lê a Precificação —
        // **nenhuma requisição ao Mercado Livre** sai dele.
        $de = (float) ($financeira->precoDoAlvo($daConta)['preco'] ?? 0.0);

        $salva = DB::transaction(function () use ($aprovacao, $historico, $preco, $daConta, $de, $novo, $request) {
            // 1) e 2) — a versão sobe e o aviso de alteração é limpo (serviço da 186).
            $salva = $aprovacao->registrarPrecoSalvo($daConta, $de, $novo, $request->user());

            // 3) — o ATO, na frase do protótipo (`:1169`) e com a versão RESULTANTE.
            $historico->registrar(
                $salva,
                $request->user(),
                PubAprovacaoEvento::ALTERACAO,
                $preco->registroDePrecoSalvo($de, $novo, (int) $salva->versao),
                (int) $salva->versao,
            );

            return $salva;
        });

        return back()->with('painel_preco_salvo', [
            'oferta_id' => (int) $salva->id,
            'versao' => (int) $salva->versao,
            'preco_de' => $de,
            'preco_para' => $novo,
        ]);
    }

    /**
     * **FIN-11 / FIN-12 — O TRANSPORTE DA SIMULAÇÃO.** ⚠️ **LEITURA PURA: não grava nada, não
     * envia nada ao Mercado Livre além da LEITURA de tarifa.**
     *
     * ⚠️⚠️ **ELA EXISTE PORQUE NÃO HAVIA CAMINHO.** `PrecoDoPainelService::simular()` está pronto
     * desde o plano 188-04 e **nenhuma rota nem parâmetro recebia o preço digitado** — e o plano
     * 188-09 **proíbe aritmética de taxa no cliente**. Sem este transporte, o critério 6
     * ("simular preço") e o 7 ("recalcula e mostra o selo") ficavam **sem caminho nenhum**.
     *
     * ⚠️ **POST e não GET, de propósito:** o valor é entrada da pessoa e não é bookmarkável —
     * preço simulado na URL faria um link compartilhado abrir a tela com a simulação de outra
     * pessoa. A decisão de *"gesto não vai para a URL"* já está tomada neste módulo
     * (`Aprovacao.jsx:316`-`:317`).
     *
     * ⚠️ **Preço inválido NÃO é 422 aqui, e isso é diferente de `salvarPreco()` de propósito:**
     * `simular()` devolve o **estado NEUTRO** para `0`, negativo, vazio, texto e array (`num()`,
     * `:1048`) — a pessoa está DIGITANDO, e um erro vermelho a cada tecla seria a tela brigando
     * com quem a usa. Já salvar é um ato: lá a recusa é explícita.
     *
     * ⚠️ **As duas formas de resposta carregam a MESMA array**, construída uma vez: JSON para quem
     * pedir JSON, e `back()->with(...)` para o `router.post` do Inertia (é o helper
     * `escreverNoPainel` do plano 188-07 que o cliente usa). Duas montagens poderiam divergir;
     * duas **entregas** da mesma array, não.
     */
    public function simularPreco(
        Request $request,
        Company $company,
        int $oferta,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        PrecoDoPainelService $preco,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);

        $simulacao = $preco->simular($daConta, $request->input('preco'));

        return $request->expectsJson()
            ? response()->json($simulacao)
            : back()->with('painel_simulacao', $simulacao);
    }

    /**
     * **BLOQ-07 — "Conferir alteração" CONFERE SEM ALTERAR O PREÇO.**
     *
     * ⚠️⚠️ **É CONTRAINTUITIVO, e a asserção que prova isso é o que impede alguém de "completar"
     * a função:** o botão se chama *"Conferir alteração"* e **não reaplica nada** (`:1170`:
     * `D.offers[id].priceChanged = null` e só). Quem confere diz *"eu vi que mudou"*, e é isso
     * que faz o bloqueio `alteracao_nao_conferida` sair. ➡️ Há caso exigindo que o preço fique
     * **IGUAL** depois.
     *
     * ⚠️ A guarda de "só em oferta revisável" é DAQUI:
     * {@see AprovacaoDaOfertaService::conferirAlteracao()} não a tem, e em `publicado` ou
     * `publicando` ela registraria um fato que ninguém pode mais produzir. É o mesmo desenho (e o
     * mesmo id de regra) da guarda de `salvarRevisao()`, do plano 188-05.
     */
    public function conferirAlteracao(
        Request $request,
        Company $company,
        int $oferta,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        AprovacaoDaOfertaService $aprovacao,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);

        $dados = $request->validate([
            'versao_esperada' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        if (($recusa = $this->recusaDeRevisao($daConta, $dados['versao_esperada'] ?? null)) !== null) {
            return $recusa;
        }

        try {
            $conferida = $aprovacao->conferirAlteracao($daConta, $request->user());
        } catch (RegraViolada $e) {
            return $this->recusaDoPainel($e);
        }

        return back()->with('painel_alteracao_conferida', [
            'oferta_id' => (int) $conferida->id,
            // ⚠️ A versão NÃO sobe: conferir não é mudança relevante. A chave vai para a tela
            // poder continuar mandando `versao_esperada` sem recarregar.
            'versao' => (int) $conferida->versao,
        ]);
    }

    /**
     * **MOD-03 / DADO-13 / BLOQ-05 — confirma o frete do vendedor.** Grava valor + origem
     * `Confirmado` + a **nota que nomeia a fonte E a data**, e **INCREMENTA a versão**.
     *
     * ⚠️ Os três efeitos são de {@see AprovacaoDaOfertaService::confirmarFrete()} (que delega a
     * `PubAprovacaoFrete::informar()`, numa transação única: linha nova, a anterior derrubada,
     * versão++). A narração do ato (`:1204`) é desta action, pela mesma razão de `salvarPreco()`.
     *
     * ⚠️⚠️ **AS TRÊS ORIGENS SÃO LISTA FECHADA, E O QUE VIAJA É A CHAVE** (VOC-06): o corpo manda
     * `cotacao_transportadora` · `tabela_cliente` · `simulador`, e os RÓTULOS que a pessoa lê
     * (`Cotação da transportadora` · `Tabela do cliente` · `Simulador de frete`, literais do
     * protótipo em `:1401` — **remedido**; a prosa do plano dizia `:1403`, que é o modal
     * `reject`) moram em `PubAprovacaoFrete::ORIGENS`. ➡️ Quem recusa a origem inventada é o
     * modelo, com o id estável `MOD-03-ORIGEM-DESCONHECIDA`, **antes** de qualquer escrita.
     *
     * ⚠️ **FIN-07 não é reaberto aqui:** "antes de declarar o frete Pendente está provado que não
     * há fonte" é decisão da Fase 186 (`ComposicaoFinanceiraService` + `PubAprovacaoComposicao::
     * linhaDeFrete()`, onde o informado à mão VENCE o da Precificação). Esta action só grava o
     * frete que a pessoa informa.
     */
    public function confirmarFrete(
        Request $request,
        Company $company,
        int $oferta,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        AprovacaoDaOfertaService $aprovacao,
        HistoricoDaOfertaService $historico,
        PrecoDoPainelService $preco,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);

        $dados = $request->validate([
            'origem' => ['required', 'string'],
            'versao_esperada' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        if (($recusa = $this->recusaDeRevisao($daConta, $dados['versao_esperada'] ?? null)) !== null) {
            return $recusa;
        }

        $valor = PrecoDoPainelService::sanear($request->input('valor'));

        if ($valor === null) {
            return $this->recusaDoPainel(new RegraViolada(
                PrecoDoPainelService::REGRA_FRETE_INVALIDO,
                'Informe o frete pago pelo vendedor.',
                ['oferta_id' => (int) $daConta->id]
            ));
        }

        $origem = (string) $dados['origem'];

        try {
            $confirmada = DB::transaction(function () use ($aprovacao, $historico, $preco, $daConta, $valor, $origem, $request) {
                $confirmada = $aprovacao->confirmarFrete($daConta, $valor, $origem, $request->user());

                $historico->registrar(
                    $confirmada,
                    $request->user(),
                    PubAprovacaoEvento::ALTERACAO,
                    // ⚠️ O RÓTULO, nunca a chave: `cotacao_transportadora` não vai para a linha
                    // do tempo. Quem traduz é o modelo.
                    $preco->registroDeFreteConfirmado($valor, PubAprovacaoFrete::ORIGENS[$origem] ?? $origem, (int) $confirmada->versao),
                    (int) $confirmada->versao,
                );

                return $confirmada;
            });
        } catch (RegraViolada $e) {
            return $this->recusaDoPainel($e);
        }

        $vigente = PubAprovacaoFrete::vigenteDe($confirmada);

        return back()->with('painel_frete_confirmado', [
            'oferta_id' => (int) $confirmada->id,
            'versao' => (int) $confirmada->versao,
            'valor' => $valor,
            'origem' => $origem,
            // ⚠️ DADO-13 — a nota nomeia a FONTE e a DATA, e é LIDA da linha gravada. Remontá-la
            // aqui criaria a segunda redação da mesma frase.
            'nota' => $vigente?->nota,
        ]);
    }

    /**
     * **Critério 14 — a PRÉVIA do resultado e da margem, ANTES de confirmar o frete.**
     * ⚠️ **LEITURA PURA: não grava nada.**
     *
     * ⚠️ Toda a regra está em {@see PrecoDoPainelService::previaDoFrete()}, inclusive **por que
     * ela não pode ser `ComposicaoFinanceiraService::para()` com o frete substituído** (aquela
     * assinatura não existe, e as duas alternativas foram medidas e descartadas — está escrito
     * lá, com o ponto fixo que trava a premissa por asserção).
     *
     * ⚠️ Valor inválido devolve o estado "sem valor" com a frase do protótipo (`:1401`), **não um
     * 422**: a pessoa está digitando.
     */
    public function previaDoFrete(
        Request $request,
        Company $company,
        int $oferta,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        PrecoDoPainelService $preco,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);

        $previa = $preco->previaDoFrete($daConta, $request->input('valor'));

        return $request->expectsJson()
            ? response()->json($previa)
            : back()->with('painel_frete_previa', $previa);
    }


    // ═══ ⚠️⚠️ Fase 188, plano 06 (tarefa 2) — AJUSTE COM ESCOPO, RESOLVER E EXCEÇÃO ═══════════
    //
    // ⚠️⚠️ **AS TRÊS ESCREVEM ANDAMENTO — SEMPRE PELA ÚNICA PORTA.** Quem move é
    // `AndamentoDaOfertaService::mover()`, por dentro de `AjustesDaOfertaService::pedir()` e
    // `::resolver()`. **Nenhuma destas actions toca a coluna `andamento`**, e **nenhuma a devolve
    // numa chave de saída** — por isso este arquivo continua **FORA** da lista de EXPOSITORES da
    // camada B do gate de andamento: ele não casa a sonda larga (`'andamento' =>` /
    // `->andamento =`), e entrar nela seria **linha morta**. Medido.
    //
    // ⚠️ O que as respostas devolvem, em vez do andamento, são **IDS** (quais ofertas o escopo
    // alcançou, quantos ajustes restam): é o que a tela precisa para saber que o pedido foi além
    // da oferta aberta, sem criar um segundo lugar que afirme o estado.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * **MOD-01 / DADO-09 / AND-07 — pede um ajuste e move as ofertas AFETADAS para
     * `ajustes_solicitados`, conforme o ESCOPO.**
     *
     * ⚠️⚠️ **O ESCOPO É A PARTE QUE ERRA EM SILÊNCIO** (`:1197`): `offer → [o.id]` ·
     * `set → as duas do rascunho` · `product → todas as ofertas do produto`. O conjunto de fotos é
     * **compartilhado** entre Clássico e Premium — se um pedido de conjunto movesse só o Clássico,
     * **o Premium seguiria aprovável e iria ao ar com a foto reprovada**, e aprovar já publica.
     *
     * ⚠️ **O RECORTE DE `:1198` NÃO É OPCIONAL:** só `awaiting`, `ecf_review` e `approved` vão
     * para `changes`. Oferta em `publicando`/`publicado` **recebe o evento** (o fato aconteceu) e
     * **não muda de andamento** — mandar tudo reabriria a revisão de algo que **já está no ar**.
     * Quem aplica esse recorte é `AjustesDaOfertaService::pedir()`, e lá está a divergência
     * declarada: `aprovado → ajustes_solicitados` **não existe** em `TRANSICOES`, então uma oferta
     * aprovada recebe o evento e segue o seu caminho.
     *
     * ⚠️⚠️ **A CHAVE DO ESCOPO É `offer`/`set`/`product`, as de `PubAprovacaoAjuste::ESCOPOS`** —
     * e **`prod` do protótipo é RECUSADO de propósito** (`ESCOPO_DO_PROTOTIPO` é mapa de
     * conferência, não um `||` que aceita as duas formas). O que a tela MOSTRA é o `destino`
     * legível, que o payload do painel já entrega (`modais.ajuste.itens`).
     *
     * ⚠️ **O `required` do `texto` é a porta da frente; o piso de conteúdo é do serviço**
     * (`REGRA_TEXTO_VAZIO`). ⚠️ **E, PELA ROTA, AQUELE CAMINHO É INALCANÇÁVEL — medido na wave
     * 188-05:** o middleware `TrimStrings` chega **antes** da validação, `'     '` vira `''` e
     * morre no `required`, com `errors.texto` em vez do id do serviço. ➡️ A guarda do serviço é o
     * backstop de jobs/commands, e o gate a exercita **DIRETO**; afirmá-la pela rota mediria o
     * caminho errado.
     */
    public function pedirAjuste(
        Request $request,
        Company $company,
        int $oferta,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        AjustesDaOfertaService $ajustes,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);

        $dados = $request->validate([
            'escopo' => ['required', 'string'],
            'item' => ['required', 'string'],
            'texto' => ['required', 'string'],
            'versao_esperada' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        if (($recusa = $this->recusaDeRevisao($daConta, $dados['versao_esperada'] ?? null)) !== null) {
            return $recusa;
        }

        try {
            $ajuste = $ajustes->pedir($daConta, $request->user(), $dados['escopo'], $dados['item'], $dados['texto']);
        } catch (RegraViolada $e) {
            return $this->recusaDoPainel($e);
        }

        return back()->with('painel_ajuste_pedido', [
            'oferta_id' => (int) $daConta->id,
            'ajuste_id' => (int) $ajuste->id,
            'escopo' => (string) $ajuste->escopo,
            // ⚠️ VOC-06 — o destino LEGÍVEL, montado no servidor por
            // `PubAprovacaoAjuste::destinoLegivel()`. A chave nunca chega à tela.
            'destino' => (string) $ajuste->destino,
            // ⚠️ **QUAIS ofertas o escopo alcançou** — a tela tem de poder dizer que o pedido foi
            // além da oferta aberta (a caixa "Encaminhar para" é do plano 188-11). São IDS, não
            // andamentos: o estado tem UM lugar só.
            'ofertas_afetadas' => $ajustes->ofertasAfetadas($ajuste)->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ]);
    }

    /**
     * **AND-07 — resolve o ajuste e, SENDO O ÚLTIMO EM ABERTO, devolve a oferta para
     * `aguardando_aprovacao`.**
     *
     * ⚠️⚠️ **"O ÚLTIMO" É POR OFERTA, NÃO POR AJUSTE** (`:1202`: `if (!left && status ===
     * 'changes')`). Resolver um de dois **não** devolve ninguém: a oferta voltaria a ser aprovável
     * com um ajuste ainda em aberto, e aprovar publica. ➡️ Os **dois** sentidos têm asserção, na
     * mesma fixture — um gate que só testasse o "último" passaria com um serviço que devolve
     * **sempre**.
     *
     * ⚠️ `{ajuste}` é conferido contra **os ajustes que afetam ESTA oferta**, nunca contra a
     * tabela inteira: o filtro é a CONSULTA (`abertosQueAfetam()`), não um `in_array` no PHP, e
     * ajuste de outra conta dá **404, nunca 403** (T-188-58).
     * ⚠️ Ajuste **já resolvido** também dá 404: `abertosQueAfetam()` só traz os abertos, e
     * resolver duas vezes registraria o mesmo fato duas vezes na linha do tempo.
     */
    public function resolverAjuste(
        Request $request,
        Company $company,
        int $oferta,
        int $ajuste,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        AjustesDaOfertaService $ajustes,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);

        $aberto = $ajustes->abertosQueAfetam($daConta)->firstWhere('id', $ajuste);

        abort_if($aberto === null, 404, 'Ajuste fora desta oferta.');

        try {
            $resolvido = $ajustes->resolver($aberto, $request->user());
        } catch (RegraViolada $e) {
            return $this->recusaDoPainel($e);
        }

        return back()->with('painel_ajuste_resolvido', [
            'oferta_id' => (int) $daConta->id,
            'ajuste_id' => (int) $resolvido->id,
            // ⚠️ **QUANTOS AINDA RESTAM** — é o número que explica por que a oferta voltou (ou
            // não) para a fila de conferência, e ele sai da MESMA consulta do bloqueio
            // `ajuste_em_aberto`. Recontar na tela abriria a segunda verdade.
            'restantes' => $ajustes->abertosQueAfetam($daConta->refresh())->count(),
        ]);
    }

    /**
     * **BLOQ-08 / BLOQ-09 / DADO-11 — registra a EXCEÇÃO AUTORIZADA.** O efeito é **IMEDIATO**.
     *
     * ⚠️⚠️ **DECISÃO 6 DO USUÁRIO (14/10/2026): A EXCEÇÃO SÓ REGISTRA QUEM LIBEROU.** Não existe
     * estado "exceção aguardando autorização", não existe segundo passo, não existe caixa de
     * pendência e não existe rota de autorização posterior. **Aprovação em dois passos foi
     * OFERECIDA e RECUSADA.** O controle é **auditoria DEPOIS, não barreira ANTES**, porque
     * aprovar já publica e não há desfazer. ⚠️ Há gate de ausência, ancorado no **sujeito**.
     *
     * ⚠️⚠️ **ELA LIBERA SÓ DOIS BLOQUEIOS** (`PubAprovacaoExcecao::LIBERA`): resultado negativo e
     * margem abaixo do mínimo. **Nunca** fotos, frete, alteração, ajuste ou título — e o gate prova
     * a recusa nos **SEIS** outros, um por um, não só a aceitação nos dois. Exceção que liberasse
     * fotos poria foto não conferida no ar, **sem desfazer** (T-188-50).
     *
     * ⚠️ **AS CHAVES SÃO pt-BR** (`resultado_negativo`, `margem_abaixo_do_minimo`), as mesmas dos
     * oito bloqueios do módulo. **`loss`/`margin` do protótipo são RECUSADOS de propósito** —
     * `TIPO_DO_PROTOTIPO` é mapa de conferência, não um `||` que aceita as duas formas.
     *
     * ⚠️ **`$quem` de `aplicarExcecao()` é O RESPONSÁVEL ESCOLHIDO, não quem clicou**, e isso é o
     * centro do critério 11: `liberada_por` tem de ser quem autorizou (`:1203`: o evento entra com
     * `F.by`, não com `this.ME`). ➡️ O `responsavel_id` é conferido contra a **lista FECHADA da
     * conta** — o MESMO resolvedor do filtro de Responsável da fila e dos autorizadores do payload
     * (`FiltrosDaFilaService::responsaveisDaConta()`). Id de fora da conta é **404**, nunca 403.
     *
     * ⚠️ **A justificativa é `>= 10`, não `> 10`** (`:1203`: `length < 10` recusa), e a trava real
     * é do servidor (`PubAprovacaoExcecao::justificativaValida()`); o botão apagado é conforto de
     * tela. O gate mede **9, 10 e 11** — a fronteira é onde o erro mora.
     *
     * ⚠️ **DIVERGÊNCIA DECLARADA, e ela JÁ ESTÁ DECLARADA pela Fase 186 e pela wave 188-05 — não
     * se reabre:** o critério 11 descreve o registro como *"§ Exceção autorizada por X · data ·
     * justificativa"*, e essa é a frase de **EXIBIÇÃO** (`PubAprovacaoExcecao::fraseDeExibicao()`,
     * que o painel entrega em `preco.excecao`, plano 188-04). O **evento do histórico** é
     * *"Autorizou exceção (…): …"*, gravado por `AjustesDaOfertaService:319` **com quem liberou**.
     * Emendar o texto exigiria alterar um serviço da 186 em produção, ou gravar um **2º evento
     * para o mesmo fato** — o defeito que a 186 consertou em 15/10/2026.
     */
    public function registrarExcecao(
        Request $request,
        Company $company,
        int $oferta,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
        FiltrosDaFilaService $filtros,
        AjustesDaOfertaService $ajustes,
    ) {
        $daConta = $this->ofertaDoPainel($company, $oferta, $fila, $programas);

        $dados = $request->validate([
            'tipo' => ['required', 'string'],
            'justificativa' => ['required', 'string'],
            'responsavel_id' => ['required', 'integer'],
            'versao_esperada' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        if (($recusa = $this->recusaDeRevisao($daConta, $dados['versao_esperada'] ?? null)) !== null) {
            return $recusa;
        }

        // ⚠️ A lista FECHADA de quem pode autorizar — a MESMA do filtro da fila e do payload dos
        // modais. **O FILTRO É A CONSULTA**: quem não é da conta não volta de `responsaveisDaConta()`,
        // então não existe caminho em que um id alheio chegue ao serviço de escrita.
        $alvo = $programas->resolver('company-'.$company->id);
        $daContaBuilder = $fila->paraAConta($company->id, $alvo['mlb_empresa']?->id);
        $ids = array_map(static fn (array $r): int => $r['id'], $filtros->responsaveisDaConta($daContaBuilder));

        abort_unless(in_array((int) $dados['responsavel_id'], $ids, true), 404, 'Responsável fora desta conta.');

        /** @var \App\Models\User $responsavel */
        $responsavel = \App\Models\User::query()->whereKey((int) $dados['responsavel_id'])->firstOrFail();

        try {
            // ⚠️ **O `$quem` é o RESPONSÁVEL**, e é ele que vai para `liberada_por` e para o autor
            // do evento do histórico (`:1203`). Mandar quem clicou apagaria o critério 11.
            $excecao = $ajustes->aplicarExcecao($daConta, $responsavel, $dados['tipo'], $dados['justificativa']);
        } catch (RegraViolada $e) {
            return $this->recusaDoPainel($e);
        }

        return back()->with('painel_excecao_registrada', [
            'oferta_id' => (int) $daConta->id,
            'excecao_id' => (int) $excecao->id,
            'tipo' => (string) $excecao->tipo,
            // ⚠️ A frase que a tela mostra (D29) é LIDA do modelo — remontá-la aqui criaria a
            // segunda redação do mesmo registro.
            'frase' => $excecao->fresh()->load('liberadaPor')->fraseDeExibicao(),
        ]);
    }

    // ═══ Apoio das seis escritas do painel ═══════════════════════════════════════════════════

    /**
     * A oferta da URL, **conferida contra a conta ANTES de qualquer gravação** (T-188-40).
     *
     * ⚠️ **O FILTRO É A CONSULTA**, nunca um `in_array` sobre lista montada no PHP: o que não é da
     * conta **não volta** de `paraAConta()`, então não existe caminho em que uma oferta alheia
     * chegue a um serviço de escrita.
     * ⚠️ **404, nunca 403** — um 403 confirmaria que o id existe (T-188-44).
     *
     * ⚠️ A variável da segunda âncora se chama `$mlbEmpresaIdDoPainel` de propósito: `$mlbEmpresaId`
     * já existe **duas vezes** neste arquivo (em `index()` e em `aprovarLote()`), resultado das
     * edições paralelas das waves 187-09/10, e um terceiro homônimo tornaria qualquer busca por
     * nome ambígua para quem vier depois.
     */
    private function ofertaDoPainel(
        Company $company,
        int $oferta,
        FilaDeAprovacaoService $fila,
        ProgramasPublicadorService $programas,
    ): PubAprovacaoOferta {
        // ⚠️ A MESMA trava das outras actions do módulo, com a MESMA mensagem (T-187-11).
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');

        $alvo = $programas->resolver('company-'.$company->id);
        $mlbEmpresaIdDoPainel = $alvo['mlb_empresa']?->id;

        $daConta = $fila->paraAConta($company->id, $mlbEmpresaIdDoPainel)
            ->whereKey($oferta)
            ->first();

        abort_if($daConta === null, 404, 'Oferta fora desta conta.');

        return $daConta;
    }

    /**
     * A linha de revisão de imagem da URL, conferida contra **o rascunho daquela oferta**.
     *
     * ⚠️ `{imagem}` é `pub_aprovacao_imagens.id`, **não** `pub_imagens.id`. O recorte é pelos
     * conjuntos do rascunho da oferta já travada — foto de outro rascunho dá **404**, mesmo sendo
     * da mesma empresa: o escopo da escrita é a oferta que está aberta no painel.
     */
    private function imagemDoPainel(PubAprovacaoOferta $daConta, int $imagem): PubAprovacaoImagem
    {
        $daGaleria = PubAprovacaoImagem::query()
            ->whereKey($imagem)
            ->whereIn(
                'conjunto_id',
                PubAprovacaoConjunto::doRascunho((int) $daConta->rascunho_id)->select('id')
            )
            ->first();

        abort_if($daGaleria === null, 404, 'Foto fora desta oferta.');

        return $daGaleria;
    }

    /**
     * ⚠️⚠️ **A GUARDA COMUM DAS TRÊS ESCRITAS DO PLANO 188-06** (preço, conferir alteração,
     * frete): a oferta tem de estar **revisável**, e a `versao_esperada`, quando vem, tem de ser a
     * atual. Devolve a recusa pronta, ou `null` quando pode gravar.
     *
     * ⚠️ **"Revisável" aqui é `LIVE` MAIS `aprovado`, e isso é MEDIDO, não folga:**
     * `registrarMudanca()` (a única rotina de reabertura, plano 186-08) devolve a oferta
     * `aprovado` para `aguardando_aprovacao` pela única porta — então salvar preço ou confirmar
     * frete numa oferta aprovada **REABRE** a aprovação, e é exatamente isso que **impede a Fase
     * 193 de publicar um número velho**. Recusar seria *menos* seguro. É o que o protótipo faz
     * (`:1169`: `if (x.status === 'approved') x.status = 'awaiting'`). ⚠️ Fora disso
     * (`preparando`, `publicando`, `publicado`, `falha`) não há revisão a fazer: a edição está
     * bloqueada e gravar registraria um fato que ninguém pode produzir.
     *
     * ⚠️ **O id da regra é o que JÁ EXISTE** ({@see AprovacaoDaOfertaService::REGRA_FORA_DO_AO_VIVO}
     * e {@see AprovacaoDaOfertaService::REGRA_VERSAO_DIVERGENTE}), nunca um id novo: a tela já sabe
     * ler os dois, e cunhar um segundo para a mesma recusa espalharia o vocabulário.
     *
     * ⚠️ **E ela roda ANTES de qualquer gravação**, nas três.
     */
    private function recusaDeRevisao(PubAprovacaoOferta $daConta, ?int $versaoEsperada): ?JsonResponse
    {
        if (! $daConta->ehLive() && $daConta->andamento !== PubAprovacaoOferta::APROVADO) {
            return $this->recusaDoPainel(new RegraViolada(
                AprovacaoDaOfertaService::REGRA_FORA_DO_AO_VIVO,
                'Esta oferta não está aberta para revisão.',
                ['oferta_id' => (int) $daConta->id]
            ));
        }

        if ($versaoEsperada !== null && $versaoEsperada !== (int) $daConta->versao) {
            // ⚠️ COL-04 / critério 17 — alguém mexeu na oferta entre o que a tela mostrou e o
            // clique. **Nunca sobrescrever em silêncio.** A mensagem diz as DUAS versões, porque
            // sem elas a pessoa não tem o que conferir; o `regra` é o id ESTÁVEL que a tela usa.
            return $this->recusaDoPainel(new RegraViolada(
                AprovacaoDaOfertaService::REGRA_VERSAO_DIVERGENTE,
                sprintf(
                    'Esta oferta mudou enquanto você revisava (você viu a versão %d, a atual é %d). Confira de novo antes de gravar.',
                    $versaoEsperada,
                    (int) $daConta->versao
                ),
                ['oferta_id' => (int) $daConta->id, 'versao_vista' => $versaoEsperada, 'versao_atual' => (int) $daConta->versao]
            ));
        }

        return null;
    }

    /**
     * A recusa no formato que o módulo já usa, com o **id estável da regra** junto do texto.
     *
     * ⚠️ `regra` não é enfeite: é por ele que a tela decide onde marcar o erro, e é ele que
     * aparece nos testes. Devolver só a mensagem obrigaria a tela a casar texto em português.
     */
    private function recusaDoPainel(RegraViolada $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'regra' => $e->regra,
        ], 422);
    }
}
