<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use Tests\TestCase;

/**
 * O Job do preparo que entrou na fila ANTES da trilha (02/11/2026) volta da fila sem o campo `soItens` — o construtor
 * não roda na volta. Ele tem de rodar igual a antes (todos os itens), e não quebrar: com a propriedade promovida no
 * construtor, ela ficava sem valor e o `handle` lançava "must not be accessed before initialization" em toda loja.
 */
class PreparoJobDeAntesDaTrilhaTest extends TestCase
{
    public function test_o_job_enfileirado_antes_da_trilha_roda_para_todos_os_itens(): void
    {
        $velho = unserialize($this->payloadSemSoItens(new PrepararProdutoNoPublicadorJob(7, 9, 'marca-1', 2)));

        $this->assertInstanceOf(PrepararProdutoNoPublicadorJob::class, $velho);
        $this->assertNull($velho->soItens);
        $this->assertSame(2, $velho->adiamentos);

        $this->mock(PreparoIaDoRascunhoService::class, fn ($m) => $m->shouldReceive('preparar')->once()->with(7, 9, 'marca-1', 2, null));
        app()->call([$velho, 'handle']);
    }

    public function test_o_job_novo_leva_os_itens_pela_fila(): void
    {
        $novo = unserialize(serialize(new PrepararProdutoNoPublicadorJob(7, 9, 'marca-1', 0, ['s-9', 'c-9-2'])));

        $this->assertSame(['s-9', 'c-9-2'], $novo->soItens);
        $this->mock(PreparoIaDoRascunhoService::class, fn ($m) => $m->shouldReceive('preparar')->once()->with(7, 9, 'marca-1', 0, ['s-9', 'c-9-2']));
        app()->call([$novo, 'handle']);
    }

    /** O payload como o código de antes gravava: os mesmos campos, sem `soItens`. */
    private function payloadSemSoItens(PrepararProdutoNoPublicadorJob $job): string
    {
        $dados = $job->__serialize();
        unset($dados['soItens']);
        $this->assertArrayHasKey('companyId', $dados);

        // `a:N:{…}` → `O:<tam>:"<classe>":N:{…}`: o PHP entrega o miolo ao `__unserialize`, como na volta da fila.
        return 'O:'.strlen(PrepararProdutoNoPublicadorJob::class).':"'.PrepararProdutoNoPublicadorJob::class.'"'.substr(serialize($dados), 1);
    }
}
