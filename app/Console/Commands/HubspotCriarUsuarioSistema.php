<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * hubspot:criar-usuario-sistema — cria (uma única vez) a conta de sistema
 * "Sistema HubSpot", ator da transição de nascimento na etapa 1 quando o
 * webhook do HubSpot roda sem sessão autenticada (Fase 138, COMERC-01/D-17).
 *
 * `EtapaTransicaoService::transicionar()` exige um `User` real e não-nulo
 * (tipo estrito) — a D-17 travou a saída: nunca tornar o parâmetro nullable
 * (reabriria um serviço que a Fase 137 fechou/testou), nunca apontar para um
 * admin real (geraria histórico falso na timeline da Fase 143, o mesmo
 * problema que D-14 desta fase e D-05 da Fase 137 proíbem). A saída é uma
 * conta dedicada, resolvida por `config('services.hubspot.webhook_user_id')`.
 *
 * Idempotente: se o e-mail canônico já existe, não cria outro — imprime o id
 * existente e sai com 0. Seguro por construção:
 *  - `role = 'consultor'` (o MENOR dos três valores do enum admin/consultor/
 *    mentor) — nunca admin;
 *  - `active = false` — defesa em profundidade;
 *  - sem linha em `user_setores` (fonte de cargo/permissão por setor) nem em
 *    `company_users` — a conta não tem NENHUMA permissão efetiva
 *    (`User::hasPermission()` sempre `false` para ela, `isAdmin()` sempre
 *    `false`);
 *  - senha hasheada a partir de uma string aleatória de 64 caracteres — o
 *    valor aleatório é gerado, usado e DESCARTADO na mesma expressão, nunca
 *    impresso, nunca logado, nunca guardado numa variável que apareça na
 *    saída do comando.
 *
 * ⚠️ ACHADO MEDIDO NESTA SESSÃO, na letra para quem for endurecer isto
 * depois: o fluxo de login deste projeto NÃO checa `users.active` — não há
 * verificação de `active` em `LoginRequest` nem nos controllers de `Auth/`.
 * Portanto `active = false` acima é defesa em profundidade, e é a SENHA
 * ALEATÓRIA DE 64 CARACTERES que de fato torna esta conta não-logável. Quem
 * assumir que `active = false` bloqueia login está presumindo uma proteção
 * que não existe no código atual.
 */
class HubspotCriarUsuarioSistema extends Command
{
    /**
     * E-mail canônico da conta de sistema — chave de idempotência.
     */
    private const EMAIL = 'sistema.hubspot@ecfconsultoria.com.br';

    protected $signature = 'hubspot:criar-usuario-sistema
        {--apply : Cria de verdade. Sem esta flag o comando só mostra o que faria}';

    protected $description = 'Cria (idempotente) a conta de sistema "Sistema HubSpot", ator da transição de nascimento do webhook (Fase 138, D-17)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $existente = User::where('email', self::EMAIL)->first();

        if ($existente !== null) {
            $this->info("Conta de sistema já existe — id={$existente->id}, email=" . self::EMAIL . '.');
            $this->line('Nada foi criado (idempotência). Se HUBSPOT_WEBHOOK_USER_ID ainda não aponta para este id, atualize o .env.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('MODO DRY-RUN — nada foi criado. Rode de novo com --apply para criar a conta de verdade.');
            $this->line('Criaria: name="Sistema HubSpot", email=' . self::EMAIL . ", role='consultor', active=false, sem user_setores, sem company_users.");

            return self::SUCCESS;
        }

        // Senha aleatória de 64 caracteres: gerada, usada e descartada na
        // MESMA expressão — nunca atribuída a uma variável própria, nunca
        // impressa, nunca logada. Junto com active=false (defesa em
        // profundidade), é o que torna a conta não-logável de fato, já que
        // o login deste projeto não checa `active` (achado documentado acima).
        $user = User::create([
            'name'     => 'Sistema HubSpot',
            'email'    => self::EMAIL,
            'password' => Hash::make(Str::random(64)),
            'role'     => 'consultor',
            'active'   => false,
        ]);

        // Nenhuma linha em user_setores (cargo/permissão por setor) nem em
        // company_users — de propósito, a conta não deve ter NENHUMA
        // permissão efetiva além do que `isAdmin()`/`hasPermission()` já
        // negam por não ser admin e não ter setor.

        $this->info("Conta de sistema criada — id={$user->id}.");
        $this->line('Coloque esta linha no .env (local E na VPS, antes do primeiro deploy da Fase 138):');
        $this->line("HUBSPOT_WEBHOOK_USER_ID={$user->id}");
        $this->warn(
            'Registre esta conta onde ela não vire login esquecido — precedente direto: o usuário '
            . 'de review da Shopee (users.id=30) segue ativo em produção desde 2026-07-16 porque '
            . 'ninguém anotou que precisava sair.'
        );

        // Nunca em ambiente de teste: a suíte roda este comando sobre um
        // banco sqlite `:memory:` descartável (RefreshDatabase) — deixar o
        // arquivo real de documentação ser sobrescrito com o id efêmero de
        // uma execução de teste corromperia o registro por escrito que a
        // D-17 exige. Só a execução real (local ou VPS) documenta.
        if (! app()->environment('testing')) {
            $this->registrarDocumentacao($user->id);
        }

        return self::SUCCESS;
    }

    /**
     * Escreve/atualiza o registro por escrito da conta, para o plano 138-09
     * preencher o `id_vps:` depois de criar a mesma conta na VPS.
     */
    private function registrarDocumentacao(int $userId): void
    {
        $caminho = base_path('.planning/phases/138-rea-comercial-conectada-etapa-v23-0/138-CONTA-SISTEMA-HUBSPOT.md');

        $conteudo = <<<MD
        # Fase 138 — Conta de sistema "Sistema HubSpot" (D-17)

        Ator da transição de nascimento na etapa 1 quando o webhook do HubSpot roda
        sem sessão autenticada. Criada por `php artisan hubspot:criar-usuario-sistema --apply`.

        - **id_local:** {$userId}
        - **id_vps:** (preencher no plano 138-09, depois de rodar o mesmo comando na VPS)
        - **email:** sistema.hubspot@ecfconsultoria.com.br
        - **criado_em:** {$this->agora()}
        - **role:** consultor (menor valor do enum — nunca admin)
        - **active:** false (defesa em profundidade)
        - **não-logável de fato por:** senha aleatória de 64 caracteres, hasheada e descartada
          na criação — NÃO pelo `active=false` (o login deste projeto não checa `users.active`).
        - **sem `user_setores` e sem `company_users`** — nenhuma permissão efetiva.

        ⚠️ Lembrete registrado por escrito (D-17): não deixe esta conta virar login
        esquecido. Precedente: usuário de review da Shopee (`users.id=30`), ativo em
        produção desde 2026-07-16 porque ninguém anotou que precisava sair.

        Depois de criar em cada ambiente, colocar `HUBSPOT_WEBHOOK_USER_ID={id}` no
        `.env` daquele ambiente (local e VPS, separadamente).
        MD;

        file_put_contents($caminho, $conteudo . "\n");
    }

    private function agora(): string
    {
        return now()->toDateString();
    }
}
