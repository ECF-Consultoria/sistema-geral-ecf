<?php

namespace App\Services\BoasVindas;

use App\Models\BoasVindasTemplate;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\PortalUsuario;

/**
 * MensagemBoasVindasService — monta a mensagem de boas-vindas da empresa, com
 * os 6 blocos do §4 preenchidos (Fase 153, COMUNIC-01/02/03).
 *
 * **A montagem é do BACKEND** (D-B). Não é detalhe de organização: a
 * substituição dos placeholders do Polos vive no JSX (`ImplModal.jsx`), sem
 * nenhum teste automatizado, e é justamente o pedaço onde um link errado tem
 * consequência fora do sistema. Aqui o texto sai pronto do servidor, coberto por
 * PHPUnit, e o front só exibe e copia — mesma disciplina da D-04 da Fase 152.
 *
 * `{link_oauth}` leva ao onboarding autenticado, onde o cliente escolhe a
 * empresa e conecta o Mercado Livre. Nunca divulgar a rota interna do admin.
 *
 * Esta classe **não** toca a mensagem do Polos (D-A): aquela continua em
 * `mlb_configuracoes.implementacao_defaults` e é montada pelo `ImplModal.jsx`
 * como sempre.
 */
class MensagemBoasVindasService
{
    /**
     * Monta a mensagem da empresa.
     *
     * @return array{
     *   texto: string,
     *   template_servico_id: ?int,
     *   template_servico_nome: ?string,
     *   pendencias: array<int, string>,
     *   pronta: bool,
     * }
     *
     * `pendencias` lista, em português, os blocos que ficariam vazios; `pronta`
     * é `false` quando há qualquer uma. A tela usa isso para avisar em vez de
     * entregar para copiar um texto com "Link: " pendurado — mesmo princípio do
     * `requisito_faltante` da Fase 152: dizer o que falta, não devolver um
     * resultado mudo.
     */
    public function paraEmpresa(Company $company): array
    {
        $template = $this->templateDaEmpresa($company);
        $valores  = $this->valores($company);

        $texto = $template['texto'];
        foreach ($valores['substituicoes'] as $chave => $valor) {
            // split/join em vez de str_replace com arrays: deixa explícito que
            // TODAS as ocorrências de cada chave são trocadas, uma chave por vez.
            $texto = implode($valor ?? '', explode($chave, $texto));
        }

        return [
            'texto'                 => $texto,
            'template_servico_id'   => $template['servico_id'],
            'template_servico_nome' => $template['servico_nome'],
            'pendencias'            => $valores['pendencias'],
            'pronta'                => $valores['pendencias'] === [],
        ];
    }

    /**
     * Escolhe o template da empresa (D-A + D-D).
     *
     * Entre os serviços **ativos** que têm template próprio, vence o de **menor
     * `servicos.id`**; sem nenhum, cai no genérico; sem genérico cadastrado,
     * usa o texto padrão da constante.
     *
     * O desempate por menor id é deliberado e não é detalhe: sem ele, uma
     * empresa com dois serviços receberia mensagens diferentes entre duas
     * requisições idênticas, conforme a ordem de carga da coleção. É a mesma
     * disciplina da D-12 da Fase 152. Em particular, **não** se reusa o
     * `setorDominante` de `ComercialEntradaController` — aquele é um `->first()`
     * sobre coleção sem ordenação, exatamente o que se está evitando aqui.
     *
     * @return array{texto: string, servico_id: ?int, servico_nome: ?string}
     */
    private function templateDaEmpresa(Company $company): array
    {
        $servicoIds = ContratoServico::query()
            ->where('company_id', $company->id)
            ->where('ativo', true)
            ->pluck('servico_id')
            ->filter()
            ->unique()
            ->all();

        if ($servicoIds !== []) {
            $doServico = BoasVindasTemplate::with('servico')
                ->whereIn('servico_id', $servicoIds)
                ->orderBy('servico_id')
                ->first();

            if ($doServico) {
                return [
                    'texto'        => $doServico->texto,
                    'servico_id'   => $doServico->servico_id,
                    'servico_nome' => $doServico->servico?->nome,
                ];
            }
        }

        $generico = BoasVindasTemplate::generico();

        return [
            'texto'        => $generico?->texto ?? BoasVindasTemplate::TEXTO_GENERICO_PADRAO,
            'servico_id'   => null,
            'servico_nome' => null,
        ];
    }

    /**
     * Resolve os valores dos placeholders e acusa o que falta.
     *
     * @return array{substituicoes: array<string, ?string>, pendencias: array<int, string>}
     */
    private function valores(Company $company): array
    {
        $pendencias = [];

        $emailColaborador = $company->email_colaborador ?: null;
        if ($emailColaborador === null) {
            $pendencias[] = 'E-mail colaborador não preenchido no cadastro da empresa.';
        }

        $linkAdman = config('services.adman.register_url') ?: null;
        if ($linkAdman === null) {
            $pendencias[] = 'Link de cadastro do Adman não configurado no ambiente.';
        }

        $linkSistema = \App\Support\Portal\UrlDoPortal::para('portal.entrada');
        $linkOauth = \App\Support\Portal\UrlDoPortal::para('portal.auth.onboarding');
        if (!PortalUsuario::ativos()->whereHas('empresas', fn ($q) => $q->where('companies.id', $company->id))->exists()) {
            $pendencias[] = 'Cadastre um contato em Acessos do portal antes de enviar as boas-vindas.';
        }

        return [
            'substituicoes' => [
                '{empresa}'            => $company->name,
                '{email_colaborador}'  => $emailColaborador,
                '{link_adman}'         => $linkAdman,
                '{link_oauth}'         => $linkOauth,
                '{link_sistema}'       => $linkSistema,
            ],
            'pendencias' => $pendencias,
        ];
    }
}
