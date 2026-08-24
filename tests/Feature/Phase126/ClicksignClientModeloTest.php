<?php

namespace Tests\Feature\Phase126;

use App\Exceptions\ClicksignException;
use App\Models\ContratoAssinaturaSignatario;
use App\Services\Clicksign\ClicksignClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ClicksignSandboxFixtures;
use Tests\TestCase;

/**
 * Fase 126 Plan 126-07 (CLICK-01, D-16) — cobre o caminho de MODELO do
 * `ClicksignClient`: CRUD do recurso `templates` e criação de documento a
 * partir de modelo, na forma medida em
 * `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` §9.6.
 *
 * ⚠️ Este teste NÃO prova que a Clicksign aceita o payload — `Http::fake()`
 * confirma só o que decidimos enviar. Foi assim que `communicate_by` (§9.1
 * do empírico) passou nos testes e quebraria em produção. A prova real
 * contra o sandbox é o plano 126-11, com `.docx` cadastrado.
 */
class ClicksignClientModeloTest extends TestCase
{
    private const TOKEN = 'token-clicksign-falso';
    private const BASE  = 'https://sandbox.clicksign.com/api/v3';

    private const ENVELOPE_ID = '00000000-0000-4000-8000-000000000001';
    private const DOCUMENT_ID = '00000000-0000-4000-8000-000000000002';
    private const TEMPLATE_ID = '00000000-0000-4000-8000-000000000008';

    private function client(): ClicksignClient
    {
        return new ClicksignClient(token: self::TOKEN, baseUrl: self::BASE);
    }

    /**
     * Os 3 signatários fixos da ECF (D-08) usados nos testes — dados
     * fictícios (`@example.com`), mesmo molde de `ClicksignClientEnvelopeTest`.
     *
     * @return array<int, array{nome: string, email: string, papel: string}>
     */
    private function signatariosEcfDeTeste(): array
    {
        return [
            ['nome' => 'Sócio Um', 'email' => 'socio1@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATADA],
            ['nome' => 'Sócio Dois', 'email' => 'socio2@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATADA],
            ['nome' => 'Comercial', 'email' => 'comercial@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_TESTEMUNHA],
        ];
    }

    // ─── listarModelos() ───

    #[Test]
    public function listar_modelos_manda_page_number_e_page_size_e_devolve_lista_desembrulhada(): void
    {
        Http::fake([
            self::BASE . '/templates*' => Http::response(ClicksignSandboxFixtures::modelosListados(), 200),
        ]);

        $modelos = $this->client()->listarModelos(pagina: 2, porPagina: 10);

        Http::assertSent(function ($request) {
            $this->assertSame('GET', $request->method());

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $this->assertSame('2', $query['page']['number'] ?? null);
            $this->assertSame('10', $query['page']['size'] ?? null);

            return true;
        });

        $this->assertIsArray($modelos);
        $this->assertSame('00000000-0000-4000-8000-000000000007', $modelos[0]['id']);
    }

    // ─── criarModelo() ───

    #[Test]
    public function criar_modelo_envia_type_templates_e_exatamente_name_e_content_base64(): void
    {
        Http::fake([
            self::BASE . '/templates' => Http::response(ClicksignSandboxFixtures::modeloCriado(), 200),
        ]);

        $this->client()->criarModelo('Contrato ECF.docx', 'binário docx falso de teste');

        Http::assertSent(function ($request) {
            $this->assertSame('templates', $request['data']['type'] ?? null);

            $atributos = $request['data']['attributes'] ?? [];

            $this->assertSame(['name', 'content_base64'], array_keys($atributos));
            $this->assertSame('Contrato ECF.docx', $atributos['name']);
            $this->assertTrue(str_starts_with(
                $atributos['content_base64'],
                'data:application/vnd.openxmlformats-officedocument.wordprocessingml.document;base64,'
            ));

            return true;
        });
    }

    #[Test]
    public function criar_modelo_com_cor_inclui_color_como_terceira_chave(): void
    {
        Http::fake([
            self::BASE . '/templates' => Http::response(ClicksignSandboxFixtures::modeloCriado(), 200),
        ]);

        $this->client()->criarModelo('Contrato ECF.docx', 'binário docx falso', '#fccf00');

        Http::assertSent(function ($request) {
            $atributos = $request['data']['attributes'] ?? [];

            $this->assertSame(['name', 'content_base64', 'color'], array_keys($atributos));
            $this->assertSame('#fccf00', $atributos['color']);

            return true;
        });
    }

    #[Test]
    public function criar_modelo_com_nome_sem_extensao_docx_lanca_excecao_antes_de_qualquer_requisicao(): void
    {
        Http::fake();

        try {
            $this->client()->criarModelo('Contrato ECF.pdf', 'binário docx falso');
            $this->fail('Esperava ClicksignException por nome sem extensão .docx.');
        } catch (ClicksignException $e) {
            $this->assertStringContainsString('.docx', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function criar_modelo_com_422_da_api_propaga_a_mensagem_medida(): void
    {
        Http::fake([
            self::BASE . '/templates' => Http::response(ClicksignSandboxFixtures::erro422ModeloInvalido(), 422),
        ]);

        try {
            $this->client()->criarModelo('Contrato ECF.docx', 'binário docx inválido de propósito');
            $this->fail('Esperava ClicksignException por 422.');
        } catch (ClicksignException $e) {
            $this->assertStringContainsString('formato .docx', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    // ─── excluirModelo() ───

    #[Test]
    public function excluir_modelo_usa_delete_e_devolve_true_em_204(): void
    {
        Http::fake([
            self::BASE . '/templates/*' => Http::response('', 204),
        ]);

        $resultado = $this->client()->excluirModelo(self::TEMPLATE_ID);

        $this->assertTrue($resultado);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    }

    #[Test]
    public function excluir_modelo_com_resposta_de_erro_devolve_false_sem_lancar(): void
    {
        Http::fake([
            self::BASE . '/templates/*' => Http::response(
                ['errors' => [['code' => 'server_error', 'status' => 500]]],
                500
            ),
        ]);

        $resultado = $this->client()->excluirModelo(self::TEMPLATE_ID);

        $this->assertFalse($resultado);
    }

    // ─── anexarDocumentoPorModelo() ───

    #[Test]
    public function anexar_documento_por_modelo_envia_exatamente_filename_e_template_key_data(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/documents' => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
        ]);

        $this->client()->anexarDocumentoPorModelo(
            self::ENVELOPE_ID,
            'Contrato de teste.docx',
            self::TEMPLATE_ID,
            ['razao_social' => 'Empresa Teste LTDA', 'dia' => 1]
        );

        Http::assertSent(function ($request) {
            $atributos = $request['data']['attributes'] ?? [];

            $this->assertSame(['filename', 'template'], array_keys($atributos));
            $this->assertSame('Contrato de teste.docx', $atributos['filename']);
            $this->assertSame(['key', 'data'], array_keys($atributos['template']));
            $this->assertSame(self::TEMPLATE_ID, $atributos['template']['key']);
            $this->assertSame(
                ['razao_social' => 'Empresa Teste LTDA', 'dia' => 1],
                $atributos['template']['data']
            );

            return true;
        });
    }

    #[Test]
    public function anexar_documento_por_modelo_nao_envia_content_base64_nem_template_id(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/documents' => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
        ]);

        $this->client()->anexarDocumentoPorModelo(self::ENVELOPE_ID, 'Contrato.docx', self::TEMPLATE_ID, []);

        Http::assertSent(function ($request) {
            $atributos = $request['data']['attributes'] ?? [];

            $this->assertArrayNotHasKey('content_base64', $atributos);
            $this->assertArrayNotHasKey('template_id', $atributos);

            return true;
        });
    }

    /**
     * O ponto que a §9.6 do empírico mediu como divisor de água: array PHP
     * vazio serializa como `[]`, e a API responde "data deve ser um hash".
     * A asserção inspeciona o JSON BRUTO da requisição — `[]` e `{}` são
     * indistinguíveis no array PHP decodificado por `$request['...']`.
     */
    #[Test]
    public function anexar_documento_por_modelo_com_variaveis_vazias_serializa_data_como_objeto_json(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/documents' => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
        ]);

        $this->client()->anexarDocumentoPorModelo(self::ENVELOPE_ID, 'Contrato.docx', self::TEMPLATE_ID, []);

        Http::assertSent(function ($request) {
            $corpoBruto = $request->body();

            $this->assertStringContainsString('"data":{}', $corpoBruto);
            $this->assertStringNotContainsString('"data":[]', $corpoBruto);

            return true;
        });
    }

    #[Test]
    public function anexar_documento_por_modelo_com_chave_numerica_lanca_excecao_sem_requisicao(): void
    {
        Http::fake();

        try {
            $this->client()->anexarDocumentoPorModelo(self::ENVELOPE_ID, 'Contrato.docx', self::TEMPLATE_ID, [0 => 'valor']);
            $this->fail('Esperava ClicksignException por chave numérica em $variaveis.');
        } catch (ClicksignException $e) {
            // ok — chave numérica faria o hash virar array JSON
        }

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nomesDeArquivoRecusadosPelaApi(): array
    {
        return [
            'extensao pdf'  => ['Sondagem-modelo.pdf'],
            'sem extensao'  => ['contrato'],
            'extensao doc'  => ['contrato.doc'],
            'docx no meio'  => ['contrato.docx.pdf'],
        ];
    }

    /**
     * Regressão MEDIDA no sandbox em 11/08/2026 (gate do plano 126-11), com
     * modelo real cadastrado: documento instanciado a partir de modelo NASCE
     * de um `.docx`, e a API exige que o `filename` reflita isso.
     *
     *   "contrato.docx"          => 201 ACEITO
     *   "contrato_sondagem.docx" => 201 ACEITO
     *   "Sondagem-modelo.pdf"    => 400 "filename não está em um formato válido"
     *   "contrato" (sem extensão) => 400, mesma mensagem
     *
     * O comando `clicksign:sondar-modelo` mandava `.pdf` e falhava contra a
     * API real — o `Http::fake()` do plano 126-07 não tinha como pegar.
     * A guarda é local para não gastar uma das 20 requisições/min descobrindo
     * isso, e porque a mensagem da API não diz qual é o formato certo.
     */
    #[Test]
    #[DataProvider('nomesDeArquivoRecusadosPelaApi')]
    public function anexar_documento_por_modelo_exige_extensao_docx_no_filename(string $nomeInvalido): void
    {
        Http::fake();

        try {
            $this->client()->anexarDocumentoPorModelo(
                self::ENVELOPE_ID,
                $nomeInvalido,
                self::TEMPLATE_ID,
                ['razao_social' => 'Empresa Teste']
            );
            $this->fail("Esperava ClicksignException para o filename \"{$nomeInvalido}\".");
        } catch (ClicksignException $e) {
            $this->assertStringContainsString('.docx', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * O caminho de UPLOAD de binário (`anexarDocumento`) continua usando
     * `.pdf` — a exigência de `.docx` é só do caminho de modelo. Este teste
     * existe para a guarda nova não ser generalizada por engano para o outro
     * método numa refatoração futura.
     */
    #[Test]
    public function anexar_documento_por_upload_continua_aceitando_pdf(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/documents' => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
        ]);

        $this->client()->anexarDocumento(self::ENVELOPE_ID, 'contrato.pdf', '%PDF-1.4 binário falso');

        Http::assertSentCount(1);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nomesDeVariavelComCaractereProibido(): array
    {
        return [
            'arroba'    => ['razao@social'],
            'cerquilha' => ['razao#social'],
            'exclamacao' => ['razao!social'],
        ];
    }

    #[Test]
    #[DataProvider('nomesDeVariavelComCaractereProibido')]
    public function anexar_documento_por_modelo_com_caractere_proibido_no_nome_lanca_excecao_sem_requisicao(string $nomeInvalido): void
    {
        Http::fake();

        try {
            $this->client()->anexarDocumentoPorModelo(self::ENVELOPE_ID, 'Contrato.docx', self::TEMPLATE_ID, [$nomeInvalido => 'valor']);
            $this->fail("Esperava ClicksignException para o nome de variável \"{$nomeInvalido}\".");
        } catch (ClicksignException $e) {
            // ok — @, # e ! são recusados pela Clicksign no cadastro do modelo (medido, §9.4)
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function anexar_documento_por_modelo_propaga_o_400_medido_se_a_api_recusar_o_hash(): void
    {
        // Não é o client que erra aqui — a guarda acima impede array numérico
        // e serializa vazio como objeto. Esta é uma prova de que, SE a API um
        // dia devolver esse erro medido (§9.6), a mensagem chega ao chamador
        // sem ser mascarada por um diagnóstico genérico.
        Http::fake([
            self::BASE . '/envelopes/*/documents' => Http::response(ClicksignSandboxFixtures::erro400TemplateDataNaoEhHash(), 400),
        ]);

        try {
            $this->client()->anexarDocumentoPorModelo(self::ENVELOPE_ID, 'Contrato.docx', self::TEMPLATE_ID, ['razao_social' => 'Empresa Teste']);
            $this->fail('Esperava ClicksignException por 400.');
        } catch (ClicksignException $e) {
            $this->assertSame(400, $e->httpStatus);
            $this->assertSame('/data/attributes/template/data', $e->ponteiro);
        }
    }

    // ─── montarEnvelopePorModelo() ───

    #[Test]
    public function montar_envelope_por_modelo_caminho_feliz_consome_19_chamadas(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);

        Http::fake([
            self::BASE . '/envelopes'                => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
            self::BASE . '/envelopes/*/documents'     => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
            self::BASE . '/envelopes/*/signers'       => Http::response(ClicksignSandboxFixtures::signatarioCriado(), 200),
            self::BASE . '/envelopes/*/requirements'  => Http::response(ClicksignSandboxFixtures::requisitoCriado(), 200),
            self::BASE . '/envelopes/*'               => Http::response(ClicksignSandboxFixtures::envelopeAtivado(), 200),
        ]);

        $resultado = $this->client()->montarEnvelopePorModelo(
            ['name' => 'Contrato de teste — ECF Admin'],
            'Contrato de teste.docx',
            self::TEMPLATE_ID,
            ['razao_social' => 'Empresa Teste LTDA'],
            ['nome' => 'Cliente Teste', 'email' => 'cliente@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE]
        );

        // criar (1) + anexar por modelo (1) + 4 signatários × (signer +
        // qualificação + autenticação + rubrica) (16) + ativar (1) = 19.
        // Rubrica acrescentada no quick 260824-mv3 — eram 15.
        Http::assertSentCount(19);

        $this->assertSame(self::ENVELOPE_ID, $resultado['envelope_id']);
        $this->assertSame(self::DOCUMENT_ID, $resultado['document_id']);
        $this->assertCount(4, $resultado['signatarios']);
    }

    #[Test]
    public function montar_envelope_por_modelo_falha_no_meio_cancela_o_envelope_e_propaga_o_erro_original(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosEcfDeTeste()]);

        Http::fake([
            self::BASE . '/envelopes'                => Http::response(ClicksignSandboxFixtures::envelopeCriado(), 200),
            self::BASE . '/envelopes/*/documents'     => Http::response(ClicksignSandboxFixtures::documentoCriado(), 200),
            self::BASE . '/envelopes/*/signers'       => Http::response(ClicksignSandboxFixtures::signatarioCriado(), 200),
            self::BASE . '/envelopes/*/requirements'  => Http::response(
                ['errors' => [['code' => 'unprocessable_entity', 'status' => 422, 'detail' => 'role inválido']]],
                422
            ),
            self::BASE . '/envelopes/*'               => Http::response('', ClicksignSandboxFixtures::envelopeDescartadoStatusHttp()),
        ]);

        try {
            $this->client()->montarEnvelopePorModelo(
                ['name' => 'Contrato de teste — ECF Admin'],
                'Contrato de teste.docx',
                self::TEMPLATE_ID,
                ['razao_social' => 'Empresa Teste LTDA'],
                ['nome' => 'Cliente Teste', 'email' => 'cliente@example.com', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE]
            );
            $this->fail('Esperava a ClicksignException original propagada, não a de cancelamento.');
        } catch (ClicksignException $e) {
            $this->assertSame(422, $e->httpStatus);
        }

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && $request->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID;
        });

        // criar envelope (1) + anexar documento por modelo (1) + 1º signatário (1) +
        // 1º requisito, que falha (1) + cancelamento (1) = 5.
        Http::assertSentCount(5);
    }

    // ─── 403: diagnóstico certo para cada causa ───

    #[Test]
    public function erro_403_de_plano_sem_acesso_a_modelos_cita_essa_causa_e_nao_a_de_email_da_api(): void
    {
        Http::fake([
            self::BASE . '/templates*' => Http::response(ClicksignSandboxFixtures::erro403ContaSemAcessoAModelos(), 403),
        ]);

        try {
            $this->client()->listarModelos();
            $this->fail('Esperava ClicksignException por 403.');
        } catch (ClicksignException $e) {
            $this->assertStringContainsString('A conta não possui acesso a essa funcionalidade', $e->getMessage());
            $this->assertNotSame(
                'A Clicksign recusou a chamada: o e-mail do usuário da API não está configurado no painel da Clicksign (Configurações → API).',
                $e->getMessage()
            );
            $this->assertSame(403, $e->httpStatus);
        }
    }

    #[Test]
    public function erro_403_de_email_da_api_nao_configurado_continua_apontando_configuracoes_api(): void
    {
        Http::fake([
            self::BASE . '/templates*' => Http::response(ClicksignSandboxFixtures::erro403EmailApiNaoConfigurado(), 403),
        ]);

        try {
            $this->client()->listarModelos();
            $this->fail('Esperava ClicksignException por 403.');
        } catch (ClicksignException $e) {
            $this->assertStringContainsString('Configurações → API', $e->getMessage());
            $this->assertStringContainsString('e-mail do usuário da API', $e->getMessage());
            $this->assertSame(403, $e->httpStatus);
        }
    }
}
