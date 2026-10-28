<?php

namespace App\Services\Portal\Estrutura\Produtos\MercadoLivre;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoOrigemMl;
use App\Models\EstruturaProdutoVariacao;
use App\Services\MercadoLivreService;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Importa UM anúncio do Mercado Livre como Produto do Portal (28/10/2026). Quem chama é o
 * {@see \App\Jobs\Portal\ImportarAnuncioMlParaProdutoJob}.
 *
 * ### Passos
 * 1. Lê o anúncio (`include_attributes=all`) e a descrição. O anúncio tem de ser da conta
 *    conectada da empresa (`seller_id`) — o acervo já filtrou, aqui se confere no ML.
 * 2. Traduz ({@see TradutorAnuncioMlParaPortal}) e grava pelo ÚNICO caminho de escrita do
 *    cadastro (`ProdutoCadastroService::gravarLinhas`, modo importação, sem agendar a IA — quem
 *    agenda é a jardinagem, no fim).
 * 3. Ficha técnica: os atributos do anúncio pela gravação PARCIAL da ficha (mesma validação da
 *    tela; o que a categoria não aceita fica de fora, com o motivo no retrato).
 * 4. Fotos: baixadas do anúncio original e guardadas pela MESMA galeria da ficha
 *    (`VariacaoImagensService::enviar`) — nada de imagem nova gerada (custo).
 * 5. Grava a origem (o selo) e põe a jardinagem pela IA na fila.
 *
 * ### Não sobrescreve cadastro
 * Ref que já existe na empresa NÃO é atualizada: o anúncio é recusado como "já existe" (é o caso do
 * Clássico e do Premium com o mesmo SKU — o produto já entrou pelo outro anúncio). O grupo que bate
 * com produto existente troca para o MLB, para a variação nova nunca cair dentro de outro produto.
 *
 * ### Escrita no ML
 * NENHUMA. Só GET no anúncio e nas fotos. O anúncio original continua igual.
 */
class ImportadorDeAnuncioMl
{
    private const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_BYTES_FOTO = 10 * 1024 * 1024;

    public function __construct(
        private MercadoLivreService $ml,
        private ProdutoCadastroService $cadastro,
        private FichaTecnicaDaCategoria $fichas,
        private FichaTecnicaDoProduto $fichaTecnica,
        private VariacaoImagensService $imagens,
    ) {}

    /**
     * @param  callable(string): void|null  $passo  avisa a tela em que passo está
     * @return array{situacao: string, produto_id: ?int, mensagem: ?string, avisos: list<string>}
     */
    public function importar(Company $empresa, string $mlb, AtorDoPortal $ator, ?callable $passo = null): array
    {
        $mlb = strtoupper(trim($mlb));
        $passo ??= fn (string $p) => null;

        $origem = EstruturaProdutoOrigemMl::query()->where('company_id', $empresa->id)->where('ml_item_id', $mlb)->first();
        if ($origem !== null) {
            return $this->resultado(ProdutosDoMercadoLivreService::PRONTO, (int) $origem->produto_id, 'Este anúncio já tinha sido importado.');
        }

        $token = $this->ml->ensureValidToken($empresa);
        if ($token === null) {
            return $this->resultado(ProdutosDoMercadoLivreService::ERRO, null, 'A conta do Mercado Livre está desconectada. Conecte de novo e tente outra vez.');
        }

        // ─── 1. O anúncio ───
        $passo('Lendo o anúncio');
        try {
            $item = $this->ml->get($empresa, "/items/{$mlb}", ['include_attributes' => 'all']);
        } catch (\Throwable $e) {
            Log::warning("[Estrutura Produtos ML] leitura do anúncio {$mlb} falhou — empresa {$empresa->id} ({$empresa->name}): {$e->getMessage()}");

            return $this->resultado(ProdutosDoMercadoLivreService::ERRO, null, 'O Mercado Livre não devolveu o anúncio agora. Tente de novo em instantes.');
        }

        if ((string) ($item['seller_id'] ?? '') !== (string) $token->ml_user_id) {
            return $this->resultado(ProdutosDoMercadoLivreService::ERRO, null, 'Este anúncio não é da conta conectada.');
        }
        // Qualquer situação entra (ativo, pausado, encerrado — decisão do usuário, 28/10/2026): é um clone
        // para melhorar, e o anúncio original não é tocado.

        $descricao = null;
        try {
            $d = $this->ml->get($empresa, "/items/{$mlb}/description");
            $descricao = is_string($d['plain_text'] ?? null) ? $d['plain_text'] : null;
        } catch (\Throwable) {
            // Anúncio sem descrição responde 404: segue sem ela.
        }

        $traducao = TradutorAnuncioMlParaPortal::traduzir($item, $descricao, VariacaoImagensService::maxPorVariacao());
        $avisos = $traducao['avisos'];
        $linhas = $traducao['linhas'];

        // ─── 2. O cadastro ───
        $conflito = $this->refJaCadastrada($empresa, $linhas);
        if ($conflito !== null) {
            return $this->resultado(ProdutosDoMercadoLivreService::JA_EXISTE, (int) $conflito->produto_id,
                "Já existe um produto com o código “{$conflito->codigo}” ({$conflito->produto?->nome}). Se é o mesmo produto de outro anúncio, ele já está no cadastro.");
        }
        $linhas = $this->grupoLivre($empresa, $linhas, $mlb);
        if ($linhas === null) {
            return $this->resultado(ProdutosDoMercadoLivreService::ERRO, null, "Já existe um produto com o código {$mlb}.");
        }

        $passo('Criando o produto');
        $res = $this->cadastro->gravarLinhas($empresa, $linhas, $ator, ProdutoCadastroService::MODO_IMPORTACAO, agendarPreparo: false);
        $variacoes = [];
        foreach ($res['linhas'] as $l) {
            $variacoes[ProdutoCadastroService::chaveCodigo((string) $l['codigo'])] = $l;
        }
        $produtoId = $res['linhas'][0]['produto_id'] ?? null;
        if ($produtoId === null) {
            $msg = $res['erros'][0]['mensagem'] ?? 'Não foi possível criar o produto.';

            return $this->resultado(ProdutosDoMercadoLivreService::ERRO, null, (string) $msg);
        }
        foreach ($res['erros'] as $e) {
            $avisos[] = "Variação {$e['codigo']}: {$e['mensagem']}";
        }
        /** @var EstruturaProduto $produto */
        $produto = EstruturaProduto::query()->where('company_id', $empresa->id)->findOrFail($produtoId);

        // ─── 3. A ficha técnica ───
        $passo('Preenchendo a ficha técnica');
        $recusados = $this->ficha($empresa, $produto, $traducao['atributos'], $ator, $avisos);

        // ─── 4. As fotos do anúncio original ───
        $passo('Trazendo as fotos');
        $this->fotos($empresa, $linhas, $traducao['fotos_por_linha'], $variacoes, $ator, $avisos);

        // ─── 5. A origem (o selo) ───
        $origem = EstruturaProdutoOrigemMl::create([
            'company_id'      => $empresa->id,
            'produto_id'      => $produto->id,
            'ml_item_id'      => $mlb,
            'titulo_original' => mb_substr((string) ($item['title'] ?? ''), 0, 255),
            // `atributos_ml` cru: se a ficha da categoria não carregou agora, a jardinagem os aplica depois.
            'retrato'         => $traducao['retrato'] + ['atributos_ml' => $traducao['atributos'], 'campos_recusados' => $recusados, 'avisos' => $avisos],
            'importado_por'   => mb_substr($ator->descricao(), 0, 255),
            'importado_em'    => now(),
        ]);

        RegistroEstrutura::registrar($ator, $empresa, $produto, 'produto_importado_do_ml',
            "Produto “{$produto->nome}” importado do anúncio {$mlb}", ['produto_id' => (int) $produto->id, 'ml_item_id' => $mlb]);

        JardinagemDaFicha::agendar($origem);

        return $this->resultado(ProdutosDoMercadoLivreService::PRONTO, (int) $produto->id, null, $avisos);
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /** A primeira Ref do anúncio que já existe na empresa (sem caixa nem acento), ou null. */
    private function refJaCadastrada(Company $empresa, array $linhas): ?EstruturaProdutoVariacao
    {
        $chaves = [];
        $minusculas = [];
        foreach ($linhas as $l) {
            $chaves[ProdutoCadastroService::chaveCodigo((string) $l['codigo'])] = true;
            $minusculas[] = mb_strtolower(trim((string) $l['codigo']));
        }

        // O banco acha os candidatos (no MySQL a collation já ignora caixa e acento); o PHP decide pela
        // mesma chave do cadastro (`chaveCodigo`), que é a que derruba a linha por código repetido.
        return EstruturaProdutoVariacao::query()
            ->where('company_id', $empresa->id)
            ->where(function ($q) use ($minusculas) {
                foreach ($minusculas as $m) {
                    $q->orWhereRaw('LOWER(codigo) = ?', [$m]);
                }
            })
            ->with('produto:id,nome')
            ->get()
            ->first(fn (EstruturaProdutoVariacao $v) => isset($chaves[ProdutoCadastroService::chaveCodigo((string) $v->codigo)]));
    }

    /**
     * O grupo (código do produto) não pode cair num produto que já existe — no modo importação o
     * grupo CASA com o produto existente e a variação entraria nele. Bateu: vira o MLB. Bateu de
     * novo: null (recusa).
     */
    private function grupoLivre(Company $empresa, array $linhas, string $mlb): ?array
    {
        $ocupado = function (string $codigo) use ($empresa): bool {
            $chave = ProdutoCadastroService::chaveCodigo($codigo);
            $produtos = EstruturaProduto::query()->where('company_id', $empresa->id)->whereRaw('LOWER(codigo) = ?', [mb_strtolower($codigo)])->pluck('codigo');
            $variacoes = EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->whereRaw('LOWER(codigo) = ?', [mb_strtolower($codigo)])->pluck('codigo');

            return $produtos->merge($variacoes)->contains(fn ($c) => ProdutoCadastroService::chaveCodigo((string) $c) === $chave);
        };

        $grupo = (string) ($linhas[0]['grupo'] ?? $mlb);
        if (! $ocupado($grupo)) {
            return $linhas;
        }
        if ($ocupado($mlb)) {
            return null;
        }

        return array_map(fn (array $l) => ['grupo' => $mlb] + $l, $linhas);
    }

    /**
     * Os atributos do anúncio → a ficha, pela gravação parcial (mesma validação da tela).
     *
     * @return list<array{id: string, nome: string, motivo: string}>  o que a ficha não aceitou
     */
    private function ficha(Company $empresa, EstruturaProduto $produto, array $atributos, AtorDoPortal $ator, array &$avisos): array
    {
        $categoria = trim((string) $produto->categoria_ml_id);
        if ($categoria === '' || $atributos === []) {
            return [];
        }

        $definicao = $this->fichas->definicao($categoria);
        if ($definicao === []) {
            $avisos[] = 'A ficha técnica da categoria não carregou agora: os campos do anúncio entram em seguida.';

            return [];
        }
        $campos = FichaTecnicaDaCategoria::camposPorId(FichaTecnicaDaCategoria::doProduto($definicao, FichaTecnicaDoProduto::eixosDoProduto($produto)));

        $entradas = [];
        foreach ($atributos as $id => $a) {
            if (isset($campos[$id]) && ($e = TradutorAnuncioMlParaPortal::entradaDaFicha($campos[$id], $a)) !== null) {
                $entradas[$id] = $e;
            }
        }
        if ($entradas === []) {
            return [];
        }

        try {
            $r = $this->fichaTecnica->gravarParcial($empresa, $produto, $entradas, $ator);
        } catch (ValidationException $e) {
            $avisos[] = 'A ficha técnica não foi gravada: '.collect($e->errors())->flatten()->first();

            return [];
        }

        $recusados = [];
        foreach ($r['erros'] as $id => $motivo) {
            $recusados[] = ['id' => (string) $id, 'nome' => (string) ($campos[$id]['nome'] ?? $id), 'motivo' => (string) $motivo];
        }

        return $recusados;
    }

    /**
     * Baixa as fotos de cada variação (uma vez por foto, mesmo repetida entre variações) e as
     * guarda pela galeria da ficha. Foto que não baixa é pulada, com aviso.
     */
    private function fotos(Company $empresa, array $linhas, array $fotosPorLinha, array $variacoes, AtorDoPortal $ator, array &$avisos): void
    {
        $baixadas = [];   // id da foto => [caminho, mime] | false
        $falhas = 0;

        try {
            foreach ($linhas as $i => $linha) {
                $v = $variacoes[ProdutoCadastroService::chaveCodigo((string) $linha['codigo'])] ?? null;
                if ($v === null) {
                    continue;
                }
                $variacao = EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->find($v['id']);
                if ($variacao === null) {
                    continue;
                }

                $arquivos = [];
                foreach ($fotosPorLinha[$i] ?? [] as $n => $foto) {
                    $baixadas[$foto['id']] ??= $this->baixar($foto['url']);
                    if ($baixadas[$foto['id']] === false) {
                        $falhas++;

                        continue;
                    }
                    [$caminho, $mime] = $baixadas[$foto['id']];
                    $arquivos[] = new UploadedFile($caminho, ($linha['codigo'] ?? 'foto').'_'.($n + 1).'.'.self::MIMES[$mime], $mime, null, true);
                }
                if ($arquivos === []) {
                    continue;
                }

                try {
                    $this->imagens->enviar($empresa, $variacao, $arquivos, $ator);
                } catch (\Throwable $e) {
                    $avisos[] = "As fotos da variação {$variacao->codigo} não entraram: ".($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : 'falha ao guardar.');
                    Log::warning("[Estrutura Produtos ML] fotos da variação {$variacao->id} falharam — empresa {$empresa->id}: {$e->getMessage()}");
                }
            }
        } finally {
            foreach ($baixadas as $b) {
                if (is_array($b)) {
                    @unlink($b[0]);
                }
            }
        }

        if ($falhas > 0) {
            $avisos[] = $falhas === 1 ? '1 foto do anúncio não pôde ser baixada.' : "{$falhas} fotos do anúncio não puderam ser baixadas.";
        }
    }

    /** @return array{0: string, 1: string}|false  [arquivo temporário, mime] */
    private function baixar(string $url): array|false
    {
        try {
            $r = Http::timeout(20)->retry(2, 500, throw: false)->get($url);
        } catch (\Throwable) {
            return false;
        }
        $corpo = $r->successful() ? $r->body() : '';
        if ($corpo === '' || strlen($corpo) > self::MAX_BYTES_FOTO) {
            return false;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($corpo);
        if (! isset(self::MIMES[$mime])) {
            return false;
        }
        $caminho = tempnam(sys_get_temp_dir(), 'mlfoto');
        if ($caminho === false || file_put_contents($caminho, $corpo) === false) {
            return false;
        }

        return [$caminho, $mime];
    }

    private function resultado(string $situacao, ?int $produtoId, ?string $mensagem, array $avisos = []): array
    {
        return ['situacao' => $situacao, 'produto_id' => $produtoId, 'mensagem' => $mensagem, 'avisos' => array_values(array_unique($avisos))];
    }
}
