<?php

namespace Tests\Feature\Trilha\Concerns;

use App\Models\Company;
use App\Models\EstruturaAmbiente;
use App\Models\EstruturaFamilia;
use App\Models\EstruturaOferta;
use App\Models\EstruturaOfertaComponente;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\EstruturaProdutoVolume;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use Illuminate\Support\Str;

/**
 * Dados da trilha montados pelos models (sem os serviços do Portal), para os testes da 2ª entrega: um produto
 * COMPLETO pela régua da lista, ofertas compostas, o grupo no Publicador com rascunho e as ondas.
 * Os nomes evitam as palavras dos tipos de produto para o gerador de sugestões não sugerir nada sozinho.
 */
trait MontaTrilha
{
    /** `$id`: a empresa com o id escolhido (a 4d só publica na 459 — `PublicacaoNaTrilha::LOJA`). */
    protected function empresaDaTrilha(bool $trabalho = true, ?int $id = null): Company
    {
        $dados = [
            'name'   => 'Loja da Trilha '.uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ];
        $empresa = $id !== null ? Company::query()->forceCreate([...$dados, 'id' => $id]) : Company::create($dados);
        config(['trilha.empresas' => [...(array) config('trilha.empresas', []), $empresa->id]]);
        if ($trabalho) {
            config(['trilha.trabalho.empresas' => [...(array) config('trilha.trabalho.empresas', []), $empresa->id]]);
        }

        return $empresa;
    }

    /**
     * Produto completo, uma oferta simples por cor (como o cadastro do Portal cria). `$cores` com `null` = sem eixo.
     *
     * @param  list<?string>  $cores
     * @return array{produto: EstruturaProduto, variacoes: list<EstruturaProdutoVariacao>, simples: list<EstruturaOferta>}
     */
    protected function produtoCompleto(Company $empresa, string $nome, string $codigo, array $cores = [null], array $dados = []): array
    {
        $familia = EstruturaFamilia::firstOrCreate(['company_id' => $empresa->id, 'nome' => 'Decoração']);
        $produto = EstruturaProduto::create(array_merge([
            'company_id' => $empresa->id, 'codigo' => $codigo, 'nome' => $nome, 'titulo' => "Título de venda {$nome}",
            'familia_id' => $familia->id, 'categoria_ml_id' => 'MLB100200', 'categoria_ml_nome' => 'Objetos de decoração',
            'descricao' => 'Uma descrição do produto.',
        ], $dados));
        $produto->ambientes()->attach(EstruturaAmbiente::firstOrCreate(['company_id' => $empresa->id, 'nome' => 'Sala de estar'])->id);

        $variacoes = [];
        $simples = [];
        foreach (array_values($cores) as $i => $cor) {
            $v = EstruturaProdutoVariacao::create([
                'produto_id' => $produto->id, 'company_id' => $empresa->id, 'ordem' => $i + 1,
                'codigo' => count($cores) > 1 ? "{$codigo}-".($i + 1) : $codigo,
                'eixo' => $cor !== null ? 'cor' : null, 'valor' => $cor, 'custo' => 100 + 10 * $i,
            ]);
            EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 1, 'comprimento' => 50, 'largura' => 40, 'altura' => 30, 'peso' => 5]);
            for ($f = 0; $f < 3; $f++) {
                EstruturaProdutoVariacaoImagem::create([
                    'company_id' => $empresa->id, 'produto_id' => $produto->id, 'variacao_id' => $v->id,
                    'caminho' => 'estrutura/'.Str::random(8).'.jpg', 'nome_original' => "foto{$f}.jpg", 'mime' => 'image/jpeg',
                    'tamanho' => 1000, 'ordem' => $f,
                ]);
            }
            $variacoes[] = $v;
            $simples[] = EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => $v->id, 'sku' => $v->codigo,
                'fase' => EstruturaOferta::FASE_SIMPLES, 'nome' => trim("{$nome} {$cor}")]);
        }

        return ['produto' => $produto->fresh(), 'variacoes' => $variacoes, 'simples' => $simples];
    }

    /** @param  list<array{0: EstruturaOferta, 1: int}>  $componentes */
    protected function ofertaComposta(Company $empresa, string $fase, string $sku, string $nome, array $componentes): EstruturaOferta
    {
        $oferta = EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => null, 'sku' => $sku, 'fase' => $fase, 'nome' => $nome]);
        foreach ($componentes as [$componente, $quantidade]) {
            EstruturaOfertaComponente::create(['oferta_id' => $oferta->id, 'componente_id' => $componente->id, 'quantidade' => $quantidade]);
        }

        return $oferta;
    }

    /** O pub_produto do grupo das cores, como o Sincronizar cria (ancorado na oferta da 1ª cor). */
    protected function grupoNoPublicador(Company $empresa, array $p): PubProduto
    {
        return PubProduto::create(['company_id' => $empresa->id, 'oferta_id' => $p['simples'][0]->id, 'estrutura_produto_id' => $p['produto']->id,
            'sku' => (string) $p['produto']->codigo, 'nome' => $p['produto']->nome, 'origem' => PubProduto::ORIGEM_PORTAL]);
    }

    protected function ofertaNoPublicador(Company $empresa, EstruturaOferta $oferta): PubProduto
    {
        return PubProduto::create(['company_id' => $empresa->id, 'oferta_id' => $oferta->id, 'sku' => (string) $oferta->sku,
            'nome' => (string) $oferta->nome, 'origem' => PubProduto::ORIGEM_PORTAL]);
    }

    protected function rascunhoDoPub(PubProduto $pub, string $status = PubRascunho::DRAFT, array $stepState = []): PubRascunho
    {
        return PubRascunho::create(['produto_id' => $pub->id, 'status' => $status, 'revisao' => 1, 'step_state' => $stepState]);
    }

    protected function onda(Company $empresa, string $data, string $nome = 'Onda', ?string $liberadaEm = null): TrilhaOnda
    {
        return TrilhaOnda::create(['company_id' => $empresa->id, 'nome' => $nome, 'data' => $data, 'liberada_em' => $liberadaEm]);
    }

    /** Põe o item (`s-12`, `c-12-2`, `o-55`) na onda, direto no banco. */
    protected function itemNaOnda(TrilhaOnda $onda, string $chave, ?string $pedidoEm = null): TrilhaOndaItem
    {
        return TrilhaOndaItem::create([...TrilhaOndaItem::colunasDaChave($chave), 'company_id' => $onda->company_id,
            'onda_id' => $onda->id, 'pedido_em' => $pedidoEm]);
    }

    protected function itemDaTrilha(array $trilha, string $id): ?array
    {
        foreach ($trilha['ofertas'] as $o) {
            if ($o['id'] === $id) {
                return $o;
            }
        }

        return null;
    }

    protected function produtoNaTrilha(array $trilha, int $id): ?array
    {
        foreach ($trilha['produtos'] as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }

        return null;
    }
}
