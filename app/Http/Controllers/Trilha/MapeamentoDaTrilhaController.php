<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Portal\PortalEquipeService;
use App\Services\Trilha\MapeamentoNaTrilha;
use App\Support\Trilha\TrilhaDeTrabalho;
use App\Support\Trilha\TrilhaLigada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * O Mapeamento na porta da EQUIPE da trilha (2ª entrega, 02/11/2026), em JSON: as opções do painel, a prévia ao vivo
 * e o "Concluir mapeamento". Quem passa é conferido como nas ondas ({@see OndasDaTrilhaController}): as duas chaves
 * (404), a régua de escrita do Portal da empresa (403) e o produto da empresa do endereço (404). O navegador manda
 * só a escolha (combos, parceiros, juntar); o servidor recalcula tudo.
 */
class MapeamentoDaTrilhaController extends Controller
{
    public function __construct(
        private MapeamentoNaTrilha $mapeamento,
        private PortalEquipeService $equipe,
    ) {}

    public function opcoes(Request $request, int $company, int $produto): JsonResponse
    {
        [$empresa, $alvo] = $this->alvo($request, $company, $produto);

        return response()->json($this->mapeamento->opcoes($empresa, $alvo));
    }

    public function previa(Request $request, int $company, int $produto): JsonResponse
    {
        [$empresa, $alvo] = $this->alvo($request, $company, $produto);

        return response()->json($this->mapeamento->previa($empresa, $alvo, $this->escolha($request)));
    }

    public function concluir(Request $request, int $company, int $produto): JsonResponse
    {
        [$empresa, $alvo] = $this->alvo($request, $company, $produto);

        return response()->json($this->mapeamento->concluir($empresa, $alvo, $this->escolha($request), $request->user()));
    }

    /** @return array{0: Company, 1: EstruturaProduto} */
    private function alvo(Request $request, int $company, int $produto): array
    {
        abort_unless(TrilhaLigada::para($company) && TrilhaDeTrabalho::ligada($company), 404);
        $empresa = Company::query()->findOrFail($company);
        abort_unless($this->equipe->podeEntrar($request->user(), $empresa), 403, 'Você não tem acesso ao portal desta empresa.');

        return [$empresa, EstruturaProduto::query()->where('company_id', $empresa->id)->findOrFail($produto)];
    }

    /** @return array{combos: list<int>, parceiros: list<array{produto_id: int, quantidade: int}>, juntar: bool} */
    private function escolha(Request $request): array
    {
        $dados = $request->validate([
            'combos'                  => ['present', 'array', 'max:20'],
            // A régua do Portal: combo de 2 a 999 unidades (`EstruturaOfertaService::criarCombos`).
            'combos.*'                => ['integer', 'min:2', 'max:999'],
            // O produto + os parceiros cabem numa composição (a tela usa a mesma conta: `max_componentes − 1`).
            'parceiros'               => ['present', 'array', 'max:'.(ChaveDeComposicao::MAXIMO_COMPONENTES - 1)],
            'parceiros.*.produto_id'  => ['required', 'integer', 'min:1'],
            'parceiros.*.quantidade'  => ['required', 'integer', 'min:1', 'max:'.MapeamentoNaTrilha::MAX_QUANTIDADE_PARCEIRO],
            'juntar'                  => ['sometimes', 'boolean'],
        ]);
        $juntar = (bool) ($dados['juntar'] ?? false);
        if ($juntar && count($dados['parceiros']) < 2) {
            throw ValidationException::withMessages(['juntar' => 'Marque dois ou mais produtos para juntar numa oferta só.']);
        }

        return [
            'combos'    => array_map('intval', $dados['combos']),
            'parceiros' => array_map(fn ($x) => ['produto_id' => (int) $x['produto_id'], 'quantidade' => (int) $x['quantidade']], $dados['parceiros']),
            'juntar'    => $juntar,
        ];
    }
}
