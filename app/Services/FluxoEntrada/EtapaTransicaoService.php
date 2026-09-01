<?php

namespace App\Services\FluxoEntrada;

use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fase 137 (plano 03) — o ponto ÚNICO de escrita de `companies.etapa`
 * (D-12). Espelha o precedente já existente em
 * `App\Services\Contratos\GatilhoContratoAdministrativoService`: um par
 * puro/efeito, `podeTransicionar()` × `transicionar()`, onde a MESMA régua
 * serve tanto à recusa programática (ETAPA-06) quanto, em fases futuras, ao
 * botão desabilitado na UI — nunca duas fontes de verdade para a mesma regra.
 *
 * ⚠️ Este serviço NÃO tem chamador de produção nesta fase, de propósito —
 * é o precedente literal do `App\Services\Operacional\EmpresaOperacionalRouter`
 * (Fase 124): "separa o risco de escrever o service novo do risco de trocar
 * o caminho de produção". Quem pluga gatilho real é a Fase 138 (webhook →
 * etapa 1), a Fase 139 (FINALIZAR → etapa 5), a Fase 141 (distribuição →
 * etapa 6) e a Fase 142 (onboarding → 7/8/9). A prova desta fase é o teste
 * unitário (`tests/Unit/Phase137/EtapaTransicaoServiceTest.php`), não uma
 * rota.
 *
 * A etapa NUNCA é derivada de estado externo (D-11): quem detém o estado
 * externo (Clicksign, motor de onboarding, tela da Coordenação) é quem
 * chama `transicionar()`. Se a etapa fosse derivada, ela não estaria
 * realmente armazenada, e a Fase 143 (HIST-03, "quanto tempo em cada
 * etapa") não teria o instante real da transição — só um recálculo.
 *
 * Regra de domínio DECLARADA aqui, mas NÃO implementada nesta fase
 * (D-09/D-10): onboarding nasce por contrato
 * (`OnboardingEngineService::criarParaContrato()`), então uma empresa com
 * dois serviços tem dois onboardings, e a etapa é uma só, em `companies`.
 * **Manda o onboarding mais atrasado** — a empresa só chega em
 * `onboarding_concluido` quando TODOS os onboardings considerados
 * concluírem. Quem liga onboarding a etapa é a Fase 142; o recorte exato de
 * "onboardings considerados" nasce lá, dentro deste MESMO serviço, nunca ad
 * hoc num controller.
 */
class EtapaTransicaoService
{
    /**
     * Tabela explícita de transições permitidas (D-14 — a ordem do §10 é o
     * domínio, não uma corrente `+1` rígida; o salto 2→4 é o exemplo já
     * conhecido). Chave `''` representa origem `NULL` (empresa legada,
     * D-03) — PHP funde toda chave `null` de array em `''`; a chave já
     * nasce escrita como string vazia aqui, de propósito, para não
     * depender do cast implícito.
     *
     * Requisitos externos por destino (ex.: "destino 4 exige contrato
     * assinado") são das Fases 138-142 e entram AQUI dentro, na checagem de
     * `podeTransicionar()` — nunca num controller.
     */
    private const TRANSICOES_PERMITIDAS = [
        ''                                        => [Company::ETAPA_AGUARDANDO_ADMINISTRATIVO],
        Company::ETAPA_AGUARDANDO_ADMINISTRATIVO  => [Company::ETAPA_ADMINISTRATIVO_ANDAMENTO],
        // Salto 2→4 (empresa `isento` no gate da v22.0 — nenhum serviço
        // ativo exige contrato, ex.: 100% Polos): não passa por
        // `aguardando_assinatura`.
        Company::ETAPA_ADMINISTRATIVO_ANDAMENTO   => [
            Company::ETAPA_AGUARDANDO_ASSINATURA,
            Company::ETAPA_ADMINISTRATIVO_CONCLUIDO,
        ],
        Company::ETAPA_AGUARDANDO_ASSINATURA      => [Company::ETAPA_ADMINISTRATIVO_CONCLUIDO],
        Company::ETAPA_ADMINISTRATIVO_CONCLUIDO   => [Company::ETAPA_AGUARDANDO_DISTRIBUICAO],
        Company::ETAPA_AGUARDANDO_DISTRIBUICAO    => [Company::ETAPA_AGUARDANDO_ONBOARDING],
        Company::ETAPA_AGUARDANDO_ONBOARDING      => [Company::ETAPA_ONBOARDING_ANDAMENTO],
        Company::ETAPA_ONBOARDING_ANDAMENTO       => [Company::ETAPA_ONBOARDING_CONCLUIDO],
        Company::ETAPA_ONBOARDING_CONCLUIDO       => [Company::ETAPA_EM_OPERACAO],
        Company::ETAPA_EM_OPERACAO                => [],
    ];

    /**
     * Régua PURA — zero efeito colateral. Não grava nada, não despacha job,
     * não toca em `Company`. Mesma fonte serve à recusa programática da
     * ETAPA-06 e, em fases futuras, ao botão desabilitado na UI (D-12).
     *
     * Ordem das regras, cada uma retornando cedo com o requisito faltante
     * NOMEADO (a ETAPA-06 proíbe mensagem genérica):
     * 1. destino fora de `Company::ETAPAS` → recusa;
     * 2. destino igual à etapa atual → recusa;
     * 3. destino anterior à atual na ordem de `Company::ETAPAS` →
     *    PERMITIDO como retrocesso (D-15). A exigência de motivo é checada
     *    em `transicionar()`, porque este método é puro e não recebe
     *    motivo;
     * 4. destino não listado em `TRANSICOES_PERMITIDAS` para a origem atual
     *    → recusa, nomeando origem, destino e os destinos aceitos;
     * 5. caso contrário, permitido (avanço normal).
     *
     * @return array{permitido: bool, retrocesso: bool, requisito_faltante: ?string}
     */
    public function podeTransicionar(Company $company, string $etapaDestino): array
    {
        // 1. Destino precisa ser um dos 9 valores do vocabulário travado
        // (D-01). String arbitrária nunca chega além daqui.
        if (! in_array($etapaDestino, Company::ETAPAS, true)) {
            return [
                'permitido' => false,
                'retrocesso' => false,
                'requisito_faltante' => "Etapa de destino desconhecida: '{$etapaDestino}'. Valores aceitos: " . implode(', ', Company::ETAPAS) . '.',
            ];
        }

        // 2. Empresa já está lá — nada a fazer.
        if ($company->etapa === $etapaDestino) {
            return [
                'permitido' => false,
                'retrocesso' => false,
                'requisito_faltante' => "A empresa já está na etapa '{$etapaDestino}'.",
            ];
        }

        $indiceAtual = $company->etapa !== null ? array_search($company->etapa, Company::ETAPAS, true) : null;
        $indiceDestino = array_search($etapaDestino, Company::ETAPAS, true);

        // 3. Retrocesso (D-15): destino vem ANTES da etapa atual na ordem do
        // §10. Permitido por construção — proibir de todo transformaria um
        // FINALIZAR clicado por engano num problema irreversível de dado de
        // produção com cliente real. Retrocesso não faz parte do fluxo
        // normal e nunca é automático: a exigência de motivo é de
        // `transicionar()`, não daqui.
        if ($indiceAtual !== null && $indiceDestino < $indiceAtual) {
            return [
                'permitido' => true,
                'retrocesso' => true,
                'requisito_faltante' => null,
            ];
        }

        // 4. Avanço: precisa estar na tabela explícita de transições
        // permitidas a partir da origem atual (D-14). Requisitos externos
        // por destino específico (ex.: "destino 4 exige contrato assinado")
        // são das Fases 138-142 e entrariam AQUI, nunca num controller.
        $origemChave = $company->etapa ?? '';
        $destinosAceitos = self::TRANSICOES_PERMITIDAS[$origemChave] ?? [];
        $origemLabel = $company->etapa ?? '(sem etapa)';

        if (! in_array($etapaDestino, $destinosAceitos, true)) {
            $listaDestinos = $destinosAceitos === [] ? '(nenhum — etapa terminal)' : implode(', ', $destinosAceitos);

            return [
                'permitido' => false,
                'retrocesso' => false,
                'requisito_faltante' => "Transição não permitida de '{$origemLabel}' para '{$etapaDestino}'. Destinos aceitos a partir de '{$origemLabel}': {$listaDestinos}.",
            ];
        }

        // 5. Avanço normal, aceito pela tabela.
        return [
            'permitido' => true,
            'retrocesso' => false,
            'requisito_faltante' => null,
        ];
    }

    /**
     * ÚNICA escrita de `companies.etapa` no sistema (D-12). Chama
     * `podeTransicionar()` primeiro — nunca condicional só na UI — e
     * propaga o `requisito_faltante` sem inventar mensagem nova quando a
     * transição é recusada.
     *
     * D-13: recebe o `User` que agiu e o registra como ator da linha de
     * histórico. ⚠️ Segurança (T-137-02): o parâmetro é tipado `User`, NÃO
     * `int` — todo chamador futuro deve passar `$request->user()` /
     * `auth()->user()`, NUNCA um `user_id` cru vindo do corpo da
     * requisição.
     *
     * D-15: se `podeTransicionar()` marcar retrocesso e `$motivo` vier
     * vazio (ou só espaço), a transição é recusada aqui — retrocesso nunca
     * é automático.
     *
     * Escrita da coluna + criação do histórico dentro de UMA transação
     * (T-137-11): não existe etapa gravada sem linha de histórico.
     *
     * @return array{status: string, de: ?string, para: string, requisito_faltante: ?string, erro: ?string}
     */
    public function transicionar(Company $company, string $etapaDestino, User $por, ?string $motivo = null): array
    {
        $avaliacao = $this->podeTransicionar($company, $etapaDestino);

        if (! $avaliacao['permitido']) {
            return [
                'status' => 'recusado',
                'de' => $company->etapa,
                'para' => $etapaDestino,
                'requisito_faltante' => $avaliacao['requisito_faltante'],
                'erro' => null,
            ];
        }

        // D-15: retrocesso não faz parte do fluxo normal e nunca é
        // automático — exige motivo não vazio, checado aqui porque
        // `podeTransicionar()` é puro e não recebe motivo.
        if ($avaliacao['retrocesso'] && trim((string) $motivo) === '') {
            return [
                'status' => 'recusado',
                'de' => $company->etapa,
                'para' => $etapaDestino,
                'requisito_faltante' => 'Retrocesso exige motivo obrigatório (D-15) — nenhum motivo foi informado.',
                'erro' => null,
            ];
        }

        $etapaAnterior = $company->etapa;

        try {
            DB::transaction(function () use ($company, $etapaDestino, $por, $motivo, $avaliacao, $etapaAnterior) {
                // Pitfall 6 (137-RESEARCH.md): grava SÓ `etapa` neste
                // update(). `Company` tem
                // #[ObservedBy(CompanyGatilhoContratoObserver::class)], que
                // reage a `wasChanged(CAMPOS_GATILHO)` com
                // CAMPOS_GATILHO = ['email_cliente', 'cnpj', 'nome_contato'].
                // `etapa` não está nessa lista, então gravar só `etapa` é
                // seguro — o gate administrativo não dispara de carona. Se
                // algum dia a transição precisar gravar outro campo (ex.:
                // dados de onboarding na Fase 142), isso vai num `save()`
                // SEPARADO, nunca neste mesmo update().
                $company->update(['etapa' => $etapaDestino]);

                CompanyEtapaTransicao::create([
                    'company_id' => $company->id,
                    'etapa_anterior' => $etapaAnterior,
                    'etapa_nova' => $etapaDestino,
                    'user_id' => $por->id,
                    'motivo' => $motivo,
                    'retrocesso' => $avaliacao['retrocesso'],
                ]);
            });

            return [
                'status' => 'transicionado',
                'de' => $etapaAnterior,
                'para' => $etapaDestino,
                'requisito_faltante' => null,
                'erro' => null,
            ];
        } catch (\Throwable $e) {
            Log::error("[EtapaTransicao] falha ao transicionar empresa {$company->id} ({$company->name}) de '{$etapaAnterior}' para '{$etapaDestino}': {$e->getMessage()}");

            return [
                'status' => 'erro',
                'de' => $etapaAnterior,
                'para' => $etapaDestino,
                'requisito_faltante' => null,
                'erro' => $e->getMessage(),
            ];
        }
    }

    /**
     * Escrita em MASSA de `Company::ETAPA_EM_OPERACAO`, exclusiva do
     * backfill legado do plano 137-05 (ETAPA-02). Existe por um motivo
     * específico: o Success Criteria 2 desta fase exige que não haja outro
     * ponto do código gravando `companies.etapa` — e o backfill precisa
     * escrever em massa. A escrita mora AQUI, nesta mesma classe; o
     * comando do plano 137-05 só decide QUAIS ids (balde 1 do D-04).
     *
     * ⚠️ Deliberadamente NÃO gera histórico em `company_etapa_transicoes`.
     * Backfill não é transição do fluxo — carimbar centenas de linhas de
     * histórico com data de hoje criaria duração fictícia no painel de
     * gargalo da Fase 143 (mesmo raciocínio de D-05). Este método é
     * exclusivo do backfill legado; nenhuma fase futura deve chamá-lo para
     * transição normal — use `transicionar()`.
     */
    public function carimbarBackfill(array $companyIds): int
    {
        if ($companyIds === []) {
            return 0;
        }

        return Company::whereIn('id', $companyIds)->update(['etapa' => Company::ETAPA_EM_OPERACAO]);
    }
}
