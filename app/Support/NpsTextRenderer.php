<?php

namespace App\Support;

use App\Models\Configuracao;

/**
 * Renderizador de textos customizáveis do NPS (Phase 32, Plan 01).
 *
 * Responsável por:
 *  - aplicar substituição de placeholders (`{nome_estrategista}` etc.) em strings
 *    livres editadas pelo admin na futura página `/nps/configuracao`;
 *  - escapar HTML quando o texto vai ser injetado em template Blade (XSS safe);
 *  - fornecer defaults defensivos quando a chave `nps_textos` ainda não existe
 *    em `configuracoes` (ambiente recém-migrado ou config apagada via admin).
 *
 * Decisões D-03 (LOCKED): 1 chave JSON `nps_textos` com 11 textos
 * (5 do email + 6 da página /nps/respond).
 *
 * Placeholders suportados:
 *   {nome_estrategista}, {nome_analista}, {nome_empresa}, {mes_referencia},
 *   {bloco_analista}  (apenas em email_corpo — string vazia em mentoria pura)
 *
 * @see app/Models/Configuracao.php (KV store)
 * @see .planning/phases/32-customizacao-nps/32-CONTEXT.md (D-03)
 */
class NpsTextRenderer
{
    /**
     * Defaults canônicos do CONTEXT D-03. Idempotente — mesma estrutura usada
     * pela migration de seed (database/migrations/2026_06_11_200002_*).
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
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
    }

    /**
     * Lê os textos persistidos em `configuracoes.nps_textos`, decodifica JSON
     * e mescla com os defaults — chaves ausentes na config caem para o default
     * automaticamente (defensivo contra config parcialmente populada).
     *
     * @return array<string, string>
     */
    public static function getTextos(): array
    {
        $raw = Configuracao::get('nps_textos');

        if (! is_string($raw) || $raw === '') {
            return self::defaults();
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return self::defaults();
        }

        // Merge garante que qualquer chave faltante use o default — protege contra
        // edição parcial que tenha apagado uma chave por engano.
        return array_merge(self::defaults(), $decoded);
    }

    /**
     * Substitui placeholders `{chave}` em um template texto-puro.
     *
     * NÃO aplica escape HTML — use para campos que serão exibidos como texto
     * (assunto do email, texto do botão CTA, etc).
     *
     * @param array<string, string|null> $vars
     */
    public static function render(string $template, array $vars): string
    {
        $search  = [];
        $replace = [];

        foreach ($vars as $chave => $valor) {
            $search[]  = '{' . $chave . '}';
            $replace[] = (string) ($valor ?? '');
        }

        return str_replace($search, $replace, $template);
    }

    /**
     * Versão HTML-safe: aplica e() (htmlspecialchars) em cada variável ANTES de
     * substituir no template, e depois converte quebras de linha `\n` em `<br>`
     * para preservar a formatação do textarea.
     *
     * Use em campos que vão ser renderizados como HTML dentro do template Blade
     * (saudação, corpo do email, assinatura).
     *
     * @param array<string, string|null> $vars
     */
    public static function renderHtml(string $template, array $vars): string
    {
        // Escapa cada variável antes do substitute para evitar XSS via valores
        // controlados (nome de empresa com `<script>`, por exemplo).
        $escapadas = [];
        foreach ($vars as $chave => $valor) {
            $escapadas[$chave] = e((string) ($valor ?? ''));
        }

        $rendered = self::render($template, $escapadas);

        // Preserva quebras de linha do textarea — admin escreve em texto plano
        // e espera que parágrafos quebrem visualmente no email.
        return nl2br($rendered, false);
    }
}
