<?php

namespace App\Services\Trilha;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Services\Portal\Estrutura\Produtos\ListasDaEmpresaService;
use App\Services\Portal\Estrutura\Produtos\NormalizadorDeLinha;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Portal\Estrutura\Produtos\ProdutoLinhas;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Services\Portal\PortalEquipeService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Trilha\Ondas\OndasDaEmpresa;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Trilha\TrilhaDeTrabalho;
use App\Support\Trilha\TrilhaLigada;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * O cadastro do produto DENTRO da trilha (3ª entrega, 02/11/2026): a equipe cria e completa o produto na etapa 1, sem
 * abrir o Portal. As tabelas e os serviços são os do Portal (`ProdutoCadastroService`, `ProdutoLinhas`…): só a porta é
 * nova. Esta classe guarda a regra que a porta da trilha acrescenta, testável sem HTTP de verdade:
 *
 * - **a guarda** ({@see empresa()}, {@see alvo()}), na ordem do desenho (§6.1): as duas chaves (404) antes de ler a
 *   empresa, a régua de escrita do Portal dela (403), o recurso do ENDEREÇO preso à empresa (404) antes de validar o
 *   corpo — "não é seu" nunca vira "campo faltando";
 * - **o retrato** ({@see retrato()}): o JSON da gaveta por LISTA DE CAMPOS. O retorno cru dos serviços traz a rota de
 *   imagem do Portal (`/portal/0/…`, foto quebrada no admin) e campo novo de lá nasceria visível; aqui as fotos saem
 *   pela rota da equipe (`trilha.fotos`) e nenhum campo sai sem estar escrito;
 * - **uma requisição, um produto** ({@see conferirLinhas()}): toda linha vai para o produto do caminho; o `id` de
 *   variação de OUTRO produto (mesmo da loja) é 404 antes do serviço — com `id` na linha o serviço acha o produto
 *   pela variação e ignoraria o do caminho;
 * - **a trava** ({@see travado()}): o produto que algum item da trilha já levou adiante fica só para leitura (409);
 * - **o gatilho do Publicador** ({@see prepararSeCabe()}): as escritas gravam com `agendarPreparo: false` e o produto
 *   só vai ao Publicador quando sai da Lista (ou se já está lá);
 * - **o rastro** ({@see registrar()}): um `trilha_cadastro_salvo` por escrita, além dos eventos de sempre dos serviços.
 *
 * ### Por que o produto só vai ao Publicador quando completo (pergunta 2 do desenho)
 * O Sincronizar roda a cada save, mas só aplica a categoria em rascunho SEM categoria e só copia foto para grupo VAZIO
 * (`PortalParaRascunhoService`). Com o cadastro aos poucos, que a trilha incentiva, o rascunho ficaria preso na 1ª
 * categoria e na 1ª foto. Completo, o 1º Sincronizar leva tudo de uma vez; daí em diante, cada gravação vai.
 *
 * ### F1 (esta fatia)
 * Criar (nome + SKU) e editar o básico: nome, título, família, ambientes, descrição e, por variação que JÁ existe,
 * SKU, custo, estoque e caixas. Categoria (F2) e cor/tamanho novos (F4) entram com as conferências deles — até lá as
 * chaves deles saem da linha ({@see CHAVES_DA_LINHA}) e a linha sem `id` é recusada.
 */
class CadastroNaTrilha
{
    public const PASSO_CRIAR = 'criar';

    public const PASSO_LINHAS = 'linhas';

    /**
     * As chaves da linha que a trilha deixa chegar ao serviço; o resto é descartado (`grupo`, `company_id`,
     * `categoria_texto`, `variacao`, `volumes_texto`…), e o `produto_id` é sempre o do caminho. A lista final do
     * desenho (§6.3) acrescenta `categoria_ml_id` (F2: folha, anunciável e não travada) e `eixo`, `valor`, `eixo_2`,
     * `valor_2`, `ordem` (F4, com a conferência das cores) — cada uma junto da conferência dela.
     */
    public const CHAVES_DA_LINHA = [
        'id', 'chave', 'codigo', 'nome', 'titulo', 'familia', 'ambientes', 'volumes', 'custo', 'estoque', 'descricao',
    ];

    public function __construct(
        private PortalEquipeService $equipe,
        private ProdutoCadastroService $cadastro,
        private ProdutoLinhas $linhas,
        private VariacaoImagensService $imagens,
        private FichaTecnicaDoProduto $ficha,
        private ListasDaEmpresaService $listas,
    ) {}

    // ═══ A guarda ═══════════════════════════════════════════════════════════

    /**
     * A empresa do endereço: as duas chaves (404) ANTES de ler a empresa, e a régua que deixa a equipe escrever no
     * Portal dela (`PortalEquipeService::podeEntrar`: admin, ou `core.onboarding` com a empresa na carteira → 403).
     * Liberar o módulo no Controle Dev não abre a escrita a quem não tem a empresa.
     */
    public function empresa(User $quem, int $company): Company
    {
        abort_unless(TrilhaLigada::para($company) && TrilhaDeTrabalho::ligada($company), 404);
        $empresa = Company::query()->findOrFail($company);
        abort_unless($this->equipe->podeEntrar($quem, $empresa), 403, 'Você não tem acesso ao portal desta empresa.');

        return $empresa;
    }

    /**
     * A empresa e o produto do endereço — o de outra loja é 404, igual ao que não existe (dizer "proibido" confirmaria
     * que ele existe em algum lugar).
     *
     * @return array{0: Company, 1: EstruturaProduto}
     */
    public function alvo(User $quem, int $company, int $produto): array
    {
        $empresa = $this->empresa($quem, $company);

        return [$empresa, EstruturaProduto::query()->where('company_id', $empresa->id)->findOrFail($produto)];
    }

    /**
     * Uma requisição, um produto: cada linha recebe o `produto_id` do caminho e só as chaves de
     * {@see CHAVES_DA_LINHA}. O `id` que vier precisa ser uma variação DESTE produto — senão 404 uniforme, antes do
     * serviço (com `id`, o `ProdutoCadastroService` acha o produto pela variação: o id do produto B pelo caminho do A
     * editaria o B). O `id` que o serviço não leria como número (texto, fração) também é 404: lá ele viraria "sem id"
     * e criaria uma variação nova.
     *
     * Nesta fatia (F1) a trilha só edita as variações que o produto já tem: linha sem `id` → 422.
     *
     * @param  array<int, mixed>  $linhas  como o navegador mandou ({@see \App\Support\Portal\Estrutura\RegrasDoCadastro::linhasComoVieram()})
     * @return list<array<string, mixed>>
     */
    public function conferirLinhas(Company $empresa, EstruturaProduto $produto, array $linhas): array
    {
        $doProduto = EstruturaProdutoVariacao::query()
            ->where('company_id', $empresa->id)
            ->where('produto_id', $produto->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $saida = [];
        $semId = null;
        foreach (array_values($linhas) as $i => $bruta) {
            $bruta = is_array($bruta) ? $bruta : [];
            $id = $bruta['id'] ?? null;
            if ($id === null || $id === '') {
                $semId ??= $i;
            } else {
                abort_unless(in_array(self::idDaLinha($id), $doProduto, true), 404);
            }

            $linha = array_intersect_key($bruta, array_flip(self::CHAVES_DA_LINHA));
            $linha['produto_id'] = (int) $produto->id;
            $saida[] = $linha;
        }

        if ($semId !== null) {
            throw ValidationException::withMessages([
                "linhas.{$semId}.id" => 'Por aqui, por enquanto, só se editam as variações que o produto já tem.',
            ]);
        }

        return $saida;
    }

    // ═══ A trava (pergunta 1) ═══════════════════════════════════════════════

    /**
     * O motivo da trava, ou null quando o cadastro está livre. O produto fica TRAVADO quando QUALQUER item da trilha
     * que o contém já andou ({@see TrilhaDaEmpresa::travasDoCadastro()}, a régua que a tela também lê). Lê os itens da
     * empresa inteira com número FIXO de consultas ({@see TrilhaDaEmpresa::itens()}).
     */
    public function travado(Company $empresa, EstruturaProduto $produto): ?string
    {
        $trava = TrilhaDaEmpresa::travasDoCadastro(TrilhaDaEmpresa::itens($empresa))[(int) $produto->id] ?? null;

        return $trava !== null ? self::motivoDaTrava($trava['nome']) : null;
    }

    /**
     * Toda escrita no produto travado responde 409 `{travado: true, motivo}` (e `message`, que a tela já lê nos
     * outros erros) — nada é gravado.
     */
    public function exigirLivre(Company $empresa, EstruturaProduto $produto): void
    {
        $motivo = $this->travado($empresa, $produto);
        if ($motivo !== null) {
            throw new HttpResponseException(response()->json(['travado' => true, 'motivo' => $motivo, 'message' => $motivo], 409));
        }
    }

    // ═══ As escritas ════════════════════════════════════════════════════════

    /**
     * "Criar primeiro" (D3): o produto nasce com o nome e o SKU — UMA linha sem `grupo`, pelo mesmo serviço do Portal
     * (`MODO_GRADE`, sem agendar o Publicador). Nascem o produto, a 1ª variação (sem eixo) e a oferta simples (SKU =
     * Ref); a espera é varrida (o SKU que já tinha anúncio nasce ligado a ele).
     *
     * O SKU repetido (sem caixa nem acento, a régua do serviço) volta como 422 no campo. Dois pedidos ao mesmo tempo (o
     * duplo clique, duas abas) não criam dois: a linha da empresa fica presa durante a gravação, e o 2º pedido lê o SKU
     * do 1º e cai no "repetido".
     *
     * @return array{produto: EstruturaProduto, absorvidos_da_espera: int, avisos: list<string>}
     *
     * @throws ValidationException
     */
    public function criar(Company $empresa, string $nome, string $codigo, AtorDoPortal $ator): array
    {
        $r = DB::transaction(function () use ($empresa, $nome, $codigo, $ator) {
            Company::query()->whereKey($empresa->id)->lockForUpdate()->first();

            return $this->cadastro->gravarLinhas($empresa, [['nome' => $nome, 'codigo' => $codigo]], $ator,
                ProdutoCadastroService::MODO_GRADE, agendarPreparo: false);
        });

        if ($r['erros'] !== []) {
            $erro = $r['erros'][0];
            throw ValidationException::withMessages($erro['campos'] !== [] ? $erro['campos'] : ['codigo' => $erro['mensagem']]);
        }

        $produto = EstruturaProduto::query()->where('company_id', $empresa->id)->findOrFail((int) $r['linhas'][0]['produto_id']);
        $absorvidos = (int) ($r['totais']['absorvidos_da_espera'] ?? 0);
        $this->registrar($ator, $empresa, $produto, self::PASSO_CRIAR, ['codigo' => $codigo, 'absorvidos_da_espera' => $absorvidos]);
        $this->prepararSeCabe($empresa, $produto);

        return ['produto' => $produto, 'absorvidos_da_espera' => $absorvidos, 'avisos' => array_values($r['avisos'])];
    }

    /**
     * As linhas JÁ conferidas ({@see conferirLinhas()}) pelo serviço do Portal, sem agendar o Publicador; depois, se
     * algo mudou, o evento da trilha e o gatilho {@see prepararSeCabe()}. Erro de uma linha não impede as outras: volta
     * em `erros` (um por linha, com a `chave` da tela), com HTTP 200, como no Portal.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return array{erros: list<array>, avisos: list<string>, totais: array<string, int>, criadas_nas_listas: array{familias: list<string>, ambientes: list<string>}}
     */
    public function gravar(Company $empresa, EstruturaProduto $produto, array $linhas, AtorDoPortal $ator): array
    {
        $r = $this->cadastro->gravarLinhas($empresa, $linhas, $ator, ProdutoCadastroService::MODO_GRADE, agendarPreparo: false);

        if ((int) $r['totais']['criadas'] + (int) $r['totais']['atualizadas'] > 0) {
            $this->registrar($ator, $empresa, $produto->fresh() ?? $produto, self::PASSO_LINHAS, ['totais' => $r['totais']]);
            $this->prepararSeCabe($empresa, $produto);
        }

        return [
            'erros'              => $r['erros'],
            'avisos'             => array_values($r['avisos']),
            'totais'             => $r['totais'],
            'criadas_nas_listas' => $r['criadas_nas_listas'],
        ];
    }

    // ═══ O Publicador (pergunta 2) ══════════════════════════════════════════

    /**
     * Depois de CADA escrita: o produto vai ao Publicador (`PreparoIaAgenda::aoSalvar`, com a espera de sempre) só se
     * saiu da Lista (nenhuma falta) ou se já está lá — aí cada gravação vai, como no Portal. Com a trilha de trabalho,
     * a IA fica `aguardando_onda` até a data (`PortaoDaOnda`). Nenhum Job novo: só os que o save do Portal já enfileira.
     *
     * @return bool se agendou
     */
    public function prepararSeCabe(Company $empresa, EstruturaProduto $produto): bool
    {
        if (! $this->jaNoPublicador($empresa, $produto) && TrilhaDaEmpresa::faltasDoProduto($empresa, (int) $produto->id) !== []) {
            return false;
        }

        app(PreparoIaAgenda::class)->aoSalvar((int) $empresa->id, [(int) $produto->id]);

        return true;
    }

    /**
     * O produto já está no Publicador: há `pub_produtos` pelo grupo das cores (`estrutura_produto_id`) ou pela oferta
     * simples de uma das cores dele (o legado por cor, de antes do agrupamento). Uma consulta.
     */
    public function jaNoPublicador(Company $empresa, EstruturaProduto $produto): bool
    {
        $simples = EstruturaOferta::query()
            ->select('id')
            ->where('company_id', $empresa->id)
            ->whereIn('variacao_id', EstruturaProdutoVariacao::query()->select('id')->where('company_id', $empresa->id)->where('produto_id', $produto->id));

        return PubProduto::query()
            ->where(fn ($q) => $q->where('estrutura_produto_id', $produto->id)->orWhereIn('oferta_id', $simples))
            ->exists();
    }

    // ═══ O rastro ═══════════════════════════════════════════════════════════

    /**
     * UM evento por escrita da trilha (`trilha_cadastro_salvo`, `via: trilha`, o `passo`), além dos que os serviços já
     * gravam — é o que diz "veio da trilha" sem coluna nova em `estrutura_produtos` (ALTER em tabela com dado de
     * cliente). Origem `interno`, causer = quem da equipe gravou.
     */
    public function registrar(AtorDoPortal $ator, Company $empresa, EstruturaProduto $produto, string $passo, array $extra = []): void
    {
        RegistroEstrutura::registrar($ator, $empresa, $produto, 'trilha_cadastro_salvo',
            "Cadastro de {$produto->nome} salvo na trilha ({$passo})",
            ['via' => 'trilha', 'passo' => $passo, 'produto_id' => (int) $produto->id, ...$extra]);
    }

    // ═══ O retrato da gaveta ════════════════════════════════════════════════

    /**
     * O JSON da gaveta `e:{id}` (contrato §6.3), campo a campo. Nada aqui chama o Mercado Livre nem grava. Não saem: a
     * capa, o frete estimado e as ofertas/conjuntos do `ProdutoLinhas` (a gaveta lê "Onde está" e "Ofertas" da própria
     * trilha). As fotos saem pela rota da equipe — nenhuma URL `/portal/`. `versao` fica nula até a F6.
     *
     * @return array{produto: array, variacoes: list<array>, ficha: array, listas: array, eixos: array<string, string>, limites: array<string, int>, situacao: array, edicao: array, versao: null}
     */
    public function retrato(Company $empresa, EstruturaProduto $produto): array
    {
        $produto = EstruturaProduto::query()->where('company_id', $empresa->id)->with(['familia', 'ambientes'])->findOrFail($produto->id);
        $linhas = $this->linhas->paraProdutos($empresa, [(int) $produto->id]);
        $galerias = $this->imagens->listarDasVariacoes($empresa, array_map(fn (array $l) => (int) $l['id'], $linhas));
        $donas = self::donasDaCor($linhas, $galerias);
        $primeira = $linhas[0] ?? [];

        $faltas = TrilhaDaEmpresa::faltasDoProduto($empresa, (int) $produto->id);
        $noPublicador = $this->jaNoPublicador($empresa, $produto);
        $trilha = $this->naTrilha($empresa, (int) $produto->id);
        $etapa = match (true) {
            $faltas !== []                                               => 'lista',
            $trilha['mapeado_em'] !== null || $trilha['simples_andou'] => null,
            default                                                      => 'mapa',
        };

        $categoriaId = trim((string) $produto->categoria_ml_id);
        $categoriaNome = trim((string) $produto->categoria_ml_nome);
        $caminho = trim((string) $produto->categoria_ml_caminho);

        return [
            'produto'   => [
                'id'        => (int) $produto->id,
                'nome'      => (string) $produto->nome,
                'titulo'    => $produto->titulo,
                'familia'   => $produto->familia?->nome,
                'ambientes' => $produto->ambientes->pluck('nome')->map(fn ($n) => (string) $n)->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
                'categoria' => $categoriaId === '' && $categoriaNome === '' ? null : [
                    'id'            => $categoriaId !== '' ? $categoriaId : null,
                    'nome'          => $categoriaNome !== '' ? $categoriaNome : null,
                    'caminho_texto' => $caminho !== '' ? $caminho : null,
                    'estado'        => $produto->estadoCategoria(),
                ],
                'descricao' => $produto->descricao,
                'eixo'      => $primeira['eixo'] ?? null,
                'eixo_2'    => $primeira['eixo_2'] ?? null,
                'origem_ml' => ($primeira['origem_ml'] ?? null) !== null,
            ],
            'variacoes' => array_map(fn (array $l) => [
                'id'               => (int) $l['id'],
                'codigo'           => (string) $l['codigo'],
                'valor'            => $l['valor'],
                'valor_2'          => $l['valor_2'],
                'ordem'            => (int) $l['ordem'],
                'custo'            => $l['custo'],
                'estoque'          => $l['estoque'],
                'volumes'          => $l['volumes'],
                // O SKU e o estoque que vêm do Bling: as MESMAS duas travas que o serviço aplica ao gravar (ele
                // ignora o campo e avisa). São independentes — o estoque só trava com a sincronização dele ligada.
                'bling'            => (bool) ($l['bling']['sku_travado'] ?? false),
                'estoque_do_bling' => (bool) ($l['bling']['estoque_travado'] ?? false),
                'dona_da_cor'      => isset($donas[(int) $l['id']]),
                'logistica'        => $l['logistica'],
                'fotos'            => array_map(fn (array $f) => self::foto($empresa, (int) $l['id'], $f), $galerias[(int) $l['id']] ?? []),
            ], $linhas),
            'ficha'     => ['salvos' => $this->ficha->salvos($produto)],
            'listas'    => [
                'familias'  => self::itensDaLista($this->listas->lista($empresa, ListasDaEmpresaService::FAMILIA)),
                'ambientes' => self::itensDaLista($this->listas->lista($empresa, ListasDaEmpresaService::AMBIENTE)),
            ],
            'eixos'     => EstruturaProdutoVariacao::EIXOS,
            'limites'   => [
                'fotos_por_variacao' => VariacaoImagensService::maxPorVariacao(),
                'foto_mb'            => (int) round((int) config('estrutura_produtos.imagens.max_kb', 10240) / 1024),
                'descricao'          => NormalizadorDeLinha::MAX_DESCRICAO,
                'linhas'             => ProdutoCadastroService::MAX_GRADE,
            ],
            'situacao'  => [
                'etapa'             => $etapa,
                'faltas'            => $faltas,
                // F3: o "frete ME1" vira aviso aqui (pergunta 3). Na F1, nenhum.
                'avisos'            => [],
                'no_publicador'     => $noPublicador,
                // D12: com o produto no Publicador a categoria só se troca no editor de lá (a recusa no servidor é da F2).
                'categoria_travada' => $noPublicador,
                'mapeado_em'        => $trilha['mapeado_em'],
            ],
            'edicao'    => [
                'livre'        => $trilha['trava'] === null,
                'motivo'       => $trilha['trava'] !== null ? self::motivoDaTrava($trilha['trava']['nome']) : null,
                'onda_comecou' => $trilha['onda_comecou'],
            ],
            // F6 (versão contra a aba velha).
            'versao'    => null,
        ];
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /**
     * O que a trilha sabe do produto, lido de uma vez: a trava, se a Simples dele andou, a onda já começada de um item
     * dele que ainda não andou (salvar, então, faz o robô preparar com o que estiver aqui) e a marca do mapeamento.
     *
     * @return array{trava: ?array{chave: string, nome: string}, simples_andou: bool, onda_comecou: ?array{id: int, nome: string, data: string}, mapeado_em: ?string}
     */
    private function naTrilha(Company $empresa, int $produtoId): array
    {
        $itens = TrilhaDaEmpresa::itens($empresa);
        $trava = TrilhaDaEmpresa::travasDoCadastro($itens)[$produtoId] ?? null;
        $ondas = OndasDaEmpresa::ler((int) $empresa->id);

        $ondaComecou = null;
        if ($trava === null) {
            foreach ($itens as $chave => $item) {
                if ($item['andou'] || ! in_array($produtoId, $item['produtos'], true)) {
                    continue;
                }
                $onda = $ondas->ondaDoItem((string) $chave);
                if ($onda !== null && $onda->comecou($ondas->hoje)) {
                    $ondaComecou = ['id' => (int) $onda->id, 'nome' => (string) $onda->nome, 'data' => $onda->dia()];
                    break;
                }
            }
        }

        return [
            'trava'         => $trava,
            'simples_andou' => (bool) ($itens["s-{$produtoId}"]['andou'] ?? false),
            'onda_comecou'  => $ondaComecou,
            'mapeado_em'    => $ondas->mapeamento($produtoId)?->concluido_em?->copy()->setTimezone(TrilhaOnda::FUSO)->toIso8601String(),
        ];
    }

    /** O texto da trava, para a gaveta e para o 409. */
    private static function motivoDaTrava(string $nomeDoItem): string
    {
        return "A oferta “{$nomeDoItem}” já saiu do Planejamento (o robô já preparou, está na revisão ou no ar). "
            .'Mudar o cadastro agora faria o robô refazer texto e imagens e não muda o anúncio no ar.';
    }

    /**
     * As variações que guardam as fotos da cor. Com UM eixo (ou nenhum), cada variação tem as suas: todas são donas.
     * Com cor E tamanho, as fotos são da COR e moram numa variação dela — a 1ª que tem foto, senão a 1ª (a régua do
     * `gruposPorCor` da tela e do `comFotosDaCor` do Publicador, com a cor comparada como lá).
     *
     * @param  list<array>  $linhas  saída de `ProdutoLinhas::paraProdutos`, na ordem das variações
     * @param  array<int, list<array>>  $galerias
     * @return array<int, true>
     */
    private static function donasDaCor(array $linhas, array $galerias): array
    {
        $doisEixos = false;
        foreach ($linhas as $l) {
            $doisEixos = $doisEixos || trim((string) ($l['eixo_2'] ?? '')) !== '';
        }

        $donas = [];
        if (! $doisEixos) {
            foreach ($linhas as $l) {
                $donas[(int) $l['id']] = true;
            }

            return $donas;
        }

        $primeira = [];
        $comFoto = [];
        foreach ($linhas as $l) {
            $cor = ChaveCanonica::texto((string) $l['valor']);
            $primeira[$cor] ??= (int) $l['id'];
            if (($galerias[(int) $l['id']] ?? []) !== []) {
                $comFoto[$cor] ??= (int) $l['id'];
            }
        }
        foreach ($primeira as $cor => $id) {
            $donas[$comFoto[$cor] ?? $id] = true;
        }

        return $donas;
    }

    /**
     * A foto pela rota da equipe (a miniatura; `url_original` pede a grande — servida a partir da F3).
     *
     * @return array{id: int, url: string, url_original: string, nome_original: string}
     */
    private static function foto(Company $empresa, int $variacaoId, array $f): array
    {
        $parametros = ['company' => (int) $empresa->id, 'variacao' => $variacaoId, 'imagem' => (int) $f['id']];

        return [
            'id'            => (int) $f['id'],
            'url'           => route('trilha.fotos', $parametros, false),
            'url_original'  => route('trilha.fotos', [...$parametros, 'original' => 1], false),
            'nome_original' => (string) ($f['nome_original'] ?? ''),
        ];
    }

    /**
     * @param  list<array{id: int, nome: string}>  $itens
     * @return list<array{id: int, nome: string}>
     */
    private static function itensDaLista(array $itens): array
    {
        return array_map(fn (array $i) => ['id' => (int) $i['id'], 'nome' => (string) $i['nome']], $itens);
    }

    /** O `id` da linha pela régua do serviço (`NormalizadorDeLinha::inteiro`): inteiro, ou texto de 1 a 18 dígitos. */
    private static function idDaLinha(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id;
        }
        if (is_string($id) && preg_match('/^\d{1,18}$/', trim($id))) {
            return (int) trim($id);
        }

        return null;
    }
}
