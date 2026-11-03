<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Services\Portal\Estrutura\Produtos\ModeloCompleto;
use App\Services\Trilha\CadastroNaTrilha;
use App\Services\Trilha\PlanilhaDaTrilha;
use App\Support\Portal\AtorDoPortal;
use App\Support\Portal\Estrutura\RegrasDoCadastro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A planilha por categoria na porta da EQUIPE da trilha (3ª entrega, fatia F7, desenho §17): baixar o modelo das
 * categorias escolhidas, a prévia do arquivo preenchido e o gravar. Controller fino: a regra mora em
 * {@see PlanilhaDaTrilha}; a guarda é a do cadastro.
 *
 * A guarda, nesta ordem (desenho §6.1): a rota já exigiu login e o módulo `trilha.produtos`; as duas chaves (404, antes
 * de ler a empresa) e quem escreve no Portal da empresa (403) — `CadastroNaTrilha::empresa()`; a forma do pedido
 * (422); e só então o serviço, com o ator da equipe. Não há recurso no endereço (o produto vem do arquivo), e a trava e
 * a versão são POR PRODUTO dentro do resultado — nunca um 409 do pedido inteiro.
 *
 * O arquivo que não serve (não é .xlsx, passa de 2 MB, não é a planilha da trilha, linhas demais…) é 422
 * `errors.arquivo`; o 413 do PHP vira o mesmo 422 no `bootstrap/app.php`. Todo 422 daqui sai em JSON
 * (`{message, errors}`), mesmo quando o pedido não diz que aceita JSON: o download do modelo pode sair de um link, e um
 * redirecionamento com a sessão de erros não chegaria à tela.
 */
class PlanilhaDaTrilhaController extends Controller
{
    public function __construct(
        private CadastroNaTrilha $cadastro,
        private PlanilhaDaTrilha $planilha,
    ) {}

    /**
     * `GET planilha/modelo?categorias[]=MLB…` (1 a 10): o `.xlsx` para baixar. A categoria que não serve (não existe, não
     * é a última do ramo, não é anunciável, sem ficha agora, colunas demais) volta 422 `errors.categorias` com o motivo
     * de cada uma — o arquivo só sai inteiro. Nenhuma IA (a ficha sai sem as explicações).
     */
    public function modelo(Request $request, int $company): StreamedResponse|JsonResponse
    {
        $this->cadastro->empresa($request->user(), $company);

        return self::emJson(fn () => $this->baixar($request));
    }

    private function baixar(Request $request): StreamedResponse
    {
        $validador = Validator::make($request->query(), [
            'categorias'   => ['required', 'array', 'min:1', 'max:'.PlanilhaDaTrilha::MAX_CATEGORIAS],
            'categorias.*' => ['required', 'string', 'regex:/^MLB\d{1,17}$/i'],
        ], [
            'categorias.required' => 'Escolha ao menos uma categoria.',
            'categorias.array'    => 'Escolha ao menos uma categoria.',
            'categorias.min'      => 'Escolha ao menos uma categoria.',
            'categorias.max'      => 'Escolha no máximo '.PlanilhaDaTrilha::MAX_CATEGORIAS.' categorias por arquivo.',
            'categorias.*.required' => 'Categoria inválida. Escolha de novo.',
            'categorias.*.string' => 'Categoria inválida. Escolha de novo.',
            'categorias.*.regex'  => 'Categoria inválida. Escolha de novo.',
        ]);
        if ($validador->fails()) {
            // Tudo em `errors.categorias` — o item inválido também (o Laravel o poria em `categorias.0`): a tela lê a 1ª.
            throw ValidationException::withMessages(['categorias' => array_values(array_unique($validador->errors()->all()))]);
        }
        $dados = $validador->validated();

        $r = $this->planilha->modelo($dados['categorias']);
        if ($r['recusadas'] !== [] || $r['planilha'] === null) {
            throw ValidationException::withMessages(['categorias' => array_column($r['recusadas'], 'motivo') ?: ['Não foi possível montar a planilha agora. Tente de novo.']]);
        }

        $planilha = $r['planilha'];

        return response()->streamDownload(
            fn () => (new Xlsx($planilha))->save('php://output'),
            ModeloCompleto::nomeDoArquivo($r['abas']),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * `POST planilha/previa` (multipart `arquivo`): o que o arquivo faria, produto a produto — o gravar desfeito.
     * Nada grava, nada é registrado, nada vai para a fila. 200 com o contrato da §17.4 (`gravado: false`).
     */
    public function previa(Request $request, int $company): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);

        return self::emJson(fn () => response()->json(
            $this->planilha->previa($empresa, $this->arquivo($request), AtorDoPortal::daEquipe($request->user()))));
    }

    /**
     * `POST planilha` (multipart `arquivo` + `versoes[{produto_id}]=hash`, as da prévia): grava os produtos certos; o
     * recusado fica inteiro de fora, com o motivo. 200 com o mesmo contrato (`gravado: true`).
     */
    public function gravar(Request $request, int $company): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);

        return self::emJson(function () use ($request, $empresa) {
            $caminho = $this->arquivo($request, [
                'versoes'   => ['nullable', 'array', 'max:'.PlanilhaDaTrilha::MAX_LINHAS],
                'versoes.*' => ['nullable', 'string', 'max:64'],
            ]);

            return response()->json($this->planilha->gravar($empresa, $caminho, AtorDoPortal::daEquipe($request->user()),
                (array) $request->input('versoes', [])));
        });
    }

    /**
     * Roda a ação e devolve o 422 de validação SEMPRE em JSON (`{message, errors}`), como a tela lê
     * (`errors.categorias`, `errors.arquivo`).
     *
     * @param  callable(): (StreamedResponse|JsonResponse)  $acao
     */
    private static function emJson(callable $acao): StreamedResponse|JsonResponse
    {
        try {
            return $acao();
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    /**
     * O arquivo do pedido: o que o PHP descartou por tamanho vira a frase dos 2 MB; depois a forma (`.xlsx` pelo
     * conteúdo, até 2 MB) e a extensão do nome. As mesmas regras do Portal (`RegrasDoCadastro`).
     *
     * @param  array<string, mixed>  $mais  regras do resto do corpo
     */
    private function arquivo(Request $request, array $mais = []): string
    {
        RegrasDoCadastro::recusarPlanilhaQueNaoChegou($request);
        $request->validate(RegrasDoCadastro::planilha() + $mais, RegrasDoCadastro::MENSAGENS_DA_PLANILHA + [
            'versoes.array'    => 'Recarregue a prévia e tente de novo.',
            'versoes.max'      => 'Recarregue a prévia e tente de novo.',
            'versoes.*.string' => 'Recarregue a prévia e tente de novo.',
            'versoes.*.max'    => 'Recarregue a prévia e tente de novo.',
        ]);

        return RegrasDoCadastro::caminhoDaPlanilha($request);
    }
}
