<?php

namespace App\Services\Trilha;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubProduto;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Publicador\PreparoIaDoRascunhoService as Preparo;
use App\Support\Publicador\IaTrabalhando;
use App\Support\Publicador\MemoriaDoPreparoIa;
use App\Support\Publicador\SituacaoDoAnuncioMl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Onde cada oferta da trilha está DEPOIS do Mapeamento (etapas 3 a 6): Planejamento, preparo pelo agente,
 * revisão e anúncio no ar — tudo lido do banco de uma vez, sem gravar nada e sem chamar o Mercado Livre.
 *
 * ### De onde vem cada coisa
 * - Não existe relação do Portal para o Publicador: o caminho é de `pub_produtos` para o Portal
 *   (`estrutura_produto_id` = o grupo das cores; `oferta_id` = a oferta; `produto_base_id` + `quantidade_kit`
 *   = a fase N, que é o Combo N de cada cor).
 * - O rascunho é `pub_rascunhos.produto_id` (único). O "o que falta" é o resumo que o editor grava em
 *   `step_state.resumo` — só vale EM DIA (a régua privada `VitrineDeProdutosService::resumoEmDia`, reproduzida
 *   aqui). Recalcular com `EditorRascunhoService::estado()` custa 40–80 ms por rascunho e GRAVA: nunca aqui.
 * - Anúncios de DUAS fontes, unidos sem repetir MLB: os itens `CREATED` das publicações do Publicador (a fase N
 *   nunca é espelhada no Portal) e `estrutura_anuncios` (a régua do Portal: colado, importado, digitado, espelho).
 *
 * ### Número fixo de consultas
 * `IaTrabalhando::no()` e `CriativosAutomaticosService::situacaoDasImagens()` fazem uma consulta (e uma leitura
 * de cache) POR rascunho. A trilha reproduz as MESMAS regras em lote: os kits de imagem de todos os rascunhos numa
 * consulta e as marcas de "agendado" num `Cache::many`. Os prazos e a lista de estados de kit são cópias das
 * constantes privadas de lá (referência em cada uma) — mudou lá, muda aqui.
 *
 * Consultas, qualquer que seja o tamanho do catálogo: produtos do Publicador com as fases (1), rascunhos (1), itens
 * publicados (1), anúncios da régua (1) e kits de imagem (1) — rascunhos, itens e kits só quando há o que ler.
 */
class SituacaoDasOfertas
{
    /** `IaTrabalhando::no()`: etapa `rodando` há menos que isto = o agente está escrevendo agora. */
    private const ESCREVENDO_VALE_MIN = 10;

    /** `IaTrabalhando::no()`: kit automático criado há menos que isto e ainda andando = gerando as imagens. */
    private const IMAGENS_VALE_MIN = 30;

    /**
     * `PreparoIaDoRascunhoService::EM_ANDAMENTO_VALE_MIN` (privada): etapa `rodando`/`adiada` mais velha que isto é
     * Job que morreu sem avisar — o próprio serviço a trata como incompleta (`situacaoDoTexto`).
     */
    private const EM_ANDAMENTO_VALE_MIN = 180;

    /** `IaTrabalhando::KIT_EM_ANDAMENTO` (privada): o kit automático que ainda vai entrar no anúncio. */
    private const KIT_EM_ANDAMENTO = [
        MlAnuncioCriativoKit::STATUS_PLANEJANDO, MlAnuncioCriativoKit::STATUS_PLANEJADO, MlAnuncioCriativoKit::STATUS_GERANDO,
        MlAnuncioCriativoKit::STATUS_PRONTO, MlAnuncioCriativoKit::STATUS_PARCIAL,
    ];

    /** Etapa do texto terminada: escrita, pulada ou o rascunho já intocável (`situacaoDoTexto`: "completo"). */
    private const TEXTO_PRONTO = [Preparo::OK, Preparo::PULADO, Preparo::INTOCAVEL];

    private const ROTULO_DA_ETAPA = [Preparo::TITULO => 'título', Preparo::MODELO => 'modelo', Preparo::DESCRICAO => 'descrição'];

    private const TIPO_DO_PUBLICADOR = ['gold_special' => 'Clássico', 'gold_pro' => 'Premium'];

    private const TIPO_DA_REGUA = [EstruturaAnuncio::TIPO_CLASSICO => 'Clássico', EstruturaAnuncio::TIPO_PREMIUM => 'Premium'];

    /**
     * O status do ML ({@see SituacaoDoAnuncioMl::situacao()}) em rótulo CURTO. Os textos de lá dizem "pelo Mercado
     * Livre" e a porta do cliente não pode ler o nome da plataforma; o status é o mesmo.
     */
    private const SITUACAO_CURTA = [
        'paused'           => 'Pausado',
        'under_review'     => 'Em revisão',
        'inactive'         => 'Inativo',
        'closed'           => 'Encerrado',
        'payment_required' => 'Aguardando pagamento',
        'not_yet_active'   => 'Ainda não ativado',
    ];

    /** Anúncio que existe mas não está mais à venda: aparece na lista, mas não conta como "no ar". */
    private const FORA_DO_AR = ['Encerrado', 'Inativo'];

    /**
     * @param  array<int, array{id: int, oferta_id: ?int, estrutura_produto_id: ?int, produto_base_id: ?int, quantidade_kit: int}>  $pubs
     * @param  array<int, int>  $grupoDoProduto  estrutura_produto_id → pub do grupo
     * @param  array<int, int>  $pubDaOferta  oferta_id → pub
     * @param  array<int, array<int, list<int>>>  $kitsDaBase  pub base → N → kits
     * @param  array<int, PubRascunho>  $rascunhos  pub → rascunho
     * @param  array<int, list<array{tipo: string, mlb: string, situacao: ?string}>>  $publicados  rascunho → anúncios
     * @param  array<int, list<array{tipo: string, mlb: string, situacao: ?string}>>  $daRegua  oferta → anúncios
     * @param  array<int, array{status: ?string, criado_em: ?Carbon}>  $kitsDeImagem
     * @param  array<int, true>  $agendados  estrutura_produto_id com o preparo agendado
     */
    private function __construct(
        private array $pubs,
        private array $grupoDoProduto,
        private array $pubDaOferta,
        private array $kitsDaBase,
        private array $rascunhos,
        private array $publicados,
        private array $daRegua,
        private array $kitsDeImagem,
        private array $agendados,
        private Carbon $agora,
    ) {}

    /**
     * Lê tudo o que o Publicador e a régua de anúncios sabem das ofertas da empresa.
     *
     * @param  list<int>  $produtoIds  os produtos do Portal da empresa (para as marcas de "agendado", que são de cache)
     */
    public static function daEmpresa(Company $empresa, array $produtoIds): self
    {
        $empresaId = (int) $empresa->id;
        $doPortal = fn ($q) => $q
            ->whereIn('estrutura_produto_id', EstruturaProduto::query()->select('id')->where('company_id', $empresaId))
            ->orWhereIn('oferta_id', EstruturaOferta::query()->select('id')->where('company_id', $empresaId));

        // Numa consulta: o que aponta para um produto ou oferta da empresa e os kits (fases) desses.
        // ⚠️ `pub_produtos.fase` (número) não é lida: só os vínculos.
        $linhas = PubProduto::query()
            ->where($doPortal)
            ->orWhereIn('produto_base_id', PubProduto::query()->select('id')->where($doPortal))
            ->orderBy('id')
            ->get(['id', 'oferta_id', 'estrutura_produto_id', 'produto_base_id', 'quantidade_kit']);

        $pubs = [];
        $grupoDoProduto = [];
        $pubDaOferta = [];
        $kitsDaBase = [];
        foreach ($linhas as $l) {
            $id = (int) $l->id;
            $pubs[$id] = [
                'id'                   => $id,
                'oferta_id'            => $l->oferta_id !== null ? (int) $l->oferta_id : null,
                'estrutura_produto_id' => $l->estrutura_produto_id !== null ? (int) $l->estrutura_produto_id : null,
                'produto_base_id'      => $l->produto_base_id !== null ? (int) $l->produto_base_id : null,
                'quantidade_kit'       => (int) $l->quantidade_kit,
            ];
            if ($pubs[$id]['estrutura_produto_id'] !== null) {
                $grupoDoProduto[$pubs[$id]['estrutura_produto_id']] ??= $id;
            }
            if ($pubs[$id]['oferta_id'] !== null) {
                $pubDaOferta[$pubs[$id]['oferta_id']] ??= $id;
            }
            if ($pubs[$id]['produto_base_id'] !== null) {
                $kitsDaBase[$pubs[$id]['produto_base_id']][$pubs[$id]['quantidade_kit']][] = $id;
            }
        }

        $rascunhos = [];
        if ($pubs !== []) {
            $rascunhos = PubRascunho::query()
                ->whereIn('produto_id', array_keys($pubs))
                ->get(['id', 'produto_id', 'status', 'revisao', 'step_state'])
                ->keyBy(fn (PubRascunho $r) => (int) $r->produto_id)
                ->all();
        }

        return new self(
            $pubs,
            $grupoDoProduto,
            $pubDaOferta,
            $kitsDaBase,
            $rascunhos,
            self::publicados(array_map(fn (PubRascunho $r) => (int) $r->id, array_values($rascunhos))),
            self::daRegua($empresaId),
            self::kitsDeImagem($rascunhos),
            self::agendados($produtoIds),
            now(),
        );
    }

    // ═══ Qual pub_produto representa cada item ═══════════════════════════════

    /**
     * A Simples do produto: o grupo das cores (`estrutura_produto_id`) e, ao lado dele, o que ficou de antes do
     * agrupamento ou a cor que virou produto à parte (`oferta_id` de uma simples do produto, sem grupo nem base).
     * O que representa o item é o grupo; sem grupo, o legado que já tem rascunho (o mais antigo).
     *
     * @param  list<int>  $simplesIds  as ofertas simples das cores do produto
     * @return array{principal: ?int, todos: list<int>}
     */
    public function pubsDaSimples(int $produtoId, array $simplesIds): array
    {
        $grupo = $this->grupoDoProduto[$produtoId] ?? null;
        $legados = [];
        foreach ($simplesIds as $ofertaId) {
            $id = $this->pubDaOferta[(int) $ofertaId] ?? null;
            if ($id !== null && $id !== $grupo && $this->pubs[$id]['estrutura_produto_id'] === null && $this->pubs[$id]['produto_base_id'] === null) {
                $legados[] = $id;
            }
        }
        sort($legados);

        return $this->escolher($grupo !== null ? [$grupo] : [], $legados);
    }

    /**
     * O Combo N do produto: a fase N (o kit com `quantidade_kit` = N de um dos pub_produtos da Simples — o do grupo
     * primeiro) e, ao lado, o combo avulso (`oferta_id` de uma das ofertas combo do item).
     *
     * @param  array{principal: ?int, todos: list<int>}  $daSimples  saída de {@see pubsDaSimples()}
     * @param  list<int>  $comboIds
     * @return array{principal: ?int, todos: list<int>}
     */
    public function pubsDoCombo(array $daSimples, int $n, array $comboIds): array
    {
        $bases = $daSimples['todos'];
        if ($daSimples['principal'] !== null) {
            $bases = array_values(array_unique([$daSimples['principal'], ...$bases]));
        }
        $fases = [];
        foreach ($bases as $base) {
            foreach ($this->kitsDaBase[$base][$n] ?? [] as $kit) {
                $fases[] = $kit;
            }
        }

        $avulsos = [];
        foreach ($comboIds as $ofertaId) {
            $id = $this->pubDaOferta[(int) $ofertaId] ?? null;
            if ($id !== null && ! in_array($id, $fases, true)) {
                $avulsos[] = $id;
            }
        }
        sort($avulsos);

        return $this->escolher(array_values(array_unique($fases)), $avulsos);
    }

    /**
     * Kit, Combit, combo fora da regra e oferta sem produto: o pub_produto da própria oferta (`oferta_id` é único).
     *
     * @return array{principal: ?int, todos: list<int>}
     */
    public function pubsDaOferta(int $ofertaId): array
    {
        $id = $this->pubDaOferta[$ofertaId] ?? null;

        return ['principal' => $id, 'todos' => $id !== null ? [$id] : []];
    }

    /**
     * @param  list<int>  $preferidos  quem representa o item quando existe
     * @param  list<int>  $outros  os demais, em ordem de id
     * @return array{principal: ?int, todos: list<int>}
     */
    private function escolher(array $preferidos, array $outros): array
    {
        $principal = $preferidos[0] ?? null;
        if ($principal === null) {
            foreach ($outros as $id) {
                if (isset($this->rascunhos[$id])) {
                    $principal = $id;
                    break;
                }
            }
            $principal ??= $outros[0] ?? null;
        }

        return ['principal' => $principal, 'todos' => array_values(array_unique([...$preferidos, ...$outros]))];
    }

    // ═══ A etapa, o status e o resto do item ═════════════════════════════════

    /**
     * A etapa do item pela tabela do contrato (a primeira linha que casa), os anúncios e o preparo.
     *
     * @param  array{principal: ?int, todos: list<int>}  $pubs
     * @param  list<int>  $ofertaIds  as linhas de `estrutura_ofertas` do item (os anúncios da régua)
     * @param  bool  $esperaSimples  Combo cuja Simples ainda não está no ar
     * @return array{etapa: string, status: string, alerta: bool, anuncios: list<array{tipo: string, mlb: string, situacao: ?string}>, preparo: ?array, pub_produto_id: ?int, andou: bool}
     */
    public function doItem(array $pubs, array $ofertaIds, bool $esperaSimples): array
    {
        $principal = $pubs['principal'];
        $r = $principal !== null ? ($this->rascunhos[$principal] ?? null) : null;
        $anuncios = $this->anuncios($pubs['todos'], $ofertaIds);
        $estruturaProdutoId = $principal !== null ? $this->pubs[$principal]['estrutura_produto_id'] : null;
        // O kit da fase N (o Combo N no Publicador) não recebe o texto do agente: "o texto dele tem fluxo próprio"
        // (`PreparoIaDoRascunhoService::avaliarIa`, 'kit_da_fase'). Para ele o texto do agente não se aplica.
        $textoDoAgente = $principal === null || $this->pubs[$principal]['produto_base_id'] === null;

        $decisao = $this->decidir($r, $estruturaProdutoId, $anuncios, $esperaSimples, $textoDoAgente);
        $etapas = $r !== null ? self::etapasDoPreparo($r) : [];

        return [
            ...$decisao,
            'anuncios'       => $anuncios,
            'preparo'        => $r !== null ? $this->preparo($r, $etapas, $decisao['etapa'], $textoDoAgente) : null,
            'pub_produto_id' => $principal,
            // "Andou" (para a Simples de produto que ainda não saiu da lista/do mapeamento): tem anúncio, o rascunho
            // passou do DRAFT ou o agente já mexeu nele.
            'andou'          => $anuncios !== [] || ($r !== null && ($r->status !== PubRascunho::DRAFT || $etapas !== [])),
        ];
    }

    /**
     * @param  list<array{tipo: string, mlb: string, situacao: ?string}>  $anuncios
     * @param  bool  $textoDoAgente  false no kit da fase N: sem texto a esperar do agente, conta como pronto
     * @return array{etapa: string, status: string, alerta: bool}
     */
    private function decidir(?PubRascunho $r, ?int $estruturaProdutoId, array $anuncios, bool $esperaSimples, bool $textoDoAgente = true): array
    {
        if ($anuncios !== []) {
            return self::noAr($anuncios);
        }
        if ($r === null) {
            return ['etapa' => 'plano', 'status' => $esperaSimples ? 'Espera a oferta simples ir ao ar' : 'Sem onda', 'alerta' => false];
        }

        // Fora da tabela do contrato, só para não cair em "Preparando": o rascunho publicado cujo item criado não
        // foi achado (não acontece no fluxo normal — o MLB é gravado no instante do 201).
        if (in_array($r->status, [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED], true)) {
            return ['etapa' => 'lanc', 'status' => 'Publicado', 'alerta' => false];
        }
        if ($r->status === PubRascunho::PUBLISHING) {
            return ['etapa' => 'revisao', 'status' => 'Publicando agora', 'alerta' => false];
        }
        if ($r->status === PubRascunho::FAILED) {
            return ['etapa' => 'revisao', 'status' => 'A publicação falhou', 'alerta' => true];
        }

        $robo = match ($this->iaAgora($r, $estruturaProdutoId)) {
            IaTrabalhando::AGENDADA   => 'Na fila do agente',
            IaTrabalhando::ESCREVENDO => 'O agente está escrevendo título e descrição',
            IaTrabalhando::IMAGENS    => 'O agente está gerando as imagens',
            default                   => null,
        };
        if ($robo !== null) {
            return ['etapa' => 'robo', 'status' => $robo, 'alerta' => false];
        }

        $etapas = self::etapasDoPreparo($r);
        $paradas = [];
        foreach (Preparo::ETAPAS as $e) {
            if (isset($etapas[$e]) && $this->parou($etapas[$e])) {
                $paradas[] = self::ROTULO_DA_ETAPA[$e];
            }
        }
        if ($paradas !== []) {
            return ['etapa' => 'robo', 'status' => 'O agente parou: '.implode(', ', $paradas), 'alerta' => true];
        }

        $resumo = (array) ($r->step_state['resumo'] ?? []);
        $emDia = self::resumoEmDia($r);
        $bloqueios = (int) ($resumo['bloqueios'] ?? 0);
        // Sem isto o Combo N já no Publicador ficaria para sempre "Preparando" no robô, esperando um texto que o
        // agente nunca escreve — e o que falta nele é trabalho do Publicador.
        $textoPronto = true;
        foreach ($textoDoAgente ? Preparo::ETAPAS : [] as $e) {
            $textoPronto = $textoPronto && in_array($etapas[$e]['status'] ?? null, self::TEXTO_PRONTO, true);
        }

        // "0 bloqueios" só quer dizer pronto com a categoria: sem ela o editor não valida nada e grava
        // `validado = false` com zero (a vitrine, pela mesma razão, diz "Falta categoria" e não "Pronto").
        if ($emDia && $bloqueios === 0 && ($resumo['validado'] ?? null) === true) {
            return ['etapa' => 'revisao', 'status' => 'Pré-pronto · esperando a revisão', 'alerta' => false];
        }
        if ($textoPronto && $emDia && $bloqueios > 0) {
            return ['etapa' => 'revisao', 'status' => ($bloqueios === 1 ? 'Falta 1 item' : "Faltam {$bloqueios} itens").' para publicar', 'alerta' => true];
        }
        if ($textoPronto && ! $emDia) {
            return ['etapa' => 'revisao', 'status' => 'Pré-pronto · a conferir', 'alerta' => false];
        }

        $faltam = $emDia && $bloqueios > 0 ? ($bloqueios === 1 ? ' · falta 1 item' : " · faltam {$bloqueios} itens") : '';

        return ['etapa' => 'robo', 'status' => 'Preparando'.$faltam, 'alerta' => false];
    }

    /**
     * No ar: Clássico e Premium, ou qual tipo falta. Pausado e em revisão CONTAM (a régua do Portal: "Pausado
     * CONTA"); encerrado e inativo aparecem na lista, mas não contam.
     *
     * @param  list<array{tipo: string, mlb: string, situacao: ?string}>  $anuncios
     * @return array{etapa: string, status: string, alerta: bool}
     */
    private static function noAr(array $anuncios): array
    {
        $tipos = [];
        foreach ($anuncios as $a) {
            if (! in_array($a['situacao'], self::FORA_DO_AR, true)) {
                $tipos[$a['tipo']] = true;
            }
        }

        $status = match (true) {
            isset($tipos['Clássico'], $tipos['Premium']) => 'No ar · Clássico e Premium',
            isset($tipos['Clássico'])                    => 'No ar · falta o Premium',
            isset($tipos['Premium'])                     => 'No ar · falta o Clássico',
            // Fora da tabela do contrato: todos os anúncios encerrados. Continua na etapa 6 (é da Jardinagem).
            default                                      => 'Anúncios encerrados',
        };

        return ['etapa' => 'lanc', 'status' => $status, 'alerta' => $tipos === []];
    }

    // ═══ O agente ════════════════════════════════════════════════════════════

    /**
     * {@see IaTrabalhando::no()} em lote: agendado (cache lido de uma vez), escrevendo (`rodando` há menos de 10 min)
     * ou gerando as imagens (kit automático ainda andando, criado há menos de 30 min).
     */
    private function iaAgora(PubRascunho $r, ?int $estruturaProdutoId): ?string
    {
        if ($estruturaProdutoId !== null && isset($this->agendados[$estruturaProdutoId])) {
            return IaTrabalhando::AGENDADA;
        }
        foreach (self::etapasDoPreparo($r) as $etapa) {
            if (($etapa['status'] ?? null) === Preparo::RODANDO && $this->recente($etapa['em'] ?? null, self::ESCREVENDO_VALE_MIN)) {
                return IaTrabalhando::ESCREVENDO;
            }
        }
        foreach (self::gruposDeImagem($r) as $g) {
            if (empty($g['kit_id']) || isset($g['aplicado_em'])) {
                continue;
            }
            $kit = $this->kitsDeImagem[(int) $g['kit_id']] ?? null;
            if ($kit !== null && in_array($kit['status'], self::KIT_EM_ANDAMENTO, true)
                && $kit['criado_em'] !== null && $kit['criado_em']->gte($this->agora->copy()->subMinutes(self::IMAGENS_VALE_MIN))) {
                return IaTrabalhando::IMAGENS;
            }
        }

        return null;
    }

    /** A etapa terminou mal: erro, desistência ou `rodando`/`adiada` vencida (o Job morreu sem avisar). */
    private function parou(array $etapa): bool
    {
        $status = $etapa['status'] ?? null;

        return in_array($status, [Preparo::ERRO, Preparo::DESISTIU], true)
            || (in_array($status, [Preparo::RODANDO, Preparo::ADIADO], true) && ! $this->recente($etapa['em'] ?? null, self::EM_ANDAMENTO_VALE_MIN));
    }

    /**
     * O preparo, item por item (só a equipe vê). Etapa do texto sem registro: "na fila" enquanto o item está com o
     * robô, senão nada a dizer — e nunca "na fila" no kit da fase N, em que o texto do agente não se aplica.
     *
     * @param  array<string, array>  $etapas
     * @return array{titulo: ?string, modelo: ?string, descricao: ?string, imagens: ?string}
     */
    private function preparo(PubRascunho $r, array $etapas, string $etapaDoItem, bool $textoDoAgente): array
    {
        $saida = [];
        foreach (Preparo::ETAPAS as $e) {
            $saida[$e] = isset($etapas[$e]) ? $this->pilula($etapas[$e]) : null;
            $saida[$e] ??= $etapaDoItem === 'robo' && $textoDoAgente ? 'fila' : null;
        }
        $saida['imagens'] = $this->imagens($r);

        return $saida;
    }

    private function pilula(array $etapa): ?string
    {
        $status = $etapa['status'] ?? null;
        $recente = $this->recente($etapa['em'] ?? null, self::EM_ANDAMENTO_VALE_MIN);

        return match ($status) {
            Preparo::OK, Preparo::INTOCAVEL => 'pronto',
            Preparo::PULADO                 => 'pulado',
            Preparo::ERRO, Preparo::DESISTIU => 'erro',
            Preparo::RODANDO                => $recente ? 'fazendo' : 'erro',
            Preparo::ADIADO                 => $recente ? 'fila' : 'erro',
            default                         => null,
        };
    }

    /**
     * As imagens automáticas, da memória `step_state.criativos_auto` e dos kits já lidos — a régua de
     * `CriativosAutomaticosService::situacaoDasImagens()` sem a consulta por rascunho: teto do dia ou nova tentativa
     * = na fila; falhou de vez = erro; kit andando = fazendo; aplicado = pronto (com zero imagens aprovadas: pulado,
     * as fotos do cliente ficaram). Sem memória: null (não se sabe se a geração automática vale para a conta).
     */
    private function imagens(PubRascunho $r): ?string
    {
        $memoria = (array) ($r->step_state[CriativosAutomaticosService::MEMORIA] ?? []);
        if ($memoria === []) {
            return null;
        }

        $estados = [];
        if (isset($memoria[CriativosAutomaticosService::ADIADO])) {
            $estados['fila'] = true;
        }
        foreach (self::gruposDeImagem($r) as $g) {
            if (empty($g['kit_id'])) {
                continue;
            }
            if (isset($g['aplicado_em'])) {
                $estados[(int) ($g['aplicadas'] ?? 1) > 0 ? 'pronto' : 'pulado'] = true;
                continue;
            }
            $status = $this->kitsDeImagem[(int) $g['kit_id']]['status'] ?? null;
            if ($status === null) {
                continue;
            }
            if ($status === MlAnuncioCriativoKit::STATUS_ERRO) {
                $estados[CriativosAutomaticosService::podeTentarDeNovo($g, $status) ? 'fila' : 'erro'] = true;
            } else {
                $estados['fazendo'] = true;
            }
        }

        foreach (['erro', 'fazendo', 'fila', 'pronto', 'pulado'] as $e) {
            if (isset($estados[$e])) {
                return $e;
            }
        }

        return null;
    }

    // ═══ Anúncios ════════════════════════════════════════════════════════════

    /**
     * Os anúncios do item: os publicados pelo Publicador (de todos os pub_produtos do item) e os da régua do Portal
     * (das ofertas do item), sem repetir MLB — o do Publicador vence (a situação dele foi lida no Mercado Livre).
     * Clássico antes de Premium.
     *
     * @param  list<int>  $pubIds
     * @param  list<int>  $ofertaIds
     * @return list<array{tipo: string, mlb: string, situacao: ?string}>
     */
    private function anuncios(array $pubIds, array $ofertaIds): array
    {
        $porMlb = [];
        foreach ($pubIds as $pubId) {
            $r = $this->rascunhos[$pubId] ?? null;
            foreach ($r !== null ? ($this->publicados[(int) $r->id] ?? []) : [] as $a) {
                $porMlb[$a['mlb']] ??= $a;
            }
        }
        foreach ($ofertaIds as $ofertaId) {
            foreach ($this->daRegua[(int) $ofertaId] ?? [] as $a) {
                $porMlb[$a['mlb']] ??= $a;
            }
        }

        $lista = array_values($porMlb);
        usort($lista, fn ($x, $y) => [$x['tipo'] === 'Premium', $x['mlb']] <=> [$y['tipo'] === 'Premium', $y['mlb']]);

        return $lista;
    }

    /**
     * Os itens `CREATED` com MLB das publicações dos rascunhos, por rascunho. Só as colunas pequenas: o payload e
     * a resposta crua do ML ficam no banco.
     *
     * @param  list<int>  $rascunhoIds
     * @return array<int, list<array{tipo: string, mlb: string, situacao: ?string}>>
     */
    private static function publicados(array $rascunhoIds): array
    {
        if ($rascunhoIds === []) {
            return [];
        }

        $linhas = DB::table('pub_publicacao_itens as i')
            ->join('pub_publicacoes as p', 'p.id', '=', 'i.publicacao_id')
            ->whereIn('p.rascunho_id', $rascunhoIds)
            ->where('i.status', PubPublicacaoItem::CREATED)
            ->whereNotNull('i.ml_item_id')
            ->orderBy('i.id')
            ->get(['p.rascunho_id', 'i.listing_type_id', 'i.ml_item_id', 'i.avisos']);

        $saida = [];
        foreach ($linhas as $l) {
            $tipo = self::TIPO_DO_PUBLICADOR[(string) $l->listing_type_id] ?? null;
            $mlb = strtoupper(trim((string) $l->ml_item_id));
            if ($tipo === null || $mlb === '') {
                continue;
            }
            $avisos = is_string($l->avisos) ? json_decode($l->avisos, true) : $l->avisos;
            $estado = is_array($avisos) && is_array($avisos['estado'] ?? null) ? $avisos['estado'] : null;
            $situacao = SituacaoDoAnuncioMl::situacao($estado);

            // O mais novo vence: a mesma publicação relida tem o estado mais recente.
            $saida[(int) $l->rascunho_id][$mlb] = [
                'tipo'     => $tipo,
                'mlb'      => $mlb,
                'situacao' => $situacao === null ? null : (self::SITUACAO_CURTA[$situacao['status']] ?? 'Fora do ar'),
            ];
        }

        return array_map('array_values', $saida);
    }

    /**
     * Os anúncios da régua do Portal que contam ({@see EstruturaAnuncio::conta()}: não inativo e com MLB), por oferta.
     *
     * @return array<int, list<array{tipo: string, mlb: string, situacao: ?string}>>
     */
    private static function daRegua(int $empresaId): array
    {
        $linhas = EstruturaAnuncio::query()
            ->whereIn('oferta_id', EstruturaOferta::query()->select('id')->where('company_id', $empresaId))
            ->orderBy('id')
            ->get(['oferta_id', 'tipo', 'status', 'codigo_mlb']);

        $saida = [];
        foreach ($linhas as $a) {
            $tipo = self::TIPO_DA_REGUA[(string) $a->tipo] ?? null;
            if ($tipo === null || ! EstruturaAnuncio::conta((string) $a->status, $a->codigo_mlb)) {
                continue;
            }
            $saida[(int) $a->oferta_id][] = [
                'tipo'     => $tipo,
                'mlb'      => strtoupper(trim((string) $a->codigo_mlb)),
                'situacao' => $a->status === EstruturaAnuncio::STATUS_PAUSADO ? 'Pausado' : null,
            ];
        }

        return $saida;
    }

    // ═══ Leituras em lote do agente ══════════════════════════════════════════

    /**
     * Status e criação dos kits de imagem que a automação pediu (os de `criativos_auto.grupos`), numa consulta.
     *
     * @param  array<int, PubRascunho>  $rascunhos
     * @return array<int, array{status: ?string, criado_em: ?Carbon}>
     */
    private static function kitsDeImagem(array $rascunhos): array
    {
        $ids = [];
        foreach ($rascunhos as $r) {
            foreach (self::gruposDeImagem($r) as $g) {
                if (! empty($g['kit_id'])) {
                    $ids[(int) $g['kit_id']] = true;
                }
            }
        }
        if ($ids === []) {
            return [];
        }

        $saida = [];
        foreach (MlAnuncioCriativoKit::query()->whereIn('id', array_keys($ids))->get(['id', 'status', 'created_at']) as $k) {
            $saida[(int) $k->id] = ['status' => $k->status, 'criado_em' => $k->created_at];
        }

        return $saida;
    }

    /**
     * Quais produtos o cliente acabou de salvar e esperam o preparo (`PreparoIaAgenda::chaveDoAgendado`): uma leitura
     * de cache para todos.
     *
     * @param  list<int>  $produtoIds
     * @return array<int, true>
     */
    private static function agendados(array $produtoIds): array
    {
        if ($produtoIds === []) {
            return [];
        }

        $chaves = [];
        foreach ($produtoIds as $id) {
            $chaves[PreparoIaAgenda::chaveDoAgendado((int) $id)] = (int) $id;
        }

        $saida = [];
        foreach (Cache::many(array_keys($chaves)) as $chave => $valor) {
            if ($valor !== null && isset($chaves[$chave])) {
                $saida[$chaves[$chave]] = true;
            }
        }

        return $saida;
    }

    // ═══ step_state ══════════════════════════════════════════════════════════

    /** @return array<string, array> etapa → {status, em} de `step_state.ia_preparo.etapas` */
    private static function etapasDoPreparo(PubRascunho $r): array
    {
        $etapas = [];
        foreach ((array) ($r->step_state[MemoriaDoPreparoIa::PREPARO]['etapas'] ?? []) as $nome => $etapa) {
            if (is_array($etapa)) {
                $etapas[(string) $nome] = $etapa;
            }
        }

        return $etapas;
    }

    /** @return list<array> os grupos de `step_state.criativos_auto.grupos` */
    private static function gruposDeImagem(PubRascunho $r): array
    {
        return array_values(array_filter(
            (array) ($r->step_state[CriativosAutomaticosService::MEMORIA]['grupos'] ?? []),
            'is_array',
        ));
    }

    /**
     * O resumo do que falta foi feito NESTA revisão, no formato de hoje (com `validado`)? A mesma régua da
     * `VitrineDeProdutosService::resumoEmDia()` (privada lá).
     */
    private static function resumoEmDia(PubRascunho $r): bool
    {
        $resumo = (array) ($r->step_state['resumo'] ?? []);

        return (int) ($resumo['revisao'] ?? -1) === (int) $r->revisao
            && array_key_exists('validado', $resumo)
            && ($resumo['versao'] ?? null) === EditorRascunhoService::VERSAO_DO_RESUMO;
    }

    private function recente(mixed $em, int $minutos): bool
    {
        if (! is_string($em) || $em === '') {
            return false;
        }
        try {
            return Carbon::parse($em)->gte($this->agora->copy()->subMinutes($minutos));
        } catch (\Throwable) {
            return false;
        }
    }
}
