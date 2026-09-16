<?php

namespace App\Services\Fechamento;

use App\Models\Company;
use App\Models\CompanyGroup;
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
 *
 * ## Quick 260916-onn — "não participa do fechamento" (decisão humana)
 *
 * Antes da regra da data, `separar()` tira quem foi MARCADO como não
 * participante — caso a caso, para quem não tem contrato progressivo:
 *
 * - a própria empresa marcada (`companies.fora_do_fechamento`);
 * - o grupo dela marcado, ou o grupo de cobrança acima dele (`parent_id`,
 *   Fase 143) marcado — o grupo inteiro deixa de participar.
 *
 * Quem sai por decisão vai para a lista `fora_por_decisao`, SEPARADA de
 * `fora` (data), para o resumo dizer os dois motivos. Uma empresa marcada e
 * também com início depois do mês aparece UMA vez só, por decisão.
 *
 * A marcação é lida em lote (três consultas por chamada, nunca uma por
 * empresa) direto das tabelas — não depende de quais colunas o chamador pôs
 * no eager loading de `grupo`/`grupo.pai` (a tela seleciona colunas).
 *
 * ⛔ Só o fechamento. NPS, desempenho, carteira e bônus não leem a marcação.
 */
class FechamentoEmpresasDoMes
{
    public const ENTRA    = 'entra';
    public const PENDENTE = 'pendente';
    public const FORA     = 'fora';

    /** Quick 260916-onn — fora por decisão de alguém (marcação), não pela data. */
    public const FORA_POR_DECISAO = 'fora_por_decisao';

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
     * `fora_por_decisao` (Quick 260916-onn): cada item é
     * `['company' => Company, 'origem' => 'empresa'|'grupo', 'grupo_id' => ?int,
     * 'grupo_nome' => ?string, 'motivo' => ?string]` — `origem = grupo` quando
     * quem foi marcado é o grupo da empresa ou o grupo de cobrança acima dele
     * (`grupo_id`/`grupo_nome` apontam para o grupo MARCADO). Empresa marcada
     * vence o grupo marcado (o motivo mais específico).
     *
     * `$idsSempreDentro` vale também para a marcação: mês fechado mantém
     * quem está gravado até ser refeito (mesma decisão do 260915-jpr).
     *
     * @param  Collection<int, Company>  $companies
     * @param  iterable<int>  $idsSempreDentro
     * @return array{entram: Collection<int, Company>, pendentes: Collection<int, Company>, fora: Collection<int, Company>, fora_por_decisao: Collection<int, array>}
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

        $idsFora        = [];
        $pendentes      = [];
        $fora           = [];
        $foraPorDecisao = [];

        $decisoes = $this->decisoesDeFora($companies);

        foreach ($companies as $company) {
            $id = (int) $company->id;

            // Quick 260916-onn — decisão humana vem ANTES da data: quem foi
            // marcado sai uma vez só, com um motivo só.
            $decisao = $this->decisaoDaEmpresa($company, $decisoes);
            if ($decisao !== null && ! isset($sempre[$id])) {
                $foraPorDecisao[] = ['company' => $company] + $decisao;
                $idsFora[$id]     = true;

                continue;
            }

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
            'fora_por_decisao' => collect($foraPorDecisao)->values(),
        ];
    }

    /**
     * Quick 260916-onn — a marcação lida em LOTE: empresas marcadas, grupos
     * marcados e o pai de cada subgrupo. Três consultas por chamada, nunca
     * uma por empresa. Coleção vazia não consulta nada.
     *
     * @param  Collection<int, Company>  $companies
     * @return array{empresas: array<int, ?string>, grupos: array<int, array{nome: string, motivo: ?string}>, pais: array<int, int>}
     */
    private function decisoesDeFora(Collection $companies): array
    {
        $vazio = ['empresas' => [], 'grupos' => [], 'pais' => []];

        if ($companies->isEmpty()) {
            return $vazio;
        }

        $empresas = Company::query()
            ->where('fora_do_fechamento', true)
            ->pluck('fora_do_fechamento_motivo', 'id')
            ->all();

        $grupos = CompanyGroup::query()
            ->where('fora_do_fechamento', true)
            ->get(['id', 'name', 'fora_do_fechamento_motivo'])
            ->mapWithKeys(fn (CompanyGroup $g) => [(int) $g->id => [
                'nome'   => (string) $g->name,
                'motivo' => $g->fora_do_fechamento_motivo,
            ]])
            ->all();

        // Sem grupo marcado, o pai de ninguém importa.
        $pais = $grupos === []
            ? []
            : CompanyGroup::query()->whereNotNull('parent_id')->pluck('parent_id', 'id')->map(fn ($p) => (int) $p)->all();

        return ['empresas' => $empresas, 'grupos' => $grupos, 'pais' => $pais];
    }

    /**
     * Quick 260916-onn — motivo de a empresa não participar, ou null. A
     * árvore de cobrança tem UM nível (Fase 143): basta olhar o grupo e o pai.
     *
     * @return array{origem: string, grupo_id: ?int, grupo_nome: ?string, motivo: ?string}|null
     */
    private function decisaoDaEmpresa(Company $company, array $decisoes): ?array
    {
        $id = (int) $company->id;

        if (array_key_exists($id, $decisoes['empresas'])) {
            return [
                'origem'     => 'empresa',
                'grupo_id'   => null,
                'grupo_nome' => null,
                'motivo'     => $decisoes['empresas'][$id],
            ];
        }

        $grupoId = $company->company_group_id !== null ? (int) $company->company_group_id : null;
        if ($grupoId === null || $decisoes['grupos'] === []) {
            return null;
        }

        foreach ([$grupoId, $decisoes['pais'][$grupoId] ?? null] as $candidato) {
            if ($candidato !== null && isset($decisoes['grupos'][$candidato])) {
                return [
                    'origem'     => 'grupo',
                    'grupo_id'   => $candidato,
                    'grupo_nome' => $decisoes['grupos'][$candidato]['nome'],
                    'motivo'     => $decisoes['grupos'][$candidato]['motivo'],
                ];
            }
        }

        return null;
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
