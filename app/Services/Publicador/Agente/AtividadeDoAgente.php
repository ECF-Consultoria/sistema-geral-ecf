<?php

namespace App\Services\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Models\PubAprovacaoEvento;
use App\Models\PubProduto;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * O REGISTRO DE ATIVIDADE DE UMA LOJA (E1, 23/10/2026) — e ele é uma **PROJEÇÃO**, não uma tabela.
 *
 * ⚠️⚠️ **NÃO HÁ TABELA NOVA, E ISSO É DECISÃO MEDIDA, NÃO PREGUIÇA.** A §5.5 do plano do Agente
 * diz *"dados novos, apenas onde há lacuna"*, e **não há lacuna**: tudo o que a Atividade precisa
 * mostrar já está persistido em três tabelas que o agente e o portão já escrevem.
 *
 *   | o que a tela mostra          | de onde vem                        | quem já escreve          |
 *   |------------------------------|------------------------------------|--------------------------|
 *   | preparo de um produto        | `pub_agente_execucoes`             | `EntradaDoPreparo`       |
 *   | configuração, pausa, retomada| `pub_agente_configuracao_versoes`  | `ConfiguracaoDoAgente…`  |
 *   | aprovação, falha, exceção    | `pub_aprovacao_eventos`            | `HistoricoDaOfertaService`|
 *
 * ⚠️ **Pausar, suspender e retomar TAMBÉM gravam versão** (o docblock do `ControleDoAgente` diz:
 * *"grava o controle… com versão e snapshot"*) — medido antes de escrever. É por isso que o
 * controle **não** precisa de registro próprio: ele já deixa rastro com autor e data.
 *
 * ⚠️ **`pub_aprovacao_eventos` é APPEND-ONLY e tem porta única de escrita.** Esta classe **só lê**,
 * e nunca deve virar um segundo caminho de escrita — seria desfazer a garantia da auditoria.
 *
 * ═══ DUAS PALAVRAS QUE NÃO PODEM SE CONFUNDIR ═════════════════════════════════════════════════
 *
 * ⚠️⚠️ **`fonte` ≠ `origem`.** Prop com DOIS sentidos foi achada como defeito grave na Fase 186, e
 * aqui havia colisão esperando:
 *
 *   · **`fonte`** = de QUAL das três tabelas a linha veio (`preparo`/`configuracao`/`aprovacao`);
 *   · **`origem`** = QUEM disparou (`automatica`/`manual`) — é o sentido que `PubAgenteExecucao::
 *     ORIGENS` já usa, e o que a pessoa quer filtrar ("foi o agente ou fui eu?").
 *
 * ═══ POR QUE A PÁGINA SAI DO BANCO, E NÃO DE UM MERGE EM PHP ═══════════════════════════════════
 *
 * Três consultas com teto, juntadas e cortadas em PHP, dariam `total` e número de páginas
 * **MENTIROSOS** assim que uma das fontes passasse do teto — e a pessoa rolaria até o fim de uma
 * lista que não acabou. Então é `UNION ALL` no banco, ordenação e página **no banco**.
 *
 * ⚠️ **O agrupamento por dia e a leitura do JSON ficam em PHP, de propósito.** `DATE()` e
 * `JSON_EXTRACT` divergem entre o MySQL 8 de produção, o MariaDB local e o SQLite dos testes —
 * este projeto já perdeu deploy para divergência dessas. Em PHP é portável e o resultado é o mesmo.
 *
 * ═══ UNIDADES, COMO O RESTO DO AGENTE ══════════════════════════════════════════════════════════
 *
 * Preparo conta **PRODUTOS**; aprovação e publicação contam **OFERTAS**. É a mesma regra que o
 * `LeituraDoAgente` já declara, e ela aparece no rótulo de cada linha — não só nos contadores.
 *
 * ═══ ⚠️ O QUE ESTA FATIA **NÃO** FAZ (dito aqui para ninguém presumir) ═════════════════════════
 *
 *   · **não diz QUAIS campos mudaram** entre duas versões da configuração. O snapshot de cada
 *     versão está em `dados`, então o diff é possível — mas comparar versões vizinhas quebra na
 *     fronteira da página, e fazer certo é fatia própria. Aqui a linha diz *"Configuração salva
 *     (versão N)"* e mostra o ESTADO naquela versão. **Não afirma o que mudou.**
 *   · **não tem evidências/anexos** por linha (o desenho pede; é E6).
 *   · **não mostra promoção automática nem correção humana como tipos próprios** — eles existem
 *     como eventos da oferta e entram pela fonte `aprovacao`, com o rótulo que o portão já dá.
 */
class AtividadeDoAgente
{
    /** Linhas por página. Vinte e cinco cabe num dia cheio sem fazer a pessoa paginar a cada clique. */
    public const POR_PAGINA = 25;

    public const FONTE_PREPARO = 'preparo';
    public const FONTE_CONFIGURACAO = 'configuracao';
    public const FONTE_APROVACAO = 'aprovacao';

    /** As três fontes, na ordem em que aparecem no filtro. */
    public const FONTES = [self::FONTE_PREPARO, self::FONTE_CONFIGURACAO, self::FONTE_APROVACAO];

    /** Rótulo pt-BR de cada fonte — VOC: nada de nome de tabela na tela. */
    public const ROTULO_DA_FONTE = [
        self::FONTE_PREPARO => 'Preparo',
        self::FONTE_CONFIGURACAO => 'Configuração',
        self::FONTE_APROVACAO => 'Aprovação',
    ];

    public const ORIGEM_AUTOMATICA = 'automatica';
    public const ORIGEM_MANUAL = 'manual';

    public const ROTULO_DA_ORIGEM = [
        self::ORIGEM_AUTOMATICA => 'O agente',
        self::ORIGEM_MANUAL => 'Uma pessoa',
    ];

    /**
     * O resultado de cada estado de execução, em pt-BR.
     *
     * ⚠️ Lista COMPLETA dos sete estados de `PubAgenteExecucao`, de propósito: estado novo que não
     * entre aqui cai no fallback e aparece cru na tela, e há teste cobrando a bijeção.
     */
    public const ROTULO_DO_ESTADO = [
        PubAgenteExecucao::ADMITIDA => 'Admitido para preparo',
        PubAgenteExecucao::PREPARANDO => 'Preparando',
        PubAgenteExecucao::AGUARDANDO_APROVACAO => 'Esperando aprovação',
        PubAgenteExecucao::CONCLUIDA => 'Preparo concluído',
        PubAgenteExecucao::BLOQUEADA => 'Faltam dados',
        PubAgenteExecucao::FALHOU => 'Falhou',
        PubAgenteExecucao::CANCELADA => 'Cancelado',
    ];

    /** Rótulo do controle gravado no snapshot de uma versão da configuração. */
    public const ROTULO_DO_CONTROLE = [
        'ativo' => 'ativo',
        'pausado' => 'pausado',
        'suspenso' => 'suspenso',
    ];

    /**
     * A atividade de UMA loja, já paginada e agrupada por dia.
     *
     * @param  array  $conta  O recorte do Publicador (`chave`, `company`, `mlb_empresa`).
     * @param  array{de?:?string,ate?:?string,fonte?:?string,origem?:?string,produto_id?:?int,resultado?:?string}  $filtros
     * @return array{
     *     dias: list<array{dia:string,rotulo:string,linhas:list<array<string,mixed>>}>,
     *     pagina: int, paginas: int, total: int, por_pagina: int,
     *     filtros: array<string,mixed>, opcoes: array<string,mixed>
     * }
     */
    public function paraConta(array $conta, array $filtros = [], int $pagina = 1): array
    {
        $f = $this->saneia($filtros);
        $consulta = $this->uniao($conta, $f);

        // ⚠️ O `count()` sai da MESMA subconsulta filtrada — contar outra coisa faria o número de
        // páginas discordar das linhas, que é o modo clássico de a última página vir vazia.
        $total = $consulta === null ? 0 : (int) DB::query()->fromSub($consulta, 'atv')->count();
        $paginas = (int) max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = (int) min(max(1, $pagina), $paginas);

        $cruas = $consulta === null ? collect() : DB::query()->fromSub($consulta, 'atv')
            // ⚠️ Desempate por `fonte` e `chave`: só `quando` não é estável (duas linhas podem
            // cair no mesmo segundo), e ordenação instável faz a MESMA linha aparecer em duas
            // páginas e outra em nenhuma.
            ->orderByDesc('quando')->orderByDesc('fonte')->orderByDesc('chave')
            ->forPage($pagina, self::POR_PAGINA)->get();

        $linhas = $this->monta($cruas, $conta);

        return [
            'dias' => $this->porDia($linhas),
            'pagina' => $pagina,
            'paginas' => $paginas,
            'total' => $total,
            'por_pagina' => self::POR_PAGINA,
            'filtros' => $f,
            'opcoes' => $this->opcoes(),
        ];
    }

    /** Os valores que o filtro oferece — a tela não inventa opção, ela lê esta lista. */
    public function opcoes(): array
    {
        return [
            'fontes' => array_map(
                fn (string $k) => ['valor' => $k, 'rotulo' => self::ROTULO_DA_FONTE[$k]],
                self::FONTES,
            ),
            'origens' => array_map(
                fn (string $k) => ['valor' => $k, 'rotulo' => self::ROTULO_DA_ORIGEM[$k]],
                [self::ORIGEM_AUTOMATICA, self::ORIGEM_MANUAL],
            ),
            'resultados' => array_map(
                fn (string $k) => ['valor' => $k, 'rotulo' => self::ROTULO_DO_ESTADO[$k]],
                array_keys(self::ROTULO_DO_ESTADO),
            ),
        ];
    }

    /**
     * Normaliza o que veio da URL. Filtro forjado é **descartado em silêncio** — é a mesma regra
     * dos filtros da fila de Aprovação (187-06): valor fora da lista fechada não vira erro de
     * validação na cara da pessoa, ele simplesmente não filtra.
     */
    private function saneia(array $filtros): array
    {
        $data = function ($v): ?string {
            if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return null;
            }

            return $v;
        };

        $de = $data($filtros['de'] ?? null);
        $ate = $data($filtros['ate'] ?? null);

        // ⚠️ Intervalo invertido não devolve zero linhas em silêncio: a gente troca as pontas.
        // "De 23 a 20" é erro de digitação, e lista vazia faria a pessoa achar que não houve nada.
        if ($de !== null && $ate !== null && $de > $ate) {
            [$de, $ate] = [$ate, $de];
        }

        $fonte = $filtros['fonte'] ?? null;
        $origem = $filtros['origem'] ?? null;
        $resultado = $filtros['resultado'] ?? null;
        $produto = $filtros['produto_id'] ?? null;

        return [
            'de' => $de,
            'ate' => $ate,
            'fonte' => in_array($fonte, self::FONTES, true) ? $fonte : null,
            'origem' => in_array($origem, [self::ORIGEM_AUTOMATICA, self::ORIGEM_MANUAL], true) ? $origem : null,
            'resultado' => isset(self::ROTULO_DO_ESTADO[$resultado]) ? $resultado : null,
            'produto_id' => is_numeric($produto) && (int) $produto > 0 ? (int) $produto : null,
        ];
    }

    /**
     * As três fontes normalizadas no MESMO formato de coluna e juntadas por `UNION ALL`.
     *
     * ⚠️ `UNION ALL`, nunca `UNION`: `UNION` deduplica, e duas linhas legítimas com os mesmos
     * valores (duas pausas no mesmo segundo) seriam engolidas — perder evento de auditoria em
     * silêncio é o pior defeito possível nesta tela.
     *
     * @return \Illuminate\Database\Query\Builder|null `null` quando a conta não tem âncora nenhuma.
     */
    private function uniao(array $conta, array $f)
    {
        $partes = [];

        if ($f['fonte'] === null || $f['fonte'] === self::FONTE_PREPARO) {
            $partes[] = $this->dePreparo($conta, $f);
        }
        if ($f['fonte'] === null || $f['fonte'] === self::FONTE_CONFIGURACAO) {
            $p = $this->deConfiguracao($conta, $f);
            if ($p !== null) {
                $partes[] = $p;
            }
        }
        if ($f['fonte'] === null || $f['fonte'] === self::FONTE_APROVACAO) {
            $p = $this->deAprovacao($conta, $f);
            if ($p !== null) {
                $partes[] = $p;
            }
        }

        if ($partes === []) {
            return null;
        }

        $base = array_shift($partes);
        foreach ($partes as $p) {
            $base->unionAll($p);
        }

        return $base;
    }

    /** As colunas que TODA parte da união precisa ter, na MESMA ordem. */
    private function colunas(string $fonte): string
    {
        return "'{$fonte}' as fonte";
    }

    private function dePreparo(array $conta, array $f)
    {
        $q = DB::table('pub_agente_execucoes')
            ->selectRaw($this->colunas(self::FONTE_PREPARO))
            ->selectRaw('id as chave')
            ->selectRaw('estado as tipo')
            ->selectRaw('origem as origem')
            // ⚠️ `iniciada_em` pode ser nula (admitida e ainda não começou) — `created_at` é o
            // fallback, nunca nulo. Linha com `quando` nulo iria para o fim da ordenação e a
            // pessoa veria o evento mais novo no rodapé.
            ->selectRaw('coalesce(concluida_em, iniciada_em, created_at) as quando')
            ->selectRaw('produto_id as produto_id')
            ->selectRaw('solicitada_por as autor_id')
            ->selectRaw('estado as resultado')
            ->selectRaw('motivo as motivo')
            ->selectRaw('rascunho_id as rascunho_id')
            ->selectRaw('null as oferta_id')
            ->selectRaw('configuracao_versao as versao')
            ->selectRaw('null as extra')
            ->where('conta_chave', $conta['chave']);

        $this->aplicaComuns($q, $f, 'coalesce(concluida_em, iniciada_em, created_at)');

        if ($f['origem'] !== null) {
            // ⚠️ `ao_salvar` conta como MANUAL: alguém salvou um produto e isso disparou o preparo.
            // Tratá-lo como automático diria "foi o agente" sobre um gesto de pessoa.
            $q->whereIn('origem', $f['origem'] === self::ORIGEM_AUTOMATICA
                ? [PubAgenteExecucao::ORIGEM_AUTOMATICA]
                : [PubAgenteExecucao::ORIGEM_MANUAL, PubAgenteExecucao::ORIGEM_AO_SALVAR]);
        }
        if ($f['resultado'] !== null) {
            $q->where('estado', $f['resultado']);
        }
        if ($f['produto_id'] !== null) {
            $q->where('produto_id', $f['produto_id']);
        }

        return $q;
    }

    private function deConfiguracao(array $conta, array $f)
    {
        // ⚠️ O filtro por produto ou por resultado de execução EXCLUI esta fonte inteira, em vez
        // de a trazer sem filtrar: versão de configuração não tem produto nem estado de preparo, e
        // devolvê-la junto faria a pessoa achar que o filtro não funcionou.
        if ($f['produto_id'] !== null || $f['resultado'] !== null) {
            return null;
        }

        $cfg = PubAgenteConfiguracao::query()->where('conta_chave', $conta['chave'])->value('id');
        if ($cfg === null) {
            return null;
        }

        $q = DB::table('pub_agente_configuracao_versoes')
            ->selectRaw($this->colunas(self::FONTE_CONFIGURACAO))
            ->selectRaw('id as chave')
            ->selectRaw("'versao' as tipo")
            // ⚠️ Versão sem autor é do sistema (a FK é `nullOnDelete`, então autor apagado também
            // cai aqui) — e a tela diz "O agente" sem fingir saber quem era.
            ->selectRaw('case when autor_id is null then \''.self::ORIGEM_AUTOMATICA.'\' else \''.self::ORIGEM_MANUAL.'\' end as origem')
            ->selectRaw('criada_em as quando')
            ->selectRaw('null as produto_id')
            ->selectRaw('autor_id as autor_id')
            ->selectRaw('null as resultado')
            ->selectRaw('null as motivo')
            ->selectRaw('null as rascunho_id')
            ->selectRaw('null as oferta_id')
            ->selectRaw('versao as versao')
            // ⚠️ O snapshot vem como TEXTO e é decodificado em PHP — `JSON_EXTRACT` não é portável
            // entre o MySQL 8 de produção e o SQLite dos testes.
            ->selectRaw('dados as extra')
            ->where('configuracao_id', $cfg);

        $this->aplicaComuns($q, $f, 'criada_em');

        if ($f['origem'] === self::ORIGEM_AUTOMATICA) {
            $q->whereNull('autor_id');
        }
        if ($f['origem'] === self::ORIGEM_MANUAL) {
            $q->whereNotNull('autor_id');
        }

        return $q;
    }

    private function deAprovacao(array $conta, array $f)
    {
        // Mesma razão do bloco de cima: evento de oferta não tem estado de preparo.
        if ($f['resultado'] !== null) {
            return null;
        }

        $companyId = $conta['company']?->id;
        $mlbId = $conta['mlb_empresa']?->id;
        if ($companyId === null && $mlbId === null) {
            return null;
        }

        $q = DB::table('pub_aprovacao_eventos as ev')
            ->join('pub_aprovacao_ofertas as of', 'of.id', '=', 'ev.oferta_id')
            ->selectRaw($this->colunas(self::FONTE_APROVACAO))
            ->selectRaw('ev.id as chave')
            ->selectRaw('ev.tipo as tipo')
            ->selectRaw('case when ev.ator_sistema = 1 then \''.self::ORIGEM_AUTOMATICA.'\' else \''.self::ORIGEM_MANUAL.'\' end as origem')
            ->selectRaw('ev.ocorrido_em as quando')
            ->selectRaw('of.produto_id as produto_id')
            ->selectRaw('ev.autor_id as autor_id')
            ->selectRaw('of.andamento as resultado')
            ->selectRaw('ev.texto as motivo')
            ->selectRaw('of.rascunho_id as rascunho_id')
            ->selectRaw('ev.oferta_id as oferta_id')
            ->selectRaw('ev.versao as versao')
            ->selectRaw('null as extra');

        if ($companyId !== null) {
            $q->where('of.company_id', $companyId);
        }
        if ($mlbId !== null) {
            $q->where('of.mlb_empresa_id', $mlbId);
        }

        $this->aplicaComuns($q, $f, 'ev.ocorrido_em');

        if ($f['origem'] === self::ORIGEM_AUTOMATICA) {
            $q->where('ev.ator_sistema', true);
        }
        if ($f['origem'] === self::ORIGEM_MANUAL) {
            $q->where('ev.ator_sistema', false);
        }
        if ($f['produto_id'] !== null) {
            $q->where('of.produto_id', $f['produto_id']);
        }

        return $q;
    }

    /**
     * O recorte de data, igual nas três fontes.
     *
     * ⚠️ O dia é o dia de **São Paulo**, não o do servidor: a pessoa filtra "hoje" pensando no
     * fuso dela, e o `app.timezone` pode não ser o mesmo. A conversão vira um intervalo de
     * `datetime`, o que funciona nos três bancos sem função de data no SQL.
     */
    private function aplicaComuns($q, array $f, string $coluna): void
    {
        if ($f['de'] !== null) {
            $q->where(DB::raw($coluna), '>=', Carbon::parse($f['de'], 'America/Sao_Paulo')
                ->startOfDay()->setTimezone(config('app.timezone')));
        }
        if ($f['ate'] !== null) {
            $q->where(DB::raw($coluna), '<=', Carbon::parse($f['ate'], 'America/Sao_Paulo')
                ->endOfDay()->setTimezone(config('app.timezone')));
        }
    }

    /**
     * Transforma as linhas cruas da união em linhas de tela: rótulo pt-BR, autor, produto e links.
     *
     * ⚠️ Os nomes de produto e de autor saem em **DUAS consultas para a página inteira**, não uma
     * por linha: 25 linhas davam até 50 consultas, e a tela do agente já carrega configuração,
     * contadores e fila no mesmo request.
     */
    private function monta($cruas, array $conta): array
    {
        if ($cruas->isEmpty()) {
            return [];
        }

        $idsProduto = $cruas->pluck('produto_id')->filter()->unique()->values();
        $produtos = $idsProduto->isEmpty() ? collect() : PubProduto::query()
            ->whereIn('id', $idsProduto)->get(['id', 'nome', 'sku'])->keyBy('id');

        $idsAutor = $cruas->pluck('autor_id')->filter()->unique()->values();
        $autores = $idsAutor->isEmpty() ? collect() : User::query()
            ->whereIn('id', $idsAutor)->get(['id', 'name'])->keyBy('id');

        return $cruas->map(function ($r) use ($produtos, $autores, $conta) {
            $produto = $r->produto_id === null ? null : $produtos->get($r->produto_id);
            $quando = Carbon::parse($r->quando)->setTimezone('America/Sao_Paulo');

            return [
                'id' => "{$r->fonte}-{$r->chave}",
                'fonte' => $r->fonte,
                'fonte_rotulo' => self::ROTULO_DA_FONTE[$r->fonte] ?? $r->fonte,
                'origem' => $r->origem,
                'origem_rotulo' => self::ROTULO_DA_ORIGEM[$r->origem] ?? $r->origem,
                'quando' => $quando->toIso8601String(),
                'hora' => $quando->format('H:i'),
                'dia' => $quando->format('Y-m-d'),
                'titulo' => $this->titulo($r),
                'resultado' => $r->resultado,
                'resultado_rotulo' => $this->resultadoRotulo($r),
                'motivo' => $this->texto($r->motivo),
                'versao' => $r->versao === null ? null : (int) $r->versao,
                'autor' => $r->autor_id === null
                    ? PubAprovacaoEvento::AUTOR_SISTEMA
                    : ($autores->get($r->autor_id)?->name ?? 'Usuário removido'),
                'produto' => $produto === null ? null : [
                    'id' => (int) $produto->id,
                    'nome' => (string) $produto->nome,
                    'sku' => (string) $produto->sku,
                ],
                'links' => $this->links($r, $produto, $conta),
            ];
        })->values()->all();
    }

    /** O que aconteceu, em uma frase, com a unidade dita. */
    private function titulo($r): string
    {
        return match ($r->fonte) {
            self::FONTE_PREPARO => 'Preparo de produto',
            // ⚠️ "Configuração salva (versão N)" e NUNCA "agente pausado": esta fatia tem o
            // snapshot, não o diff, então ela não afirma o que mudou. Ver o docblock da classe.
            self::FONTE_CONFIGURACAO => 'Configuração salva (versão '.((int) $r->versao).')',
            self::FONTE_APROVACAO => PubAprovacaoEvento::TIPOS[$r->tipo]['rotulo'] ?? 'Evento da oferta',
            default => 'Evento',
        };
    }

    /** O desfecho da linha, em pt-BR. */
    private function resultadoRotulo($r): ?string
    {
        if ($r->fonte === self::FONTE_PREPARO) {
            return self::ROTULO_DO_ESTADO[$r->resultado] ?? $r->resultado;
        }

        if ($r->fonte === self::FONTE_CONFIGURACAO) {
            // ⚠️ O snapshot é lido em PHP. `json_decode` de valor torto devolve `null`, e aí a
            // linha simplesmente não tem desfecho — nunca lança, nunca inventa.
            $dados = is_string($r->extra) ? json_decode($r->extra, true) : null;
            if (! is_array($dados)) {
                return null;
            }
            $controle = is_string($dados['controle'] ?? null) ? $dados['controle'] : null;
            $ligado = ($dados['habilitado'] ?? null) === true || ($dados['habilitado'] ?? null) === 1;

            if ($controle !== null && $controle !== 'ativo') {
                return 'Agente '.(self::ROTULO_DO_CONTROLE[$controle] ?? $controle).' nesta versão';
            }

            return $ligado ? 'Agente ligado nesta versão' : 'Agente desligado nesta versão';
        }

        return null;
    }

    /**
     * Os destinos reais da linha — Produtos e Prévia.
     *
     * ⚠️ **Link só nasce com destino.** Linha sem produto não ganha link de Prévia: botão que não
     * leva a lugar nenhum é dívida, e a decisão D4 deste projeto é explícita ("botão sem destino
     * não nasce"; *"desabilitado com explicação"* foi oferecido e RECUSADO).
     */
    private function links($r, $produto, array $conta): array
    {
        $links = [];

        if ($produto !== null) {
            $links['produto'] = route('mlb.anuncios.publicador.abrir', ['produto' => $produto->id]);
            // A Prévia é a etapa 5 do editor — a mesma tela em que a revisão passa a acontecer.
            $links['previa'] = route('mlb.anuncios.publicador.previa', ['produto' => $produto->id]);
        }

        // ⚠️⚠️ **MEDIDO ANTES DE ESCREVER, e eu havia assumido errado duas coisas:** a rota
        // chama-se `mlb.anuncios.meus.aprovacao` (não `publicador.aprovacao`) e recebe
        // **`{company}` com route model binding em `Company`** (`MlbPublicadorAprovacaoController::
        // index(Request, Company, ...)`), **não** a `chave` da conta do Publicador.
        // ➡️ Logo conta **sem `Company`** (as ancoradas só em `MlbEmpresa`) **não ganha este link** —
        // montar a URL com a chave daria 404, e link que leva a 404 é pior que link ausente.
        if ($r->fonte === self::FONTE_APROVACAO && $r->oferta_id !== null && $conta['company'] !== null) {
            $links['oferta'] = route('mlb.anuncios.meus.aprovacao', ['company' => $conta['company']->id])
                .'?oferta='.((int) $r->oferta_id);
        }

        return $links;
    }

    /** Texto do banco que vai para a tela: nunca `String()`, nunca `null` virando `"null"`. */
    private function texto($v): ?string
    {
        return is_string($v) && trim($v) !== '' ? $v : null;
    }

    /**
     * Agrupa a página por dia, do mais novo para o mais antigo.
     *
     * ⚠️ Em PHP, sobre a página JÁ cortada — agrupar no banco exigiria `DATE()` com fuso, que não é
     * portável entre os três bancos deste projeto.
     */
    private function porDia(array $linhas): array
    {
        $dias = [];
        foreach ($linhas as $l) {
            $dias[$l['dia']][] = $l;
        }

        $hoje = now()->setTimezone('America/Sao_Paulo')->format('Y-m-d');
        $ontem = now()->setTimezone('America/Sao_Paulo')->subDay()->format('Y-m-d');

        return array_values(array_map(fn (string $dia) => [
            'dia' => $dia,
            // ⚠️ "hoje"/"ontem" por extenso, e a data completa para os outros dias — "3 d" foi
            // RECUSADO pelo usuário nesta milestone (a abreviação de dia não vai para a tela).
            'rotulo' => match ($dia) {
                $hoje => 'Hoje',
                $ontem => 'Ontem',
                default => Carbon::parse($dia)->format('d/m/Y'),
            },
            'linhas' => $dias[$dia],
        ], array_keys($dias)));
    }
}
