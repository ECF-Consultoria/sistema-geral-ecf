<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OperaPlanejadorFull;
use App\Models\Company;
use App\Models\FullCompartilhamento;
use App\Models\FullEnvio;
use App\Services\PlanejadorFull\FullEnviosService;
use App\Services\PlanejadorFull\PlanejadorFullService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * O Planejador FULL pelo link aberto do cliente — sem login (decisão do usuário, 15/10/2026).
 *
 * A empresa vem do token ({@see FullCompartilhamento}); token errado, trocado ou revogado dá
 * 404 (não 403: não confirma que o endereço existe). Cada ação resolve o token de novo, então
 * revogar corta também uma aba que já estava aberta. O cliente opera tudo, igual à equipe —
 * as ações são as mesmas do {@see OperaPlanejadorFull}, com autor nulo e envio de origem
 * `cliente`. Envio de outra empresa continua 404 (checado no trait).
 */
class PlanejadorFullPublicoController extends Controller
{
    use OperaPlanejadorFull {
        atualizar as protected atualizarDaEmpresa;
        status as protected statusDaEmpresa;
        vendas as protected vendasDaEmpresa;
        salvarConfig as protected salvarConfigDaEmpresa;
        salvarSkus as protected salvarSkusDaEmpresa;
        salvarItens as protected salvarItensDaEmpresa;
        importarGalpao as protected importarGalpaoDaEmpresa;
        registrarEnvio as protected registrarEnvioDaEmpresa;
        atualizarEnvio as protected atualizarEnvioDaEmpresa;
        excluirEnvio as protected excluirEnvioDaEmpresa;
        importarPlanilhaEstoque as protected importarPlanilhaEstoqueDaEmpresa;
        exportar as protected exportarDaEmpresa;
    }

    public function __construct(
        private PlanejadorFullService $service,
        private FullEnviosService $envios,
    ) {}

    public function show(string $token)
    {
        $compartilhamento = $this->compartilhamento($token);
        $company = $compartilhamento->company;

        // Quantas vezes o link foi aberto — a equipe vê na tela de compartilhar.
        $compartilhamento->forceFill([
            'ultimo_acesso_em' => now(),
            'acessos'          => $compartilhamento->acessos + 1,
        ])->saveQuietly();

        return Inertia::render('PlanejadorFull/Publico', $this->service->payload($company, $this->envios) + [
            'temToken' => $company->mlToken?->status === 'active',
            'rotas'    => $this->rotas($token),
        ])->toResponse(request())->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function atualizar(string $token): JsonResponse
    {
        return $this->atualizarDaEmpresa($this->empresa($token));
    }

    public function status(string $token): JsonResponse
    {
        return $this->statusDaEmpresa($this->empresa($token));
    }

    public function vendas(Request $request, string $token, string $inventory): JsonResponse
    {
        return $this->vendasDaEmpresa($request, $this->empresa($token), $inventory);
    }

    public function salvarConfig(Request $request, string $token): JsonResponse
    {
        return $this->salvarConfigDaEmpresa($request, $this->empresa($token));
    }

    public function salvarSkus(Request $request, string $token): JsonResponse
    {
        return $this->salvarSkusDaEmpresa($request, $this->empresa($token));
    }

    public function salvarItens(Request $request, string $token): JsonResponse
    {
        return $this->salvarItensDaEmpresa($request, $this->empresa($token));
    }

    public function importarGalpao(Request $request, string $token): JsonResponse
    {
        return $this->importarGalpaoDaEmpresa($request, $this->empresa($token));
    }

    public function registrarEnvio(Request $request, string $token): JsonResponse
    {
        return $this->registrarEnvioDaEmpresa($request, $this->empresa($token));
    }

    public function atualizarEnvio(Request $request, string $token, FullEnvio $envio): JsonResponse
    {
        return $this->atualizarEnvioDaEmpresa($request, $this->empresa($token), $envio);
    }

    public function excluirEnvio(string $token, FullEnvio $envio): JsonResponse
    {
        return $this->excluirEnvioDaEmpresa($this->empresa($token), $envio);
    }

    public function importarPlanilhaEstoque(Request $request, string $token): JsonResponse
    {
        return $this->importarPlanilhaEstoqueDaEmpresa($request, $this->empresa($token));
    }

    public function exportar(Request $request, string $token)
    {
        return $this->exportarDaEmpresa($request, $this->empresa($token));
    }

    private function compartilhamento(string $token): FullCompartilhamento
    {
        $c = FullCompartilhamento::acharAtivo($token);
        abort_if($c === null || $c->company === null, 404);

        return $c;
    }

    private function empresa(string $token): Company
    {
        return $this->compartilhamento($token)->company;
    }

    /** Os mesmos nomes de endereço da porta interna — a tela não sabe por qual porta entrou. */
    private function rotas(string $token): array
    {
        return [
            'atualizar'      => route('planejador-publico.atualizar', $token, false),
            'status'         => route('planejador-publico.status', $token, false),
            'vendas'         => route('planejador-publico.vendas', [$token, '__COD__'], false),
            'config'         => route('planejador-publico.config', $token, false),
            'skus'           => route('planejador-publico.skus', $token, false),
            'itens'          => route('planejador-publico.itens', $token, false),
            'galpao'         => route('planejador-publico.galpao', $token, false),
            'envios'         => route('planejador-publico.envios.store', $token, false),
            'enviosPlanilha' => route('planejador-publico.envios.planilha', $token, false),
            'envio'          => route('planejador-publico.envios.update', [$token, '__ID__'], false),
            'exportar'       => route('planejador-publico.exportar', $token, false),
        ];
    }

    protected function autorId(Request $request): ?int
    {
        return null;
    }

    protected function origemDoEnvio(): string
    {
        return FullEnvio::ORIGEM_CLIENTE;
    }
}
