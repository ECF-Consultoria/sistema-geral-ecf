<?php

namespace App\Http\Controllers\Trilha;

use App\Models\Company;
use App\Models\User;
use App\Services\Portal\PortalEquipeService;
use App\Services\Publicador\ProgramasPublicadorService;

/**
 * As props da página `Trilha/Empresa` nas duas portas, a partir da saída CRUA de
 * `App\Services\Trilha\TrilhaDaEmpresa::montar()` — e a contagem por etapa do índice da equipe.
 *
 * O serviço não sabe quem está olhando; quem sabe é esta classe. Ela faz duas coisas:
 *
 * 1. **Acrescenta os links** para as telas que já existem (fora o Mapeamento e o Planejamento da trilha de trabalho,
 *    que gravam pelos endereços de `escrita`, toda ação é um link). Cada link
 *    só sai quando quem olha consegue abri-lo — Publicador só para admin (as rotas `mlb/anuncios` são
 *    `role:admin`), "Abrir o Portal" só para quem `PortalEquipeService::podeEntrar()`. Link que daria 403 é pior
 *    que link nenhum.
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
 */
class ApresentacaoDaTrilha
{
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
    ];

    /** As etapas que contam produtos e as que contam ofertas — a mesma divisão de `contagemPorEtapa()` do front. */
    private const ETAPAS_DE_PRODUTO = ['lista', 'mapa'];

    private const ETAPAS_DE_OFERTA = ['plano', 'robo', 'revisao', 'lanc'];

    public function __construct(
        private PortalEquipeService $equipe,
        private ProgramasPublicadorService $programas,
    ) {}

    /**
     * Porta da EQUIPE (`/trilha/{empresa}`): tudo o que o serviço montou, mais os links para o Portal da empresa
     * e para o Publicador. A visão "como o cliente vê" da equipe é alternada no navegador, sem vir ao servidor.
     *
     * @param  array{produtos: list<array>, ofertas: list<array>, ondas: list<array>, gerado_em: string}  $trilha
     */
    public function paraEquipe(Company $empresa, array $trilha, User $usuario): array
    {
        // O mesmo teste do `role:admin` que guarda as rotas do Publicador.
        $admin = $usuario->isAdmin();
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
            'links'         => [
                // Com a trilha de trabalho a trilha SUBSTITUI o Portal (decisão do usuário de 03/11/2026; F5 do desenho,
                // D17): nenhum caminho dela leva ao Portal do cliente. Sem a chave, como na 1ª entrega.
                'portal_abrir' => $podeEntrar && ! $trabalho
                    ? route('companies.portal.abrir', ['company' => $empresa->id])
                    : null,
                'planejamento' => null,
                'produtos'     => null,
                'publicador'   => $admin ? $this->contaNoPublicador($empresa) : null,
            ],
            'produtos'      => array_map(fn (array $p) => self::campos($p, self::PRODUTO) + [
                'url_ficha'        => null,
                'url_publicador'   => $admin ? self::editor($p['pub_produto_id'] ?? null) : null,
                // 3ª entrega (pergunta 1): o item que já andou deixa o cadastro do produto só para leitura na trilha. Só
                // nesta porta — o cliente não cadastra pela trilha e não lê a régua do robô.
                'cadastro_travado' => (bool) ($p['cadastro_travado'] ?? false),
            ], $trilha['produtos'] ?? []),
            'ofertas'       => array_map(fn (array $o) => array_replace(self::campos($o, self::OFERTA), [
                'componentes' => self::componentes($o['componentes'] ?? []),
                'anuncios'    => self::anuncios($o['anuncios'] ?? [], comMlb: true),
            ]) + [
                // Na revisão o que a equipe quer é a prévia do anúncio, não o começo do editor.
                'url_publicador' => $admin ? self::editor($o['pub_produto_id'] ?? null, ($o['etapa'] ?? null) === 'revisao') : null,
            ], $trilha['ofertas'] ?? []),
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
     * relativos também.
     *
     * @return array{ondas: string, planejamento: string, produtos: string, categorias: string, planilha: array{modelo: string, previa: string, gravar: string}, mercado_livre: array{anuncios: string, importar: string, estado: string, limpar: string}}
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
            'links'         => [
                'portal_abrir' => null,
                // Com a trilha de trabalho a equipe cadastra e mapeia na trilha: o cliente acompanha, não decide (como
                // no artefato) — os atalhos para o Planejamento e o cadastro de Produtos do Portal saem.
                'planejamento' => $trabalho ? null : route('portal.auth.estrutura.sugestoes'),
                'produtos'     => $trabalho ? null : route('portal.auth.estrutura.produtos'),
                'publicador'   => null,
            ],
            'produtos'      => array_map(fn (array $p) => array_replace(self::campos($p, self::PRODUTO), [
                'sugestoes_pendentes' => [],
                'mapeado_por'         => null,
            ]) + [
                'url_ficha' => isset($p['id']) && ! $trabalho ? route('portal.auth.estrutura.produtos.ficha', ['produto' => (int) $p['id']]) : null,
            ], $trilha['produtos'] ?? []),
            'ofertas'       => array_map(fn (array $o) => array_replace(self::campos($o, self::OFERTA), [
                'componentes' => self::componentes($o['componentes'] ?? []),
                'preparo'     => null,
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

    /** O editor do Publicador para o pub_produto (na Prévia, quando pedido); null sem pub_produto. */
    private static function editor(mixed $pubProdutoId, bool $previa = false): ?string
    {
        $id = (int) $pubProdutoId;
        if ($id <= 0) {
            return null;
        }

        return route('mlb.anuncios.publicador.editor', ['produto' => $id]).($previa ? '?etapa=previa' : '');
    }

    /**
     * A tela da conta no Publicador. A chave é a do `resolver()` (o mesmo da entrada do Publicador): empresa
     * ligada a um programa (Polos, Incubadora) tem chave canônica `empresa-N`, e a `company-N` só serviria para
     * ser redirecionada até lá.
     */
    private function contaNoPublicador(Company $empresa): ?string
    {
        $chave = $this->programas->resolver('company-'.$empresa->id)['chave'] ?? null;

        return $chave !== null ? route('mlb.anuncios.publicador.produtos', ['conta' => $chave]) : null;
    }
}
