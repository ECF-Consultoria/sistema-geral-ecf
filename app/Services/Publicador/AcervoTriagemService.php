<?php

namespace App\Services\Publicador;

use App\Models\Company;
use App\Models\MlAcervoItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Fonte única de triagem/defasagem do acervo ML (Fase 134 D-09/D-08).
 *
 * Extraído de `MlbAnuncioController::meus()` (Fase 173 Plan 02) para que a
 * Visão geral (Fase 173, plans seguintes) use os MESMOS agregados sem
 * reimplementar a query — ver `134-CONTEXT.md` e
 * `design_handoff_publicador/ETAPA-2-visao-geral.md` seção 4.
 *
 * `motivosDef()` continua sendo a FONTE ÚNICA da definição dos 5 motivos —
 * nunca duplicar esta lista em outro lugar do código.
 *
 * Serviço sem dependências de construtor: todo método recebe a `Company` e,
 * quando precisa, os mesmos filtros de busca/status já usados por
 * `MeusAnuncios.jsx`.
 */
class AcervoTriagemService
{
    /**
     * Escopo base do acervo por empresa (T-134-01) — company_id é a fronteira
     * de segurança inteira desta tela e não pode sair de nenhum caminho:
     * busca, triagem, contagem, paginação. A busca é OBRIGATORIAMENTE
     * agrupada dentro de where(function...): um orWhere solto sobe ao topo
     * do WHERE e anula o escopo por empresa (mesma pegadinha travada em
     * historico(), Fase 86).
     */
    public function escopo(Company $company, string $busca, string $statusFiltro): Builder
    {
        // 'acionaveis' (default) cobre DOIS status — é o universo sobre o qual
        // a triagem do D-09 conta e a ordenação do D-12 opera. Os demais
        // filtros recortam um status só; 'todos' não filtra.
        $statusColunas = match ($statusFiltro) {
            'acionaveis' => ['active', 'paused'],
            'ativos'     => ['active'],
            'pausados'   => ['paused'],
            'encerrados' => ['closed'],
            default      => null, // 'todos' não filtra
        };

        return MlAcervoItem::where('company_id', $company->id)
            ->when($busca !== '', function ($q) use ($busca) {
                $q->where(function ($s) use ($busca) {
                    $s->where('title', 'like', "%{$busca}%")
                      ->orWhere('ml_item_id', 'like', "%{$busca}%");
                });
            })
            ->when($statusColunas !== null, fn ($q) => $q->whereIn('status', $statusColunas));
    }

    /**
     * Definição fechada dos 5 motivos de triagem (D-09), na mesma ordem de
     * gravidade do D-12: pausado/sem estoque (crítica, red) antes de ficha
     * incompleta/perdendo catálogo/foto insuficiente (atenção, amber).
     * Fonte única para a whitelist de validação e para os chips/selects
     * agregados — nunca duplicar esta lista.
     *
     * @return array<int, array{chave:string, label:string, cor:string}>
     */
    public function motivosDef(): array
    {
        return [
            ['chave' => MlAcervoItem::MOTIVO_PAUSADO,           'label' => 'Pausado',           'cor' => 'red'],
            ['chave' => MlAcervoItem::MOTIVO_SEM_ESTOQUE,       'label' => 'Sem estoque',        'cor' => 'red'],
            ['chave' => MlAcervoItem::MOTIVO_FICHA_INCOMPLETA,  'label' => 'Ficha incompleta',   'cor' => 'amber'],
            ['chave' => MlAcervoItem::MOTIVO_PERDENDO_CATALOGO, 'label' => 'Perdendo catálogo',  'cor' => 'amber'],
            ['chave' => MlAcervoItem::MOTIVO_FOTO_INSUFICIENTE, 'label' => 'Foto insuficiente',  'cor' => 'amber'],
        ];
    }

    /**
     * Triagem agregada (D-09) — UMA query, nunca um laço de ->count() por
     * motivo. Reusa os MESMOS filtros de status/busca de `escopo()`, mas SEM
     * filtro de motivo: os chips precisam continuar mostrando os outros
     * motivos quando um já está filtrado na listagem.
     *
     * @return array{total:int, chips:array, nao_avaliado:int}
     */
    public function triagem(Company $company, string $busca, string $statusFiltro): array
    {
        $motivosDef = $this->motivosDef();

        $selects = [
            // Total = anúncios DISTINTOS com >=1 motivo, nunca soma dos chips
            // (severidade > 0 <=> motivos não vazio, ver AnuncioSaudeService::triagem()).
            'SUM(CASE WHEN severidade > 0 THEN 1 ELSE 0 END) as total_com_motivo',
            'SUM(CASE WHEN catalog_listing = 1 AND buybox_status IS NULL THEN 1 ELSE 0 END) as nao_avaliado',
        ];
        foreach ($motivosDef as $i => $m) {
            // $m['chave'] vem da whitelist fechada (constantes do model), nunca da querystring.
            $selects[] = "SUM(CASE WHEN motivos LIKE '%\"{$m['chave']}\"%' THEN 1 ELSE 0 END) as motivo_{$i}";
        }

        $linhaTriagem = $this->escopo($company, $busca, $statusFiltro)
            ->selectRaw(implode(', ', $selects))
            ->first();

        $chips = [];
        foreach ($motivosDef as $i => $m) {
            $chips[] = [
                'chave' => $m['chave'],
                'label' => $m['label'],
                'count' => (int) ($linhaTriagem->{"motivo_{$i}"} ?? 0),
                'cor'   => $m['cor'],
            ];
        }

        return [
            'total'        => (int) ($linhaTriagem->total_com_motivo ?? 0),
            'chips'        => $chips,
            'nao_avaliado' => (int) ($linhaTriagem->nao_avaliado ?? 0),
        ];
    }

    /**
     * Defasagem da coleta (D-08) — nunca resposta vazia. `nunca_coletado`
     * distingue "nunca medimos" de "medimos e é zero": acervo nunca coletado
     * tem que aparecer na tela como "—" + "Atualizar agora", NUNCA como zero
     * (critério de aceite da Visão geral,
     * design_handoff_publicador/ETAPA-2-visao-geral.md seção 7).
     *
     * @return array{coletado_em:?string, horas:?int, defasado:bool, nunca_coletado:bool, motivo:?string}
     */
    public function defasagem(Company $company): array
    {
        $temLinhas     = MlAcervoItem::where('company_id', $company->id)->exists();
        $nuncaColetado = ! $temLinhas;
        $coletadoEmRaw = $nuncaColetado ? null : MlAcervoItem::where('company_id', $company->id)->max('coletado_em');
        $coletadoEm    = $coletadoEmRaw !== null ? Carbon::parse($coletadoEmRaw) : null;
        $horas         = $coletadoEm !== null ? $coletadoEm->diffInHours(now()) : null;
        $limiteHoras   = (int) config('mlb_acervo.defasagem_horas');
        $motivoErro    = MlAcervoItem::where('company_id', $company->id)
            ->whereNotNull('coleta_erro')
            ->orderByDesc('updated_at')
            ->value('coleta_erro');

        return [
            'coletado_em'    => $coletadoEm?->toIso8601String(),
            'horas'          => $horas,
            'defasado'       => $horas !== null && $horas > $limiteHoras,
            'nunca_coletado' => $nuncaColetado,
            'motivo'         => $motivoErro,
        ];
    }

    /**
     * Conta anúncios no escopo 'acionaveis' (sem busca) cujo `motivos`
     * contém QUALQUER um dos motivos passados — usado pela linha 2 ("Anúncios
     * pausados ou sem estoque") e linha 8 ("Ficha, catálogo ou foto") de "O
     * que fazer agora" (Fase 173 Plan 05).
     *
     * Um orWhere por motivo, SEMPRE agrupado dentro de where(function...) —
     * nunca um orWhere solto, mesma armadilha documentada no docblock de
     * `escopo()`.
     *
     * @param  array<int,string> $motivos  chaves de MlAcervoItem::MOTIVO_*
     */
    public function comMotivos(Company $company, array $motivos): int
    {
        return $this->escopo($company, '', 'acionaveis')
            ->where(function ($q) use ($motivos) {
                foreach ($motivos as $motivo) {
                    $q->orWhere('motivos', 'like', '%"' . $motivo . '"%');
                }
            })
            ->count();
    }

    /**
     * Mesmo filtro de `comMotivos()`, restrito a `origem = legado` — usado
     * pela linha 2 de "O que fazer agora" para mostrar "quantos são legado"
     * (Fase 173 Plan 05).
     *
     * @param  array<int,string> $motivos  chaves de MlAcervoItem::MOTIVO_*
     */
    public function legadoEntre(Company $company, array $motivos): int
    {
        return $this->escopo($company, '', 'acionaveis')
            ->where('origem', MlAcervoItem::ORIGEM_LEGADO)
            ->where(function ($q) use ($motivos) {
                foreach ($motivos as $motivo) {
                    $q->orWhere('motivos', 'like', '%"' . $motivo . '"%');
                }
            })
            ->count();
    }
}
