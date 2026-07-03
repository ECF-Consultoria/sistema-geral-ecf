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
        'acesso_colaborador',
        'gmail_colaborador',
        'grupo_whatsapp',
        'planilha_produtos',
        'listagem',
        'publicacao',
        'decola',
        'campanha_criada',
        'contextos_logistica',
        'me1',
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
        'decola'           => 'boolean',
        'campanha_criada'  => 'boolean',
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

    /** Fase do onboarding da empresa no polo (M0 = entrada, Churn = saída) */
    public const ONB_FASE_OPCOES = [
        'M0', 'M1', 'M2', 'M3', 'M4', 'Encerrado', 'Churn',
    ];

    /** Status do acesso colaborador dado pela empresa ao colaborador ECF */
    public const ONB_ACESSO_COLABORADOR_OPCOES = [
        'Com acesso',
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

    /** Status da publicação dos anúncios */
    public const ONB_PUBLICACAO_OPCOES = [
        'Concluído',
        'Estágio 2',
        'Suspensa',
        'Banida',
    ];

    /** Status do ME1 (Mercado Envios Full nível 1) */
    public const ONB_ME1_OPCOES = [
        'Sem itens ainda',
        'Não é necessário',
        'Ativo',
        'Em contratação',
        'Precisa de ME1',
        'Aguardando contato',
        'Conversando com cliente',
        'Pendente com integradora',
        'Preenchendo tabela',
        'Verificando',
    ];

    /** Integradora logística contratada (Frente 3 — diferente de INTEGRADOR_OPCOES do checklist) */
    public const ONB_INTEGRADORA_OPCOES = [
        'Nenhuma',
        'Frenet',
        'Sisfrete',
        'Intelispost',
        'Frete Gestão',
        'Em contratação',
        'Any',
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
            'tem_tutorial'=> false,
            'descricao'   => 'Qual ERP a empresa utiliza? Informe também o acesso',
        ],
        [
            'id'          => 'integrador_logistico',
            'titulo'      => 'Integrador Logístico',
            'tipo'        => 'select',
            'tem_tutorial'=> false,
            'descricao'   => 'Qual integrador logístico a empresa utiliza?',
        ],
        [
            'id'          => 'hub',
            'titulo'      => 'HUB',
            'tipo'        => 'texto',
            'tem_tutorial'=> false,
            'descricao'   => 'Informe o acesso ao HUB (se aplicável)',
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

    public const INTEGRADOR_OPCOES = [
        'Em Contratação', 'Melhor Envio', 'Frenet', 'DirectLog', 'Jadlog', 'Correios', 'Outro',
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
            'itens' => [
                'conta_ml'             => ['feito' => false],
                'acesso_colaborador'   => ['gmail' => '', 'feito' => false],
                'app_ecf'              => ['feito' => false],
                'erp'                  => ['valor' => 'Em Contratação', 'outro' => '', 'acesso' => '', 'feito' => false],
                'integrador_logistico' => ['valor' => 'Em Contratação', 'outro' => '', 'feito' => false],
                'hub'                  => ['acesso' => '', 'feito' => false],
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

    public function progresso(): array
    {
        $itens = $this->dados['itens'] ?? [];
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
