<?php

namespace Tests\Feature\Phase126;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\Clicksign\ContratoVariaveisModeloService;
use App\Services\ContratoPdfService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Contrato da ponte `montarDados()` (aninhado) → `template.data` (plano) —
 * Fase 126, plano 09 (PDF-01). Especificação executável de
 * `ContratoVariaveisModeloService`, escrita ANTES da implementação (RED).
 *
 * Lista de variáveis: `126-VARIAVEIS-DO-MODELO.md` §4 ("Lista final"),
 * fechada pelas decisões D-19 (serviços concatenados numa variável só, um
 * envelope por empresa) e D-20 (rodapé literal, fora do escopo de código
 * deste plano — não vira variável).
 */
class ContratoVariaveisModeloTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Mesmo molde de `ContratoPdfDadosTest::contratoComSnapshot()` — contato
     * preenchido por padrão, para isolar cada teste do que ele realmente
     * quer provar.
     */
    private function contratoComSnapshot(array $atributosCompany = []): ContratoAssinatura
    {
        $atributosPadrao = [
            'nome_contato'  => 'Contato Padrão do Teste',
            'email_cliente' => 'contato@empresa-teste.com.br',
            'telefone'      => '(11) 4000-0000',
        ];

        return ContratoAssinatura::factory()
            // Quick 260825-fn0 — mesmo item ÚNICO padrão da factory
            // (`comSnapshot()` sem args), mas com `plataforma` preenchida:
            // os testes desta classe que dependem de `campos_pendentes`
            // (ex.: lista exata `['forma_pagamento']`) continuam isolados
            // do que já testavam antes deste quick. Os testes de
            // PLATAFORMA propriamente ditos montam o próprio snapshot
            // explicitamente, mais abaixo.
            ->comSnapshot([
                [
                    'servico'          => 'Gestão de Tráfego — Mercado Livre',
                    'plataforma'       => 'Mercado Livre',
                    'valor_contratado' => 1847.32,
                    'data_contratacao' => '2026-01-15',
                    'data_vencimento'  => '2027-01-15',
                ],
            ])
            ->for(Company::factory()->state(array_merge($atributosPadrao, $atributosCompany)), 'company')
            ->create();
    }

    private function service(): ContratoVariaveisModeloService
    {
        return new ContratoVariaveisModeloService(new ContratoPdfService());
    }

    #[Test]
    public function montar_devolve_um_array_com_exatamente_as_chaves_variaveis_e_campos_pendentes(): void
    {
        $contrato = $this->contratoComSnapshot();

        $resultado = $this->service()->montar($contrato);

        $this->assertSame(['variaveis', 'campos_pendentes'], array_keys($resultado));
    }

    #[Test]
    public function toda_chave_de_variaveis_casa_com_a_regra_de_nome_da_clicksign(): void
    {
        $contrato = $this->contratoComSnapshot();

        $resultado = $this->service()->montar($contrato);

        $this->assertNotEmpty($resultado['variaveis']);

        foreach (array_keys($resultado['variaveis']) as $chave) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $chave,
                "Chave '{$chave}' não obedece à regra de nome da Clicksign (minúscula, sem acento/espaço/@/#/!)."
            );
        }
    }

    #[Test]
    public function conjunto_de_chaves_de_variaveis_e_exatamente_igual_a_nomes_mesmo_sem_complementos(): void
    {
        // Sem nenhum $complementos preenchido — o caso que mais tenta esconder
        // variável, já que metade dos campos cai no placeholder.
        $contrato = $this->contratoComSnapshot();

        $resultado = $this->service()->montar($contrato);

        $nomesEsperados = ContratoVariaveisModeloService::nomes();
        sort($nomesEsperados);

        $chavesObtidas = array_keys($resultado['variaveis']);
        sort($chavesObtidas);

        $this->assertSame($nomesEsperados, $chavesObtidas);
    }

    #[Test]
    public function campo_ausente_vale_exatamente_a_definir_via_a_constante_do_service_de_pdf(): void
    {
        $contrato = $this->contratoComSnapshot();

        $resultado = $this->service()->montar($contrato);

        // Referencia a constante — nunca a string 'A DEFINIR' redigitada.
        $this->assertSame(ContratoPdfService::PLACEHOLDER, $resultado['variaveis']['endereco']);
        $this->assertSame(ContratoPdfService::PLACEHOLDER, $resultado['variaveis']['dia_vencimento']);
        $this->assertSame(ContratoPdfService::PLACEHOLDER, $resultado['variaveis']['data_primeira_parcela']);

        foreach ($resultado['variaveis'] as $chave => $valor) {
            $this->assertNotNull($valor, "Variável '{$chave}' veio null — documento jurídico não pode ter campo em branco silencioso.");
            $this->assertNotSame('', $valor, "Variável '{$chave}' veio string vazia — documento jurídico não pode ter campo em branco silencioso.");
        }
    }

    #[Test]
    public function razao_social_cnpj_valor_mensal_e_vigencia_batem_com_montardados_para_o_mesmo_contrato(): void
    {
        $contrato = $this->contratoComSnapshot([
            'name' => 'Empresa Exemplo Ltda',
            'cnpj' => '12.345.678/0001-90',
        ]);

        $pdfService = new ContratoPdfService();
        $dados      = $pdfService->montarDados($contrato);

        $resultado = (new ContratoVariaveisModeloService($pdfService))->montar($contrato);

        // A ponte não reformata nem recalcula — repassa exatamente o que
        // montarDados() já produziu.
        $this->assertSame($dados['empresa']['razao_social'], $resultado['variaveis']['razao_social']);
        $this->assertSame($dados['empresa']['cnpj'], $resultado['variaveis']['cnpj']);
        $this->assertSame($dados['totais']['valor_mensal_formatado'], $resultado['variaveis']['valor_mensal']);
        $this->assertSame($dados['vigencia']['inicio'], $resultado['variaveis']['vigencia_inicio']);
        $this->assertSame($dados['vigencia']['fim'], $resultado['variaveis']['vigencia_fim']);
    }

    #[Test]
    public function alterar_contratos_servico_ao_vivo_depois_do_snapshot_nao_muda_nenhuma_variavel(): void
    {
        // D-04 herdado: mesmo caso vivido do hs_mrr=0 do HubSpot que já zerou
        // 3 contratos de R$ 3.000 lendo dado "ao vivo" — aqui grava um valor
        // divergente em contratos_servico e prova que a ponte ignora essa
        // tabela por completo (ela nem consulta ContratoServico).
        $contrato = $this->contratoComSnapshot();

        $servico = Servico::create([
            'nome'          => 'Serviço Ao Vivo (não deve aparecer na variável)',
            'valor_padrao'  => 99999.99,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);
        ContratoServico::create([
            'company_id'       => $contrato->company_id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 99999.99,
            'data_contratacao' => '2020-01-01',
            'data_vencimento'  => '2020-12-31',
            'ativo'            => true,
        ]);

        $resultado = $this->service()->montar($contrato);

        $this->assertStringNotContainsString('99.999,99', $resultado['variaveis']['valor_mensal']);
        $this->assertStringNotContainsString('Serviço Ao Vivo', $resultado['variaveis']['servico_contratado']);
    }

    #[Test]
    public function data_assinatura_sai_por_extenso_em_pt_br_derivada_de_gerado_em(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 10, 14, 32));

        $contrato = $this->contratoComSnapshot();

        $resultado = $this->service()->montar($contrato);

        $this->assertSame('10 de agosto de 2026', $resultado['variaveis']['data_assinatura']);
    }

    #[Test]
    public function campos_pendentes_repassa_fielmente_a_lista_de_montardados(): void
    {
        $contrato = $this->contratoComSnapshot();

        $pdfService = new ContratoPdfService();
        $dados      = $pdfService->montarDados($contrato);

        $resultado = (new ContratoVariaveisModeloService($pdfService))->montar($contrato);

        $this->assertSame($dados['campos_pendentes'], $resultado['campos_pendentes']);
        $this->assertNotEmpty($resultado['campos_pendentes'], 'Este contrato de teste não preenche complementos — a pendência tem que aparecer.');
    }

    #[Test]
    public function nomes_e_chamavel_sem_contrato_e_devolve_lista_sem_repeticao(): void
    {
        $nomes = ContratoVariaveisModeloService::nomes();

        $this->assertNotEmpty($nomes);
        $this->assertSame(array_values(array_unique($nomes)), array_values($nomes));

        foreach ($nomes as $nome) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $nome);
        }
    }

    // ─── Quick 260819-guy — as 4 variáveis saem com valor real quando o complemento existe ───

    #[Test]
    public function razao_social_endereco_dia_vencimento_e_data_primeira_parcela_saem_com_valor_real(): void
    {
        $contrato = $this->contratoComSnapshot([
            'name'         => 'Empresa Fantasia Ltda',
            'razao_social' => 'Empresa Fantasia Comércio e Serviços Ltda',
        ]);

        $resultado = $this->service()->montar($contrato, [
            'endereco'              => 'Rua Exemplo, 123',
            // Quick 260821-cq0 — bairro/cidade/estado/cep são variáveis
            // próprias, mesma disciplina de endereco/dia_vencimento/
            // data_primeira_parcela testados aqui.
            'bairro'                => 'Centro',
            'cidade'                => 'São Paulo',
            'estado'                => 'SP',
            'cep'                   => '01000-000',
            'dia_vencimento'        => '10',
            'data_primeira_parcela' => '2026-09-05',
        ]);

        $this->assertSame('Empresa Fantasia Comércio e Serviços Ltda', $resultado['variaveis']['razao_social']);
        $this->assertSame('Rua Exemplo, 123', $resultado['variaveis']['endereco']);
        $this->assertSame('Centro', $resultado['variaveis']['bairro']);
        $this->assertSame('São Paulo', $resultado['variaveis']['cidade']);
        $this->assertSame('SP', $resultado['variaveis']['estado']);
        $this->assertSame('01000-000', $resultado['variaveis']['cep']);
        $this->assertSame('10', $resultado['variaveis']['dia_vencimento']);
        $this->assertSame('05/09/2026', $resultado['variaveis']['data_primeira_parcela']);
        // `forma_pagamento` não é uma variável do modelo (não está em
        // `mapa()`) — continua pendente mesmo com os complementos desta
        // Tarefa preenchidos; não é o que este teste mede.
        $this->assertSame(['forma_pagamento'], $resultado['campos_pendentes']);
    }

    #[Test]
    public function ausencia_de_dado_cai_em_campos_pendentes_em_vez_de_quebrar(): void
    {
        // Sem complementos e sem razao_social/endereco na empresa — o
        // caminho que hoje é bloqueado ANTES pelo ContratoDadosMinimosService
        // (Tarefa 3), mas esta ponte continua sendo defensiva por conta
        // própria: nunca deve lançar exceção, sempre placeholder + pendência.
        $contrato = $this->contratoComSnapshot(['razao_social' => null]);

        $resultado = $this->service()->montar($contrato);

        $this->assertSame(ContratoPdfService::PLACEHOLDER, $resultado['variaveis']['endereco']);
        $this->assertSame(ContratoPdfService::PLACEHOLDER, $resultado['variaveis']['dia_vencimento']);
        $this->assertSame(ContratoPdfService::PLACEHOLDER, $resultado['variaveis']['data_primeira_parcela']);
        $this->assertContains('endereco', $resultado['campos_pendentes']);
        $this->assertContains('dia_vencimento', $resultado['campos_pendentes']);
        $this->assertContains('data_primeira_parcela', $resultado['campos_pendentes']);
    }

    #[Test]
    public function com_dois_servicos_no_snapshot_servico_contratado_concatena_os_dois_nomes(): void
    {
        // D-19 (opção B): um envelope por empresa, N serviços viram UMA
        // variável concatenada — não um serviço por índice, não tabela em loop.
        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Gestão de ADS para Mercado Livre',
                    'valor_contratado' => 1500.0,
                    'data_contratacao' => '2026-01-01',
                    'data_vencimento'  => '2027-01-01',
                ],
                [
                    'servico'          => 'Gestão de ADS para Shopee',
                    'valor_contratado' => 800.0,
                    'data_contratacao' => '2026-01-01',
                    'data_vencimento'  => '2027-01-01',
                ],
            ])
            ->for(Company::factory(), 'company')
            ->create();

        $resultado = $this->service()->montar($contrato);

        $this->assertSame(
            'Gestão de ADS para Mercado Livre e Gestão de ADS para Shopee',
            $resultado['variaveis']['servico_contratado']
        );
    }

    // ─── Quick 260821-m9h — {{plano_parcelas}} do modelo de Gestão (caso simples) ───

    #[Test]
    public function plano_parcelas_emite_a_frase_constante_do_caso_simples(): void
    {
        $contrato = $this->contratoComSnapshot();

        $resultado = $this->service()->montar($contrato);

        $this->assertSame(
            ContratoVariaveisModeloService::PLANO_PARCELAS_CASO_SIMPLES,
            $resultado['variaveis']['plano_parcelas']
        );
        $this->assertSame(
            'As parcelas seguirão a faixa apurada na forma da Cláusula 2.1.2.',
            $resultado['variaveis']['plano_parcelas']
        );
    }

    #[Test]
    public function nomes_inclui_plano_parcelas(): void
    {
        $this->assertContains('plano_parcelas', ContratoVariaveisModeloService::nomes());
    }

    // ─── Quick 260824-bte — pagamento escalonado: plano_parcelas passa a compor ───

    #[Test]
    public function plano_parcelas_compoe_a_frase_das_duas_fases_do_mesmo_servico(): void
    {
        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Gestão de Ads (Mons Bike)',
                    'valor_contratado' => 5500.0,
                    'data_contratacao' => '2026-01-01',
                    'data_vencimento'  => null,
                    'parcelas'         => 3,
                ],
                [
                    'servico'          => 'Gestão de Ads (Mons Bike)',
                    'valor_contratado' => 6000.0,
                    'data_contratacao' => '2026-12-01',
                    'data_vencimento'  => null,
                    'parcelas'         => 9,
                ],
            ])
            ->for(Company::factory(), 'company')
            ->create();

        $resultado = $this->service()->montar($contrato);

        $this->assertSame(
            'As 3 (três) primeiras parcelas corresponderão a R$ 5.500,00 e as 9 (nove) demais a R$ 6.000,00.',
            $resultado['variaveis']['plano_parcelas']
        );
    }

    #[Test]
    public function plano_parcelas_com_override_gravado_no_contrato_usa_o_texto_literal(): void
    {
        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Gestão de Ads (Mons Bike)',
                    'valor_contratado' => 5500.0,
                    'data_contratacao' => '2026-01-01',
                    'data_vencimento'  => null,
                    'parcelas'         => 3,
                ],
                [
                    'servico'          => 'Gestão de Ads (Mons Bike)',
                    'valor_contratado' => 6000.0,
                    'data_contratacao' => '2026-12-01',
                    'data_vencimento'  => null,
                    'parcelas'         => 9,
                ],
            ])
            ->for(Company::factory(), 'company')
            ->create(['plano_parcelas_texto' => 'Texto combinado à mão com o cliente.']);

        $resultado = $this->service()->montar($contrato);

        $this->assertSame('Texto combinado à mão com o cliente.', $resultado['variaveis']['plano_parcelas']);
    }

    /**
     * Duas FASES do MESMO serviço (mesmo nome repetido no snapshot) não
     * podem virar "Gestão e Gestão" em `servico_contratado` — dedupe pelo
     * nome (quick 260824-bte).
     */
    #[Test]
    public function servico_contratado_nao_repete_o_nome_quando_as_duas_entradas_sao_fases_do_mesmo_servico(): void
    {
        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Gestão de Ads (Mons Bike)',
                    'valor_contratado' => 5500.0,
                    'data_contratacao' => '2026-01-01',
                    'data_vencimento'  => null,
                    'parcelas'         => 3,
                ],
                [
                    'servico'          => 'Gestão de Ads (Mons Bike)',
                    'valor_contratado' => 6000.0,
                    'data_contratacao' => '2026-12-01',
                    'data_vencimento'  => null,
                    'parcelas'         => 9,
                ],
            ])
            ->for(Company::factory(), 'company')
            ->create();

        $resultado = $this->service()->montar($contrato);

        $this->assertSame('Gestão de Ads (Mons Bike)', $resultado['variaveis']['servico_contratado']);
    }

    // ─── Quick 260825-fn0 — {{plataformas}}: a plataforma sai do serviço ───

    #[Test]
    public function nomes_inclui_plataformas(): void
    {
        $this->assertContains('plataformas', ContratoVariaveisModeloService::nomes());
    }

    #[Test]
    public function plataformas_com_valor_configurado_sai_o_nome_da_plataforma(): void
    {
        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Gestão de Ads',
                    'plataforma'       => 'Mercado Livre',
                    'valor_contratado' => 1500.0,
                    'data_contratacao' => '2026-01-01',
                    'data_vencimento'  => '2027-01-01',
                ],
            ])
            ->for(Company::factory(), 'company')
            ->create();

        $resultado = $this->service()->montar($contrato);

        $this->assertSame('Mercado Livre', $resultado['variaveis']['plataformas']);
        $this->assertNotContains('plataformas', $resultado['campos_pendentes']);
    }

    /**
     * Serviço sem `plataforma` (ou snapshot antigo sem a chave) — a ponte
     * não decide nada, só repassa o que `montarDados()` já apurou: o mesmo
     * placeholder `A DEFINIR` e a mesma pendência.
     */
    #[Test]
    public function plataformas_sem_valor_configurado_repassa_o_placeholder_e_a_pendencia_de_montardados(): void
    {
        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Gestão de Ads',
                    // Sem a chave 'plataforma' — snapshot antigo.
                    'valor_contratado' => 1500.0,
                    'data_contratacao' => '2026-01-01',
                    'data_vencimento'  => '2027-01-01',
                ],
            ])
            ->for(Company::factory(), 'company')
            ->create();

        $resultado = $this->service()->montar($contrato);

        $this->assertSame(ContratoPdfService::PLACEHOLDER, $resultado['variaveis']['plataformas']);
        $this->assertContains('plataformas', $resultado['campos_pendentes']);
    }

    #[Test]
    public function plataformas_com_dois_servicos_de_plataformas_diferentes_concatena_com_e(): void
    {
        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Gestão de Ads',
                    'plataforma'       => 'Mercado Livre',
                    'valor_contratado' => 1500.0,
                    'data_contratacao' => '2026-01-01',
                    'data_vencimento'  => '2027-01-01',
                ],
                [
                    'servico'          => 'Gestão Shopee',
                    'plataforma'       => 'Shopee',
                    'valor_contratado' => 800.0,
                    'data_contratacao' => '2026-01-01',
                    'data_vencimento'  => '2027-01-01',
                ],
            ])
            ->for(Company::factory(), 'company')
            ->create();

        $resultado = $this->service()->montar($contrato);

        $this->assertSame('Mercado Livre e Shopee', $resultado['variaveis']['plataformas']);
    }

    /**
     * Duas FASES do mesmo serviço têm a mesma plataforma — aparece UMA VEZ
     * SÓ na variável, mesma disciplina de `servico_contratado` acima.
     */
    #[Test]
    public function plataformas_com_duas_fases_do_mesmo_servico_aparece_uma_vez_so(): void
    {
        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Gestão de Ads (Mons Bike)',
                    'plataforma'       => 'Mercado Livre',
                    'valor_contratado' => 5500.0,
                    'data_contratacao' => '2026-01-01',
                    'data_vencimento'  => null,
                    'parcelas'         => 3,
                ],
                [
                    'servico'          => 'Gestão de Ads (Mons Bike)',
                    'plataforma'       => 'Mercado Livre',
                    'valor_contratado' => 6000.0,
                    'data_contratacao' => '2026-12-01',
                    'data_vencimento'  => null,
                    'parcelas'         => 9,
                ],
            ])
            ->for(Company::factory(), 'company')
            ->create();

        $resultado = $this->service()->montar($contrato);

        $this->assertSame('Mercado Livre', $resultado['variaveis']['plataformas']);
    }

    /**
     * T-126-40: `ContratoVariaveisModeloService` continua PURA — nenhuma
     * chamada a `DB::`/`Http::`/`Log::`/`Cache::`/`Storage::` no arquivo
     * inteiro. Mesma técnica de `montardados_nao_depende_de_nenhuma_view()`
     * em `ContratoPdfDadosTest`: lido com comentários removidos, para que os
     * próprios comentários que citam essas palavras (como este docblock, se
     * estivesse no arquivo de produção) não confundam a checagem.
     */
    #[Test]
    public function service_continua_puro_sem_db_http_log_cache_ou_storage(): void
    {
        $caminho = app_path('Services/Clicksign/ContratoVariaveisModeloService.php');
        $this->assertFileExists($caminho, 'ContratoVariaveisModeloService.php ainda não existe.');

        $conteudo     = file_get_contents($caminho);
        $semBlocos    = preg_replace('/\/\*.*?\*\//s', '', $conteudo);
        $semComentarios = preg_replace('/\/\/.*$/m', '', $semBlocos);

        $this->assertDoesNotMatchRegularExpression('/\bDB::/', $semComentarios);
        $this->assertDoesNotMatchRegularExpression('/\bHttp::/', $semComentarios);
        $this->assertDoesNotMatchRegularExpression('/\bLog::/', $semComentarios);
        $this->assertDoesNotMatchRegularExpression('/\bCache::/', $semComentarios);
        $this->assertDoesNotMatchRegularExpression('/\bStorage::/', $semComentarios);
    }
}
