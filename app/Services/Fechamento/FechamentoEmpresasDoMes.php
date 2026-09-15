<?php

namespace App\Services\Fechamento;

use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Quick 260915-jpr — ponto ÚNICO de verdade de "quais empresas entram no
 * fechamento de um mês".
 *
 * Antes, cada um dos quatro lugares que montam a lista (o comando
 * `fechamento:consolidar-mes`, a tela `AdminController::fechamento()` e o
 * relatório geral, o job de e-mail e `fechamento:comparar-mensalidade`)
 * usava `Company::where('active', true)` — toda empresa ativa HOJE, mesmo
 * que só tenha virado cliente depois do mês fechado (julho/2026 tinha 39
 * assim). Os quatro passam a filtrar por aqui, senão tela, relatório e
 * comparativo divergiriam do que o comando grava.
 *
 * A data é `contratos_servico.data_contratacao` (início de contrato oficial
 * — o PDF do contrato já a usa como início da vigência). ⛔ NUNCA
 * `companies.created_at`: o usuário recusou esse critério em 2026-09-15 —
 * há clientes antigos cadastrados bem depois (reimport de 25/05).
 *
 * Regra, com `fim` = último dia do mês (nunca "hoje" — contrato que começa no
 * fim do mês corrente já entra no mês corrente):
 *
 * | contratos ATIVOS da empresa                              | resultado |
 * |----------------------------------------------------------|-----------|
 * | nenhum                                                   | entra (regressão zero) |
 * | algum com data ≤ fim (inclusive começo no meio do mês)   | entra     |
 * | nenhum com data ≤ fim e algum SEM data                   | entra, como pendência |
 * | todos com data, todas depois de fim                      | sai       |
 *
 * ⚠️ Na dúvida, entra. A ÚNICA forma de sair é ter data em TODOS os
 * contratos ativos e todas depois do mês — sumir com cobrança sem ninguém
 * perceber é o erro que esta regra não pode introduzir.
 *
 * Não faz consulta: lê `$company->contratosServico` já carregado pelos
 * chamadores (todos já fazem eager loading dos contratos ativos). Se a
 * relação não vier carregada, carrega em lote uma vez só.
 */
class FechamentoEmpresasDoMes
{
    public const ENTRA    = 'entra';
    public const PENDENTE = 'pendente';
    public const FORA     = 'fora';

    /**
     * Último dia do mês de referência. Aceita 'Y-m', 'Y-m-d' ou data.
     * NUNCA ancorar 'Y-m' sem o dia (estoura para o mês seguinte quando o
     * mês alvo tem menos dias que hoje) — mesma armadilha do comando.
     */
    public static function fimDoMes(CarbonInterface|string $mes): Carbon
    {
        if ($mes instanceof CarbonInterface) {
            return Carbon::parse($mes->format('Y-m-01'))->endOfMonth();
        }

        $base = strlen($mes) === 7 ? $mes.'-01' : substr($mes, 0, 7).'-01';

        return Carbon::createFromFormat('Y-m-d', $base)->startOfDay()->endOfMonth();
    }

    /**
     * Situação de UMA empresa no mês que termina em `$fim`.
     */
    public function situacao(Company $company, CarbonInterface $fim): string
    {
        $contratos = $company->contratosServico->filter(fn ($ct) => (bool) $ct->ativo);

        // Sem contrato ativo: entra como sempre entrou (regressão zero).
        if ($contratos->isEmpty()) {
            return self::ENTRA;
        }

        $fimStr  = $fim->toDateString();
        $semData = false;

        foreach ($contratos as $contrato) {
            $inicio = $this->dataDeInicio($contrato->data_contratacao);

            if ($inicio === null) {
                $semData = true;

                continue;
            }

            // Comparação por string 'Y-m-d': hora nenhuma interfere. Começo
            // no meio do mês entra — o fechamento cobra o mês, não calcula
            // proporcional.
            if ($inicio <= $fimStr) {
                return self::ENTRA;
            }
        }

        // Nenhum contrato provadamente começou até o fim do mês. Se algum
        // não tem data, não dá para provar que a empresa ainda não era
        // cliente — entra, e aparece como pendência.
        return $semData ? self::PENDENTE : self::FORA;
    }

    /**
     * Separa a coleção de empresas para o mês.
     *
     * `$idsSempreDentro`: empresas que entram independente da data — usado
     * pelos leitores de mês FECHADO para quem já tem linha gravada. O mês
     * congelado não muda sozinho (D-11 da Fase 137): enquanto ele não for
     * refeito, quem está gravado continua aparecendo e somando no total.
     *
     * @param  Collection<int, Company>  $companies
     * @param  iterable<int>  $idsSempreDentro
     * @return array{entram: Collection<int, Company>, pendentes: Collection<int, Company>, fora: Collection<int, Company>}
     */
    public function separar(Collection $companies, CarbonInterface|string $mes, iterable $idsSempreDentro = []): array
    {
        $fim = self::fimDoMes($mes);

        if ($companies instanceof \Illuminate\Database\Eloquent\Collection) {
            $companies->loadMissing(['contratosServico' => fn ($q) => $q->where('ativo', true)]);
        }

        $sempre = [];
        foreach ($idsSempreDentro as $id) {
            $sempre[(int) $id] = true;
        }

        $idsFora   = [];
        $pendentes = [];
        $fora      = [];

        foreach ($companies as $company) {
            $situacao = $this->situacao($company, $fim);

            if ($situacao === self::PENDENTE) {
                $pendentes[] = $company;
            }

            if ($situacao === self::FORA && ! isset($sempre[(int) $company->id])) {
                $fora[]                       = $company;
                $idsFora[(int) $company->id] = true;
            }
        }

        // Mantém o tipo e a ordem da coleção de entrada (Eloquent\Collection
        // nos chamadores) — `filter()`/`values()` preservam a classe.
        return [
            'entram'    => $companies->filter(fn ($c) => ! isset($idsFora[(int) $c->id]))->values(),
            'pendentes' => collect($pendentes)->values(),
            'fora'      => collect($fora)->values(),
        ];
    }

    /**
     * Só as empresas que entram — atalho para quem não precisa das listas.
     *
     * @param  Collection<int, Company>  $companies
     * @param  iterable<int>  $idsSempreDentro
     * @return Collection<int, Company>
     */
    public function filtrar(Collection $companies, CarbonInterface|string $mes, iterable $idsSempreDentro = []): Collection
    {
        return $this->separar($companies, $mes, $idsSempreDentro)['entram'];
    }

    /**
     * Data de início normalizada para 'Y-m-d', ou null quando não há data
     * utilizável. Data zerada do MySQL ('0000-00-00') vira ano negativo no
     * cast do Carbon — tratada como SEM data (na dúvida, entra).
     */
    private function dataDeInicio(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            $data = $valor instanceof CarbonInterface ? $valor : Carbon::parse($valor);
        } catch (\Throwable) {
            return null;
        }

        if ($data->year < 1900) {
            return null;
        }

        return $data->toDateString();
    }
}
