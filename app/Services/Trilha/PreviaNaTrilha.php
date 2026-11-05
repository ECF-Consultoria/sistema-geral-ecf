<?php

namespace App\Services\Trilha;

use App\Models\AcervoImagem;
use App\Models\Company;
use App\Models\PubImagem;
use App\Models\PubRascunho;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\PreviaDoAnuncioService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A prévia do anúncio DENTRO da trilha (4ª entrega, fatia 4a, desenho §19, D42/D44): o painel `v:{item}` da equipe mostra
 * o anúncio que o agente preparou — fotos (as geradas marcadas), título e preço por tipo, descrição e ficha — sem abrir o
 * Publicador. SÓ LEITURA: nada aqui grava, chama IA ou publica.
 *
 * ### De onde vem
 * - O item pela régua única ({@see TrilhaDaEmpresa::itens()}, com `comoNaTela`: a mesma etapa e o mesmo status da
 *   linha) → o `pub_produto_id` que o representa → o rascunho dele. Nenhuma outra forma de casar item com anúncio.
 * - O anúncio pelo {@see PreviaDoAnuncioService::montar()} do Publicador, chamado como está: o MESMO plano da
 *   conferência e da publicação ("o que se vê é o que se envia"). A rota do Publicador NÃO é usada — ela é `role:admin`
 *   e marca `EditorEmUso` (a fila e o agente esperam quem está "no editor"); o `montar` sozinho não marca nada.
 *
 * ### O que a trilha muda na saída (o JSON é escrito por lista de campos, como o retrato do cadastro)
 * - **fotos**: o endereço vira a rota da trilha (`trilha.previa.foto`, relativa) — a do Publicador é `role:admin` e a do
 *   ML (`ml_url`) é de outra origem; e cada foto ganha `gerada` ({@see geradas()}, a régua copiada das imagens
 *   automáticas — mudou lá, muda aqui);
 * - **motivo**: o texto do Publicador cita "etapa Produto/Condições/Detalhes" e o Mercado Livre; aqui sai um texto
 *   neutro ({@see motivoNeutro()});
 * - **preço** por tipo: o efetivo × o da Precificação, o mínimo dela, se as cores diferem e se o Premium empatou com o
 *   Clássico ({@see precos()}; a 4c — {@see PrecoNaTrilha} — grava o preço e devolve esta mesma prévia);
 * - **preparo**: o do agente, da régua da linha (`step_state.ia_preparo` e a memória das imagens);
 * - **publicacao** (4d): a situação do "Aprovar e publicar" ({@see PublicacaoNaTrilha::situacao()}), em toda resposta —
 *   inclusive fora da revisão (o item publicado mostra o `publicado` com os MLBs enquanto o pedido vale);
 * - **ajuste** (4b): o "Pedir ajuste ao agente" ({@see AjusteDoRoboNaTrilha::situacao()}) e `ajustes_restantes_hoje`.
 * - **página do anúncio** (05/11/2026, aditivo — a tela cheia "como o comprador vê"): por anúncio, `titulo_completo`
 *   (o `titulo_no_ml`: o título com os valores da variação no fim), `condicao`, `estoque` e `garantia`; no topo, `eixos`
 *   (os nomes da variação: "Cor", "Cor" + "Tamanho") e `vendedor` ({@see vendedor()}: o apelido da loja que a última
 *   leitura da conta guardou no rascunho — nenhuma chamada nova ao canal).
 *   Fora do contrato e de propósito: o id da categoria e a promoção "sem promoção porque…" (cita o Portal).
 *
 * ### Quando há prévia (D44)
 * Item na etapa `revisao` — e no ar (`lanc`) só se o rascunho foi publicado pelo sistema (a prévia é o plano; o anúncio
 * colado ou importado não tem plano que o descreva). Fora disso a resposta é 200 com `pronta: false` e o motivo neutro
 * — não 409: o contrato (§19.5) já descreve o "não pronta", e o item segue útil para a tela (etapa e status).
 *
 * Custo: consultas FIXAS (a régua da linha, o `montar`, a Precificação uma vez a mais para o "da Precificação" e uma
 * consulta para as geradas); o ML só com cache frio (schema público e `shipping_preferences`, dentro do `montar`). R3
 * (04/11/2026): a régua lida aqui fica guardada como o mapa item → anúncio da empresa ({@see imagem()}) — as fotos da
 * galeria, pedidas logo depois, não leem a loja de novo.
 */
class PreviaNaTrilha
{
    public const MOTIVO_PREPARANDO = 'O agente ainda está preparando este anúncio.';

    public const MOTIVO_SEM_PREVIA = 'Este anúncio não tem prévia: ele não foi montado pelo sistema.';

    public const MOTIVO_SEM_CATEGORIA = 'O anúncio ainda está sem categoria.';

    /** A categoria não se leu agora (o schema não veio) ou o `montar` lançou: tentar de novo. */
    public const MOTIVO_INDISPONIVEL = 'Não foi possível montar a prévia agora. Tente de novo em instantes.';

    public const MOTIVO_SEM_TIPO = 'Nenhum tipo de anúncio ligado: nem Clássico, nem Premium.';

    public const MOTIVO_SEM_VARIACAO = 'Nenhuma variação ativa no anúncio.';

    public const MOTIVO_PENDENCIA = 'O anúncio ainda tem uma pendência que impede a prévia.';

    /** R3: por quanto tempo o mapa item → anúncio da empresa vale para as fotos ({@see imagem()}). */
    public const PUBS_VALE_MIN = 10;

    /** As etapas da trilha em que o painel `v:` mostra a prévia (D44). */
    public const ETAPAS_COM_PREVIA = ['revisao', 'lanc'];

    /** `listing_type_id` → a chave do tipo no contrato (o Publicador só publica estes dois). Público: o preço (4c) usa o mesmo. */
    public const TIPO = ['gold_special' => 'classico', 'gold_pro' => 'premium'];

    public const NOME_DO_TIPO = ['classico' => 'Clássico', 'premium' => 'Premium'];

    /** Abaixo disto o preço é o mesmo (centavo arredondado) — a régua do D46. Pública: o gravar do preço (4c) usa a mesma. */
    public const DIFERENCA = 0.005;

    /** O preparo do agente que o contrato leva, e a pílula da linha → a palavra do contrato. */
    private const ETAPAS_DO_PREPARO = ['titulo', 'descricao', 'imagens'];

    private const PILULA = ['pronto' => 'ok', 'pulado' => 'pulado', 'erro' => 'erro', 'fazendo' => 'fazendo', 'fila' => 'fila'];

    public function __construct(
        private PreviaDoAnuncioService $previa,
        private RascunhoRepository $repo,
        private DadosEfetivosService $efetivos,
    ) {}

    /**
     * A prévia do item `$chave` (`s-12`, `c-12-2`, `o-55`) da empresa, no contrato da §19.5. Item que não existe na
     * empresa: 404 (de outra loja também — a régua só conhece os itens desta).
     *
     * 4e (04/11/2026, aditivo): `$urlDaFoto` troca o endereço das fotos (a porta do CLIENTE serve as fotos pela rota
     * dela, `portal.trilha.aprovacao.foto`; sem ele, a da equipe) e `$item` é o item já lido pela régua
     * ({@see TrilhaDaEmpresa::itens()} com `comoNaTela`) — quem já o leu não paga a régua duas vezes. O corte do que o
     * cliente não vê é de {@see AprovacaoDoClienteNaTrilha::paraOCliente()}, não daqui.
     *
     * @param  ?\Closure(int): string  $urlDaFoto  id da `pub_imagens` → o endereço da foto na porta de quem pede
     * @param  ?array  $item  o item de `TrilhaDaEmpresa::itens($empresa, comoNaTela: true)[$chave]`
     * @return array<string, mixed>
     */
    public function montar(Company $empresa, string $chave, ?\Closure $urlDaFoto = null, ?array $item = null): array
    {
        if ($item === null) {
            $itens = TrilhaDaEmpresa::itens($empresa, comoNaTela: true);
            // R3: a régua já está lida — as fotos que a galeria pede logo depois acham o rascunho por aqui.
            self::guardarPubs($empresa, $itens);
            $item = $itens[$chave] ?? null;
        }
        abort_if($item === null, 404);
        // R3: a miniatura (a grade da galeria) só na porta da equipe — a do cliente tem a rota dela e não a recebe.
        $miniaturaDaFoto = $urlDaFoto === null ? fn (int $imagem): string => self::urlDaFoto($empresa, $chave, $imagem, miniatura: true) : null;
        $urlDaFoto ??= fn (int $imagem): string => self::urlDaFoto($empresa, $chave, $imagem);

        $r = self::rascunho($item);
        $restantes = AjusteDoRoboNaTrilha::restantesHoje($r);
        $publicacao = PublicacaoNaTrilha::situacao($r);
        $saida = [
            'item'       => [
                'id'     => $chave,
                'nome'   => (string) $item['nome'],
                'tipo'   => (string) $item['tipo'],
                'etapa'  => (string) $item['etapa'],
                'status' => (string) $item['status'],
            ],
            'pronta'     => false,
            'motivo'     => null,
            'revisao'    => $r !== null ? (int) $r->revisao : null,
            'categoria'  => null,
            'tipos'      => [],
            'variantes'  => [],
            'anuncios'   => [],
            'descricao'  => null,
            'preco'      => null,
            'preparo'    => $r !== null ? self::preparo($item['preparo'] ?? null) : null,
            // 4d: a situação do "Aprovar e publicar" — o MESMO cálculo do `GET …/publicacao` (nula: nunca pediu e nada vivo
            // na fila).
            'publicacao' => $publicacao,
            // 4b: o "Pedir ajuste ao agente" — o MESMO cálculo do `GET …/ajuste` (nulo: nunca pediu) — e quantos pedidos o
            // anúncio ainda tem hoje (0 também quando o texto não é do agente: a tela só oferece o botão com saldo).
            'ajuste'                 => AjusteDoRoboNaTrilha::situacao($r, $restantes),
            'ajustes_restantes_hoje' => $restantes,
            // 4e: a aprovação do cliente na versão da EQUIPE (nula: a loja não aprova com o cliente, ou nunca mandou) —
            // {@see AprovacaoDoClienteNaTrilha::daEquipe()}.
            'aprovacao'  => AprovacaoDoClienteNaTrilha::daEquipe($empresa, $r, $publicacao),
            'bloqueios'  => [],
            // A página do anúncio (05/11/2026, aditivo, no FIM): os nomes da variação e o "Vendido por".
            'eixos'      => [],
            'vendedor'   => $r !== null ? self::vendedor($r) : null,
        ];

        if (! in_array($item['etapa'], self::ETAPAS_COM_PREVIA, true)) {
            return [...$saida, 'motivo' => self::MOTIVO_PREPARANDO];
        }
        // Na revisão sem rascunho (não acontece pela régua) ou no ar por fora do sistema: não há plano para mostrar.
        if ($r === null || ($item['etapa'] === 'lanc' && ! in_array($r->status, [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED], true))) {
            return [...$saida, 'motivo' => self::MOTIVO_SEM_PREVIA];
        }

        // O `montar` LANÇA quando o schema da categoria precisa vir do ML e não vem (cache frio, ML fora, token): a rota do
        // Publicador responde 500; aqui a prévia diz que não deu agora, com o item e a etapa — o painel continua de pé.
        try {
            $p = $this->previa->montar($r);
        } catch (\Throwable $e) {
            Log::warning("[Trilha] Prévia do item {$chave} da empresa {$empresa->id} (rascunho {$r->id}) não montou: ".class_basename($e).' — '.$e->getMessage());

            return [...$saida, 'motivo' => self::MOTIVO_INDISPONIVEL];
        }
        $tipos = [];
        foreach ((array) $p['tipos'] as $t) {
            if (isset(self::TIPO[$t['listing_type_id']])) {
                $tipos[] = ['chave' => self::TIPO[$t['listing_type_id']], 'nome' => self::NOME_DO_TIPO[self::TIPO[$t['listing_type_id']]]];
            }
        }
        $saida = [
            ...$saida,
            'revisao'   => (int) $p['revisao'],
            'categoria' => $p['categoria'] !== null ? ['caminho' => array_values(array_map('strval', (array) $p['categoria']['caminho']))] : null,
            'tipos'     => $tipos,
            'variantes' => array_map(fn (array $v) => ['chave' => (string) $v['chave'], 'rotulo' => (string) $v['rotulo']], (array) $p['variantes']),
            'descricao' => $p['descricao'],
            'eixos'     => self::nomesDosEixos((array) ($p['eixos'] ?? [])),
        ];
        if (! $p['pronta']) {
            return [...$saida, 'motivo' => self::motivoNeutro($p, $r)];
        }

        $geradas = self::geradas((int) $r->id);
        $anuncios = [];
        foreach ($p['anuncios'] as $a) {
            $tipo = self::TIPO[$a['listing_type_id']] ?? null;
            if ($tipo === null) {
                continue;
            }
            $anuncios[] = [
                'tipo'            => $tipo,
                'variante'        => (string) $a['variante']['chave'],
                'titulo'          => $a['titulo'],
                'preco'           => $a['preco'],
                'promocao'        => $a['promocao'],
                'parcelas'        => $a['parcelas'],
                'fotos'           => array_map(fn (array $f) => [
                    'id'        => (string) $f['id'],
                    'url'       => $urlDaFoto((int) $f['id']),
                    // R3 (aditivo): a versão pequena para a grade; nula na porta do cliente.
                    'miniatura' => $miniaturaDaFoto !== null ? $miniaturaDaFoto((int) $f['id']) : null,
                    'gerada'    => isset($geradas[(int) $f['id']]),
                ], (array) $a['fotos']),
                'caracteristicas' => $a['caracteristicas'],
                // O `aviso` do Publicador, quando existir, é texto de lá (cita o ML): a trilha não o repassa.
                'envio'           => [
                    'modo'         => (string) $a['envio']['modo'],
                    'frete_gratis' => (bool) ($a['envio']['frete_gratis'] ?? false),
                    'retirada'     => (bool) ($a['envio']['retirada'] ?? false),
                    'aviso'        => null,
                ],
                // A página do anúncio (05/11/2026, aditivo, no FIM).
                ...self::daPagina($a),
            ];
        }

        return [...$saida, 'pronta' => true, 'anuncios' => $anuncios, 'preco' => $this->precos($r, $p['anuncios'], $tipos)];
    }

    /**
     * A foto do ENDEREÇO: só uma `pub_imagens` do rascunho que a prévia do item mostra, achado pela MESMA régua
     * ({@see TrilhaDaEmpresa::itens()} → `pub_produto_id`) — a de outro rascunho (outro item, outra loja) é 404. A régua
     * sem as ondas: o `pub_produto_id` não depende delas, e a foto não confere a etapa (a lista das fotos é da prévia).
     *
     * R3 (retorno do 1º teste, 04/11/2026): a régua lia a loja INTEIRA (produtos, ofertas e o `step_state` de todos os
     * rascunhos) a cada foto, e a galeria pede várias de uma vez. Agora o item → anúncio vem do mapa que a própria prévia
     * acabou de ler ({@see guardarPubs()}, por empresa, {@see PUBS_VALE_MIN} min): a foto custa uma leitura de cache e UMA
     * consulta. Sem o mapa (expirou, ou a foto pedida sem a prévia), a régua roda uma vez e o guarda para as próximas. O
     * mapa é do servidor e da empresa do endereço, montado pela régua de sempre: a guarda é a mesma.
     */
    public function imagem(Company $empresa, string $chave, int $imagem): PubImagem
    {
        $pub = self::pubDoItem($empresa, $chave);
        abort_if($pub === null, 404);

        return PubImagem::query()
            ->whereIn('rascunho_id', PubRascunho::query()->select('id')->where('produto_id', $pub))
            ->whereKey($imagem)
            ->firstOrFail();
    }

    /** O anúncio (pub_produto) do item pela régua, do mapa da empresa (nulo: o item não existe ou não tem anúncio). */
    private static function pubDoItem(Company $empresa, string $chave): ?int
    {
        $mapa = Cache::get(self::chaveDosPubs($empresa));
        if (! is_array($mapa) || ! array_key_exists($chave, $mapa)) {
            $mapa = self::guardarPubs($empresa, TrilhaDaEmpresa::itens($empresa));
        }

        return isset($mapa[$chave]) ? (int) $mapa[$chave] : null;
    }

    /**
     * Guarda o mapa item → `pub_produto_id` da empresa (o que a régua acabou de ler).
     *
     * @param  array<string, array{pub_produto_id: ?int}>  $itens
     * @return array<string, ?int>
     */
    private static function guardarPubs(Company $empresa, array $itens): array
    {
        $mapa = array_map(fn (array $i) => isset($i['pub_produto_id']) ? (int) $i['pub_produto_id'] : null, $itens);
        Cache::put(self::chaveDosPubs($empresa), $mapa, now()->addMinutes(self::PUBS_VALE_MIN));

        return $mapa;
    }

    public static function chaveDosPubs(Company $empresa): string
    {
        return 'trilha:previa:pubs:'.(int) $empresa->id;
    }

    /**
     * As fotos do rascunho feitas pela IA, numa consulta — CÓPIA da régua de `CriativosAutomaticosService::grupos()`
     * (que as tira das referências): ativo do acervo com origem `ia` OU foto que veio de um criativo aprovado
     * (`ml_anuncio_criativos.pub_imagem_id`, a mesma regra do `acervo:registrar-existentes`). Mudou lá, muda aqui. NÃO é o
     * `de_ia` da Aprovação (outra régua, de outra tela).
     *
     * @return array<int, true> id da `pub_imagens` → true
     */
    public static function geradas(int $rascunhoId): array
    {
        $ids = DB::table('pub_imagens as i')
            ->leftJoin('acervo_imagens as acv', 'acv.id', '=', 'i.acervo_imagem_id')
            ->where('i.rascunho_id', $rascunhoId)
            ->where(fn ($q) => $q->where('acv.origem', AcervoImagem::ORIGEM_IA)
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('ml_anuncio_criativos as c')->whereColumn('c.pub_imagem_id', 'i.id')))
            ->pluck('i.id');

        $saida = [];
        foreach ($ids as $id) {
            $saida[(int) $id] = true;
        }

        return $saida;
    }

    /** O endereço da foto pela trilha, relativo (como as fotos do retrato do cadastro); `miniatura` = a versão pequena (R3). */
    public static function urlDaFoto(Company $empresa, string $chave, int $imagem, bool $miniatura = false): string
    {
        return route('trilha.previa.foto', ['company' => $empresa->id, 'item' => $chave, 'imagem' => $imagem, ...($miniatura ? ['miniatura' => 1] : [])], false);
    }

    /**
     * O rascunho do pub_produto que representa o item (nulo: o item ainda não está no Publicador). Público: o preço
     * ({@see PrecoNaTrilha}) acha o rascunho pela MESMA régua.
     */
    public static function rascunho(array $item): ?PubRascunho
    {
        $pub = $item['pub_produto_id'] ?? null;

        return $pub !== null ? PubRascunho::query()->where('produto_id', (int) $pub)->first() : null;
    }

    /**
     * O preparo do agente no contrato: título, descrição e imagens, pela pílula da linha (a régua de
     * `SituacaoDasOfertas::preparo()` — com o `rodando`/`adiado` vencido virando erro) — `pronto` sai `ok`. Nulo = nada
     * registrado (o texto do Kit N, que o agente não escreve; as imagens sem a automação).
     *
     * @return array{titulo: ?string, descricao: ?string, imagens: ?string}
     */
    /**
     * O que a página do anúncio (a tela cheia "como o comprador vê") lê de cada anúncio do plano: o título como o
     * comprador lê (`titulo_no_ml`: o título com os valores da variação no fim), a condição, o estoque e a garantia.
     *
     * @param  array<string, mixed>  $a  um anúncio de {@see PreviaDoAnuncioService::montar()}
     * @return array{titulo_completo: ?string, condicao: ?string, estoque: ?int, garantia: ?string}
     */
    public static function daPagina(array $a): array
    {
        return [
            'titulo_completo' => isset($a['titulo_no_ml']) ? (string) $a['titulo_no_ml'] : null,
            'condicao'        => isset($a['condicao']) ? (string) $a['condicao'] : null,
            'estoque'         => isset($a['estoque']) && is_numeric($a['estoque']) ? (int) $a['estoque'] : null,
            'garantia'        => isset($a['garantia']) ? (string) $a['garantia'] : null,
        ];
    }

    /**
     * Os nomes da variação ("Cor", "Tamanho") a partir dos eixos do plano, aparados e sem o vazio.
     *
     * @param  array<int, mixed>  $eixos  os `eixos` de {@see PreviaDoAnuncioService::montar()} (`[{chave, nome}]`)
     * @return list<string>
     */
    public static function nomesDosEixos(array $eixos): array
    {
        $nomes = array_map(fn ($e) => trim((string) (is_array($e) ? ($e['nome'] ?? '') : '')), $eixos);

        return array_values(array_filter($nomes, fn (string $nome) => $nome !== ''));
    }

    /**
     * O apelido da loja no "Vendido por" da página do anúncio: o que a última leitura da conta guardou no rascunho
     * (`step_state.conta.nickname`, gravado pelo editor e pelo preparo). Nulo quando a conta ainda não foi lida ou a
     * leitura falhou — a página então não mostra a linha (o nome do cadastro não é o que o comprador vê).
     */
    private static function vendedor(PubRascunho $r): ?string
    {
        $apelido = $r->step_state['conta']['nickname'] ?? null;

        return is_string($apelido) && trim($apelido) !== '' ? trim($apelido) : null;
    }

    private static function preparo(?array $doItem): array
    {
        $saida = [];
        foreach (self::ETAPAS_DO_PREPARO as $e) {
            $saida[$e] = self::PILULA[$doItem[$e] ?? ''] ?? null;
        }

        return $saida;
    }

    /**
     * O motivo de o `montar` não ter prévia, em texto neutro: o do Publicador manda a pessoa a uma etapa do editor de lá
     * ("na etapa Produto", "em Condições de venda") ou cita o Mercado Livre (as `RegraViolada`).
     */
    private static function motivoNeutro(array $p, PubRascunho $r): string
    {
        return match (true) {
            $p['categoria'] === null => trim((string) $r->categoria_id) === '' ? self::MOTIVO_SEM_CATEGORIA : self::MOTIVO_INDISPONIVEL,
            $p['tipos'] === []       => self::MOTIVO_SEM_TIPO,
            $p['variantes'] === []   => self::MOTIVO_SEM_VARIACAO,
            default                  => self::MOTIVO_PENDENCIA,
        };
    }

    /**
     * O preço de cada tipo: `valor` = o do anúncio da 1ª cor ativa (o preço do plano, o que vai ao ML); `da_precificacao`
     * = o da Precificação para a mesma cor — a MESMA leitura do `montar` (`DadosEfetivosService::daProduto` →
     * `RascunhoSnapshot::comEfetivosDe`: o preço da oferta daquela cor, senão o da âncora), refeita aqui porque o
     * `montar` não a devolve; `minimo` (4c) = o mínimo da Precificação da MESMA linha (o preço da Central de Promoções,
     * `dados.portal[lt].minimo`); `ajustado` = ALGUMA cor ativa do tipo tem preço gravado no rascunho que difere do da
     * Precificação (ou a Precificação não tem preço). Com cores de preço diferente, o preço de cada uma está em
     * `anuncios[].preco`. Tipo desligado: null.
     *
     * 4c (aditivo): `por_cor` = em algum tipo, uma cor ativa tem preço diferente das outras (meio centavo); e
     * `premium_igual` = em alguma cor, o Premium tem o MESMO preço do Clássico (a régua do D46, a mesma do gravar —
     * {@see PrecoNaTrilha}). Os dois olham o preço do PLANO de cada cor (o `anuncios[].preco`).
     *
     * @param  list<array<string, mixed>>  $anuncios  os do `montar` (com `listing_type_id` e `variante.chave`)
     * @param  list<array{chave: string, nome: string}>  $tipos
     * @return array{classico: ?array{valor: ?float, da_precificacao: ?float, minimo: ?float, ajustado: bool}, premium: ?array{valor: ?float, da_precificacao: ?float, minimo: ?float, ajustado: bool}, por_cor: bool, premium_igual: bool}
     */
    private function precos(PubRascunho $r, array $anuncios, array $tipos): array
    {
        $efetivo = $this->repo->snapshot($r)->comEfetivosDe($this->efetivos->daProduto($r->produto));
        $variantes = [];
        foreach ($efetivo->variantesAtivas() as $v) {
            /** @var Variante $v */
            $variantes[$v->chave] = $v->dados;
        }

        $saida = ['classico' => null, 'premium' => null];
        $ligados = array_column($tipos, 'chave');
        foreach (self::TIPO as $lt => $tipo) {
            if (! in_array($tipo, $ligados, true)) {
                continue;
            }
            $doTipo = array_values(array_filter($anuncios, fn (array $a) => $a['listing_type_id'] === $lt));
            if ($doTipo === []) {
                continue;
            }
            $dados = $variantes[(string) $doTipo[0]['variante']['chave']] ?? [];
            $ajustado = false;
            foreach ($doTipo as $a) {
                $ajustado = $ajustado || self::ajustado($a['preco'], $variantes[(string) $a['variante']['chave']] ?? [], $lt);
            }
            $saida[$tipo] = [
                'valor'           => $doTipo[0]['preco'] !== null ? (float) $doTipo[0]['preco'] : null,
                'da_precificacao' => isset($dados['portal'][$lt]['anunciado']) ? (float) $dados['portal'][$lt]['anunciado'] : null,
                'minimo'          => isset($dados['portal'][$lt]['minimo']) ? (float) $dados['portal'][$lt]['minimo'] : null,
                'ajustado'        => $ajustado,
            ];
        }

        // 4c: o preço do plano de cada cor, por tipo — para dizer se as cores diferem e se o Premium empatou com o Clássico.
        $porVariante = [];
        foreach ($anuncios as $a) {
            if (isset(self::TIPO[$a['listing_type_id']]) && in_array(self::TIPO[$a['listing_type_id']], $ligados, true)) {
                $porVariante[(string) $a['variante']['chave']][$a['listing_type_id']] = $a['preco'] !== null ? (float) $a['preco'] : null;
            }
        }
        $porCor = false;
        foreach (array_keys(self::TIPO) as $lt) {
            $valores = [];
            foreach ($porVariante as $precos) {
                if (array_key_exists($lt, $precos)) {
                    $valores[$precos[$lt] === null ? 'sem' : number_format($precos[$lt], 2, '.', '')] = true;
                }
            }
            $porCor = $porCor || count($valores) > 1;
        }
        $premiumIgual = false;
        foreach ($porVariante as $precos) {
            $premiumIgual = $premiumIgual || self::mesmoPreco($precos['gold_special'] ?? null, $precos['gold_pro'] ?? null);
        }

        return [...$saida, 'por_cor' => $porCor, 'premium_igual' => $premiumIgual];
    }

    /** Os dois preços existem e diferem menos que meio centavo — a régua do D46 (Premium = Clássico). */
    public static function mesmoPreco(?float $classico, ?float $premium): bool
    {
        return $classico !== null && $premium !== null && abs($classico - $premium) < self::DIFERENCA;
    }

    /**
     * O preço desta cor neste tipo foi gravado no rascunho (não veio da Precificação: `preco_do_portal`, a marca que o
     * `comEfetivos` põe) e é outro que o da Precificação — ou ela não tem preço.
     */
    private static function ajustado(mixed $valor, array $dados, string $lt): bool
    {
        if ($valor === null || $dados === [] || ($dados['preco_do_portal'][$lt] ?? false)) {
            return false;
        }
        $daPrecificacao = $dados['portal'][$lt]['anunciado'] ?? null;

        return $daPrecificacao === null || abs((float) $valor - (float) $daPrecificacao) >= self::DIFERENCA;
    }
}
