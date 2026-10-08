<?php

namespace App\Services\Publicador;

use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Support\Publicador\Imagem\ConversorParaJpg;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\Portal\ComposicaoDoPortal;
use App\Support\Publicador\Portal\PortalValorDeAtributo;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Schema\AtributoClassificado;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Schema\SchemaClassificado;
use App\Support\Publicador\Validacao\Problema;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Leva o produto do Portal para o rascunho do Publicador (Fase 172): categoria, ficha técnica,
 * pacote, uma variação por cor com o SKU e o estoque de cada uma.
 *
 * Mesma coreografia do `IaParaRascunhoService` — rascunho relido sob `lockForUpdate`,
 * "intocável" refeito a cada escrita, SEMPRE pelo motor (`EditorRascunhoService` /
 * `RascunhoRepository`, nunca SQL direto em `pub_*`) —, com o Portal como fonte.
 *
 * Regra de ouro (D-05): o Portal NUNCA sobrescreve. Só preenche o que está vazio: categoria sem
 * categoria, atributo sem valor, estoque nulo, SKU vazio (ou repetido, ou cópia do SKU do grupo).
 * Cor nova entra como variação nova; nada é removido. Rodar de novo não muda nada (a revisão
 * do rascunho só sobe quando algo foi gravado).
 *
 * D-10: vale para qualquer empresa (piloto ou não) e não escreve no ML — a única rede possível é
 * a leitura do schema da categoria (app token), a mesma que o editor já faz.
 *
 * Cobre o produto Simples AGRUPADO (`estrutura_produto_id`) e as ofertas compostas (Combo/Kit/Combit),
 * com as fotos de cada cor/componente copiadas pendentes (nada sobe ao ML).
 */
class PortalParaRascunhoService
{
    /** Eixo do Portal → atributo de variação do ML. Cor principal fica de fora (learnings §11). */
    private const EIXO_PARA_ML = [
        'cor' => 'COLOR',
        'tamanho' => 'SIZE',
        'voltagem' => 'VOLTAGE',
        'material' => 'MATERIAL',
        'sabor' => 'FLAVOR',
    ];

    private const AVISO_INTOCAVEL = 'O anúncio já estava publicado (ou em publicação); o Portal não mudou nada.';

    public function __construct(
        private EditorRascunhoService $editor,
        private RascunhoRepository $repo,
        private CategorySchemaRepository $schemas,
        private PortalProdutoLeitor $leitor,
        private ImagemAssetService $imagens,
    ) {}

    /**
     * @return array{produto_id: int, rascunho_id: ?int, intocavel: bool, variantes: int, campos_preenchidos: int, campos_mantidos: int, fotos_trazidas: int, fotos_nao_trazidas: array<string, int>, avisos: list<string>}
     */
    public function preencher(PubProduto $produto): array
    {
        $resumo = [
            'produto_id' => (int) $produto->id, 'rascunho_id' => null, 'intocavel' => false, 'variantes' => 0,
            'campos_preenchidos' => 0, 'campos_mantidos' => 0, 'fotos_trazidas' => 0, 'fotos_nao_trazidas' => [], 'avisos' => [],
        ];

        if ($produto->estrutura_produto_id === null) {
            return $produto->oferta_id !== null ? $this->preencherComposta($produto, $resumo) : $resumo;
        }
        $grupo = $this->leitor->doGrupo($produto);
        if ($grupo === null) {
            $resumo['avisos'][] = 'O produto do Portal não foi encontrado para esta empresa; nada foi preenchido.';

            return $resumo;
        }

        $r = $this->editor->rascunhoDoProduto($produto, false);
        $resumo['rascunho_id'] = (int) $r->id;
        if (IaParaRascunhoService::intocavel($r)) {
            $resumo['intocavel'] = true;
            $resumo['avisos'][] = self::AVISO_INTOCAVEL;

            return $resumo;
        }

        $avisos = &$resumo['avisos'];

        // ─── Categoria e schema (o schema é lido ANTES da trava: pode ir ao ML) ───
        $categoriaPortal = $this->categoriaDoPortal($grupo['categoria'] ?? null, $r, $avisos);
        $base = $this->aplicarCategoria($r, $categoriaPortal, $resumo);
        if ($base === null) {
            return $this->parou($resumo);
        }
        [$r, $schema] = $base;

        $plano = $this->planoDoEixo($grupo['variacoes'], $schema, $avisos);

        // ─── Ficha técnica e pacote — uma escrita, só o vazio ───
        $pacotes = array_map(
            fn (array $v) => LogisticaProduto::pacote($v['volumes']),
            array_values(array_filter($grupo['variacoes'], fn (array $v) => $v['volumes'] !== [])),
        );
        $doGrupo = ComposicaoDoPortal::pacoteDoGrupo($pacotes);
        if (! $this->aplicarFicha($r, $schema, $grupo['atributos'], $doGrupo['pacote'], $doGrupo['divergem'], $plano['chave'] ?? null, $resumo)) {
            return $this->parou($resumo);
        }

        // ─── Variações: eixo, uma variante por cor, SKU e estoque de cada uma ───
        $vivo = $this->sobTrava($r->id, function (PubRascunho $r) use ($grupo, $plano, $produto, &$resumo) {
            return $this->aplicarVariacoes($r, $produto, $grupo['variacoes'], $plano, $resumo['avisos'], $resumo['campos_preenchidos'], $resumo['campos_mantidos']);
        });
        if (! $vivo) {
            return $this->parou($resumo);
        }

        // ─── Fotos: cada cor leva as suas para o grupo da variação, só se o grupo estiver vazio ───
        if (! $this->trazerFotosDoGrupo($r, $produto, $schema, $grupo['variacoes'], $plano, $resumo)) {
            return $this->parou($resumo);
        }

        return $this->concluir($resumo, $produto, $r);
    }

    /** Fecha o resumo: conta as variantes vivas, tira aviso repetido e registra no log. */
    private function concluir(array $resumo, PubProduto $produto, PubRascunho $r): array
    {
        $resumo['variantes'] = count(array_filter($this->repo->snapshot($r->fresh())->variantes, fn (Variante $v) => ! $v->orfa));
        $resumo['avisos'] = array_values(array_unique($resumo['avisos']));

        Log::info("[Publicador] Portal -> rascunho: produto {$produto->id} ({$produto->nome}) rascunho {$r->id}: "
            ."{$resumo['campos_preenchidos']} preenchidos, {$resumo['campos_mantidos']} mantidos, {$resumo['variantes']} variante(s), "
            ."{$resumo['fotos_trazidas']} foto(s), ".count($resumo['avisos']).' aviso(s)');

        return $resumo;
    }

    // ═══ Oferta composta (Combo, Kit, Combit — D-07, D-12) ═══════════════════

    /**
     * Combo/Kit/Combit: variante única que herda do PRINCIPAL a categoria e a ficha; pacote, estoque e
     * fotos vêm de todos os componentes. Mesmas regras do grupo: só o vazio, nunca sobrescreve.
     */
    private function preencherComposta(PubProduto $produto, array $resumo): array
    {
        $composta = $this->leitor->daComposta($produto);
        if ($composta === null) {
            return $resumo;
        }
        $resumo['avisos'] = [...$resumo['avisos'], ...$composta['avisos']];
        $itens = $composta['itens'];
        if ($itens === []) {
            $resumo['avisos'][] = 'A composição não tem nenhum componente do Portal utilizável; nada foi preenchido.';
            $resumo['avisos'] = array_values(array_unique($resumo['avisos']));

            return $resumo;
        }

        $r = $this->editor->rascunhoDoProduto($produto, true);
        $resumo['rascunho_id'] = (int) $r->id;
        if (IaParaRascunhoService::intocavel($r)) {
            $resumo['intocavel'] = true;
            $resumo['avisos'][] = self::AVISO_INTOCAVEL;

            return $resumo;
        }

        $idxPrincipal = ComposicaoDoPortal::principal($itens, $composta['pares']);
        $principal = $itens[$idxPrincipal]['produto'];

        $categoriaPortal = $this->categoriaDoPortal($principal['categoria'] ?? null, $r, $resumo['avisos']);
        $base = $this->aplicarCategoria($r, $categoriaPortal, $resumo);
        if ($base === null) {
            return $this->parou($resumo);
        }
        [$r, $schema] = $base;

        $pacote = ComposicaoDoPortal::pacoteDoConjunto(array_map(fn (array $i) => [
            'produto_id' => $i['produto']['produto_id'], 'produto_nome' => $i['produto']['nome'], 'quantidade' => $i['quantidade'],
            'volumes' => $i['produto']['variacoes'][0]['volumes'] ?? [], 'custo' => $i['custo'],
        ], $itens));
        if (! $this->aplicarFicha($r, $schema, $principal['atributos'], $pacote, false, null, $resumo)) {
            return $this->parou($resumo);
        }

        // ─── Variante única: SKU da oferta e estoque do conjunto ───
        $estoque = ComposicaoDoPortal::estoque(array_map(fn (array $i) => [
            'estoque' => $i['produto']['variacoes'][0]['estoque'] ?? null, 'quantidade' => $i['quantidade'],
        ], $itens));
        $vivo = $this->sobTrava($r->id, function (PubRascunho $r) use ($composta, $estoque, $produto, &$resumo) {
            $snap = $this->repo->snapshot($r);
            $unica = collect($snap->variantes)->first(fn (Variante $v) => $v->chave === ChaveCanonica::UNICA);
            if ($snap->eixos !== [] || ! $unica) {
                return false;
            }
            $dados = $this->dadosDaVariante($unica, ['estoque' => $estoque, 'codigo' => (string) $composta['sku']], false, [], $produto, $resumo['campos_preenchidos'], $resumo['campos_mantidos']);
            if ($dados !== null) {
                $this->editor->salvarVariantes($r->fresh(), [ChaveCanonica::UNICA => $dados]);
            }

            return true;
        });
        if (! $vivo) {
            return $this->parou($resumo);
        }

        // ─── Fotos: a do principal primeiro, depois as dos outros componentes, no grupo geral ───
        $ordem = array_merge([$principal], array_map(fn (array $i) => $i['produto'], array_filter($itens, fn (int $k) => $k !== $idxPrincipal, ARRAY_FILTER_USE_KEY)));
        $fotos = [];
        foreach ($ordem as $p) {
            foreach ($p['variacoes'] as $v) {
                $fotos = [...$fotos, ...$this->imagensOrdenadas($v)];
            }
        }
        $snap = $this->repo->snapshot($r->fresh());
        $unica = collect($snap->variantes)->first(fn (Variante $v) => $v->chave === ChaveCanonica::UNICA);
        if ($unica && $snap->eixos === [] && ! $this->trazerFotos($r, $produto, $schema, [ResolvedorGruposImagem::GERAL => $fotos], 1, $resumo)) {
            return $this->parou($resumo);
        }

        return $this->concluir($resumo, $produto, $r);
    }

    // ═══ Categoria e ficha (o mesmo código para grupo e composta) ════════════

    /** A categoria que o Portal pede, ou null (com aviso quando a equipe também não tem uma). */
    private function categoriaDoPortal(?array $categoria, PubRascunho $r, array &$avisos): ?string
    {
        $id = $categoria['id'] ?? null;
        if ($id === null || trim((string) $id) === '') {
            if (! $r->categoria_id) {
                $avisos[] = 'A categoria do produto no Portal ainda não foi confirmada; defina a categoria no Publicador.';
            }

            return null;
        }
        if ($r->categoria_id && $r->categoria_id !== $id) {
            $avisos[] = "O rascunho já está na categoria {$r->categoria_id}; a categoria do Portal ({$id}) não foi aplicada.";
        }

        return (string) $id;
    }

    /**
     * Aplica a categoria (só se o rascunho não tem) e devolve o rascunho relido com o schema vigente.
     *
     * @return ?array{0: PubRascunho, 1: ?SchemaClassificado}  null = o rascunho ficou intocável
     */
    private function aplicarCategoria(PubRascunho $r, ?string $categoriaPortal, array &$resumo): ?array
    {
        $avisos = &$resumo['avisos'];
        $preenchidos = &$resumo['campos_preenchidos'];

        $erroSchema = $this->lerSchemas([$categoriaPortal, $r->categoria_id]);
        $vivo = $this->sobTrava($r->id, function (PubRascunho $r) use ($categoriaPortal, $erroSchema, &$avisos, &$preenchidos) {
            if ($r->categoria_id || $categoriaPortal === null) {
                return false;
            }
            if ($erroSchema !== null) {
                $avisos[] = 'A categoria do Portal não pôde ser aplicada: '.$erroSchema;

                return false;
            }
            try {
                $this->editor->trocarCategoria($r, $categoriaPortal);
            } catch (RegraViolada $e) {
                $avisos[] = 'A categoria do Portal não pôde ser aplicada: '.$e->getMessage();

                return false;
            }
            $preenchidos++;

            return true;
        });
        if (! $vivo) {
            return null;
        }

        // Fora da trava: pode ir ao ML. Dentro dela só vale se a categoria ainda for esta.
        $r = $r->fresh();
        $schema = $this->schemaDoRascunho($r);
        if ($r->categoria_id && $schema === null) {
            $avisos[] = 'Não foi possível ler a categoria no Mercado Livre agora; ficha, pacote e variações ficaram para depois.';
        }

        return [$r, $schema];
    }

    /**
     * Ficha técnica e pacote numa escrita só, só o vazio.
     *
     * @param  list<array>  $atributosPortal
     * @return bool false = o rascunho ficou intocável
     */
    private function aplicarFicha(PubRascunho $r, ?SchemaClassificado $schema, array $atributosPortal, ?array $pacote, bool $divergem, ?string $chaveEixo, array &$resumo): bool
    {
        $avisos = &$resumo['avisos'];
        $preenchidos = &$resumo['campos_preenchidos'];
        $mantidos = &$resumo['campos_mantidos'];

        return $this->sobTrava($r->id, function (PubRascunho $r) use ($schema, $atributosPortal, $pacote, $divergem, $chaveEixo, &$avisos, &$preenchidos, &$mantidos) {
            $schema = $this->schemaQueVale($schema, $r);
            if ($schema === null) {
                return false;
            }
            $snap = $this->repo->snapshot($r);

            $novos = [];
            foreach ($atributosPortal as $salvo) {
                $id = (string) ($salvo['id'] ?? '');
                $def = $schema->atributo($id);
                if ($def === null || $def->papel !== AtributoClassificado::PRODUCT || $id === $chaveEixo
                    || $def->secao === AtributoClassificado::SECAO_EMBALAGEM) {
                    continue;
                }
                if ($this->preenchido($snap->atributos[$id] ?? null) || isset($novos[$id])) {
                    $mantidos++;

                    continue;
                }
                $res = PortalValorDeAtributo::resolver($def, $salvo);
                if ($res['aviso'] !== null) {
                    $avisos[] = $res['aviso'];
                }
                if ($res['valor'] !== null) {
                    $novos[$id] = $res['valor'];
                }
            }

            if ($divergem) {
                $avisos[] = 'As cores têm pacotes diferentes no Portal; foi usado o de maior peso. Confira o card de envio.';
            }
            foreach (PortalValorDeAtributo::pacoteParaAtributos($pacote) as $id => $texto) {
                $def = $schema->atributo($id);
                if ($def === null || ! $this->aceitaUnidadeDoPacote($def, $id)) {
                    continue;
                }
                if ($this->preenchido($snap->atributos[$id] ?? null) || isset($novos[$id])) {
                    $mantidos++;

                    continue;
                }
                $novos[$id] = ['value_name' => $texto, 'origem' => 'portal', 'revisar' => $divergem];
            }

            if ($novos === []) {
                return false;
            }
            $this->repo->mesclarAtributos($r, $novos);
            $this->repo->tocar($r);
            $preenchidos += count($novos);

            return true;
        });
    }

    // ═══ Fotos (D-08, D-15) ══════════════════════════════════════════════════

    /** As fotos de uma variação do Portal na ordem dela. */
    private function imagensOrdenadas(array $variacao): array
    {
        $imagens = $variacao['imagens'] ?? [];
        usort($imagens, fn ($a, $b) => $a['ordem'] <=> $b['ordem']);

        return $imagens;
    }

    /**
     * Cada variante do rascunho recebe as fotos da cor do Portal no grupo dela. Se a categoria não
     * define foto por cor e há 2+ variantes (e nenhuma foto ainda), liga "fotos por variante" como a tela faz.
     */
    private function trazerFotosDoGrupo(PubRascunho $r, PubProduto $produto, ?SchemaClassificado $schema, array $variacoes, array $plano, array &$resumo): bool
    {
        $snap = $this->repo->snapshot($r->fresh());
        $vivas = array_values(array_filter($snap->variantes, fn (Variante $v) => ! $v->orfa));

        if (count($vivas) >= 2 && $snap->imagens === [] && ! $snap->fotosPorVariante
            && ! array_filter($snap->eixos, fn (Eixo $e) => $e->definesPicture)) {
            $this->editor->salvar($r, ['fotos_por_variante' => true]);
            $snap = $this->repo->snapshot($r->fresh());
        }

        $eixos = Eixo::ordenar($snap->eixos);
        $porGrupo = [];
        foreach ($vivas as $v) {
            $variacao = $this->variacaoDaVariante($v, $variacoes, $plano, $snap->eixos);
            if ($variacao === null) {
                continue;
            }
            $grupo = ResolvedorGruposImagem::chaveDoGrupo($v, $eixos, $snap->fotosPorVariante);
            $porGrupo[$grupo] ??= $this->imagensOrdenadas($variacao); // grupo repetido entre variantes: uma vez só
        }

        return $this->trazerFotos($r, $produto, $schema, $porGrupo, count($vivas), $resumo);
    }

    /** A variação do Portal (cor) que dá os dados desta variante, ou null (cor da equipe, sem correspondência). */
    private function variacaoDaVariante(Variante $v, array $variacoes, array $plano, array $eixos): ?array
    {
        if ($plano['chave'] === null) {
            return count($variacoes) === 1 && $v->chave === ChaveCanonica::UNICA ? $variacoes[0] : null;
        }
        $eixo = collect($eixos)->first(fn (Eixo $e) => $e->chave === $plano['chave'] || ChaveCanonica::texto($e->nome) === ChaveCanonica::texto($plano['nome']));
        $valor = $eixo !== null ? ($v->valores[$eixo->chave] ?? null) : null;
        if ($valor === null) {
            return null;
        }
        $cor = collect($plano['cores'])->first(fn ($c) => ChaveCanonica::texto($c['nome']) === ChaveCanonica::texto($valor->valueName)
            || ($c['id'] !== null && $c['id'] === $valor->valueId));

        return $cor['variacao'] ?? null;
    }

    /**
     * Copia as fotos do Portal para os grupos que ainda estão VAZIOS. Nada sobe ao ML (`enviar: false`):
     * as fotos ficam pendentes. O que não entra é contado com o motivo (nunca corte silencioso).
     *
     * @param  array<string, list<array>>  $porGrupo  grupo → fotos do Portal na ordem
     * @return bool false = o rascunho ficou intocável
     */
    private function trazerFotos(PubRascunho $r, PubProduto $produto, ?SchemaClassificado $schema, array $porGrupo, int $variantesAtivas, array &$resumo): bool
    {
        $limites = $schema?->limites ?? [];
        $limite = $variantesAtivas > 1
            ? ($limites['max_pictures_per_item_var'] ?? $limites['max_pictures_per_item'] ?? null)
            : ($limites['max_pictures_per_item'] ?? null);
        $limite = $limite === null ? null : (int) $limite;

        foreach ($porGrupo as $grupo => $fotos) {
            $atual = $r->fresh();
            if ($atual === null || IaParaRascunhoService::intocavel($atual)) {
                return false;
            }
            // Só grupo vazio (D-05): a equipe manda no que já tem foto.
            $jaTem = array_filter($this->repo->snapshot($atual)->imagens, fn ($a) => $a['grupo'] === (string) $grupo);
            if ($jaTem !== [] || $fotos === []) {
                continue;
            }

            $colocadas = 0;
            foreach ($fotos as $foto) {
                if ($limite !== null && $colocadas >= $limite) {
                    $this->naoTrouxe($resumo, 'acima_do_limite');

                    continue;
                }
                $motivo = $this->copiarFoto($atual, $produto, $foto, (string) $grupo);
                if ($motivo === null) {
                    $colocadas++;
                    $resumo['fotos_trazidas']++;
                } else {
                    $this->naoTrouxe($resumo, $motivo);
                }
            }
        }

        return true;
    }

    /** @return ?string null = a foto entrou no grupo; senão o motivo de não ter entrado */
    private function copiarFoto(PubRascunho $r, PubProduto $produto, array $foto, string $grupo): ?string
    {
        // A leitura do arquivo fica FORA da trava do rascunho; `colocarFotoNoGrupo` trava sozinho.
        $conteudo = $this->leitor->lerImagem((int) $produto->company_id, (string) $foto['caminho']);
        if ($conteudo === null) {
            return 'arquivo_sumido';
        }
        $convertida = ConversorParaJpg::converter($conteudo);
        if ($convertida['conteudo'] === null) {
            return 'formato';
        }

        $res = $this->imagens->receber($r, $convertida['conteudo'], (string) ($foto['nome_original'] ?? 'foto'), enviar: false);
        if ($res['imagem'] === null) {
            $codigos = array_map(fn (Problema $p) => $p->regra, array_filter($res['problemas'], fn (Problema $p) => $p->bloqueia()));

            return match (true) {
                in_array('V-IMG-03', $codigos, true) => 'pequena',
                in_array('V-IMG-02', $codigos, true) => 'arquivo_grande',
                default => 'formato',
            };
        }

        $this->editor->colocarFotoNoGrupo($r, $res['imagem'], $grupo);

        return null;
    }

    private function naoTrouxe(array &$resumo, string $motivo): void
    {
        $resumo['fotos_nao_trazidas'][$motivo] = ($resumo['fotos_nao_trazidas'][$motivo] ?? 0) + 1;
    }

    // ═══ Trava (mesma do IaParaRascunhoService) ══════════════════════════════

    /**
     * Toda escrita passa aqui: numa transação, o rascunho é relido com `lockForUpdate` e, se ficou
     * intocável (publicando/publicado), nada é gravado. O Portal nunca sobrescreve, então não há "substituir".
     *
     * @param  callable(PubRascunho): bool  $escrita
     * @return bool false = o rascunho ficou intocável (ou sumiu)
     */
    private function sobTrava(int $rascunhoId, callable $escrita): bool
    {
        return DB::transaction(function () use ($rascunhoId, $escrita) {
            $r = PubRascunho::whereKey($rascunhoId)->lockForUpdate()->first();
            if (! $r || IaParaRascunhoService::intocavel($r)) {
                return false;
            }
            $escrita($r);

            return true;
        });
    }

    private function parou(array $resumo): array
    {
        $resumo['intocavel'] = true;
        $resumo['avisos'][] = self::AVISO_INTOCAVEL;
        $resumo['avisos'] = array_values(array_unique($resumo['avisos']));

        return $resumo;
    }

    /** @param list<?string> $categorias @return ?string a mensagem, se alguma não pôde ser lida */
    private function lerSchemas(array $categorias): ?string
    {
        try {
            foreach (array_filter($categorias) as $c) {
                $this->schemas->obter($c);
            }

            return null;
        } catch (RegraViolada $e) {
            return $e->getMessage();
        }
    }

    private function schemaQueVale(?SchemaClassificado $schema, PubRascunho $r): ?SchemaClassificado
    {
        return $schema !== null && $schema->categoriaId === (string) $r->categoria_id ? $schema : null;
    }

    private function schemaDoRascunho(PubRascunho $r): ?SchemaClassificado
    {
        if (! $r->categoria_id) {
            return null;
        }
        try {
            $s = $this->repo->snapshot($r);
            $eixos = array_values(array_filter(array_map(fn (Eixo $e) => $e->attributeId(), $s->eixos)));

            return (new ClassificadorAtributos())->classificar($this->schemas->obter($r->categoria_id), new ContextoClassificacao($s->condicao, $eixos));
        } catch (RegraViolada) {
            return null;
        }
    }

    // ═══ Variações ═══════════════════════════════════════════════════════════

    /**
     * O eixo que o Portal pede, sem tocar no rascunho.
     *
     * @param  list<array>  $variacoes
     * @return array{chave: ?string, nome: ?string, definePicture: bool, cores: list<array>}  `chave` null = sem eixo
     */
    private function planoDoEixo(array $variacoes, ?SchemaClassificado $schema, array &$avisos): array
    {
        $vazio = ['chave' => null, 'nome' => null, 'definePicture' => false, 'cores' => []];
        if (count($variacoes) < 2) {
            return $vazio;
        }

        // Eixo dominante entre as variações que têm valor; empate = o que aparece primeiro.
        $contagem = [];
        foreach ($variacoes as $v) {
            if (trim((string) $v['valor']) !== '') {
                $contagem[$v['eixo'] ?: 'outro'] = ($contagem[$v['eixo'] ?: 'outro'] ?? 0) + 1;
            }
        }
        if ($contagem === []) {
            $avisos[] = 'As variações do produto no Portal não têm valor (ex.: nome da cor); nenhuma variação foi criada.';

            return $vazio;
        }
        arsort($contagem);
        $eixo = (string) array_key_first($contagem);

        $cores = [];
        foreach ($variacoes as $v) {
            $valor = trim((string) $v['valor']);
            if ($valor === '') {
                $avisos[] = 'Uma variação do Portal está sem valor e foi pulada.';

                continue;
            }
            if (($v['eixo'] ?: 'outro') !== $eixo) {
                $avisos[] = "A variação \"{$valor}\" usa outro tipo de variação no Portal e foi pulada.";

                continue;
            }
            $chaveTexto = ChaveCanonica::texto($valor);
            if (isset($cores[$chaveTexto])) {
                $avisos[] = "A variação \"{$valor}\" está repetida no Portal; só a primeira foi usada.";

                continue;
            }
            $cores[$chaveTexto] = $v + ['nome_valor' => $valor];
        }
        if (count($cores) < 2) {
            $avisos[] = 'O Portal tem menos de duas variações com valor; nenhuma variação foi criada.';

            return $vazio;
        }

        $rotulo = EstruturaProdutoVariacao::EIXOS[$eixo] ?? 'Outro';
        $idMl = self::EIXO_PARA_ML[$eixo] ?? null;
        $def = $idMl !== null ? $schema?->atributo($idMl) : null;
        $usaSchema = $def !== null && $def->podeSerEixo;

        $valores = [];
        foreach ($cores as $cor) {
            $id = null;
            $nome = $cor['nome_valor'];
            if ($usaSchema) {
                foreach ($def->valores as $opcao) {
                    if (ChaveCanonica::texto((string) $opcao['name']) === ChaveCanonica::texto($nome)) {
                        $id = (string) $opcao['id'];
                        $nome = (string) $opcao['name'];
                        break;
                    }
                }
            }
            $valores[] = ['id' => $id, 'nome' => $nome, 'variacao' => $cor];
        }

        return [
            'chave' => $usaSchema ? $idMl : ChaveCanonica::EIXO_CUSTOM,
            'nome' => $usaSchema ? ($def->nome !== '' ? $def->nome : $rotulo) : $rotulo,
            'definePicture' => $usaSchema && $def->definePicture,
            'cores' => $valores,
        ];
    }

    /**
     * Eixos, variantes, SKU e estoque — dentro da trava. Um único gravador de variantes por execução.
     *
     * @param  list<array>  $variacoes
     */
    private function aplicarVariacoes(PubRascunho $r, PubProduto $produto, array $variacoes, array $plano, array &$avisos, int &$preenchidos, int &$mantidos): bool
    {
        $snap = $this->repo->snapshot($r);

        // ── Produto de uma só variação (ou sem variação útil): a variante única recebe SKU e estoque ──
        if ($plano['chave'] === null) {
            if (count($variacoes) !== 1 || $snap->eixos !== []) {
                return false;
            }
            $unica = collect($snap->variantes)->first(fn (Variante $v) => $v->chave === ChaveCanonica::UNICA);
            if (! $unica) {
                return false;
            }
            $porChave = $this->dadosDaVariante($unica, $variacoes[0], false, [], $produto, $preenchidos, $mantidos);

            return $this->gravarVariantes($r, $porChave !== null ? [ChaveCanonica::UNICA => $porChave] : []);
        }

        // ── Eixo ──
        $chaveEixo = $plano['chave'];
        $existente = collect($snap->eixos)->first(fn (Eixo $e) => $e->chave === $chaveEixo
            || ChaveCanonica::texto($e->nome) === ChaveCanonica::texto($plano['nome']));

        if ($snap->eixos === []) {
            $ancora = $this->corAncora($plano['cores'], $produto);
            $eixoPortal = fn (array $valores) => [[
                'chave' => $chaveEixo, 'nome' => $plano['nome'], 'defines_picture' => $plano['definePicture'],
                'valores' => array_map(fn ($c) => ['id' => $c['id'], 'nome' => $c['nome']], $valores),
            ]];
            try {
                // Dois passos: a variante única (com os dados que já tiver) passa para a cor âncora; as demais nascem depois.
                $this->editor->salvarEixos($r, $eixoPortal([$ancora]));
                $this->editor->salvarEixos($r, $eixoPortal($plano['cores']));
            } catch (RegraViolada $e) {
                $avisos[] = 'As variações do Portal não puderam ser criadas: '.$e->getMessage();

                return false;
            }
            $chaveReal = $chaveEixo;
        } elseif ($existente !== null && count($snap->eixos) === 1) {
            $chaveReal = $existente->chave;
            $falta = [];
            foreach ($plano['cores'] as $cor) {
                $achou = collect($existente->valores)->contains(fn ($x) => ChaveCanonica::texto($x->valueName) === ChaveCanonica::texto($cor['nome'])
                    || ($cor['id'] !== null && $x->valueId === $cor['id']));
                if (! $achou) {
                    $falta[] = ['id' => $cor['id'], 'nome' => $cor['nome']];
                }
            }
            if ($falta !== []) {
                $atuais = array_map(fn ($x) => ['id' => $x->valueId, 'nome' => $x->valueName], $existente->valores);
                try {
                    $this->editor->salvarEixos($r, [[
                        'chave' => $existente->chave, 'nome' => $existente->nome, 'defines_picture' => $existente->definesPicture,
                        'valores' => [...$atuais, ...$falta],
                    ]]);
                } catch (RegraViolada $e) {
                    $avisos[] = 'As cores novas do Portal não puderam ser acrescentadas: '.$e->getMessage();

                    return false;
                }
            }
        } else {
            $avisos[] = 'O rascunho já tem variações próprias (outro tipo de variação); o Portal não acrescentou cores.';

            return false;
        }

        // ── Variantes: SKU e estoque de cada cor, relidos sob a trava ──
        $snap = $this->repo->snapshot($r->fresh());
        $skus = [];
        foreach ($snap->variantes as $v) {
            $sku = trim((string) ($v->dados['atributos']['SELLER_SKU']['value_name'] ?? ''));
            if ($sku !== '') {
                $skus[$sku] ??= $v->chave; // o 1º a ter o SKU fica com ele; as cópias seguintes contam como vazias
            }
        }

        $porChave = [];
        foreach ($snap->variantes as $v) {
            $valor = $v->valores[$chaveReal] ?? null;
            if ($v->orfa || $valor === null) {
                continue;
            }
            $cor = collect($plano['cores'])->first(fn ($c) => ChaveCanonica::texto($c['nome']) === ChaveCanonica::texto($valor->valueName)
                || ($c['id'] !== null && $c['id'] === $valor->valueId));
            if ($cor === null) {
                continue; // cor que a equipe criou no rascunho e o Portal não tem
            }
            $dados = $this->dadosDaVariante($v, $cor['variacao'], true, $skus, $produto, $preenchidos, $mantidos);
            if ($dados !== null) {
                $porChave[$v->chave] = $dados;
            }
        }

        return $this->gravarVariantes($r, $porChave);
    }

    /** @param array<string, array> $porChave */
    private function gravarVariantes(PubRascunho $r, array $porChave): bool
    {
        if ($porChave === []) {
            return false;
        }
        $this->editor->salvarVariantes($r->fresh(), $porChave);

        return true;
    }

    /**
     * Só o que mudou na variante, ou null. Estoque: só se ainda é nulo. SKU: só se vazio, se se repete
     * em outra variante ou (várias cores) se é a cópia do SKU do grupo que o ancestral deixou.
     *
     * @param  array<string, string>  $skus  SKU → chave da 1ª variante que o tem hoje
     */
    private function dadosDaVariante(Variante $v, array $variacao, bool $varias, array $skus, PubProduto $produto, int &$preenchidos, int &$mantidos): ?array
    {
        $novo = [];

        if (($v->dados['estoque'] ?? null) !== null) {
            $mantidos++;
        } elseif ($variacao['estoque'] !== null) {
            $novo['estoque'] = max(0, (int) $variacao['estoque']);
            $preenchidos++;
        }

        $codigo = trim((string) $variacao['codigo']);
        $atual = trim((string) ($v->dados['atributos']['SELLER_SKU']['value_name'] ?? ''));
        $troca = $atual === '' || ($varias && ((isset($skus[$atual]) && $skus[$atual] !== $v->chave) || $atual === trim($produto->skuExibido())));
        if ($atual !== '' && ! $troca) {
            $mantidos++;
        } elseif ($codigo !== '' && $codigo !== $atual && $troca) {
            $novo['atributos'] = (array) ($v->dados['atributos'] ?? []);
            $novo['atributos']['SELLER_SKU'] = ['value_name' => $codigo];
            $preenchidos++;
        }

        return $novo === [] ? null : $novo;
    }

    /** A cor que herda os dados da variante única: a da oferta do produto, senão a de menor ordem. */
    private function corAncora(array $cores, PubProduto $produto): array
    {
        if ($produto->oferta_id !== null) {
            foreach ($cores as $c) {
                if ($c['variacao']['oferta_id'] === $produto->oferta_id) {
                    return $c;
                }
            }
        }

        return $cores[0];
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /** SELLER_PACKAGE_* só recebe cm (medidas) ou g (peso); unidade que o schema não aceita fica de fora. */
    private function aceitaUnidadeDoPacote(AtributoClassificado $def, string $id): bool
    {
        if ($def->unidades === []) {
            return true;
        }
        $quer = $id === 'SELLER_PACKAGE_WEIGHT' ? 'g' : 'cm';

        return in_array($quer, array_map('mb_strtolower', $def->unidades), true);
    }

    private function preenchido(?array $valor): bool
    {
        return $valor !== null && (trim((string) ($valor['value_id'] ?? '')) !== ''
            || trim((string) ($valor['value_name'] ?? '')) !== ''
            || isset($valor['value_number']));
    }
}
