<?php

namespace App\Services\Trilha;

use App\Jobs\Trilha\ImportarAnuncioNaTrilhaJob;
use App\Models\Company;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoOrigemMl;
use App\Models\EstruturaProdutoVariacao;
use App\Models\MlAcervoItem;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use App\Services\MercadoLivreService;
use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use App\Services\Mlb\Publicacao\MlGradeService;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\AnunciosNoPortal;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\FotosDoAnuncioMl;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\JardinagemDaFicha;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\ProdutosDoMercadoLivreService;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\TradutorAnuncioMlParaPortal;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Publicador\PalavrasChaveService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * "Importar do Mercado Livre" DENTRO da trilha (3ª entrega, fatia F8, desenho fechado em 03/11/2026 —
 * `ENTREGA-3-DESENHO.md` §18). A equipe escolhe anúncios que a loja já tem e cada um vira um produto na Lista, com
 * variações, fotos, caixa, ficha e título; o custo, a família e o ambiente ficam para ela.
 *
 * ### A lista ({@see anuncios()})
 * O acervo de sempre (`ProdutosDoMercadoLivreService::candidatos`, zero chamada ao ML), por LISTA DE CAMPOS: o que o
 * Portal acrescentar lá não chega aqui. `na_lista` diz o que já está na Lista — importado, publicado, ligado no
 * Mapeamento (`AnunciosNoPortal`) ou com SKU igual a uma Ref da loja (os `skus` do acervo × as Refs, sem chamar o ML).
 *
 * ### Cada anúncio, como uma gaveta salva (D28, o padrão da F7) — {@see executar()}, no Job
 * 1. Antes de tudo, fora de transação (D30): o anúncio e a descrição (GET), as recusas que o anúncio já diz (SKU de
 *    outro produto, SKU com anúncio na espera — D37 —, variações demais), a categoria (`detalhes` e `definicao`, o mesmo
 *    cache de 7 dias que as conferências de dentro leem) e as FOTOS, baixadas para temporários (`FotosDoAnuncioMl`).
 * 2. UMA transação, só banco: `CadastroNaTrilha::criar` → `conferirLinhas` → `conferirCategoria` → `gravar` (a régua das
 *    cores pelo resultado) → a ficha do anúncio (`gravarParcial`) → a origem (`EstruturaProdutoOrigemMl`: selo,
 *    deduplicação, fatos para a IA) → o evento `passo: mercado_livre`. Qualquer erro de linha recusa o anúncio inteiro.
 * 3. Depois do commit, cada passo protegido (o produto fica, com aviso): as fotos por cor (`VariacaoImagensService`),
 *    a ficha pela IA (D31, `JardinagemDaFicha::completar`), o título pelo robô (D32, o mesmo do Publicador:
 *    `PalavrasChaveService::gerarTitulosDoProduto`) e, no fim, `prepararSeCabe` (na prática nada vai: custo, família e
 *    ambiente nunca vêm do ML).
 *
 * Produto existente nunca é atualizado (D34). Zero escrita no ML.
 *
 * ### O estado
 * No cache, por 6 h: o lote da empresa (`trilha:ml:lote:{empresa}`) e uma chave por anúncio
 * (`trilha:ml:item:{empresa}:{mlb}` — dois Jobs nunca escrevem a mesma). A tela pergunta a cada 2 s ({@see estado()}).
 * Nenhum texto daqui cita o Portal: a trilha o substitui (regra de 03/11/2026).
 */
class ImportacaoDoMercadoLivre
{
    // ─── Situações de um anúncio (contrato §18.4) ───

    public const FILA = 'fila';

    public const IMPORTANDO = 'importando';

    public const NOVO = 'novo';

    public const NA_LISTA = 'na_lista';

    public const RECUSADO = 'recusado';

    public const ERRO = 'erro';

    // ─── Passos (o que a tela mostra enquanto importa) ───

    public const PASSO_LENDO = 'Lendo o anúncio';

    public const PASSO_GRAVANDO = 'Gravando';

    public const PASSO_FOTOS = 'Trazendo as fotos';

    public const PASSO_FICHA = 'Preenchendo a ficha';

    public const PASSO_TITULO = 'Escrevendo o título';

    /** Anúncios por clique em "Importar" (cada um baixa até 12 fotos por variação e chama a IA duas vezes). */
    public const MAX_POR_IMPORTACAO = ProdutosDoMercadoLivreService::MAX_POR_IMPORTACAO;

    /** `na_lista.como`: de onde se sabe que o anúncio já está na Lista. */
    private const COMO = [
        AnunciosNoPortal::IMPORTADO  => 'importado',
        AnunciosNoPortal::PUBLICADOR => 'publicado',
        AnunciosNoPortal::MAPEAMENTO => 'ligado',
    ];

    public const COMO_SKU = 'sku';

    private const VALIDADE_HORAS = 6;

    // ─── Validação do pedido (422 `errors.mlbs`) ───

    public const MSG_DESCONECTADA = 'A conta do Mercado Livre desta loja está desconectada. Conecte a conta de novo para importar os anúncios.';

    public const MSG_NENHUM = 'Escolha pelo menos um anúncio.';

    public const MSG_MAIS_DE = 'Escolha até %d anúncios por vez.';

    public const MSG_FORA_DO_ACERVO = 'Algum dos anúncios escolhidos não está na conta desta loja. Recarregue a lista e escolha de novo.';

    // ─── Recusado (nada foi gravado) ───

    public const MSG_JA_NA_LISTA = 'Este anúncio já está na Lista.';

    public const MSG_SKU_DE_OUTRO = 'O SKU %s deste anúncio já é do produto “%s”, que está na Lista. Nada foi importado.';

    public const MSG_ESPERA = 'O SKU %s tem um anúncio do Mercado Livre esperando para ser ligado a um produto. Importar agora ligaria o '
        .'produto novo a esse anúncio, já no ar e travado. Resolva esse anúncio antes de importar.';

    public const MSG_GRADE = 'O anúncio tem %d variações, e um produto vai até %d.';

    public const MSG_LINHA = 'A variação %s não pôde ser gravada: %s';

    // ─── Erro (nada foi gravado; tente de novo) ───

    public const MSG_LEITURA = 'O Mercado Livre não devolveu o anúncio agora. Tente de novo em instantes.';

    public const MSG_OUTRA_CONTA = 'Este anúncio não é da conta conectada.';

    public const MSG_CATEGORIA_FORA = 'Não conseguimos ler a categoria do anúncio agora. Tente de novo em instantes.';

    public const MSG_FALHA = 'Não foi possível importar este anúncio agora. Tente de novo.';

    public const MSG_EXPIROU = 'A importação expirou. Tente de novo.';

    public const MSG_EM_ANDAMENTO = 'Este anúncio já está sendo importado agora. Confira a Lista em instantes.';

    public const MSG_SEM_PESSOA = 'Quem pediu a importação não está mais ativo. Peça de novo.';

    // ─── Avisos (o produto fica) ───

    public const AVISO_SEM_CATEGORIA = 'A categoria do anúncio não aceita produtos novos: escolha a categoria na gaveta do produto. '
        .'A ficha e o título ficam para depois dela.';

    public const AVISO_MODA = 'Esta categoria ainda não publica pelo sistema (ela pede a tabela de medidas): o produto fica na Lista, '
        .'mas ainda não vira anúncio novo por aqui.';

    public const AVISO_TITULO = 'O robô não conseguiu escrever o título agora. Escreva o título na gaveta.';

    public const AVISO_FICHA_IA = 'A IA não completou a ficha agora: confira os campos na gaveta.';

    public const AVISO_FOTOS = 'As fotos da variação %s não entraram: %s';

    public function __construct(
        private ProdutosDoMercadoLivreService $acervo,
        private AnunciosNoPortal $jaNaLista,
        private MercadoLivreService $ml,
        private CadastroNaTrilha $cadastro,
        private CategoriaSugestaoService $categorias,
        private FichaTecnicaDaCategoria $fichas,
        private FichaTecnicaDoProduto $fichaTecnica,
        private VariacaoImagensService $imagens,
        private MlCatalogoMetaService $catalogo,
    ) {}

    // ═══ A lista ════════════════════════════════════════════════════════════

    /**
     * Os anúncios do acervo da loja (30 por página), sem chamar o ML. Por padrão os que já estão na Lista por
     * importação, publicação ou Mapeamento ficam escondidos (`mostrarNaLista` os traz); o de SKU igual aparece marcado
     * (`na_lista.como = sku`) — o acervo não sabe filtrá-lo antes de paginar.
     *
     * @return array{conectado: bool, atualizado_em: ?string, total: int, pagina: int, paginas: int, limite: int, itens: list<array>}
     */
    public function anuncios(Company $empresa, string $busca = '', string $ordem = 'oportunidade', string $situacao = 'todos', int $pagina = 1, bool $mostrarNaLista = false): array
    {
        $r = $this->acervo->candidatos($empresa, $busca, $ordem, $pagina, ! $mostrarNaLista, $situacao);
        $mlbs = array_column($r['itens'], 'mlb');

        $skus = $mlbs === [] ? [] : MlAcervoItem::query()->where('company_id', $empresa->id)->whereIn('ml_item_id', $mlbs)
            ->get(['ml_item_id', 'skus'])->mapWithKeys(fn (MlAcervoItem $i) => [$i->ml_item_id => (array) ($i->skus ?? [])])->all();
        $naLista = $this->naLista($empresa, $mlbs, $skus);
        $importacoes = $this->situacoes($empresa, $mlbs);

        return [
            'conectado'     => (bool) $r['conectado'],
            'atualizado_em' => self::iso($r['atualizado_em'] ?? null),
            'total'         => (int) $r['total'],
            'pagina'        => (int) $r['pagina'],
            'paginas'       => (int) $r['paginas'],
            'limite'        => self::MAX_POR_IMPORTACAO,
            'itens'         => array_map(fn (array $i) => [
                'mlb'            => (string) $i['mlb'],
                'titulo'         => $i['titulo'],
                'thumbnail'      => $i['thumbnail'],
                'permalink'      => $i['permalink'],
                'preco'          => $i['preco'],
                'tipo'           => $i['tipo'],
                'ativo'          => (bool) $i['ativo'],
                'status'         => $i['status'],
                'catalogo'       => (bool) $i['catalogo'],
                'estoque'        => $i['estoque'],
                'vendas_total'   => (int) $i['vendas_total'],
                'visitas_30d'    => $i['visitas_30d'],
                'fotos'          => $i['fotos'],
                'saude_nivel'    => $i['saude_nivel'],
                'feito_pela_ecf' => (bool) $i['feito_pela_ecf'],
                'na_lista'       => isset($naLista[$i['mlb']])
                    ? ['produto_id' => $naLista[$i['mlb']]['produto_id'], 'nome' => $naLista[$i['mlb']]['nome'], 'como' => $naLista[$i['mlb']]['como']]
                    : null,
                'importacao'     => isset($importacoes[$i['mlb']]) ? ['situacao' => $importacoes[$i['mlb']]] : null,
            ], $r['itens']),
        ];
    }

    // ═══ O pedido ═══════════════════════════════════════════════════════════

    /**
     * "Importar N": confere o pedido (422 `mlbs`: conta desconectada, nenhum, mais de {@see MAX_POR_IMPORTACAO}, algum
     * fora do acervo da loja) e põe na fila da IA um Job por anúncio. O que já está na Lista não vai à fila: importado,
     * publicado ou ligado sai `na_lista` na hora; o de SKU igual a uma Ref da loja sai `recusado`, dizendo de qual
     * produto é. O que já está na fila (ou importando) não entra de novo.
     *
     * @param  array<int, mixed>  $mlbs
     * @return array estado ({@see estado()})
     *
     * @throws ValidationException
     */
    public function importar(Company $empresa, array $mlbs, User $quem): array
    {
        if (! AnunciosMercadoLivreService::conectado($empresa)) {
            throw ValidationException::withMessages(['mlbs' => self::MSG_DESCONECTADA]);
        }

        $pedidos = array_values(array_unique(array_filter(array_map(fn ($m) => strtoupper(trim(is_scalar($m) ? (string) $m : '')), $mlbs))));
        if ($pedidos === []) {
            throw ValidationException::withMessages(['mlbs' => self::MSG_NENHUM]);
        }
        if (count($pedidos) > self::MAX_POR_IMPORTACAO) {
            throw ValidationException::withMessages(['mlbs' => sprintf(self::MSG_MAIS_DE, self::MAX_POR_IMPORTACAO)]);
        }

        $doAcervo = MlAcervoItem::query()->where('company_id', $empresa->id)->whereIn('ml_item_id', $pedidos)
            ->get(['ml_item_id', 'title', 'skus'])->keyBy('ml_item_id');
        if (count(array_diff($pedidos, $doAcervo->keys()->all())) > 0) {
            throw ValidationException::withMessages(['mlbs' => self::MSG_FORA_DO_ACERVO]);
        }

        $lote = Cache::get($this->chaveLote($empresa));
        $lote = is_array($lote) ? $lote : ['mlbs' => [], 'iniciado_em' => now()->toIso8601String()];
        $naLista = $this->naLista($empresa, $pedidos, $doAcervo->map(fn (MlAcervoItem $i) => (array) ($i->skus ?? []))->all());

        foreach ($pedidos as $mlb) {
            if (in_array($this->situacao($empresa, $mlb)['situacao'] ?? null, [self::FILA, self::IMPORTANDO], true)) {
                continue;
            }
            if (! in_array($mlb, $lote['mlbs'], true)) {
                $lote['mlbs'][] = $mlb;
            }
            $base = ['titulo_do_anuncio' => (string) ($doAcervo->get($mlb)?->title ?? $mlb)];

            $ja = $naLista[$mlb] ?? null;
            if ($ja !== null) {
                $this->recomecar($empresa, $mlb, $base + ($ja['como'] === self::COMO_SKU
                    ? ['situacao' => self::RECUSADO, 'mensagem' => sprintf(self::MSG_SKU_DE_OUTRO, $ja['sku'], $ja['nome'] ?? '')]
                    : ['situacao' => self::NA_LISTA, 'produto_id' => $ja['produto_id'], 'nome' => $ja['nome'], 'mensagem' => self::MSG_JA_NA_LISTA]));

                continue;
            }

            $this->recomecar($empresa, $mlb, $base + ['situacao' => self::FILA]);
            ImportarAnuncioNaTrilhaJob::dispatch((int) $empresa->id, $mlb, (int) $quem->id);
        }

        Cache::put($this->chaveLote($empresa), $lote, now()->addHours(self::VALIDADE_HORAS));

        return $this->estado($empresa);
    }

    /**
     * O painel do lote (contrato §18.4, com a correção da tela de 03/11): um item por anúncio pedido, na ordem do pedido —
     * o último lote fica 6 h no cache, mesmo terminado; sem lote, `{em_andamento: false, totais: zeros, itens: []}`.
     * `titulo_do_anuncio` é sempre o do anúncio; `titulo` é o título de venda que o robô escreveu (nulo até ele
     * escrever, e nulo no `novo` em que ele não conseguiu).
     *
     * @return array{em_andamento: bool, totais: array{novos: int, na_lista: int, recusados: int, erros: int}, itens: list<array>}
     */
    public function estado(Company $empresa): array
    {
        $lote = Cache::get($this->chaveLote($empresa));
        $mlbs = is_array($lote) ? array_values((array) ($lote['mlbs'] ?? [])) : [];

        $itens = [];
        foreach ($mlbs as $mlb) {
            $itens[] = self::item((string) $mlb, $this->situacao($empresa, (string) $mlb));
        }

        $totais = ['novos' => 0, 'na_lista' => 0, 'recusados' => 0, 'erros' => 0];
        foreach ($itens as $i) {
            match ($i['situacao']) {
                self::NOVO     => $totais['novos']++,
                self::NA_LISTA => $totais['na_lista']++,
                self::RECUSADO => $totais['recusados']++,
                self::ERRO     => $totais['erros']++,
                default        => null,
            };
        }

        return [
            'em_andamento' => collect($itens)->contains(fn (array $i) => in_array($i['situacao'], [self::FILA, self::IMPORTANDO], true)),
            'totais'       => $totais,
            'itens'        => $itens,
        ];
    }

    /** Esquece o painel do lote, menos o que ainda está na fila ou importando (os produtos importados ficam). */
    public function limpar(Company $empresa): void
    {
        $lote = Cache::get($this->chaveLote($empresa));
        foreach ((array) ($lote['mlbs'] ?? []) as $mlb) {
            if (! in_array($this->situacao($empresa, (string) $mlb)['situacao'] ?? null, [self::FILA, self::IMPORTANDO], true)) {
                Cache::forget($this->chaveItem($empresa, (string) $mlb));
            }
        }
        $restantes = array_values(array_filter((array) ($lote['mlbs'] ?? []), fn ($m) => Cache::has($this->chaveItem($empresa, (string) $m))));
        $restantes === [] ? Cache::forget($this->chaveLote($empresa))
            : Cache::put($this->chaveLote($empresa), ['mlbs' => $restantes] + (array) $lote, now()->addHours(self::VALIDADE_HORAS));
    }

    // ═══ Um anúncio (o Job) ═════════════════════════════════════════════════

    /**
     * Importa UM anúncio e escreve o estado dele a cada passo. Não lança depois do commit: o que falha dali em diante
     * (foto, IA, título) vira aviso e o produto fica. Antes do commit, a recusa e o erro não gravam nada.
     *
     * @return array<string, mixed> o estado final do anúncio (o que foi escrito no cache)
     */
    public function executar(Company $empresa, string $mlb, AtorDoPortal $ator): array
    {
        $mlb = strtoupper(trim($mlb));
        $this->marcar($empresa, $mlb, ['situacao' => self::IMPORTANDO, 'passo' => self::PASSO_LENDO]);

        $r = $this->importarUm($empresa, $mlb, $ator);
        $this->marcar($empresa, $mlb, $r + ['passo' => null]);

        Log::info("[Trilha] Importar do ML: anúncio {$mlb} → {$r['situacao']} (produto ".($r['produto_id'] ?? '—').") — empresa {$empresa->id} ({$empresa->name})");

        return $this->situacao($empresa, $mlb);
    }

    /** O Job marca o erro que não passou por {@see executar()} (sem a pessoa, a trava de outra importação, a queda). */
    public function marcarErro(Company $empresa, string $mlb, string $mensagem): void
    {
        $this->marcar($empresa, strtoupper(trim($mlb)), ['situacao' => self::ERRO, 'passo' => null, 'mensagem' => $mensagem]);
    }

    /** @return array<string, mixed> o que {@see executar()} escreve no estado (sem o `passo`) */
    private function importarUm(Company $empresa, string $mlb, AtorDoPortal $ator): array
    {
        // ─── 1. O que já está na Lista não é tocado (D34) ───
        $ja = $this->naLista($empresa, [$mlb], [])[$mlb] ?? null;
        if ($ja !== null) {
            return ['situacao' => self::NA_LISTA, 'produto_id' => $ja['produto_id'], 'nome' => $ja['nome'], 'mensagem' => self::MSG_JA_NA_LISTA];
        }

        // ─── 2. O anúncio e a descrição (GET, fora de transação) ───
        $token = $this->ml->ensureValidToken($empresa);
        if ($token === null) {
            return self::erro(self::MSG_DESCONECTADA);
        }
        try {
            $item = $this->ml->get($empresa, "/items/{$mlb}", ['include_attributes' => 'all']);
        } catch (\Throwable $e) {
            Log::warning("[Trilha] Importar do ML: leitura do anúncio {$mlb} falhou — empresa {$empresa->id} ({$empresa->name}): {$e->getMessage()}");

            return self::erro(self::MSG_LEITURA);
        }
        if ((string) ($item['seller_id'] ?? '') !== (string) $token->ml_user_id) {
            return self::erro(self::MSG_OUTRA_CONTA);
        }
        $descricao = null;
        try {
            $d = $this->ml->get($empresa, "/items/{$mlb}/description");
            $descricao = is_string($d['plain_text'] ?? null) ? $d['plain_text'] : null;
        } catch (\Throwable) {
            // Anúncio sem descrição responde 404: segue sem ela.
        }

        $traducao = TradutorAnuncioMlParaPortal::traduzir($item, $descricao, VariacaoImagensService::maxPorVariacao());
        $linhas = $traducao['linhas'];
        $tituloDoAnuncio = trim((string) ($item['title'] ?? ''));
        $avisos = $traducao['avisos'];

        // ─── 3. As recusas que o anúncio já diz ───
        if (count($linhas) > ProdutoCadastroService::MAX_GRADE) {
            return self::recusado(sprintf(self::MSG_GRADE, count($linhas), ProdutoCadastroService::MAX_GRADE));
        }
        $codigos = array_map(fn (array $l) => (string) $l['codigo'], $linhas);
        $dono = $this->refsDaEmpresa($empresa, $codigos);
        if ($dono !== []) {
            $d = reset($dono);

            return self::recusado(sprintf(self::MSG_SKU_DE_OUTRO, $d['sku'], $d['nome'] ?? ''));
        }
        // D37: o SKU com anúncio na espera faria o produto nascer ligado a ele, "no ar" e travado (`varrerEspera`).
        $espera = $this->naEspera($empresa, $codigos);
        if ($espera !== null) {
            return self::recusado(sprintf(self::MSG_ESPERA, $espera));
        }

        // ─── 4. A categoria (D33): os caches ANTES da transação (D30) ───
        $categoriaId = strtoupper(trim((string) ($item['category_id'] ?? '')));
        $definicao = null;
        if ($categoriaId !== '') {
            $detalhe = $this->categorias->detalhes([$categoriaId])[$categoriaId] ?? null;
            if ($detalhe === null) {
                return self::erro(self::MSG_CATEGORIA_FORA);
            }
            if ($detalhe['folha'] && ($detalhe['anunciavel'] ?? false)) {
                $definicao = $this->fichas->definicao($categoriaId);
                if ($definicao === []) {
                    return self::erro(self::MSG_CATEGORIA_FORA);
                }
                if (app(MlGradeService::class)->categoriaExigeGrade($this->catalogo->atributos($categoriaId))) {
                    $avisos[] = self::AVISO_MODA;
                }
            }
        }
        if ($definicao === null) {
            $avisos[] = self::AVISO_SEM_CATEGORIA;
            $linhas = array_map(function (array $l) {
                unset($l['categoria_ml_id']);

                return $l;
            }, $linhas);
        }

        $baixador = new FotosDoAnuncioMl();
        try {
            // ─── 5. As fotos, para temporários (D30: nenhum HTTP dentro da transação) ───
            $fotosPorLinha = [];
            $falhas = 0;
            foreach ($linhas as $i => $l) {
                $r = $baixador->arquivos($traducao['fotos_por_linha'][$i] ?? [], (string) $l['codigo']);
                $fotosPorLinha[$i] = $r['arquivos'];
                $falhas += $r['falhas'];
            }

            // ─── 6. A gaveta salva, numa transação ───
            $this->marcar($empresa, $mlb, ['passo' => self::PASSO_GRAVANDO]);
            $gravado = $this->gravar($empresa, $mlb, $item, $traducao, $linhas, $definicao, $avisos, $ator);
            if (isset($gravado['situacao'])) {
                return $gravado;
            }
            /** @var EstruturaProduto $produto */
            ['produto' => $produto, 'origem' => $origem, 'ficha' => $ficha] = $gravado;
            $avisos = $gravado['avisos'];

            // ─── 7. Depois do commit: fotos, ficha pela IA, título pelo robô (cada um protegido) ───
            $this->marcar($empresa, $mlb, ['passo' => self::PASSO_FOTOS, 'produto_id' => (int) $produto->id, 'nome' => (string) $produto->nome]);
            $fotos = $this->guardarFotos($empresa, $produto, $linhas, $fotosPorLinha, $ator, $avisos);
            $fotos['falharam'] += $falhas;
            if ($falhas > 0) {
                $avisos[] = $falhas === 1 ? '1 foto do anúncio não pôde ser baixada.' : "{$falhas} fotos do anúncio não puderam ser baixadas.";
            }
        } finally {
            $baixador->limpar();
        }

        $ficha['pela_ia'] = 0;
        if ($definicao !== null) {
            $this->marcar($empresa, $mlb, ['passo' => self::PASSO_FICHA]);
            $registro = $this->fichaPelaIa($empresa, $produto, $origem);
            $ficha['pela_ia'] = count((array) ($registro['campos_ia'] ?? []));
            if (isset($registro['erro'])) {
                $avisos[] = self::AVISO_FICHA_IA;
            }
        } else {
            // Sem categoria não há ficha nem IA. O registro NÃO fica nulo: nulo, o estado do mesmo anúncio lido pelo
            // outro caminho de importação diria "a IA ainda vai passar" para sempre.
            $registro = ['campos_ia' => [], 'nomes' => [], 'faltavam' => 0, 'sem_categoria' => true, 'em' => now()->toIso8601String()];
        }
        $origem->jardinagem = $registro;
        $origem->save();

        $titulo = null;
        if ($definicao !== null) {
            $this->marcar($empresa, $mlb, ['passo' => self::PASSO_TITULO]);
            $titulo = $this->tituloPeloRobo($empresa, $produto, $tituloDoAnuncio, $ator);
            if ($titulo === null) {
                $avisos[] = self::AVISO_TITULO;
            }
        }

        $produto = $produto->fresh() ?? $produto;
        $faltas = [];
        try {
            $faltas = TrilhaDaEmpresa::faltasDoProduto($empresa, (int) $produto->id);
            // D35: na prática nada vai (custo, família e ambiente nunca vêm do ML) — mas a régua é a de sempre.
            $this->cadastro->prepararSeCabe($empresa, $produto);
        } catch (\Throwable $e) {
            Log::warning("[Trilha] Importar do ML: o fim do anúncio {$mlb} (produto {$produto->id}) falhou — empresa {$empresa->id}: {$e->getMessage()}");
        }

        return [
            'situacao'        => self::NOVO,
            'produto_id'      => (int) $produto->id,
            'nome'            => (string) $produto->nome,
            'codigo'          => $produto->codigo,
            'variacoes'       => EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->where('produto_id', $produto->id)->count(),
            'fotos'           => $fotos,
            'ficha'           => $ficha,
            'titulo_de_venda' => $titulo,
            'avisos'          => array_values(array_unique(array_map('strval', $avisos))),
            'faltas'          => $faltas,
            'mensagem'        => null,
        ];
    }

    /**
     * A transação do anúncio (D28): criar → as linhas (conferidas como as da gaveta) → a ficha do anúncio → a origem → o
     * evento. Só banco: os caches da categoria já estão quentes e as fotos já estão no disco. Qualquer erro desfaz tudo.
     *
     * @param  ?array  $definicao  a ficha da categoria (null = o produto entra sem categoria)
     * @param  list<string>  $avisos
     * @return array{situacao: string, mensagem: string}|array{produto: EstruturaProduto, origem: EstruturaProdutoOrigemMl, ficha: array{gravados: int, recusados: int}, avisos: list<string>}
     */
    private function gravar(Company $empresa, string $mlb, array $item, array $traducao, array $linhas, ?array $definicao, array $avisos, AtorDoPortal $ator): array
    {
        DB::beginTransaction();
        try {
            $primeira = $linhas[0];
            $criado = $this->cadastro->criar($empresa, (string) $primeira['nome'], (string) $primeira['codigo'], $ator, efeitos: false);
            /** @var EstruturaProduto $produto */
            $produto = $criado['produto'];
            $idDaPrimeira = (int) EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->where('produto_id', $produto->id)->value('id');

            $doServico = [];
            foreach ($linhas as $i => $l) {
                $linha = array_intersect_key($l, array_flip(CadastroNaTrilha::CHAVES_DA_LINHA));
                if ($i === 0) {
                    $linha['id'] = $idDaPrimeira;
                }
                $doServico[] = $linha;
            }
            $doServico = $this->cadastro->conferirLinhas($empresa, $produto, $doServico);
            $doServico = $this->cadastro->conferirCategoria($empresa, $produto, $doServico);
            $r = $this->cadastro->gravar($empresa, $produto, $doServico, $ator, efeitos: false);
            if ($r['erros'] !== []) {
                DB::rollBack();

                return self::recusado(implode(' ', array_map(fn (array $e) => sprintf(self::MSG_LINHA, (string) ($e['codigo'] ?? ''), (string) $e['mensagem']), $r['erros'])));
            }
            $avisos = [...$avisos, ...array_map('strval', $r['avisos'])];
            $produto = $produto->fresh() ?? $produto;

            // A ficha do anúncio, mesclando (o campo que a categoria não aceita fica de fora, com o motivo na origem).
            $ficha = ['gravados' => 0, 'recusados' => 0];
            $recusados = [];
            if ($definicao !== null) {
                $campos = FichaTecnicaDaCategoria::camposPorId(FichaTecnicaDaCategoria::doProduto($definicao, FichaTecnicaDoProduto::eixosDoProduto($produto)));
                $entradas = TradutorAnuncioMlParaPortal::entradasDaFicha($campos, $traducao['atributos']);
                if ($entradas !== []) {
                    try {
                        $f = $this->fichaTecnica->gravarParcial($empresa, $produto, $entradas, $ator);
                        $ficha['gravados'] = (int) $f['gravados'];
                        foreach ($f['erros'] as $id => $motivo) {
                            $recusados[] = ['id' => (string) $id, 'nome' => (string) ($campos[$id]['nome'] ?? $id), 'motivo' => (string) $motivo];
                        }
                        $ficha['recusados'] = count($recusados);
                    } catch (ValidationException $e) {
                        $avisos[] = 'A ficha técnica do anúncio não foi gravada: '.collect($e->errors())->flatten()->first();
                    }
                }
            }

            // A origem: o selo, a deduplicação (as duas portas) e os fatos para a IA. `atributos_ml` cru, como o
            // outro caminho grava: a jardinagem os relê.
            $origem = EstruturaProdutoOrigemMl::create([
                'company_id'      => $empresa->id,
                'produto_id'      => $produto->id,
                'ml_item_id'      => $mlb,
                'titulo_original' => mb_substr((string) ($item['title'] ?? ''), 0, 255),
                'retrato'         => $traducao['retrato'] + ['atributos_ml' => $traducao['atributos'], 'campos_recusados' => $recusados,
                    'avisos' => array_values(array_unique($avisos))],
                'importado_por'   => mb_substr($ator->descricao(), 0, 255),
                'importado_em'    => now(),
            ]);

            $this->cadastro->registrar($ator, $empresa, $produto, CadastroNaTrilha::PASSO_MERCADO_LIVRE, [
                'mlb'       => $mlb,
                'variacoes' => count($linhas),
                'ficha'     => $ficha['gravados'],
                'categoria' => $definicao !== null ? (string) $produto->categoria_ml_id : null,
            ]);

            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();

            return self::recusado((string) collect($e->errors())->flatten()->first());
        } catch (HttpResponseException $e) {
            // A régua das cores (422): as variações do anúncio não formam um grupo que o Publicador aceite.
            DB::rollBack();
            $corpo = (array) json_decode((string) $e->getResponse()->getContent(), true);
            $motivos = array_values(array_unique(array_map(fn ($x) => (string) ($x['mensagem'] ?? ''), (array) ($corpo['erros'] ?? []))));

            return self::recusado(trim((string) ($corpo['message'] ?? '').' '.implode(' ', array_filter($motivos))));
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("[Trilha] Importar do ML: o anúncio {$mlb} da empresa {$empresa->id} ({$empresa->name}) falhou ao gravar: {$e->getMessage()}");

            return self::erro(self::MSG_FALHA);
        }

        return ['produto' => $produto, 'origem' => $origem, 'ficha' => $ficha, 'avisos' => $avisos];
    }

    /**
     * As fotos baixadas, na galeria de cada variação (a 1ª de cada cor, quando há cor e tamanho — o tradutor já as pôs
     * só nela). Depois do commit; a variação cuja galeria não aceita vira aviso.
     *
     * @param  array<int, list<\Illuminate\Http\UploadedFile>>  $fotosPorLinha
     * @param  list<string>  $avisos
     * @return array{trazidas: int, falharam: int}
     */
    private function guardarFotos(Company $empresa, EstruturaProduto $produto, array $linhas, array $fotosPorLinha, AtorDoPortal $ator, array &$avisos): array
    {
        $variacoes = EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->where('produto_id', $produto->id)->get()
            ->keyBy(fn (EstruturaProdutoVariacao $v) => ProdutoCadastroService::chaveCodigo((string) $v->codigo));
        $saida = ['trazidas' => 0, 'falharam' => 0];

        foreach ($linhas as $i => $l) {
            $arquivos = $fotosPorLinha[$i] ?? [];
            $variacao = $variacoes->get(ProdutoCadastroService::chaveCodigo((string) $l['codigo']));
            if ($arquivos === [] || $variacao === null) {
                continue;
            }
            try {
                $this->imagens->enviar($empresa, $variacao, $arquivos, $ator);
                $saida['trazidas'] += count($arquivos);
            } catch (\Throwable $e) {
                $saida['falharam'] += count($arquivos);
                $avisos[] = sprintf(self::AVISO_FOTOS, $variacao->codigo, $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : 'falha ao guardar.');
                Log::warning("[Trilha] Importar do ML: fotos da variação {$variacao->id} falharam — empresa {$empresa->id}: {$e->getMessage()}");
            }
        }

        return $saida;
    }

    /**
     * D31 — a ficha pela IA, na hora: o que o anúncio deixou de fora e a IA no que continua vazio (só preenche vazio).
     * A queda vira o registro de erro, como o outro caminho grava.
     */
    private function fichaPelaIa(Company $empresa, EstruturaProduto $produto, EstruturaProdutoOrigemMl $origem): array
    {
        try {
            return app(JardinagemDaFicha::class)->completar($empresa, $produto->fresh() ?? $produto, $origem);
        } catch (\Throwable $e) {
            Log::warning("[Trilha] Importar do ML: a IA da ficha do produto {$produto->id} falhou — empresa {$empresa->id} ({$empresa->name}): {$e->getMessage()}");

            return ['erro' => 'A IA não respondeu agora.', 'em' => now()->toIso8601String()];
        }
    }

    /**
     * D32 — o título de venda pelo robô do Publicador, a partir do PRODUTO (nome, categoria, termos, ficha gravada,
     * marca, limite), nunca igual ao do anúncio original. UM título: o do Clássico (o do Premium só quando o do
     * Clássico não deu para diferenciar). Grava pela gaveta (`CadastroNaTrilha::gravar`, a 1ª variação). Null = não
     * escreveu (IA fora, termos fora, nada que não repetisse o original) — a equipe escreve na gaveta.
     */
    private function tituloPeloRobo(Company $empresa, EstruturaProduto $produto, string $tituloDoAnuncio, AtorDoPortal $ator): ?string
    {
        try {
            $produto = $produto->fresh() ?? $produto;
            $titulos = app(PalavrasChaveService::class)->gerarTitulosDoProduto($produto, $tituloDoAnuncio !== '' ? ['original' => $tituloDoAnuncio] : []);
            $titulo = trim((string) ($titulos['gold_special'] ?: $titulos['gold_pro']));
            if ($titulo === '') {
                return null;
            }

            $primeira = EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->where('produto_id', $produto->id)
                ->orderBy('ordem')->orderBy('id')->firstOrFail();
            $r = $this->cadastro->gravar($empresa, $produto, [[
                'id' => (int) $primeira->id, 'codigo' => (string) $primeira->codigo, 'nome' => (string) $produto->nome, 'titulo' => $titulo,
            ]], $ator, efeitos: false);
            if ($r['erros'] !== []) {
                Log::warning("[Trilha] Importar do ML: o título do produto {$produto->id} não gravou — empresa {$empresa->id}: ".json_encode($r['erros'], JSON_UNESCAPED_UNICODE));

                return null;
            }

            return (string) ($produto->fresh()?->titulo ?? $titulo);
        } catch (\Throwable $e) {
            Log::warning("[Trilha] Importar do ML: o robô não escreveu o título do produto {$produto->id} — empresa {$empresa->id} ({$empresa->name}): {$e->getMessage()}");

            return null;
        }
    }

    // ═══ Internos: o que já está na Lista ═══════════════════════════════════

    /**
     * Os anúncios que já estão na Lista: importado, publicado ou ligado no Mapeamento (`AnunciosNoPortal`, nesta
     * ordem de precedência) e, para os outros, o SKU do acervo igual a uma Ref da loja (sem chamar o ML).
     *
     * @param  list<string>  $mlbs
     * @param  array<string, list<string>>  $skusPorMlb  os `skus` do acervo
     * @return array<string, array{produto_id: int, nome: ?string, como: string, sku?: string}>
     */
    private function naLista(Company $empresa, array $mlbs, array $skusPorMlb): array
    {
        if ($mlbs === []) {
            return [];
        }

        $saida = [];
        foreach ($this->jaNaLista->daEmpresa($empresa, $mlbs) as $mlb => $a) {
            $saida[$mlb] = ['produto_id' => (int) $a['produto_id'], 'nome' => $a['nome'] ?? null, 'como' => self::COMO[$a['origem']] ?? 'importado'];
        }

        $faltam = array_diff_key($skusPorMlb, $saida);
        $todos = [];
        foreach ($faltam as $skus) {
            foreach ($skus as $s) {
                $todos[] = (string) $s;
            }
        }
        $refs = $this->refsDaEmpresa($empresa, $todos);
        foreach ($faltam as $mlb => $skus) {
            foreach ($skus as $s) {
                $r = $refs[ProdutoCadastroService::chaveCodigo((string) $s)] ?? null;
                if ($r !== null) {
                    $saida[$mlb] = ['produto_id' => $r['produto_id'], 'nome' => $r['nome'], 'como' => self::COMO_SKU, 'sku' => $r['sku']];
                    break;
                }
            }
        }

        return $saida;
    }

    /**
     * As Refs da loja que batem com estes SKUs (sem caixa, acento nem espaço nas pontas — a `chaveCodigo` do cadastro).
     * O banco acha os candidatos (no MariaDB a collation já ignora caixa e acento); o PHP decide pela chave. Uma
     * consulta.
     *
     * @param  list<string>  $skus
     * @return array<string, array{produto_id: int, nome: ?string, sku: string}>  chave do SKU → o produto dono
     */
    private function refsDaEmpresa(Company $empresa, array $skus): array
    {
        $chaves = [];
        $minusculas = [];
        foreach ($skus as $s) {
            $s = trim($s);
            if ($s !== '') {
                $chaves[ProdutoCadastroService::chaveCodigo($s)] = true;
                $minusculas[mb_strtolower($s)] = true;
            }
        }
        if ($chaves === []) {
            return [];
        }

        $saida = [];
        EstruturaProdutoVariacao::query()
            ->where('company_id', $empresa->id)
            ->where(function ($q) use ($minusculas) {
                foreach (array_keys($minusculas) as $m) {
                    $q->orWhereRaw('LOWER(codigo) = ?', [$m]);
                }
            })
            ->with('produto:id,nome')
            ->orderBy('id')
            ->get(['id', 'produto_id', 'codigo'])
            ->each(function (EstruturaProdutoVariacao $v) use (&$saida, $chaves) {
                $chave = ProdutoCadastroService::chaveCodigo((string) $v->codigo);
                if (isset($chaves[$chave])) {
                    $saida[$chave] ??= ['produto_id' => (int) $v->produto_id, 'nome' => $v->produto?->nome, 'sku' => (string) $v->codigo];
                }
            });

        return $saida;
    }

    /** O 1º SKU destes que tem anúncio na espera da loja (a régua do `varrerEspera`: `normalizarSku`), ou null. */
    private function naEspera(Company $empresa, array $codigos): ?string
    {
        $alvos = [];
        foreach ($codigos as $c) {
            $n = EstruturaOferta::normalizarSku($c);
            if ($n !== null) {
                $alvos[$n] = $c;
            }
        }
        if ($alvos === []) {
            return null;
        }

        foreach (EstruturaAnuncioEspera::query()->where('company_id', $empresa->id)->whereNotNull('sku_colado')->orderBy('id')->get(['id', 'sku_colado']) as $l) {
            $n = EstruturaOferta::normalizarSku($l->sku_colado);
            if ($n !== null && isset($alvos[$n])) {
                return (string) $alvos[$n];
            }
        }

        return null;
    }

    // ═══ Internos: o estado ═════════════════════════════════════════════════

    /** Um item do painel, campo a campo (contrato §18.4). */
    private static function item(string $mlb, array $s): array
    {
        $situacao = (string) ($s['situacao'] ?? self::ERRO);
        $doAnuncio = isset($s['titulo_do_anuncio']) ? (string) $s['titulo_do_anuncio'] : null;

        return [
            'mlb'               => $mlb,
            // O título de VENDA que o robô escreveu: nulo até ele escrever (só no `novo`, e só se a IA deu certo).
            'titulo'            => $situacao === self::NOVO && isset($s['titulo_de_venda']) ? (string) $s['titulo_de_venda'] : null,
            // O título do anúncio no ML: sempre, desde a fila.
            'titulo_do_anuncio' => $doAnuncio,
            'situacao'          => $situacao,
            'passo'             => $situacao === self::IMPORTANDO ? ($s['passo'] ?? null) : null,
            'produto_id'        => isset($s['produto_id']) ? (int) $s['produto_id'] : null,
            'nome'              => isset($s['nome']) ? (string) $s['nome'] : null,
            'codigo'            => isset($s['codigo']) ? (string) $s['codigo'] : null,
            'variacoes'         => (int) ($s['variacoes'] ?? 0),
            'fotos'             => ['trazidas' => (int) ($s['fotos']['trazidas'] ?? 0), 'falharam' => (int) ($s['fotos']['falharam'] ?? 0)],
            'ficha'             => ['gravados' => (int) ($s['ficha']['gravados'] ?? 0), 'recusados' => (int) ($s['ficha']['recusados'] ?? 0),
                'pela_ia' => (int) ($s['ficha']['pela_ia'] ?? 0)],
            'avisos'            => array_values(array_map('strval', (array) ($s['avisos'] ?? []))),
            'faltas'            => array_values(array_map('strval', (array) ($s['faltas'] ?? []))),
            'mensagem'          => $s === [] ? self::MSG_EXPIROU : (isset($s['mensagem']) ? (string) $s['mensagem'] : null),
        ];
    }

    /** @return array{situacao: string, mensagem: string} */
    private static function recusado(string $mensagem): array
    {
        return ['situacao' => self::RECUSADO, 'mensagem' => $mensagem];
    }

    /** @return array{situacao: string, mensagem: string} */
    private static function erro(string $mensagem): array
    {
        return ['situacao' => self::ERRO, 'mensagem' => $mensagem];
    }

    /** O Job escreve a situação do SEU anúncio (chave própria: dois Jobs nunca disputam a mesma). */
    private function marcar(Company $empresa, string $mlb, array $dados): void
    {
        Cache::put($this->chaveItem($empresa, $mlb), array_merge($this->situacao($empresa, $mlb), $dados), now()->addHours(self::VALIDADE_HORAS));
    }

    /** Recomeça o estado do anúncio (o pedido novo não herda avisos nem números da importação anterior). */
    private function recomecar(Company $empresa, string $mlb, array $dados): void
    {
        Cache::put($this->chaveItem($empresa, $mlb), $dados, now()->addHours(self::VALIDADE_HORAS));
    }

    private function situacao(Company $empresa, string $mlb): array
    {
        $s = Cache::get($this->chaveItem($empresa, $mlb));

        return is_array($s) ? $s : [];
    }

    /**
     * A situação na importação de cada anúncio da página que está no lote atual.
     *
     * @param  list<string>  $mlbs
     * @return array<string, string>
     */
    private function situacoes(Company $empresa, array $mlbs): array
    {
        if ($mlbs === []) {
            return [];
        }
        $chaves = array_map(fn (string $m) => $this->chaveItem($empresa, $m), $mlbs);
        $lidos = Cache::many($chaves);

        $saida = [];
        foreach ($mlbs as $i => $mlb) {
            $s = $lidos[$chaves[$i]] ?? null;
            if (is_array($s) && isset($s['situacao'])) {
                $saida[$mlb] = (string) $s['situacao'];
            }
        }

        return $saida;
    }

    private function chaveLote(Company $empresa): string
    {
        return 'trilha:ml:lote:'.$empresa->id;
    }

    private function chaveItem(Company $empresa, string $mlb): string
    {
        return 'trilha:ml:item:'.$empresa->id.':'.$mlb;
    }

    /** A hora da coleta do acervo em ISO 8601, no fuso da trilha. */
    private static function iso(mixed $quando): ?string
    {
        if ($quando === null || $quando === '') {
            return null;
        }
        try {
            return Carbon::parse($quando, config('app.timezone'))->setTimezone(TrilhaOnda::FUSO)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
