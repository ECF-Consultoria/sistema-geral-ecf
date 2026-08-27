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
    ];

    /**
     * Adesão à Central de Promoções do ML (planilha V2, coluna "Central de Promoção").
     * Como todo select do Painel, é sugestão — o operador pode criar valor novo inline.
     */
    public const ONB_CENTRAL_PROMOCAO_OPCOES = [
        'Sim',
        'Não',
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
        'Em Contratação', 'Melhor Envio', 'Frenet', 'DirectLog', 'Jadlog', 'Correios',
        // Empresa que não usa integrador — despacha tudo pelo Mercado Envios (quick 260804)
        'Trabalhar apenas com Mercado Envios',
        'Outro',
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
            case 'select': // ERP / Integrador — escolher qualquer opção real (≠ '---') libera
                $valor = trim((string) ($dado['valor'] ?? ''));
                return $valor !== '' && $valor !== '---';

            case 'texto': // HUB
                return trim((string) ($dado['acesso'] ?? '')) !== '';

            case 'link': // URL digitada pelo cliente
                return trim((string) ($dado['link'] ?? '')) !== '';

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
