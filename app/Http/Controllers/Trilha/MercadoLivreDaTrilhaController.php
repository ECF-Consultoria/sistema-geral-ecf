<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\ProdutosDoMercadoLivreService;
use App\Services\Trilha\CadastroNaTrilha;
use App\Services\Trilha\ImportacaoDoMercadoLivre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * "Importar do Mercado Livre" na porta da EQUIPE da trilha (3ª entrega, fatia F8, desenho §18): a lista dos anúncios da
 * loja (o acervo, sem chamar o ML), o pedido de importação (um Job por anúncio, na fila da IA), o estado do lote (a tela
 * pergunta a cada 2 s) e o "limpar" do painel. Controller fino: a regra mora em {@see ImportacaoDoMercadoLivre}; a
 * guarda é a do cadastro.
 *
 * A guarda, nesta ordem (desenho §6.1): a rota já exigiu login e o módulo `trilha.produtos`; as duas chaves (404, antes
 * de ler a empresa) e quem escreve no Portal da empresa (403) — `CadastroNaTrilha::empresa()`; a forma do pedido (422);
 * e só então o serviço. Nenhuma resposta leva endereço nem texto do Portal.
 */
class MercadoLivreDaTrilhaController extends Controller
{
    public function __construct(
        private CadastroNaTrilha $cadastro,
        private ImportacaoDoMercadoLivre $importacao,
    ) {}

    /** `GET mercado-livre/anuncios?q=&ordem=&situacao=&pagina=&mostrar_na_lista=` — a lista (contrato §18.4). */
    public function anuncios(Request $request, int $company): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);
        $dados = $request->validate([
            'q'                => ['nullable', 'string', 'max:120'],
            'ordem'            => ['nullable', 'string', 'in:'.implode(',', ProdutosDoMercadoLivreService::ORDENS)],
            'situacao'         => ['nullable', 'string', 'in:'.implode(',', ProdutosDoMercadoLivreService::SITUACOES)],
            'pagina'           => ['nullable', 'integer', 'min:1', 'max:10000'],
            'mostrar_na_lista' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->importacao->anuncios(
            $empresa,
            (string) ($dados['q'] ?? ''),
            (string) ($dados['ordem'] ?? 'oportunidade'),
            (string) ($dados['situacao'] ?? 'todos'),
            (int) ($dados['pagina'] ?? 1),
            (bool) ($dados['mostrar_na_lista'] ?? false),
        ));
    }

    /**
     * `POST mercado-livre/importar` (`mlbs[]`, 1 a 20): põe os anúncios na fila. 200 com o estado; 422 em `errors.mlbs`
     * (nenhum, mais de 20, fora do acervo da loja, conta desconectada) — todo erro do pedido nessa chave, também o do
     * item (o Laravel o poria em `mlbs.0`, que a tela não lê).
     */
    public function importar(Request $request, int $company): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);
        $max = ImportacaoDoMercadoLivre::MAX_POR_IMPORTACAO;
        $validador = Validator::make($request->all(), [
            'mlbs'   => ['required', 'array', 'min:1', 'max:'.$max],
            'mlbs.*' => ['required', 'string', 'max:30'],
        ], [
            'mlbs.required'   => ImportacaoDoMercadoLivre::MSG_NENHUM,
            'mlbs.array'      => ImportacaoDoMercadoLivre::MSG_NENHUM,
            'mlbs.min'        => ImportacaoDoMercadoLivre::MSG_NENHUM,
            'mlbs.max'        => sprintf(ImportacaoDoMercadoLivre::MSG_MAIS_DE, $max),
            'mlbs.*.required' => ImportacaoDoMercadoLivre::MSG_FORA_DO_ACERVO,
            'mlbs.*.string'   => ImportacaoDoMercadoLivre::MSG_FORA_DO_ACERVO,
            'mlbs.*.max'      => ImportacaoDoMercadoLivre::MSG_FORA_DO_ACERVO,
        ]);
        if ($validador->fails()) {
            throw ValidationException::withMessages(['mlbs' => array_values(array_unique($validador->errors()->all()))]);
        }

        return response()->json($this->importacao->importar($empresa, (array) $validador->validated()['mlbs'], $request->user()));
    }

    /** `GET mercado-livre/estado` — o painel do lote (a tela pergunta a cada 2 s). */
    public function estado(Request $request, int $company): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);

        return response()->json($this->importacao->estado($empresa));
    }

    /** `POST mercado-livre/limpar` — esquece o painel (o que está na fila fica); devolve o estado. */
    public function limpar(Request $request, int $company): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);
        $this->importacao->limpar($empresa);

        return response()->json($this->importacao->estado($empresa));
    }
}
