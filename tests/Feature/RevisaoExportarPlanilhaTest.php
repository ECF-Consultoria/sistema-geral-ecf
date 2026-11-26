<?php

namespace Tests\Feature;

use App\Models\Pendencia;
use App\Models\Publicacao;
use App\Models\Revisao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * "Baixar planilha" da Revisão: a planilha sai com o mesmo recorte da tela
 * (mês, publicador, estado, severidade, busca), sem o limite de 50 por coluna,
 * e com uma linha por pendência em aberto.
 *
 * @group revisao
 */
class RevisaoExportarPlanilhaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $renan;
    private User $outro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Líder']);
        $this->renan = User::factory()->create(['name' => 'Renan Souza']);
        $this->outro = User::factory()->create(['name' => 'Outra Pessoa']);
    }

    private function pub(User $dono, string $status, array $attrs = []): Publicacao
    {
        static $seq = 0;
        $seq++;

        return Publicacao::create(array_merge([
            'data'           => now()->startOfMonth()->toDateString(),
            'user_id'        => $dono->id,
            'empresa'        => 'Loja Teste',
            'mlb_code'       => 'MLB' . str_pad((string) $seq, 10, '0', STR_PAD_LEFT),
            'tipo'           => 'anuncio',
            'vendido'        => false,
            'status_revisao' => $status,
        ], $attrs));
    }

    private function pendencia(Publicacao $pub, string $severidade, string $texto, string $status = Pendencia::ST_ABERTA): Pendencia
    {
        return Pendencia::create([
            'publicacao_id' => $pub->id,
            'severidade'    => $severidade,
            'categoria'     => 'foto',
            'texto'         => $texto,
            'aberta_por'    => $this->admin->id,
            'aberta_em'     => now()->subDays(3),
            'status'        => $status,
        ]);
    }

    /** Baixa e devolve [cabeçalho, linhas] como arrays de texto. */
    private function baixar(array $filtros): array
    {
        $resposta = $this->actingAs($this->admin)->get(route('mlb.revisao.exportar', $filtros));
        $resposta->assertOk();
        $this->assertStringContainsString('spreadsheetml', $resposta->headers->get('Content-Type'));

        $arquivo = tempnam(sys_get_temp_dir(), 'rev') . '.xlsx';
        file_put_contents($arquivo, $resposta->streamedContent());
        $linhas = IOFactory::load($arquivo)->getActiveSheet()->toArray(null, true, false);
        @unlink($arquivo);

        $cabecalho = array_shift($linhas);

        return [$cabecalho, array_map(fn($l) => array_combine($cabecalho, $l), $linhas)];
    }

    public function test_exporta_so_os_em_ajuste_do_publicador_filtrado_uma_linha_por_pendencia(): void
    {
        $doRenan = $this->pub($this->renan, Revisao::ST_EM_AJUSTE);
        $this->pendencia($doRenan, Pendencia::SEV_AJUSTE, 'Foto principal com fundo');
        $this->pendencia($doRenan, Pendencia::SEV_BLOQUEIO, 'Categoria errada');
        // Resolvida não é erro em aberto: não vira linha.
        $this->pendencia($doRenan, Pendencia::SEV_AJUSTE, 'Já resolvida', Pendencia::ST_RESOLVIDA);

        $this->pub($this->renan, Revisao::ST_APROVADO);                 // outro estado
        $this->pendencia($this->pub($this->outro, Revisao::ST_EM_AJUSTE), Pendencia::SEV_AJUSTE, 'De outra pessoa');

        $mes = now()->format('Y-m');
        [$cabecalho, $linhas] = $this->baixar(['mes' => $mes, 'func' => $this->renan->id, 'status' => 'em_ajuste']);

        $this->assertContains('Pendência', $cabecalho);
        $this->assertCount(2, $linhas);
        $this->assertSame(['Renan Souza'], array_values(array_unique(array_column($linhas, 'Publicador'))));
        $this->assertSame(['Em ajuste'], array_values(array_unique(array_column($linhas, 'Status da revisão'))));
        $this->assertEqualsCanonicalizing(['Foto principal com fundo', 'Categoria errada'], array_column($linhas, 'Pendência'));
        $this->assertSame($doRenan->mlb_code, $linhas[0]['MLB']);
    }

    public function test_filtro_de_severidade_so_traz_pendencias_daquela_severidade(): void
    {
        $pub = $this->pub($this->renan, Revisao::ST_EM_AJUSTE);
        $this->pendencia($pub, Pendencia::SEV_AJUSTE, 'Título fora do padrão');
        $this->pendencia($pub, Pendencia::SEV_OBSERVACAO, 'Só uma dica');

        // Sem pendência de ajuste: não entra.
        $this->pendencia($this->pub($this->renan, Revisao::ST_EM_AJUSTE), Pendencia::SEV_OBSERVACAO, 'Outra dica');

        [, $linhas] = $this->baixar(['mes' => now()->format('Y-m'), 'status' => 'todos', 'sev' => 'ajuste']);

        $this->assertCount(1, $linhas);
        $this->assertSame('Ajuste', $linhas[0]['Severidade']);
        $this->assertSame('Título fora do padrão', $linhas[0]['Pendência']);
    }

    public function test_sem_filtro_de_estado_sai_o_que_as_colunas_mostram_sem_limite(): void
    {
        // Acima do limite de 50 cards por coluna da tela.
        for ($i = 0; $i < 55; $i++) {
            $this->pub($this->outro, Revisao::ST_NAO_REVISADO);
        }
        $this->pub($this->outro, Revisao::ST_RECONFERIR);
        $this->pub($this->outro, Revisao::ST_APROVADO);                     // fora das colunas
        $this->pub($this->outro, Revisao::ST_NAO_REVISADO, ['desconsiderado' => true]); // fora da metodologia

        [, $linhas] = $this->baixar(['mes' => now()->format('Y-m')]);
        $this->assertCount(56, $linhas);
        $this->assertNotContains('Aprovado', array_column($linhas, 'Status da revisão'));

        [, $todos] = $this->baixar(['mes' => now()->format('Y-m'), 'status' => 'todos']);
        $this->assertCount(57, $todos);

        [$cabFora, $fora] = $this->baixar(['mes' => now()->format('Y-m'), 'status' => 'desconsiderados']);
        $this->assertCount(1, $fora);
        $this->assertContains('Fora da metodologia por', $cabFora);
    }

    public function test_nome_do_arquivo_carrega_o_recorte(): void
    {
        $this->pub($this->renan, Revisao::ST_EM_AJUSTE);
        $mes = now()->format('Y-m');

        $resposta = $this->actingAs($this->admin)
            ->get(route('mlb.revisao.exportar', ['mes' => $mes, 'func' => $this->renan->id, 'status' => 'em_ajuste']));

        $this->assertStringContainsString(
            "revisao-{$mes}-em-ajuste-renan-souza.xlsx",
            $resposta->headers->get('Content-Disposition'),
        );
    }

    public function test_quem_nao_esta_no_modulo_mlb_nao_baixa(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('mlb.revisao.exportar'))
            ->assertForbidden();
    }
}
