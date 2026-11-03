<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Portal\Estrutura\Produtos\LimiteDoTitulo;
use App\Services\Portal\Estrutura\Produtos\TituloDeVendaService;
use App\Services\Portal\PortalEquipeService;
use App\Services\Publicador\CategoriaBuscaService;
use App\Support\Trilha\TrilhaDeTrabalho;
use App\Support\Trilha\TrilhaLigada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * A categoria e a ficha técnica no cadastro da trilha (3ª entrega, fatia F2 — desenho §5.4, §5.5 e §6.2, rotas 5 a 8),
 * em JSON, na porta da EQUIPE: buscar pelo nome, navegar na árvore, os campos da categoria e os termos do título.
 *
 * Só LEITURA de dado público (o APP token da ECF; nenhuma conta da loja), pelos MESMOS serviços do Portal. Nada é
 * aplicado sozinho (D-06): a categoria só grava no "Salvar" do produto, pela rota das linhas.
 *
 * A guarda é a da trilha de trabalho ({@see MapeamentoDaTrilhaController}): as duas chaves (404, antes de ler a
 * empresa), a empresa (404) e quem escreve no Portal dela (403). Não há recurso da empresa no endereço: a categoria é
 * do catálogo, e o `where` da rota já recusa o que não é `MLB` + dígitos (404).
 *
 * O ML fora NUNCA é erro HTTP aqui: a resposta sai 200 com `indisponivel` e a gaveta segue ("Não achei pelo nome",
 * ficha sem campos, título digitado à mão). O 422 fica só para o pedido malformado (a tela distingue os dois).
 */
class CategoriasDaTrilhaController extends Controller
{
    public function __construct(private PortalEquipeService $equipe) {}

    /**
     * Rota 5 — as candidatas pelo nome do produto (o preditor), cada uma com o caminho, `folha` e `anunciavel`. A tela
     * sugere a 1ª `folha && anunciavel` sem aplicar. Sem candidata ou com o ML fora: `indisponivel`.
     */
    public function buscar(Request $request, int $company, CategoriaSugestaoService $categorias): JsonResponse
    {
        $this->empresa($request, $company);
        $dados = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);

        try {
            $saida = [];
            $candidatas = $categorias->sugerir($dados['q']);
            // UMA leitura em lote (paralela e com o cache do `sugerir`): o `detalhe` uma a uma, com o ML lento,
            // somava 15 s por candidata e estourava o tempo do PHP — um 500 que o catch não pega (revisão da F1).
            $detalhes = $categorias->detalhes(array_column($candidatas, 'id'));
            foreach ($candidatas as $c) {
                $detalhe = $detalhes[$c['id']] ?? null;
                $saida[] = [
                    'id'            => $c['id'],
                    'nome'          => $c['nome'],
                    'caminho'       => $c['caminho'],
                    'caminho_texto' => implode(' > ', array_column($c['caminho'], 'nome')),
                    // Nulos = não deu para ler a categoria agora (a tela não a sugere; abre a navegação nela).
                    'folha'         => $detalhe['folha'] ?? null,
                    'anunciavel'    => $detalhe['anunciavel'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('[Trilha] busca de categoria falhou', ['empresa' => $company, 'erro' => $e->getMessage()]);

            return response()->json(['categorias' => [], 'indisponivel' => true]);
        }

        return response()->json(['categorias' => $saida, 'indisponivel' => $saida === []]);
    }

    /**
     * Rota 6 — um nível da árvore (sem `pai`, o 1º nível), com `folha` e `anunciavel` em cada filha. O ML fora:
     * `indisponivel`; `pai` malformado: 422 (a tela não confunde os dois).
     */
    public function arvore(Request $request, int $company, CategoriaBuscaService $arvore): JsonResponse
    {
        $this->empresa($request, $company);
        $dados = $request->validate(['pai' => ['nullable', 'string', 'regex:/^MLB[0-9]{1,17}$/']]);

        try {
            $nivel = $arvore->nivel($dados['pai'] ?? null);
        } catch (\Throwable $e) {
            Log::warning('[Trilha] navegação de categorias falhou', ['empresa' => $company, 'erro' => $e->getMessage()]);
            $nivel = null;
        }

        return response()->json($nivel === null
            ? ['atual' => null, 'caminho' => [], 'filhas' => [], 'indisponivel' => true]
            : $nivel + ['indisponivel' => false]);
    }

    /**
     * Rota 7 — os campos da ficha técnica da categoria, em grupos (D10): a `definicao()` pura, SEM as explicações por
     * IA — a do Portal (`definicaoComExplicacoes`) enfileira IA para cada campo sem texto, e a equipe não precisa do
     * "o que é isto?". Ler os campos guarda a definição no cache de 7 dias, e é por ele que a Lista deixa de dizer
     * "a conferir". O filtro de sigilo da definição continua (nenhum rótulo com a origem dos campos).
     */
    public function campos(Request $request, int $company, string $categoria, FichaTecnicaDaCategoria $ficha): JsonResponse
    {
        $this->empresa($request, $company);

        try {
            $grupos = $ficha->definicao($categoria);
        } catch (\Throwable $e) {
            Log::warning('[Trilha] campos da categoria falharam', ['empresa' => $company, 'categoria' => $categoria, 'erro' => $e->getMessage()]);
            $grupos = [];
        }

        return response()->json(['grupos' => $grupos, 'indisponivel' => $grupos === []]);
    }

    /**
     * Rota 8 — os termos mais buscados da categoria e o limite do título. Ler os termos guarda o schema da categoria
     * (`ml_categoria_schemas`). O serviço já degrada para `indisponivel`; o `catch` daqui é a rede para o que ele não
     * previu — a gaveta deixa digitar o título à mão no limite padrão.
     */
    public function titulo(Request $request, int $company, string $categoria, TituloDeVendaService $titulo): JsonResponse
    {
        $this->empresa($request, $company);
        $dados = $request->validate(['nome' => ['nullable', 'string', 'max:255']]);

        try {
            $termos = $titulo->termos($categoria, trim((string) ($dados['nome'] ?? '')));
        } catch (\Throwable $e) {
            Log::warning('[Trilha] termos do título falharam', ['empresa' => $company, 'categoria' => $categoria, 'erro' => $e->getMessage()]);
            $termos = ['limite' => LimiteDoTitulo::PADRAO, 'indisponivel' => true];
        }

        return response()->json($termos);
    }

    /** A guarda do desenho §6.1, passos 1 a 3 (a ordem importa: as chaves antes de ler a empresa). */
    private function empresa(Request $request, int $company): Company
    {
        abort_unless(TrilhaLigada::para($company) && TrilhaDeTrabalho::ligada($company), 404);
        $empresa = Company::query()->findOrFail($company);
        abort_unless($this->equipe->podeEntrar($request->user(), $empresa), 403, 'Você não tem acesso ao portal desta empresa.');

        return $empresa;
    }
}
