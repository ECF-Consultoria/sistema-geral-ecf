<?php

namespace App\Services\Contratos;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Services\Clicksign\ContratoClicksignService;
use App\Services\Comercial\PendenciasComerciaisService;
use Illuminate\Support\Facades\Log;

/**
 * GatilhoContratoAdministrativoService — Fase 128 (plano 03). O ponto ÚNICO
 * de decisão de "esta empresa gera contrato agora?" — o `EmpresaOperacionalRouter`
 * do contrato (Fase 124). Os dois pontos de entrada (plano 04) e o Observer
 * (plano 05) chamam SEMPRE este service, nunca a Clicksign direto, para que
 * o comportamento seja idêntico nos três lugares.
 *
 * ⚠️ Este service roda EM PARALELO ao roteamento operacional
 * (`EmpresaOperacionalRouter`), nunca no lugar dele — são dois portões
 * independentes: um decide se a empresa entra no operacional, este decide
 * se a empresa entra no fluxo administrativo de contrato.
 *
 * Sequência de `avaliar()` (D-02), nesta ordem e por este motivo:
 * 1. Isenção por serviço (D-03/SC0) — empresa 100% Polos nunca é avaliada,
 *    nunca vira pendente, não aparece em lugar nenhum.
 * 2. Reentrância (D-04) — contrato já em andamento? Checado ANTES dos
 *    portões de propósito: "o ideal é nem chegar lá", não confiar só na
 *    trava composta do banco (Fase 127).
 * 3. 1º portão de pendências comerciais (D-02) — `calcularUniversais()`.
 * 4. Elegível — quem chama decide se dispara (`dispararSeElegivel()`) ou só
 *    consulta (`avaliar()`).
 *
 * O 2º portão da D-02 (dados mínimos + configuração ECF) roda DENTRO de
 * `ContratoClicksignService::iniciarParaEmpresa()` — não duplicado aqui.
 */
class GatilhoContratoAdministrativoService
{
    /**
     * Guard de reentrância por REQUEST (não persistido): corta o laço
     * Observer → disparo → gravação → Observer antes de chegar no banco.
     * Indexado por `company_id`.
     *
     * @var array<int, bool>
     */
    private static array $emAvaliacao = [];

    public function __construct(
        private PendenciasComerciaisService $pendencias,
        private ContratoClicksignService $clicksign,
    ) {
    }

    /**
     * Decide, SEM nenhum efeito colateral — não cria `ContratoAssinatura`,
     * não despacha job, não grava nada em `Company`. Ver docblock da classe
     * para a ordem e o motivo de cada passo.
     *
     * @return array{status: string, pendencias: array<int, string>, motivo?: string}
     */
    public function avaliar(Company $company): array
    {
        // 1. Isenção (D-03/SC0). Empresa cujos serviços ativos são TODOS
        // isentos (ex.: só Polos) morre aqui: não é avaliada, não é
        // pendente, não aparece em lugar nenhum.
        //
        // Fix pós-verificação (achado WARNING da revisão/verificação da
        // Fase 128): `Collection::contains()` numa coleção VAZIA devolve
        // `false`, então a checagem original classificava "zero contrato
        // ativo" como isento — igual a "só Polos". Não é o mesmo caso: zero
        // serviço é `sem_servico` (pendência universal da D-01), e precisa
        // cair no 1º portão (`calcularUniversais()`) como qualquer outra
        // empresa sem dado, não ser tratada como isenção. Só existe isenção
        // quando HÁ contrato ativo e nenhum deles exige contrato.
        $contratosServico = $company->contratosServico()->where('ativo', true)->with('servico')->get();

        if ($contratosServico->isNotEmpty()) {
            $existeServicoQueExigeContrato = $contratosServico->contains(
                fn ($cs) => optional($cs->servico)?->exigeContrato() === true
            );

            if (!$existeServicoQueExigeContrato) {
                return ['status' => 'isento', 'pendencias' => [], 'motivo' => 'nenhum servico exige contrato'];
            }
        }

        // 2. Reentrância (D-04): "o ideal é nem chegar lá" — checagem de
        // código ANTES dos portões, não confiando só na trava composta do
        // banco (Fase 127).
        if (ContratoAssinatura::emAndamentoDaEmpresa($company->id) !== null) {
            return ['status' => 'ja_em_andamento', 'pendencias' => []];
        }

        // 3. 1º portão (D-02): pendências comerciais universais.
        $slugs = $this->pendencias->calcularUniversais($company);

        if ($slugs !== []) {
            return ['status' => 'aguardando_comercial', 'pendencias' => $slugs];
        }

        // 4. Elegível — o 2º portão (dados mínimos + configuração ECF) roda
        // dentro do `ContratoClicksignService`, quem for disparar.
        return ['status' => 'elegivel', 'pendencias' => []];
    }

    /**
     * Estado `aguardando_comercial` (Success Criteria 3) — DERIVADO, nunca
     * persistido. A "marca" é a combinação de serviço que exige contrato +
     * pendência aberta + ausência de `ContratoAssinatura`. Nada é gravado em
     * `companies.status`, que já tem semântica própria em uso
     * (`pendente`/`ativo`) e colidiria.
     */
    public function aguardandoComercial(Company $company): bool
    {
        return $this->avaliar($company)['status'] === 'aguardando_comercial';
    }

    /**
     * Método que os controllers (plano 04) e o Observer (plano 05) chamam.
     * Zero chamada à Clicksign quando a empresa não está `elegivel`
     * (Success Criteria 3).
     *
     * ⚠️ Invariante da fase em código: uma falha do gate NUNCA se propaga
     * para quem cadastrou a empresa. Todo o corpo roda dentro de
     * `try/catch (\Throwable)`; o catch loga e devolve `status => 'erro'`,
     * nunca relança.
     *
     * @return array{status: string, pendencias?: array<int, string>, resultado?: array, erro?: string}
     */
    public function dispararSeElegivel(Company $company): array
    {
        // Guard de reentrância por request (T-128-05): corta o laço
        // Observer → disparo → gravação → Observer antes de chegar no banco.
        if (self::$emAvaliacao[$company->id] ?? false) {
            return ['status' => 'reentrancia_ignorada'];
        }

        self::$emAvaliacao[$company->id] = true;

        try {
            $avaliacao = $this->avaliar($company);

            if ($avaliacao['status'] !== 'elegivel') {
                Log::info("[GatilhoContrato] empresa {$company->id} ({$company->name}) não elegível — status={$avaliacao['status']}");

                return $avaliacao;
            }

            $resultado = $this->clicksign->iniciarParaEmpresa($company);

            return ['status' => 'disparado', 'resultado' => $resultado];
        } catch (\Throwable $e) {
            Log::error("[GatilhoContrato] falha ao avaliar/disparar contrato para empresa {$company->id} ({$company->name}): {$e->getMessage()}");

            return ['status' => 'erro', 'erro' => $e->getMessage()];
        } finally {
            unset(self::$emAvaliacao[$company->id]);
        }
    }
}
