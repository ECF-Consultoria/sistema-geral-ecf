<?php

namespace App\Services\Publicador\Fila;

use App\Models\EstruturaOferta;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubValidacao;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PlanejamentoDaFaseService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\AlavancasLiberadas;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\Erros\MapeadorErrosMl;
use App\Support\Publicador\MemoriaDoPreparoIa;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Payload\MontadorDePlano;
use App\Support\Publicador\PrecoDaPromocao;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Validacao\Problema;
use App\Support\Publicador\Validacao\ValidadorRascunho;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * A VISÃO RÁPIDA da publicação em lote (10/10/2026, learnings publicador-ml §20): para cada rascunho ainda
 * não publicado da conta, as informações que decidem "pode ir assim?" — títulos Clássico e Premium (e se são
 * iguais), preço de cada tipo (mín–máx entre as cores, do Portal ou digitado), custo, frete, margem estimada,
 * estoque, variações, anúncios, fotos, a conferência e as pendências.
 *
 * NENHUMA chamada ao Mercado Livre e um número FIXO de consultas para qualquer N (o
 * `VisaoRapidaDoLoteTest` mede 3 × 12 produtos): os produtos e os rascunhos inteiros vêm com eager load,
 * o rascunho é remontado em memória (só o que a visão usa) e os efetivos saem de `EfetivosEmLote` — uma
 * `pagina()` da Precificação por empresa. A última conferência vem SEM `respostas_ml` (é a coluna pesada;
 * a lista do Publicador carrega todas, esta não).
 *
 * Margem estimada (por cor e tipo) = preço − custo − frete − (comissão% + imposto%) × preço, com a comissão e o
 * imposto da Precificação do Portal (a exceção do produto ou o padrão da empresa). Sem custo, não há margem;
 * sem frete, a margem sai marcada (`sem_frete`) — frete esquecido não pode sumir da conta calado.
 */
final class ResumoRapidoService
{
    /** Status do rascunho que a fila aceita publicar (o `iniciar()` decide de novo na hora). */
    public const PODE_PUBLICAR = [PubRascunho::DRAFT, PubRascunho::VALIDATED, PubRascunho::FAILED, PubRascunho::PARTIALLY_PUBLISHED];

    /** Quantas pendências da conferência vão para a linha (o editor mostra todas). */
    private const PENDENCIAS_NA_LINHA = 12;

    private const PORTAL_DO_TIPO = ['gold_special' => 'classico', 'gold_pro' => 'premium'];

    public function __construct(
        private ProgramasPublicadorService $programas,
        private EfetivosEmLote $efetivos,
    ) {}

    /** A marca de "conferindo" de um produto (o `ConferirEmLoteJob` apaga ao terminar). */
    public static function chaveConferindo(int $produtoId): string
    {
        return "publicador:lote:conferindo:{$produtoId}";
    }

    /**
     * @param  array{mlb_empresa: ?\App\Models\MlbEmpresa, company: ?\App\Models\Company, chave: string}  $alvo  saída de `ProgramasPublicadorService::resolver`
     * @param  list<int>|null  $soEstes  só estes produtos (null = todos os não publicados da conta)
     * @param  bool  $comPublicados  inclui os já publicados (o agendador relê UM produto, qualquer status)
     * @return list<array>
     */
    public function linhas(array $alvo, ?array $soEstes = null, bool $comPublicados = false): array
    {
        $daConta = $this->programas->produtosQuery($alvo['mlb_empresa'], $alvo['company'])
            ->with(['mlbEmpresa.mlToken', 'company.mlToken', 'oferta', 'estruturaProduto'])
            ->get()->keyBy('id');
        if ($daConta->isEmpty()) {
            return [];
        }

        // Primeiro só o status (barato): o rascunho INTEIRO, com as relações, só dos que a visão mostra — a conta
        // pode ter centenas de publicados que ninguém vai ver aqui.
        $statusDoRascunho = PubRascunho::query()->whereIn('produto_id', $daConta->keys()->all())->pluck('status', 'produto_id');
        $filtro = $soEstes === null ? null : array_flip(array_map('intval', $soEstes));
        $mostrados = $daConta->filter(function (PubProduto $p) use ($statusDoRascunho, $filtro, $comPublicados) {
            if ($filtro !== null && ! isset($filtro[(int) $p->id])) {
                return false;
            }

            return $comPublicados || ($statusDoRascunho[$p->id] ?? null) !== PubRascunho::PUBLISHED;
        });
        if ($mostrados->isEmpty()) {
            return [];
        }

        $rascunhos = PubRascunho::query()->whereIn('produto_id', $mostrados->keys()->all())
            ->with(['alvos', 'atributos', 'eixos', 'variantes.valoresDosEixos', 'variantes.atributos', 'variantes.precos'])
            ->orderBy('id')->get()->keyBy('produto_id');

        $snapshots = [];
        foreach ($mostrados as $p) {
            $r = $rascunhos->get($p->id);
            if ($r !== null) {
                $snapshots[(int) $p->id] = self::snapshot($r, $p);
            }
        }

        $ef = $this->efetivos->carregar($mostrados->values(), $daConta, $snapshots);
        $rascunhoIds = $rascunhos->pluck('id')->values()->all();

        // A última conferência de cada rascunho, SEM a coluna pesada.
        $validacoes = $rascunhoIds === [] ? collect() : PubValidacao::query()
            ->whereIn('id', PubValidacao::query()->selectRaw('MAX(id)')->whereIn('rascunho_id', $rascunhoIds)->groupBy('rascunho_id'))
            ->get(['id', 'rascunho_id', 'revisao', 'camada', 'resultado', 'plano_hash', 'issues', 'created_at'])
            ->keyBy('rascunho_id');

        $fotos = $rascunhoIds === [] ? collect() : PubImagem::query()->whereIn('rascunho_id', $rascunhoIds)
            ->selectRaw('rascunho_id, COUNT(*) as total')->groupBy('rascunho_id')->pluck('total', 'rascunho_id');

        $naFila = $this->itensVivos($mostrados->keys()->all());

        $marcas = Cache::many($mostrados->keys()->map(fn ($id) => self::chaveConferindo((int) $id))->all());

        $kitsAuto = [];
        foreach ($rascunhos as $r) {
            $kitId = (int) ($r->step_state['criativos_auto']['kit_id'] ?? 0);
            if ($kitId > 0) {
                $kitsAuto[$kitId] = (int) $r->id;
            }
        }
        $statusDosKits = $kitsAuto === [] ? collect() : MlAnuncioCriativoKit::query()->whereIn('id', array_keys($kitsAuto))->pluck('status', 'id');

        $saida = [];
        foreach ($mostrados as $p) {
            $r = $rascunhos->get($p->id);
            $kitId = $r !== null ? (int) ($r->step_state['criativos_auto']['kit_id'] ?? 0) : 0;
            $saida[] = $this->linha(
                $p, $r, $snapshots[(int) $p->id] ?? null, $ef, (int) $p->id,
                $r !== null ? $validacoes->get($r->id) : null,
                $r !== null ? (int) ($fotos[$r->id] ?? 0) : 0,
                $naFila[(int) $p->id] ?? null,
                ($marcas[self::chaveConferindo((int) $p->id)] ?? null) !== null,
                $kitId > 0 ? ($statusDosKits[$kitId] ?? null) : null,
            );
        }

        // Nome (natural, sem caixa) e, no empate, o id: a ordem não pula a cada atualização.
        usort($saida, fn ($a, $b) => [mb_strtolower($a['nome']), $a['produto_id']] <=> [mb_strtolower($b['nome']), $b['produto_id']]);

        return $saida;
    }

    /**
     * O "digital" do que vem DE FORA do rascunho — título efetivo de cada tipo ativo e preço efetivo de cada cor
     * ativa — de um produto. A fila guarda o do agendamento e compara na hora de publicar: se o Portal mudou o preço
     * ou o título planejado depois da conferência, o item vira `precisa_revisar` sem gastar uma publicação.
     */
    public function digitalDe(array $alvo, int $produtoId): ?string
    {
        return $this->linhaDe($alvo, $produtoId)['digital'] ?? null;
    }

    /** A linha da visão rápida de UM produto, qualquer status (o agendador relê na hora de publicar). */
    public function linhaDe(array $alvo, int $produtoId): ?array
    {
        return $this->linhas($alvo, [$produtoId], comPublicados: true)[0] ?? null;
    }

    // ═══ Uma linha ═══════════════════════════════════════════════════════════

    private function linha(PubProduto $p, ?PubRascunho $r, ?RascunhoSnapshot $digitado, array $ef, int $id, ?PubValidacao $v, int $fotos, ?array $naFila, bool $conferindo, ?string $statusKitAuto): array
    {
        $conta = $p->contaOuNula();
        $liberada = ContasLiberadas::libera($conta);
        $status = EditorRascunhoService::prontidao($r, $v);
        $composto = PlanejamentoDaFaseService::tipoComposto($p);

        $base = [
            'produto_id' => (int) $p->id,
            'rascunho_id' => $r?->id,
            'sku' => $p->skuExibido(),
            'nome' => $p->nomeExibido(),
            'rotulo_fase' => ProgramasPublicadorService::rotuloFase($p->quantidade_kit, $composto),
            'eh_kit' => $p->ehKit(),
            'situacao' => $status,
            'status_rascunho' => $r?->status,
            'url_editor' => route('mlb.anuncios.publicador.editor', ['produto' => $p->id]),
            'conta' => ['nome' => $conta?->nomeContaMl(), 'liberada' => $liberada, 'token' => $conta !== null],
            'faltam' => (int) ($r?->step_state['resumo']['bloqueios'] ?? 0),
            'fotos' => $fotos,
            'conferindo' => $conferindo,
            'fila' => $naFila,
            'criativos_ia_prontos' => in_array($statusKitAuto, [MlAnuncioCriativoKit::STATUS_PRONTO, MlAnuncioCriativoKit::STATUS_PARCIAL], true),
        ];

        // A promoção automática pós-publicação só é criada onde as Alavancas escrevem (a mesma régua do editor).
        $base['promocao_automatica'] = ['automatica' => AlavancasLiberadas::libera($conta), 'dias' => PrecoDaPromocao::DIAS];

        if ($r === null || $digitado === null) {
            return $base + [
                'titulos' => [], 'titulos_iguais' => false, 'preco_sem_frete' => false, 'precos' => [], 'promocao' => [],
                'custo' => null, 'frete' => [], 'margem' => [],
                'estoque' => ['total' => 0, 'sem_estoque' => 0], 'variacoes' => 0, 'anuncios' => 0,
                'bloqueios' => [], 'conferencia' => null, 'digital' => null,
                ...$this->prontidaoDoLote($r, $v, $liberada, $conta !== null, $naFila, []),
            ];
        }

        $e = $ef['efetivos'][$id] ?? EfetivosEmLote::vazio();
        // `comEfetivosDe`, o caminho de quem valida: grava em cada cor o `portal` (anunciado, mínimo, sem frete) e o
        // `preco_do_portal` — é o que o V-SAL-08 e a promoção automática leem.
        $efetivo = $digitado->comEfetivosDe($e);
        // Os bloqueios que se sabem ANTES de conferir (títulos iguais, preço do Portal sem frete): as MESMAS regras do
        // `ValidadorRascunho`, sem o schema da categoria — e quem os tem não entra na fila.
        $bloqueios = array_map(fn (Problema $x) => [
            'regra' => $x->regra, 'severidade' => $x->severidade, 'mensagem' => $x->mensagem, 'alvo' => $x->alvo,
        ], ValidadorRascunho::bloqueiosSemSchema($efetivo));
        $ativasDigitadas = $digitado->variantesAtivas();
        $ativasEfetivas = $efetivo->variantesAtivas();
        $alvosAtivos = $efetivo->alvosAtivos();

        $daIa = MemoriaDoPreparoIa::paraTela(
            (array) $r->step_state,
            collect($digitado->alvos)->mapWithKeys(fn (Alvo $a) => [$a->listingTypeId => $a->titulo])->all(),
            $digitado->atributos['MODEL'] ?? null,
            $r->descricao,
        )['titulos'] ?? [];

        $titulos = [];
        foreach ($digitado->alvos as $i => $a) {
            $ef1 = $efetivo->alvos[$i] ?? $a;
            $digitadoAqui = trim((string) $a->titulo) !== '';
            $titulos[$a->listingTypeId] = [
                'ativo' => $a->ativo,
                'texto' => $ef1->titulo,
                'origem' => $digitadoAqui ? (($daIa[$a->listingTypeId] ?? false) ? 'ia' : 'digitado') : ($ef1->titulo !== null ? 'portal' : null),
            ];
        }
        $regras = array_column($bloqueios, 'regra');

        [$precos, $custo, $frete, $margem] = $this->dinheiro($p, $id, $ef, $digitado, $ativasDigitadas, $ativasEfetivas, $alvosAtivos);
        $promocao = self::promocoes($ativasEfetivas, $alvosAtivos);

        $estoque = ['total' => 0, 'sem_estoque' => 0];
        foreach ($ativasDigitadas as $va) {
            $n = (int) ($va->dados['estoque'] ?? 0);
            $estoque['total'] += $n;
            if ($n <= 0) {
                $estoque['sem_estoque']++;
            }
        }
        $up = ($r->modelo_publicacao ?? ($r->step_state['conta']['modelo'] ?? MontadorDePlano::UP)) === MontadorDePlano::UP;

        return $base + [
            'titulos' => $titulos,
            'titulos_iguais' => in_array('V-TIT-04', $regras, true),
            'preco_sem_frete' => in_array('V-SAL-08', $regras, true),
            'precos' => $precos,
            'promocao' => $promocao,
            'custo' => $custo,
            'frete' => $frete,
            'margem' => $margem,
            'estoque' => $estoque,
            'variacoes' => count($ativasEfetivas),
            'rotulos_variacoes' => array_slice(array_map(fn (Variante $va) => $va->rotulo($digitado->eixos), $ativasDigitadas), 0, 12),
            'anuncios' => $up ? count($alvosAtivos) * count($ativasEfetivas) : count($alvosAtivos),
            'bloqueios' => $bloqueios,
            'conferencia' => $this->conferencia($r, $v),
            'digital' => self::digital($efetivo),
            ...$this->prontidaoDoLote($r, $v, $liberada, $conta !== null, $naFila, $bloqueios),
        ];
    }

    /**
     * A promoção automática pós-publicação de cada tipo, entre as cores ativas — a conta do `PrecoDaPromocao` (a mesma
     * do editor e do gatilho pós-publicação) sobre o preço que vai ao ML e o `portal` da cor. Sem promoção em
     * nenhuma cor, o motivo da primeira (o texto de `PrecoDaPromocao::MOTIVOS`); produto sem Portal não diz nada.
     *
     * @param  list<Variante>  $ativas  as cores ativas do snapshot EFETIVO (`comEfetivosDe`)
     * @param  list<Alvo>  $alvos
     * @return array<string, array{calculavel: bool, min: ?float, max: ?float, pct_min: ?float, pct_max: ?float, sem_promocao: int, motivo: ?string, ajustada_ao_minimo: bool}>
     */
    private static function promocoes(array $ativas, array $alvos): array
    {
        $saida = [];
        foreach ($alvos as $alvo) {
            $lt = $alvo->listingTypeId;
            $ok = [];
            $motivos = [];
            foreach ($ativas as $va) {
                $preco = $va->dados['precos'][$lt] ?? null;
                $calc = PrecoDaPromocao::calcular($preco === null ? null : (float) $preco, $va->dados['portal'][$lt] ?? null);
                if ($calc['calculavel']) {
                    $ok[] = $calc;
                } else {
                    $motivos[] = (string) $calc['motivo'];
                }
            }
            if ($ok === []) {
                $motivo = $motivos[0] ?? PrecoDaPromocao::SEM_PRECO;
                $saida[$lt] = ['calculavel' => false, 'min' => null, 'max' => null, 'pct_min' => null, 'pct_max' => null, 'sem_promocao' => count($motivos),
                    // Sem Portal (ou sem preço) não há o que dizer; os outros motivos a equipe precisa ver.
                    'motivo' => in_array($motivo, [PrecoDaPromocao::SEM_PORTAL, PrecoDaPromocao::SEM_PRECO], true) ? null : PrecoDaPromocao::MOTIVOS[$motivo] ?? null,
                    'ajustada_ao_minimo' => false];

                continue;
            }
            $saida[$lt] = [
                'calculavel' => true,
                'min' => min(array_column($ok, 'preco')), 'max' => max(array_column($ok, 'preco')),
                'pct_min' => min(array_column($ok, 'percentual')), 'pct_max' => max(array_column($ok, 'percentual')),
                'sem_promocao' => count($motivos),
                'motivo' => $motivos === [] ? null : PrecoDaPromocao::MOTIVOS[$motivos[0]] ?? null,
                'ajustada_ao_minimo' => in_array(true, array_column($ok, 'ajustada_ao_minimo'), true),
            ];
        }

        return $saida;
    }

    /**
     * Preço, custo, frete e margem por tipo, entre as cores ativas.
     *
     * @return array{0: array, 1: ?array, 2: array, 3: array}
     */
    private function dinheiro(PubProduto $p, int $id, array $ef, RascunhoSnapshot $digitado, array $ativasDigitadas, array $ativasEfetivas, array $alvosAtivos): array
    {
        $companyId = $ef['company_do_produto'][$id] ?? null;
        $precificacao = $companyId !== null ? ($ef['precificacao'][$companyId] ?? null) : null;
        $porOferta = (array) ($precificacao['por_oferta'] ?? []);
        $impostoPadrao = isset($precificacao['parametros']['imposto']) ? (float) $precificacao['parametros']['imposto'] : null;
        $skuDoProduto = $digitado->atributos['SELLER_SKU']['value_name'] ?? null;

        $precos = [];
        $frete = [];
        $margem = [];
        $custos = [];
        $origensCusto = [];

        $digitadasPorChave = collect($ativasDigitadas)->keyBy('chave');
        foreach ($alvosAtivos as $alvo) {
            $lt = $alvo->listingTypeId;
            $tipoPortal = self::PORTAL_DO_TIPO[$lt] ?? null;
            $valores = [];
            $origens = [];
            $semPreco = 0;
            $fretes = [];
            $origensFrete = [];
            $margens = [];
            $semFrete = false;

            foreach ($ativasEfetivas as $va) {
                $preco = $va->dados['precos'][$lt] ?? null;
                $digitadoAqui = ($digitadasPorChave->get($va->chave)?->dados['precos'][$lt] ?? null) !== null;
                if ($preco === null) {
                    $semPreco++;
                } else {
                    $valores[] = (float) $preco;
                    $origens[$digitadoAqui ? 'digitado' : 'portal'] = true;
                }

                $sku = EstruturaOferta::normalizarSku($va->dados['atributos']['SELLER_SKU']['value_name'] ?? $skuDoProduto);
                $ofertaId = ($sku !== null ? ($ef['ofertas_por_sku'][$id][$sku] ?? null) : null) ?? ($ef['oferta_ancora'][$id] ?? null);
                $linhaPortal = $ofertaId !== null ? ($porOferta[$ofertaId] ?? null) : null;
                if ($linhaPortal === null || $tipoPortal === null) {
                    continue;
                }
                $custo = isset($linhaPortal['custo']['valor']) && is_numeric($linhaPortal['custo']['valor']) ? (float) $linhaPortal['custo']['valor'] : null;
                if ($custo !== null) {
                    $custos[] = $custo;
                    $origensCusto[(string) ($linhaPortal['custo']['origem'] ?? '')] = true;
                }
                $doTipo = (array) ($linhaPortal[$tipoPortal] ?? []);
                $valorFrete = isset($doTipo['frete']) && is_numeric($doTipo['frete']) ? (float) $doTipo['frete'] : null;
                if ($valorFrete !== null) {
                    $fretes[] = $valorFrete;
                    $origensFrete[(string) ($doTipo['frete_origem'] ?? '')] = true;
                }
                $comissao = isset($doTipo['comissao']) && is_numeric($doTipo['comissao']) ? (float) $doTipo['comissao'] : null;
                $imposto = isset($linhaPortal['excecoes']['imposto']) && is_numeric($linhaPortal['excecoes']['imposto'])
                    ? (float) $linhaPortal['excecoes']['imposto'] : $impostoPadrao;
                if ($preco !== null && $custo !== null && (float) $preco > 0) {
                    if ($valorFrete === null) {
                        $semFrete = true;
                    }
                    $percentuais = ((float) ($comissao ?? 0) + (float) ($imposto ?? 0)) / 100;
                    $m = round((float) $preco - $custo - (float) ($valorFrete ?? 0) - $percentuais * (float) $preco, 2);
                    $margens[] = ['valor' => $m, 'pct' => round($m / (float) $preco * 100, 1), 'comissao' => $comissao, 'imposto' => $imposto];
                }
            }

            $precos[$lt] = $valores === [] ? ['min' => null, 'max' => null, 'origem' => null, 'sem_preco' => $semPreco] : [
                'min' => min($valores), 'max' => max($valores),
                'origem' => count($origens) > 1 ? 'misto' : array_key_first($origens),
                'sem_preco' => $semPreco,
            ];
            $frete[$lt] = $fretes === [] ? null : [
                'min' => min($fretes), 'max' => max($fretes),
                'origem' => count($origensFrete) > 1 ? 'misto' : (array_key_first($origensFrete) ?: null),
            ];
            $margem[$lt] = $margens === [] ? null : [
                'min' => min(array_column($margens, 'valor')), 'max' => max(array_column($margens, 'valor')),
                'pct_min' => min(array_column($margens, 'pct')), 'pct_max' => max(array_column($margens, 'pct')),
                'comissao' => $margens[0]['comissao'], 'imposto' => $margens[0]['imposto'],
                'sem_frete' => $semFrete,
            ];
        }

        $custo = $custos === [] ? null : [
            'min' => min($custos), 'max' => max($custos),
            'origem' => count($origensCusto) > 1 ? 'misto' : (array_key_first($origensCusto) ?: null),
        ];

        return [$precos, $custo, $frete, $margem];
    }

    /** A última conferência como a linha mostra: resultado, se vale para a versão atual e as pendências. */
    private function conferencia(PubRascunho $r, ?PubValidacao $v): ?array
    {
        if ($v === null) {
            return null;
        }
        // Conferência gravada antes do filtro de ruído (03/10) ainda traz o 4053 — como no `estado()`.
        $issues = array_values(array_filter((array) $v->issues, fn ($i) => ! MapeadorErrosMl::ehRuido((array) ($i['ml_causa'] ?? []))));
        // E a mensagem que ficou crua porque o código só entrou no dicionário depois (10/10) — também como no `estado()`.
        $issues = array_map(fn ($i) => MapeadorErrosMl::comTraducaoDeHoje((array) $i, (array) config('publicador_erros', [])), $issues);
        $bloqueios = count(array_filter($issues, fn ($i) => ($i['severidade'] ?? null) === Problema::BLOQUEIO));
        $avisos = count(array_filter($issues, fn ($i) => ($i['severidade'] ?? null) === Problema::AVISO));
        // Bloqueio primeiro: é o que impede publicar.
        usort($issues, fn ($a, $b) => (($a['severidade'] ?? '') === Problema::BLOQUEIO ? 0 : 1) <=> (($b['severidade'] ?? '') === Problema::BLOQUEIO ? 0 : 1));

        return [
            'id' => (int) $v->id,
            'resultado' => $v->resultado,
            'camada' => $v->camada,
            'vale' => (int) $v->revisao === (int) $r->revisao,
            'em' => $v->created_at?->toIso8601String(),
            'local' => $v->camada === 'L2',
            'bloqueios' => $bloqueios,
            'avisos' => $avisos,
            'pendencias' => array_map(fn ($i) => [
                'regra' => (string) ($i['regra'] ?? ''),
                'severidade' => (string) ($i['severidade'] ?? ''),
                'mensagem' => (string) ($i['mensagem'] ?? ''),
                'alvo' => is_array($i['alvo'] ?? null) ? $i['alvo'] : [],
            ], array_slice($issues, 0, self::PENDENCIAS_NA_LINHA)),
            'mais_pendencias' => max(0, count($issues) - self::PENDENCIAS_NA_LINHA),
        ];
    }

    /**
     * Pode entrar na fila? Só o que o `iniciar()` aceitaria AGORA: conta liberada, conferência com o ML (L3) OK ou
     * com avisos, da versão atual, com plano — e fora de outra fila.
     *
     * 10/10/2026: com bloqueio que se sabe ANTES de conferir (títulos iguais, preço do Portal sem frete — o
     * `ValidadorRascunho::bloqueiosSemSchema`), não agenda, mesmo com uma conferência OK de antes dessas regras.
     *
     * @param  list<array{regra: string, mensagem: string}>  $bloqueios
     * @return array{pronto: bool, motivo: ?string, pode_conferir: bool, avisos_ml: bool}
     */
    private function prontidaoDoLote(?PubRascunho $r, ?PubValidacao $v, bool $liberada, bool $temToken, ?array $naFila, array $bloqueios): array
    {
        $publicando = $r?->status === PubRascunho::PUBLISHING;
        $podeConferir = $naFila === null && ! $publicando && $r?->status !== PubRascunho::PUBLISHED;
        $nao = fn (string $motivo) => ['pronto' => false, 'motivo' => $motivo, 'pode_conferir' => $podeConferir, 'avisos_ml' => false];

        if ($naFila !== null) {
            return $nao('Já está na fila de publicação.');
        }
        if ($r === null) {
            return $nao('O anúncio ainda não foi aberto: confira para criá-lo.');
        }
        if ($publicando) {
            return $nao('Está sendo publicado agora.');
        }
        if (! in_array($r->status, self::PODE_PUBLICAR, true)) {
            return $nao('Já publicado.');
        }
        if (! $temToken) {
            return $nao('A conta do Mercado Livre precisa ser reconectada.');
        }
        if (! $liberada) {
            return $nao('A publicação ainda não foi liberada para esta conta.');
        }
        if ($bloqueios !== []) {
            return $nao((string) $bloqueios[0]['mensagem']);
        }
        if ($v === null) {
            return $nao('Confira no Mercado Livre antes de agendar.');
        }
        if ((int) $v->revisao !== (int) $r->revisao) {
            return $nao('O produto mudou depois da conferência: confira de novo.');
        }
        if ($v->camada !== 'L3') {
            return $nao('A conferência foi só local: confira no Mercado Livre.');
        }
        if ($v->resultado === ConferenciaService::ERRO) {
            return $nao('A conferência não terminou: confira de novo.');
        }
        if (! in_array($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], true) || ! $v->plano_hash) {
            return $nao('A conferência achou pendências: corrija e confira de novo.');
        }

        return ['pronto' => true, 'motivo' => null, 'pode_conferir' => $podeConferir, 'avisos_ml' => $v->resultado === ConferenciaService::AVISOS];
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /**
     * O rascunho em memória a partir do eager load — só o que a visão rápida usa (alvos, variantes com preço,
     * estoque, SKU e valores de eixo, atributos do produto). Nada é relido do banco aqui.
     */
    private static function snapshot(PubRascunho $r, PubProduto $p): RascunhoSnapshot
    {
        $chaveDoEixo = [];
        $eixos = [];
        foreach ($r->eixos as $e) {
            $chaveDoEixo[(int) $e->id] = $e->attribute_id ?? ChaveCanonica::EIXO_CUSTOM;
            if (! $e->removido) {
                $eixos[] = new Eixo($e->attribute_id ?? ChaveCanonica::EIXO_CUSTOM, (string) $e->nome, (int) $e->posicao, (bool) $e->defines_picture);
            }
        }
        $tipoDoAlvo = $r->alvos->pluck('listing_type_id', 'id')->all();

        $variantes = $r->variantes->map(function ($v) use ($chaveDoEixo, $tipoDoAlvo) {
            $valores = [];
            foreach ($v->valoresDosEixos as $ev) {
                $chave = $chaveDoEixo[(int) $ev->pivot->eixo_id] ?? null;
                if ($chave !== null) {
                    $valores[$chave] = new ValorEixo($ev->value_id, (string) $ev->value_name);
                }
            }
            $precos = [];
            foreach ($v->precos as $preco) {
                if (isset($tipoDoAlvo[$preco->alvo_id])) {
                    $precos[$tipoDoAlvo[$preco->alvo_id]] = $preco->preco === null ? null : (float) $preco->preco;
                }
            }

            return new Variante($v->combinacao_chave, $valores, (bool) $v->ativa, (bool) $v->orfa, array_filter([
                'estoque' => $v->estoque,
                'precos' => $precos,
                'atributos' => $v->atributos->mapWithKeys(fn ($a) => [$a->attribute_id => array_filter(['value_id' => $a->value_id, 'value_name' => $a->value_name], fn ($x) => $x !== null)])->all(),
            ], fn ($x) => $x !== null && $x !== []), (bool) $v->publicada);
        })->all();

        return new RascunhoSnapshot(
            categoriaId: (string) $r->categoria_id,
            condicao: (string) ($r->condicao ?? 'new'),
            atributos: $r->atributos->mapWithKeys(fn ($a) => [$a->attribute_id => array_filter([
                'value_id' => $a->value_id, 'value_name' => $a->value_name, 'value_number' => $a->value_number, 'value_unit' => $a->value_unit,
            ], fn ($x) => $x !== null)])->all(),
            eixos: $eixos,
            variantes: $variantes,
            alvos: $r->alvos->map(fn ($a) => new Alvo($a->listing_type_id, $a->titulo, (bool) $a->ativo))->all(),
            unidadesPorOferta: max(1, (int) ($p->quantidade_kit ?? 1)),
        );
    }

    /** Título efetivo de cada tipo ativo + preço efetivo de cada cor ativa, em hash. */
    public static function digital(RascunhoSnapshot $efetivo): string
    {
        $titulos = [];
        foreach ($efetivo->alvosAtivos() as $a) {
            $titulos[$a->listingTypeId] = trim((string) $a->titulo);
        }
        ksort($titulos);
        $precos = [];
        foreach ($efetivo->variantesAtivas() as $v) {
            $doTipo = [];
            foreach (array_keys($titulos) as $lt) {
                $preco = $v->dados['precos'][$lt] ?? null;
                $doTipo[$lt] = $preco === null ? null : round((float) $preco, 2);
            }
            $precos[$v->chave] = $doTipo;
        }
        ksort($precos);

        return sha1((string) json_encode(['titulos' => $titulos, 'precos' => $precos], JSON_UNESCAPED_UNICODE));
    }

    /**
     * O item VIVO de fila de cada produto (`produto_ativo`), numa consulta.
     *
     * @param  list<int>  $produtoIds
     * @return array<int, array{item_id: int, fila_id: int, status: string, posicao: int}>
     */
    private function itensVivos(array $produtoIds): array
    {
        if ($produtoIds === []) {
            return [];
        }
        try {
            return PubFilaPublicacaoItem::query()->whereIn('produto_ativo', $produtoIds)
                ->get(['id', 'fila_id', 'produto_ativo', 'status', 'posicao'])
                ->mapWithKeys(fn ($i) => [(int) $i->produto_ativo => [
                    'item_id' => (int) $i->id, 'fila_id' => (int) $i->fila_id, 'status' => (string) $i->status, 'posicao' => (int) $i->posicao,
                ]])->all();
        } catch (QueryException) {
            // Sem a tabela (deploy do código antes do `migrate`): ninguém está na fila.
            return [];
        }
    }

    /** As linhas que a fila aceitaria agora, por produto. @param list<array> $linhas @return Collection<int, array> */
    public static function prontas(array $linhas): Collection
    {
        return collect($linhas)->filter(fn ($l) => ($l['pronto'] ?? false) === true)->keyBy('produto_id');
    }
}
