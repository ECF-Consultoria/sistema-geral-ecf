<?php

namespace App\Http\Controllers;

use App\Models\EstruturaTipoPar;
use App\Models\EstruturaTipoProduto;
use App\Services\Portal\Estrutura\Geracao\CatalogoDaEcfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tipos e pares das sugestões de ofertas (Fase 168, D-15): lista GLOBAL da ECF; o cliente não edita.
 * Rotas só dentro do grupo `role:admin`, fora do Portal e sem allowlist de domínio.
 */
class DevEstruturaGeracaoController extends Controller
{
    public function __construct(private CatalogoDaEcfService $catalogo) {}

    public function index(): Response
    {
        $tipos = EstruturaTipoProduto::orderBy('ordem')->orderBy('id')->get();
        $nomes = $tipos->pluck('nome', 'id');

        $contagem = [];
        $pares = EstruturaTipoPar::get();
        foreach ($pares as $par) {
            $contagem[$par->tipo_a_id] = ($contagem[$par->tipo_a_id] ?? 0) + 1;
            if ($par->tipo_b_id !== $par->tipo_a_id) {
                $contagem[$par->tipo_b_id] = ($contagem[$par->tipo_b_id] ?? 0) + 1;
            }
        }

        $listaPares = $pares->map(fn (EstruturaTipoPar $p) => [
            'id'       => $p->id,
            'primeiro' => ['id' => $p->tipo_a_id, 'nome' => $nomes[$p->tipo_a_id] ?? '—'],
            'segundo'  => ['id' => $p->tipo_b_id, 'nome' => $nomes[$p->tipo_b_id] ?? '—'],
            'combit'   => match ($p->combit_repete) {
                'a'     => 'primeiro',
                'b'     => 'segundo',
                'ambos' => 'ambos',
                default => 'nao',
            },
        ])->sortBy(fn ($p) => mb_strtolower($p['primeiro']['nome'].' '.$p['segundo']['nome']))->values();

        return Inertia::render('Dev/EstruturaGeracao', [
            'tipos' => $tipos->map(fn (EstruturaTipoProduto $t) => [
                'id'         => $t->id,
                'slug'       => $t->slug,
                'nome'       => $t->nome,
                'plural'     => $t->plural,
                'palavras'   => array_values(array_filter(array_map('trim', explode(',', (string) $t->palavras)), fn ($p) => $p !== '')),
                'qtd_combo'  => $t->qtd_combo,
                'qtd_combit' => $t->qtd_combit,
                'ordem'      => $t->ordem,
                'pares'      => $contagem[$t->id] ?? 0,
            ])->values(),
            'pares' => $listaPares,
        ]);
    }

    public function criarTipo(Request $request): RedirectResponse
    {
        $this->catalogo->criarTipo($this->dadosDoTipo($request));

        return back()->with('success', 'Tipo salvo.');
    }

    public function atualizarTipo(Request $request, EstruturaTipoProduto $tipo): RedirectResponse
    {
        $this->catalogo->atualizarTipo($tipo, $this->dadosDoTipo($request));

        return back()->with('success', 'Tipo salvo.');
    }

    public function excluirTipo(EstruturaTipoProduto $tipo): RedirectResponse
    {
        $this->catalogo->excluirTipo($tipo);

        return back()->with('success', 'Tipo excluído.');
    }

    public function criarPar(Request $request): RedirectResponse
    {
        $d = $this->dadosDoPar($request);
        $this->catalogo->criarPar($d['primeiro'], $d['segundo'], $d['combit']);

        return back()->with('success', 'Par salvo.');
    }

    public function atualizarPar(Request $request, EstruturaTipoPar $par): RedirectResponse
    {
        $d = $this->dadosDoPar($request);
        $this->catalogo->atualizarPar($par, $d['primeiro'], $d['segundo'], $d['combit']);

        return back()->with('success', 'Par salvo.');
    }

    public function excluirPar(EstruturaTipoPar $par): RedirectResponse
    {
        $this->catalogo->excluirPar($par);

        return back()->with('success', 'Par excluído.');
    }

    /** @return array<string,mixed> */
    private function dadosDoTipo(Request $request): array
    {
        return $request->validate([
            'nome'       => 'required|string|max:60',
            'plural'     => 'required|string|max:60',
            'palavras'   => 'required|string|max:255',
            'qtd_combo'  => 'nullable|string|max:40',
            'qtd_combit' => 'nullable|string|max:40',
            'ordem'      => 'nullable|integer|min:0|max:65535',
        ]);
    }

    /** @return array{primeiro: int, segundo: int, combit: string} */
    private function dadosDoPar(Request $request): array
    {
        $d = $request->validate([
            'primeiro' => 'required|integer|exists:estrutura_tipos_produto,id',
            'segundo'  => 'required|integer|exists:estrutura_tipos_produto,id',
            'combit'   => 'required|in:nao,primeiro,segundo,ambos',
        ]);

        return ['primeiro' => (int) $d['primeiro'], 'segundo' => (int) $d['segundo'], 'combit' => $d['combit']];
    }
}
