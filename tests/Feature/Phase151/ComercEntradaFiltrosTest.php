<?php

namespace Tests\Feature\Phase151;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filtros da Entrada (23/09/2026) — serviço, vencimento (término do contrato)
 * e status do contrato, em `ComercialEntradaController::index()`.
 *
 * O que estes testes prendem, além do óbvio "filtra":
 *  - o filtro de contrato roda ANTES da paginação — o total do paginador é o
 *    da lista filtrada, nunca o da lista inteira;
 *  - "sem prazo" é faixa própria, e não conta como vencido;
 *  - "vence hoje" ainda não venceu;
 *  - valor fora da whitelist é ignorado, sem erro.
 */
class ComercEntradaFiltrosTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servico(string $nome, bool $exigeContrato = true): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => $exigeContrato,
        ]);
    }

    private function empresa(string $nome, Servico $servico, ?string $termino = null): Company
    {
        $empresa = Company::factory()->create([
            'name'   => $nome,
            'active' => true,
            'etapa'  => Company::ETAPA_ADMINISTRATIVO_ANDAMENTO,
        ]);

        ContratoServico::create([
            'company_id'       => $empresa->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->subMonth()->toDateString(),
            'data_vencimento'  => $termino,
            'ativo'            => true,
        ]);

        return $empresa;
    }

    /** @return array<int, string> */
    private function nomes(array $filtros): array
    {
        $resposta = $this->actingAs($this->admin())->get(route('comercial.entrada.index', $filtros));
        $resposta->assertOk();

        return collect($resposta->viewData('page')['props']['companies']['data'])->pluck('name')->sort()->values()->all();
    }

    public function test_filtra_por_servico_ativo(): void
    {
        $gestao = $this->servico('Gestão');
        $publicacao = $this->servico('Publicação');

        $this->empresa('Loja Gestão', $gestao);
        $this->empresa('Loja Publicação', $publicacao);

        $this->assertSame(['Loja Gestão'], $this->nomes(['servico' => $gestao->id]));
        $this->assertSame(['Loja Publicação'], $this->nomes(['servico' => $publicacao->id]));
    }

    public function test_filtra_pelo_termino_do_contrato(): void
    {
        $gestao = $this->servico('Gestão');

        $this->empresa('Vencida', $gestao, now()->subDay()->toDateString());
        $this->empresa('Vence Hoje', $gestao, now()->toDateString());
        $this->empresa('Vence em 45', $gestao, now()->addDays(45)->toDateString());
        $this->empresa('Vence em 200', $gestao, now()->addDays(200)->toDateString());
        $this->empresa('Sem Prazo', $gestao, null);

        $this->assertSame(['Vencida'], $this->nomes(['vencimento' => 'vencido']));
        // Vence hoje ainda não venceu — entra na janela, não no "vencido".
        $this->assertSame(['Vence Hoje'], $this->nomes(['vencimento' => '30']));
        // Faixas cumulativas: 60 dias contém o que vence em 30.
        $this->assertSame(['Vence Hoje', 'Vence em 45'], $this->nomes(['vencimento' => '60']));
        $this->assertSame(['Sem Prazo'], $this->nomes(['vencimento' => 'sem_prazo']));
    }

    public function test_payload_traz_o_termino_mais_proximo_entre_os_contratos_ativos(): void
    {
        $gestao = $this->servico('Gestão');
        $publicacao = $this->servico('Publicação');

        $empresa = $this->empresa('Dois Contratos', $gestao, now()->addDays(90)->toDateString());
        ContratoServico::create([
            'company_id'       => $empresa->id,
            'servico_id'       => $publicacao->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->subMonth()->toDateString(),
            'data_vencimento'  => now()->addDays(10)->toDateString(),
            'ativo'            => true,
        ]);

        $resposta = $this->actingAs($this->admin())->get(route('comercial.entrada.index'));
        $linha = collect($resposta->viewData('page')['props']['companies']['data'])->firstWhere('name', 'Dois Contratos');

        $this->assertSame(now()->addDays(10)->toDateString(), $linha['termino_contrato']);
    }

    public function test_filtra_pelo_status_do_contrato_antes_de_paginar(): void
    {
        $gestao = $this->servico('Gestão');
        $polos = $this->servico('Polos', exigeContrato: false);

        $assinada = $this->empresa('Assinada', $gestao);
        ContratoAssinatura::factory()->create([
            'company_id' => $assinada->id,
            'servico_id' => $gestao->id,
            'status'     => ContratoAssinatura::STATUS_ASSINADO,
        ]);

        $this->empresa('Sem Contrato Gerado', $gestao);
        $this->empresa('Só Polos', $polos);

        $this->assertSame(['Assinada'], $this->nomes(['contrato' => ContratoAssinatura::STATUS_ASSINADO]));
        $this->assertSame(['Sem Contrato Gerado'], $this->nomes(['contrato' => Company::ETAPAS[0]]));
        $this->assertSame(['Só Polos'], $this->nomes(['contrato' => 'isento']));

        $resposta = $this->actingAs($this->admin())->get(route('comercial.entrada.index', ['contrato' => 'isento']));
        $this->assertSame(1, $resposta->viewData('page')['props']['companies']['total']);
    }

    public function test_valor_fora_da_whitelist_e_ignorado(): void
    {
        $gestao = $this->servico('Gestão');
        $this->empresa('Loja A', $gestao);

        $resposta = $this->actingAs($this->admin())->get(route('comercial.entrada.index', [
            'vencimento' => 'amanha',
            'contrato'   => 'qualquer',
            'servico'    => 'abc',
        ]));

        $resposta->assertOk();
        $filtros = $resposta->viewData('page')['props']['filters'];
        $this->assertNull($filtros['vencimento']);
        $this->assertNull($filtros['contrato']);
        $this->assertNull($filtros['servico']);
        $this->assertSame(['Loja A'], collect($resposta->viewData('page')['props']['companies']['data'])->pluck('name')->all());
    }
}
