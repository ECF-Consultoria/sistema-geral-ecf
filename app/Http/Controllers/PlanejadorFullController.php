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
 * Planejador FULL — reposição do estoque no Fulfillment do Mercado Livre, por empresa.
 *
 * Os dados do ML chegam pela coleta diária (full:coletar → FullColetaService); aqui só se
 * lê o que já está no banco e se grava o que a pessoa ajusta. Acesso de todos os
 * colaboradores logados (decisão de produto de 15/10/2026) — gate na rota.
 *
 * As ações (salvar ajustes, registrar envio, exportar...) moram em {@see OperaPlanejadorFull},
 * compartilhadas com o link aberto do cliente ({@see PlanejadorFullPublicoController}).
 */
class PlanejadorFullController extends Controller
{
    use OperaPlanejadorFull;

    public function __construct(
        private PlanejadorFullService $service,
        private FullEnviosService $envios,
    ) {}

    public function index()
    {
        return Inertia::render('PlanejadorFull/Index', [
            'empresas' => $this->service->empresas(),
        ]);
    }

    public function show(Company $company)
    {
        return Inertia::render('PlanejadorFull/Show', $this->service->payload($company, $this->envios) + [
            'outrasEmpresas' => collect($this->service->empresas())
                ->map(fn ($e) => ['id' => $e['id'], 'nome' => $e['nome']])
                ->values(),
            'temToken'        => $company->mlToken?->status === 'active',
            'rotas'           => $this->rotas($company),
            'compartilhamento' => $this->compartilhamentoPublico($company),
        ]);
    }

    /** Gera (ou troca) o link aberto do cliente. Trocar invalida o link anterior na hora. */
    public function compartilhar(Request $request, Company $company): JsonResponse
    {
        FullCompartilhamento::gerar($company, $request->user()->id);

        return response()->json(['compartilhamento' => $this->compartilhamentoPublico($company)]);
    }

    /** Desliga o link aberto do cliente. */
    public function revogarCompartilhamento(Company $company): JsonResponse
    {
        FullCompartilhamento::where('company_id', $company->id)->first()?->revogar();

        return response()->json(['compartilhamento' => $this->compartilhamentoPublico($company)]);
    }

    private function compartilhamentoPublico(Company $company): ?array
    {
        $c = FullCompartilhamento::with('criador:id,name')->where('company_id', $company->id)->first();

        if (! $c) {
            return null;
        }

        return [
            'ativo'          => $c->ativo(),
            'url'            => $c->url(),
            'geradoEm'       => $c->gerado_em?->toIso8601String(),
            'geradoPor'      => $c->criador?->name,
            'revogadoEm'     => $c->revogado_em?->toIso8601String(),
            'ultimoAcessoEm' => $c->ultimo_acesso_em?->toIso8601String(),
            'acessos'        => $c->acessos,
        ];
    }

    /** Os endereços que a tela chama — relativos, os mesmos nomes da porta do cliente. */
    private function rotas(Company $company): array
    {
        return [
            'atualizar'      => route('planejador-full.atualizar', $company, false),
            'status'         => route('planejador-full.status', $company, false),
            'vendas'         => route('planejador-full.vendas', [$company, '__COD__'], false),
            'config'         => route('planejador-full.config', $company, false),
            'skus'           => route('planejador-full.skus', $company, false),
            'itens'          => route('planejador-full.itens', $company, false),
            'galpao'         => route('planejador-full.galpao', $company, false),
            'envios'         => route('planejador-full.envios.store', $company, false),
            'enviosPlanilha' => route('planejador-full.envios.planilha', $company, false),
            'envio'          => route('planejador-full.envios.update', [$company, '__ID__'], false),
            'exportar'       => route('planejador-full.exportar', $company, false),
            'compartilhar'   => route('planejador-full.compartilhar', $company, false),
        ];
    }

    protected function autorId(Request $request): ?int
    {
        return $request->user()?->id;
    }

    protected function origemDoEnvio(): string
    {
        return FullEnvio::ORIGEM_PLANEJADOR;
    }
}
