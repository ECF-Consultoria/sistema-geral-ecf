<?php

namespace Tests\Feature\Publicador\Concerns;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\MlbEmpresa;
use App\Models\MlCategoriaSchema;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Portal\Estrutura\Geracao\NomesSugeridos;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\RegeneradorVariantes;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;

/**
 * O cenário da #459 do relato de 09/10/2026, montado sem o Mercado Livre: um produto do Portal de
 * TRÊS cores (cada uma com a sua oferta Simples), agrupado no Publicador (UM `pub_produto` com o
 * rascunho de três variantes, eixo COLOR) e, quando o teste pede, as três ofertas Combo 2 — uma por
 * cor — como o "Aceitar" do Planejamento as cria (`EstruturaOfertaService::criar`, SKU `{cor}-CB2`).
 *
 * O kit "antigo" é o que o "Criar Fase N" de antes de 09/10 deixava: `produto_base_id` no grupo,
 * `oferta_id` nulo e SKU `-KIT{N}` em cada cor, sem nenhuma oferta no Portal.
 */
trait CenarioPlanejamentoDaFase
{
    /** cor → [SKU da variação no Portal, value_id da cor no ML] */
    protected const CORES_DO_CENARIO = ['Preto' => ['CAD-PT', '52049'], 'Azul' => ['CAD-AZ', '52028'], 'Branco' => ['CAD-BR', '52055']];

    protected Company $empresaP;

    protected MlbEmpresa $mlbP;

    protected User $equipeP;

    protected EstruturaProduto $produtoP;

    /** @var array<string, EstruturaProdutoVariacao> cor → variação */
    protected array $variacoesP = [];

    /** @var array<string, EstruturaOferta> cor → oferta Simples */
    protected array $simplesP = [];

    protected function repoP(): RascunhoRepository
    {
        return new RascunhoRepository();
    }

    /** Empresa com Portal, MlbEmpresa de Polos, token e o produto de três cores com as ofertas Simples. */
    protected function montarPlanejamento(?Company $empresa = null, string $codigo = 'CAD'): void
    {
        $this->empresaP = $empresa ?? Company::factory()->create();
        $this->mlbP = MlbEmpresa::create(['nome' => 'Polo das Cadeiras', 'projeto' => 'POLOS', 'company_id' => $this->empresaP->id])->fresh();
        MlToken::create([
            'company_id' => $this->empresaP->id, 'ml_user_id' => '1555596317', 'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token', 'token_type' => 'bearer', 'expires_at' => now()->addHours(5),
            'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now(),
        ]);
        $this->equipeP = User::factory()->create(['role' => 'admin', 'name' => 'Dev ECF']);

        $this->produtoP = EstruturaProduto::create(['company_id' => $this->empresaP->id, 'codigo' => $codigo, 'nome' => 'Cadeira Jantar']);
        $this->variacoesP = [];
        $this->simplesP = [];
        $ordem = 0;
        foreach (self::CORES_DO_CENARIO as $cor => [$sku]) {
            $sku = str_replace('CAD', $codigo, $sku);
            $v = EstruturaProdutoVariacao::create([
                'produto_id' => $this->produtoP->id, 'company_id' => $this->empresaP->id, 'ordem' => $ordem++,
                'codigo' => $sku, 'eixo' => 'cor', 'valor' => $cor, 'custo' => 100, 'estoque' => 9,
            ]);
            $this->variacoesP[$cor] = $v;
            $this->simplesP[$cor] = EstruturaOferta::create([
                'company_id' => $this->empresaP->id, 'variacao_id' => $v->id, 'sku' => $sku, 'fase' => 'simples', 'nome' => "Cadeira Jantar — {$cor}",
            ]);
        }

        // O schema da cadeira já guardado: a prévia lê o `max_title_length` sem falar com o ML.
        if (! MlCategoriaSchema::whereKey('MLB193945')->exists()) {
            MlCategoriaSchema::create([
                'category_id' => 'MLB193945',
                'categoria' => ['id' => 'MLB193945', 'name' => 'Cadeiras', 'settings' => ['max_title_length' => 60],
                    'path_from_root' => [['id' => 'MLB193945', 'name' => 'Cadeiras']]],
                'atributos' => [], 'technical_specs' => [], 'sale_terms' => [],
                'schema_hash' => str_repeat('a', 64), 'fetched_at' => now(),
            ]);
        }
    }

    /**
     * As ofertas Combo N de cada cor, como o "Aceitar" do Planejamento as cria.
     *
     * @param  list<string>|null  $cores  null = as três
     * @return array<string, EstruturaOferta> cor → a oferta Combo
     */
    protected function aceitarCombos(int $n, ?array $cores = null): array
    {
        $ator = AtorDoPortal::daEquipe($this->equipeP);
        $saida = [];
        foreach ($cores ?? array_keys(self::CORES_DO_CENARIO) as $cor) {
            $simples = $this->simplesP[$cor];
            $nomeado = NomesSugeridos::combo('Cadeira Jantar', $simples->sku, $cor, $n, null);
            [$saida[$cor]] = app(EstruturaOfertaService::class)->criar($this->empresaP, [
                'sku' => $nomeado['sku'], 'fase' => 'combo', 'nome' => $nomeado['nome'],
                'componentes' => [['id' => $simples->id, 'quantidade' => $n]],
            ], $ator, varrerEspera: false);
        }

        return $saida;
    }

    /** O `pub_produto` do grupo (D-06), ancorado na oferta da 1ª cor. */
    protected function grupoP(): PubProduto
    {
        return PubProduto::firstOrCreate(['estrutura_produto_id' => $this->produtoP->id], [
            'oferta_id' => $this->simplesP['Preto']->id, 'company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id,
            'sku' => $this->produtoP->codigo, 'nome' => 'Cadeira Jantar', 'origem' => PubProduto::ORIGEM_PORTAL,
        ]);
    }

    /**
     * O grupo com o rascunho de três cores (eixo COLOR), SKU de cada cor = o do Portal, estoque e os dois
     * tipos de anúncio — por padrão já PUBLICADO (o "Criar Fase N" exige a Fase 1 no ar).
     */
    protected function grupoComRascunho(string $status = PubRascunho::PUBLISHED, int $estoque = 10): PubProduto
    {
        $grupo = $this->grupoP();
        $skus = [];
        foreach (array_keys(self::CORES_DO_CENARIO) as $cor) {
            $skus[$cor] = $this->simplesP[$cor]->sku;
        }
        $this->rascunhoDeCores($grupo, $skus, $estoque);
        PubRascunho::where('produto_id', $grupo->id)->update(['status' => $status, 'categoria_id' => 'MLB193945', 'descricao' => 'Cadeira de jantar.']);

        return $grupo->fresh(['rascunho']);
    }

    /**
     * Um kit como o "Criar Fase N" de antes de 09/10 deixava: sem oferta, SKU `-KIT{N}` em cada cor.
     *
     * @param  array<string, ?string>|null  $skus  cor → SELLER_SKU (null = sem SKU); null = `{cor}-KIT{N}`
     */
    protected function kitAntigo(PubProduto $grupo, int $n, ?array $skus = null): PubProduto
    {
        $kit = PubProduto::create([
            'company_id' => $grupo->company_id, 'mlb_empresa_id' => $grupo->mlb_empresa_id, 'oferta_id' => null,
            'sku' => $grupo->sku."-KIT{$n}", 'nome' => "Kit {$n} Cadeira Jantar", 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $grupo->id, 'quantidade_kit' => $n, 'fase' => PubProduto::faseDaQuantidade($n),
            'estoque_calculado' => true,
        ]);
        if ($skus === null) {
            $skus = [];
            foreach (array_keys(self::CORES_DO_CENARIO) as $cor) {
                $skus[$cor] = $this->simplesP[$cor]->sku."-KIT{$n}";
            }
        }
        $this->rascunhoDeCores($kit, $skus, null);

        return $kit->fresh(['rascunho']);
    }

    /** @param array<string, ?string> $skus cor → SELLER_SKU */
    protected function rascunhoDeCores(PubProduto $produto, array $skus, ?int $estoque): PubRascunho
    {
        $repo = $this->repoP();
        $r = PubRascunho::where('produto_id', $produto->id)->first()
            ?? $repo->criar($produto, [new Alvo('gold_special', 'Cadeira Jantar Clássico'), new Alvo('gold_pro', 'Cadeira Jantar Premium')]);

        $eixo = new Eixo('COLOR', 'Cor', 0, true, array_map(
            fn (string $cor) => new ValorEixo(self::CORES_DO_CENARIO[$cor][1] ?? null, $cor),
            array_keys($skus),
        ));
        $regen = RegeneradorVariantes::regenerar($repo->snapshot($r->fresh())->variantes, [$eixo]);
        $repo->gravarVariacao($r->fresh(), [$eixo], $regen->variantes);

        $s = $repo->snapshot($r->fresh());
        $variantes = array_map(function (Variante $v) use ($skus, $estoque) {
            $cor = (string) ($v->valores['COLOR']->valueName ?? '');
            $sku = $skus[$cor] ?? null;
            $dados = [...$v->dados, 'estoque' => $estoque, 'atributos' => $sku === null ? [] : ['SELLER_SKU' => ['value_name' => $sku]]];

            return $v->comDados($dados);
        }, $s->variantes);
        $repo->gravarVariacao($r->fresh(), $s->eixos, $variantes);

        return $r->fresh();
    }

    /** O SELLER_SKU da variante da cor no rascunho do produto (null = sem SKU ou sem a cor). */
    protected function skuDaCor(PubProduto $produto, string $cor): ?string
    {
        $r = PubRascunho::where('produto_id', $produto->id)->firstOrFail();
        foreach ($this->repoP()->snapshot($r)->variantes as $v) {
            if (($v->valores['COLOR']->valueName ?? null) === $cor) {
                $sku = $v->dados['atributos']['SELLER_SKU']['value_name'] ?? null;

                return $sku === null ? null : (string) $sku;
            }
        }

        return null;
    }

    /** A Precificação do Portal simulada: SKU da oferta (sem caixa) → preço anunciado do Clássico e do Premium. */
    protected function precificacaoPorSku(array $porSku): void
    {
        $this->mock(EstruturaPrecificacaoService::class, function ($m) use ($porSku) {
            $m->shouldReceive('pagina')->andReturnUsing(function ($empresa, array $ids) use ($porSku) {
                $por = [];
                foreach (EstruturaOferta::query()->where('company_id', $empresa->id)->whereIn('id', $ids)->get() as $o) {
                    $preco = $porSku[mb_strtolower($o->sku)] ?? null;
                    $por[$o->id] = $preco === null ? [] : ['classico' => ['anunciado' => $preco], 'premium' => ['anunciado' => $preco + 20]];
                }

                return ['por_oferta' => $por];
            });
        });
    }
}
