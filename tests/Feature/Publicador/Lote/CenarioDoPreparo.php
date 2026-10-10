<?php

namespace Tests\Feature\Publicador\Lote;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Models\MlCategoriaSchema;
use App\Models\PubFilaPublicacao;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Incubadora\Publicador\TermosMaisBuscadosService;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * O cenário do preparo pela IA ao salvar no Portal (o do `PreparoIaAoSalvarNoPortalTest`, enxuto): a cadeira
 * MLB193945 com o schema real guardado, a IA e os termos como dublês, o ML bloqueado e a fila `Queue::fake()`
 * rodada à mão, cadeia incluída.
 */
trait CenarioDoPreparo
{
    use CarregaSchemas;

    protected Company $empresa;

    private \SplObjectStorage $rodados;

    protected function montarPreparo(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        Cache::flush();
        $this->rodados = new \SplObjectStorage();

        $schema = self::schema(self::CADEIRA);
        MlCategoriaSchema::create(['category_id' => self::CADEIRA, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
            'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
            'schema_hash' => $schema->hash(), 'fetched_at' => now()]);

        $this->mock(AnaliseAnuncioService::class, function ($m) {
            $m->shouldReceive('comPrazo')->andReturnSelf();
            $m->shouldReceive('titulosPorTermos')->andReturn(['dados' => ['classico' => 'Cadeira Escritório Giratória Ergonômica', 'premium' => 'Cadeira Giratória Escritório Ergonômica'], 'meta' => []]);
            $m->shouldReceive('modeloPorTermos')->andReturn(['dados' => 'cadeira home office, cadeira ergonomica', 'meta' => []]);
            $m->shouldReceive('analise')->andReturn(['dados' => ['jtbd' => 'trabalhar sentado', 'puv' => 'conforto'], 'meta' => []]);
            $m->shouldReceive('descricao')->andReturn(['dados' => '<p>Cadeira confortável para o dia todo.</p>', 'meta' => []]);
        });
        $this->mock(TermosMaisBuscadosService::class, fn ($m) => $m->shouldReceive('termos')->andReturn(['termos' => [
            ['termo' => 'cadeira escritorio', 'posicao' => 1, 'relacionado' => true],
        ]]));

        $this->empresa = Company::factory()->create();
    }

    /** A cadeira do Portal (uma cor) com a ficha completa e a descrição do cliente. */
    protected function produtoDoPortal(string $codigo = 'CAD'): EstruturaProduto
    {
        $p = EstruturaProduto::create(['company_id' => $this->empresa->id, 'codigo' => $codigo, 'nome' => 'Cadeira Executiva',
            'categoria_ml_id' => self::CADEIRA, 'categoria_ml_nome' => 'Cadeiras de Escritório',
            'descricao' => 'Cadeira com encosto em tela, ótima para home office.']);
        $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresa->id, 'ordem' => 0,
            'codigo' => "{$codigo}-AZ", 'eixo' => 'cor', 'valor' => 'Azul', 'custo' => 100, 'estoque' => 5]);
        EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 60, 'largura' => 60, 'altura' => 40, 'peso' => 12]);
        EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $v->id, 'sku' => "{$codigo}-AZ", 'fase' => 'simples', 'nome' => 'Cadeira Executiva — Azul']);

        $grupos = FichaTecnicaDaCategoria::doProduto(FichaTecnicaDaCategoria::daAtributos(self::schema(self::CADEIRA)->atributos), ['cor']);
        foreach (FichaTecnicaDaCategoria::camposPorId($grupos) as $id => $c) {
            if (! $c['obrigatorio'] || $id === 'MODEL') {
                continue;
            }
            [$valor, $valorId, $unidade] = match ($c['tipo']) {
                FichaTecnicaDaCategoria::TIPO_NUMERO_UNIDADE => ['50', null, 'cm'],
                FichaTecnicaDaCategoria::TIPO_NUMERO => ['3', null, null],
                FichaTecnicaDaCategoria::TIPO_SIM_NAO => ['Sim', '242085', null],
                default => $c['valores'] !== [] ? [$c['valores'][0]['nome'], (string) $c['valores'][0]['id'], null] : ['ECF', null, null],
            };
            EstruturaProdutoAtributo::updateOrCreate(['company_id' => $p->company_id, 'produto_id' => $p->id, 'atributo_id' => $id],
                ['atributo_nome' => $id, 'valor' => $valor, 'valor_id' => $valorId, 'unidade' => $unidade]);
        }

        return $p;
    }

    protected function preparo(): PreparoIaDoRascunhoService
    {
        return app(PreparoIaDoRascunhoService::class);
    }

    /** Os Jobs desta classe que a fila recebeu e ainda não rodaram, na ordem, com a cadeia. @return list<string> */
    protected function rodar(string $classe): array
    {
        $saida = [];
        foreach (Queue::pushed($classe) as $job) {
            if ($this->rodados->contains($job)) {
                continue;
            }
            $this->rodados->attach($job);
            $saida[] = $this->executar($job);
            foreach ($job->chained ?? [] as $serializado) {
                $saida[] = $this->executar(unserialize($serializado));
            }
        }

        return $saida;
    }

    protected function executar(object $job): string
    {
        return match (true) {
            $job instanceof PrepararProdutoNoPublicadorJob => $this->preparo()->preparar($job->companyId, $job->estruturaProdutoId, $job->marca, $job->adiamentos),
            $job instanceof GerarPreparoIaJob => $this->preparo()->executarEtapa($job->rascunhoId, $job->etapa, $job->hash, $job->valorPronto, $job->adiamentos),
            default => 'outro:'.class_basename($job),
        };
    }

    /** @return array{0: list<string>, 1: list<string>} */
    protected function salvarERodar(EstruturaProduto $p): array
    {
        app(PreparoIaAgenda::class)->aoSalvar((int) $p->company_id, [$p->id]);

        return [$this->rodar(PrepararProdutoNoPublicadorJob::class), $this->rodar(GerarPreparoIaJob::class)];
    }

    protected function pubDo(EstruturaProduto $p): PubProduto
    {
        return PubProduto::where('estrutura_produto_id', $p->id)->firstOrFail();
    }

    protected function rascunhoDo(EstruturaProduto $p): PubRascunho
    {
        return PubRascunho::where('produto_id', $this->pubDo($p)->id)->firstOrFail();
    }

    /** O produto entra AGENDADO numa fila viva da conta (direto no banco: a fila em si é provada no `FilaDePublicacaoTest`). */
    protected function agendarNaFila(PubProduto $pub): void
    {
        $chave = 'company-'.$pub->company_id;
        $fila = PubFilaPublicacao::query()->where('conta_ativa', $chave)->first()
            ?? PubFilaPublicacao::create(['conta_chave' => $chave, 'conta_ativa' => $chave, 'company_id' => $pub->company_id, 'status' => PubFilaPublicacao::ATIVA]);
        $fila->itens()->create(['produto_id' => $pub->id, 'rascunho_id' => PubRascunho::where('produto_id', $pub->id)->value('id'),
            'produto_ativo' => $pub->id, 'posicao' => 1, 'status' => 'agendado']);
    }
}
