<?php

namespace Tests\Feature\Phase202;

use App\Models\Company;
use App\Models\ContratoClicksignAssinado;
use App\Services\Clicksign\ClicksignClient;
use App\Services\Contratos\AcervoVigenciaClicksignService;
use App\Services\Contratos\EmpresaPalpiteService;
use App\Services\Contratos\ExtratorTextoContratoService;
use App\Services\Contratos\TabelaProgressivaContratoParser;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 202-08 — leitura do acervo de contratos ASSINADOS da Clicksign
 * (prazo escrito + data da assinatura). Http::fake na forma REAL medida em
 * 09/11/2026: listagem JSON:API com `attributes.name/status`, documento com
 * `links.files.original`, eventos com `attributes.name/created`.
 */
class AcervoVigenciaClicksignTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sandbox.clicksign.com/api/v3';

    private function servico(): AcervoVigenciaClicksignService
    {
        return new AcervoVigenciaClicksignService(
            new ClicksignClient(token: 'token-falso', baseUrl: self::BASE),
            new ExtratorTextoContratoService(),
            app(TabelaProgressivaContratoParser::class),
            app(EmpresaPalpiteService::class),
            0,
        );
    }

    private function envelope(string $id, string $nome, string $status = 'closed'): array
    {
        return ['id' => $id, 'type' => 'envelopes', 'attributes' => [
            'name' => $nome, 'status' => $status,
            'created' => '2026-07-28T10:00:00-03:00', 'modified' => '2026-07-30T09:16:01-03:00',
        ]];
    }

    private function pdf(string $texto): string
    {
        return Pdf::loadHTML('<html><body><p>'.$texto.'</p></body></html>')->output();
    }

    private function fakeClicksign(array $envelopes, string $textoContrato, array $eventos): void
    {
        $pdf = $this->pdf($textoContrato);

        Http::fake([
            self::BASE.'/envelopes/*/documents/*/events' => Http::response(['data' => $eventos], 200),
            self::BASE.'/envelopes/*/documents' => Http::response(['data' => [[
                'id' => 'doc-1', 'type' => 'documents',
                'attributes' => ['filename' => 'contrato.pdf'],
                'links' => ['files' => ['original' => 'https://s3.example.com/original.pdf?X-Amz-Expires=299']],
            ]]], 200),
            'https://s3.example.com/*' => Http::response($pdf, 200),
            self::BASE.'/envelopes*' => Http::response(['data' => $envelopes], 200),
        ]);
    }

    private function evento(string $nome, string $criado): array
    {
        return ['id' => uniqid(), 'type' => 'events', 'attributes' => ['name' => $nome, 'created' => $criado]];
    }

    // ─── Prazo ───

    public function test_le_prazo_das_frases_medidas(): void
    {
        $this->assertSame(['meses' => 6, 'fonte' => 'texto'], AcervoVigenciaClicksignService::lerPrazo(
            'O presente contrato terá vigência de 6 (seis) meses, contados da data de sua assinatura.', 'x'));
        $this->assertSame(['meses' => 12, 'fonte' => 'texto'], AcervoVigenciaClicksignService::lerPrazo(
            "A CONTRATADA prestará os serviços\npelo prazo de 12 (doze) meses.", 'x'));
        $this->assertSame(['meses' => 12, 'fonte' => 'texto'], AcervoVigenciaClicksignService::lerPrazo(
            'O PRESENTE CONTRATO TERÁ VIGÊNCIA DE 12 (DOZE) MESES, contados da data de sua assinatura', 'x'));
    }

    public function test_nao_casa_clausula_de_nao_concorrencia(): void
    {
        $texto = 'A CONTRATANTE não poderá contratar a equipe pelo período de 12 (doze) meses após o seu término. '
            .'Também fica vedado pelo prazo de 24 (vinte e quatro) meses após o término deste contrato.';

        $this->assertSame(['meses' => null, 'fonte' => null], AcervoVigenciaClicksignService::lerPrazo($texto, 'Contrato — Brigada — X'));

        // Com a cláusula ANTES da vigência, vale a vigência.
        $this->assertSame(6, AcervoVigenciaClicksignService::lerPrazo($texto.' O contrato terá vigência de 6 (seis) meses.', 'x')['meses']);
    }

    public function test_fallback_pelo_nome_do_envelope(): void
    {
        $this->assertSame(['meses' => 12, 'fonte' => 'nome'], AcervoVigenciaClicksignService::lerPrazo(
            'texto sem prazo nenhum', 'Contrato_Projeto_Brigadas_ECF - AB4 STORE LTDA - 12 Meses'));
        $this->assertSame(['meses' => null, 'fonte' => null], AcervoVigenciaClicksignService::lerPrazo(null, 'Contrato — Gestão — Ab4'));
    }

    // ─── Data da assinatura ───

    public function test_data_da_assinatura_pela_preferencia_dos_eventos(): void
    {
        $sign1  = $this->evento('sign', '2026-07-29T15:00:00-03:00');
        $sign2  = $this->evento('sign', '2026-07-30T08:00:00-03:00');
        $auto   = $this->evento('auto_close', '2026-07-30T08:30:00-03:00');
        $closed = $this->evento('document_closed', '2026-07-30T09:16:01-03:00');

        $this->assertSame('2026-07-30 09:16:01', AcervoVigenciaClicksignService::dataAssinatura([$sign1, $closed, $auto, $sign2])->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-30 08:30:00', AcervoVigenciaClicksignService::dataAssinatura([$sign1, $auto, $sign2])->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-30 08:00:00', AcervoVigenciaClicksignService::dataAssinatura([$sign2, $sign1])->format('Y-m-d H:i:s'));
        $this->assertNull(AcervoVigenciaClicksignService::dataAssinatura([$this->evento('add_signer', '2026-07-01T00:00:00-03:00')]));
    }

    public function test_data_da_assinatura_fica_no_fuso_de_sao_paulo(): void
    {
        // 23:30 em BRT = 02:30 UTC do dia seguinte: a data é a de São Paulo.
        $d = AcervoVigenciaClicksignService::dataAssinatura([$this->evento('document_closed', '2026-07-31T02:30:00Z')]);
        $this->assertSame('2026-07-30', $d->toDateString());
    }

    // ─── Filtro ───

    public function test_filtro_pega_brigada_gestao_e_mentoria_e_nunca_funcionario(): void
    {
        $f = fn (string $n) => AcervoVigenciaClicksignService::tipoDoEnvelope($n);

        $this->assertSame('gestao', $f('Contrato_Projeto_Brigadas_ECF - AB4 STORE LTDA - 12 Meses'));
        $this->assertSame('gestao', $f('Contrato — Brigada — Flipshop'));
        $this->assertSame('gestao', $f('Contrato — Gestão — OliverMulti'));
        $this->assertSame('gestao', $f('Contrato Gestao de Ads ECF - KAITON COMERCIO LTDA'));
        $this->assertSame('gestao', $f('contrato_gestao_ads_meli_WEHOUSE_SERVICOS_DIGITAIS_LTDA'));
        $this->assertSame('mentoria', $f('Contrato — Mentoria — Quater'));
        $this->assertNull($f('CONTRATO PRESTAÇÃO DE SERVIÇOS - Jessica De Oliveira'));
        $this->assertNull($f('Locação de Auditório 3'));
        $this->assertNull($f('Distrato — Brigada — Flipshop'));
    }

    public function test_nome_do_cliente_sem_prefixos(): void
    {
        $this->assertSame('AB4 STORE LTDA', AcervoVigenciaClicksignService::nomeDoCliente('Contrato_Projeto_Brigadas_ECF - AB4 STORE LTDA - 12 Meses'));
        $this->assertSame('Flipshop', AcervoVigenciaClicksignService::nomeDoCliente('Contrato — Brigada — Flipshop (1)'));
        $this->assertSame('KAITON COMERCIO LTDA', AcervoVigenciaClicksignService::nomeDoCliente('Contrato Gestao de Ads ECF - KAITON COMERCIO LTDA'));
    }

    // ─── Leitura ponta a ponta ───

    public function test_le_contrato_assinado_casa_empresa_e_e_incremental(): void
    {
        $ab4 = Company::factory()->create(['name' => 'AB4 Store', 'active' => true]);
        Company::factory()->create(['name' => 'Loja Totalmente Diferente', 'active' => true]);

        $this->fakeClicksign([
            $this->envelope('env-ab4', 'Contrato_Projeto_Brigadas_ECF - AB4 STORE LTDA - 12 Meses'),
            $this->envelope('env-cancelado', 'Contrato_Projeto_Brigadas_ECF - AB4 STORE LTDA (1)', 'canceled'),
            $this->envelope('env-func', 'CONTRATO PRESTAÇÃO DE SERVIÇOS - Jessica De Oliveira'),
        ], 'A CONTRATADA prestará o Projeto Brigada pelo prazo de 12 (doze) meses.', [
            $this->evento('sign', '2026-07-29T18:00:00-03:00'),
            $this->evento('document_closed', '2026-07-30T09:16:01-03:00'),
        ]);

        $resumo = $this->servico()->executar();

        $this->assertSame(1, $resumo['candidatos'], 'cancelado e funcionário não entram');
        $this->assertSame(1, $resumo['lidos']);

        $linha = ContratoClicksignAssinado::sole();
        $this->assertSame('env-ab4', $linha->envelope_id);
        $this->assertSame('gestao', $linha->tipo);
        $this->assertSame($ab4->id, $linha->company_id);
        $this->assertSame('casado', $linha->situacao);
        $this->assertSame(12, $linha->vigencia_meses);
        $this->assertSame('texto', $linha->vigencia_fonte);
        $this->assertSame('2026-07-30 09:16:01', $linha->assinado_em->format('Y-m-d H:i:s'));

        // Segunda rodada: não baixa de novo.
        $antes = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/documents'))->count();
        $resumo2 = $this->servico()->executar();
        $depois = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/documents'))->count();

        $this->assertSame(1, $resumo2['pulados']);
        $this->assertSame(0, $resumo2['lidos']);
        $this->assertSame($antes, $depois);
        $this->assertSame(1, ContratoClicksignAssinado::count());

        // --releer lê de novo.
        $this->assertSame(1, $this->servico()->executar(null, true)['lidos']);
    }

    public function test_empresa_nao_confirmada_fica_duvidoso_sem_company(): void
    {
        Company::factory()->create(['name' => 'Flip Comercio', 'active' => true]);
        Company::factory()->create(['name' => 'Flip Comercial', 'active' => true]);

        $this->fakeClicksign(
            [$this->envelope('env-flip', 'Contrato — Brigada — Flip Comerc')],
            'pelo prazo de 12 (doze) meses',
            [$this->evento('document_closed', '2026-07-30T09:16:01-03:00')],
        );

        $this->servico()->executar();

        $linha = ContratoClicksignAssinado::sole();
        $this->assertNull($linha->company_id);
        $this->assertSame('duvidoso', $linha->situacao);
        $this->assertStringContainsString('candidatos', $linha->motivo);
    }

    public function test_falha_no_envelope_vira_ilegivel_e_e_relida_na_proxima(): void
    {
        Http::fake([
            self::BASE.'/envelopes/*/documents' => Http::response(['errors' => [['detail' => 'boom']]], 500),
            self::BASE.'/envelopes*' => Http::response(['data' => [$this->envelope('env-x', 'Contrato — Gestão — X')]], 200),
        ]);

        $this->servico()->executar();

        $this->assertSame('ilegivel', ContratoClicksignAssinado::sole()->situacao);
        $this->assertSame(1, $this->servico()->executar()['lidos'], 'ilegível não conta como lido');
    }

    public function test_comando_imprime_resumo_e_so_grava_no_acervo(): void
    {
        config(['services.clicksign.access_token' => 'token-falso', 'services.clicksign.base_url' => self::BASE]);
        $this->fakeClicksign([], 'x', []);

        $this->artisan('contratos:ler-clicksign', ['--pausa-ms' => 0])
            ->expectsOutputToContain('Contratos de cliente assinados na Clicksign: 0')
            ->assertSuccessful();
    }
}
