<?php

use App\Models\Configuracao;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 32 Plan 01 — Popula a chave `nps_textos` na tabela `configuracoes`
 * com os 11 defaults do CONTEXT D-03 (LOCKED).
 *
 * Schema dos textos (5 do email + 6 da página de resposta):
 *
 *   email_assunto, email_saudacao, email_corpo, email_cta, email_assinatura,
 *   perg_estrategista, perg_analista, perg_empresa, perg_comentario_label,
 *   perg_comentario_placeholder, perg_nome_label
 *
 * Placeholders suportados pelo helper NpsTextRenderer:
 *   {nome_estrategista}, {nome_analista}, {nome_empresa}, {mes_referencia},
 *   {bloco_analista} (somente em email_corpo)
 *
 * O helper aplica defaults defensivamente em runtime caso a chave ainda não
 * tenha sido populada (Configuracao::get retorna null), mas essa migration
 * grava o estado inicial em produção.
 *
 * @see app/Support/NpsTextRenderer.php
 * @see .planning/phases/32-customizacao-nps/32-CONTEXT.md (D-03)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Mesmos defaults retornados por NpsTextRenderer::defaults() — duplicação
        // consciente porque a migration roda antes do helper estar disponível em
        // ambientes que migrem do zero.
        $defaults = [
            'email_assunto'              => 'Pesquisa mensal de satisfação ECF — {mes_referencia}',
            'email_saudacao'             => 'Olá!',
            'email_corpo'                => "Esta é a nossa pesquisa mensal de satisfação. Sua resposta nos ajuda a entender o que está funcionando e o que podemos melhorar.\n\nSeu estrategista é **{nome_estrategista}**{bloco_analista}.\n\nLeva menos de 2 minutos.",
            'email_cta'                  => 'Responder pesquisa',
            'email_assinatura'           => "Obrigado,\nEquipe ECF",
            'perg_estrategista'          => 'O atendimento do {nome_estrategista}',
            'perg_analista'              => 'O atendimento do {nome_analista}',
            'perg_empresa'               => 'A ECF está atendendo suas expectativas?',
            'perg_comentario_label'      => 'Comentário (opcional)',
            'perg_comentario_placeholder'=> 'Opiniões, sugestões ou outra coisa que queira compartilhar',
            'perg_nome_label'            => 'Seu nome (opcional)',
        ];

        Configuracao::set('nps_textos', json_encode($defaults, JSON_UNESCAPED_UNICODE));
    }

    public function down(): void
    {
        Configuracao::where('chave', 'nps_textos')->delete();
    }
};
