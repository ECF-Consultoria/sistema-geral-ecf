<?php

namespace App\Services\Publicador\Alavancas;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\PubAlavancaEscrita;
use App\Models\PubProduto;
use App\Services\Publicador\ProgramasPublicadorService;

/**
 * Resolve a conta das Alavancas para as duas âncoras (Company e MlbEmpresa sem
 * Company) no mesmo formato. Nenhuma chamada HTTP aqui.
 */
class ContextoAlavancas
{
    public function __construct(private ProgramasPublicadorService $programas) {}

    /** @return ?array{mlb_empresa: ?MlbEmpresa, company: ?Company, programa: string, chave: string} */
    public function resolver(string $chave): ?array
    {
        return $this->programas->resolver($chave);
    }

    /** Sem âncora com token ativo (ou sem vendedor no token): null — e nenhuma chamada ao ML sai. */
    public function daTela(array $alvo): ?ContaAlavanca
    {
        $ancora = PubProduto::ancoraComToken($alvo['mlb_empresa'] ?? null, $alvo['company'] ?? null);
        $sellerId = (string) ($ancora?->mlToken?->ml_user_id ?? '');
        if ($ancora === null || $sellerId === '') {
            return null;
        }

        return new ContaAlavanca(
            conta: $ancora,
            sellerId: $sellerId,
            mlbEmpresa: $alvo['mlb_empresa'] ?? null,
            company: $alvo['company'] ?? null,
            chaveTela: (string) $alvo['chave'],
            nome: $ancora->nomeContaMl(),
        );
    }

    /**
     * Reconstrói a conta pelas âncoras gravadas na linha do histórico: o job de
     * lote compara `chaveConta()` com `conta_chave` da linha — conta trocada = nada sai.
     */
    public function daLinha(PubAlavancaEscrita $l): ?ContaAlavanca
    {
        $empresa = $l->mlb_empresa_id ? MlbEmpresa::find($l->mlb_empresa_id) : null;
        $company = $l->company_id ? Company::find($l->company_id) : null;
        if ($empresa === null && $company === null) {
            return null;
        }

        return $this->daTela([
            'mlb_empresa' => $empresa,
            'company' => $company,
            'chave' => $empresa?->chaveContaMl() ?? $company->chaveContaMl(),
        ]);
    }
}
