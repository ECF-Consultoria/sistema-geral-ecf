<?php

namespace App\Services\Trilha;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoOrigemMl;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\PubProduto;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use App\Services\Portal\Estrutura\Produtos\ExclusaoDeProdutos;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Services\Portal\Estrutura\Produtos\FreteMe2Service;
use App\Services\Portal\Estrutura\Produtos\ListasDaEmpresaService;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Services\Portal\Estrutura\Produtos\NormalizadorDeLinha;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Portal\Estrutura\Produtos\ProdutoLinhas;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Services\Portal\PortalEquipeService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Trilha\Ondas\OndasDaEmpresa;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Portal\CoresDoGrupo;
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
 * ### F1
 * Criar (nome + SKU) e editar o básico: nome, título, família, ambientes, descrição e, por variação que JÁ existe,
 * SKU, custo, estoque e caixas. Cor/tamanho novos (F4) entram com a conferência deles — até lá as chaves deles saem da
 * linha ({@see CHAVES_DA_LINHA}) e a linha sem `id` é recusada.
 *
 * ### F2 (03/11/2026)
 * A categoria do ML entra na linha com a conferência dela ({@see conferirCategoria()}: anunciável e não travada pelo
 * Publicador), e a ficha técnica grava INCOMPLETA ({@see gravarFicha()}: o obrigatório vazio vira a falta "ficha: x"
 * na Lista, em vez de barrar o salvar).
 *
 * ### F3 (03/11/2026)
 * As fotos da variação ({@see enviarFotos()}, {@see excluirFoto()}: a variação e a imagem do ENDEREÇO presas ao
 * produto do caminho — {@see variacao()}, {@see imagem()}) e o "Conferir frete pela conta" ({@see cotarFrete()}). Com
 * a trilha de trabalho o "frete ME1" é AVISO (`situacao.avisos`), não falta: não segura o produto na Lista nem o
 * gatilho do Publicador (pergunta 3, {@see CompletudeDoProduto::avaliar()}).
 *
 * ### F4 (03/11/2026)
 * Cores e tamanhos: a rota das linhas aceita VÁRIAS variações e as NOVAS (sem `id`) do mesmo produto, com eixo, valor,
 * 2º eixo e ordem. A cor nova herda custo e caixas da 1ª variação quando a linha não os traz e ganha a sua oferta
 * simples (é o serviço do Portal que faz). Antes de valer, as variações passam pela régua do Publicador
 * ({@see conferirCores()}, a do `CoresDoGrupo`): o que ela separaria em produto à parte, em silêncio, volta 422 por
 * linha e nada grava. Excluir: a cor/tamanho ({@see excluirVariacao()}; a última variação só sai por "Excluir
 * produto") e o produto inteiro, em dois passos ({@see previaDaExclusao()}, {@see excluirProduto()}).
 *
 * ### F6 (03/11/2026) — a aba velha
 * O retrato traz a `versao` do cadastro ({@see VersaoDoCadastro}); as escritas que mudam conteúdo (linhas, ficha,
 * excluir cor, excluir produto) aceitam a `versao` que a gaveta tinha e, diferente da atual, respondem 409 SEM
 * `travado` ({@see exigirVersao()}) — a tela distingue os dois: o da trava vira só leitura, o da versão pede
 * "Recarregar". Sem `versao` no corpo, segue como antes (compatível com quem não manda).
 *
 * ### 4f (04/11/2026) — as fotos enviadas pelo CLIENTE
 * A 1ª escrita da porta do cliente usa {@see enviarFotos()} e {@see excluirFoto()} como estão, com o ator CLIENTE
 * (`AtorDoPortal::cliente`), atrás da guarda {@see empresaDoCliente()} (as duas chaves; o vínculo já veio do
 * `portal.auth`). O que só vale para o cliente — o quadro de fotos e "só enquanto o produto está na Lista" — mora em
 * {@see FotosDoClienteNaTrilha}.
 */
class CadastroNaTrilha
{
    public const PASSO_CRIAR = 'criar';

    public const PASSO_LINHAS = 'linhas';

    public const PASSO_FICHA = 'ficha';

    public const PASSO_FOTOS = 'fotos';

    public const PASSO_FOTO_EXCLUIDA = 'foto_excluida';

    /** R6 (04/11/2026): a ordem das fotos de uma cor mudou (a 1ª é a capa). */
    public const PASSO_FOTOS_ORDENADAS = 'fotos_ordenadas';

    /** R6: a lista da ordem não é a galeria de agora (outra aba enviou ou excluiu uma foto no meio do caminho). */
    public const MSG_ORDEM_DAS_FOTOS = 'As fotos desta cor mudaram enquanto você arrumava a ordem: confira e arraste de novo.';

    public const PASSO_VARIACAO_EXCLUIDA = 'variacao_excluida';

    public const PASSO_PRODUTO_EXCLUIDO = 'produto_excluido';

    /** F7: o produto gravado pela planilha por categoria ({@see PlanilhaDaTrilha}) — um evento por produto. */
    public const PASSO_PLANILHA = 'planilha';

    /** F8: o produto importado de um anúncio do Mercado Livre ({@see ImportacaoDoMercadoLivre}) — um evento por anúncio, com o `mlb`. */
    public const PASSO_MERCADO_LIVRE = 'mercado_livre';

    /**
     * As chaves da linha que a trilha deixa chegar ao serviço; o resto é descartado (`grupo`, `company_id`,
     * `categoria_texto`, `variacao`, `volumes_texto`…), e o `produto_id` é sempre o do caminho. `categoria_ml_id` (F2)
     * passa por {@see conferirCategoria()}; `eixo`, `valor`, `eixo_2`, `valor_2` e `ordem` (F4), pela conferência das
     * cores ({@see gravar()}).
     */
    public const CHAVES_DA_LINHA = [
        'id', 'chave', 'codigo', 'nome', 'titulo', 'eixo', 'valor', 'eixo_2', 'valor_2', 'ordem',
        'familia', 'ambientes', 'categoria_ml_id', 'volumes', 'custo', 'estoque', 'descricao',
    ];

    /** As chaves que mexem no que o Publicador agrupa (a linha sem `id` também): só com elas há o que conferir. */
    private const CHAVES_DAS_CORES = ['eixo', 'valor', 'eixo_2', 'valor_2', 'ordem'];

    /** O artigo de cada eixo nas mensagens da conferência ("Falta a cor…", "Falta o tamanho…"). */
    private const O_EIXO = [
        'cor' => 'a cor', 'tamanho' => 'o tamanho', 'voltagem' => 'a voltagem', 'material' => 'o material', 'sabor' => 'o sabor',
    ];

    public const MSG_CORES = 'Confira as variações marcadas: nada foi salvo. Com duas ou mais variações, cada uma precisa de '
        .'um nome próprio, sem repetir, e todas do mesmo tipo.';

    /** A conferência caiu só porque uma linha falhou (e a outra, sozinha, deixaria variação sem nome). */
    public const MSG_CORES_LINHA = 'Confira os campos marcados: nada foi salvo — as variações deste pedido só gravam juntas.';

    /** A última variação não sai pela rota da cor: sem ela o produto deixaria de existir — é "Excluir produto". */
    public const MSG_ULTIMA_VARIACAO = 'Esta é a única variação do produto: para tirá-la, use “Excluir produto”.';

    /**
     * A categoria no Publicador só se troca no editor de lá (D12): o rascunho só recebe categoria quando está sem. O texto
     * não cita o Publicador (R10, 04/11/2026: nada na trilha leva a ele) — diz o porquê e a quem pedir.
     */
    public const MSG_CATEGORIA_NO_PUBLICADOR = 'A categoria já foi usada no anúncio em preparo e não muda mais por aqui. '
        .'Se estiver errada, peça a troca a quem cuida da publicação deste produto.';

    public const MSG_CATEGORIA_NAO_ANUNCIAVEL = 'O Mercado Livre não aceita produtos novos nesta categoria; escolha outra.';

    public const MSG_CATEGORIA_INVALIDA = 'Categoria inválida: escolha uma pela busca ou pela navegação.';

    /** O 409 da versão (F6): a gaveta foi aberta antes de outra gravação no mesmo produto. */
    public const MSG_VERSAO = 'Este produto mudou depois que você abriu (outra pessoa salvou nele). '
        .'Recarregue para ver o que mudou.';

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

        return [$empresa, $this->produto($empresa, $produto)];
    }

    /**
     * O produto do ENDEREÇO preso à empresa: o de outra loja é 404, igual ao que não existe (4f: a porta do cliente usa
     * a mesma régua, com a empresa que o `portal.auth` já conferiu).
     */
    public function produto(Company $empresa, int $produto): EstruturaProduto
    {
        return EstruturaProduto::query()->where('company_id', $empresa->id)->findOrFail($produto);
    }

    // ─── A porta do CLIENTE (4ª entrega, 4f, 04/11/2026 — desenho §19.15, D57/D58) ───

    /**
     * O cliente escreve na trilha desta empresa? As MESMAS duas chaves da escrita da equipe ({@see empresa()}):
     * `trilha.empresas` e `trilha.trabalho.empresas`. É a régua do endereço `cliente_escrita` da página do cliente
     * ({@see \App\Http\Controllers\Trilha\ApresentacaoDaTrilha::paraCliente()}) e da guarda {@see empresaDoCliente()}.
     */
    public static function clienteEscreve(Company|int $empresa): bool
    {
        return TrilhaLigada::para($empresa) && TrilhaDeTrabalho::ligada($empresa);
    }

    /**
     * A guarda da porta do CLIENTE (D58): a empresa já vem conferida pelo `portal.auth` (o vínculo do cliente com ela)
     * — aqui só as duas chaves, 404 sem elas (como se a rota não existisse, a mesma resposta da página da trilha fora da
     * chave). Não há régua de carteira: quem escreve é o próprio cliente da loja.
     */
    public function empresaDoCliente(Company $empresa): Company
    {
        abort_unless(self::clienteEscreve($empresa), 404);

        return $empresa;
    }

    /**
     * A variação do ENDEREÇO, presa à empresa E ao produto do caminho: a de outro produto (mesmo da loja) ou de outra
     * loja é 404 — antes de validar o corpo, como o produto ({@see alvo()}).
     */
    public function variacao(Company $empresa, EstruturaProduto $produto, int $variacao): EstruturaProdutoVariacao
    {
        return EstruturaProdutoVariacao::query()
            ->where('company_id', $empresa->id)
            ->where('produto_id', $produto->id)
            ->findOrFail($variacao);
    }

    /** A imagem do ENDEREÇO, presa à empresa E à variação do caminho: a de outra variação ou loja é 404. */
    public function imagem(Company $empresa, EstruturaProdutoVariacao $variacao, int $imagem): EstruturaProdutoVariacaoImagem
    {
        return EstruturaProdutoVariacaoImagem::query()
            ->where('company_id', $empresa->id)
            ->where('variacao_id', $variacao->id)
            ->findOrFail($imagem);
    }

    /**
     * Uma requisição, um produto: cada linha recebe o `produto_id` do caminho e só as chaves de
     * {@see CHAVES_DA_LINHA}. O `id` que vier precisa ser uma variação DESTE produto — senão 404 uniforme, antes do
     * serviço (com `id`, o `ProdutoCadastroService` acha o produto pela variação: o id do produto B pelo caminho do A
     * editaria o B). O `id` que o serviço não leria como número (texto, fração) também é 404: lá ele viraria "sem id"
     * e criaria uma variação nova.
     *
     * A linha SEM `id` (F4) é uma variação nova DESTE produto (o `produto_id` do caminho; o `grupo` não passa).
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
        foreach (array_values($linhas) as $bruta) {
            $bruta = is_array($bruta) ? $bruta : [];
            if (! self::semId($bruta)) {
                abort_unless(in_array(self::idDaLinha($bruta['id']), $doProduto, true), 404);
            }

            $linha = array_intersect_key($bruta, array_flip(self::CHAVES_DA_LINHA));
            $linha['produto_id'] = (int) $produto->id;
            $saida[] = $linha;
        }

        return $saida;
    }

    /**
     * A categoria do ML nas linhas JÁ conferidas ({@see conferirLinhas()}), antes do serviço (F2, desenho §6.3):
     *
     * - `''` (ou só espaço) = não mexi: a chave sai da linha — no normalizador do Portal o texto vazio LIMPARIA a
     *   categoria, e a trilha segue o contrato do resto da linha (`''` não mexe, `null` limpa);
     * - `MLB` + 1..17 dígitos (sem caixa) passa adiante em maiúsculas; qualquer outro texto é 422 — na trilha a
     *   categoria só vem da busca ou da navegação, e o Portal faria dele uma categoria "a confirmar";
     * - a categoria igual à gravada não é conferida de novo (a tela devolve a de sempre em toda linha);
     * - trocar (ou limpar) com o produto no Publicador é 422: lá o rascunho só recebe categoria quando está sem, e a
     *   troca mora no editor dele (D12);
     * - a categoria NOVA precisa ser anunciável (`CategoriaSugestaoService::detalhe`, o mesmo cache de 7 dias da busca):
     *   a folha fechada para anúncio novo é 422. Com o ML fora (`null`), segue como no Portal — o serviço grava "não
     *   validada" e avisa; a que não é folha também segue, e o serviço devolve o erro da linha ("mais específica").
     *
     * Os erros vão no campo da linha (`linhas.{i}.categoria_ml_id`), num 422 só; nada grava.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function conferirCategoria(Company $empresa, EstruturaProduto $produto, array $linhas): array
    {
        $gravada = strtoupper(trim((string) $produto->categoria_ml_id));
        $noPublicador = null;
        $erros = [];
        $novas = []; // id => índice da 1ª linha que a pede

        foreach ($linhas as $i => $linha) {
            if (! array_key_exists('categoria_ml_id', $linha)) {
                continue;
            }
            $bruta = $linha['categoria_ml_id'];
            if (is_string($bruta) && trim($bruta) === '') {
                unset($linhas[$i]['categoria_ml_id']);
                continue;
            }
            if ($bruta !== null && ! (is_string($bruta) && preg_match('/^MLB\d{1,17}$/i', trim($bruta)))) {
                $erros["linhas.{$i}.categoria_ml_id"] = self::MSG_CATEGORIA_INVALIDA;
                continue;
            }

            $id = $bruta === null ? null : strtoupper(trim($bruta));
            $linhas[$i]['categoria_ml_id'] = $id;
            if (($id ?? '') === $gravada) {
                continue;
            }
            if ($noPublicador ??= $this->jaNoPublicador($empresa, $produto)) {
                $erros["linhas.{$i}.categoria_ml_id"] = self::MSG_CATEGORIA_NO_PUBLICADOR;
                continue;
            }
            if ($id !== null) {
                $novas[$id] ??= $i;
            }
        }

        if ($erros === [] && $novas !== []) {
            $categorias = app(CategoriaSugestaoService::class);
            foreach ($novas as $id => $i) {
                $detalhe = $categorias->detalhe((string) $id);
                if ($detalhe !== null && $detalhe['folha'] && ! ($detalhe['anunciavel'] ?? true)) {
                    $erros["linhas.{$i}.categoria_ml_id"] = self::MSG_CATEGORIA_NAO_ANUNCIAVEL;
                }
            }
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        return array_values($linhas);
    }

    // ═══ As cores (F4) ══════════════════════════════════════════════════════

    /**
     * A régua do Publicador sobre as variações de um produto (a do `CoresDoGrupo`, que decide quem vira cor do grupo e
     * quem vira produto à parte, em silêncio): com duas ou mais variações, toda variação tem valor (e o do 2º eixo,
     * quando ele existe); nenhum valor — nem par cor e tamanho — se repete, sem caixa nem acento; e todas usam o mesmo
     * tipo de variação. Função pura: o CONJUNTO das que ficam fora é sempre o do `CoresDoGrupo::separar` (mudou lá,
     * muda aqui sozinho); daqui sai só o motivo, o campo e o texto para a tela — sem "Portal" nem "Publicador": a
     * trilha também é a porta do cliente.
     *
     * @param  list<array{id: int, eixo: ?string, valor: ?string, eixo_2?: ?string, valor_2?: ?string, codigo?: ?string}>  $variacoes  na ordem do produto (`ordem`, `id`)
     * @return array<int, array{motivo: string, campo: string, mensagem: string}>  id da variação → o que está errado nela
     */
    public static function conferirCores(array $variacoes): array
    {
        $fora = CoresDoGrupo::separar($variacoes)['fora'];
        if ($fora === []) {
            return [];
        }

        // O tipo dominante, pela mesma conta do `CoresDoGrupo` (só para dizer qual é o tipo das outras).
        $contagem = [];
        foreach ($variacoes as $v) {
            if (trim((string) $v['valor']) !== '') {
                $tipo = CoresDoGrupo::tipo($v);
                $contagem[$tipo] = ($contagem[$tipo] ?? 0) + 1;
            }
        }
        arsort($contagem);
        $dominante = $contagem === [] ? '' : (string) array_key_first($contagem);

        $saida = [];
        foreach ($variacoes as $v) {
            if (isset($fora[(int) $v['id']])) {
                $saida[(int) $v['id']] = self::motivoDaCor($v, $dominante);
            }
        }

        return $saida;
    }

    /**
     * O motivo, na MESMA ordem das perguntas do `CoresDoGrupo` (sem valor, sem o 2º valor, outro tipo, repetida).
     *
     * @return array{motivo: string, campo: string, mensagem: string}
     */
    private static function motivoDaCor(array $v, string $dominante): array
    {
        $eixo = trim((string) ($v['eixo'] ?? ''));
        $eixo2 = trim((string) ($v['eixo_2'] ?? ''));

        if (trim((string) $v['valor']) === '') {
            return ['motivo' => 'sem_valor', 'campo' => 'valor',
                'mensagem' => 'Falta '.(self::O_EIXO[$eixo] ?? 'o nome').' desta variação: com duas ou mais, cada uma precisa ter o seu.'];
        }
        if ($eixo2 !== '' && trim((string) ($v['valor_2'] ?? '')) === '') {
            return ['motivo' => 'sem_valor_2', 'campo' => 'valor_2', 'mensagem' => 'Falta '.(self::O_EIXO[$eixo2] ?? 'o segundo valor').' desta variação.'];
        }
        $tipo = CoresDoGrupo::tipo($v);
        if ($tipo !== $dominante) {
            [$eixoDominante] = explode('+', $dominante.'+');

            return ['motivo' => 'outro_tipo', 'campo' => ($eixo ?: 'outro') !== $eixoDominante ? 'eixo' : 'eixo_2',
                'mensagem' => 'Esta variação é por '.self::rotuloDoTipo($tipo).', e as outras por '.self::rotuloDoTipo($dominante)
                    .': todas as variações do produto precisam ser do mesmo tipo.'];
        }

        return ['motivo' => 'repetida', 'campo' => $eixo2 !== '' ? 'valor_2' : 'valor',
            'mensagem' => '“'.CoresDoGrupo::valorCompleto($v).'” já existe neste produto: cada variação precisa de um valor diferente.'];
    }

    /** "Cor", ou "Cor e Tamanho" para o par. */
    private static function rotuloDoTipo(string $tipo): string
    {
        return implode(' e ', array_map(fn (string $e) => EstruturaProdutoVariacao::EIXOS[$e] ?? $e, explode('+', $tipo)));
    }

    /**
     * As variações do produto como o Publicador as lê (na ordem `ordem`, `id`), para {@see conferirCores()}.
     *
     * @return list<array{id: int, eixo: ?string, valor: ?string, eixo_2: ?string, valor_2: ?string, codigo: string}>
     */
    private function variacoesParaConferir(Company $empresa, EstruturaProduto $produto): array
    {
        return EstruturaProdutoVariacao::query()
            ->where('company_id', $empresa->id)
            ->where('produto_id', $produto->id)
            ->orderBy('ordem')->orderBy('id')
            ->get(['id', 'eixo', 'valor', 'eixo_2', 'valor_2', 'codigo'])
            ->map(fn (EstruturaProdutoVariacao $v) => ['id' => (int) $v->id, 'eixo' => $v->eixo, 'valor' => $v->valor,
                'eixo_2' => $v->eixo_2, 'valor_2' => $v->valor_2, 'codigo' => (string) $v->codigo])
            ->all();
    }

    /**
     * Dentro da transação do {@see gravar()}: com as variações como FICARAM, a que o Publicador separaria e que não
     * estava separada ANTES do pedido derruba tudo — 422, a transação desfaz. A resposta traz TODAS as variações fora
     * da régua (as de antes também, para a tela mostrar o quadro inteiro), cada uma presa à linha do pedido que a
     * tocou: pelo `id`, ou, na variação nova, pelo SKU (o serviço a criou com o código da linha). A que nenhuma linha
     * tocou vem só com o `variacao_id`; a nova, sem ele (o id dela foi desfeito).
     *
     * Os erros de LINHA do serviço (custo inválido, SKU repetido…) vêm junto, com `motivo: 'linha'` — nada gravou, então
     * todos valem. E a variação cuja linha falhou por um deles não leva também o erro da cor: ela ficou sem nome porque
     * a linha não gravou, e "falta a cor" ao lado da cor que a pessoa digitou só confundiria.
     *
     * Formato (a tela lê `erros` como os `erros` do serviço — `indice`, `chave`, `codigo`, `mensagem`, `campos` — mais
     * `variacao_id` e `motivo`; e `errors`, o do Laravel: `linhas.{i}.{campo}`, ou `variacoes.{id}.{campo}`).
     *
     * @param  list<array>  $antes  {@see variacoesParaConferir()} antes do pedido
     * @param  list<array>  $depois  idem, depois do serviço, na mesma transação
     * @param  list<array<string, mixed>>  $linhas  as linhas do pedido, na ordem em que vieram
     * @param  list<array{indice: int, chave: ?string, codigo: ?string, mensagem: string, campos: array<string, string>}>  $errosDasLinhas  os `erros` do serviço
     *
     * @throws HttpResponseException
     */
    private function recusarCores(array $antes, array $depois, array $linhas, array $errosDasLinhas): void
    {
        $problemas = self::conferirCores($depois);
        $novos = array_diff_key($problemas, self::conferirCores($antes));
        if ($novos === []) {
            return;
        }

        $existiam = array_flip(array_column($antes, 'id'));
        $indicePorId = [];
        $indicePorCodigo = [];
        foreach ($linhas as $i => $l) {
            if (self::semId($l)) {
                $indicePorCodigo[ProdutoCadastroService::chaveCodigo((string) ($l['codigo'] ?? ''))] ??= $i;
            } else {
                $indicePorId[self::idDaLinha($l['id'])] ??= $i;
            }
        }

        $erros = [];
        $porCampo = [];
        $comErro = [];
        foreach ($errosDasLinhas as $e) {
            $i = (int) $e['indice'];
            $comErro[$i] = true;
            $id = self::semId($linhas[$i] ?? []) ? null : self::idDaLinha($linhas[$i]['id']);
            $erros[] = ['indice' => $i, 'chave' => $e['chave'] ?? null, 'variacao_id' => $id, 'codigo' => $e['codigo'] ?? null,
                'motivo' => 'linha', 'mensagem' => (string) $e['mensagem'], 'campos' => $e['campos']];
            foreach ($e['campos'] as $campo => $mensagem) {
                $porCampo["linhas.{$i}.{$campo}"][] = (string) $mensagem;
            }
        }

        foreach ($depois as $v) {
            $id = (int) $v['id'];
            if (! isset($problemas[$id])) {
                continue;
            }
            $existia = isset($existiam[$id]);
            $i = $existia ? ($indicePorId[$id] ?? null) : ($indicePorCodigo[ProdutoCadastroService::chaveCodigo($v['codigo'])] ?? null);
            if ($i !== null && isset($comErro[$i])) {
                continue;
            }
            $chave = $i !== null ? trim((string) (is_scalar($linhas[$i]['chave'] ?? null) ? $linhas[$i]['chave'] : '')) : '';
            ['motivo' => $motivo, 'campo' => $campo, 'mensagem' => $mensagem] = $problemas[$id];

            $erros[] = [
                'indice'      => $i,
                'chave'       => $chave !== '' ? mb_substr($chave, 0, 64) : null,
                'variacao_id' => $existia ? $id : null,
                'codigo'      => $v['codigo'],
                'motivo'      => $motivo,
                'mensagem'    => $mensagem,
                'campos'      => [$campo => $mensagem],
            ];
            $porCampo[$i !== null ? "linhas.{$i}.{$campo}" : "variacoes.{$id}.{$campo}"][] = $mensagem;
        }

        $soDeLinha = array_filter($erros, fn (array $e) => $e['motivo'] !== 'linha') === [];

        throw new HttpResponseException(response()->json([
            'message' => $soDeLinha ? self::MSG_CORES_LINHA : self::MSG_CORES,
            'errors'  => $porCampo,
            'erros'   => $erros,
        ], 422));
    }

    /** O pedido cria variação ou mexe no que o Publicador agrupa (eixo, valor, 2º eixo, ordem)? */
    private static function mexeNasCores(array $linhas): bool
    {
        foreach ($linhas as $l) {
            if (self::semId($l) || array_intersect_key($l, array_flip(self::CHAVES_DAS_CORES)) !== []) {
                return true;
            }
        }

        return false;
    }

    /** A linha é de uma variação nova (sem `id`, ou com ele vazio). */
    private static function semId(array $linha): bool
    {
        $id = $linha['id'] ?? null;

        return $id === null || $id === '';
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
     * A trava de TODOS os produtos da empresa, lida uma vez (F7: a planilha confere cada produto do arquivo pela mesma
     * régua de {@see travado()}, sem reler os itens por produto).
     *
     * @return array<int, string> produto → o texto da trava (o mesmo do 409)
     */
    public function motivosDasTravas(Company $empresa): array
    {
        return array_map(fn (array $t) => self::motivoDaTrava($t['nome']), TrilhaDaEmpresa::travasDoCadastro(TrilhaDaEmpresa::itens($empresa)));
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

    // ═══ A versão (F6) ══════════════════════════════════════════════════════

    /**
     * A aba velha: a `versao` que a gaveta recebeu no retrato, conferida contra a de agora ({@see VersaoDoCadastro}).
     * Diferente → 409 `{versao_atual, message}` e nada grava. SEM a chave `travado`, de propósito: com ela a tela
     * viraria só leitura, e aqui a pessoa só precisa recarregar (learnings §30). Sem versão (nula ou vazia), segue
     * como antes — quem não manda não é conferido.
     *
     * Vem DEPOIS da trava (desenho §6.1): o produto que andou responde a trava, que diz mais.
     */
    public function exigirVersao(Company $empresa, EstruturaProduto $produto, ?string $versao): void
    {
        if ($versao === null || trim($versao) === '') {
            return;
        }

        $atual = VersaoDoCadastro::de($empresa, $produto);
        if (! hash_equals($atual, trim($versao))) {
            throw new HttpResponseException(response()->json(['versao_atual' => $atual, 'message' => self::MSG_VERSAO], 409));
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
     * `$efeitos` (F7, aditivo): com `false`, nem o evento da trilha nem o gatilho do Publicador — a planilha os faz uma
     * vez por produto no fim ({@see PlanilhaDaTrilha}), e a prévia não faz nenhum dos dois.
     *
     * @return array{produto: EstruturaProduto, absorvidos_da_espera: int, avisos: list<string>}
     *
     * @throws ValidationException
     */
    public function criar(Company $empresa, string $nome, string $codigo, AtorDoPortal $ator, bool $efeitos = true): array
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
        if ($efeitos) {
            $this->registrar($ator, $empresa, $produto, self::PASSO_CRIAR, ['codigo' => $codigo, 'absorvidos_da_espera' => $absorvidos]);
            $this->prepararSeCabe($empresa, $produto);
        }

        return ['produto' => $produto, 'absorvidos_da_espera' => $absorvidos, 'avisos' => array_values($r['avisos'])];
    }

    /**
     * As linhas JÁ conferidas ({@see conferirLinhas()}) pelo serviço do Portal, sem agendar o Publicador; depois, se
     * algo mudou, o evento da trilha e o gatilho {@see prepararSeCabe()}. Erro de uma linha não impede as outras: volta
     * em `erros` (um por linha, com a `chave` da tela), com HTTP 200, como no Portal.
     *
     * F4 — as cores: o pedido que cria variação ou mexe em eixo, valor, 2º eixo ou ordem grava numa transação e, ANTES
     * de ela valer, as variações do produto como FICARAM passam pela régua do Publicador ({@see conferirCores()}). A
     * variação que ele separaria em produto à parte e que não estava separada antes do pedido desfaz tudo: 422 com o
     * erro por linha ({@see recusarCores()}). Conferir o resultado (e não simular o pedido) pega o que o serviço faz por
     * conta própria: a variação nova herda o eixo da 1ª, a linha que falha não grava e a de depois grava — a 1ª cor que
     * falhasse por outro motivo (título longo, SKU repetido) deixaria a variação de antes sem nome ao lado da nova. O
     * problema que o produto já tinha (cadastrado antes por outra porta) não trava o salvar de outra coisa.
     *
     * `$efeitos` (F7, aditivo): com `false`, nem o evento da trilha nem o gatilho do Publicador (ver {@see criar()}).
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return array{erros: list<array>, avisos: list<string>, totais: array<string, int>, criadas_nas_listas: array{familias: list<string>, ambientes: list<string>}}
     *
     * @throws HttpResponseException 422 da conferência das cores (nada gravado)
     */
    public function gravar(Company $empresa, EstruturaProduto $produto, array $linhas, AtorDoPortal $ator, bool $efeitos = true): array
    {
        if (! self::mexeNasCores($linhas)) {
            $r = $this->cadastro->gravarLinhas($empresa, $linhas, $ator, ProdutoCadastroService::MODO_GRADE, agendarPreparo: false);
        } else {
            $antes = $this->variacoesParaConferir($empresa, $produto);
            $r = DB::transaction(function () use ($empresa, $produto, $linhas, $ator, $antes) {
                $r = $this->cadastro->gravarLinhas($empresa, $linhas, $ator, ProdutoCadastroService::MODO_GRADE, agendarPreparo: false);
                $this->recusarCores($antes, $this->variacoesParaConferir($empresa, $produto), $linhas, $r['erros']);

                return $r;
            });
        }

        if ($efeitos && (int) $r['totais']['criadas'] + (int) $r['totais']['atualizadas'] > 0) {
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

    /**
     * A ficha técnica INTEIRA do estado da gaveta, pelo serviço do Portal com `exigirObrigatorios: false` (F2): o
     * obrigatório vazio grava e vira a falta "ficha: x" na Lista — a equipe cadastra aos poucos. O resto é o do
     * Portal: gravar SUBSTITUI (o Modelo antigo fica), o id de eixo do produto e o desconhecido são ignorados, valor
     * inválido é 422 por campo (`atributos.{ID}`) sem gravar nada, sem categoria é 422 SEM chamar o ML, e o catálogo
     * fora é 422 antes de apagar qualquer linha.
     *
     * `$categoriaDaTela` (opcional): a categoria que a gaveta tinha aberta ao montar a ficha. Diferente da gravada (o
     * "Salvar" das linhas não levou a troca), é 422 em `categoria` — gravar a ficha de uma categoria por cima da outra
     * apagaria os campos da que está gravada.
     *
     * Depois de gravar: o evento da trilha (passo `ficha`) e o gatilho {@see prepararSeCabe()}.
     *
     * @param  array<int, mixed>  $atributos  [{id, valor, unidade, nao_se_aplica, proprio, proprios}, ...] como a tela mandou
     * @return list<array{id: string, nome: string, valor: ?string, valor_id: ?string, unidade: ?string}> o que ficou salvo
     *
     * @throws ValidationException
     */
    public function gravarFicha(Company $empresa, EstruturaProduto $produto, array $atributos, ?string $categoriaDaTela, AtorDoPortal $ator): array
    {
        $gravada = strtoupper(trim((string) $produto->categoria_ml_id));
        if ($gravada !== '' && filled($categoriaDaTela) && strtoupper(trim((string) $categoriaDaTela)) !== $gravada) {
            throw ValidationException::withMessages(['categoria' => 'A categoria mudou: salve o produto antes de gravar a ficha.']);
        }

        $salvos = $this->ficha->gravar($empresa, $produto, $atributos, $ator, exigirObrigatorios: false);

        $this->registrar($ator, $empresa, $produto, self::PASSO_FICHA, ['campos' => count($salvos)]);
        $this->prepararSeCabe($empresa, $produto);

        return array_values($salvos);
    }

    /**
     * As fotos no fim da galeria da variação, pelo serviço do Portal (`VariacaoImagensService::enviar`): disco privado,
     * TUDO OU NADA (a remessa que não cabe inteira no teto da variação é 422 em `imagens` e nada fica), a variação
     * presa durante a gravação (dois envios ao mesmo tempo não furam o teto). Depois: o evento da trilha (passo `fotos`)
     * e o gatilho {@see prepararSeCabe()} — a foto que completa o produto o leva ao Publicador, uma vez.
     *
     * @param  list<\Illuminate\Http\UploadedFile>  $arquivos  já validados pela forma ({@see \App\Support\Portal\Estrutura\RegrasDoCadastro::imagens()})
     *
     * @throws ValidationException
     */
    public function enviarFotos(Company $empresa, EstruturaProduto $produto, EstruturaProdutoVariacao $variacao, array $arquivos, AtorDoPortal $ator): void
    {
        $this->imagens->enviar($empresa, $variacao, $arquivos, $ator);

        $this->registrar($ator, $empresa, $produto, self::PASSO_FOTOS, ['variacao_id' => (int) $variacao->id, 'quantidade' => count($arquivos)]);
        $this->prepararSeCabe($empresa, $produto);
    }

    /**
     * Tira a foto da galeria, pelo serviço do Portal (`VariacaoImagensService::excluir`): as que sobram são renumeradas
     * (a 1ª vira capa) e o arquivo sai do disco DEPOIS do commit. Depois: o evento (passo `foto_excluida`) e o gatilho
     * {@see prepararSeCabe()} (o produto que já está no Publicador segue recebendo cada gravação).
     */
    public function excluirFoto(Company $empresa, EstruturaProduto $produto, EstruturaProdutoVariacao $variacao, EstruturaProdutoVariacaoImagem $imagem, AtorDoPortal $ator): void
    {
        $id = (int) $imagem->id;
        $this->imagens->excluir($empresa, $variacao, $imagem, $ator);

        $this->registrar($ator, $empresa, $produto, self::PASSO_FOTO_EXCLUIDA, ['variacao_id' => (int) $variacao->id, 'imagem_id' => $id]);
        $this->prepararSeCabe($empresa, $produto);
    }

    /**
     * R6 (04/11/2026) — a ordem das fotos da cor, pelo serviço do Portal (`VariacaoImagensService::reordenar`, que
     * renumera 0..N-1; a 1ª é a capa). Diferente do Portal (que ignora o id de fora e põe o esquecido no fim), aqui a
     * lista tem de ser EXATAMENTE a galeria de agora: a conferência e a regravação correm com a variação presa (a mesma
     * trava do envio), e a foto que outra aba enviou ou excluiu no meio do caminho é 422 em `ordem` — nada muda.
     *
     * A mesma ordem de antes não grava nem registra nada (devolve false). Mudou: o evento (passo `fotos_ordenadas`) e o
     * gatilho {@see prepararSeCabe()}, como o Portal faz ao reordenar.
     *
     * @param  list<int>  $ordem  os ids das fotos da variação, na ordem desejada
     * @return bool se a ordem mudou
     *
     * @throws ValidationException
     */
    public function ordenarFotos(Company $empresa, EstruturaProduto $produto, EstruturaProdutoVariacao $variacao, array $ordem, AtorDoPortal $ator): bool
    {
        $ordem = array_map('intval', array_values($ordem));

        $mudou = DB::transaction(function () use ($empresa, $variacao, $ordem, $ator) {
            EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->whereKey($variacao->id)->lockForUpdate()->first();

            $atual = EstruturaProdutoVariacaoImagem::query()
                ->where('company_id', $empresa->id)->where('variacao_id', $variacao->id)
                ->orderBy('ordem')->orderBy('id')
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            $pedidos = $ordem;
            $existentes = $atual;
            sort($pedidos);
            sort($existentes);
            if ($pedidos !== $existentes) {
                throw ValidationException::withMessages(['ordem' => self::MSG_ORDEM_DAS_FOTOS]);
            }
            if ($ordem === $atual) {
                return false;
            }

            $this->imagens->reordenar($empresa, $variacao, $ordem, $ator);

            return true;
        });

        if ($mudou) {
            $this->registrar($ator, $empresa, $produto, self::PASSO_FOTOS_ORDENADAS, ['variacao_id' => (int) $variacao->id, 'ordem' => $ordem]);
            $this->prepararSeCabe($empresa, $produto);
        }

        return $mudou;
    }

    // ═══ Excluir (F4) ═══════════════════════════════════════════════════════

    /**
     * Tira UMA cor ou tamanho do produto, pelo serviço do Portal (`ProdutoCadastroService::excluirVariacao`, a regra da
     * Lista SKUs): a peça de combo ou kit é recusada (422 em `oferta`, com o texto do serviço) e nada muda; os anúncios
     * da oferta dela voltam para a espera; com cor E tamanho, a galeria da cor passa para outro tamanho dela quando era
     * este que a guardava.
     *
     * A ÚLTIMA variação não sai por aqui (422 em `variacao`): no serviço ela leva o produto junto, e isso é o "Excluir
     * produto", em dois passos ({@see excluirProduto()}). A linha do produto fica presa durante a conta e a exclusão:
     * duas abas tirando as duas últimas cores ao mesmo tempo não apagam o produto.
     *
     * Não agenda o Publicador, como o Portal (exclusão não chama o `aoSalvar`). Depois: o evento (passo
     * `variacao_excluida`).
     *
     * @return array{produto_excluido: bool, anuncios_para_espera: int, sku: ?string}
     *
     * @throws ValidationException
     */
    public function excluirVariacao(Company $empresa, EstruturaProduto $produto, EstruturaProdutoVariacao $variacao, AtorDoPortal $ator): array
    {
        $r = DB::transaction(function () use ($empresa, $produto, $variacao, $ator) {
            EstruturaProduto::query()->whereKey($produto->id)->lockForUpdate()->first();
            $quantas = EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->where('produto_id', $produto->id)->count();
            if ($quantas <= 1) {
                throw ValidationException::withMessages(['variacao' => self::MSG_ULTIMA_VARIACAO]);
            }

            return $this->cadastro->excluirVariacao($empresa, (int) $variacao->id, $ator);
        });

        $this->registrar($ator, $empresa, $produto, self::PASSO_VARIACAO_EXCLUIDA, [
            'variacao_id' => (int) $variacao->id, 'sku' => $r['sku'], 'anuncios_para_espera' => (int) $r['anuncios_para_espera'],
        ]);

        return $r;
    }

    /**
     * O 1º passo do "Excluir produto": o que sai junto, sem tocar em nada (`ExclusaoDeProdutos::previa` do produto do
     * caminho) — as variações e as ofertas montadas que o usam (combos, kits, combits), cada uma com `em_uso`.
     *
     * @return array{produtos: list<array>, montadas: list<array{id: int, sku: string, nome: ?string, fase: string, em_uso: bool}>, totais: array<string, int>, nao_encontrados: int}
     */
    public function previaDaExclusao(Company $empresa, EstruturaProduto $produto): array
    {
        return app(ExclusaoDeProdutos::class)->previa($empresa, [(int) $produto->id]);
    }

    /**
     * O 2º passo: exclui o produto e as ofertas montadas que o usam, só se `$montadasConfirmadas` são exatamente as que a
     * prévia mostrou (apareceu outra no meio do caminho: 422 em `montadas`, nada sai). Tudo numa transação
     * (`ExclusaoDeProdutos::excluir`). O que a trilha tinha dele sai junto: `trilha_onda_itens` e `trilha_mapeamentos`
     * pela cascata do banco (FK no produto e na oferta), e o Combo apagado sai da onda pelo `TrilhaComboExcluidoObserver`,
     * na mesma transação. Depois: o evento (passo `produto_excluido`). Não agenda nada.
     *
     * @param  array<int, int|string>  $montadasConfirmadas
     * @return array{produtos: int, variacoes: int, montadas: int, ids: list<int>, nao_encontrados: int}
     *
     * @throws ValidationException
     */
    public function excluirProduto(Company $empresa, EstruturaProduto $produto, array $montadasConfirmadas, AtorDoPortal $ator): array
    {
        $r = app(ExclusaoDeProdutos::class)->excluir($empresa, [(int) $produto->id], $montadasConfirmadas, $ator);

        $this->registrar($ator, $empresa, $produto, self::PASSO_PRODUTO_EXCLUIDO, [
            'codigo' => $produto->codigo, 'variacoes' => (int) $r['variacoes'], 'montadas' => (int) $r['montadas'],
        ]);

        return $r;
    }

    // ═══ O frete (rota 13) ══════════════════════════════════════════════════

    /**
     * "Conferir frete pela conta": as variações do produto pelo `FreteMe2Service::cotar`, montadas como na tela de
     * Produtos do Portal (`cotarFretes`). É o único ponto do cadastro que usa a conta da LOJA, e só lê (GET da
     * preferência de envio e das cotações, com o token dela); sem conta conectada, a estimativa da tabela, sem nenhuma
     * requisição. O que fica é a modalidade de envio da conta em cache (`ModalidadeDeEnvio`,
     * `frete.modalidade_cache_horas`): a régua da Lista e a logística do retrato passam a usar os limites da conta, e o
     * ME1 que era só dos limites dos Correios some. Nada é gravado no banco.
     *
     * @return array{conectado: bool, falhou: bool, fora_do_me2: string}
     */
    public function cotarFrete(Company $empresa, EstruturaProduto $produto): array
    {
        $variacoes = EstruturaProdutoVariacao::query()
            ->where('company_id', $empresa->id)
            ->where('produto_id', $produto->id)
            ->with('volumes')
            ->orderBy('ordem')->orderBy('id')
            ->get();

        $itens = [];
        foreach ($variacoes as $v) {
            $volumes = $v->volumes
                ->map(fn ($x) => ['c' => (float) $x->comprimento, 'l' => (float) $x->largura, 'a' => (float) $x->altura, 'kg' => (float) $x->peso])
                ->values()->all();
            $log = LogisticaProduto::daVolumes($volumes);

            $itens[(int) $v->id] = [
                'pacote'        => $log['pacote'],
                'peso_faturado' => $log['peso_faturado'],
                'logistica'     => $log['logistica'],
                'custo'         => $v->custo,
            ];
        }

        $r = app(FreteMe2Service::class)->cotar($empresa, $itens);

        return [
            'conectado'   => (bool) $r['conectado'],
            'falhou'      => (bool) $r['falhou'],
            // Para onde vai o que passa do Mercado Envios da conta: a transportadora (ME1), "Combine a entrega" (a conta
            // sem ME1) ou ainda não se sabe (a preferência não foi lida).
            'fora_do_me2' => ModalidadeDeEnvio::destinoFora(ModalidadeDeEnvio::me1EmCache($empresa)),
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
    /**
     * Na empresa da trilha de trabalho, os produtos da lista que PODEM ir ao Publicador: os completos (faltas vazias) e
     * os que ele já conhece — a mesma régua do {@see prepararSeCabe()}, para os caminhos de save do PORTAL (a tela, a
     * gravação de linhas e a planilha, a oferta criada, as fichas em lote, a ficha vinda do ML). Sem isso, o Portal —
     * aonde a própria gaveta da F1 mandava a equipe para categoria e fotos — levava ao Publicador o produto ainda
     * incompleto, e o rascunho ficava preso na 1ª categoria e nas 1ªs fotos (revisão da F1, 03/11/2026).
     *
     * Fora da chave, devolve a lista como veio. A soltura da onda não passa por aqui (ela pede de propósito). Erro ao
     * ler deixa passar, como era antes.
     *
     * @param  iterable<int|string>  $produtoIds
     * @return list<int>
     */
    public static function soOsQueCabem(int $empresaId, iterable $produtoIds): array
    {
        $ids = [];
        foreach ($produtoIds as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }
        if ($ids === [] || ! TrilhaDeTrabalho::ligada($empresaId)) {
            return array_values($ids);
        }

        try {
            $empresa = Company::query()->find($empresaId);
            if ($empresa === null) {
                return array_values($ids);
            }
            $servico = app(self::class);
            $produtos = EstruturaProduto::query()->where('company_id', $empresaId)->whereIn('id', $ids)->get()->keyBy('id');

            return array_values(array_filter($ids, fn (int $id) => ! $produtos->has($id)
                || $servico->jaNoPublicador($empresa, $produtos->get($id))
                || TrilhaDaEmpresa::faltasDoProduto($empresa, $id) === []));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[Trilha] Não deu para conferir se os produtos da empresa {$empresaId} cabem no Publicador; seguem como antes. ".$e->getMessage());

            return array_values($ids);
        }
    }

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
     * trilha). As fotos saem pela rota da equipe — nenhuma URL `/portal/`. `versao` (F6) é a do cadastro AGORA
     * ({@see VersaoDoCadastro}): a gaveta a devolve nas escritas que mudam conteúdo.
     *
     * @return array{produto: array, variacoes: list<array>, ficha: array, listas: array, eixos: array<string, string>, limites: array<string, int>, situacao: array, edicao: array, versao: string}
     */
    public function retrato(Company $empresa, EstruturaProduto $produto): array
    {
        $produto = EstruturaProduto::query()->where('company_id', $empresa->id)->with(['familia', 'ambientes'])->findOrFail($produto->id);
        $linhas = $this->linhas->paraProdutos($empresa, [(int) $produto->id]);
        $galerias = $this->imagens->listarDasVariacoes($empresa, array_map(fn (array $l) => (int) $l['id'], $linhas));
        $donas = self::donasDaCor($linhas, $galerias);
        $primeira = $linhas[0] ?? [];

        ['faltas' => $faltas, 'avisos' => $avisos] = TrilhaDaEmpresa::cadastroDoProduto($empresa, (int) $produto->id);
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
                // F8 (D41): o título do anúncio de onde o produto veio, só como referência para a equipe comparar com o do
                // agente. Nulo no produto que não veio do ML (esse não gasta a consulta).
                'titulo_do_anuncio' => ($primeira['origem_ml'] ?? null) !== null ? $this->tituloDoAnuncio($empresa, $produto) : null,
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
                // F3 (pergunta 3): o que não segura o produto na Lista — o "frete ME1" vira o aviso
                // `CompletudeDoProduto::AVISO_ME1` ("vai por ME1 (transportadora)").
                'avisos'            => $avisos,
                'no_publicador'     => $noPublicador,
                // D12: com o produto no Publicador a categoria só se troca no editor de lá (o servidor recusa a troca
                // em `conferirCategoria`).
                'categoria_travada' => $noPublicador,
                'mapeado_em'        => $trilha['mapeado_em'],
            ],
            'edicao'    => [
                'livre'        => $trilha['trava'] === null,
                'motivo'       => $trilha['trava'] !== null ? self::motivoDaTrava($trilha['trava']['nome']) : null,
                'onda_comecou' => $trilha['onda_comecou'],
            ],
            // F6 (versão contra a aba velha): sem o estoque nem as fotos.
            'versao'    => VersaoDoCadastro::de($empresa, $produto),
        ];
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /** F8 (D41): o título do anúncio original (o do retrato da origem; sem ele, a coluna), ou null. Uma consulta. */
    private function tituloDoAnuncio(Company $empresa, EstruturaProduto $produto): ?string
    {
        $origem = EstruturaProdutoOrigemMl::query()->where('company_id', $empresa->id)->where('produto_id', $produto->id)
            ->orderBy('id')->first(['titulo_original', 'retrato']);
        $titulo = trim((string) (($origem?->retrato['titulo'] ?? null) ?: $origem?->titulo_original));

        return $titulo !== '' ? $titulo : null;
    }

    /**
     * O que a trilha sabe do produto, lido de uma vez: a trava, se a Simples dele andou, a onda já começada de um item
     * dele que ainda não andou (salvar, então, faz o agente preparar com o que estiver aqui) e a marca do mapeamento.
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
        return "A oferta “{$nomeDoItem}” já saiu do Planejamento (o agente já preparou, está na revisão ou no ar). "
            .'Mudar o cadastro agora faria o agente refazer texto e imagens e não muda o anúncio no ar.';
    }

    /**
     * As variações que guardam as fotos da cor. Com UM eixo (ou nenhum), cada variação tem as suas: todas são donas.
     * Com cor E tamanho, as fotos são da COR e moram numa variação dela — a 1ª que tem foto, senão a 1ª (a régua do
     * `gruposPorCor` da tela e do `comFotosDaCor` do Publicador, com a cor comparada como lá).
     *
     * Pública desde a 4f (04/11/2026): o quadro de fotos do CLIENTE ({@see FotosDoClienteNaTrilha::quadro()}) escolhe a
     * variação de cada cor por aqui — a mesma régua da gaveta da equipe, num lugar só.
     *
     * @param  list<array>  $linhas  na ordem das variações, com `id`, `valor` e `eixo_2` (a saída de `ProdutoLinhas::paraProdutos` serve)
     * @param  array<int, list<array>>  $galerias
     * @return array<int, true>
     */
    public static function donasDaCor(array $linhas, array $galerias): array
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
     * A foto pela rota da equipe: `url` é a miniatura; `url_original` (`?original=1`, F3) é o arquivo enviado, para o
     * "Ver maior".
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
