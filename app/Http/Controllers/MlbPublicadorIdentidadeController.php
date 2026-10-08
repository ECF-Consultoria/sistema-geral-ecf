<?php

namespace App\Http\Controllers;

use App\Models\CreativeIdentidade;
use App\Models\PubProduto;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Publicador\ProgramasPublicadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fase 170, Plano 02 (D2, IDENT-01/04) — leitura e gravação do texto de
 * identidade visual de UMA CONTA, endereçado pelo `{produto}` só para o
 * servidor descobrir QUAL conta (mesma resolução de
 * `ProgramasPublicadorService::empresaDoProduto()` já usada em todo o
 * Publicador) — o que é lido/gravado é sempre o registro da CONTA
 * (`company_id`/`mlb_empresa_id`), nunca do produto.
 *
 * Mesma disciplina de autorização de
 * `MlbPublicadorCriativoController::rascunhoAutorizado()` (chave ligada +
 * produto autorizado), mas sem buscar `PubRascunho` — identidade não é por
 * anúncio, não precisa de rascunho.
 *
 * ⚠️ Sem nenhum endpoint de logo (D3 fora do planejamento desta fase).
 */
class MlbPublicadorIdentidadeController extends Controller
{
    public function __construct(
        private CreativeEngineAtivo $chave,
        private ProgramasPublicadorService $programas,
    ) {}

    /** O produto autorizado — chave desligada ou produto fora do escopo dão 404 (nunca 403, D-13). */
    private function produtoAutorizado(int $produto): PubProduto
    {
        abort_unless($this->chave->ativa(), 404);

        $p = PubProduto::findOrFail($produto);
        abort_if($this->programas->empresaDoProduto($p) === null, 404);

        return $p;
    }

    /** O texto de identidade da CONTA do produto aberto — único dado exposto, nunca o id do registro. */
    public function mostrar(int $produto): JsonResponse
    {
        $p = $this->produtoAutorizado($produto);
        $identidade = CreativeIdentidade::paraAncora($p->company_id, $p->mlb_empresa_id);

        return response()->json(['texto' => $identidade?->texto]);
    }

    /** Grava o texto de identidade da CONTA do produto aberto — sempre pela âncora do produto AUTORIZADO. */
    public function salvar(Request $request, int $produto): JsonResponse
    {
        $p = $this->produtoAutorizado($produto);
        $dados = $request->validate(['texto' => ['nullable', 'string', 'max:4000']]);

        CreativeIdentidade::updateOrCreate(
            ['company_id' => $p->company_id, 'mlb_empresa_id' => $p->mlb_empresa_id],
            ['texto' => $dados['texto'] ?? null],
        );

        return response()->json(['texto' => $dados['texto'] ?? null]);
    }
}
