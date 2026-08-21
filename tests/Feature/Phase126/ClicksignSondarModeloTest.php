<?php

namespace Tests\Feature\Phase126;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Fase 126 Plan 126-10 (CLICK-01) — especificação executável do comando
 * `clicksign:sondar-modelo`: o instrumento de medição do caminho de modelo
 * que o gate humano do plano 126-11 roda contra o `.docx` real cadastrado
 * na Clicksign, e que a Fase 127 reusa contra a conta de produção.
 *
 * ⚠️ **O que este teste prova:** o COMPORTAMENTO do comando — guardas de
 * ambiente/template, dry-run por padrão, ordem e contagem exatas de
 * requisições, descarte do envelope de sondagem e ausência do token na
 * saída. `Http::fake()` confirma só o que o comando DECIDE enviar — não que
 * a Clicksign aceita o payload. É a mesma limitação estrutural documentada
 * em `ClicksignClientModeloTest`; a prova real fica para o plano 126-11.
 */
class ClicksignSondarModeloTest extends TestCase
{
    private const BASE = 'https://sandbox.clicksign.com/api/v3';

    private const ENVELOPE_ID = '00000000-0000-4000-8000-000000000001';
    private const DOCUMENT_ID = '00000000-0000-4000-8000-000000000002';
    private const TEMPLATE_ID = '00000000-0000-4000-8000-000000000008';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.clicksign.env'          => 'sandbox',
            'services.clicksign.base_url'     => self::BASE,
            'services.clicksign.access_token' => 'token-sondagem-de-teste',
            'services.clicksign.template_id'  => self::TEMPLATE_ID,
        ]);
    }

    // ─── Fixtures HTTP mínimas (forma, não medição — a medição real é 126-11) ───

    private function respostaEnvelopeCriado(): array
    {
        return ['data' => ['id' => self::ENVELOPE_ID, 'type' => 'envelopes', 'attributes' => ['status' => 'draft']]];
    }

    private function respostaDocumentoCriado(): array
    {
        return ['data' => ['id' => self::DOCUMENT_ID, 'type' => 'documents', 'attributes' => ['filename' => 'Sondagem-modelo.docx', 'status' => 'draft']]];
    }

    private function respostaEventosVazia(): array
    {
        return ['data' => []];
    }

    private function respostaModeloCriado(): array
    {
        return ['data' => ['id' => self::TEMPLATE_ID, 'type' => 'templates', 'attributes' => ['name' => 'Sondagem.docx']]];
    }

    /**
     * Um `.docx` de verdade (zip válido com `word/document.xml`) contendo as
     * variáveis passadas como `{{nome}}` literais no texto — suficiente para
     * exercitar a extração local do comando sem depender de um contrato real
     * do Word.
     *
     * @param  array<int, string>  $variaveis
     */
    private function criarDocxDeTeste(array $variaveis): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'sondagem') . '.docx';

        $texto = implode(' ', array_map(fn (string $v) => "{{{$v}}}", $variaveis));
        $xml   = '<?xml version="1.0"?><w:document><w:body><w:p><w:r><w:t>' . $texto . '</w:t></w:r></w:p></w:body></w:document>';

        $zip = new ZipArchive();
        $zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $caminho;
    }

    /**
     * Fake único cobrindo todas as rotas que o comando pode chamar (criar
     * modelo, excluir modelo, listar modelos, e a sequência de sondagem por
     * envelope/documento/eventos) — um `Closure` em vez de padrões de URL
     * porque `/envelopes/*` (curinga) casaria com `/envelopes/{id}/documents`
     * e `/envelopes/{id}/documents/{id}/events` também, e a ordem de
     * cadastro dos padrões no array do `Http::fake()` é frágil para isso.
     */
    private function fakeTudo(): void
    {
        Http::fake(function ($request) {
            $url    = $request->url();
            $metodo = $request->method();

            if ($metodo === 'POST' && $url === self::BASE . '/templates') {
                return Http::response($this->respostaModeloCriado(), 200);
            }

            if ($metodo === 'DELETE' && str_contains($url, '/templates/')) {
                return Http::response('', 204);
            }

            if ($metodo === 'GET' && str_contains($url, '/templates')) {
                return Http::response(['data' => []], 200);
            }

            if ($metodo === 'POST' && $url === self::BASE . '/envelopes') {
                return Http::response($this->respostaEnvelopeCriado(), 200);
            }

            if ($metodo === 'POST' && str_contains($url, '/documents') && !str_contains($url, '/events')) {
                return Http::response($this->respostaDocumentoCriado(), 200);
            }

            if ($metodo === 'GET' && str_contains($url, '/events')) {
                return Http::response($this->respostaEventosVazia(), 200);
            }

            if ($metodo === 'DELETE' && str_contains($url, '/envelopes/')) {
                return Http::response('', 204);
            }

            return Http::response(['errors' => [['code' => 'rota_nao_faqueada_neste_teste']]], 599);
        });
    }

    // ─── 1. Dry-run é o padrão em qualquer modo que crie/apague recurso ───

    #[Test]
    public function sem_confirmar_modo_padrao_nao_envia_requisicao_alguma_e_sai_0(): void
    {
        Http::fake();

        $codigo = Artisan::call('clicksign:sondar-modelo');
        $saida  = Artisan::output();

        Http::assertNothingSent();
        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('DRY-RUN', $saida);
    }

    #[Test]
    public function excluir_modelo_sem_confirmar_nao_envia_delete_templates(): void
    {
        Http::fake();

        $codigo = Artisan::call('clicksign:sondar-modelo', ['--excluir-modelo' => true]);

        Http::assertNothingSent();
        $this->assertSame(0, $codigo);
    }

    // ─── 2. --listar ───

    #[Test]
    public function listar_sem_confirmar_nao_envia_nada(): void
    {
        Http::fake();

        $codigo = Artisan::call('clicksign:sondar-modelo', ['--listar' => true]);

        Http::assertNothingSent();
        $this->assertSame(0, $codigo);
    }

    #[Test]
    public function listar_com_confirmar_envia_exatamente_1_requisicao_para_templates(): void
    {
        Http::fake([
            self::BASE . '/templates*' => Http::response(['data' => []], 200),
        ]);

        $codigo = Artisan::call('clicksign:sondar-modelo', ['--listar' => true, '--confirmar' => true]);

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/templates'));
        $this->assertSame(0, $codigo);
    }

    // ─── 3. Guarda de ambiente ───

    #[Test]
    public function fora_do_sandbox_sem_producao_aborta_sem_requisicao(): void
    {
        config(['services.clicksign.env' => 'producao']);
        Http::fake();

        $codigo = Artisan::call('clicksign:sondar-modelo', ['--confirmar' => true]);
        $saida  = Artisan::output();

        Http::assertNothingSent();
        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('sandbox', $saida);
    }

    // ─── 4. Guarda de template ───

    #[Test]
    public function sem_template_e_sem_config_aborta_apontando_a_variavel_de_ambiente(): void
    {
        config(['services.clicksign.template_id' => null]);
        Http::fake();

        $codigo = Artisan::call('clicksign:sondar-modelo', ['--confirmar' => true]);
        $saida  = Artisan::output();

        Http::assertNothingSent();
        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('CLICKSIGN_TEMPLATE_ID', $saida);
    }

    // ─── 5. Sequência exata do modo padrão ───

    #[Test]
    public function modo_padrao_com_confirmar_faz_exatamente_4_requisicoes_nesta_ordem(): void
    {
        $this->fakeTudo();

        $codigo = Artisan::call('clicksign:sondar-modelo', ['--confirmar' => true]);

        Http::assertSentInOrder([
            fn ($r) => $r->method() === 'POST' && $r->url() === self::BASE . '/envelopes',
            fn ($r) => $r->method() === 'POST' && $r->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID . '/documents',
            fn ($r) => $r->method() === 'GET' && $r->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID . '/documents/' . self::DOCUMENT_ID . '/events',
            fn ($r) => $r->method() === 'DELETE' && $r->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID,
        ]);

        $this->assertSame(0, $codigo);
    }

    // ─── 6. Descarte mesmo com falha na instanciação ───

    #[Test]
    public function envelope_e_descartado_mesmo_quando_instanciacao_do_modelo_falha(): void
    {
        Http::fake(function ($request) {
            $url    = $request->url();
            $metodo = $request->method();

            if ($metodo === 'POST' && $url === self::BASE . '/envelopes') {
                return Http::response($this->respostaEnvelopeCriado(), 200);
            }

            if ($metodo === 'POST' && str_contains($url, '/documents')) {
                return Http::response(
                    ['errors' => [['code' => 'unprocessable_entity', 'status' => 422, 'detail' => 'template inválido']]],
                    422
                );
            }

            if ($metodo === 'DELETE' && str_contains($url, '/envelopes/')) {
                return Http::response('', 204);
            }

            return Http::response(['errors' => [['code' => 'rota_nao_faqueada']]], 599);
        });

        $codigo = Artisan::call('clicksign:sondar-modelo', ['--confirmar' => true]);

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID);
        Http::assertSentCount(3);
        $this->assertNotSame(0, $codigo);
    }

    // ─── 7. Tabela de confronto ───

    #[Test]
    public function tabela_de_confronto_lista_ok_faltando_e_sobrando(): void
    {
        $this->fakeTudo();

        // As 15 variáveis de ContratoVariaveisModeloService::nomes() são:
        // razao_social, cnpj, endereco, bairro, cidade, estado, cep,
        // servico_contratado, valor_mensal, vigencia_inicio, vigencia_fim,
        // data_primeira_parcela, dia_vencimento, data_assinatura,
        // plano_parcelas.
        //
        // O .docx de teste inclui 8 delas (faltam data_primeira_parcela,
        // data_assinatura, bairro, cidade, estado, cep e plano_parcelas ->
        // "faltando no .docx") e mais uma que o código não emite ->
        // "sobrando no .docx".
        $docx = $this->criarDocxDeTeste([
            'razao_social', 'cnpj', 'endereco', 'servico_contratado', 'valor_mensal',
            'vigencia_inicio', 'vigencia_fim', 'dia_vencimento',
            'campo_extra_do_docx',
        ]);

        $codigo = Artisan::call('clicksign:sondar-modelo', ['--confirmar' => true, '--criar-modelo' => $docx]);
        $saida  = Artisan::output();

        @unlink($docx);

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('razao_social', $saida);
        $this->assertStringContainsString('ok', $saida);
        $this->assertStringContainsString('faltando no .docx', $saida);
        $this->assertStringContainsString('sobrando no .docx', $saida);
        $this->assertStringContainsString('data_primeira_parcela', $saida);
        $this->assertStringContainsString('campo_extra_do_docx', $saida);
    }

    #[Test]
    public function sem_criar_modelo_a_coluna_do_modelo_fica_desconhecida_e_confronto_e_parcial(): void
    {
        $this->fakeTudo();

        $codigo = Artisan::call('clicksign:sondar-modelo', ['--confirmar' => true]);
        $saida  = Artisan::output();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('desconhecido', $saida);
        $this->assertStringContainsString('PARCIAL', $saida);
    }

    // ─── 8. --excluir-modelo ───

    #[Test]
    public function excluir_modelo_com_confirmar_dispara_delete_templates_e_avisa_limitacao_draft(): void
    {
        $this->fakeTudo();

        $codigo = Artisan::call('clicksign:sondar-modelo', ['--confirmar' => true, '--excluir-modelo' => true]);
        $saida  = Artisan::output();

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/templates/'));
        $this->assertStringContainsString('DRAFT', $saida);
        $this->assertSame(0, $codigo);
    }

    // ─── 9. Token nunca aparece na saída ───

    #[Test]
    public function saida_nunca_contem_o_token_de_acesso_em_sucesso_ou_erro(): void
    {
        config(['services.clicksign.access_token' => 'TOKEN-SUPER-SECRETO-NAO-PODE-VAZAR']);

        $this->fakeTudo();

        Artisan::call('clicksign:sondar-modelo', ['--confirmar' => true]);
        $saidaSucesso = Artisan::output();

        $this->assertStringNotContainsString('TOKEN-SUPER-SECRETO-NAO-PODE-VAZAR', $saidaSucesso);
        $this->assertStringNotContainsString('Bearer', $saidaSucesso);

        Http::fake(function ($request) {
            $url    = $request->url();
            $metodo = $request->method();

            if ($metodo === 'POST' && $url === self::BASE . '/envelopes') {
                return Http::response($this->respostaEnvelopeCriado(), 200);
            }

            if ($metodo === 'DELETE') {
                return Http::response('', 204);
            }

            return Http::response(
                ['errors' => [['code' => 'unprocessable_entity', 'status' => 422, 'detail' => 'template inválido']]],
                422
            );
        });

        Artisan::call('clicksign:sondar-modelo', ['--confirmar' => true]);
        $saidaErro = Artisan::output();

        $this->assertStringNotContainsString('TOKEN-SUPER-SECRETO-NAO-PODE-VAZAR', $saidaErro);
        $this->assertStringNotContainsString('Bearer', $saidaErro);
    }

    // ─── 10. Contagem de requisições impressa bate com o real ───

    #[Test]
    public function contagem_de_requisicoes_impressa_bate_com_o_numero_real_no_caminho_feliz(): void
    {
        $this->fakeTudo();

        Artisan::call('clicksign:sondar-modelo', ['--confirmar' => true]);
        $saida = Artisan::output();

        Http::assertSentCount(4);
        $this->assertStringContainsString('Requisições feitas: 4', $saida);
    }

    #[Test]
    public function contagem_de_requisicoes_impressa_bate_com_o_numero_real_quando_instanciacao_falha(): void
    {
        Http::fake(function ($request) {
            $url    = $request->url();
            $metodo = $request->method();

            if ($metodo === 'POST' && $url === self::BASE . '/envelopes') {
                return Http::response($this->respostaEnvelopeCriado(), 200);
            }

            if ($metodo === 'POST' && str_contains($url, '/documents')) {
                return Http::response(
                    ['errors' => [['code' => 'unprocessable_entity', 'status' => 422, 'detail' => 'template inválido']]],
                    422
                );
            }

            if ($metodo === 'DELETE') {
                return Http::response('', 204);
            }

            return Http::response(['errors' => [['code' => 'rota_nao_faqueada']]], 599);
        });

        Artisan::call('clicksign:sondar-modelo', ['--confirmar' => true]);
        $saida = Artisan::output();

        Http::assertSentCount(3);
        $this->assertStringContainsString('Requisições feitas: 3', $saida);
    }

    // ─── --baixar: regressão do 4º bug da fase (gate do plano 126-11) ───

    /**
     * MEDIDO em 11/08/2026, com envelope real ativado no sandbox: o link do
     * arquivo gerado vive em **`data.links.files.original`**, NÃO em
     * `data.attributes.files.original` — que era onde o código do plano
     * 126-10 procurava, e por isso nunca achava nada.
     *
     * Medido junto (§10.4 do empírico): o link **não existe** enquanto o
     * envelope está em `draft` (a Clicksign só materializa o arquivo na
     * ativação), e o que ela devolve é o **`.docx`**, não um PDF — por isso o
     * método salva com a extensão que veio no `filename`, e não `.pdf` fixo.
     */
    #[Test]
    public function baixar_le_o_link_de_links_files_original_e_nao_de_attributes(): void
    {
        $urlArquivo = 'https://clicksign-sandbox-content.s3.amazonaws.com/exemplo/contrato.docx?X-Amz-Expires=300';

        Http::fake(function ($request) use ($urlArquivo) {
            $url    = $request->url();
            $metodo = $request->method();

            if ($metodo === 'POST' && $url === self::BASE . '/envelopes') {
                return Http::response($this->respostaEnvelopeCriado(), 200);
            }

            if ($metodo === 'POST' && str_contains($url, '/documents')) {
                // Forma REAL medida: `links.files.original`, e o filename `.docx`.
                return Http::response(['data' => [
                    'id'         => self::DOCUMENT_ID,
                    'type'       => 'documents',
                    'attributes' => ['filename' => 'Sondagem-modelo.docx', 'status' => 'draft'],
                    'links'      => ['files' => ['original' => $urlArquivo]],
                ]], 200);
            }

            if ($metodo === 'GET' && str_contains($url, '/events')) {
                return Http::response($this->respostaEventosVazia(), 200);
            }

            if (str_contains($url, 's3.amazonaws.com')) {
                return Http::response('PK conteudo binario falso do docx', 200);
            }

            if ($metodo === 'DELETE') {
                return Http::response('', 204);
            }

            return Http::response(['errors' => [['code' => 'rota_nao_faqueada']]], 599);
        });

        Artisan::call('clicksign:sondar-modelo', [
            '--template'  => self::TEMPLATE_ID,
            '--baixar'    => true,
            '--confirmar' => true,
        ]);
        $saida = Artisan::output();

        $this->assertStringContainsString('Arquivo gerado baixado em:', $saida);
        // Extensão vem do filename devolvido pela API, não `.pdf` presumido.
        $this->assertStringContainsString('.docx', $saida);
        $this->assertStringNotContainsString('Sem link de download', $saida);

        Http::assertSent(fn ($request) => str_contains($request->url(), 's3.amazonaws.com'));
    }

    /**
     * Sem `links.files.original` — o caso do envelope em `draft`, que é o
     * normal na sondagem. Não é erro: o comando avisa e segue, em vez de
     * falhar ou de inventar um caminho de arquivo.
     */
    #[Test]
    public function baixar_sem_link_avisa_que_e_esperado_em_rascunho_e_nao_falha(): void
    {
        $this->fakeTudo();

        $codigo = Artisan::call('clicksign:sondar-modelo', [
            '--template'  => self::TEMPLATE_ID,
            '--baixar'    => true,
            '--confirmar' => true,
        ]);
        $saida = Artisan::output();

        $this->assertSame(0, $codigo, 'Ausência de link não é falha do comando.');
        $this->assertStringContainsString('Sem link de download', $saida);
        $this->assertStringContainsString('rascunho', $saida);
    }
}
