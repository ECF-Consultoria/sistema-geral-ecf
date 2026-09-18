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
     * Os 9 itens do checklist, na ordem 1..9. Cada item tem o shape
     * `['ordem', 'chave', 'titulo', 'grupo', 'natureza', 'auto_fonte', 'ajuda']`,
     * mais duas chaves OPCIONAIS que só alguns itens declaram: `depende_de`
     * (chaves que precisam estar concluídas antes) e `exige_valor` (a coluna
     * de `companies` que precisa estar preenchida antes).
     *
     * ⚠️ A ordem NÃO é mais a da tabela D-03 original: `boas_vindas_enviada`
     * saiu da posição 4 para a 9 em 2026-09-18, a pedido do usuário — ver o
     * comentário no próprio item.
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
     * - Item 5 (`email_colaborador_criado`) é o único manual com evidência
     *   obrigatória: `exige_valor` prende a marcação ao endereço gravado.
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
     * @return array<int, array{ordem:int, chave:string, titulo:string, grupo:string, natureza:string, auto_fonte:?string, ajuda:string, depende_de?:array<int,string>, exige_valor?:string}>
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
                // Manual porque revisar contrato é ato humano, e nenhum dos 7
                // estados do envelope Clicksign representa "revisado" (D-06) —
                // exceção explícita ao ADMIN-02, em REQUIREMENTS-v23.md.
                'ajuda'      => 'Confira o contrato antes de enviar para assinatura.',
            ],
            [
                'ordem'      => 2,
                'chave'      => 'contrato_enviado',
                'titulo'     => 'Contrato enviado',
                'grupo'      => self::GRUPO_CONTRATO,
                'natureza'   => self::NATUREZA_AUTO,
                'auto_fonte' => self::AUTO_FONTE_CONTRATO_ENVIADO,
                'ajuda'      => 'Fecha sozinho quando o contrato for enviado para assinatura.',
            ],
            [
                'ordem'      => 3,
                'chave'      => 'contrato_assinado',
                'titulo'     => 'Contrato assinado',
                'grupo'      => self::GRUPO_CONTRATO,
                'natureza'   => self::NATUREZA_AUTO,
                'auto_fonte' => self::AUTO_FONTE_CONTRATO_ASSINADO,
                // Fecha também por liberação manual registrada para o serviço:
                // aquela via não grava no envelope da Clicksign (D-16).
                'ajuda'      => 'Fecha sozinho quando o cliente assinar, ou quando houver liberação registrada.',
            ],
            [
                'ordem'      => 4,
                'chave'      => 'grupo_whatsapp_criado',
                'titulo'     => 'Grupo de WhatsApp criado',
                'grupo'      => self::GRUPO_ENTRADA,
                'natureza'   => self::NATUREZA_MANUAL,
                'auto_fonte' => null,
                // Manual: não existe sinal observável de que o grupo foi criado,
                // e fabricar um seria marcar concluído sem evidência (D-13).
                'ajuda'      => 'Crie o grupo com o cliente e a equipe antes de enviar as boas-vindas.',
            ],
            [
                'ordem'      => 5,
                'chave'      => 'email_colaborador_criado',
                'titulo'     => 'E-mail colaborador criado',
                'grupo'      => self::GRUPO_ENTRADA,
                'natureza'   => self::NATUREZA_MANUAL,
                'auto_fonte' => null,
                // `exige_valor` (2026-09-18): quem cria o e-mail é o próprio
                // Administrativo, e o endereço criado entra LITERALMENTE na
                // mensagem de boas-vindas (`{email_colaborador}`). Marcar este
                // item sem gravar o endereço deixaria a mensagem com um bloco
                // vazio — é a única evidência que o item pode ter, então ela é
                // obrigatória. O campo mora na própria linha do checklist e
                // grava em `companies.email_colaborador`, a MESMA coluna que
                // `MensagemBoasVindasService` lê.
                'exige_valor' => 'email_colaborador',
                'ajuda'      => 'O endereço que a ECF cria para a operação do cliente.',
            ],
            [
                'ordem'      => 6,
                'chave'      => 'link_adman_entregue',
                'titulo'     => 'Link Adman entregue',
                'grupo'      => self::GRUPO_ENTRADA,
                'natureza'   => self::NATUREZA_MANUAL,
                'auto_fonte' => null,
                // Manual porque o link do Adman é fixo, idêntico para todas as
                // empresas, e mora numa chave de config (D-04) — não há estado
                // por empresa para observar.
                'ajuda'      => 'Copie o link de cadastro no Adman e entregue ao cliente.',
            ],
            [
                'ordem'      => 7,
                'chave'      => 'grant_consultoria_ml',
                'titulo'     => 'Grant da consultoria (OAuth Mercado Livre)',
                'grupo'      => self::GRUPO_ENTRADA,
                'natureza'   => self::NATUREZA_AUTO,
                'auto_fonte' => self::AUTO_FONTE_ML_OAUTH,
                // Nunca fecha por link GERADO: o link expira em 7 dias e o item
                // estaria mentindo o tempo todo (D-05).
                'ajuda'      => 'Fecha sozinho quando o cliente autorizar o acesso à conta dele.',
            ],
            [
                'ordem'      => 8,
                // ⚠️ A `chave` continua `conexao_ecf_gerada` de propósito — só o
                // TÍTULO mudou (2026-09-11, pedido do usuário). Trocar a chave
                // deixaria órfã toda linha já gravada em
                // `checklist_administrativo_itens`, e a ficha nunca mais
                // fecharia 100%. Aqui o título é lido do catálogo a cada render
                // (a tabela não o guarda), então renomear alcança todo mundo na
                // hora — diferente do checklist de Onboarding, onde o título
                // congela no nascimento.
                'chave'      => 'conexao_ecf_gerada',
                'titulo'     => 'Portal do Cliente',
                'grupo'      => self::GRUPO_ENTRADA,
                'natureza'   => self::NATUREZA_AUTO,
                'auto_fonte' => self::AUTO_FONTE_CONEXAO_ECF,
                // Fecha por EXISTÊNCIA de contato ativo em Acessos do portal,
                // gerado de forma idempotente (D-14).
                'ajuda'      => 'O endereço por onde o cliente acompanha o onboarding e envia o que pedimos. '
                    . 'Fecha sozinho quando houver um contato cadastrado.',
            ],
            [
                'ordem'      => 9,
                'chave'      => 'boas_vindas_enviada',
                'titulo'     => 'Boas-vindas enviada',
                'grupo'      => self::GRUPO_ENTRADA,
                'natureza'   => self::NATUREZA_MANUAL,
                'auto_fonte' => null,
                // ⚠️ ORDEM 9, e não 4 como nasceu (2026-09-18, pedido do usuário).
                //
                // A decisão de 2026-09-10 punha a mensagem logo após o contrato,
                // por ser "o que abre a relação com o cliente". Na prática isso
                // invertia a causalidade: a mensagem é MONTADA a partir do que o
                // Administrativo preencheu — o e-mail colaborador, o endereço do
                // Portal do Cliente — e mandá-la antes significava mandar texto
                // com bloco vazio. Por isso ela agora fecha a lista.
                //
                // `depende_de` é a régua, e ela é DELIBERADAMENTE curta: só os
                // itens cujo conteúdo ENTRA na mensagem e que dependem da ECF.
                // `link_adman_entregue` e `grant_consultoria_ml` NÃO entram, e
                // isso não é esquecimento — os dois são CONSEQUÊNCIA da mensagem
                // (é ela que leva o link do Adman e o de autorização do Mercado
                // Livre). Exigi-los aqui fecharia um ciclo: o cliente nunca
                // recebe a mensagem porque não autorizou, e não autoriza porque
                // não recebeu a mensagem.
                'depende_de' => [
                    'grupo_whatsapp_criado',
                    'email_colaborador_criado',
                    'conexao_ecf_gerada',
                ],
                // Manual: não existe sinal observável de que a mensagem foi
                // enviada, e fabricar um seria marcar concluído sem evidência (D-13).
                'ajuda'      => 'A mensagem abaixo é montada com o que foi preenchido acima. Copie e envie '
                    . 'no grupo do cliente.',
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
     * @return array<int, array{ordem:int, chave:string, titulo:string, grupo:string, natureza:string, auto_fonte:?string, ajuda:string, depende_de?:array<int,string>, exige_valor?:string}>
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
     * @return array{ordem:int, chave:string, titulo:string, grupo:string, natureza:string, auto_fonte:?string, ajuda:string, depende_de?:array<int,string>, exige_valor?:string}|null
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
