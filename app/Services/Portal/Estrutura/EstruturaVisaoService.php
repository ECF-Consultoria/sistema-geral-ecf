<?php

namespace App\Services\Portal\Estrutura;

use App\Models\Company;
use App\Models\EstruturaAgendaItem;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use Carbon\CarbonImmutable;

/**
 * Os payloads das duas visões do módulo — Ofertas e Agenda.
 *
 * ### Ofertas pagina SEMPRE, no servidor (ADR §Escala)
 * Não há limiar a partir do qual "passa a paginar": seriam dois caminhos, e o
 * de cima só seria exercitado pelo maior cliente (2.688 anúncios ativos). Quem
 * tem pouca coisa vê uma página só, sem paginador.
 *
 * O PAINEL e os CONTADORES dos filtros são somados sobre o conjunto inteiro —
 * nunca sobre a página. Filtro e busca vão por query string; a página já chega
 * recortada.
 */
class EstruturaVisaoService
{
    public const BLOCOS_POR_PAGINA = 25;

    public const FILTROS = ['todas', 'publicar', 'falta', 'completas'];

    /** Limites da agenda por seção (os totais vão sempre inteiros). */
    public const LIMITE_ATRASADAS  = 100;
    public const LIMITE_PROXIMAS   = 100;
    public const LIMITE_CONCLUIDAS = 20;

    public function paginaOfertas(Company $empresa, string $filtro, string $busca, int $pagina): array
    {
        $conjunto = EstruturaConjunto::daEmpresa($empresa);
        $filtro = in_array($filtro, self::FILTROS, true) ? $filtro : 'todas';
        $busca = mb_strtolower(trim($busca));

        $repetidos = $conjunto->skusRepetidos();
        $usoEmKits = $conjunto->usoEmKits();

        $contadores = array_fill_keys(self::FILTROS, 0);
        foreach ($conjunto->ofertas() as $o) {
            foreach (self::FILTROS as $f) {
                if ($this->passaNoFiltro($o, $f)) {
                    $contadores[$f]++;
                }
            }
        }

        $blocos = [];
        foreach ($conjunto->blocos() as $bloco) {
            $ofertas = array_values(array_filter(
                array_map(fn ($id) => $conjunto->oferta($id), $bloco['ofertas']),
                fn ($o) => $this->passaNoFiltro($o, $filtro) && $this->passaNaBusca($o, $busca),
            ));

            if ($ofertas) {
                $blocos[] = ['principal' => $conjunto->oferta($bloco['principal']), 'ofertas' => $ofertas, 'todas' => $bloco['ofertas']];
            }
        }

        $total = count($blocos);
        $paginas = max(1, (int) ceil($total / self::BLOCOS_POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);
        $daPagina = array_slice($blocos, ($pagina - 1) * self::BLOCOS_POR_PAGINA, self::BLOCOS_POR_PAGINA);

        // A agenda só das ofertas desta página — é o que a gaveta mostra.
        $idsDaPagina = [];
        foreach ($daPagina as $b) {
            foreach ($b['ofertas'] as $o) {
                $idsDaPagina[] = $o['id'];
            }
        }
        $agenda = EstruturaAgendaItem::whereIn('oferta_id', $idsDaPagina)
            ->orderBy('data')->orderBy('id')
            ->get()
            ->groupBy('oferta_id');

        $serializar = fn (array $o) => $this->oferta($o, $conjunto, $repetidos, $usoEmKits, $agenda[$o['id']] ?? collect());

        // Quem já tem publicação na agenda — para o resumo dizer se sobrou
        // buraco SEM data. Publicação agendada de oferta não-OK está pendente
        // por definição (a publicação é derivada), então basta existir a linha.
        $comPublicacao = EstruturaAgendaItem::query()
            ->join('estrutura_ofertas as o', 'o.id', '=', 'estrutura_agenda.oferta_id')
            ->where('o.company_id', $empresa->id)
            ->where('estrutura_agenda.acao', EstruturaAgendaItem::ACAO_PUBLICACAO)
            ->distinct()
            ->pluck('estrutura_agenda.oferta_id')
            ->flip()
            ->all();

        $kitsECombits = array_values(array_filter(
            $conjunto->ofertas(),
            fn ($o) => in_array($o['fase'], [EstruturaOferta::FASE_KIT, EstruturaOferta::FASE_COMBIT], true),
        ));

        return [
            'painel'     => $conjunto->painel(),
            'contadores' => $contadores,
            'espera'     => EstruturaAnuncioEspera::where('company_id', $empresa->id)->count(),
            'blocos'     => array_map(fn ($b) => [
                'chave'     => $b['principal']['id'],
                'produto'   => $b['principal']['fase'] === EstruturaOferta::FASE_SIMPLES,
                'principal' => [...$this->resumo($b['principal']), 'situacao' => $b['principal']['situacao']],
                'tambem_em' => array_map(fn ($id) => $this->resumo($conjunto->oferta($id)), $usoEmKits[$b['principal']['id']] ?? []),
                // O bloco nasce RECOLHIDO na tela; o cabeçalho vive deste
                // resumo. Ele é do bloco INTEIRO — nunca do que o filtro
                // deixou —, senão "3 a publicar" viraria "1" ao filtrar.
                'resumo_combos' => $this->contarSituacoes(
                    array_map(fn ($id) => $conjunto->oferta($id), array_slice($b['todas'], 1)),
                    $comPublicacao,
                ),
                'uso'       => $this->contarUso($usoEmKits[$b['principal']['id']] ?? [], $conjunto),
                // Quantidades que este produto já tem como combo (do bloco
                // inteiro): o formulário de combos em lote marca "já existe"
                // em vez de prometer criar o que o servidor vai pular.
                'quantidades_combo' => array_values(array_filter(array_map(
                    fn ($id) => $conjunto->oferta($id)['fase'] === EstruturaOferta::FASE_COMBO
                        ? ($conjunto->oferta($id)['componentes'][0]['quantidade'] ?? null) : null,
                    array_slice($b['todas'], 1),
                ))),
                'ofertas'   => array_map($serializar, $b['ofertas']),
            ], $daPagina),
            // Resumo da seção "Kits e combits", sobre TODOS da empresa — a
            // seção também nasce recolhida, e a página só traz os dela.
            'resumo_kits' => $this->contarSituacoes($kitsECombits, $comPublicacao),
            'proximo_passo' => $this->proximoPasso($empresa, $conjunto, $comPublicacao),
            'paginacao'  => ['pagina' => $pagina, 'paginas' => $paginas, 'blocos' => $total, 'por_pagina' => self::BLOCOS_POR_PAGINA],
        ];
    }

    /**
     * A agenda em seções. Uma publicação está feita quando a oferta está OK
     * AGORA — inclusive se ficou OK por uma colagem, e não pela agenda.
     */
    public function agenda(Company $empresa, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $conjunto = EstruturaConjunto::daEmpresa($empresa);

        $itens = EstruturaAgendaItem::query()
            ->join('estrutura_ofertas as o', 'o.id', '=', 'estrutura_agenda.oferta_id')
            ->where('o.company_id', $empresa->id)
            ->orderBy('estrutura_agenda.data')->orderBy('estrutura_agenda.id')
            ->get(['estrutura_agenda.*']);

        // Ofertas que já têm Jardinagem marcada — a publicação concluída sem
        // ela ganha o atalho "Agendar Jardinagem (+7 dias)" na tela.
        $comJardinagem = $itens->where('acao', EstruturaAgendaItem::ACAO_JARDINAGEM)->pluck('oferta_id')->flip();

        $secoes = ['atrasadas' => [], 'hoje' => [], 'proximas' => [], 'concluidas' => []];

        foreach ($itens as $item) {
            $o = $conjunto->oferta($item->oferta_id);
            if (! $o) {
                continue;
            }

            $publicacao = $item->acao === EstruturaAgendaItem::ACAO_PUBLICACAO;
            $feita = $publicacao ? $o['situacao'] === ReguaEstrutura::SITUACAO_OK : $item->concluida_em !== null;

            $linha = [
                'id'          => $item->id,
                'data'        => $item->data->format('Y-m-d'),
                'acao'        => $item->acao,
                'feita'       => $feita,
                'concluida_em'=> $item->concluida_em?->format('Y-m-d H:i'),
                'oferta'      => $this->resumo($o) + [
                    'unidades'      => $o['unidades'],
                    'classicos'     => $o['classicos'],
                    'premiums'      => $o['premiums'],
                    'catalogos'     => $o['catalogos'],
                    'kits_virtuais' => $o['kits_virtuais'],
                    'situacao'      => $o['situacao'],
                    'anuncios'      => $o['anuncios'],
                    'tem_jardinagem'=> isset($comJardinagem[$o['id']]),
                ],
            ];

            $dia = $item->data->toImmutable()->startOfDay();

            $secao = match (true) {
                $dia->equalTo($hoje) => 'hoje',
                $feita => 'concluidas',
                $dia->lt($hoje) => 'atrasadas',
                default => 'proximas',
            };

            $secoes[$secao][] = $linha;
        }

        // Concluídas: as mais recentes primeiro.
        $secoes['concluidas'] = array_reverse($secoes['concluidas']);

        $limites = ['atrasadas' => self::LIMITE_ATRASADAS, 'hoje' => null, 'proximas' => self::LIMITE_PROXIMAS, 'concluidas' => self::LIMITE_CONCLUIDAS];

        $resultado = ['hoje_data' => $hoje->format('Y-m-d'), 'painel' => $conjunto->painel(), 'secoes' => []];
        foreach ($secoes as $nome => $linhas) {
            $resultado['secoes'][$nome] = [
                'total' => count($linhas),
                'itens' => $limites[$nome] === null ? $linhas : array_slice($linhas, 0, $limites[$nome]),
            ];
        }

        return $resultado;
    }

    /** As linhas da espera, para o diálogo "Resolver". */
    public function espera(Company $empresa): array
    {
        return EstruturaAnuncioEspera::where('company_id', $empresa->id)
            ->orderBy('id')
            ->get()
            ->map(fn (EstruturaAnuncioEspera $l) => [
                'id'           => $l->id,
                'sku_colado'   => $l->sku_colado,
                'motivo'       => $l->motivo,
                'motivo_texto' => EstruturaAnuncioEspera::MOTIVOS[$l->motivo] ?? $l->motivo,
                'tipo'         => $l->tipo,
                'status'       => $l->status,
                'catalogo'     => $l->catalogo,
                'kit_virtual'  => $l->kit_virtual,
                'codigo_mlb'   => $l->codigo_mlb,
                'titulo'       => $l->titulo,
            ])->all();
    }

    /** Ofertas enxutas para os seletores (vincular, compor kit). */
    public function opcoesDeOfertas(Company $empresa): array
    {
        return EstruturaOferta::where('company_id', $empresa->id)
            ->orderBy('sku')
            ->get(['id', 'sku', 'nome', 'fase'])
            ->map(fn ($o) => ['id' => $o->id, 'sku' => $o->sku, 'nome' => $o->nome, 'fase' => $o->fase])
            ->all();
    }

    /** Os vocabulários fechados, que o JSX só EXIBE — quem decide é o PHP. */
    /**
     * Os rótulos que a TELA mostra — em português de quem vende, com o termo
     * da aula entre parênteses, porque o cliente é leigo em sistema e o
     * analista ensina usando a aula (24/09). As constantes dos models seguem
     * com os nomes da planilha: são elas que as mensagens do servidor e o
     * activity log usam.
     */
    public static function vocabulario(): array
    {
        return [
            'fases' => [
                EstruturaOferta::FASE_SIMPLES => 'Produto',
                EstruturaOferta::FASE_COMBO   => 'Combo',
                EstruturaOferta::FASE_KIT     => 'Kit',
                EstruturaOferta::FASE_COMBIT  => 'Kit com mais unidades (Combit)',
            ],
            'logisticas' => EstruturaOferta::LOGISTICAS,
            'tipos' => [
                EstruturaAnuncio::TIPO_CLASSICO => 'À vista (Clássico)',
                EstruturaAnuncio::TIPO_PREMIUM  => 'Parcelado (Premium)',
            ],
            'tipos_curtos' => [
                EstruturaAnuncio::TIPO_CLASSICO => 'À vista',
                EstruturaAnuncio::TIPO_PREMIUM  => 'Parcelado',
            ],
            'status'    => EstruturaAnuncio::STATUS,
            'situacoes' => [
                ReguaEstrutura::SITUACAO_OK             => 'Pronta: à vista e parcelado no ar',
                ReguaEstrutura::SITUACAO_FALTA_CLASSICO => 'Falta o anúncio à vista (Clássico)',
                ReguaEstrutura::SITUACAO_FALTA_PREMIUM  => 'Falta o anúncio parcelado (Premium)',
                ReguaEstrutura::SITUACAO_PUBLICAR       => 'Publicar os dois: à vista e parcelado',
            ],
            'acoes' => [
                EstruturaAgendaItem::ACAO_PUBLICACAO => 'Publicar',
                EstruturaAgendaItem::ACAO_JARDINAGEM => 'Revisar anúncio (Jardinagem)',
            ],
            'motivos' => [
                EstruturaAnuncioEspera::MOTIVO_SEM_OFERTA   => 'O código do produto não bate com nenhum produto seu',
                EstruturaAnuncioEspera::MOTIVO_SKU_REPETIDO => 'Dois produtos seus têm esse mesmo código',
                EstruturaAnuncioEspera::MOTIVO_SEM_SKU      => 'Veio sem código do produto',
            ],
            'dias_ate_jardinagem' => EstruturaAgendaItem::DIAS_ATE_JARDINAGEM,
        ];
    }

    /**
     * Quantas ofertas em cada situação, e quantas das que não estão OK ainda
     * não têm publicação na agenda (`sem_agenda`) — o buraco sem data.
     *
     * @param  array<int, array>  $ofertas
     * @param  array<int, mixed>  $comPublicacao  oferta_id → (qualquer) de quem tem publicação
     * @return array{total: int, ok: int, falta: int, publicar: int, sem_agenda: int}
     */
    private function contarSituacoes(array $ofertas, array $comPublicacao): array
    {
        $r = ['total' => 0, 'ok' => 0, 'falta' => 0, 'publicar' => 0, 'sem_agenda' => 0];

        foreach ($ofertas as $o) {
            $r['total']++;

            match ($o['situacao']) {
                ReguaEstrutura::SITUACAO_OK => $r['ok']++,
                ReguaEstrutura::SITUACAO_PUBLICAR => $r['publicar']++,
                default => $r['falta']++,
            };

            if ($o['situacao'] !== ReguaEstrutura::SITUACAO_OK && ! isset($comPublicacao[$o['id']])) {
                $r['sem_agenda']++;
            }
        }

        return $r;
    }

    /**
     * A faixa do topo: UMA coisa a fazer agora, em ordem de urgência. Não
     * esconde nada da tela — só aponta. O cliente é leigo e o analista o
     * ensina uma vez; depois, é esta faixa que o lembra do método.
     *
     * 1. `cadastrar`  — nenhuma oferta ainda;
     * 2. `hoje`       — publicação ou revisão com data até hoje, não feita;
     * 3. `agendar`    — ofertas com buraco e SEM publicação na agenda;
     * 4. `variacoes`  — produtos que não entram em nenhum combo/kit (a
     *                   pergunta da aula: "dá combo? combina com qual?");
     * 5. `em_dia`     — nada acima.
     *
     * @param  array<int, mixed>  $comPublicacao
     * @return array{tipo: string, quantidade?: int, primeira?: array, acao?: string}
     */
    private function proximoPasso(Company $empresa, EstruturaConjunto $conjunto, array $comPublicacao, ?CarbonImmutable $hoje = null): array
    {
        $ofertas = $conjunto->ofertas();

        if (! $ofertas) {
            return ['tipo' => 'cadastrar'];
        }

        $hoje ??= CarbonImmutable::today();

        $pendentes = EstruturaAgendaItem::query()
            ->join('estrutura_ofertas as o', 'o.id', '=', 'estrutura_agenda.oferta_id')
            ->where('o.company_id', $empresa->id)
            ->whereDate('estrutura_agenda.data', '<=', $hoje->format('Y-m-d'))
            ->orderBy('estrutura_agenda.data')->orderBy('estrutura_agenda.id')
            ->get(['estrutura_agenda.*'])
            ->filter(fn (EstruturaAgendaItem $i) => $i->acao === EstruturaAgendaItem::ACAO_PUBLICACAO
                ? ($conjunto->oferta($i->oferta_id)['situacao'] ?? ReguaEstrutura::SITUACAO_OK) !== ReguaEstrutura::SITUACAO_OK
                : $i->concluida_em === null)
            ->values();

        if ($pendentes->isNotEmpty()) {
            $primeira = $pendentes->first();

            return [
                'tipo'       => 'hoje',
                'quantidade' => $pendentes->count(),
                'primeira'   => [
                    ...$this->resumo($conjunto->oferta($primeira->oferta_id)),
                    'acao'  => $primeira->acao,
                    'data'  => $primeira->data->format('Y-m-d'),
                ],
            ];
        }

        $semData = $this->contarSituacoes($ofertas, $comPublicacao)['sem_agenda'];
        if ($semData > 0) {
            return ['tipo' => 'agendar', 'quantidade' => $semData];
        }

        $semVariacao = count(array_filter(
            $ofertas,
            fn ($o) => $o['fase'] === EstruturaOferta::FASE_SIMPLES && $conjunto->vezesUsadaComoComponente($o['id']) === 0,
        ));
        if ($semVariacao > 0) {
            return ['tipo' => 'variacoes', 'quantidade' => $semVariacao];
        }

        return ['tipo' => 'em_dia'];
    }

    /** "Entra em 1 kit e 1 combit" — contagem curta no lugar da lista por extenso. */
    private function contarUso(array $ids, EstruturaConjunto $conjunto): array
    {
        $uso = ['kits' => 0, 'combits' => 0];

        foreach ($ids as $id) {
            $fase = $conjunto->oferta($id)['fase'] ?? null;
            if ($fase === EstruturaOferta::FASE_KIT) {
                $uso['kits']++;
            } elseif ($fase === EstruturaOferta::FASE_COMBIT) {
                $uso['combits']++;
            }
        }

        return $uso;
    }

    private function passaNoFiltro(array $o, string $filtro): bool
    {
        return match ($filtro) {
            'publicar'  => $o['situacao'] === ReguaEstrutura::SITUACAO_PUBLICAR,
            'falta'     => in_array($o['situacao'], [ReguaEstrutura::SITUACAO_FALTA_CLASSICO, ReguaEstrutura::SITUACAO_FALTA_PREMIUM], true),
            'completas' => $o['situacao'] === ReguaEstrutura::SITUACAO_OK,
            default     => true,
        };
    }

    /** SKU, nome, MLB ou título de anúncio — "onde está o MLB tal?" leva à oferta. */
    private function passaNaBusca(array $o, string $busca): bool
    {
        if ($busca === '') {
            return true;
        }

        $contem = fn (?string $t) => $t !== null && str_contains(mb_strtolower($t), $busca);

        if ($contem($o['sku']) || $contem($o['nome'])) {
            return true;
        }

        foreach ($o['anuncios'] as $a) {
            if ($contem($a['codigo_mlb']) || $contem($a['titulo'])) {
                return true;
            }
        }

        return false;
    }

    private function resumo(array $o): array
    {
        return ['id' => $o['id'], 'sku' => $o['sku'], 'nome' => $o['nome'], 'fase' => $o['fase']];
    }

    private function oferta(array $o, EstruturaConjunto $conjunto, array $repetidos, array $usoEmKits, $agenda): array
    {
        return [
            ...$this->resumo($o),
            'logistica'     => $o['logistica'],
            'observacoes'   => $o['observacoes'],
            'unidades'      => $o['unidades'],
            'componentes'   => array_map(fn ($c) => [
                ...$this->resumo($conjunto->oferta($c['id']) ?? ['id' => $c['id'], 'sku' => '?', 'nome' => null, 'fase' => 'simples']),
                'quantidade' => $c['quantidade'],
            ], $o['componentes']),
            'anuncios'      => $o['anuncios'],
            'classicos'     => $o['classicos'],
            'premiums'      => $o['premiums'],
            'catalogos'     => $o['catalogos'],
            'kits_virtuais' => $o['kits_virtuais'],
            'situacao'      => $o['situacao'],
            'sku_repetido'  => isset($repetidos[EstruturaOferta::normalizarSku($o['sku'])]),
            'usada_em'      => $conjunto->vezesUsadaComoComponente($o['id']),
            'agenda'        => $agenda->map(fn (EstruturaAgendaItem $i) => [
                'id'           => $i->id,
                'data'         => $i->data->format('Y-m-d'),
                'acao'         => $i->acao,
                'feita'        => $i->acao === EstruturaAgendaItem::ACAO_PUBLICACAO
                    ? $o['situacao'] === ReguaEstrutura::SITUACAO_OK
                    : $i->concluida_em !== null,
            ])->values()->all(),
        ];
    }
}
