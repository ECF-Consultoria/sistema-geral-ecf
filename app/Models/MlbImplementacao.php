<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlbImplementacao extends Model
{
    protected $table = 'mlb_implementacoes';

    protected $fillable = [
        // ── Campos originais ──
        'empresa_id', 'token', 'dados', 'ultimo_acesso',

        // ── Campos do Onboarding (Wave 1 — ONB-03) ──
        'nome_contato',
        'data_solicitacao',
        // ── Entrada no projeto (planilha "Dash Gerencial Polos V2") ──
        'status_entrada',
        'chance_entrada',
        'reuniao_onboarding',
        'acesso_colaborador',
        'gmail_colaborador',
        'grupo_whatsapp',
        // Link do grupo de WhatsApp (quick 260810-dv6): grupo_whatsapp diz SE o grupo
        // existe; este campo diz ONDE ele está. Texto livre — o time cola o convite.
        'link_whatsapp',
        'planilha_produtos',
        'listagem',
        'publicacao',
        'decola',
        'campanha_criada',
        // Adesão à Central de Promoções do ML (planilha V2, coluna "Central de Promoção").
        'central_promocao',
        'contextos_logistica',
        'me1',
        // Trava de override manual do ME1 (quick 260722-nwc): quando o consultor
        // edita o me1 na mão, esta flag impede que a regra do Mercado Envios o sobrescreva.
        'me1_manual',
        'integradora',
        'places',
        'erp',

        // ── Rastreio de envio do link + responsável (ONB-ENVIO-LINK / ONB-RESPONSAVEL) ──
        'link_enviado_em',
        'link_enviado_por',
        'responsavel_id',
    ];

    protected $casts = [
        'dados'            => 'array',
        'ultimo_acesso'    => 'datetime',
        // Onboarding
        'data_solicitacao' => 'date',
        'grupo_whatsapp'   => 'boolean',
        // 'decola' NÃO tem cast: virou string (ONB_DECOLA_OPCOES) em 2026-08-03.
        'campanha_criada'  => 'boolean',
        'me1_manual'       => 'boolean',
        // Rastreio de envio do link (ONB-ENVIO-LINK)
        'link_enviado_em'  => 'datetime',
    ];

    // tipos:
    //   link_fixo       — botão com URL fixa definida aqui
    //   link_admin      — botão com URL configurada pelo admin por empresa (dados.links_admin)
    //   gmail           — campo de e-mail + tutorial
    //   link            — campo URL digitado pelo cliente
    //   texto           — textarea
    //   select          — ERP / Integrador (opções fixas com "Outro")
    //   select_opcoes   — dropdown com opções definidas em item.opcoes
    //   canais_venda    — duas perguntas no mesmo item: canal que mais vende (item.opcoes_canal)
    //                     + faixa de faturamento (item.opcoes), esta só se vende em algum
    //   produtos        — tabela inline de produtos
    //   instrucoes      — texto de instrução + checkbox
    //   instrucoes_link — texto de instrução + botão de link fixo + checkbox
    //   checkbox        — apenas checkbox

    // ══════════════════════════════════════════════════════════════════
    // Constantes do Onboarding Hub (Frente 3 — prefixo ONB_ para não
    // colidir com ERP_OPCOES / INTEGRADOR_OPCOES do checklist público)
    // Fonte de verdade: MAPEAMENTO_POLOS.xlsx (reunião 2026-06-10)
    // ══════════════════════════════════════════════════════════════════

    /** Polos de operação gerenciados pela ECF */
    public const ONB_POLO_OPCOES = [
        'Arapongas',
        'S. J. Rio Preto',
        'Serra Gaúcha', // renomeado de 'Bento Gonçalves' (planilha 2026-07)
        'São Bento do Sul',
    ];

    /**
     * Fase do onboarding da empresa no polo.
     * "Encaminhar Comercial" = lead a repassar ao Comercial (pré-aceite; não tem Cust ID ainda)
     * → "Aceite no Projeto" = aceitou, ainda entrando (pré-M0; espelha a planilha) → M0 = entrada
     * efetiva → M1..M4 → Encerrado/Churn = saída. "Protocolo Churn" = protocolo de
     * retenção aberto — a empresa ainda está no polo, mas em processo de saída.
     */
    public const ONB_FASE_OPCOES = [
        'Encaminhar Comercial', 'Aceite no Projeto', 'M0', 'M1', 'M2', 'M3', 'M4', 'Encerrado', 'Protocolo Churn', 'Churn',
    ];

    /** Status de entrada da empresa no projeto (funil — planilha V2, coluna "status de entrada") */
    public const ONB_STATUS_ENTRADA_OPCOES = [
        'Feito',
        'em contato',
        'Reserva - entrada prox mês',
        'Não tem CNPJ',
        'Não tem conta ML',
        'Não responde',
        'Abandonou o projeto',
    ];

    /** Chance de entrada da empresa no projeto (planilha V2, coluna "chance de entrada") */
    public const ONB_CHANCE_ENTRADA_OPCOES = [
        'Alta',
        'Média',
        'Baixo',
    ];

    /** Status da reunião de onboarding (planilha V2 — NÃO é booleano) */
    public const ONB_REUNIAO_ONBOARDING_OPCOES = [
        'Sim',
        'Não',
        'Agendada',
        'Não compareceu',
    ];

    /**
     * Status do acesso colaborador dado pela empresa ao colaborador ECF.
     *
     * "Falta Aceitar" NÃO é preenchido pela equipe: quem grava é o próprio cliente, ao
     * marcar o item "Acesso Colaborador" como feito no link público (salvarItem). Significa
     * "o cliente diz que convidou, falta alguém da ECF aceitar" — é fila de trabalho, não
     * conclusão. Por isso NÃO conta como entrante na meta (EntrantesM0Panel/MetasPanel
     * exigem exatamente 'Com acesso') e nunca sobrescreve um 'Com acesso' já registrado.
     */
    public const ONB_ACESSO_COLABORADOR_OPCOES = [
        'Com acesso',
        'Falta Aceitar',
        'Sem acesso',
    ];

    /** Status de envio da planilha de produtos */
    public const ONB_PLANILHA_PRODUTOS_OPCOES = [
        'Já enviado',
        'Não enviado',
    ];

    /** Status de listagem dos anúncios */
    public const ONB_LISTAGEM_OPCOES = [
        'Não',
        'Pronto para listar',
        'Já listado',
        'Falta informação',
    ];

    /** Status da publicação dos anúncios ("Banida" saiu do select em 2026-08-04) */
    public const ONB_PUBLICACAO_OPCOES = [
        'Não iniciado',
        'Concluído',
        'Estágio 2',
        'Suspensa',
    ];

    /**
     * Status do Programa Decola. Era boolean (Sim/Não) até 2026-08-03; virou texto para
     * comportar o estado intermediário "Mensagem Enviada" (convite mandado, sem resposta)
     * e aceitar valor criado inline no Painel Polos.
     */
    public const ONB_DECOLA_OPCOES = [
        'Sim',
        'Não',
        'Mensagem Enviada',
        // Gravado pelo CLIENTE ao marcar "Programa Decola" no link público: ele diz que
        // aderiu, a ECF ainda precisa conferir na conta. Não rebaixa um 'Sim' já registrado.
        'Verificar',
    ];

    /**
     * Adesão à Central de Promoções do ML (planilha V2, coluna "Central de Promoção").
     * Como todo select do Painel, é sugestão — o operador pode criar valor novo inline.
     */
    public const ONB_CENTRAL_PROMOCAO_OPCOES = [
        'Sim',
        'Não',
    ];

    /**
     * Status do ME1 (Mercado Envios nível 1). Enxugado de 10 para 5 valores em 2026-09-01.
     *
     * Encurtar a constante NÃO limpa a coluna: o Painel Polos reinjeta no dropdown todo
     * valor presente no banco (valoresPresentes), e o sync da planilha copiava a coluna
     * verbatim — o banco tinha 12 variantes, com caixa e acento divergentes do catálogo.
     * A limpeza é feita por `onboarding:normalizar-catalogos` e a re-sujeira é barrada por
     * SyncPolosPlanilha::normMe1(), que normaliza na INGESTÃO.
     *
     * 'Precisa de ME1' continua sendo gravado automaticamente pelas medidas da embalagem
     * (planilhaExcedeMercadoEnvios) enquanto me1_manual for falso.
     */
    public const ONB_ME1_OPCOES = [
        'Não é necessário',
        'Precisa de ME1',
        'Em contratação',
        'Ativo',
        'Não',
    ];

    /** Integradora logística contratada (Frente 3 — diferente de INTEGRADOR_OPCOES do checklist) */
    public const ONB_INTEGRADORA_OPCOES = [
        'Nenhuma',
        'Frenet',
        'Sisfrete',
        'Intelipost',
        'Frete Gestão',
        'Em contratação',
        'Anymarket',
    ];

    /** Status do Places (endereço de retirada ML) */
    public const ONB_PLACES_OPCOES = [
        'Ativo',
        'Solicitado',
        'Falta emissor fiscal',
        'Falta certificado A1',
        'Falta endereço fiscal',
        'Checklist realizado',
        'Realizando checklist',
        'Não',
    ];

    /** ERP utilizado pela empresa (Frente 3 — diferente de ERP_OPCOES do checklist) */
    public const ONB_ERP_OPCOES = [
        'Sem informação',
        'Bling',
        'Tiny',
        'Magazord',
        'Anymarket',
        'Olist',
        'Tray',
        'WeNext',
        'Não utiliza',
        'Em contratação',
    ];

    // ══════════════════════════════════════════════════════════════════
    // Checklist público (workspace do cliente — NÃO confundir com ONB_*)
    // ══════════════════════════════════════════════════════════════════

    public const CHECKLIST = [
        [
            'id'          => 'conta_ml',
            'titulo'      => 'Conta no Mercado Livre',
            'tipo'        => 'link_fixo',
            'link_fixo'   => 'https://www.mercadolivre.com.br/hub/registration?from_landing=true&contextual=unified_normal&entity=no_apply',
            'tem_tutorial'=> false,
            'descricao'   => 'Acesse o link abaixo para continuar com o cadastro no Mercado Livre',
        ],
        [
            'id'          => 'acesso_colaborador',
            'titulo'      => 'Acesso Colaborador',
            'tipo'        => 'gmail',
            'tem_tutorial'=> true,
            'descricao'   => 'Siga o tutorial e dê acesso colaborador ao e-mail abaixo',
        ],
        [
            'id'          => 'app_ecf',
            'titulo'      => 'App ECF',
            'tipo'        => 'link_admin',
            'tem_tutorial'=> false,
            'descricao'   => 'Acesse o App ECF pelo link abaixo',
        ],
        [
            'id'          => 'erp',
            'titulo'      => 'ERP',
            'tipo'        => 'select',
            'opcoes'      => self::ERP_OPCOES,
            'tem_acesso'  => true,
            'tem_tutorial'=> false,
            'descricao'   => 'Qual ERP a empresa utiliza? Informe também o acesso',
        ],
        [
            'id'          => 'integrador_logistico',
            'titulo'      => 'Integrador Logístico',
            'tipo'        => 'select',
            'opcoes'      => self::INTEGRADOR_OPCOES,
            'tem_tutorial'=> false,
            'descricao'   => 'Qual integrador logístico a empresa utiliza?',
        ],
        [
            'id'          => 'produtos_perfil',
            'titulo'      => 'Perfil dos Produtos',
            'tipo'        => 'select_opcoes',
            'opcoes'      => [
                'Produtos pequenos, leves e monovolumes que podem ser enviados normalmente pelo Mercado Envios',
                'Produtos grandes, volumosos, multivolumes e/ou com mais de 50 kg',
                'Ainda não sei qual será a melhor opção de envio',
            ],
            'tem_tutorial'=> false,
            'descricao'   => 'Como são os produtos que você pretende vender no Mercado Livre?',
        ],
        [
            'id'           => 'canais_faturamento',
            'titulo'       => 'Outros Canais de Venda',
            // Duas perguntas no mesmo item (pedido de 02/09/2026): QUAL canal vende mais e,
            // só para quem vende em algum, a faixa de faturamento. Era 'select_opcoes' com a
            // faixa sozinha — a faixa continua em `valor` para não quebrar quem já lê o campo.
            'tipo'         => 'canais_venda',
            'opcoes_canal' => self::CANAL_VENDA_OPCOES,
            'opcoes'       => self::CANAL_FAIXA_OPCOES,
            'tem_tutorial' => false,
            'descricao'    => 'Você já vende em outros canais? Se sim, qual deles você mais vende e qual a faixa de faturamento?',
        ],
        [
            'id'          => 'hub',
            'titulo'      => 'HUB',
            // Era 'texto' (textarea livre) até 2026-09-01. Virou 'select' — e não
            // 'select_opcoes' — de propósito: 'select' mantém a trava anti-check-vazio
            // (itemTemConteudo exige valor ≠ '---'), enquanto 'select_opcoes' marca o item
            // como feito sozinho. O campo de texto continua embaixo, como no ERP.
            'tipo'        => 'select',
            'opcoes'      => self::HUB_OPCOES,
            'tem_acesso'  => true,
            'tem_tutorial'=> false,
            'descricao'   => 'Qual HUB de integração a empresa utiliza? Informe também o acesso',
        ],
        [
            'id'          => 'publicar_em_massa',
            'titulo'      => 'Publicar em Massa?',
            'tipo'        => 'select_opcoes',
            'opcoes'      => [
                'Sim',
                'Não',
                'Todos os meus produtos já estão publicados',
                'Meu HUB / ERP ainda não está completo para publicar em massa',
            ],
            'tem_tutorial'=> false,
            'descricao'   => 'A empresa deseja publicar anúncios em massa',
        ],
        [
            'id'          => 'planilha_produtos',
            'titulo'      => 'Planilha de Produtos',
            'tipo'        => 'produtos',
            'tem_tutorial'=> false,
            'descricao'   => 'Preencha abaixo as informações dos seus produtos',
        ],
        [
            'id'          => 'drive_imagens',
            'titulo'      => 'Drive com Imagens',
            'tipo'        => 'link_admin',
            'tem_tutorial'=> false,
            'descricao'   => 'Acesse o Drive abaixo e adicione as imagens de cada produto na pasta correspondente',
        ],
        [
            'id'          => 'precificacao',
            'titulo'      => 'Precificação',
            'tipo'        => 'precificacao',
            'tem_tutorial'=> false,
            'descricao'   => 'Preencha o custo de aquisição de cada produto para calcular o preço de venda',
        ],
        [
            'id'          => 'certificado_a1',
            'titulo'      => 'Certificado A1',
            'tipo'        => 'checkbox',
            'tem_tutorial'=> false,
            'descricao'   => 'A empresa possui Certificado A1',
        ],
        [
            'id'          => 'programa_decola',
            'titulo'      => 'Programa Decola',
            'tipo'        => 'link_admin',
            'tem_tutorial'=> false,
            'descricao'   => 'Acesse o Programa Decola no link abaixo',
        ],
        [
            'id'          => 'endereco_fiscal',
            'titulo'      => 'Endereço Fiscal',
            'tipo'        => 'instrucoes',
            'instrucoes'  => 'Clique em MEU PERFIL → Informações da empresa → Dados Fiscais → insira o cartão CNPJ da empresa.',
            'tem_tutorial'=> false,
            'descricao'   => 'Configure o endereço fiscal no Mercado Livre',
        ],
        [
            'id'          => 'inscricao_estadual',
            'titulo'      => 'Inscrição Estadual',
            'tipo'        => 'instrucoes_link',
            'instrucoes'  => 'No site do Sintegra (https://www.sintegra.gov.br/) baixe uma versão da sua inscrição estadual ATUALIZADA e insira no Mercado Livre pelo link abaixo.',
            'link_fixo'   => 'https://www.mercadolivre.com.br/kyc?initiative=taxes-tax-registration&continue-kyc=true&congrats=true&landing=true',
            'tem_tutorial'=> true,
            'descricao'   => 'Insira sua inscrição estadual no Mercado Livre',
        ],
        [
            'id'          => 'verificacao_seguranca',
            'titulo'      => 'Verificação de Segurança',
            'tipo'        => 'link_fixo',
            'link_fixo'   => 'https://www.mercadolivre.com.br/pampa/security-settings?source=pampa',
            'tem_tutorial'=> false,
            'descricao'   => 'Acesse o link abaixo para fazer a verificação de segurança',
        ],
    ];

    public const ERP_OPCOES = [
        'Em Contratação', 'Tiny ERP', 'Bling', 'SAP', 'Netsuite', 'TOTVS', 'Omie', 'Outro',
    ];

    /**
     * Integrador logístico do CHECKLIST público (≠ ONB_INTEGRADORA_OPCOES, que é a coluna
     * "Integradora" do Painel Polos). Revisto em 2026-09-01: saíram Melhor Envio, DirectLog,
     * Jadlog, Correios e "Trabalhar apenas com Mercado Envios" (nenhuma ficha usava);
     * 'Em Contratação' (9 fichas) e 'Outro' (1) ficaram porque tirá-los deixaria o valor
     * órfão — o select renderiza vazio e itemTemConteudo trava o check.
     */
    public const INTEGRADOR_OPCOES = [
        'Em Contratação',
        'Frenet',
        'Sisfrete',
        'Intelipost',
        'Anymarket',
        // Empresa que não usa integrador — despacha tudo pelo Mercado Envios (quick 260804)
        'Enviarei Apenas pelo Mercado Envios',
        'Outro',
    ];

    /**
     * Sentinela do item "Outros Canais de Venda": quem escolhe isto não vende fora do
     * Mercado Livre, então a pergunta da faixa de faturamento deixa de existir para ele.
     * Era uma opção da FAIXA até 02/09/2026 — mudou de pergunta, não sumiu.
     */
    public const CANAL_NENHUM = 'Não vendo em outros canais';

    /**
     * Canal em que o cliente MAIS vende, fora o Mercado Livre (pedido de 02/09/2026).
     * A lista é a do time comercial; 'Outro' abre campo de texto (mesmo padrão do ERP)
     * para não obrigar quem vende em Shein/Netshoes a escolher um canal errado.
     */
    public const CANAL_VENDA_OPCOES = [
        self::CANAL_NENHUM,
        'Shopee',
        'Amazon',
        'Madeira Madeira',
        'Magalu',
        'Web Continental',
        'Outro',
    ];

    /** Faixa de faturamento nos outros canais — só perguntada a quem vende em algum. */
    public const CANAL_FAIXA_OPCOES = [
        'Até 50k',
        'De 50 a 100k',
        'De 100 a 500k',
        'Acima de 500k',
    ];

    /** HUB de integração usado pelo cliente (item "HUB" do checklist público). */
    public const HUB_OPCOES = [
        'Anymarket',
        'Tray',
        'Magis5',
        'Ideris',
        'Shopping de Preços',
        'Não utilizo',
    ];

    public static function dadosPadrao(): array
    {
        return [
            'tutorial_intro' => '',
            'prazo_data'     => '',
            'tutoriais' => [
                'acesso_colaborador' => '',
                'inscricao_estadual' => '',
            ],
            'links_admin' => [
                'gmail_colaborador' => '',
                'drive_imagens'     => '',
                'app_ecf'           => '',
                'programa_decola'   => '',
            ],
            // Check-in do Publicador por SKU: mapa {sku => true} marcado na visão
            // /publicador enquanto os anúncios vão sendo feitos. Fica FORA de
            // itens.planilha_produtos.produtos de propósito — o cliente salva aquele
            // array inteiro e sobrescreveria as marcações do publicador.
            'publicador_checkin' => [],
            'itens' => [
                'conta_ml'             => ['feito' => false],
                'acesso_colaborador'   => ['gmail' => '', 'feito' => false],
                'app_ecf'              => ['feito' => false],
                'erp'                  => ['valor' => '---', 'outro' => '', 'acesso' => '', 'feito' => false],
                'integrador_logistico' => ['valor' => '---', 'outro' => '', 'feito' => false],
                'produtos_perfil'      => ['valor' => '', 'feito' => false],
                // 'valor' = faixa de faturamento (chave original, preservada); 'canal' e
                // 'outro' são a pergunta acrescentada em 02/09/2026.
                'canais_faturamento'   => ['canal' => '', 'outro' => '', 'valor' => '', 'feito' => false],
                // 'acesso' preservado: o HUB era textarea livre até 2026-09-01 e 3 fichas
                // têm texto salvo ali.
                'hub'                  => ['valor' => '---', 'outro' => '', 'acesso' => '', 'feito' => false],
                'publicar_em_massa'    => ['valor' => '', 'feito' => false],
                'planilha_produtos'    => ['produtos' => [], 'feito' => false],
                'drive_imagens'        => ['feito' => false],
                'precificacao' => [
                    'classico'  => ['comissao' => 0.115, 'imposto' => 0.19],
                    'premium'   => ['comissao' => 0.165, 'imposto' => 0.19],
                    // Alvos globais (default 0 — o cliente/consultor escolhe). Preço =
                    // (custo+frete)/(1−comissão−imposto−margem_contribuicao−lucro_liquido).
                    'margem_contribuicao' => 0,
                    'lucro_liquido'       => 0,
                    'acrescimo' => 0.20, // acréscimo global do Anunciado (default 20%)
                    'produtos'  => [],
                    'feito'     => false,
                ],
                'certificado_a1'       => ['feito' => false],
                'programa_decola'      => ['feito' => false],
                'endereco_fiscal'      => ['feito' => false],
                'inscricao_estadual'   => ['feito' => false],
                'verificacao_seguranca'=> ['feito' => false],
            ],
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(MlbEmpresa::class, 'empresa_id');
    }

    /** Usuário responsável pelo onboarding (ONB-RESPONSAVEL) */
    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    /** Usuário que marcou o link como enviado (ONB-ENVIO-LINK) */
    public function linkEnviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'link_enviado_por');
    }

    /**
     * Retorna o status do envio do link do cliente (ONB-ENVIO-LINK).
     *
     * Precedência: o status reflete a fase atual, não o histórico.
     *   concluido    — todos os itens do checklist marcados (progresso 100%)
     *   enviado      — equipe marcou manualmente que enviou o link (link_enviado_em preenchido)
     *   falta_enviar — nenhuma das condições acima
     *
     * NÃO usamos ultimo_acesso para inferir "cliente acessou": o link público é aberto
     * também pela própria equipe (testes/conferência), então atribuir esse acesso ao
     * cliente quebra a lógica. O status reflete apenas o que a equipe controla (envio
     * manual) + a conclusão do checklist.
     */
    public function statusEnvio(): string
    {
        if ($this->progresso()['pct'] === 100) return 'concluido';
        if ($this->link_enviado_em !== null)    return 'enviado';
        return 'falta_enviar';
    }

    /**
     * Estado da autorização do Mercado Livre pelo link do Onboarding —
     * o `{link_oauth}` da mensagem de boas-vindas.
     *
     * O carimbo é gravado por MercadoLivreOAuthController::carimbarOauthPolos()
     * em `dados['ml_oauth']`, chave TOP-LEVEL fora de `itens` (o cliente
     * reescreve `itens` inteiro a cada salvamento do formulário).
     *
     * "Conectado" aqui significa ESTE cliente autorizou o app da ECF por ESTA
     * empresa — não confundir com o Grant, que é um link por polo e não diz
     * quem autorizou. E não se deduz de `cust_id` preenchido: o Cust ID entra
     * à mão em muitas empresas, e o link só passou a existir em 27/08/2026 —
     * quem autorizou antes disso simplesmente não tem carimbo.
     *
     * SÃO TRÊS ESTADOS, não dois. O carimbo `divergente` (gravado por
     * {@see \App\Http\Controllers\MercadoLivreOAuthController::carimbarOauthPolos()})
     * marca clique cuja conta autorizada NÃO era a cadastrada — o `cust_id` da
     * empresa não foi tocado e a conta pende conferência. Ler isso como
     * "conectado" é pior que não mostrar nada: em 27/08 um clique interno da ECF
     * carimbou a própria conta (MGSTOREL) sobre a Masitto Home Decor, porque o
     * Mercado Livre devolve o code direto quando o navegador já tem sessão ativa
     * com o app. A tela diria "cliente autorizou" para uma empresa que nunca
     * autorizou.
     *
     * @return array{
     *   conectado: bool,              autorização VÁLIDA (divergente nunca conta)
     *   divergente: bool,             clicou, mas com outra conta — nada foi gravado
     *   autorizado_em: ?string,       d/m/Y H:i
     *   autorizado_em_iso: ?string,
     *   cust_id: ?string,             Seller ID devolvido pelo próprio /oauth/token
     *   nickname: ?string,            apelido da conta ML (pode faltar — é enfeite)
     *   cust_id_corrigido: bool,      a autorização REESCREVEU o Cust ID cadastrado
     *   cust_id_anterior: ?string
     * }
     */
    public function oauthMl(): array
    {
        $carimbo = $this->dados['ml_oauth'] ?? null;

        if (! is_array($carimbo) || empty($carimbo['autorizado_em'])) {
            return [
                'conectado'         => false,
                'divergente'        => false,
                'autorizado_em'     => null,
                'autorizado_em_iso' => null,
                'cust_id'           => null,
                'nickname'          => null,
                'cust_id_corrigido' => false,
                'cust_id_anterior'  => null,
            ];
        }

        $anterior   = $carimbo['cust_id_anterior'] ?? null;
        $atual      = $carimbo['cust_id'] ?? null;
        $divergente = (bool) ($carimbo['divergente'] ?? false);

        // Empresa que chegou SEM Cust ID não teve correção, teve preenchimento —
        // avisar "era —, virou X" manda a equipe conferir uma troca que nunca
        // houve. Visto em produção: das 4 autorizações, 1 era campo vazio.
        $tinhaAnterior = $anterior !== null && trim((string) $anterior) !== '';

        return [
            // Divergente NÃO é conectado: o cliente desta empresa não autorizou
            // nada, quem autorizou foi outra conta.
            'conectado'         => ! $divergente,
            'divergente'        => $divergente,
            // O carimbo é gravado com now()->toISOString(), que sai em UTC. Sem o
            // timezone() a tela mostraria 3 horas a mais e ninguém desconfiaria —
            // "autorizou 17:55" para uma autorização das 14:55.
            'autorizado_em'     => \Carbon\Carbon::parse($carimbo['autorizado_em'])
                ->timezone(config('app.timezone'))
                ->format('d/m/Y H:i'),
            'autorizado_em_iso' => $carimbo['autorizado_em'],
            'cust_id'           => $atual,
            'nickname'          => $carimbo['nickname'] ?? null,
            // Divergente não reescreveu nada — o controller recusa de propósito.
            'cust_id_corrigido' => ! $divergente && $tinhaAnterior && trim((string) $anterior) !== (string) $atual,
            'cust_id_anterior'  => $anterior,
        ];
    }

    /**
     * Verdadeiro se o cliente já preencheu o mínimo necessário para poder marcar
     * o item como "feito". Só itens onde o cliente DIGITA/SELECIONA/MONTA algo
     * exigem conteúdo; itens de ação pura (acessar link, dar acesso, declarar)
     * não têm o que preencher e permanecem sempre liberados.
     *
     * Regra espelhada em resources/js/Pages/Mlb/ImplementacaoPublica.jsx
     * (função itemTemConteudo) — mantê-las em sincronia manualmente.
     *
     * @param string $tipo Tipo do item do CHECKLIST (erp/produtos/etc).
     * @param array  $dado Dados salvos do item (dados.itens[<id>]).
     */
    public static function itemTemConteudo(string $tipo, array $dado): bool
    {
        switch ($tipo) {
            case 'select': // ERP / Integrador / HUB — escolher opção real (≠ '---') libera
                $valor = trim((string) ($dado['valor'] ?? ''));
                return $valor !== '' && $valor !== '---';

            case 'texto': // legado — o HUB virou 'select' em 2026-09-01; nenhum item usa hoje
                return trim((string) ($dado['acesso'] ?? '')) !== '';

            case 'link': // URL digitada pelo cliente
                return trim((string) ($dado['link'] ?? '')) !== '';

            case 'canais_venda': // Outros Canais de Venda — canal + (se vende) faixa
                $canal = trim((string) ($dado['canal'] ?? ''));
                if ($canal === '') {
                    return false;
                }
                // Quem não vende em outro canal já respondeu tudo o que havia para responder.
                if ($canal === self::CANAL_NENHUM) {
                    return true;
                }
                if ($canal === 'Outro' && trim((string) ($dado['outro'] ?? '')) === '') {
                    return false;
                }
                return trim((string) ($dado['valor'] ?? '')) !== '';

            case 'produtos': // Planilha de Produtos — ao menos 1 produto com SKU ou nome
                foreach (($dado['produtos'] ?? []) as $p) {
                    if (trim((string) ($p['sku'] ?? '')) !== '' || trim((string) ($p['produto'] ?? '')) !== '') {
                        return true;
                    }
                }
                return false;

            case 'precificacao': // ao menos 1 produto com custo informado
                foreach (($dado['produtos'] ?? []) as $p) {
                    if (trim((string) ($p['custo'] ?? '')) !== '') {
                        return true;
                    }
                }
                return false;

            default:
                // link_fixo, link_admin, gmail, instrucoes, instrucoes_link,
                // checkbox, select_opcoes — ação pura, nada a preencher.
                return true;
        }
    }

    /** Tipo do item do CHECKLIST pelo id (null se id inexistente). */
    public static function tipoDoItem(string $id): ?string
    {
        foreach (self::CHECKLIST as $item) {
            if ($item['id'] === $id) return $item['tipo'];
        }
        return null;
    }

    // ══════════════════════════════════════════════════════════════════
    // Regra do Mercado Envios para as MEDIDAS DA EMBALAGEM (ME-ONBOARDING)
    // Fonte: limites de logística do Mercado Envios. Aplicada à Planilha de
    // Produtos do checklist público — se algum produto estourar, a empresa
    // "Precisa de ME1" (marca automática do campo me1 no salvarItem).
    // ══════════════════════════════════════════════════════════════════

    /** Maior lado da embalagem não pode passar de 200 cm. */
    public const ME_LADO_MAX_CM = 200;
    /** Soma dos três lados da embalagem não pode passar de 300 cm. */
    public const ME_SOMA_MAX_CM = 300;
    /** Peso da embalagem não pode ultrapassar 50 kg. */
    public const ME_PESO_MAX_KG = 50;

    /**
     * Verdadeiro se ALGUM produto da Planilha de Produtos ultrapassa os limites
     * do Mercado Envios nas MEDIDAS DA EMBALAGEM (altura_emb/largura_emb/prof_emb
     * em cm e peso_emb_kg em kg):
     *   - maior lado > 200 cm, OU
     *   - soma dos três lados > 300 cm, OU
     *   - peso > 50 kg
     *
     * Usada em MlbImplementacaoController::salvarItem para marcar o campo me1
     * como "Precisa de ME1" quando o cliente preenche a planilha. Basta um
     * produto excedente para retornar true.
     *
     * @param array $produtos Lista de produtos (dados.itens.planilha_produtos.produtos).
     */
    public static function planilhaExcedeMercadoEnvios(array $produtos): bool
    {
        foreach ($produtos as $p) {
            if (!is_array($p)) continue;

            $alt  = self::medidaParaNumero($p['altura_emb']  ?? null);
            $larg = self::medidaParaNumero($p['largura_emb'] ?? null);
            $prof = self::medidaParaNumero($p['prof_emb']    ?? null);
            $peso = self::medidaParaNumero($p['peso_emb_kg'] ?? null);

            $maiorLado = max($alt, $larg, $prof);
            $somaLados = $alt + $larg + $prof;

            if ($maiorLado > self::ME_LADO_MAX_CM
                || $somaLados > self::ME_SOMA_MAX_CM
                || $peso > self::ME_PESO_MAX_KG) {
                return true;
            }
        }

        return false;
    }

    /**
     * Converte uma medida digitada pelo cliente (string livre) em float.
     * Aceita vírgula ou ponto como separador decimal; vazio/nulo/inválido → 0.0.
     */
    private static function medidaParaNumero($valor): float
    {
        $s = trim((string) $valor);
        if ($s === '') return 0.0;
        return (float) str_replace(',', '.', $s);
    }

    /**
     * De-para das variantes de ME1 encontradas no banco para os 5 valores de ONB_ME1_OPCOES.
     *
     * A chave é o valor JÁ NORMALIZADO (maiúsculas, sem acento) — a planilha mistura caixa
     * e acento na mesma coluna ('NÃO' 91 × 'Não é Necessario' 41 × 'EM CONTRATAÇÃO' 16) e
     * comparar fiel deixaria variante escapando.
     *
     * Os 6 estados intermediários que existiam no catálogo antigo colapsam assim (decisão do
     * usuário em 01/09/2026): 'Sem itens ainda' → vazio, porque a empresa ainda não mandou
     * produto e não há status de ME1 a afirmar; os demais são negociação em curso e viram
     * 'Em contratação'. `null` no valor = LIMPAR a coluna.
     */
    public const ME1_DE_PARA = [
        'NAO'                      => 'Não',
        'NAO E NECESSARIO'         => 'Não é necessário',
        'ATIVO'                    => 'Ativo',
        'EM CONTRATACAO'           => 'Em contratação',
        'PRECISA DE ME1'           => 'Precisa de ME1',
        'SEM ITENS AINDA'          => null,
        'AGUARDANDO CONTATO'       => 'Em contratação',
        'CONVERSANDO COM CLIENTE'  => 'Em contratação',
        'PENDENTE COM INTEGRADORA' => 'Em contratação',
        'VERIFICANDO'              => 'Em contratação',
        'PREENCHENDO TABELA'       => 'Em contratação',
    ];

    /** Grafias divergentes da coluna "Integradora" (o Painel usava 'Intelispost' e 'Any'). */
    public const INTEGRADORA_DE_PARA = [
        'INTELISPOST' => 'Intelipost',
        'INTELIPOST'  => 'Intelipost',
        'ANY'         => 'Anymarket',
        'ANYMARKET'   => 'Anymarket',
    ];

    /** Maiúsculas sem acento — chave de comparação dos de-para acima. */
    public static function chaveCatalogo(string $v): string
    {
        $sem = strtr(trim($v), [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e',
            'í'=>'i','î'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','û'=>'u','ü'=>'u','ç'=>'c',
            'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A','É'=>'E','Ê'=>'E','È'=>'E',
            'Í'=>'I','Î'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ú'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C',
        ]);

        return mb_strtoupper($sem);
    }

    /**
     * Normaliza um valor de ME1 para ONB_ME1_OPCOES.
     * Retorna null quando o valor deve LIMPAR a coluna ('Sem itens ainda' e vazio).
     * Valor desconhecido passa VERBATIM — não inventamos status de operação.
     */
    public static function normalizarMe1(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }

        $chave = self::chaveCatalogo($v);

        return array_key_exists($chave, self::ME1_DE_PARA) ? self::ME1_DE_PARA[$chave] : $v;
    }

    /** Normaliza a grafia da coluna "Integradora". Desconhecido passa verbatim. */
    public static function normalizarIntegradora(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }

        return self::INTEGRADORA_DE_PARA[self::chaveCatalogo($v)] ?? $v;
    }

    /**
     * Alinha um valor à GRAFIA do catálogo (só caixa e acento). Desconhecido passa verbatim.
     *
     * Existe porque o time digita esses valores à mão, no Painel e na planilha, muito antes
     * de virarem catálogo: em 01/09/2026 a produção já tinha 8 fichas com 'Falta aceitar'
     * (a minúsculo) enquanto ONB_ACESSO_COLABORADOR_OPCOES define 'Falta Aceitar'. Sem
     * alinhar, as duas grafias convivem como opções distintas no dropdown do Painel
     * (valoresPresentes reinjeta todo valor do banco) e a de caixa errada não é reconhecida
     * pelas SENTINELAS_DO_CLIENTE do sync nem pelas cores de VAL_PROG/corStatus.
     *
     * NÃO é usado na ingestão do sync de propósito: a planilha não deve poder fabricar uma
     * sentinela do cliente só escrevendo o texto certo na coluna.
     *
     * @param array<int, string> $catalogo
     */
    public static function normalizarParaCatalogo(?string $v, array $catalogo): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }

        $chave = self::chaveCatalogo($v);
        foreach ($catalogo as $opcao) {
            if (self::chaveCatalogo($opcao) === $chave) {
                return $opcao;
            }
        }

        return $v;
    }

    /**
     * Garante em `dados['itens']` todas as chaves do CHECKLIST atual, sem perder o que já
     * foi salvo (o valor gravado SEMPRE vence o padrão).
     *
     * Por que isto existe: o JSON de `dados` é escrito uma vez e nunca mais ganha chave.
     * Quem salvou a ficha quando o checklist tinha 15 itens continua com 15 no banco para
     * sempre. Sem o merge, uma pergunta acrescentada ao CHECKLIST é pior do que invisível
     * nessas fichas — `salvarItem()` faz `abort_unless(isset($dados['itens'][$id]), 422)`,
     * então o cliente escolhe a opção, o autosave leva 422 e nada é gravado.
     *
     * Fichas com `dados` NULL (259 das 269 em 01/09/2026) nunca tiveram esse problema:
     * renderizam de dadosPadrao() fresco. O merge é o que dá o mesmo tratamento às 10 que
     * já têm JSON salvo — sem migration e sem backfill, se curando na primeira leitura.
     *
     * @param array $dados Conteúdo de `dados` (ou dadosPadrao() quando NULL).
     */
    public static function mesclarItensPadrao(array $dados): array
    {
        $padrao = self::dadosPadrao()['itens'];
        $itens  = is_array($dados['itens'] ?? null) ? $dados['itens'] : [];

        foreach ($padrao as $id => $default) {
            $salvo = is_array($itens[$id] ?? null) ? $itens[$id] : [];
            // array_merge com o salvo em SEGUNDO: o que o cliente gravou prevalece, e
            // sub-chaves novas (ex.: hub.valor) entram sem apagar as antigas (hub.acesso).
            $itens[$id] = array_merge($default, $salvo);
        }

        $dados['itens'] = $itens;

        return $dados;
    }

    /**
     * Resposta do cliente a um item `select`/`select_opcoes` do CHECKLIST.
     *
     * Existe para o Painel Polos exibir e FILTRAR respostas que moram no JSON
     * (`dados.itens.<id>.valor`) lado a lado com as colunas de `mlb_implementacoes`, sem
     * que a tela precise saber dessa diferença de origem.
     *
     * Lê direto do JSON, sem passar por mesclarItensPadrao(): chave ausente e chave no
     * padrão dão o MESMO resultado (null), e o merge reconstrói dadosPadrao() a cada
     * chamada — caro no Painel, que chama isto 2x por empresa em ~500 linhas.
     *
     * @return string|null null para não respondido e para a sentinela '---' dos selects.
     */
    public function respostaChecklist(string $id): ?string
    {
        $valor = trim((string) ($this->dados['itens'][$id]['valor'] ?? ''));

        return ($valor === '' || $valor === '---') ? null : $valor;
    }

    /**
     * Resposta do item "Outros Canais de Venda" para a coluna homônima do Painel Polos.
     *
     * O item passou a ter DUAS respostas em 02/09/2026 (canal + faixa) e
     * `respostaChecklist()` só enxerga `valor` — a coluna "Outros canais" mostraria a
     * faixa e nunca o canal, que é justamente o que o time pediu para ver.
     *
     * Formato: "Shopee · De 50 a 100k" · "Outro: Shein · Até 50k" ·
     * "Não vendo em outros canais" (sozinho — quem não vende não tem faixa) · null.
     * Ficha antiga, que respondeu quando só havia a faixa, devolve só a faixa.
     */
    public function respostaCanaisVenda(): ?string
    {
        $item  = $this->dados['itens']['canais_faturamento'] ?? [];
        $canal = trim((string) ($item['canal'] ?? ''));
        $faixa = trim((string) ($item['valor'] ?? ''));

        if ($canal === 'Outro') {
            $outro = trim((string) ($item['outro'] ?? ''));
            $canal = $outro === '' ? 'Outro' : 'Outro: ' . $outro;
        }

        $partes = array_filter([$canal, $canal === self::CANAL_NENHUM ? '' : $faixa]);

        return $partes === [] ? null : implode(' · ', $partes);
    }

    public function progresso(): array
    {
        // Conta sobre o CHECKLIST atual (via merge), não sobre o JSON congelado da ficha:
        // sem isso, ficha antiga fica presa no denominador do checklist de quando foi salva
        // e uma pergunta nova nunca a tira de 100%.
        $itens = self::mesclarItensPadrao($this->dados ?? [])['itens'];
        $total = count($itens);
        $feitos = count(array_filter($itens, fn($v) => $v['feito'] ?? false));
        return [
            'feitos' => $feitos,
            'total'  => $total,
            // round() retorna float em PHP; cast (int) garante que === 100 no controller funcione corretamente (ONB-11)
            'pct'    => $total > 0 ? (int) round($feitos / $total * 100) : 0,
        ];
    }

    /**
     * Calcula informações de prazo para o Onboarding (ONB-09).
     *
     * "Concluído" = progresso()['pct'] === 100 (todos os itens do checklist marcados como feito).
     * "Fora do prazo" = passados 5 dias desde data_solicitacao (ou created_at) E ainda não concluiu.
     * Empresa concluída NUNCA é marcada como fora do prazo, mesmo que o prazo já tenha vencido.
     *
     * startOfDay() é aplicado em ambos os operandos do diff para neutralizar timezone BRT
     * e evitar resultados diferentes dependendo da hora em que o cálculo é feito (Pitfall 2).
     *
     * @return array{
     *     data_inicio: string,      Y-m-d — data_solicitacao ou created_at
     *     dias_decorridos: int,
     *     dias_restantes: int,      negativo = prazo já vencido
     *     fora_do_prazo: bool,      true = vencido E não concluído
     *     concluido: bool
     * }
     */
    public function infoPrazo(): array
    {
        $concluido = $this->progresso()['pct'] === 100;

        // Fallback: usa created_at se data_solicitacao for nula
        $inicio = $this->data_solicitacao ?? $this->created_at;

        // startOfDay() em ambos os lados para neutralizar componente de hora (Pitfall 2 — BRT)
        $diasDecorridos = (int) \Carbon\Carbon::parse($inicio)->startOfDay()->diffInDays(
            now()->startOfDay()
        );

        return [
            'data_inicio'     => \Carbon\Carbon::parse($inicio)->format('Y-m-d'),
            'dias_decorridos' => $diasDecorridos,
            'dias_restantes'  => 5 - $diasDecorridos,
            'fora_do_prazo'   => !$concluido && $diasDecorridos > 5,
            'concluido'       => $concluido,
        ];
    }
}
