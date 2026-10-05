<?php

namespace App\Mcp\Tools;

use App\Mcp\ErroDaFerramenta;
use App\Models\DevDemanda;
use App\Models\User;
use App\Services\DevDemandas\DemandasDevService;
use App\Services\ModuleRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * `demandas_dev` — a lista de `/dev/demandas` (tarefas do time de
 * desenvolvimento).
 *
 * Tudo vem do {@see DemandasDevService}, o mesmo da tela: quem entra
 * (`podeAcessar`), quais demandas vê (`demandasVisiveis` — admin tudo, os
 * demais só as próprias), a linha (`serializar`) e as contagens (`painel`).
 * A rota da tela ainda passa pelo módulo `dev.demandas` (pode estar oculto no
 * Controle Dev); a mesma trava vale aqui.
 */
#[Name('demandas_dev')]
#[Title('Demandas do time Dev')]
#[Description(<<<'TXT'
Lista as demandas do time de desenvolvimento da tela /dev/demandas: código (ex.: DEV-29), título, responsável, prioridade (P0 a P3), status, prazo, situação (atrasada, no prazo, bloqueada...) e a última atualização. Use para "o que está atrasado no Dev", "o que o fulano está fazendo", "quais demandas P0 estão abertas".
Por padrão devolve só as ABERTAS (sem concluídas e canceladas). O campo `painel` traz as contagens da tela (por status, situação, prioridade, responsável e área), calculadas sobre todas as demandas que você enxerga.
TXT)]
class DemandasDevTool extends FerramentaEcf
{
    protected function podeUsar(User $usuario): bool
    {
        // Mesmas duas travas da rota GET /dev/demandas: o módulo liberado
        // (middleware `modulo:dev.demandas`) e o acesso do serviço.
        return app(ModuleRegistry::class)->liberadoPara($usuario, 'dev.demandas')
            && app(DemandasDevService::class)->podeAcessar($usuario);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'responsavel' => $schema->string()
                ->description('Nome (ou parte) ou id do responsável pela demanda.'),
            'prioridade' => $schema->string()
                ->enum(['P0', 'P1', 'P2', 'P3'])
                ->description('P0 = crítica, P1 = alta, P2 = normal, P3 = backlog.'),
            'status' => $schema->string()
                ->enum(array_keys(DevDemanda::STATUS_LABELS))
                ->description('Status atual da demanda.'),
            'atrasadas' => $schema->boolean()
                ->description('Só as atrasadas (prazo vencido e ainda abertas). Padrão: false.'),
            'incluir_encerradas' => $schema->boolean()
                ->description('Inclui concluídas e canceladas. Padrão: false.'),
            'busca' => $schema->string()
                ->description('Parte do código ou do título.'),
            ...$this->schemaPaginacao($schema),
        ];
    }

    protected function consultar(Request $request, User $usuario): array
    {
        $service = app(DemandasDevService::class);
        $hoje    = now()->startOfDay();
        $linhas  = $service->demandasVisiveis($usuario)
            ->map(fn (DevDemanda $d) => $service->serializar($d, $hoje))
            ->values()
            ->all();

        $prioridade = $this->texto($request, 'prioridade');
        if ($prioridade !== null && ! preg_match('/^P?([0-3])$/i', $prioridade, $m)) {
            throw new ErroDaFerramenta('Prioridade inválida: use P0, P1, P2 ou P3.');
        }
        $nivel = $prioridade !== null ? (int) $m[1] : null;

        $responsavel = $this->texto($request, 'responsavel');
        $status      = $this->texto($request, 'status');
        $busca       = $this->texto($request, 'busca');
        $encerradas  = $this->booleano($request, 'incluir_encerradas');
        $atrasadas   = $this->booleano($request, 'atrasadas');

        $filtradas = collect($linhas)
            ->when(! $encerradas && $status === null, fn ($c) => $c->reject(fn ($l) => $l['encerrada']))
            ->when($status !== null, fn ($c) => $c->filter(fn ($l) => $l['status'] === $status))
            ->when($nivel !== null, fn ($c) => $c->filter(fn ($l) => (int) $l['prioridade'] === $nivel))
            ->when($atrasadas, fn ($c) => $c->filter(fn ($l) => $l['situacao'] === DevDemanda::SITUACAO_ATRASADA))
            ->when($responsavel !== null, fn ($c) => $c->filter(fn ($l) => $this->casaResponsavel($l['responsavel'], $responsavel)))
            ->when($busca !== null, fn ($c) => $c->filter(fn ($l) => Str::contains(Str::lower($l['codigo'].' '.$l['titulo']), Str::lower($busca))))
            // Mesma ordem da fila da tela: faixa → prazo (sem prazo por último) → código.
            ->sortBy([
                fn ($a, $b) => $a['faixa_fila'] <=> $b['faixa_fila'],
                fn ($a, $b) => ($a['prazo'] ?? '9999-12-31') <=> ($b['prazo'] ?? '9999-12-31'),
                fn ($a, $b) => $a['codigo'] <=> $b['codigo'],
            ])
            ->map(fn (array $l) => $this->saida($l));

        return [
            ...$this->paginar($filtradas, $request),
            'painel' => $service->painel($linhas),
            'visao'  => $usuario->isAdmin() ? 'todas as demandas' : 'só as suas demandas',
            'fonte'  => 'Banco do ECF Admin, ao vivo (mesma lista de /dev/demandas).',
        ];
    }

    /** @param array<string, mixed> $l  linha de DemandasDevService::serializar() */
    private function saida(array $l): array
    {
        return [
            'codigo'             => $l['codigo'],
            'titulo'             => $l['titulo'],
            'area'               => $l['area'],
            'responsavel'        => $l['responsavel']['name'] ?? null,
            'prioridade'         => DevDemanda::PRIORIDADE_LABELS[$l['prioridade']] ?? (string) $l['prioridade'],
            'status'             => DevDemanda::STATUS_LABELS[$l['status']] ?? $l['status'],
            'situacao'           => $l['situacao'],
            'prazo'              => $l['prazo'],
            'dias_atraso'        => $l['dias_atraso'],
            'bloqueado'          => $l['bloqueado'],
            'motivo_bloqueio'    => $l['motivo_bloqueio'],
            'proxima_acao'       => $l['proxima_acao'],
            'ultimo_feito'       => $l['ultimo_feito'],
            'ultima_atualizacao' => $l['ultima_atualizacao'],
            'data_entrada'       => $l['data_entrada'],
            'ticket_de_origem'   => $l['chamado']['codigo'] ?? null,
        ];
    }

    private function casaResponsavel(?array $responsavel, string $filtro): bool
    {
        if ($responsavel === null) {
            return false;
        }

        return ctype_digit($filtro)
            ? (int) $filtro === (int) $responsavel['id']
            : Str::contains(Str::lower(Str::ascii($responsavel['name'])), Str::lower(Str::ascii($filtro)));
    }
}
