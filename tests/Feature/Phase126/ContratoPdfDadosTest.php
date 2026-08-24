<?php

namespace Tests\Feature\Phase126;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\ContratoPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Contrato de dados do PDF — Fase 126, plano 04 (PDF-01/PDF-02).
 *
 * Este teste é a especificação executável de `ContratoPdfService::montarDados()`,
 * escrito ANTES da implementação (RED). Cobre o bloco `<decisao_da_tensao_de_dados>`
 * do plano 126-04: os dados saem do `servicos_snapshot` congelado (D-04), nunca de
 * `contratos_servico` ao vivo, e os campos que ainda não existem no banco (dia de
 * vencimento, forma de pagamento, endereço) aparecem como placeholder visível
 * `A DEFINIR` — nunca em branco silencioso, num documento com validade jurídica.
 */
class ContratoPdfDadosTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Monta um contrato com snapshot de 3 serviços, com uma empresa cujo
     * contato já está preenchido por padrão — isola os testes de
     * `campos_pendentes` para o que cada um realmente quer provar (D-05: só
     * dia de vencimento, forma de pagamento e endereço são garantidamente
     * ausentes no banco hoje). `$atributosCompany` sobrescreve o padrão
     * quando um teste específico precisa simular contato vazio/nulo.
     *
     * Fase 127-01 (D-06): o DEFAULT de `comSnapshot()` da factory passou a
     * ser um único item (um ContratoAssinatura representa um serviço só).
     * Este helper passa a lista de 3 serviços EXPLICITAMENTE — o teste
     * `contrato_com_tres_servicos_no_snapshot_...` continua provando o
     * caso de snapshot com múltiplos itens (empresa com vários serviços
     * concatenados no mesmo snapshot), só que agora como comportamento
     * suportado, não mais como default da factory.
     */
    private function contratoComSnapshot(array $atributosCompany = []): ContratoAssinatura
    {
        $atributosPadrao = [
            'nome_contato'  => 'Contato Padrão do Teste',
            'email_cliente' => 'contato@empresa-teste.com.br',
            'telefone'      => '(11) 4000-0000',
        ];

        return ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Gestão de Tráfego — Mercado Livre',
                    'valor_contratado' => 1847.32,
                    'data_contratacao' => '2026-01-15',
                    'data_vencimento'  => '2027-01-15',
                ],
                [
                    'servico'          => 'Consultoria Shopee',
                    'valor_contratado' => 923.9,
                    'data_contratacao' => '2026-02-01',
                    'data_vencimento'  => '2027-02-01',
                ],
                [
                    'servico'          => 'Análise de Sugadores',
                    'valor_contratado' => 412.55,
                    'data_contratacao' => '2026-03-10',
                    'data_vencimento'  => '2027-03-10',
                ],
            ])
            ->for(Company::factory()->state(array_merge($atributosPadrao, $atributosCompany)), 'company')
            ->create();
    }

    #[Test]
    public function contrato_com_tres_servicos_no_snapshot_produz_tres_entradas_formatadas_em_pt_br(): void
    {
        $contrato = $this->contratoComSnapshot();

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $this->assertCount(3, $dados['servicos']);

        foreach ($dados['servicos'] as $servico) {
            $this->assertMatchesRegularExpression('/^R\$\s[\d.,]+$/', $servico['valor_formatado']);
            $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4}$/', $servico['inicio']);
            $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4}$/', $servico['fim']);
        }

        // Valor formatado no padrão brasileiro exato para o primeiro serviço do state
        // comSnapshot() da factory (1847.32 → "R$ 1.847,32").
        $this->assertSame('R$ 1.847,32', $dados['servicos'][0]['valor_formatado']);
    }

    #[Test]
    public function alterar_contratos_servico_ao_vivo_depois_de_montado_nao_muda_o_retorno(): void
    {
        // D-04: o valor que vale juridicamente é o congelado. Traduz direto o caso
        // vivido do `hs_mrr = 0` do HubSpot que já zerou 3 contratos de R$ 3.000 —
        // aqui, o teste grava um valor DIVERGENTE em contratos_servico e prova que
        // montarDados() ignora completamente essa tabela.
        $contrato = $this->contratoComSnapshot();

        $servico = Servico::create([
            'nome'          => 'Serviço Ao Vivo (não deve aparecer no PDF)',
            'valor_padrao'  => 99999.99,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);
        ContratoServico::create([
            'company_id'        => $contrato->company_id,
            'servico_id'        => $servico->id,
            'valor_contratado'  => 99999.99,
            'data_contratacao'  => '2020-01-01',
            'data_vencimento'   => '2020-12-31',
            'ativo'             => true,
        ]);

        $dados = (new ContratoPdfService())->montarDados($contrato);

        foreach ($dados['servicos'] as $servicoDados) {
            $this->assertNotSame('R$ 99.999,99', $servicoDados['valor_formatado']);
        }

        $this->assertSame('R$ 1.847,32', $dados['servicos'][0]['valor_formatado']);
    }

    #[Test]
    public function dados_da_empresa_e_contato_vem_de_companies(): void
    {
        $contrato = $this->contratoComSnapshot([
            'name'          => 'Empresa Exemplo Ltda',
            'cnpj'          => '12.345.678/0001-90',
            'nome_contato'  => 'Maria da Silva',
            'email_cliente' => 'maria@exemplo.com.br',
            'telefone'      => '(11) 99999-0000',
        ]);

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $this->assertSame('Empresa Exemplo Ltda', $dados['empresa']['razao_social']);
        $this->assertSame('12.345.678/0001-90', $dados['empresa']['cnpj']);
        $this->assertSame('Maria da Silva', $dados['contato']['nome']);
        $this->assertSame('maria@exemplo.com.br', $dados['contato']['email']);
        $this->assertSame('(11) 99999-0000', $dados['contato']['telefone']);
    }

    #[Test]
    public function vigencia_usa_a_menor_data_contratacao_e_a_maior_data_vencimento_do_snapshot(): void
    {
        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Serviço A',
                    'valor_contratado' => 100.0,
                    'data_contratacao' => '2026-03-10',
                    'data_vencimento'  => '2027-03-10',
                ],
                [
                    'servico'          => 'Serviço B',
                    'valor_contratado' => 200.0,
                    'data_contratacao' => '2026-01-15',
                    'data_vencimento'  => '2028-06-01',
                ],
            ])
            ->for(Company::factory(), 'company')
            ->create();

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $this->assertSame('15/01/2026', $dados['vigencia']['inicio']);
        $this->assertSame('01/06/2028', $dados['vigencia']['fim']);
    }

    /**
     * Achado do gate do plano 128-06 (medição real contra o sandbox
     * Clicksign): `data_vencimento` nulo é o caso legítimo de "prazo
     * indeterminado" (`ContratoDadosMinimosService::faltantes()`, item 5 —
     * NÃO reprova esse campo vazio), e `ContratoClicksignService` grava
     * exatamente `null` no snapshot congelado quando o `ContratoServico` não
     * tem vencimento. Antes do fix, `montarDados()` lançava `TypeError` ao
     * montar o envelope — quebrando `GerarContratoAssinaturaJob` para todo
     * contrato de prazo indeterminado, o caso mais comum de assinatura
     * recorrente. Regressão: nunca mais `TypeError`, e o texto visível é
     * "Indeterminado" — nunca um branco silencioso num documento com
     * validade jurídica (mesma exigência de `A DEFINIR`).
     */
    #[Test]
    public function data_vencimento_nula_no_snapshot_vira_indeterminado_sem_quebrar(): void
    {
        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Serviço com prazo indeterminado',
                    'valor_contratado' => 100.0,
                    'data_contratacao' => '2026-03-10',
                    'data_vencimento'  => null,
                ],
            ])
            ->for(Company::factory(), 'company')
            ->create();

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $this->assertSame('Indeterminado', $dados['servicos'][0]['fim']);
        $this->assertSame('Indeterminado', $dados['vigencia']['fim']);
        $this->assertSame('10/03/2026', $dados['vigencia']['inicio']);
    }

    /**
     * Um serviço SEM vencimento (indeterminado) misturado com outro que TEM
     * vencimento fixo: o conjunto vira indeterminado — não dá para apurar
     * "a maior data" quando uma delas não existe (ver docblock de
     * `montarVigencia()`).
     */
    #[Test]
    public function um_servico_com_vencimento_indeterminado_torna_a_vigencia_do_conjunto_indeterminada(): void
    {
        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Serviço com prazo fixo',
                    'valor_contratado' => 100.0,
                    'data_contratacao' => '2026-01-15',
                    'data_vencimento'  => '2027-01-15',
                ],
                [
                    'servico'          => 'Serviço com prazo indeterminado',
                    'valor_contratado' => 200.0,
                    'data_contratacao' => '2026-03-10',
                    'data_vencimento'  => null,
                ],
            ])
            ->for(Company::factory(), 'company')
            ->create();

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $this->assertSame('Indeterminado', $dados['vigencia']['fim']);
    }

    #[Test]
    public function sem_complementos_os_oito_campos_ausentes_no_banco_viram_a_definir_e_entram_em_campos_pendentes(): void
    {
        // Quick 260819-guy — passou de 3 para 4: `data_primeira_parcela`
        // deixou de ser placeholder FIXO (`ContratoVariaveisModeloService`)
        // e passou a cair na mesma disciplina de complemento opcional dos
        // outros três.
        //
        // Quick 260821-cq0 — passou de 4 para 8: bairro/cidade/estado/cep
        // entram na mesma disciplina de `endereco`.
        $contrato = $this->contratoComSnapshot();

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $this->assertSame('A DEFINIR', $dados['pagamento']['dia_vencimento']);
        $this->assertSame('A DEFINIR', $dados['pagamento']['forma_pagamento']);
        $this->assertSame('A DEFINIR', $dados['pagamento']['data_primeira_parcela']);
        $this->assertSame('A DEFINIR', $dados['empresa']['endereco']);
        $this->assertSame('A DEFINIR', $dados['empresa']['bairro']);
        $this->assertSame('A DEFINIR', $dados['empresa']['cidade']);
        $this->assertSame('A DEFINIR', $dados['empresa']['estado']);
        $this->assertSame('A DEFINIR', $dados['empresa']['cep']);

        $this->assertSame(
            ['bairro', 'cep', 'cidade', 'data_primeira_parcela', 'dia_vencimento', 'endereco', 'estado', 'forma_pagamento'],
            $dados['campos_pendentes']
        );
    }

    #[Test]
    public function com_complementos_completos_nenhum_placeholder_aparece_e_campos_pendentes_fica_vazio(): void
    {
        $contrato = $this->contratoComSnapshot();

        $dados = (new ContratoPdfService())->montarDados($contrato, [
            'dia_vencimento'         => 'Todo dia 10',
            'forma_pagamento'        => 'Boleto bancário',
            'endereco'               => 'Rua Exemplo, 123',
            // Quick 260821-cq0 — bairro/cidade/estado/cep, mesma disciplina.
            'bairro'                 => 'Centro',
            'cidade'                 => 'São Paulo',
            'estado'                 => 'SP',
            'cep'                    => '01000-000',
            // Quick 260819-guy.
            'data_primeira_parcela'  => '2026-09-05',
        ]);

        $this->assertSame('Todo dia 10', $dados['pagamento']['dia_vencimento']);
        $this->assertSame('Boleto bancário', $dados['pagamento']['forma_pagamento']);
        $this->assertSame('Rua Exemplo, 123', $dados['empresa']['endereco']);
        $this->assertSame('Centro', $dados['empresa']['bairro']);
        $this->assertSame('São Paulo', $dados['empresa']['cidade']);
        $this->assertSame('SP', $dados['empresa']['estado']);
        $this->assertSame('01000-000', $dados['empresa']['cep']);
        // formatarData() converte 'Y-m-d' para 'd/m/Y' (pt-BR), igual às
        // demais datas do documento.
        $this->assertSame('05/09/2026', $dados['pagamento']['data_primeira_parcela']);
        $this->assertSame([], $dados['campos_pendentes']);
    }

    #[Test]
    public function razao_social_usa_a_coluna_nova_com_fallback_para_o_nome_fantasia(): void
    {
        // Quick 260819-guy — a coluna nova (`companies.razao_social`) tem
        // prioridade; quando vazia, cai no fallback `Company::name` (nome
        // fantasia) — nunca em branco/A DEFINIR enquanto `name` existir.
        $comRazaoSocial = $this->contratoComSnapshot([
            'name'         => 'Empresa Fantasia Ltda',
            'razao_social' => 'Empresa Fantasia Comércio e Serviços Ltda',
        ]);
        $dadosComRazaoSocial = (new ContratoPdfService())->montarDados($comRazaoSocial);
        $this->assertSame('Empresa Fantasia Comércio e Serviços Ltda', $dadosComRazaoSocial['empresa']['razao_social']);

        $semRazaoSocial = $this->contratoComSnapshot([
            'name'         => 'Empresa Só Com Nome Fantasia Ltda',
            'razao_social' => null,
        ]);
        $dadosSemRazaoSocial = (new ContratoPdfService())->montarDados($semRazaoSocial);
        $this->assertSame('Empresa Só Com Nome Fantasia Ltda', $dadosSemRazaoSocial['empresa']['razao_social']);
        $this->assertNotContains('razao_social', $dadosSemRazaoSocial['campos_pendentes']);
    }

    #[Test]
    public function campo_nulo_no_banco_tambem_cai_em_a_definir_nunca_string_vazia(): void
    {
        $contrato = $this->contratoComSnapshot([
            'cnpj' => null,
        ]);

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $this->assertSame('A DEFINIR', $dados['empresa']['cnpj']);
        $this->assertContains('cnpj', $dados['campos_pendentes']);
    }

    #[Test]
    public function servicos_snapshot_nulo_lanca_excecao_com_mensagem_pt_br_clara(): void
    {
        $contrato = ContratoAssinatura::factory()
            ->for(Company::factory(), 'company')
            ->create(['servicos_snapshot' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/snapshot/i');

        (new ContratoPdfService())->montarDados($contrato);
    }

    #[Test]
    public function nenhum_valor_de_retorno_e_nulo_ou_string_vazia(): void
    {
        $contrato = $this->contratoComSnapshot([
            'nome_contato'  => null,
            'email_cliente' => null,
            'telefone'      => null,
        ]);

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $achatado = $this->achatarArray($dados);

        foreach ($achatado as $chave => $valor) {
            if (is_array($valor)) {
                continue;
            }

            $this->assertNotNull($valor, "Campo '{$chave}' veio null — documento jurídico não pode ter campo em branco silencioso.");
            $this->assertNotSame('', $valor, "Campo '{$chave}' veio string vazia — documento jurídico não pode ter campo em branco silencioso.");
        }
    }

    /**
     * Achata um array multidimensional em pares chave-ponto → valor, para
     * varrer folhas sem se preocupar com profundidade/estrutura.
     */
    private function achatarArray(array $dados, string $prefixo = ''): array
    {
        $resultado = [];

        foreach ($dados as $chave => $valor) {
            $chaveCompleta = $prefixo === '' ? (string) $chave : "{$prefixo}.{$chave}";

            if (is_array($valor)) {
                if ($valor === [] || array_is_list($valor)) {
                    // Listas (ex.: servicos[], campos_pendentes[]) não são
                    // folha escalar — cada item já é validado nos testes
                    // específicos acima; aqui só pulamos a lista como um todo.
                    continue;
                }

                $resultado = array_merge($resultado, $this->achatarArray($valor, $chaveCompleta));
                continue;
            }

            $resultado[$chaveCompleta] = $valor;
        }

        return $resultado;
    }

    #[Test]
    public function montardados_nao_depende_de_nenhuma_view(): void
    {
        // PDF-02: a montagem de dados não pode chamar view()/Pdf::/loadView()/render() —
        // trocar o texto jurídico não pode mudar um dado sequer. Prova por asserção
        // estática sobre o CÓDIGO DE montarDados() especificamente — não o arquivo
        // inteiro: a partir do plano 126-05 o arquivo também tem gerar()/gerarESalvar(),
        // que legitimamente usam Pdf::/loadView() para renderizar o PDF, e essas duas
        // chamadas não fazem parte desta garantia. Lido com comentários removidos (mesma
        // técnica da guarda de migration da Fase 125), para que os próprios comentários
        // que citam essas palavras não confundam a checagem.
        $caminho = app_path('Services/ContratoPdfService.php');
        $this->assertFileExists($caminho, 'ContratoPdfService.php ainda não existe.');

        $codigo = $this->codigoSemComentarios($caminho);
        $metodo = $this->extrairMetodo($codigo, 'montarDados');

        $this->assertDoesNotMatchRegularExpression('/\bview\s*\(/', $metodo);
        $this->assertDoesNotMatchRegularExpression('/Pdf::/', $metodo);
        $this->assertDoesNotMatchRegularExpression('/\bloadView\s*\(/', $metodo);
        $this->assertDoesNotMatchRegularExpression('/->render\s*\(/', $metodo);
    }

    private function codigoSemComentarios(string $caminho): string
    {
        $conteudo = file_get_contents($caminho);

        $semBlocos = preg_replace('/\/\*.*?\*\//s', '', $conteudo);
        $semLinhas = preg_replace('/\/\/.*$/m', '', $semBlocos);

        return $semLinhas;
    }

    /**
     * Extrai o corpo de um método público específico — de `function {$nome}(` até
     * a próxima assinatura `public function` (ou fim do arquivo). Escopa asserções
     * estáticas a UM método, em vez do arquivo inteiro, porque a partir do plano
     * 126-05 o arquivo passou a ter outros métodos públicos com responsabilidades
     * diferentes (gerar()/gerarESalvar(), que legitimamente usam Pdf::/loadView()).
     */
    private function extrairMetodo(string $codigo, string $nome): string
    {
        $inicio = strpos($codigo, "function {$nome}(");
        $this->assertNotFalse($inicio, "Método {$nome}() não encontrado no arquivo.");

        $proximoPublico = strpos($codigo, 'public function', $inicio + 1);

        return $proximoPublico === false
            ? substr($codigo, $inicio)
            : substr($codigo, $inicio, $proximoPublico - $inicio);
    }

    // ─── Quick 260824-bte — pagamento escalonado: fases do mesmo serviço ───

    /**
     * Duas FASES do MESMO serviço no snapshot (pagamento escalonado) —
     * `valor_mensal_formatado` soma a PRIMEIRA fase, nunca as duas: hoje
     * daria R$ 11.500,00 (nunca cobrado) em vez de R$ 5.500,00 (o valor em
     * vigor). Soma entre serviços DIFERENTES continua intacta (não coberto
     * aqui — ver `contrato_com_tres_servicos_no_snapshot_...` acima, 3
     * serviços distintos, sem repetição de nome).
     */
    #[Test]
    public function valor_mensal_soma_apenas_a_primeira_fase_de_cada_servico_nunca_as_fases_do_mesmo_servico(): void
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

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $this->assertSame('R$ 5.500,00', $dados['totais']['valor_mensal_formatado']);
    }

    #[Test]
    public function plano_parcelas_com_snapshot_de_uma_fase_so_e_o_caso_simples_legado(): void
    {
        // Snapshot LEGADO (uma fase só, sem a chave 'parcelas' nenhuma) —
        // contratos gravados ANTES deste quick continuam montando sem erro,
        // sem migração de dado.
        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Gestão de Tráfego',
                    'valor_contratado' => 1500.0,
                    'data_contratacao' => '2026-01-01',
                    'data_vencimento'  => '2027-01-01',
                ],
            ])
            ->for(Company::factory(), 'company')
            ->create();

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $this->assertSame(
            \App\Services\Clicksign\ContratoVariaveisModeloService::PLANO_PARCELAS_CASO_SIMPLES,
            $dados['pagamento']['plano_parcelas']
        );
    }

    /**
     * Reproduz a Mons Bike (2 fases, ambas com período conhecido): a frase
     * exata do plano, com quantidade em dígito + extenso entre parênteses e
     * valor `R$ 0.000,00`.
     */
    #[Test]
    public function plano_parcelas_compoe_a_frase_da_mons_bike_com_duas_fases_e_periodo_conhecido(): void
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

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $this->assertSame(
            'As 3 (três) primeiras parcelas corresponderão a R$ 5.500,00 e as 9 (nove) demais a R$ 6.000,00.',
            $dados['pagamento']['plano_parcelas']
        );
    }

    /**
     * Última fase SEM período definido ("as demais voltam à faixa") —
     * termina na Cláusula 2.1.2, sem quantidade nem valor para essa fase.
     */
    #[Test]
    public function plano_parcelas_com_ultima_fase_sem_periodo_termina_na_clausula_2_1_2(): void
    {
        $contrato = ContratoAssinatura::factory()
            ->comSnapshot([
                [
                    'servico'          => 'Gestão com faixa aberta',
                    'valor_contratado' => 2250.0,
                    'data_contratacao' => '2026-01-01',
                    'data_vencimento'  => null,
                    'parcelas'         => 2,
                ],
                [
                    'servico'          => 'Gestão com faixa aberta',
                    'valor_contratado' => 3500.0,
                    'data_contratacao' => '2026-03-01',
                    'data_vencimento'  => null,
                    'parcelas'         => null,
                ],
            ])
            ->for(Company::factory(), 'company')
            ->create();

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $this->assertSame(
            'As 2 (dois) primeiras parcelas corresponderão a R$ 2.250,00 e as demais seguirão a faixa apurada na forma da Cláusula 2.1.2.',
            $dados['pagamento']['plano_parcelas']
        );
    }

    /**
     * Override: `plano_parcelas_texto` preenchido vale LITERALMENTE, mesmo
     * havendo snapshot de múltiplas fases que produziria outro texto.
     */
    #[Test]
    public function plano_parcelas_com_override_preenchido_usa_o_texto_literal_ignorando_o_composto(): void
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
            ->create(['plano_parcelas_texto' => 'Texto combinado à mão com o cliente, fora do padrão.']);

        $dados = (new ContratoPdfService())->montarDados($contrato);

        $this->assertSame(
            'Texto combinado à mão com o cliente, fora do padrão.',
            $dados['pagamento']['plano_parcelas']
        );
    }
}
