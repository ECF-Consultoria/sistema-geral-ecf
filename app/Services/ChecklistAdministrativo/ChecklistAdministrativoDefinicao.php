<?php

namespace App\Services\ChecklistAdministrativo;

/**
 * ChecklistAdministrativoDefinicao — o catálogo fechado dos 9 itens do
 * checklist administrativo (Fase 152, D-01/D-03), a lista dos estados
 * terminais do §5 da especificação — não os 12 passos intermediários do §3.
 *
 * Diferença estrutural em relação a
 * {@see \App\Support\Onboarding\DefinicaoOnboarding}, o molde desta classe:
 * lá a lista é parametrizada por `Servico` e carrega um número de revisão
 * copiado para colunas no nascimento de cada onboarding, porque um
 * onboarding em andamento não pode mudar debaixo do cliente. Aqui **não há
 * número de revisão e não há cópia para colunas**: o catálogo é lido a cada
 * montagem, os 9 itens são a lista travada pela D-01, e mudar um rótulo é
 * mudar uma constante em código e fazer deploy. A D-02 já aceitou esse
 * custo — não existe item configurável em tempo de execução.
 *
 * Nada nesta classe consulta o banco, injeta serviço ou chama resolver —
 * é catálogo puro.
 */
class ChecklistAdministrativoDefinicao
{
    // ─── Grupos (D-03) ────────────────────────────────────────────────────
    public const GRUPO_CONTRATO = 'contrato';
    public const GRUPO_ENTRADA = 'entrada';

    // ─── Natureza — como o item fecha (D-03) ─────────────────────────────
    public const NATUREZA_MANUAL = 'manual';
    public const NATUREZA_AUTO = 'auto';

    // ─── Chaves de resolver — batem 1:1 com ChecklistResolver::chave() ──
    public const AUTO_FONTE_CONTRATO_ENVIADO = 'contrato_enviado';
    public const AUTO_FONTE_CONTRATO_ASSINADO = 'contrato_assinado';
    public const AUTO_FONTE_ML_OAUTH = 'ml_oauth_conectado';
    public const AUTO_FONTE_CONEXAO_ECF = 'conexao_ecf';

    /**
     * Os 9 itens do checklist, na ordem 1..9 da tabela D-03. Cada item tem o
     * shape `['ordem', 'chave', 'titulo', 'grupo', 'natureza', 'auto_fonte', 'ajuda']`.
     *
     * A `natureza` de cada item é decisão registrada, não falta de opção:
     *
     * - Item 1 (`contrato_revisado`) é manual porque revisar contrato é ato
     *   humano, e nenhum dos 7 estados do envelope Clicksign o representa
     *   (D-06) — exceção explícita ao ADMIN-02, registrada em
     *   `REQUIREMENTS-v23.md`.
     * - Itens 4, 5, 6 e 9 são manuais porque não existe sinal observável
     *   para nenhum deles, e fabricar um seria marcar concluído sem
     *   evidência (D-13).
     * - Item 6 em particular: o link do Adman é fixo, idêntico para todas
     *   as empresas, e mora em `config('services.adman.register_url')`
     *   (D-04) — não há estado por empresa para observar.
     * - Item 7 fecha só quando o cliente efetivamente **conectou** via OAuth
     *   do Mercado Livre, nunca quando o link foi apenas gerado — link
     *   gerado expira em 7 dias e o item estaria mentindo o tempo todo
     *   (D-05).
     * - Item 8 fecha por **existência** do link de conexão com o sistema
     *   ECF da empresa, gerado de forma idempotente (D-14).
     *
     * @return array<int, array{ordem:int, chave:string, titulo:string, grupo:string, natureza:string, auto_fonte:?string, ajuda:string}>
     */
    private static function todos(): array
    {
        return [
            [
                'ordem'      => 1,
                'chave'      => 'contrato_revisado',
                'titulo'     => 'Contrato revisado',
                'grupo'      => self::GRUPO_CONTRATO,
                'natureza'   => self::NATUREZA_MANUAL,
                'auto_fonte' => null,
                'ajuda'      => 'Revisar o contrato antes do envio é ato humano — nenhum dos estados do '
                    . 'envelope Clicksign representa "revisado" (D-06). Marcação manual, com autoria.',
            ],
            [
                'ordem'      => 2,
                'chave'      => 'contrato_enviado',
                'titulo'     => 'Contrato enviado',
                'grupo'      => self::GRUPO_CONTRATO,
                'natureza'   => self::NATUREZA_AUTO,
                'auto_fonte' => self::AUTO_FONTE_CONTRATO_ENVIADO,
                'ajuda'      => 'Fecha automaticamente quando o envelope Clicksign foi enviado para '
                    . 'assinatura (D-03).',
            ],
            [
                'ordem'      => 3,
                'chave'      => 'contrato_assinado',
                'titulo'     => 'Contrato assinado',
                'grupo'      => self::GRUPO_CONTRATO,
                'natureza'   => self::NATUREZA_AUTO,
                'auto_fonte' => self::AUTO_FONTE_CONTRATO_ASSINADO,
                'ajuda'      => 'Fecha automaticamente quando o envelope foi assinado, ou quando existe '
                    . 'liberação registrada para o serviço — a via manual de liberação não grava no '
                    . 'envelope (D-16).',
            ],
            [
                'ordem'      => 4,
                'chave'      => 'boas_vindas_enviada',
                'titulo'     => 'Boas-vindas enviada',
                'grupo'      => self::GRUPO_ENTRADA,
                'natureza'   => self::NATUREZA_MANUAL,
                'auto_fonte' => null,
                'ajuda'      => 'Vem logo após o contrato (decisão do usuário, 2026-09-10): é a mensagem que '
                    . 'abre a relação com o cliente. Não existe sinal observável de que foi enviada — '
                    . 'marcação manual, com autoria (D-13).',
            ],
            [
                'ordem'      => 5,
                'chave'      => 'grupo_whatsapp_criado',
                'titulo'     => 'Grupo de WhatsApp criado',
                'grupo'      => self::GRUPO_ENTRADA,
                'natureza'   => self::NATUREZA_MANUAL,
                'auto_fonte' => null,
                'ajuda'      => 'Não existe sinal observável de que o grupo foi criado. Marcação manual, '
                    . 'com autoria (D-13).',
            ],
            [
                'ordem'      => 6,
                'chave'      => 'email_colaborador_criado',
                'titulo'     => 'E-mail colaborador criado',
                'grupo'      => self::GRUPO_ENTRADA,
                'natureza'   => self::NATUREZA_MANUAL,
                'auto_fonte' => null,
                'ajuda'      => 'Não existe sinal observável de que o e-mail do colaborador foi criado. '
                    . 'Marcação manual, com autoria (D-13).',
            ],
            [
                'ordem'      => 7,
                'chave'      => 'link_adman_entregue',
                'titulo'     => 'Link Adman entregue',
                'grupo'      => self::GRUPO_ENTRADA,
                'natureza'   => self::NATUREZA_MANUAL,
                'auto_fonte' => null,
                'ajuda'      => 'O link de cadastro no Adman é fixo, igual para todas as empresas, e mora '
                    . 'em config(\'services.adman.register_url\') — não há estado por empresa para '
                    . 'observar (D-04). Marcação manual, com autoria.',
            ],
            [
                'ordem'      => 8,
                'chave'      => 'grant_consultoria_ml',
                'titulo'     => 'Grant da consultoria (OAuth Mercado Livre)',
                'grupo'      => self::GRUPO_ENTRADA,
                'natureza'   => self::NATUREZA_AUTO,
                'auto_fonte' => self::AUTO_FONTE_ML_OAUTH,
                'ajuda'      => 'Fecha somente quando o cliente efetivamente conectou via OAuth do Mercado '
                    . 'Livre — nunca quando o link de autorização foi apenas gerado, que expira em 7 dias '
                    . '(D-05).',
            ],
            [
                'ordem'      => 9,
                'chave'      => 'conexao_ecf_gerada',
                'titulo'     => 'Conexão com o sistema ECF gerada',
                'grupo'      => self::GRUPO_ENTRADA,
                'natureza'   => self::NATUREZA_AUTO,
                'auto_fonte' => self::AUTO_FONTE_CONEXAO_ECF,
                'ajuda'      => 'Fecha pela existência do link de conexão com o sistema ECF da empresa, '
                    . 'gerado de forma idempotente (D-14).',
            ],
        ];
    }

    /**
     * Devolve os itens do checklist conforme o serviço exige contrato ou não.
     *
     * `$exigeContrato = true` devolve os 9 itens. `$exigeContrato = false`
     * devolve **apenas os 6 itens do grupo Entrada** — para serviço isento
     * (hoje só `Polos`, `servicos.id=2`) o grupo Contrato **não existe** para
     * aquela empresa (D-07). Não é item marcado "não aplicável" à mão
     * (proibido pela D-02): é item que nem é instanciado, e o denominador do
     * progresso acompanha. Sem isso, essas empresas teriam 3 itens
     * pendentes para sempre e nunca poderiam finalizar a entrada
     * administrativa.
     *
     * @return array<int, array{ordem:int, chave:string, titulo:string, grupo:string, natureza:string, auto_fonte:?string, ajuda:string}>
     */
    public static function itens(bool $exigeContrato): array
    {
        $todos = self::todos();

        if ($exigeContrato) {
            return $todos;
        }

        return array_values(array_filter(
            $todos,
            static fn (array $item): bool => $item['grupo'] === self::GRUPO_ENTRADA
        ));
    }

    /**
     * Busca um item pela chave no catálogo completo (9 itens, independente
     * de `exigeContrato`). Devolve `null` para chave desconhecida — essa é
     * a resposta correta e esperada para uma chave órfã, nunca uma exceção
     * aqui. Quem valida entrada de requisição é o controller, com
     * `Rule::in()` sobre {@see self::chaves()}.
     *
     * @return array{ordem:int, chave:string, titulo:string, grupo:string, natureza:string, auto_fonte:?string, ajuda:string}|null
     */
    public static function item(string $chave): ?array
    {
        foreach (self::todos() as $item) {
            if ($item['chave'] === $chave) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Só os slugs dos itens aplicáveis, para uso em `Rule::in()` na validação
     * de requisição (T-152-03-01).
     *
     * @return array<int, string>
     */
    public static function chaves(bool $exigeContrato): array
    {
        return array_column(self::itens($exigeContrato), 'chave');
    }
}
