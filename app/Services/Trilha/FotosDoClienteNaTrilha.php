<?php

namespace App\Services\Trilha;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * As fotos que o CLIENTE envia pela trilha (4ª entrega, fatia 4f, 04/11/2026 — desenho §19.15, D57/D58, R9): a 1ª
 * escrita na porta dele. Enviar e excluir são os MESMOS da equipe ({@see CadastroNaTrilha::enviarFotos()},
 * {@see CadastroNaTrilha::excluirFoto()}: disco privado, tudo ou nada, o teto por variação, o evento
 * `trilha_cadastro_salvo` e o gatilho `prepararSeCabe`), com o ator CLIENTE. Esta classe guarda só o que a porta do
 * cliente acrescenta:
 *
 * - **"só enquanto o produto está na Lista"** (resposta do usuário, §19.1 e §19.12): {@see motivo()} — o produto que
 *   saiu da Lista (cadastro completo) ou que a trava do cadastro segura (algum item dele já andou) fica só leitura para
 *   o cliente: "fale com a equipe". As escritas respondem 409 `{message}` ({@see exigirEditavel()}) e nada grava. SEM
 *   `travado` de propósito: a gaveta do cliente não é a da equipe, e a chave não diz nada a ele;
 * - **o quadro de fotos** ({@see quadro()}): uma caixa por COR, pela MESMA régua da gaveta da equipe
 *   ({@see CadastroNaTrilha::donasDaCor()} — com cor e tamanho, as fotos são da cor e moram numa variação dela), com o
 *   que falta para o mínimo da Lista ({@see CompletudeDoProduto::FOTOS_MINIMAS}) e os limites do envio;
 * - **os avisos do envio** ({@see enviar()}): o que o servidor sabe e a tela não deduz do quadro.
 *
 * Nenhum texto daqui cita "Mercado Livre", "Publicador", "Portal" nem "agente" (D59): o cliente lê todos. As URLs das
 * fotos vêm de quem chama (a porta: `$urlDaFoto`) — quando a trilha for para o outro sistema, só o controller troca
 * (D57).
 */
class FotosDoClienteNaTrilha
{
    /** O `motivo` do quadro e a `message` do 409 (§19.15). */
    public const MSG_SO_LEITURA = 'Este produto já seguiu para a próxima etapa. Para trocar as fotos, fale com a equipe.';

    /** Aviso do envio: a foto completou o cadastro e o produto saiu da Lista (o quadro volta só leitura). */
    public const AVISO_SEGUIU = 'Fotos recebidas. O cadastro ficou completo e o produto seguiu para a próxima etapa.';

    /**
     * Aviso do envio: o produto voltou à Lista depois de já estar sendo montado (a equipe tirou uma foto, por exemplo).
     * A foto nova fica guardada, mas não entra sozinha no anúncio que já começou — o rascunho só recebe foto quando a
     * galeria dele está vazia (`PortalParaRascunhoService`, R8 do desenho).
     */
    public const AVISO_JA_EM_PREPARO = 'As fotos ficaram guardadas, mas o anúncio deste produto já começou a ser montado: '
        .'avise a equipe para usar as fotos novas.';

    public function __construct(
        private CadastroNaTrilha $cadastro,
        private VariacaoImagensService $imagens,
    ) {}

    /**
     * Por que o cliente NÃO pode mexer nas fotos deste produto, ou null quando pode: a trava do cadastro (algum item
     * que o leva já andou — a régua única {@see CadastroNaTrilha::travado()}) ou o produto fora da Lista (sem falta
     * nenhuma: a régua da etapa 1, {@see TrilhaDaEmpresa::faltasDoProduto()}, com o "frete ME1" como aviso). Consultas
     * fixas.
     */
    public function motivo(Company $empresa, EstruturaProduto $produto): ?string
    {
        if ($this->cadastro->travado($empresa, $produto) !== null) {
            return self::MSG_SO_LEITURA;
        }

        return TrilhaDaEmpresa::faltasDoProduto($empresa, (int) $produto->id) === [] ? self::MSG_SO_LEITURA : null;
    }

    /**
     * Toda escrita do cliente no produto que andou ou saiu da Lista: 409 `{message}` — antes de ler o corpo (§19.15:
     * 404 → 409 → 422), e nada grava.
     *
     * @throws HttpResponseException
     */
    public function exigirEditavel(Company $empresa, EstruturaProduto $produto): void
    {
        $motivo = $this->motivo($empresa, $produto);
        if ($motivo !== null) {
            throw new HttpResponseException(response()->json(['message' => $motivo], 409));
        }
    }

    /**
     * O quadro de fotos do produto (contrato §19.15), campo a campo:
     * `{produto: {id, nome, editavel, motivo}, cores: [{variacao_id, rotulo, minimo, maximo, fotos: [{id, url}],
     * faltam}], limites: {tamanho_mb, tipos, por_envio}}`.
     *
     * `cores`: com UM eixo (ou nenhum), uma caixa por variação; com cor E tamanho, uma por COR, na ordem em que a cor
     * aparece, e `variacao_id` é a "dona" dela (a 1ª variação da cor com foto, senão a 1ª — {@see
     * CadastroNaTrilha::donasDaCor()}): é para lá que o envio vai. `rotulo` é o valor da variação (a cor), null no
     * produto que não varia. `faltam` = quanto falta para o mínimo da Lista naquela caixa.
     *
     * @param  Closure(int, int): string  $urlDaFoto  (variação, imagem) → a URL da foto na porta de quem pede
     */
    public function quadro(Company $empresa, EstruturaProduto $produto, Closure $urlDaFoto): array
    {
        return $this->montarQuadro($empresa, $produto, $urlDaFoto, $this->motivo($empresa, $produto));
    }

    /** {@see quadro()} com o `motivo` já calculado (o envio o calcula uma vez, para os avisos e para o quadro). */
    private function montarQuadro(Company $empresa, EstruturaProduto $produto, Closure $urlDaFoto, ?string $motivo): array
    {
        $variacoes = EstruturaProdutoVariacao::query()
            ->where('company_id', $empresa->id)
            ->where('produto_id', $produto->id)
            ->orderBy('ordem')->orderBy('id')
            ->get(['id', 'valor', 'eixo_2']);
        $linhas = $variacoes->map(fn (EstruturaProdutoVariacao $v) => ['id' => (int) $v->id, 'valor' => $v->valor, 'eixo_2' => $v->eixo_2])->all();
        $galerias = $this->imagens->listarDasVariacoes($empresa, array_column($linhas, 'id'));
        $donas = CadastroNaTrilha::donasDaCor($linhas, $galerias);

        // Uma caixa por cor, na ordem em que a cor aparece (a dona pode não ser a 1ª variação dela). Sem 2º eixo, cada
        // variação é dona de si mesma e a chave é o id.
        $doisEixos = array_filter($linhas, fn (array $l) => trim((string) $l['eixo_2']) !== '') !== [];
        $caixas = [];
        foreach ($linhas as $l) {
            $chave = $doisEixos ? 'cor:'.ChaveCanonica::texto((string) $l['valor']) : 'id:'.$l['id'];
            $caixas[$chave] ??= ['rotulo' => trim((string) $l['valor']), 'dona' => null];
            if (isset($donas[$l['id']])) {
                $caixas[$chave]['dona'] = $l['id'];
            }
        }

        $minimo = CompletudeDoProduto::FOTOS_MINIMAS;
        $maximo = VariacaoImagensService::maxPorVariacao();
        $cores = [];
        foreach ($caixas as $caixa) {
            if ($caixa['dona'] === null) {
                continue;
            }
            $fotos = array_map(fn (array $f) => [
                'id'  => (int) $f['id'],
                'url' => $urlDaFoto((int) $caixa['dona'], (int) $f['id']),
            ], $galerias[$caixa['dona']] ?? []);
            $cores[] = [
                'variacao_id' => (int) $caixa['dona'],
                'rotulo'      => $caixa['rotulo'] !== '' ? $caixa['rotulo'] : null,
                'minimo'      => $minimo,
                'maximo'      => $maximo,
                'fotos'       => $fotos,
                'faltam'      => max(0, $minimo - count($fotos)),
            ];
        }

        return [
            'produto' => [
                'id'       => (int) $produto->id,
                'nome'     => (string) $produto->nome,
                'editavel' => $motivo === null,
                'motivo'   => $motivo,
            ],
            'cores'   => $cores,
            'limites' => self::limites(),
        ];
    }

    /**
     * Envia as fotos (já conferidas pela forma, `RegrasDoCadastro::imagens`) pelo MESMO caminho da equipe, com o ator
     * do cliente, e devolve o quadro novo com os `avisos`. Quem chama já passou por {@see exigirEditavel()}.
     *
     * @param  list<\Illuminate\Http\UploadedFile>  $arquivos
     * @param  Closure(int, int): string  $urlDaFoto
     * @return array{produto: array, cores: list<array>, limites: array, avisos: list<string>}
     *
     * @throws \Illuminate\Validation\ValidationException o teto da variação (tudo ou nada) e o formato pelo conteúdo
     */
    public function enviar(Company $empresa, EstruturaProduto $produto, EstruturaProdutoVariacao $variacao, array $arquivos, AtorDoPortal $ator, Closure $urlDaFoto): array
    {
        $jaNoPreparo = $this->cadastro->jaNoPublicador($empresa, $produto);
        $this->cadastro->enviarFotos($empresa, $produto, $variacao, $arquivos, $ator);

        $motivo = $this->motivo($empresa, $produto);
        $avisos = [];
        if ($motivo !== null) {
            $avisos[] = self::AVISO_SEGUIU;
        }
        if ($jaNoPreparo) {
            $avisos[] = self::AVISO_JA_EM_PREPARO;
        }

        return [...$this->montarQuadro($empresa, $produto, $urlDaFoto, $motivo), 'avisos' => $avisos];
    }

    /**
     * Exclui a foto pelo MESMO caminho da equipe, com o ator do cliente, e devolve o quadro novo. Quem chama já passou
     * por {@see exigirEditavel()}.
     *
     * @param  Closure(int, int): string  $urlDaFoto
     */
    public function excluir(Company $empresa, EstruturaProduto $produto, EstruturaProdutoVariacao $variacao, EstruturaProdutoVariacaoImagem $imagem, AtorDoPortal $ator, Closure $urlDaFoto): array
    {
        $this->cadastro->excluirFoto($empresa, $produto, $variacao, $imagem, $ator);

        return $this->quadro($empresa, $produto, $urlDaFoto);
    }

    /**
     * Os limites do envio, os MESMOS que o servidor confere (`RegrasDoCadastro::imagens`): o tamanho de cada foto, os
     * tipos (o `jpeg` é o `jpg`) e quantas cabem numa remessa (o teto da variação).
     *
     * @return array{tamanho_mb: int, tipos: list<string>, por_envio: int}
     */
    public static function limites(): array
    {
        $tipos = [];
        foreach ((array) config('estrutura_produtos.imagens.extensoes', ['jpg', 'jpeg', 'png', 'webp']) as $extensao) {
            $extensao = strtolower(trim((string) $extensao));
            $extensao = $extensao === 'jpeg' ? 'jpg' : $extensao;
            if ($extensao !== '' && ! in_array($extensao, $tipos, true)) {
                $tipos[] = $extensao;
            }
        }

        return [
            'tamanho_mb' => (int) round((int) config('estrutura_produtos.imagens.max_kb', 10240) / 1024),
            'tipos'      => $tipos,
            'por_envio'  => VariacaoImagensService::maxPorVariacao(),
        ];
    }
}
