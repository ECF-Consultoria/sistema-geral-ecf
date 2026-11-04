<?php

namespace App\Http\Controllers\Trilha;

use App\Models\Company;
use App\Models\User;
use App\Services\Portal\PortalEquipeService;
use App\Services\Trilha\CadastroNaTrilha;
use App\Support\Portal\PortalContexto;
use App\Support\Trilha\AprovacaoDoCliente;

/**
 * As props da página `Trilha/Empresa` nas duas portas, a partir da saída CRUA de
 * `App\Services\Trilha\TrilhaDaEmpresa::montar()` — e a contagem por etapa do índice da equipe.
 *
 * O serviço não sabe quem está olhando; quem sabe é esta classe. Ela faz duas coisas:
 *
 * 1. **Acrescenta os links** para as telas que já existem (fora o Mapeamento e o Planejamento da trilha de trabalho,
 *    que gravam pelos endereços de `escrita`, toda ação é um link). Cada link
 *    só sai quando quem olha consegue abri-lo — "Abrir o Portal" só para quem `PortalEquipeService::podeEntrar()`.
 *    Link que daria 403 é pior que link nenhum. Desde o R10 (04/11/2026) nenhum link leva ao Publicador, nem para o
 *    admin: "a trilha é o publicador".
 * 2. **Tira o que é só da equipe** na porta do cliente: o id e o link do Publicador, o preparo do agente, o MLB
 *    dos anúncios e a lista das sugestões (fica só o total). O cliente não vê o Publicador nem os bastidores do
 *    agente; o MLB, além disso, é o elo com o Mercado Livre que não se expõe por lá.
 *
 * ### Por que LISTA de campos, e não "tudo menos"
 * Os campos que saem estão escritos aqui, um a um ({@see self::PRODUTO}, {@see self::OFERTA}, e dentro da oferta
 * {@see self::COMPONENTE} e os anúncios). Um campo novo que o serviço passe a devolver nesses níveis NÃO chega à
 * tela — nem à do cliente — até alguém acrescentá-lo aqui. É a mesma lógica da allowlist do
 * `RestringeDominioDoPortal`: o padrão seguro é o campo novo nascer invisível, e expô-lo ao cliente vira uma
 * decisão consciente, não um efeito colateral de mexer no serviço.
 *
 * O que a lista NÃO cobre, de propósito (revisão de 31/10/2026, P1 — antes este texto prometia mais do que o
 * código dava): `preparo` e `sugestoes_pendentes` saem para a EQUIPE como o serviço os monta (ela vê tudo); na
 * porta do cliente os dois saem nulos/vazios. As listas de texto (`skus`, `faltas`, `avisos`, `ambientes`) passam como
 * vêm.
 *
 * ### As ondas (2ª entrega, 02/11/2026)
 * Também por lista: {@see self::ONDA} para a equipe e {@see self::ONDA_CLIENTE} para o cliente — nome, data e se
 * já começou, como no artefato; quem criou a onda não vai ao cliente. Com a trilha de trabalho ligada a equipe
 * ganha `pode_editar` (a mesma régua de escrita do Portal) e os endereços das escritas em `escrita`; o cliente
 * nunca recebe nenhum dos dois, e o "Decidir no Planejamento" do Portal sai da porta dele: o mapeamento passou a
 * ser feito na trilha.
 *
 * ### O cadastro na trilha (3ª entrega, 02/11/2026)
 * A equipe cadastra o produto pela trilha com os endereços que já saem em `escrita.produtos` (`{produtos}` cria,
 * `{produtos}/{id}/cadastro` abre, `{produtos}/{id}/linhas` grava). Cada produto da equipe leva `cadastro_travado` (o
 * item que andou deixa o cadastro só para leitura); o cliente nunca recebe a chave.
 *
 * ### A trilha de trabalho substitui o Portal (decisão do usuário de 03/11/2026; F5 do desenho, D16 e D17)
 * Com `trabalho`, nenhum endereço servido aqui leva ao Portal do cliente: na equipe, `links.portal_abrir` nulo; no
 * cliente, `voltar`, `links.planejamento`, `links.produtos` e `url_ficha` nulos. Sem a chave, tudo como na 1ª entrega.
 *
 * ### A 1ª escrita do cliente (4ª entrega, 4f, 04/11/2026)
 * `cliente_escrita.fotos` — o quadro de fotos do produto na porta do cliente (`/portal/{pe}/trilha/produtos/__PRODUTO__/
 * fotos`, as rotas da própria trilha sob o prefixo da porta dele, não um link do Portal). Só para o cliente de verdade e
 * com as duas chaves; na equipe (e no modo equipe do Portal) a chave vem nula. `escrita` continua sendo só da equipe.
 *
 * ### A aprovação do cliente (4ª entrega, 4e, 04/11/2026)
 * Só na loja da chave {@see AprovacaoDoCliente}: a equipe ganha `escrita.revisao.cliente` (o "Mandar ao cliente") e a
 * porta do cliente `cliente_escrita.aprovacao = {previa, aprovar, ajuste}` (com o marcador `__ITEM__`). Esta, também no
 * modo equipe do Portal — ali a ECF aprova EM NOME do cliente (`decidido_por_equipe`, desenho §19.1), e as fotos (4f)
 * continuam fora (`cliente_escrita.fotos` nula). Sem a chave, as duas formas ficam exatamente como na 4f.
 */
class ApresentacaoDaTrilha
{
    /** O lugar da chave do item nos endereços de `escrita.revisao` (4a): a tela troca pelo id da linha (`s-12`). */
    public const MARCADOR_DO_ITEM = '__ITEM__';

    /** O lugar do id do produto em `cliente_escrita.fotos` (4f): a tela troca pelo id do produto do painel `p:`. */
    public const MARCADOR_DO_PRODUTO = '__PRODUTO__';

    /** Campos da onda para a EQUIPE. */
    private const ONDA = [
        'id'         => null,
        'nome'       => null,
        'data'       => null,
        'comecou'    => false,
        'criada_por' => null,
        'criada_em'  => null,
    ];

    /** Campos da onda para o CLIENTE: o que o artefato mostra a ele (nome, data, andamento), sem quem criou. */
    private const ONDA_CLIENTE = [
        'id'      => null,
        'nome'    => null,
        'data'    => null,
        'comecou' => false,
    ];

    /** Campos do produto que saem para a tela (as duas portas), com o valor de quando o serviço não os trouxer. */
    private const PRODUTO = [
        'id'                        => null,
        'nome'                      => null,
        'codigo'                    => null,
        'skus'                      => [],
        'familia'                   => null,
        'ambientes'                 => [],
        'categoria'                 => null,
        'variacoes'                 => 0,
        'custo_min'                 => null,
        'custo_max'                 => null,
        'medidas'                   => null,
        'embalagem'                 => null,
        'fotos_min'                 => 0,
        // R2 (04/11/2026): a miniatura da coluna "Fotos" da Lista. O serviço manda só os ids; cada porta troca pelo
        // endereço que ELA serve ({@see capaParaEquipe()}, {@see capaParaCliente()}) — nulo onde a rota não serviria.
        'capa'                      => null,
        'tem_descricao'             => false,
        'ficha'                     => null,
        'faltas'                    => [],
        // 3ª entrega (pergunta 3): o que não segura o produto na Lista, mas a tela avisa — com a trilha de trabalho, o
        // "frete ME1" ("vai por ME1 (transportadora)"). Texto neutro: as duas portas o leem. Sem a chave, vazio.
        'avisos'                    => [],
        'etapa'                     => null,
        'sugestoes_pendentes'       => [],
        'sugestoes_pendentes_total' => 0,
        'mapeado_em'                => null,
        'mapeado_por'               => null,
    ];

    /**
     * Campos de cada componente da oferta (`componentes[]`) — as duas portas. Sem esta lista o que o serviço pusesse
     * DENTRO do componente sairia cru, inclusive para o cliente (revisão de 31/10/2026, P1).
     */
    private const COMPONENTE = [
        'produto_id' => null,
        'quantidade' => 1,
        'sku'        => '',
        'nome'       => '',
    ];

    /** Campos da oferta da trilha que saem para a tela (as duas portas). */
    private const OFERTA = [
        'id'          => null,
        'tipo'        => null,
        'nome'        => null,
        'sku'         => null,
        'skus'        => [],
        'componentes' => [],
        'etapa'       => null,
        'status'      => null,
        'alerta'      => false,
        'anuncios'    => [],
        'preparo'     => null,
        'onda_id'     => null,
        'sem_produto' => false,
        'planejavel'  => false,
        'pode_tirar'  => false,
        // R4 (04/11/2026): por que o agente parou (a causa) e se a equipe pode pedir de novo. Só a equipe: o cliente
        // recebe nulo, como o `preparo`.
        'parada'      => null,
    ];

    /** As etapas que contam produtos e as que contam ofertas — a mesma divisão de `contagemPorEtapa()` do front. */
    private const ETAPAS_DE_PRODUTO = ['lista', 'mapa'];

    private const ETAPAS_DE_OFERTA = ['plano', 'robo', 'revisao', 'lanc'];

    public function __construct(
        private PortalEquipeService $equipe,
    ) {}

    /**
     * Porta da EQUIPE (`/trilha/{empresa}`): tudo o que o serviço montou, mais o link para o Portal da empresa (sem a
     * trilha de trabalho). A visão "como o cliente vê" da equipe é alternada no navegador, sem vir ao servidor.
     *
     * R10 (retorno do 1º teste, 04/11/2026): NENHUM endereço do Publicador sai daqui — nem para o admin ("a trilha é o
     * publicador"): saíram `links.publicador` (a conta) e `url_publicador` (o editor do produto e da oferta).
     *
     * @param  array{produtos: list<array>, ofertas: list<array>, ondas: list<array>, gerado_em: string}  $trilha
     */
    public function paraEquipe(Company $empresa, array $trilha, User $usuario): array
    {
        $podeEntrar = $this->equipe->podeEntrar($usuario, $empresa);
        $trabalho = (bool) ($trilha['trabalho'] ?? false);
        $podeEditar = $trabalho && $podeEntrar;

        return [
            'visao'         => 'equipe',
            'sessao_equipe' => false,
            'empresa'       => self::empresa($empresa),
            'voltar'        => ['url' => route('trilha.index'), 'rotulo' => 'Empresas'],
            'trabalho'      => $trabalho,
            'pode_editar'   => $podeEditar,
            'escrita'       => $podeEditar ? self::escrita($empresa) : null,
            // 4f: os endereços de escrita do CLIENTE nunca vêm à porta da equipe — nem para o "Ver como: Cliente" (a
            // equipe tem a gaveta dela). A chave existe nas duas portas para a forma ser uma só.
            'cliente_escrita' => null,
            'links'         => [
                // Com a trilha de trabalho a trilha SUBSTITUI o Portal (decisão do usuário de 03/11/2026; F5 do desenho,
                // D17): nenhum caminho dela leva ao Portal do cliente. Sem a chave, como na 1ª entrega.
                'portal_abrir' => $podeEntrar && ! $trabalho
                    ? route('companies.portal.abrir', ['company' => $empresa->id])
                    : null,
                'planejamento' => null,
                'produtos'     => null,
            ],
            'produtos'      => array_map(fn (array $p) => array_replace(self::campos($p, self::PRODUTO), [
                'capa' => $podeEditar ? self::capaParaEquipe($empresa, $p['capa'] ?? null) : null,
            ]) + [
                'url_ficha'        => null,
                // 3ª entrega (pergunta 1): o item que já andou deixa o cadastro do produto só para leitura na trilha. Só
                // nesta porta — o cliente não cadastra pela trilha e não lê a régua do robô.
                'cadastro_travado' => (bool) ($p['cadastro_travado'] ?? false),
            ], $trilha['produtos'] ?? []),
            'ofertas'       => array_map(fn (array $o) => array_replace(self::campos($o, self::OFERTA), [
                'componentes' => self::componentes($o['componentes'] ?? []),
                'anuncios'    => self::anuncios($o['anuncios'] ?? [], comMlb: true),
            ]), $trilha['ofertas'] ?? []),
            'ondas'         => array_map(fn (array $o) => self::campos($o, self::ONDA), $trilha['ondas'] ?? []),
            'gerado_em'     => $trilha['gerado_em'] ?? null,
        ];
    }

    /**
     * Os endereços das escritas da equipe (Planejamento, Mapeamento e o cadastro). A onda e o produto entram no fim do
     * caminho (`{ondas}/{id}`, `{produtos}/{id}/mapeamento`, `{produtos}/{id}/ficha-tecnica`…). `categorias` (F2) é a
     * base das leituras da categoria e da ficha (`{categorias}?q=`, `{categorias}/arvore?pai=`,
     * `{categorias}/{id}/campos`, `{categorias}/{id}/titulo?nome=`) — relativa, como as fotos do retrato. `planilha` (F7):
     * os três endereços da planilha por categoria — `modelo` (GET, `?categorias[]=`), `previa` e `gravar` (POST multipart),
     * relativos como o `categorias`. `mercado_livre` (F8): `anuncios` e `estado` (GET), `importar` e `limpar` (POST),
     * relativos também. `revisao` (4ª entrega, 4a): a prévia do anúncio do painel `v:{item}`, relativa, com o marcador
     * `__ITEM__` no lugar da chave do item (`s-12`, `c-12-2`, `o-55`) para a tela trocar — cada fatia da 4ª entrega
     * acrescenta o seu endereço aqui (4c: `preco`, o PUT do preço ajustável; 4d: `publicar`, o POST do "Aprovar e
     * publicar", e `publicacao`, o GET do acompanhamento — os dois em toda loja com escrita: a #459 é conferida no servidor;
     * 4b: `ajuste`, o POST do "Pedir ajuste ao robô" e o GET do estado dele, no mesmo endereço; 4e: `cliente`, o POST do
     * "Mandar ao cliente" — SÓ na loja que aprova com o cliente: sem a chave a forma fica a da 4b).
     *
     * @return array{ondas: string, planejamento: string, produtos: string, categorias: string, planilha: array{modelo: string, previa: string, gravar: string}, mercado_livre: array{anuncios: string, importar: string, estado: string, limpar: string}, revisao: array{previa: string, preco: string, publicar: string, publicacao: string, ajuste: string, cliente?: string}, preparo: string}
     */
    private static function escrita(Company $empresa): array
    {
        $base = route('trilha.empresa', ['company' => $empresa->id]);
        $empresaId = ['company' => $empresa->id];

        return [
            'ondas'        => route('trilha.ondas.criar', $empresaId),
            'planejamento' => route('trilha.planejamento', $empresaId),
            'produtos'     => $base.'/produtos',
            'categorias'   => route('trilha.categorias', $empresaId, false),
            'planilha'     => [
                'modelo' => route('trilha.planilha.modelo', $empresaId, false),
                'previa' => route('trilha.planilha.previa', $empresaId, false),
                'gravar' => route('trilha.planilha.gravar', $empresaId, false),
            ],
            // F8: importar do Mercado Livre — a lista (GET), o pedido (POST `mlbs[]`), o estado (GET) e o limpar (POST).
            'mercado_livre' => [
                'anuncios' => route('trilha.ml.anuncios', $empresaId, false),
                'importar' => route('trilha.ml.importar', $empresaId, false),
                'estado'   => route('trilha.ml.estado', $empresaId, false),
                'limpar'   => route('trilha.ml.limpar', $empresaId, false),
            ],
            // 4a: a prévia do anúncio (GET), com o item no marcador. 4c: o preço ajustável dela (PUT). 4d: aprovar e
            // publicar (POST) e o acompanhamento (GET) — a guarda da #459 é do servidor; aqui só o endereço.
            'revisao'       => [
                'previa'     => route('trilha.previa', [...$empresaId, 'item' => self::MARCADOR_DO_ITEM], false),
                'preco'      => route('trilha.preco', [...$empresaId, 'item' => self::MARCADOR_DO_ITEM], false),
                'publicar'   => route('trilha.publicar', [...$empresaId, 'item' => self::MARCADOR_DO_ITEM], false),
                'publicacao' => route('trilha.publicacao', [...$empresaId, 'item' => self::MARCADOR_DO_ITEM], false),
                // 4b: pedir ajuste ao robô (POST) e o estado dele (GET) — o MESMO endereço.
                'ajuste'     => route('trilha.ajuste', [...$empresaId, 'item' => self::MARCADOR_DO_ITEM], false),
                // 4e: mandar a prévia ao cliente (POST) — só na loja que aprova com ele.
                ...(AprovacaoDoCliente::ligada($empresa)
                    ? ['cliente' => route('trilha.cliente', [...$empresaId, 'item' => self::MARCADOR_DO_ITEM], false)]
                    : []),
            ],
            // R4 (04/11/2026): o [Tentar de novo] da "Preparação automática" (POST), com o item no marcador.
            'preparo'       => route('trilha.preparo', [...$empresaId, 'item' => self::MARCADOR_DO_ITEM], false),
        ];
    }

    /**
     * Porta do CLIENTE (`/portal/{empresa}/trilha`): os mesmos itens, SEM o que é só da equipe — nem a chave
     * `pub_produto_id`/`url_publicador` existe, `preparo` vem nulo, o MLB dos anúncios vem nulo, a lista das
     * sugestões vem vazia (fica o total, para o "N sugestões esperando decisão"), as ondas vêm só com id, nome, data e
     * se começou (`ONDA_CLIENTE` — o NOME da onda, que a equipe digita, é visível ao cliente) e nada de escrita. Links só
     * para o próprio Portal.
     *
     * @param  bool  $sessaoEquipe  alguém da ECF abriu a porta do cliente pelo modo equipe do Portal (faixa de aviso)
     */
    public function paraCliente(Company $empresa, array $trilha, bool $sessaoEquipe): array
    {
        $trabalho = (bool) ($trilha['trabalho'] ?? false);

        return [
            'visao'         => 'cliente',
            'sessao_equipe' => $sessaoEquipe,
            'empresa'       => self::empresa($empresa),
            // As rotas do Portal saem com a empresa desta aba (o `portal.auth` põe o `URL::defaults`). Com a trilha de
            // trabalho, NENHUM endereço do Portal sai daqui (a trilha substitui o Portal, decisão do usuário de
            // 03/11/2026; F5 do desenho, D16): nem o "Voltar ao portal" — o `TrilhaLayout` some com o link nulo.
            'voltar'        => $trabalho ? null : ['url' => route('portal.auth.inicio'), 'rotulo' => 'Voltar ao portal'],
            'trabalho'      => $trabalho,
            'pode_editar'   => false,
            'escrita'       => null,
            // 4f (desenho §19.15): a 1ª escrita do cliente — as fotos do produto enquanto ele está na Lista. Só com as duas
            // chaves ({@see CadastroNaTrilha::clienteEscreve()}, a mesma régua da guarda das rotas) e só para o cliente DE
            // VERDADE: no modo equipe do Portal não vem (as rotas também respondem 404 ali). Relativo, com o marcador do
            // produto: o GET do quadro de fotos; o envio e a exclusão estão no mesmo prefixo
            // (`…/produtos/{id}/variacoes/{v}/fotos[/{imagem}]`) e as fotos vêm com a URL pronta no quadro.
            'cliente_escrita' => self::clienteEscrita($empresa, $sessaoEquipe),
            'links'         => [
                'portal_abrir' => null,
                // Com a trilha de trabalho a equipe cadastra e mapeia na trilha: o cliente acompanha, não decide (como
                // no artefato) — os atalhos para o Planejamento e o cadastro de Produtos do Portal saem.
                'planejamento' => $trabalho ? null : route('portal.auth.estrutura.sugestoes'),
                'produtos'     => $trabalho ? null : route('portal.auth.estrutura.produtos'),
            ],
            'produtos'      => array_map(fn (array $p) => array_replace(self::campos($p, self::PRODUTO), [
                'sugestoes_pendentes' => [],
                'mapeado_por'         => null,
                'capa'                => self::capaParaCliente($empresa, $p['capa'] ?? null, $sessaoEquipe),
            ]) + [
                'url_ficha' => isset($p['id']) && ! $trabalho ? route('portal.auth.estrutura.produtos.ficha', ['produto' => (int) $p['id']]) : null,
            ], $trilha['produtos'] ?? []),
            'ofertas'       => array_map(fn (array $o) => array_replace(self::campos($o, self::OFERTA), [
                'componentes' => self::componentes($o['componentes'] ?? []),
                'preparo'     => null,
                'parada'      => null,
                'anuncios'    => self::anuncios($o['anuncios'] ?? [], comMlb: false),
                // O cliente acompanha: não tira ninguém da onda.
                'pode_tirar'  => false,
            ]), $trilha['ofertas'] ?? []),
            // Nome, data e se começou (decisão de 02/11/2026, como no artefato): sem quem criou.
            'ondas'         => array_map(fn (array $o) => self::campos($o, self::ONDA_CLIENTE), $trilha['ondas'] ?? []),
            'gerado_em'     => $trilha['gerado_em'] ?? null,
        ];
    }

    /**
     * Os endereços de escrita da porta do CLIENTE (nula quando não há nenhum):
     * - `fotos` (4f): o quadro de fotos do produto, com o marcador do produto — só o cliente DE VERDADE e com as duas
     *   chaves; no modo equipe do Portal vem nula (as rotas dão 404 ali: não há cliente para ser o ator);
     * - `aprovacao` (4e): `{previa, aprovar, ajuste}`, com o marcador `__ITEM__` — só na loja que aprova com o cliente
     *   ({@see AprovacaoDoCliente}, que já exige as duas chaves). Também no modo equipe: a ECF aprova pelo cliente
     *   (`decidido_por_equipe`). Sem a chave, a chave `aprovacao` nem aparece (a forma da 4f).
     *
     * @return ?array{fotos: ?string, aprovacao?: array{previa: string, aprovar: string, ajuste: string}}
     */
    private static function clienteEscrita(Company $empresa, bool $sessaoEquipe): ?array
    {
        $fotos = ! $sessaoEquipe && CadastroNaTrilha::clienteEscreve($empresa)
            ? route('portal.trilha.fotos.quadro', [PortalContexto::PARAMETRO => (int) $empresa->id, 'produto' => self::MARCADOR_DO_PRODUTO], false)
            : null;
        $aprovacao = null;
        if (AprovacaoDoCliente::ligada($empresa)) {
            $item = [PortalContexto::PARAMETRO => (int) $empresa->id, 'item' => self::MARCADOR_DO_ITEM];
            $aprovacao = [
                'previa'  => route('portal.trilha.aprovacao.previa', $item, false),
                'aprovar' => route('portal.trilha.aprovacao.aprovar', $item, false),
                'ajuste'  => route('portal.trilha.aprovacao.ajuste', $item, false),
            ];
        }
        if ($fotos === null && $aprovacao === null) {
            return null;
        }

        return ['fotos' => $fotos, ...($aprovacao !== null ? ['aprovacao' => $aprovacao] : [])];
    }

    /**
     * Quantos itens em cada etapa, para o cartão da empresa no índice. Produtos contam só na lista e no
     * mapeamento (o produto mapeado segue pelas ofertas); ofertas, do planejamento ao "no ar" — a mesma regra de
     * `contagemPorEtapa()` em `resources/js/lib/trilha.js`, para o cartão e a tela da empresa nunca divergirem.
     *
     * @return array{lista: int, mapa: int, plano: int, robo: int, revisao: int, lanc: int}
     */
    public static function contagem(array $trilha): array
    {
        $n = array_fill_keys([...self::ETAPAS_DE_PRODUTO, ...self::ETAPAS_DE_OFERTA], 0);

        foreach ($trilha['produtos'] ?? [] as $p) {
            if (in_array($p['etapa'] ?? null, self::ETAPAS_DE_PRODUTO, true)) {
                $n[$p['etapa']]++;
            }
        }
        foreach ($trilha['ofertas'] ?? [] as $o) {
            if (in_array($o['etapa'] ?? null, self::ETAPAS_DE_OFERTA, true)) {
                $n[$o['etapa']]++;
            }
        }

        return $n;
    }

    /**
     * A identidade da empresa, igual nas duas portas e no índice.
     *
     * @return array{id: int, nome: string, logo_url: ?string}
     */
    public static function empresa(Company $empresa): array
    {
        $logo = trim((string) $empresa->logo_url);

        return [
            'id'       => (int) $empresa->id,
            'nome'     => (string) $empresa->name,
            'logo_url' => $logo !== '' ? $logo : null,
        ];
    }

    /** Só os campos da lista, na ordem dela; o que o serviço não trouxer sai com o valor padrão. */
    private static function campos(array $item, array $campos): array
    {
        $saida = [];
        foreach ($campos as $campo => $padrao) {
            $saida[$campo] = array_key_exists($campo, $item) ? $item[$campo] : $padrao;
        }

        return $saida;
    }

    /**
     * Os componentes só com os campos da lista {@see self::COMPONENTE}, na ordem dela.
     *
     * @param  list<array<string, mixed>>  $componentes
     * @return list<array{produto_id: ?int, quantidade: int, sku: string, nome: string}>
     */
    private static function componentes(array $componentes): array
    {
        return array_values(array_map(fn (array $c) => self::campos($c, self::COMPONENTE), $componentes));
    }

    /**
     * Os anúncios só com tipo, MLB e situação. Para o cliente o MLB vai nulo: a chave fica (a tela não precisa
     * perguntar se ela existe), o número não.
     *
     * @param  list<array{tipo?: string, mlb?: string, situacao?: ?string}>  $anuncios
     * @return list<array{tipo: ?string, mlb: ?string, situacao: ?string}>
     */
    private static function anuncios(array $anuncios, bool $comMlb): array
    {
        return array_values(array_map(fn (array $a) => [
            'tipo'     => $a['tipo'] ?? null,
            'mlb'      => $comMlb ? ($a['mlb'] ?? null) : null,
            'situacao' => $a['situacao'] ?? null,
        ], $anuncios));
    }

    /**
     * A capa do produto na porta da EQUIPE (R2, 04/11/2026): a miniatura pela rota da equipe (`trilha.fotos`, a mesma da
     * galeria do cadastro), relativa. Quem chama só a pede com `pode_editar`: a rota tem a guarda da escrita (as duas
     * chaves e a régua do Portal da empresa) e devolveria 404/403 — a foto quebrada que motivou o R2.
     *
     * @param  mixed  $capa  `{variacao, imagem}` do serviço
     */
    private static function capaParaEquipe(Company $empresa, mixed $capa): ?string
    {
        [$variacao, $imagem] = self::idsDaCapa($capa);
        if ($variacao === null) {
            return null;
        }

        return route('trilha.fotos', ['company' => (int) $empresa->id, 'variacao' => $variacao, 'imagem' => $imagem], false);
    }

    /**
     * A capa na porta do CLIENTE: a rota de foto DELA (`portal.trilha.fotos.ver`, da 4f), relativa e com a empresa da
     * aba. Só onde essa rota serve: o cliente de verdade com as duas chaves ({@see CadastroNaTrilha::clienteEscreve()}).
     * No modo equipe do Portal a rota é 404 (não há cliente — desenho da 4f), e a coluna volta a mostrar só a contagem.
     *
     * @param  mixed  $capa  `{variacao, imagem}` do serviço
     */
    private static function capaParaCliente(Company $empresa, mixed $capa, bool $sessaoEquipe): ?string
    {
        [$variacao, $imagem] = self::idsDaCapa($capa);
        if ($variacao === null || $sessaoEquipe || ! CadastroNaTrilha::clienteEscreve($empresa)) {
            return null;
        }

        return route('portal.trilha.fotos.ver', [PortalContexto::PARAMETRO => (int) $empresa->id, 'variacao' => $variacao, 'imagem' => $imagem], false);
    }

    /** @return array{0: ?int, 1: ?int} */
    private static function idsDaCapa(mixed $capa): array
    {
        $variacao = is_array($capa) ? (int) ($capa['variacao'] ?? 0) : 0;
        $imagem = is_array($capa) ? (int) ($capa['imagem'] ?? 0) : 0;

        return $variacao > 0 && $imagem > 0 ? [$variacao, $imagem] : [null, null];
    }

}
