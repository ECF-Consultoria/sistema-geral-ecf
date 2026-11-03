<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\JardinagemDaFicha;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Trilha\CadastroNaTrilha;
use App\Support\Portal\AtorDoPortal;
use App\Support\Portal\Estrutura\RegrasDoCadastro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * O cadastro do produto na porta da EQUIPE da trilha (3ª entrega, 02/11/2026), em JSON: abrir a gaveta (o retrato),
 * criar o produto (nome + SKU), gravar as linhas (com a categoria, F2), gravar a ficha técnica e o "Preencher com IA"
 * dela (F2b), e o "Conferir frete pela conta" (F3; as fotos ficam no {@see FotosDaTrilhaController}). Controller fino: a regra mora em {@see CadastroNaTrilha}, e os
 * serviços são os do Portal — o `PortalEstruturaProdutosController` não serve aqui porque lê o `PortalContexto`, que
 * só existe dentro do `portal.auth`.
 *
 * A guarda, nesta ordem (desenho §6.1): a rota já exigiu login e o módulo `trilha.produtos`; as duas chaves (404, antes
 * de ler a empresa); quem escreve no Portal da empresa (403); o recurso do endereço preso à empresa (404) ANTES de
 * validar o corpo; a forma do corpo (422); a trava do que andou (409); e só então o serviço, com o ator da equipe
 * (`AtorDoPortal::daEquipe`: origem `interno`, causer = o usuário).
 *
 * Toda resposta de escrita traz o retrato novo (as chaves da rota 1) mais o resultado dela — a tela não precisa de um
 * GET para saber como ficou. Nenhuma resposta leva endereço do Portal.
 */
class CadastroDaTrilhaController extends Controller
{
    public function __construct(private CadastroNaTrilha $cadastro) {}

    /** Rota 1 — o retrato da gaveta `e:{id}`. Não grava e não chama o Mercado Livre. */
    public function abrir(Request $request, int $company, int $produto): JsonResponse
    {
        [$empresa, $alvo] = $this->cadastro->alvo($request->user(), $company, $produto);

        return response()->json($this->cadastro->retrato($empresa, $alvo));
    }

    /**
     * Rota 2 — "+ Produto na lista": nome e SKU (o resto do corpo é ignorado). 201 com o retrato do produto novo;
     * SKU repetido volta 422 em `errors.codigo`.
     */
    public function criar(Request $request, int $company): JsonResponse
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);
        $dados = $request->validate([
            'nome'   => ['required', 'string', 'max:255'],
            'codigo' => ['required', 'string', 'max:120'],
        ], [
            'nome.required'   => 'Informe o nome do item.',
            'nome.string'     => 'Informe o nome do item.',
            'nome.max'        => 'O nome pode ter no máximo 255 caracteres.',
            'codigo.required' => 'Informe o SKU.',
            'codigo.string'   => 'Informe o SKU.',
            'codigo.max'      => 'O SKU pode ter no máximo 120 caracteres.',
        ]);

        $r = $this->cadastro->criar($empresa, $dados['nome'], $dados['codigo'], AtorDoPortal::daEquipe($request->user()));

        return response()->json([
            ...$this->cadastro->retrato($empresa, $r['produto']),
            'absorvidos_da_espera' => $r['absorvidos_da_espera'],
            'avisos'               => $r['avisos'],
            'mensagem'             => $r['absorvidos_da_espera'] > 0
                ? 'Produto criado. Este SKU já tinha anúncio: o produto nasceu ligado a ele e vai direto para a etapa 6.'
                : 'Produto criado.',
        ], 201);
    }

    /**
     * Rota 3 — o "Salvar" das caixas do produto: as linhas lidas do JSON CRU (`''` = não mexi, `null` = limpar), todas
     * do produto do caminho. Erro de linha volta em `erros` com HTTP 200 (como no Portal); o produto travado, 409.
     */
    public function linhas(Request $request, int $company, int $produto): JsonResponse
    {
        [$empresa, $alvo] = $this->cadastro->alvo($request->user(), $company, $produto);
        $request->validate([
            'linhas'   => ['required', 'array', 'min:1', 'max:'.ProdutoCadastroService::MAX_GRADE],
            'linhas.*' => ['array'],
        ], [
            'linhas.required' => 'Nada para salvar.',
            'linhas.array'    => 'Nada para salvar.',
            'linhas.min'      => 'Nada para salvar.',
            'linhas.max'      => 'Envie no máximo '.ProdutoCadastroService::MAX_GRADE.' linhas por vez.',
            'linhas.*.array'  => 'Linha em formato inválido.',
        ]);
        $linhas = $this->cadastro->conferirLinhas($empresa, $alvo, RegrasDoCadastro::linhasComoVieram($request));
        $this->cadastro->exigirLivre($empresa, $alvo);
        // F2: a categoria nova, depois da trava (o produto travado não chega a consultar o ML).
        $linhas = $this->cadastro->conferirCategoria($empresa, $alvo, $linhas);

        $r = $this->cadastro->gravar($empresa, $alvo, $linhas, AtorDoPortal::daEquipe($request->user()));
        $retrato = $this->cadastro->retrato($empresa, $alvo);

        return response()->json([
            ...$retrato,
            ...$r,
            'mensagem' => self::mensagemDoSalvar($r, $retrato['situacao']),
        ]);
    }

    /**
     * Rota 4 — a ficha técnica INTEIRA do estado da gaveta (gravar substitui). O obrigatório vazio grava e vira a falta
     * "ficha: x" na Lista; valor inválido é 422 por campo (`errors["atributos.{ID}"]`) e nada grava; sem categoria
     * gravada, 422 sem chamar o ML; o catálogo fora, 422 sem apagar. `categoria` (opcional) é a que a gaveta tinha
     * aberta: diferente da gravada, 422 em `errors.categoria`. Responde o retrato novo, como a rota 3.
     */
    public function ficha(Request $request, int $company, int $produto): JsonResponse
    {
        [$empresa, $alvo] = $this->cadastro->alvo($request->user(), $company, $produto);
        $dados = $request->validate(
            RegrasDoCadastro::fichaTecnica() + ['categoria' => ['nullable', 'string', 'max:20']],
            RegrasDoCadastro::mensagensDaFicha(),
        );
        $this->cadastro->exigirLivre($empresa, $alvo);

        $this->cadastro->gravarFicha($empresa, $alvo, (array) $request->input('atributos'), $dados['categoria'] ?? null,
            AtorDoPortal::daEquipe($request->user()));
        $retrato = $this->cadastro->retrato($empresa, $alvo);

        return response()->json([
            ...$retrato,
            'mensagem' => match ($retrato['situacao']['etapa']) {
                'lista' => 'Ficha salva. Ainda faltam: '.implode(', ', $retrato['situacao']['faltas']).'.',
                'mapa'  => 'Lista completa: o produto seguiu para o mapeamento.',
                default => 'Ficha salva.',
            },
        ]);
    }

    /**
     * Rota 9 — "Preencher com IA" da ficha (botão manual; a IA é paga por clique). As checagens são as do Portal
     * (`RegrasDoCadastro::recusaDaIa`): sem categoria salva, ou com a tela numa categoria que não é a salva, 422
     * `{message}` sem chamar nada. NÃO grava: devolve `{sugestoes, faltavam}` — só para os campos VAZIOS na tela, cada
     * sugestão já validada contra a categoria — e a pessoa revisa e salva pela rota 4. IA fora: 422 `{message}`.
     */
    public function sugerirFicha(Request $request, int $company, int $produto, JardinagemDaFicha $ia): JsonResponse
    {
        [$empresa, $alvo] = $this->cadastro->alvo($request->user(), $company, $produto);
        $dados = $request->validate(RegrasDoCadastro::iaDaFicha(), RegrasDoCadastro::mensagensDaFicha());
        $this->cadastro->exigirLivre($empresa, $alvo);

        $recusa = RegrasDoCadastro::recusaDaIa($alvo, $dados['categoria'] ?? null);
        if ($recusa !== null) {
            return response()->json(['message' => $recusa], 422);
        }

        try {
            $r = $ia->sugerir($alvo, RegrasDoCadastro::atributosDaTela((array) $request->input('atributos')),
                (string) ($dados['titulo'] ?? ''), (string) ($dados['descricao'] ?? ''));
        } catch (\Throwable $e) {
            Log::warning("[Trilha] IA da ficha técnica falhou — produto {$alvo->id}, empresa {$empresa->id} ({$empresa->name}): {$e->getMessage()}");

            return response()->json(['message' => 'A IA não respondeu agora. Tente de novo em instantes.'], 422);
        }

        return response()->json($r);
    }

    /**
     * Rota 13 — "Conferir frete pela conta" (F3): sem corpo. Lê a conta da LOJA (só GET) e grava em cache a modalidade
     * de envio dela; sem conta conectada, a estimativa, sem requisição. Não grava no produto (sem trava). Responde o
     * retrato novo (a `logistica` de cada variação já pela conta, e os avisos) mais `frete` {conectado, falhou,
     * fora_do_me2} e a mensagem.
     */
    public function frete(Request $request, int $company, int $produto): JsonResponse
    {
        [$empresa, $alvo] = $this->cadastro->alvo($request->user(), $company, $produto);

        $frete = $this->cadastro->cotarFrete($empresa, $alvo);
        $retrato = $this->cadastro->retrato($empresa, $alvo);

        return response()->json([...$retrato, 'frete' => $frete, 'mensagem' => self::mensagemDoFrete($frete, $retrato['variacoes'])]);
    }

    /**
     * @param  array{conectado: bool, falhou: bool, fora_do_me2: string}  $frete
     * @param  list<array{logistica: ?string}>  $variacoes
     */
    private static function mensagemDoFrete(array $frete, array $variacoes): string
    {
        if (! $frete['conectado']) {
            return 'A loja não tem conta conectada: a logística fica pela estimativa (os limites padrão do Mercado Envios).';
        }
        if ($frete['falhou']) {
            return 'A conta da loja não respondeu agora: a logística fica pela estimativa. Tente de novo em instantes.';
        }
        if (! in_array(LogisticaProduto::ME1, array_column($variacoes, 'logistica'), true)) {
            return 'Frete conferido pela conta da loja.';
        }

        return match ($frete['fora_do_me2']) {
            ModalidadeDeEnvio::FORA_COMBINAR =>'Frete conferido pela conta da loja: o produto passa do limite do Mercado Envios e, sem ME1 na conta, o anúncio sai com “Combine a entrega”.',
            default => 'Frete conferido pela conta da loja: o produto passa do limite do Mercado Envios e vai por ME1 (transportadora).',
        };
    }

    /** O resultado do "Salvar", no padrão do Mapear: completo, salvo com faltas, nada mudou ou erro de campo. */
    private static function mensagemDoSalvar(array $r, array $situacao): string
    {
        if ($r['erros'] !== []) {
            return 'Confira os campos marcados: o que está neles não foi salvo.';
        }
        if ((int) $r['totais']['criadas'] + (int) $r['totais']['atualizadas'] === 0) {
            return 'Nada mudou.';
        }

        return match ($situacao['etapa']) {
            'lista' => 'Salvo. Ainda faltam: '.implode(', ', $situacao['faltas']).'.',
            'mapa'  => 'Lista completa: o produto seguiu para o mapeamento.',
            default => 'Salvo.',
        };
    }
}
