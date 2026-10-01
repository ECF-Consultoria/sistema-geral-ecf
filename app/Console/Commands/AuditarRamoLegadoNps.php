<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\Desempenho\NpsPorEmpresaService;
use App\Services\Nps\NpsJanelaResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 159 Plano 06 (D-09) — mede, por profissional e competência, quanto da
 * nota de NPS vem do RAMO LEGADO (respostas sem atribuição congelada no
 * papel) e em que lojas o papel que o ramo APLICA diverge do papel REAL do
 * profissional naquela loja, ou cobre só uma de duas funções acumuladas.
 *
 * SÓ LEITURA: nenhuma escrita em nenhuma tabela, nenhum cache, nenhuma
 * chamada HTTP à Adman. Não reimplementa a regra do ramo legado — lê
 * `por_ramo['legado']` de `NpsPorEmpresaService::notasNpsPorEmpresa()`, a
 * MESMA régua que `NpsPorEmpresaService::notasLegadoPorEmpresa()` usa: o
 * papel aplicado é `User::dimensaoNpsDesempenho()` mapeado para
 * 'estrategista'|'consultor'.
 *
 * Por que existe (D-09 do 159-CONTEXT.md): com dois cargos na mesma pessoa,
 * `dimensaoNpsDesempenho()` escolhe UMA dimensão para a pessoa inteira. O
 * ramo legado aplica essa MESMA dimensão a toda a carteira consolidada —
 * numa loja em que o profissional é só analista, uma resposta sem
 * atribuição congelada poderia (silenciosamente) receber a pergunta do
 * estrategista. Medir ANTES de mexer: `DesempenhoScoreService` e
 * `NpsPorEmpresaService` NÃO são alterados por este comando.
 *
 * NUNCA imprime nota, faixa ou valor de bônus (learnings §11) — só
 * contagens, ids, nomes de empresa e papéis.
 *
 * Exit code (WR-09 da revisão) — é o veredito, nunca o texto:
 *  - 0: sem exposição do ramo legado, em competências com a janela de coleta
 *       do NPS (M+1) JÁ fechada;
 *  - 1: exposição encontrada (divergente/parcial) — decisão necessária (D-09).
 *       Prevalece sobre o inconclusivo: resposta que já caiu no ramo legado
 *       é exposição real, e mais respostas só podem aumentá-la;
 *  - 2: inconclusivo — alguma competência pedida ainda tem a janela de coleta
 *       aberta (`NpsJanelaResolver::fechada()`, a mesma régua do bônus). Zero
 *       resposta no ramo legado ali é falta de resposta, não ausência de
 *       exposição; medir de novo depois do fechamento.
 *
 * @see .planning/phases/159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo/159-CONTEXT.md D-09
 * @see App\Services\Desempenho\NpsPorEmpresaService::notasLegadoPorEmpresa()
 */
class AuditarRamoLegadoNps extends Command
{
    protected $signature = 'desempenho:auditar-ramo-legado
                            {--user=* : ids dos profissionais}
                            {--mes=* : competências YYYY-MM já encerradas}
                            {--json : saída em JSON}';

    protected $description = 'Mede a exposição do ramo legado de NPS por profissional/competência (D-09) — SÓ LEITURA';

    /** Exit code de "inconclusivo" (janela de coleta ainda aberta) — WR-09. */
    private const INCONCLUSIVO = 2;

    public function __construct(
        private readonly NpsPorEmpresaService $service,
        private readonly NpsJanelaResolver $janela,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $userIds = (array) $this->option('user');
        $mesesRaw = (array) $this->option('mes');

        if (empty($userIds) || empty($mesesRaw)) {
            $this->error('[AuditarRamoLegado] --user e --mes são obrigatórios (ao menos um de cada).');

            return self::FAILURE;
        }

        $meses = $this->parsearMeses($mesesRaw);
        if ($meses === null) {
            return self::FAILURE;
        }

        $usuarios = User::query()->whereIn('id', $userIds)->get()->keyBy('id');
        foreach ($userIds as $userId) {
            if (! $usuarios->has((int) $userId)) {
                $this->error("[AuditarRamoLegado] Usuário {$userId} não encontrado.");

                return self::FAILURE;
            }
        }

        // WR-09: competência cuja coleta do NPS (M+1) ainda não fechou dá
        // medição prematura — marcada como inconclusiva, nunca "sem exposição".
        // O resolver memoiza os fechamentos manuais por instância; relê a cada execução.
        $this->janela->esquecerCache();
        $abertaAtePorMes = [];
        foreach ($meses as $mes) {
            $mesColeta = $this->janela->mesDeColeta($mes);
            $abertaAtePorMes[$mes->format('Y-m')] = $this->janela->fechada($mesColeta)
                ? null
                : $mesColeta->copy()->endOfMonth()->toDateString();
        }
        $abertaAte = collect($abertaAtePorMes)->filter()->max();

        $resultados = [];
        $houveExposicao = false;

        foreach ($userIds as $userId) {
            $user = $usuarios->get((int) $userId);
            $dimensao = $user->dimensaoNpsDesempenho();
            $papelAplicado = $dimensao === 'estrategista' ? 'estrategista' : 'consultor';

            $papeisReaisPorEmpresa = $this->papeisReaisPorEmpresa($user->id);

            foreach ($meses as $mes) {
                $resultado = $this->auditarUsuarioNoMes($user, $mes, $dimensao, $papelAplicado, $papeisReaisPorEmpresa);
                $resultado['janela_coleta_aberta_ate'] = $abertaAtePorMes[$mes->format('Y-m')];
                $resultado['inconclusivo'] = $resultado['janela_coleta_aberta_ate'] !== null;

                if (! empty($resultado['divergentes']) || ! empty($resultado['parciais'])) {
                    $houveExposicao = true;
                }

                $resultados[] = $resultado;
            }
        }

        $veredito = match (true) {
            $houveExposicao && $abertaAte !== null => 'Veredito: decisão necessária (D-09) — e há competência com a '
                . "janela de coleta aberta até {$abertaAte}: a exposição ainda pode crescer",
            $houveExposicao => 'Veredito: decisão necessária (D-09)',
            $abertaAte !== null => "Veredito: inconclusivo (janela de coleta aberta até {$abertaAte}) — medir de novo "
                . 'depois do fechamento da coleta',
            default => 'Veredito: sem exposição do ramo legado',
        };

        if ($this->option('json')) {
            $this->line(json_encode(
                ['resultados' => $resultados, 'veredito' => $veredito],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ));
        } else {
            $this->imprimirRelatorio($resultados, $veredito);
        }

        if ($houveExposicao) {
            return self::FAILURE;
        }

        return $abertaAte !== null ? self::INCONCLUSIVO : self::SUCCESS;
    }

    /**
     * Valida formato (`YYYY-MM`) e recusa competência em curso — o serviço
     * devolve piso 1.0 sem consultar ramo nenhum para o mês corrente, então
     * medir "exposição do ramo legado" nele não faz sentido.
     *
     * @param  list<string>  $mesesRaw
     * @return list<Carbon>|null `null` quando algum mês é inválido (erro já impresso)
     */
    private function parsearMeses(array $mesesRaw): ?array
    {
        $meses = [];

        foreach ($mesesRaw as $mesRaw) {
            if (! preg_match('/^\d{4}-\d{2}$/', $mesRaw)) {
                $this->error("[AuditarRamoLegado] --mes inválido: '{$mesRaw}' (formato esperado YYYY-MM).");

                return null;
            }

            try {
                $mes = Carbon::createFromFormat('Y-m-d', $mesRaw . '-01')->startOfMonth();
            } catch (\Throwable $e) {
                $this->error("[AuditarRamoLegado] --mes inválido: '{$mesRaw}' (formato esperado YYYY-MM).");

                return null;
            }

            if ($mes->gte(now()->startOfMonth())) {
                $this->error("[AuditarRamoLegado] --mes={$mesRaw}: competência em curso não passa pelos ramos — use uma competência já encerrada.");

                return null;
            }

            $meses[] = $mes;
        }

        return $meses;
    }

    /**
     * `company_id => list<string>` de papéis distintos que o usuário REALMENTE
     * tem em `company_users` — a fonte do "papel real", independente do que o
     * ramo legado aplicou.
     *
     * @return \Illuminate\Support\Collection<int, array<int, string>>
     */
    private function papeisReaisPorEmpresa(int $userId): \Illuminate\Support\Collection
    {
        return DB::table('company_users')
            ->where('user_id', $userId)
            ->select('company_id', 'role')
            ->distinct()
            ->get()
            ->groupBy('company_id')
            ->map(fn ($linhas) => $linhas->pluck('role')->unique()->values()->all());
    }

    /**
     * Audita um (usuário, mês): lê `notasNpsPorEmpresa()` (SÓ LEITURA) e
     * classifica cada empresa com `por_ramo['legado'] > 0` em divergente
     * (papel aplicado não é um papel real do profissional naquela empresa)
     * ou parcial (acumula os dois papéis reais, mas só um recebeu nota).
     *
     * @param  \Illuminate\Support\Collection<int, array<int, string>>  $papeisReaisPorEmpresa
     */
    private function auditarUsuarioNoMes(User $user, Carbon $mes, string $dimensao, string $papelAplicado, \Illuminate\Support\Collection $papeisReaisPorEmpresa): array
    {
        $notas = $this->service->notasNpsPorEmpresa($user, $mes, true);

        $empresasLegado = [];
        $divergentes = [];
        $parciais = [];
        $totalNotasLegado = 0;

        foreach ($notas as $companyId => $item) {
            $notasLegado = (int) ($item->por_ramo['legado'] ?? 0);
            if ($notasLegado <= 0) {
                continue;
            }

            $totalNotasLegado += $notasLegado;
            $nomeEmpresa = Company::find($companyId)?->name ?? "empresa #{$companyId}";
            $papeisReais = $papeisReaisPorEmpresa->get($companyId, []);

            $empresasLegado[] = [
                'company_id' => $companyId,
                'company_name' => $nomeEmpresa,
                'notas_legado' => $notasLegado,
            ];

            if (! in_array($papelAplicado, $papeisReais, true)) {
                $divergentes[] = [
                    'company_id' => $companyId,
                    'company_name' => $nomeEmpresa,
                    'papel_aplicado' => $papelAplicado,
                    'papeis_reais' => $papeisReais,
                ];

                continue;
            }

            $temOsDoisPapeis = in_array('consultor', $papeisReais, true) && in_array('estrategista', $papeisReais, true);
            if ($temOsDoisPapeis && count($item->papeis) < 2) {
                $parciais[] = [
                    'company_id' => $companyId,
                    'company_name' => $nomeEmpresa,
                    'papel_aplicado' => $papelAplicado,
                    'papeis_reais' => $papeisReais,
                ];
            }
        }

        return [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'mes' => $mes->format('Y-m'),
            'dimensao_aplicada' => $dimensao,
            'papel_aplicado' => $papelAplicado,
            'empresas_legado' => $empresasLegado,
            'total_notas_legado' => $totalNotasLegado,
            'divergentes' => $divergentes,
            'parciais' => $parciais,
        ];
    }

    /**
     * Saída texto — convenção humana. NUNCA imprime nota/faixa/valor de
     * bônus (learnings §11): só contagens, ids, nomes de empresa e papéis.
     */
    private function imprimirRelatorio(array $resultados, string $veredito): void
    {
        foreach ($resultados as $r) {
            $this->line('');
            $this->info(sprintf(
                '%s (user %d) — competência %s — dimensão aplicada: %s (papel %s)',
                $r['user_name'],
                $r['user_id'],
                $r['mes'],
                $r['dimensao_aplicada'],
                $r['papel_aplicado']
            ));
            if ($r['inconclusivo']) {
                $this->warn("  inconclusivo (janela de coleta aberta até {$r['janela_coleta_aberta_ate']})");
            }
            $this->line(sprintf(
                '  Empresas com nota no ramo legado: %d — total de notas legado: %d',
                count($r['empresas_legado']),
                $r['total_notas_legado']
            ));

            if ($r['divergentes']) {
                $this->warn('  Divergentes (papel aplicado não é um dos papéis reais do profissional):');
                foreach ($r['divergentes'] as $d) {
                    $this->line(sprintf(
                        '    - empresa %d (%s): aplicado=%s, reais=%s',
                        $d['company_id'],
                        $d['company_name'],
                        $d['papel_aplicado'],
                        implode(',', $d['papeis_reais'])
                    ));
                }
            }

            if ($r['parciais']) {
                $this->warn('  Parciais (profissional acumula os dois papéis, só um recebeu nota):');
                foreach ($r['parciais'] as $p) {
                    $this->line(sprintf(
                        '    - empresa %d (%s): aplicado=%s, reais=%s',
                        $p['company_id'],
                        $p['company_name'],
                        $p['papel_aplicado'],
                        implode(',', $p['papeis_reais'])
                    ));
                }
            }
        }

        $this->line('');
        $this->line($veredito);
    }
}
