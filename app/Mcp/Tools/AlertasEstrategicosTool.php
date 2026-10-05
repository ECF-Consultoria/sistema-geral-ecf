<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\AlertasController;
use App\Mcp\ErroDaFerramenta;
use App\Models\Company;
use App\Models\User;
use App\Services\EcfDriveService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Throwable;

/**
 * `alertas_estrategicos` — a caixa de `/alertas-estrategicos`: signals que o
 * ECF Drive detecta todo dia às 07:30 UTC (queda de faturamento, queda de
 * visitas, medalha rebaixada, score crítico, oportunidade de ADS).
 *
 * Mesma fonte e mesmo cache da tela: `EcfDriveService::listSignals()` (cache de
 * 1 min), o mesmo lookup de empresa por CUST e os mesmos contadores por
 * criticidade. A tela não recorta por carteira — admin, consultor e mentor
 * veem todos os alertas; aqui também.
 *
 * Só leitura: "marcar como visto" (ack) continua só na tela.
 */
#[Name('alertas_estrategicos')]
#[Title('Alertas estratégicos (ECF Drive)')]
#[Description(<<<'TXT'
Lista os alertas estratégicos da tela /alertas-estrategicos, detectados pelo ECF Drive todo dia: queda de faturamento mês contra mês, queda de visitas, medalha rebaixada, score crítico e oportunidade de ADS. Use para "quais alertas críticos estão abertos", "a empresa X teve algum alerta", "quem caiu de faturamento".
Por padrão devolve só os NÃO VISTOS (o mesmo padrão da tela). `resumo_nao_vistos` traz os contadores por criticidade do topo da tela.
TXT)]
class AlertasEstrategicosTool extends FerramentaEcf
{
    private const CRITICIDADES = ['critical', 'warning', 'info'];

    protected function podeUsar(User $usuario): bool
    {
        // Mesma régua da rota: role:admin,consultor,mentor.
        return in_array($usuario->role, ['admin', 'consultor', 'mentor'], true);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tipo' => $schema->string()
                ->enum(array_keys(AlertasController::TYPE_LABELS))
                ->description('Tipo do alerta: '.collect(AlertasController::TYPE_LABELS)->map(fn ($l, $k) => "$k ($l)")->implode(', ').'.'),
            'criticidade' => $schema->string()
                ->enum(self::CRITICIDADES)
                ->description('critical = Crítico, warning = Atenção, info = Oportunidade.'),
            'empresa' => $schema->string()
                ->description('CUST da empresa, ou parte do nome (precisa casar com uma empresa só).'),
            'vistos' => $schema->string()
                ->enum(['nao_vistos', 'vistos', 'todos'])
                ->description('Padrão: "nao_vistos", como a tela abre.'),
            ...$this->schemaPaginacao($schema),
        ];
    }

    protected function consultar(Request $request, User $usuario): array
    {
        [$deslocamento, $limite] = $this->janela($request);
        $pagina = intdiv($deslocamento, $limite) + 1;

        $vistos  = $this->texto($request, 'vistos') ?? 'nao_vistos';
        $empresa = $this->texto($request, 'empresa');
        $filtros = array_filter([
            'event_type' => $this->texto($request, 'tipo'),
            'severity'   => $this->texto($request, 'criticidade'),
            'cust_id'    => $empresa !== null ? $this->custDaEmpresa($empresa) : null,
            'acked'      => match ($vistos) {
                'vistos' => true,
                'todos'  => null,
                default  => false,
            },
            'page'  => $pagina,
            'limit' => $limite,
        ], fn ($v) => $v !== null);

        $ecf = app(EcfDriveService::class);

        try {
            $signals  = $ecf->listSignals($filtros);
            $contagem = [];
            foreach (self::CRITICIDADES as $c) {
                $contagem[$c] = (int) ($ecf->listSignals(['severity' => $c, 'acked' => false, 'limit' => 1])['total'] ?? 0);
            }
        } catch (Throwable $e) {
            report($e);
            throw new ErroDaFerramenta('A API do ECF Drive (fonte dos alertas) está indisponível agora. Tente de novo em alguns segundos.');
        }

        $dados   = collect($signals['data'] ?? []);
        $empresas = $this->empresasPorCust($dados->pluck('custId')->filter()->map(fn ($v) => (string) $v)->unique()->values()->all());
        $total   = (int) ($signals['total'] ?? $dados->count());

        return [
            'total' => $total,
            'itens' => $dados->map(fn (array $s) => [
                'id'           => $s['id'] ?? null,
                'tipo'         => $s['eventType'] ?? null,
                'tipo_rotulo'  => AlertasController::TYPE_LABELS[$s['eventType'] ?? ''] ?? null,
                'criticidade'  => $s['severity'] ?? null,
                'criticidade_rotulo' => AlertasController::SEVERITY_LABELS[$s['severity'] ?? ''] ?? null,
                'cust_id'      => isset($s['custId']) ? (string) $s['custId'] : null,
                'empresa'      => $empresas[(string) ($s['custId'] ?? '')] ?? null,
                'periodo'      => $s['periodKey'] ?? null,
                'detalhes'     => $s['payload'] ?? null,
                'detectado_em' => $s['detectedAt'] ?? null,
                'visto_em'     => $s['ackAt'] ?? null,
            ])->values()->all(),
            'proximo_cursor' => $deslocamento + $limite < $total ? $this->cursorPara($deslocamento + $limite) : null,
            'resumo_nao_vistos' => $contagem,
            'fonte' => 'API do ECF Drive (/signals, cache de 1 min — o mesmo da tela). Detecção diária às 07:30 UTC.',
        ];
    }

    /**
     * Resolve o filtro de empresa para UM CUST — a API do ECF Drive filtra por
     * um `cust_id` só.
     */
    private function custDaEmpresa(string $filtro): string
    {
        if (ctype_digit($filtro)) {
            return $filtro;
        }

        $candidatas = Company::where('active', true)
            ->where('name', 'like', '%'.$filtro.'%')
            ->get(['id', 'name', 'adman_account_id', 'ml_store_id'])
            ->filter(fn (Company $c) => $c->adman_account_id || $c->ml_store_id);

        if ($candidatas->isEmpty()) {
            throw new ErroDaFerramenta('Empresa não encontrada (ou sem CUST): "'.$filtro.'".');
        }
        if ($candidatas->count() > 1) {
            throw new ErroDaFerramenta('Mais de uma empresa casa com "'.$filtro.'": '
                .$candidatas->take(5)->map(fn ($c) => $c->name.' (CUST '.($c->adman_account_id ?: $c->ml_store_id).')')->implode('; ')
                .'. Repita com o CUST.');
        }

        $c = $candidatas->first();

        return (string) ($c->adman_account_id ?: $c->ml_store_id);
    }

    /**
     * Mesmo lookup do AlertasController: empresa ATIVA por adman_account_id ou
     * ml_store_id, numa consulta só.
     *
     * @param  array<int, string>  $custs
     * @return array<string, array{id:int, nome:string}>
     */
    private function empresasPorCust(array $custs): array
    {
        if ($custs === []) {
            return [];
        }

        $mapa = [];
        Company::where('active', true)
            ->where(fn ($q) => $q->whereIn('adman_account_id', $custs)->orWhereIn('ml_store_id', $custs))
            ->get(['id', 'name', 'adman_account_id', 'ml_store_id'])
            ->each(function (Company $c) use (&$mapa) {
                $entrada = ['id' => $c->id, 'nome' => $c->name];
                if ($c->adman_account_id) {
                    $mapa[(string) $c->adman_account_id] = $entrada;
                }
                if ($c->ml_store_id) {
                    $mapa[(string) $c->ml_store_id] = $entrada;
                }
            });

        return $mapa;
    }
}
