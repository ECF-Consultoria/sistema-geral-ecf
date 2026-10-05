<?php

namespace App\Mcp\Tools;

use App\Mcp\ErroDaFerramenta;
use App\Models\Ppa;
use App\Models\User;
use App\Services\Portal\PortalPpaService;
use App\Services\Ppa\PpaListaService;
use App\Support\Permissions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * `ppa` — os Planos de Ação (PPA) das telas `/ppa` (carteira) e
 * `/mlb/polos-ppa` (Polos).
 *
 * Mesma consulta dos dois controllers (`PpaController::index()` e
 * `PolosPpaController::index()`): mesmos scopes (`doEscopo`, `daSituacao`,
 * `ordenadoPorAtencao`), mesma linha (`PpaListaService::linha()`) e o mesmo
 * recorte — quem não é admin vê só os PPAs que ele mesmo criou. O escopo
 * Polos exige `mlb.projetos`, como a rota da tela.
 *
 * Fora de propósito: o token do quadro do cliente e os links de
 * compartilhamento (dão acesso de escrita ao quadro).
 */
#[Name('ppa')]
#[Title('Planos de Ação (PPA)')]
#[Description(<<<'TXT'
Lista os Planos de Ação (PPA) das telas /ppa (empresas da carteira) e /mlb/polos-ppa (empresas dos Polos): título, empresa, responsável, status, prazo, dias até o prazo, tarefas feitas/total e as tarefas do plano. Use para "quais PPAs estão atrasados", "como está o plano da empresa X", "o que o cliente ainda precisa fazer".
Quem não é admin vê só os PPAs que criou (igual à tela). "visivel_ao_cliente" = plano já enviado ao Portal do Cliente (status sent ou completed).
TXT)]
class PpaTool extends FerramentaEcf
{
    protected function podeUsar(User $usuario): bool
    {
        // /ppa só exige login; o escopo Polos é conferido em consultar().
        return true;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'escopo' => $schema->string()
                ->enum([Ppa::ESCOPO_GERAL, Ppa::ESCOPO_POLOS])
                ->description('"geral" = PPAs das empresas da carteira (/ppa); "polos" = PPAs das empresas dos Polos (/mlb/polos-ppa). Padrão: "geral".'),
            'empresa' => $schema->string()
                ->description('Parte do nome da empresa, ou o id dela.'),
            'situacao' => $schema->string()
                ->enum(Ppa::SITUACOES)
                ->description('Mesmo filtro da tela: "andamento", "fazer", "concluido" ou "vencido" (prazo passou e o plano não foi encerrado).'),
            'atrasados' => $schema->boolean()
                ->description('Atalho para situacao="vencido". Padrão: false.'),
            'status' => $schema->string()
                ->enum(['draft', 'sent', 'completed'])
                ->description('Status gravado no plano: draft (rascunho), sent (enviado ao cliente), completed (encerrado).'),
            'visivel_ao_cliente' => $schema->boolean()
                ->description('true = só planos que o cliente vê no Portal; false = só os que ele ainda não vê (rascunhos).'),
            'incluir_tarefas' => $schema->boolean()
                ->description('Inclui a lista de tarefas de cada plano. Padrão: true.'),
            ...$this->schemaPaginacao($schema),
        ];
    }

    protected function consultar(Request $request, User $usuario): array
    {
        $escopo = $this->texto($request, 'escopo') ?? Ppa::ESCOPO_GERAL;
        if (! in_array($escopo, [Ppa::ESCOPO_GERAL, Ppa::ESCOPO_POLOS], true)) {
            throw new ErroDaFerramenta('Escopo inválido: use "geral" ou "polos".');
        }
        if ($escopo === Ppa::ESCOPO_POLOS && ! $usuario->hasPermission(Permissions::MLB_PROJETOS)) {
            throw new ErroDaFerramenta('Seu perfil não tem acesso aos PPAs dos Polos (a tela /mlb/polos-ppa exige a permissão de Projetos).');
        }

        $situacao = $this->booleano($request, 'atrasados') ? Ppa::SITUACAO_VENCIDO : $this->texto($request, 'situacao');
        $filtros  = Ppa::filtrosDaLista(['situacao' => $situacao]);

        $consulta = Ppa::with([$escopo === Ppa::ESCOPO_POLOS ? 'mlbEmpresa' : 'company', 'mentor', 'tasks'])
            ->doEscopo($escopo)
            ->comContagemDeTarefas()
            ->comUltimaAtividade()
            ->daSituacao($filtros['situacao'])
            ->ordenadoPorAtencao(null);

        // Mesmo recorte das duas telas: não-admin só vê o que criou.
        if (! $usuario->isAdmin()) {
            $consulta->where('mentor_id', $usuario->id);
        }

        if ($status = $this->texto($request, 'status')) {
            $consulta->where('status', $status);
        }

        $visivel = $request->get('visivel_ao_cliente');
        if ($visivel !== null && $visivel !== '') {
            $this->booleano($request, 'visivel_ao_cliente')
                ? $consulta->whereIn('status', PortalPpaService::STATUS_VISIVEIS)
                : $consulta->whereNotIn('status', PortalPpaService::STATUS_VISIVEIS);
        }

        if ($empresa = $this->texto($request, 'empresa')) {
            $relacao = $escopo === Ppa::ESCOPO_POLOS ? 'mlbEmpresa' : 'company';
            $coluna  = $escopo === Ppa::ESCOPO_POLOS ? 'nome' : 'name';
            $consulta->whereHas($relacao, fn ($q) => ctype_digit($empresa)
                ? $q->whereKey((int) $empresa)
                : $q->where($coluna, 'like', '%'.$empresa.'%'));
        }

        $comTarefas = $request->get('incluir_tarefas') === null || $this->booleano($request, 'incluir_tarefas');

        return [
            ...$this->paginarConsulta($consulta, $request, fn (Ppa $p) => $this->linha($p, $comTarefas)),
            'escopo' => $escopo,
            'visao'  => $usuario->isAdmin() ? 'todos os PPAs' : 'só os PPAs que você criou',
            'fonte'  => 'Banco do ECF Admin, ao vivo (mesma lista de /ppa e /mlb/polos-ppa).',
        ];
    }

    /** @return array<string, mixed> */
    private function linha(Ppa $ppa, bool $comTarefas): array
    {
        $l = app(PpaListaService::class)->linha($ppa);

        return [
            'id'                 => $l['id'],
            'titulo'             => $l['titulo'],
            'empresa'            => $l['empresa'],
            'responsavel'        => $l['responsavel'],
            'status'             => $l['status'],
            'visivel_ao_cliente' => in_array($ppa->status, PortalPpaService::STATUS_VISIVEIS, true),
            'prazo'              => $l['prazo_iso'],
            // Negativo = prazo passou. Null = sem prazo ou plano encerrado.
            'dias_ate_o_prazo'   => $l['prazo_dias'],
            'tarefas_feitas'     => $l['feitas'],
            'tarefas_fazendo'    => $l['fazendo'],
            'tarefas_total'      => $l['total'],
            'pct'                => $l['pct'],
            'enviado_em'         => $l['enviado_em'],
            'criado_em'          => $l['criado_em'],
            'atualizado_em'      => $l['atualizado_em'],
            'tarefas'            => $comTarefas
                ? array_map(fn (array $t) => [
                    'titulo'      => $t['titulo'],
                    'status'      => $t['status'],
                    'prazo'       => $t['prazo_iso'] ?? null,
                    'dias_ate_o_prazo' => $t['prazo_dias'] ?? null,
                    'lado'        => $t['responsavel_lado'] ?? null,
                ], $l['tarefas'])
                : null,
        ];
    }
}
