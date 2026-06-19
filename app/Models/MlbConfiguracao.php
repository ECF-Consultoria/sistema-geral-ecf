<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MlbConfiguracao extends Model
{
    protected $table = 'mlb_configuracoes';

    protected $fillable = ['link_acesso', 'implementacao_defaults'];

    protected $casts = ['implementacao_defaults' => 'array'];

    public static function get(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }

    public static function implementacaoPadroes(): array
    {
        $defaults = self::get()->implementacao_defaults ?? [];
        $base = [
            'tutorial_intro' => '',
            'tutoriais' => [
                'acesso_colaborador' => '',
                'inscricao_estadual' => '',
            ],
            'links_admin_extra' => [
                'app_ecf'         => '',
                'programa_decola' => '',
                'tabela_frete'    => '',
            ],
            // Mensagem de boas-vindas padrão enviada ao cliente no Onboarding.
            // Placeholders substituídos por empresa ao copiar: {link_formulario} (URL do
            // workspace), {link_grant} e {projeto_grant} (resolvidos pelo polo da empresa
            // via grants_por_polo) e {empresa}.
            'mensagem_boas_vindas' => self::MENSAGEM_BOAS_VINDAS_PADRAO,
            // Grant do Mercado Livre por polo (cada região tem o seu). Keyed pelos valores
            // de MlbImplementacao::ONB_POLO_OPCOES para casar com mlb_empresas.polo sem
            // divergência de nomes. "Bento Gonçalves" corresponde ao Grant da Serra Gaúcha.
            'grants_por_polo' => self::GRANTS_POR_POLO_PADRAO,
        ];
        return array_replace_recursive($base, $defaults);
    }

    /** Texto padrão da mensagem de boas-vindas do Onboarding (editável em Padrões Globais). */
    public const MENSAGEM_BOAS_VINDAS_PADRAO = <<<'TXT'
Olá, seja muito bem-vindo(a) ao Projeto Polos! 🚀
Estamos muito felizes em ter sua empresa conosco nessa jornada em parceria com o Mercado Livre.
Para iniciarmos corretamente o processo de onboarding e estruturação da sua operação dentro do projeto, precisamos que você preencha todas as informações solicitadas no formulário abaixo:
👉 Link do formulário: {link_formulario}
Além disso, também é necessário realizar o preenchimento do Grant do Projeto Polos. Esse preenchimento é essencial para conectar sua conta ao projeto e validar oficialmente sua entrada junto ao Mercado Livre.
📍 {projeto_grant}
🔗 Link Grant: {link_grant}
Também preparamos um material explicativo com as principais informações sobre o Projeto Polos, incluindo como funciona a jornada, quais são as etapas do projeto, os papéis de cada parte envolvida e os primeiros passos necessários para o início da operação.
📘 Guia do Projeto Polos: https://drive.google.com/drive/folders/1qwAp4p5Qvk-VadnKGq37aXYjULuuDhjb?usp=sharing
É muito importante que todos os dados sejam preenchidos com atenção e de forma completa, pois essas informações serão utilizadas pela nossa equipe para dar andamento às próximas etapas do projeto, como análise inicial, organização da conta, publicações, acessos, logística e direcionamentos estratégicos.
O prazo para preenchimento é de até 5 dias úteis a partir do recebimento desta mensagem.
Contamos com o seu apoio para cumprir esse prazo e garantir que o início do projeto aconteça da melhor forma possível, evitando atrasos nas próximas etapas do onboarding.
Qualquer dúvida durante o preenchimento, no Grant ou sobre o material enviado, nossa equipe está à disposição para ajudar. 🤝
Vamos construir essa operação juntos!
TXT;

    /** Grants padrão por polo (url + nome do projeto). Editável em Padrões Globais. */
    public const GRANTS_POR_POLO_PADRAO = [
        'Arapongas' => [
            'url'  => 'https://partners.mercadolivre.com.br/auth/a5bPc000008osyHIAQ',
            'nome' => 'Projeto Polos - Arapongas',
        ],
        'S. J. Rio Preto' => [
            'url'  => 'https://partners.mercadolivre.com.br/auth/a5bPc000008osztIAA',
            'nome' => 'Projeto Polos - São José do Rio Preto',
        ],
        'Bento Gonçalves' => [
            'url'  => 'https://partners.mercadolivre.com.br/auth/a5bPc000008oswfIAA',
            'nome' => 'Projeto Polos - Serra Gaúcha',
        ],
        'São Bento do Sul' => [
            'url'  => 'https://partners.mercadolivre.com.br/auth/a5bPc000008ot1VIAQ',
            'nome' => 'Projeto Polos - São Bento do Sul',
        ],
    ];
}
