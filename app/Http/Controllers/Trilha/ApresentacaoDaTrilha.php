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
 * 1. **Acrescenta os links** para as telas que já existem (a trilha é só leitura: toda ação é um link). Cada link
 *    só sai quando quem olha consegue abri-lo — Publicador só para admin (as rotas `mlb/anuncios` são
 *    `role:admin`), "Abrir o Portal" só para quem `PortalEquipeService::podeEntrar()`. Link que daria 403 é pior
 *    que link nenhum.
 * 2. **Tira o que é só da equipe** na porta do cliente: o id e o link do Publicador, o preparo do agente, o MLB
 *    dos anúncios e a lista das sugestões (fica só o total). O cliente não vê o Publicador nem os bastidores do
 *    agente; o MLB, além disso, é o elo com o Mercado Livre que não se expõe por lá.
 *
 * ### Por que LISTA de campos, e não "tudo menos"
 * Os campos que saem estão escritos aqui, um a um ({@see self::PRODUTO}, {@see self::OFERTA}). Um campo novo que o
 * serviço passe a devolver NÃO chega à tela — nem à do cliente — até alguém acrescentá-lo aqui. É a mesma lógica da
 * allowlist do `RestringeDominioDoPortal`: o padrão seguro é o campo novo nascer invisível, e expô-lo ao cliente
 * vira uma decisão consciente, não um efeito colateral de mexer no serviço.
 */
class ApresentacaoDaTrilha
{
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
        'etapa'                     => null,
        'sugestoes_pendentes'       => [],
        'sugestoes_pendentes_total' => 0,
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

        return [
            'visao'         => 'equipe',
            'sessao_equipe' => false,
            'empresa'       => self::empresa($empresa),
            'voltar'        => ['url' => route('trilha.index'), 'rotulo' => 'Empresas'],
            'links'         => [
                'portal_abrir' => $this->equipe->podeEntrar($usuario, $empresa)
                    ? route('companies.portal.abrir', ['company' => $empresa->id])
                    : null,
                'planejamento' => null,
                'produtos'     => null,
                'publicador'   => $admin ? $this->contaNoPublicador($empresa) : null,
            ],
            'produtos'      => array_map(fn (array $p) => self::campos($p, self::PRODUTO) + [
                'url_ficha'      => null,
                'url_publicador' => $admin ? self::editor($p['pub_produto_id'] ?? null) : null,
            ], $trilha['produtos'] ?? []),
            'ofertas'       => array_map(fn (array $o) => array_replace(self::campos($o, self::OFERTA), [
                'anuncios' => self::anuncios($o['anuncios'] ?? [], comMlb: true),
            ]) + [
                // Na revisão o que a equipe quer é a prévia do anúncio, não o começo do editor.
                'url_publicador' => $admin ? self::editor($o['pub_produto_id'] ?? null, ($o['etapa'] ?? null) === 'revisao') : null,
            ], $trilha['ofertas'] ?? []),
            'ondas'         => $trilha['ondas'] ?? [],
            'gerado_em'     => $trilha['gerado_em'] ?? null,
        ];
    }

    /**
     * Porta do CLIENTE (`/portal/{empresa}/trilha`): os mesmos itens, SEM o que é só da equipe — nem a chave
     * `pub_produto_id`/`url_publicador` existe, `preparo` vem nulo, o MLB dos anúncios vem nulo e a lista das
     * sugestões vem vazia (fica o total, para o "N sugestões esperando decisão"). Links só para o próprio Portal.
     *
     * @param  bool  $sessaoEquipe  alguém da ECF abriu a porta do cliente pelo modo equipe do Portal (faixa de aviso)
     */
    public function paraCliente(Company $empresa, array $trilha, bool $sessaoEquipe): array
    {
        return [
            'visao'         => 'cliente',
            'sessao_equipe' => $sessaoEquipe,
            'empresa'       => self::empresa($empresa),
            // As rotas do Portal saem com a empresa desta aba (o `portal.auth` põe o `URL::defaults`).
            'voltar'        => ['url' => route('portal.auth.inicio'), 'rotulo' => 'Voltar ao portal'],
            'links'         => [
                'portal_abrir' => null,
                'planejamento' => route('portal.auth.estrutura.sugestoes'),
                'produtos'     => route('portal.auth.estrutura.produtos'),
                'publicador'   => null,
            ],
            'produtos'      => array_map(fn (array $p) => array_replace(self::campos($p, self::PRODUTO), [
                'sugestoes_pendentes' => [],
            ]) + [
                'url_ficha' => isset($p['id']) ? route('portal.auth.estrutura.produtos.ficha', ['produto' => (int) $p['id']]) : null,
            ], $trilha['produtos'] ?? []),
            'ofertas'       => array_map(fn (array $o) => array_replace(self::campos($o, self::OFERTA), [
                'preparo'  => null,
                'anuncios' => self::anuncios($o['anuncios'] ?? [], comMlb: false),
            ]), $trilha['ofertas'] ?? []),
            'ondas'         => $trilha['ondas'] ?? [],
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
