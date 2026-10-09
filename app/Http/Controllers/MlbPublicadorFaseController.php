<?php

namespace App\Http\Controllers;

use App\Services\Publicador\FamiliaDeFasesService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\ContasLiberadas;
use Inertia\Inertia;

/**
 * Fases do produto no Publicador (§3 da ETAPA-3, Fase 175).
 *
 * Esta plan (175-04) traz só a TELA do Produto; os endpoints do painel
 * "Criar Fase N" (prévia, criação, IA) e o vínculo de combos chegam nas plans
 * 175-05 a 175-10 e moram aqui, neste mesmo controller.
 *
 * ═══ Escopo (D-13) ══════════════════════════════════════════════════════════
 *
 * `{conta}` vem SEMPRE do resolver, nunca do corpo da requisição, e a própria
 * rota recusa o que não casa com `(empresa|company)-[0-9]+`. O produto é
 * buscado DENTRO de `produtosQuery($alvo['mlb_empresa'], $alvo['company'])`:
 * produto de outra conta simplesmente não existe neste escopo e sai como
 * **404, nunca 403** — 403 confirmaria a existência do id para quem só trocou
 * o número na URL.
 *
 * Zero chamada ao Mercado Livre neste request (regra herdada da Etapa 2): tudo
 * o que a tela mostra já está gravado.
 */
class MlbPublicadorFaseController extends Controller
{
    public function __construct(
        private ProgramasPublicadorService $programas,
        private FamiliaDeFasesService $familia,
    ) {}

    /**
     * `GET publicador/empresas/{conta}/produtos/{produto}` — a tela do Produto:
     * cabeçalho, cartões de fase, ofertas no ar, histórico e as duas laterais.
     *
     * Abrir um KIT por esta rota leva à tela do BASE com a fase do kit
     * destacada (§3) — nunca a uma tela de kit solta. Quem decide isso é o
     * `FamiliaDeFasesService`, que também marca `base_excluido` quando o base
     * de um kit foi apagado (`produto_base_id` NULL depois do SET NULL).
     */
    public function mostrar(string $conta, int $produto)
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        // `company-N` ligada a MlbEmpresa ativa de Polos/Incubadora tem chave
        // canônica `empresa-N`: redireciona preservando o {produto}.
        if ($alvo['chave'] !== $conta) {
            return redirect()->route('mlb.anuncios.publicador.produto', [
                'conta' => $alvo['chave'],
                'produto' => $produto,
            ]);
        }

        // D-13: o escopo é a própria query. Fora dele, 404 — NUNCA abort(403).
        $p = $this->programas->produtosQuery($alvo['mlb_empresa'], $alvo['company'])
            ->whereKey($produto)
            ->first();
        abort_if($p === null, 404);

        // A barra da conta mostra a conta do PRODUTO quando ela difere da da
        // empresa resolvida (WR-B01) — mesma regra do editor.
        $empresa = $this->programas->empresaParaTela($alvo, $p);
        $payload = $this->familia->paraTela($p, $alvo, $empresa);

        return Inertia::render('Mlb/Publicador/Produto', [
            'empresa' => $empresa,
            'liberada' => ContasLiberadas::libera($p->contaOuNula()),
            // O contrato da tela chama o cabeçalho de `produto` (é o produto
            // base da família); o serviço devolve a mesma coisa em `base`.
            'produto' => $payload['base'],
            'fase_destacada' => $payload['fase_destacada'],
            'fases' => $payload['fases'],
            'proxima_fase' => $payload['proxima_fase'],
            'ofertas' => $payload['ofertas'],
            'historico' => $payload['historico'],
            'criativos' => $payload['criativos'],
            'mapeamento' => $payload['mapeamento'],
            'abas' => ['company_id' => $alvo['company']?->id],
        ]);
    }
}
