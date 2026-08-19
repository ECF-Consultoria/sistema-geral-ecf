<?php

namespace App\Services\Nps;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\NpsTemplate;
use App\Models\Servico;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * NpsGrupoCoberturaService — responde, ANTES de enviar, a pergunta que a
 * tela do link de NPS de GRUPO precisa fazer: quais empresas do grupo vão
 * receber a nota, e por que as outras não vão (Fase 119.1 Plan 05).
 *
 * Decisões travadas (119.1-CONTEXT.md) que este serviço implementa:
 *  - D3 — a comparação de responsáveis é POR SERVIÇO coberto pelo modelo.
 *    Não precisa bater em TODOS os serviços da empresa, só nos que o
 *    modelo de NPS cobre. Divergência num serviço fora da cobertura do
 *    modelo não exclui ninguém.
 *  - D4 — replicação PARCIAL é o comportamento CORRETO, não erro. Empresa
 *    cujo responsável diverge não recebe a nota e continua valendo 1 até
 *    alguém gerar um link individual para ela.
 *  - D6 — para cada serviço coberto, estrategista E analista precisam ser
 *    a MESMA pessoa em todas as empresas do grupo — os DOIS papéis,
 *    sempre, independente de quais o modelo pergunta. É a leitura mais
 *    restritiva, e é intencional.
 *
 * A REFERÊNCIA da comparação é a MAIOR classe de equivalência de
 * responsáveis do grupo — a MAIORIA — NUNCA a empresa de menor id. Num
 * grupo de 4 em que 3 empresas concordam e 1 diverge, se a divergente
 * tiver o menor id e a referência fosse escolhida por id, o resultado
 * seria o OPOSTO do pedido: excluiria as 3 que concordam e incluiria a
 * única que destoa. Ver `escolherReferencia()`.
 *
 * @see app/Models/Company.php::responsavelDoServicoOuConsolidado()
 * @see app/Services/Nps/NpsElegibilidadeService.php::surveyExistenteNaCompetencia()
 */
class NpsGrupoCoberturaService
{
    /**
     * Os DOIS papéis que D6 exige comparar, sempre — independente de quais
     * o modelo de NPS pergunta. `'consultor'` é o slug do ANALISTA no
     * vínculo responsável×serviço da empresa.
     */
    private const PAPEIS = ['estrategista', 'consultor'];

    /**
     * Tradução do papel para a linguagem da tela (nunca expor o slug cru
     * `'consultor'` ao operador).
     */
    private const PAPEL_LABEL = [
        'estrategista' => 'estrategista',
        'consultor'    => 'analista',
    ];

    public function __construct(private NpsElegibilidadeService $elegibilidade)
    {
    }

    /**
     * @return array{
     *   referencia: ?int,
     *   incluidas: array<int, array{company_id:int, name:string, servico_ids:int[]}>,
     *   excluidas: array<int, array{company_id:int, name:string, motivo:string, servico:?string, papel:?string, quem_cuida:?string}>,
     * }
     */
    public function calcular(CompanyGroup $grupo, NpsTemplate $template, Carbon $mes): array
    {
        $cobertosIds = $template->serviceScopes()->pluck('servicos.id');
        $empresasGrupo = $grupo->companies;

        // Modelo não cobre serviço nenhum — fallback já existente do "NPS
        // Padrão": todas as empresas ATIVAS do grupo entram, sem comparação
        // de responsáveis (não há serviço nenhum sobre o qual comparar).
        if ($cobertosIds->isEmpty()) {
            return $this->calcularSemServicoCoberto($empresasGrupo, $template, $mes);
        }

        [$candidatas, $excluidas] = $this->filtrarCandidatas($empresasGrupo, $cobertosIds);

        // Nenhuma empresa passou do primeiro filtro (inativa ou sem
        // contrato em serviço coberto) — replicação nula é regra, não erro.
        if ($candidatas->isEmpty()) {
            return [
                'referencia' => null,
                'incluidas'  => [],
                'excluidas'  => $excluidas->values()->all(),
            ];
        }

        // Pré-resolver responsáveis por (empresa, serviço, papel) UMA vez —
        // o laço de compatibilidade abaixo é O(n²) sobre as candidatas, e o
        // grupo é pequeno (unidades de empresas); repetir a query dentro do
        // O(n²) é o desperdício a evitar, não o O(n²) em si.
        $resolvidos = $this->resolverResponsaveis($candidatas);

        // 2026-08-18 — empresa sem NENHUM responsável (nenhum papel, nenhum
        // dos serviços aplicáveis) sai ANTES da comparação, com motivo
        // próprio. Dois motivos:
        //  (a) ela nunca poderia entrar no link — a nota não teria a quem ser
        //      atribuída (`NpsSnapshotService` só logaria "responsável
        //      faltante" e a nota ficaria órfã);
        //  (b) se ficasse, poderia virar a REFERÊNCIA da comparação
        //      (`escolherReferencia()` desempata por menor id) e excluir do
        //      link justamente as empresas que TÊM responsável.
        // Antes ela caía em `responsavel_diferente` com `quem_cuida` vazio, e
        // a tela escrevia "é cuidado por outra pessoa ()". É o mesmo
        // princípio de `CompanyGroup::visivelPara()`, que desconsidera a
        // empresa órfã ao decidir se o grupo aparece para a pessoa.
        [$candidatas, $orfas] = $this->separarSemResponsavel($candidatas, $resolvidos);
        $excluidas = $excluidas->merge($orfas);

        if ($candidatas->isEmpty()) {
            return [
                'referencia' => null,
                'incluidas'  => [],
                'excluidas'  => $excluidas->values()->all(),
            ];
        }

        $referenciaIdx = $this->escolherReferencia($candidatas, $resolvidos);
        $referencia = $candidatas[$referenciaIdx];

        $sobreviventes = collect([$referenciaIdx => $referencia]);

        foreach ($candidatas as $idx => $candidata) {
            if ($idx === $referenciaIdx) {
                continue;
            }

            $divergencia = $this->encontrarDivergencia($candidata, $referencia, $resolvidos);

            if ($divergencia !== null) {
                $excluidas->push($divergencia);
                continue;
            }

            $sobreviventes->put($idx, $candidata);
        }

        // Quem já tem link individual do mesmo modelo no mês fica de fora
        // da replicação — mesmo tendo sobrevivido à comparação de
        // responsáveis (não recebe DOIS links).
        $incluidas = collect();

        foreach ($sobreviventes as $candidata) {
            $empresa = $candidata['empresa'];

            if ($this->elegibilidade->surveyExistenteNaCompetencia($empresa->id, $template->id, $mes)) {
                $excluidas->push($this->linhaExcluida($empresa, 'ja_tem_link'));

                continue;
            }

            $incluidas->push([
                'company_id'  => $empresa->id,
                'name'        => $empresa->name,
                'servico_ids' => $candidata['servicos']->values()->all(),
            ]);
        }

        return [
            'referencia' => $referencia['empresa']->id,
            'incluidas'  => $incluidas->values()->all(),
            'excluidas'  => $excluidas->values()->all(),
        ];
    }

    /**
     * Modelo sem NENHUM serviço coberto — fallback do "NPS Padrão" já
     * existente noutras partes do NPS (D3 não se aplica: sem serviço
     * coberto não há o que comparar). Toda empresa ATIVA do grupo entra,
     * respeitando só o guard de duplicidade (`ja_tem_link`).
     */
    private function calcularSemServicoCoberto(Collection $empresasGrupo, NpsTemplate $template, Carbon $mes): array
    {
        $incluidas = collect();
        $excluidas = collect();

        foreach ($empresasGrupo as $empresa) {
            if (! $empresa->active) {
                $excluidas->push($this->linhaExcluida($empresa, 'empresa_inativa'));

                continue;
            }

            // 2026-08-18 — mesma exclusão do ramo principal, só que aqui o
            // teste é "tem QUALQUER vínculo em company_users": sem serviço
            // coberto não há por qual serviço perguntar. Empresa órfã não
            // entra no link porque a nota dela não teria dono.
            if ($empresa->users()->doesntExist()) {
                $excluidas->push($this->linhaExcluida($empresa, 'sem_responsavel'));

                continue;
            }

            if ($this->elegibilidade->surveyExistenteNaCompetencia($empresa->id, $template->id, $mes)) {
                $excluidas->push($this->linhaExcluida($empresa, 'ja_tem_link'));

                continue;
            }

            $incluidas->push([
                'company_id'  => $empresa->id,
                'name'        => $empresa->name,
                'servico_ids' => [],
            ]);
        }

        return [
            'referencia' => null,
            'incluidas'  => $incluidas->values()->all(),
            'excluidas'  => $excluidas->values()->all(),
        ];
    }

    /**
     * Passo 2 do algoritmo: separa empresas ATIVAS com pelo menos 1 contrato
     * ativo em serviço coberto pelo modelo (candidatas) das que já saem de
     * cara — `empresa_inativa` / `sem_servico_contratado` (motivos
     * distintos de `responsavel_diferente`, resolvido só mais adiante).
     *
     * @return array{0: Collection, 1: Collection}
     */
    private function filtrarCandidatas(Collection $empresasGrupo, Collection $cobertosIds): array
    {
        $candidatas = collect();
        $excluidas = collect();

        foreach ($empresasGrupo as $empresa) {
            if (! $empresa->active) {
                $excluidas->push($this->linhaExcluida($empresa, 'empresa_inativa'));

                continue;
            }

            $servicoIdsAtivos = $empresa->contratosServico()->active()->pluck('servico_id');
            $servicosAplicaveis = $cobertosIds->intersect($servicoIdsAtivos)->values();

            if ($servicosAplicaveis->isEmpty()) {
                $excluidas->push($this->linhaExcluida($empresa, 'sem_servico_contratado'));

                continue;
            }

            $candidatas->push(['empresa' => $empresa, 'servicos' => $servicosAplicaveis]);
        }

        return [$candidatas->values(), $excluidas];
    }

    /**
     * Pré-resolve, uma vez, o responsável de CADA candidata em CADA um dos
     * seus serviços aplicáveis, para os DOIS papéis (D6). Usa
     * EXCLUSIVAMENTE `responsavelDoServicoOuConsolidado()` — é o único
     * método que também cobre o slot consolidado (sem serviço específico
     * na atribuição), e é o que evita reintroduzir o gap do responsável
     * consolidado (debug de 2026-07-22).
     *
     * @return array{
     *   ids: array<int, array<int, array<string, int[]>>>,
     *   nomes: array<int, array<int, array<string, string>>>,
     * }
     */
    private function resolverResponsaveis(Collection $candidatas): array
    {
        $ids = [];
        $nomes = [];

        foreach ($candidatas as $candidata) {
            $empresa = $candidata['empresa'];

            foreach ($candidata['servicos'] as $servicoId) {
                foreach (self::PAPEIS as $papel) {
                    $responsaveis = $empresa->responsavelDoServicoOuConsolidado($papel, $servicoId);

                    $ids[$empresa->id][$servicoId][$papel] = $responsaveis->pluck('id')->sort()->values()->all();
                    $nomes[$empresa->id][$servicoId][$papel] = $responsaveis->pluck('name')->implode(', ');
                }
            }
        }

        return ['ids' => $ids, 'nomes' => $nomes];
    }

    /**
     * Separa as candidatas SEM responsável nenhum das demais — 2026-08-18.
     * Lê o mapa já resolvido por `resolverResponsaveis()`: nada de query
     * nova, e a régua de "quem é responsável" continua vindo de um único
     * lugar (`responsavelDoServicoOuConsolidado()`).
     *
     * @return array{0: Collection, 1: Collection}
     */
    private function separarSemResponsavel(Collection $candidatas, array $resolvidos): array
    {
        $comResponsavel = collect();
        $excluidas = collect();

        foreach ($candidatas as $candidata) {
            $empresa = $candidata['empresa'];
            $temResponsavel = false;

            foreach ($candidata['servicos'] as $servicoId) {
                foreach (self::PAPEIS as $papel) {
                    if (! empty($resolvidos['ids'][$empresa->id][$servicoId][$papel])) {
                        $temResponsavel = true;

                        break 2;
                    }
                }
            }

            if ($temResponsavel) {
                $comResponsavel->push($candidata);

                continue;
            }

            $excluidas->push($this->linhaExcluida($empresa, 'sem_responsavel'));
        }

        return [$comResponsavel->values(), $excluidas];
    }

    /**
     * Duas candidatas são compatíveis quando têm interseção de serviços
     * aplicáveis NÃO vazia e, para TODO serviço dessa interseção e para
     * CADA papel de `self::PAPEIS` (D6), o mesmo conjunto de responsáveis.
     */
    private function compativel(array $a, array $b, array $idsResolvidos): bool
    {
        if ($a['empresa']->id === $b['empresa']->id) {
            return true;
        }

        $interseccao = $a['servicos']->intersect($b['servicos']);

        if ($interseccao->isEmpty()) {
            return false;
        }

        foreach ($interseccao as $servicoId) {
            foreach (self::PAPEIS as $papel) {
                $ra = $idsResolvidos[$a['empresa']->id][$servicoId][$papel] ?? [];
                $rb = $idsResolvidos[$b['empresa']->id][$servicoId][$papel] ?? [];

                if ($ra !== $rb) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Escolhe a REFERÊNCIA da comparação: a candidata cuja classe de
     * equivalência (todas as candidatas compatíveis com ela, inclusive ela
     * mesma) é a MAIOR — a MAIORIA do grupo. NUNCA a empresa de menor id.
     *
     * Desempate determinístico e estável entre execuções:
     *  1. Entre classes de MESMO tamanho, vence a que contém a empresa de
     *     MENOR id.
     *  2. Dentro da classe vencedora, a referência é a candidata de MENOR
     *     id — o que, por construção, é a mesma empresa que decidiu o
     *     desempate do passo 1 quando ela está na própria classe.
     *
     * Isso é o que impede o caso 3-contra-1: se 3 empresas concordam e 1
     * diverge, a classe das 3 tem tamanho 3 (> 1 da divergente) e vence
     * independente de quem tem o menor id.
     */
    private function escolherReferencia(Collection $candidatas, array $resolvidos): int
    {
        $idsResolvidos = $resolvidos['ids'];

        $melhorTamanho = -1;
        $melhorMenorId = null;
        $melhorMembros = [];

        foreach ($candidatas as $i => $ci) {
            $membros = [];

            foreach ($candidatas as $j => $cj) {
                if ($this->compativel($ci, $cj, $idsResolvidos)) {
                    $membros[] = $j;
                }
            }

            $tamanho = count($membros);
            $menorId = collect($membros)->map(fn ($idx) => $candidatas[$idx]['empresa']->id)->min();

            $melhorClasseAtual = $tamanho > $melhorTamanho
                || ($tamanho === $melhorTamanho && $menorId < $melhorMenorId);

            if ($melhorClasseAtual) {
                $melhorTamanho = $tamanho;
                $melhorMenorId = $menorId;
                $melhorMembros = $membros;
            }
        }

        return collect($melhorMembros)
            ->sortBy(fn ($idx) => $candidatas[$idx]['empresa']->id)
            ->first();
    }

    /**
     * Compara uma candidata contra a referência e devolve a linha de
     * exclusão na PRIMEIRA divergência encontrada (serviço da interseção +
     * papel de `self::PAPEIS`), ou `null` se ela sobrevive.
     */
    private function encontrarDivergencia(array $candidata, array $referencia, array $resolvidos): ?array
    {
        $empresa = $candidata['empresa'];
        $empresaRef = $referencia['empresa'];

        $interseccao = $candidata['servicos']->intersect($referencia['servicos']);

        if ($interseccao->isEmpty()) {
            return $this->linhaExcluida($empresa, 'sem_servico_em_comum');
        }

        $idsResolvidos = $resolvidos['ids'];
        $nomesResolvidos = $resolvidos['nomes'];

        foreach ($interseccao as $servicoId) {
            foreach (self::PAPEIS as $papel) {
                $ra = $idsResolvidos[$empresa->id][$servicoId][$papel] ?? [];
                $rb = $idsResolvidos[$empresaRef->id][$servicoId][$papel] ?? [];

                if ($ra !== $rb) {
                    $nomeServico = Servico::find($servicoId)?->nome;

                    return $this->linhaExcluida(
                        $empresa,
                        'responsavel_diferente',
                        $nomeServico,
                        self::PAPEL_LABEL[$papel],
                        $nomesResolvidos[$empresa->id][$servicoId][$papel] ?? null,
                    );
                }
            }
        }

        return null;
    }

    private function linhaExcluida(
        Company $empresa,
        string $motivo,
        ?string $servico = null,
        ?string $papel = null,
        ?string $quemCuida = null,
    ): array {
        return [
            'company_id' => $empresa->id,
            'name'       => $empresa->name,
            'motivo'     => $motivo,
            'servico'    => $servico,
            'papel'      => $papel,
            'quem_cuida' => $quemCuida,
        ];
    }
}
