<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlbImplementacao extends Model
{
    protected $table = 'mlb_implementacoes';

    protected $fillable = ['empresa_id', 'token', 'dados', 'ultimo_acesso'];

    protected $casts = [
        'dados'         => 'array',
        'ultimo_acesso' => 'datetime',
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
            'tem_tutorial'=> true,
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
            'tem_tutorial'=> true,
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
                'app_ecf'            => '',
                'planilha_produtos'  => '',
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
                    'classico' => ['comissao' => 0.115, 'imposto' => 0.19, 'margem' => 0.32],
                    'premium'  => ['comissao' => 0.165, 'imposto' => 0.19, 'margem' => 0.35],
                    'produtos' => [],
                    'feito'    => false,
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

    public function progresso(): array
    {
        $itens = $this->dados['itens'] ?? [];
        $total = count($itens);
        $feitos = count(array_filter($itens, fn($v) => $v['feito'] ?? false));
        return [
            'feitos' => $feitos,
            'total'  => $total,
            'pct'    => $total > 0 ? round($feitos / $total * 100) : 0,
        ];
    }
}
