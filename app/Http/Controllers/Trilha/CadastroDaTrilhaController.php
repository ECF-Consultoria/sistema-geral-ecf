<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Trilha\CadastroNaTrilha;
use App\Support\Portal\AtorDoPortal;
use App\Support\Portal\Estrutura\RegrasDoCadastro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * O cadastro do produto na porta da EQUIPE da trilha (3ª entrega, 02/11/2026), em JSON: abrir a gaveta (o retrato),
 * criar o produto (nome + SKU) e gravar as linhas. Controller fino: a regra mora em {@see CadastroNaTrilha}, e os
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

        $r = $this->cadastro->gravar($empresa, $alvo, $linhas, AtorDoPortal::daEquipe($request->user()));
        $retrato = $this->cadastro->retrato($empresa, $alvo);

        return response()->json([
            ...$retrato,
            ...$r,
            'mensagem' => self::mensagemDoSalvar($r, $retrato['situacao']),
        ]);
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
