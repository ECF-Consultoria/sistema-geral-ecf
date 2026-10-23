<?php

namespace Tests\Feature\Publicador\Agente;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Models\EstruturaProduto;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\User;
use App\Services\Publicador\Agente\ConfiguracaoDoAgenteService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;

/**
 * O cenário do agente sobre o do preparo (`CenarioDoPreparo`: a cadeira do Portal com a ficha completa, a IA como
 * dublê e a fila `Queue::fake()` rodada à mão): a conta `company-N` com o agente ligado e o produto já no Publicador
 * no recorte do piloto — só o Clássico ativo, uma variante com estoque e preço, e uma foto atribuída ao grupo geral.
 */
trait CenarioDoAgente
{
    use CenarioDoPreparo;

    protected User $admin;

    protected function montarAgente(): void
    {
        $this->montarPreparo();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * As CABEÇAS das cadeias de texto enfileiradas (cada `GerarPreparoIaJob` empurrado abre uma cadeia; os elos seguintes
     * vão em `chained`), em ordem. Desde 21/10/2026 (`PreparoIaDoRascunhoService::despacharCadeias`, pedido do usuário
     * por velocidade) um preparo completo abre DUAS cadeias em paralelo — [título → Modelo] e [descrição] — então
     * "não duplicou" é "só estas cabeças", não "uma só".
     *
     * @return list<string>
     */
    protected function cabecasDasCadeias(): array
    {
        return Queue::pushed(GerarPreparoIaJob::class)->map(fn ($j) => $j->etapa)->sort()->values()->all();
    }

    protected function conta(): array
    {
        return app(ProgramasPublicadorService::class)->resolver("company-{$this->empresa->id}");
    }

    protected function ligar(array $dados = []): void
    {
        app(ConfiguracaoDoAgenteService::class)->salvar($this->conta(), ['habilitado' => true, 'modalidade' => 'gold_special', ...$dados], 0, $this->admin);
    }

    protected function noPublicador(?EstruturaProduto $p = null): PubProduto
    {
        $p ??= $this->produtoDoPortal();
        $this->preparo()->sincronizarAgora((int) $this->empresa->id, (int) $p->id);
        $pub = $this->pubDo($p);
        $r = $this->rascunhoDo($p);
        $repo = app(RascunhoRepository::class);
        $repo->gravarAlvos($r, array_map(fn (Alvo $a) => new Alvo($a->listingTypeId, $a->titulo, $a->listingTypeId === 'gold_special'), $repo->snapshot($r)->alvos));
        $snap = $repo->snapshot($r->fresh());
        $variante = $snap->variantesAtivas()[0];
        $repo->gravarVariacao($r->fresh(), $snap->eixos, [$variante->comDados([...$variante->dados, 'estoque' => 5, 'precos' => ['gold_special' => 199.9]])]);
        $foto = $r->imagens()->create(['caminho' => 'publicador/cad.jpg', 'sha256' => str_repeat('a', 64), 'mime' => 'image/jpeg', 'bytes' => 800_000,
            'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::ENVIADA]);
        $repo->gravarAtribuicoes($r, [['imagem' => $foto->id, 'grupo' => ResolvedorGruposImagem::GERAL, 'posicao' => 0]]);

        return $pub->fresh();
    }
}
