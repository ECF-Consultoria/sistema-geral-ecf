<?php

namespace App\Mcp\Tools;

use App\Mcp\ErroDaFerramenta;
use App\Models\Company;
use App\Models\Sugador;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * `sugadores` — a fila de `/sugadores`: adgroups/campanhas que gastam em ADS
 * sem retorno, detectados pela análise diária (12h).
 *
 * Recorte idêntico ao `SugadorController::index()`: o gate `viewAny` da
 * SugadorPolicy decide quem entra; admin, gestor e líder de publicação veem
 * tudo, os demais só a própria carteira (`Sugador::scopeDaCarteira`). O filtro
 * por analista é só de admin, como na tela.
 *
 * Diferença deliberada do padrão da tela: a tela abre em "hoje + pendente"
 * (a fila do dia); aqui o padrão é "pendente" de qualquer data, porque a
 * pergunta típica ao assistente é "o que está parado e há quanto tempo".
 * `apenas_hoje=true` reproduz a abertura da tela.
 */
#[Name('sugadores')]
#[Title('Sugadores de ADS')]
#[Description(<<<'TXT'
Lista os "sugadores" da tela /sugadores: anúncios/adgroups e campanhas de Mercado Ads que gastam sem retorno, detectados pela análise diária (roda às 12h com dados D-1 da Adman). Use para "quais sugadores estão pendentes", "quanto a empresa X está perdendo em ADS", "há quantos dias o sugador está parado".
Por padrão devolve os PENDENTES de qualquer data (a tela abre só nos de hoje — use apenas_hoje=true para o mesmo recorte). O resumo traz pendentes_total e pendentes_hoje, os mesmos contadores da tela.
TXT)]
class SugadoresTool extends FerramentaEcf
{
    protected function podeUsar(User $usuario): bool
    {
        // Mesmo gate do SugadorController::index().
        return Gate::forUser($usuario)->allows('viewAny', Sugador::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'empresa' => $schema->string()
                ->description('Empresa: id, CUST ou parte do nome.'),
            'status' => $schema->string()
                ->enum(['pendente', 'em_acao', 'resolvido', 'ignorado', 'movido', 'auto_resolvido', 'todos'])
                ->description('Situação do sugador. Padrão: "pendente".'),
            'tipo' => $schema->string()
                ->enum([Sugador::TIPO_CAMPANHA, Sugador::TIPO_ADGROUP])
                ->description('"campanha" (campanha inteira) ou "adgroup" (anúncio/grupo dentro da campanha).'),
            'analista' => $schema->string()
                ->description('Só admin: nome (ou parte) ou id do analista/estrategista; filtra pelas empresas da carteira dele.'),
            'data' => $schema->string()
                ->description('Data da análise (YYYY-MM-DD).'),
            'apenas_hoje' => $schema->boolean()
                ->description('Só os detectados hoje — o recorte em que a tela abre. Padrão: false.'),
            ...$this->schemaPaginacao($schema),
        ];
    }

    protected function consultar(Request $request, User $usuario): array
    {
        $hoje = now()->toDateString();
        $temVisaoGlobal = $usuario->isAdmin() || $usuario->isGestor() || $usuario->isLiderPub();

        $consulta = Sugador::query()
            ->with('company:id,name')
            ->when(! $temVisaoGlobal, fn ($q) => $q->daCarteira($usuario))
            // Mesma ordem da tela: os de hoje no topo, depois do mais recente.
            ->orderByRaw('CASE WHEN reference_date = ? THEN 0 ELSE 1 END', [$hoje])
            ->orderBy('reference_date', 'desc')
            ->orderBy('id', 'desc');

        $status = $this->texto($request, 'status') ?? Sugador::STATUS_PENDENTE;
        if ($status !== 'todos') {
            $consulta->where('status', $status);
        }

        if ($tipo = $this->texto($request, 'tipo')) {
            $consulta->where('tipo', $tipo);
        }

        if ($empresa = $this->texto($request, 'empresa')) {
            $consulta->whereIn('company_id', $this->empresasDoFiltro($empresa));
        }

        if ($analista = $this->texto($request, 'analista')) {
            if (! $usuario->isAdmin()) {
                throw new ErroDaFerramenta('O filtro por analista é só de admin, como na tela de Sugadores.');
            }
            $consulta->whereIn('company_id', $this->empresasDoAnalista($analista));
        }

        if ($this->booleano($request, 'apenas_hoje')) {
            $consulta->whereDate('reference_date', $hoje);
        } elseif ($data = $this->texto($request, 'data')) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                throw new ErroDaFerramenta('Data inválida: use o formato YYYY-MM-DD.');
            }
            $consulta->whereDate('reference_date', $data);
        }

        $pagina = $this->paginarConsulta($consulta, $request, fn (Sugador $s) => $this->linha($s));

        // Os dois contadores da tela, no recorte do usuário.
        $pendentes = Sugador::pendentes()->when(! $temVisaoGlobal, fn ($q) => $q->daCarteira($usuario));
        $ultimaAnalise = Sugador::query()->max('created_at');

        return [
            ...$pagina,
            'resumo' => [
                'pendentes_total' => (clone $pendentes)->count(),
                'pendentes_hoje'  => (clone $pendentes)->whereDate('reference_date', $hoje)->count(),
                'visao'           => $temVisaoGlobal ? 'todas as empresas' : 'só a sua carteira',
            ],
            'atualizado_em' => $ultimaAnalise ? Carbon::parse($ultimaAnalise)->toIso8601String() : null,
            'fonte' => 'Análise diária de sugadores (12h, dados D-1 da Adman).',
        ];
    }

    /** @return array<string, mixed> */
    private function linha(Sugador $s): array
    {
        return [
            'id'              => $s->id,
            'empresa'         => $s->company ? ['id' => $s->company->id, 'nome' => $s->company->name] : null,
            'tipo'            => $s->tipo,
            'status'          => $s->status,
            'campanha'        => $s->campaign_name,
            'adgroup'         => $s->adgroup_name,
            'mlb_id'          => $s->mlb_id,
            'anuncio'         => $s->mlb_titulo,
            'data_referencia' => $s->reference_date?->toDateString(),
            'periodo'         => [
                'inicio' => $s->periodo_inicio?->toDateString(),
                'fim'    => $s->periodo_fim?->toDateString(),
            ],
            // "Gasto" e "retorno" da especificação = investimento e faturamento do período.
            'gasto'           => $s->investimento_periodo !== null ? (float) $s->investimento_periodo : null,
            'retorno'         => $s->faturamento_periodo !== null ? (float) $s->faturamento_periodo : null,
            'vendas'          => $s->vendas_periodo,
            'acos'            => $s->acos !== null ? (float) $s->acos : null,
            'roas'            => $s->roas !== null ? (float) $s->roas : null,
            'cliques'         => $s->cliques,
            'motivos'         => $s->motivos,
            // Dias desde que a análise o apontou — enquanto pendente, é há
            // quanto tempo ninguém agiu.
            'dias_pendente'   => $s->status === Sugador::STATUS_PENDENTE && $s->reference_date
                ? (int) $s->reference_date->copy()->startOfDay()->diffInDays(now()->startOfDay())
                : null,
            'acao_tomada'     => $s->acao_tomada,
        ];
    }

    /** @return array<int, int> */
    private function empresasDoFiltro(string $filtro): array
    {
        $ids = Company::query()
            ->where(function ($q) use ($filtro) {
                if (ctype_digit($filtro)) {
                    $q->where('id', (int) $filtro)
                      ->orWhere('adman_account_id', $filtro)
                      ->orWhere('ml_store_id', $filtro);
                } else {
                    $q->where('name', 'like', '%'.$filtro.'%');
                }
            })
            ->pluck('id')
            ->all();

        if ($ids === []) {
            throw new ErroDaFerramenta('Empresa não encontrada: "'.$filtro.'". Tente pelo CUST ou por outra parte do nome.');
        }

        return $ids;
    }

    /**
     * Empresas da carteira do analista — mesma régua da tela: a pivot
     * `company_users` grava analista como role 'consultor' (ver o comentário
     * "Fix UAT 2026-07-02" no SugadorController).
     *
     * @return array<int, int>
     */
    private function empresasDoAnalista(string $filtro): array
    {
        $usuarios = ctype_digit($filtro)
            ? [(int) $filtro]
            : User::query()->where('name', 'like', '%'.$filtro.'%')->pluck('id')->all();

        if ($usuarios === []) {
            throw new ErroDaFerramenta('Analista não encontrado: "'.$filtro.'".');
        }

        return DB::table('company_users')
            ->whereIn('user_id', $usuarios)
            ->whereIn('role', ['consultor', 'estrategista'])
            ->pluck('company_id')
            ->unique()
            ->values()
            ->all();
    }
}
