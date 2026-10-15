<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService;
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
 * ⚠️ **ESTE PLANO NÃO DESENHA TELA.** A página `Mlb/Publicador/Aprovacao` é criada pelo plano
 * **187-04**. O teste de rota deste plano exercita a action pelo caminho Inertia **sem resolver o
 * componente** (header `X-Inertia` + asserção sobre as props), que é o que a suíte do projeto já
 * faz e que **não** carrega o `.jsx`. ➡️ **A asserção de componente resolvido é pendência NOMEADA
 * do 187-04** — está escrito aqui para ninguém achar que faltou gate.
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
     * A fila da conta: o contador da aba, a faixa de 9 recortes e a margem mínima com origem.
     *
     * ⚠️ `role:admin` vem do GRUPO de `routes/mlb_anuncios.php` — **nenhum middleware novo**
     * (T-187-12).
     */
    public function index(
        Request $request,
        Company $company,
        FilaDeAprovacaoService $fila,
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

        $base = $fila->paraAConta($company->id, $mlbEmpresaId);

        return Inertia::render('Mlb/Publicador/Aprovacao', [
            'empresa' => ['id' => $company->id, 'nome' => $company->name],
            'conta' => $conta,
            // ⚠️ `'publicacoes'` de propósito — ver o docblock da classe.
            'cabecalho' => $alvo !== null ? $cabecalhos->para($alvo, $conta, 'publicacoes') : null,
            // ⚠️ O contador vem do SERVIDOR, e é `PubAprovacaoOferta::LIVE` lido do modelo.
            'contador_da_aba' => $fila->contadorDaAba($company->id, $mlbEmpresaId),
            // As 9 entradas, na ordem literal de `ST`, com "Publicado hoje" como recorte DO DIA.
            'faixa' => $fila->faixa($base),
            // ⚠️ FIN-09 — `{valor, origem}`. O número NUNCA é literal: cada número diz de onde vem.
            'margem_minima' => $fila->margemMinima($company->id),
        ]);
    }
}
