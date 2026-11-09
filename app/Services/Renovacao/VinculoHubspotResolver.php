<?php

namespace App\Services\Renovacao;

use App\Models\Company;
use App\Services\Hubspot\HubspotErroApi;
use App\Services\HubspotApiClient;

/**
 * Renovação (09/11/2026) — descobre a empresa e o contato do HubSpot de uma
 * empresa do ECF que ainda não tem esse vínculo gravado.
 *
 * Por que existe: medido em produção em 09/11/2026, das 21 empresas na janela
 * de renovação NENHUMA tinha `hubspot_company_id`. O Comercial associa os
 * negócios só ao CONTATO (os negócios originais não têm empresa associada) e
 * os clientes antigos nem existem no HubSpot. Decisão do usuário (09/11):
 * o negócio de renovação vai associado à empresa quando ela existe, ao
 * contato quando só ele existe, e sem associação quando não há nada.
 *
 * Ordem (só caminhos determinísticos — nunca palpite por nome):
 *   contato → contatos do negócio original; senão contato com o mesmo e-mail
 *             do cadastro (só quando há UM resultado);
 *   empresa → empresas do negócio original (só quando há UMA); senão a
 *             empresa principal do contato achado.
 *
 * Só PREENCHE campo vazio — nunca troca vínculo já gravado (pode ter sido
 * corrigido à mão). Grava por query com `whereNull`, sem disparar os
 * observers de Company (o `CompanyGatilhoObserver` reage a save do cadastro
 * e não tem nada a ver com este vínculo).
 */
class VinculoHubspotResolver
{
    public function __construct(
        private HubspotApiClient $hubspot,
        private NegocioOriginalResolver $negocioOriginal,
    ) {}

    /**
     * @return array{
     *   company_id: ?string, contact_id: ?string,
     *   fonte_empresa: ?string, fonte_contato: ?string,
     *   gravou: list<string>, aviso: ?string
     * }
     *
     * @throws HubspotErroApi quando a leitura do HubSpot falha
     */
    public function resolver(Company $company, bool $aplicar): array
    {
        $companyId = filled($company->hubspot_company_id) ? (string) $company->hubspot_company_id : null;
        $contactId = filled($company->hubspot_contact_id) ? (string) $company->hubspot_contact_id : null;
        $fonteEmpresa = $companyId ? 'cadastro' : null;
        $fonteContato = $contactId ? 'cadastro' : null;
        $aviso = null;

        $dealOriginal = $this->negocioOriginal->resolver($company);

        if ($contactId === null && $dealOriginal !== null) {
            $ids = $this->hubspot->fetchAssociatedContactIds($dealOriginal);
            if ($ids !== []) {
                // Mais de um contato no negócio: o primeiro é o que o webhook
                // de venda também usa como principal na falta de critério.
                $contactId    = $ids[0];
                $fonteContato = 'negocio_original';
            }
        }

        $empresaDoContato = null;
        if ($contactId === null && filled($company->email_cliente)) {
            $achados = $this->hubspot->buscarContatosPorEmail(trim((string) $company->email_cliente));
            if (count($achados) === 1) {
                $contactId        = $achados[0]['id'];
                $empresaDoContato = $achados[0]['company_id'];
                $fonteContato     = 'email';
            } elseif (count($achados) > 1) {
                $aviso = 'Mais de um contato no HubSpot com o e-mail do cadastro — não escolhi nenhum.';
            }
        }

        if ($companyId === null && $dealOriginal !== null) {
            $ids = $this->hubspot->fetchAssociatedCompanyIds($dealOriginal);
            if (count($ids) === 1) {
                $companyId    = $ids[0];
                $fonteEmpresa = 'negocio_original';
            }
        }

        if ($companyId === null && $contactId !== null) {
            if ($empresaDoContato === null && $fonteContato !== 'email') {
                $empresaDoContato = $this->empresaPrincipalDoContato($contactId);
            }
            if ($empresaDoContato !== null) {
                $companyId    = $empresaDoContato;
                $fonteEmpresa = 'contato';
            }
        }

        $gravou = [];
        if ($aplicar) {
            if ($companyId !== null && $fonteEmpresa !== 'cadastro') {
                $gravou = array_merge($gravou, $this->preencherSeVazio($company, 'hubspot_company_id', $companyId));
            }
            if ($contactId !== null && $fonteContato !== 'cadastro') {
                $gravou = array_merge($gravou, $this->preencherSeVazio($company, 'hubspot_contact_id', $contactId));
            }
        }

        return [
            'company_id'    => $companyId,
            'contact_id'    => $contactId,
            'fonte_empresa' => $fonteEmpresa,
            'fonte_contato' => $fonteContato,
            'gravou'        => $gravou,
            'aviso'         => $aviso,
        ];
    }

    private function empresaPrincipalDoContato(string $contactId): ?string
    {
        $ids = array_values(array_map(
            static fn ($r) => (string) ($r['id'] ?? $r['toObjectId'] ?? ''),
            $this->hubspot->fetchAssociations('contacts', $contactId, 'companies')
        ));
        $ids = array_values(array_filter($ids));

        return count($ids) === 1 ? $ids[0] : null;
    }

    /** @return list<string> */
    private function preencherSeVazio(Company $company, string $coluna, string $valor): array
    {
        $alteradas = Company::query()
            ->whereKey($company->id)
            ->where(fn ($q) => $q->whereNull($coluna)->orWhere($coluna, ''))
            ->update([$coluna => $valor]);

        if ($alteradas > 0) {
            $company->setAttribute($coluna, $valor);
            $company->syncOriginalAttribute($coluna);

            return [$coluna];
        }

        return [];
    }
}
