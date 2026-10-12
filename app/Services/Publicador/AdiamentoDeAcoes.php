<?php

namespace App\Services\Publicador;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "Adiar até amanhã" das ações de "O que fazer agora" (Fase 179, Plano 02).
 *
 * VG-11 exige que o adiamento **sobreviva à recarga da página**: clicar em
 * "Adiar até amanhã" e dar F5 não pode trazer a ação de volta.
 *
 * ═══ POR QUE NO CACHE, E NÃO EM TABELA ══════════════════════════════════════
 *
 * Decisão tomada antes da execução, com o motivo: a milestone v26.0 declarou
 * **nenhuma migration**, e um adiamento é efêmero por natureza — ele morre
 * amanhã de qualquer jeito. Perder um adiamento só faz a ação **reaparecer**,
 * que é o lado benigno da falha: o usuário vê trabalho que ainda existe, nunca
 * deixa de ver trabalho que existe.
 *
 * ⚠️ **O custo honesto:** um `cache:clear` no VPS apagaria todos os adiamentos.
 * Isso é tolerável por dois motivos. Primeiro, eles duram horas, não meses.
 * Segundo, `cache:clear` **já é operação proibida neste projeto** por um motivo
 * muito pior: em 30/07/2026 ela derrubou o site inteiro — apagou o cache aquecido
 * da Adman, o dashboard passou a esperar a API, as requisições lentas ocuparam os
 * workers do php-fpm e até o login parou. O procedimento correto é
 * `systemctl reload php8.2-fpm` (mais `adman:warm-diff`), que não toca no cache.
 *
 * Em produção o store é **Redis**; em dev, `database` (a tabela `cache` existe
 * desde `0001_01_01_000001_create_cache_table.php`); nos testes, `array`.
 *
 * ═══ UM SACO POR USUÁRIO + CONTA, NUNCA UMA CHAVE POR AÇÃO ══════════════════
 *
 * A chave do cache é `publicador:adiar:{userId}:{contaChave}` e o valor é um mapa
 * `chave_da_acao => ISO-8601 da expiração`.
 *
 * Uma chave de cache por ação exigiria **varrer o Redis por prefixo** em request
 * de tela (`KEYS`/`SCAN` — operação que não se faz num request síncrono) ou saber
 * de antemão todas as chaves possíveis para fazer um `getMultiple`. O saco único
 * custa **uma** leitura por chamada de tela, travada por teste (`->once()`).
 *
 * `$userId` e `$contaChave` entram na **chave**, não no valor (T-179-06): não há
 * como o adiamento de um usuário atingir outro, nem o de uma conta vazar para
 * outra, porque eles nem compartilham entrada no cache.
 *
 * ═══ A LISTA É FECHADA (T-179-05 / T-179-07) ════════════════════════════════
 *
 * A `chaveAcao` vem do cliente. Só é gravada se existir em
 * `AcoesDaContaService::SEVERIDADE` — doze chaves. Sem isso o cliente poderia
 * inflar o saco com chaves arbitrárias. A validação dura `in_array` na borda (a
 * action HTTP) é do plano 179-04; aqui o serviço simplesmente **não grava**.
 *
 * Nenhum `Log::` por adiamento: é ação de rotina, por tela, e encheria o log.
 */
class AdiamentoDeAcoes
{
    private const PREFIXO = 'publicador:adiar';

    /**
     * A hora da volta. ⚠️ **Não é enfeite: é contrato com o texto da tela.** O
     * literal do protótipo é *"Ações adiadas voltam amanhã às 8h."*
     * (`new_layout_publicador/Visao Geral.dc.html:229`).
     */
    private const HORA_DA_VOLTA = 8;

    /**
     * Guarda que esta ação não deve aparecer até amanhã às 8h, para ESTE usuário
     * nesta conta.
     *
     * As entradas já vencidas saem do saco a cada gravação — é o que impede o
     * saco de crescer sem fim (T-179-07).
     */
    public function adiar(int $userId, string $contaChave, string $chaveAcao): void
    {
        // T-179-05: lista FECHADA. Chave que não é ação conhecida não vira entrada
        // de cache. Silencioso de propósito — o cliente não tem nada a fazer com um
        // erro aqui, e a borda (plano 179-04) é quem recusa a requisição.
        if (! array_key_exists($chaveAcao, AcoesDaContaService::SEVERIDADE)) {
            return;
        }

        $saco = $this->sacoVivo($userId, $contaChave);
        $saco[$chaveAcao] = $this->expiraEm()->toIso8601String();

        // O TTL do SACO é o da entrada mais distante — ver a armadilha em `adiadas()`.
        Cache::put($this->chaveDoCache($userId, $contaChave), $saco, $this->maiorExpiracao($saco));
    }

    /**
     * As chaves de ação que este usuário adiou nesta conta e que **ainda não
     * voltaram**.
     *
     * ⚠️ **O filtro por entrada é obrigatório e NÃO é redundante com o TTL do
     * saco.** O TTL do saco é o da entrada mais distante, então uma entrada
     * individual pode ter vencido enquanto o saco ainda está vivo no cache.
     * Exemplo concreto: adiar `pausados` ontem às 23h (vence hoje às 8h) e
     * `com_problema` hoje às 7h (vence amanhã às 8h) — o saco passa a viver até
     * amanhã e carrega a entrada vencida de `pausados` junto. Sem este filtro,
     * `pausados` ficaria escondida depois das 8h de hoje, exatamente quando
     * deveria voltar. É a armadilha do desenho de saco único, e tem teste próprio
     * (`test_entrada_vencida_dentro_de_saco_ainda_vivo_nao_esconde_a_acao`).
     *
     * @return list<string>
     */
    public function adiadas(int $userId, string $contaChave): array
    {
        return array_keys($this->sacoVivo($userId, $contaChave));
    }

    /**
     * Amanhã às 08:00 no fuso da aplicação (`America/Sao_Paulo`).
     *
     * ⚠️ `tomorrow()`, **não** `now()->addDay()`: "Adiar até amanhã" é o **dia
     * seguinte do calendário**, não 24 horas a partir de agora. Quem adia às 23h
     * não compra um dia inteiro — compra até as 8h de amanhã, que é daqui a nove
     * horas. E o fuso é o da aplicação explicitamente: o dia seguinte de quem
     * está em São Paulo, nunca o de UTC (às 23h de São Paulo já é o dia seguinte
     * em UTC, e o adiamento duraria um dia a mais).
     */
    public function expiraEm(): Carbon
    {
        return Carbon::tomorrow(config('app.timezone'))->setTime(self::HORA_DA_VOLTA, 0);
    }

    private function chaveDoCache(int $userId, string $contaChave): string
    {
        return self::PREFIXO.":{$userId}:{$contaChave}";
    }

    /**
     * O saco lido do cache, sem as entradas vencidas e sem o que não for
     * `chave string => ISO string` parseável.
     *
     * **Robustez no padrão "log e segue" do projeto, sem o log:** valor que não é
     * array devolve `[]`; entrada com chave não-string (lista em vez de mapa),
     * valor não-string ou ISO não parseável é **descartada**, nunca lançada. Uma
     * ação reaparecendo é falha benigna; uma exceção na Visão geral não é — e o
     * conteúdo deste cache não é dado de origem, é preferência descartável.
     *
     * @return array<string, string>
     */
    private function sacoVivo(int $userId, string $contaChave): array
    {
        $bruto = Cache::get($this->chaveDoCache($userId, $contaChave));
        if (! is_array($bruto)) {
            return [];
        }

        $agora = Carbon::now(config('app.timezone'));
        $vivo = [];

        foreach ($bruto as $chave => $iso) {
            if (! is_string($chave) || $chave === '' || ! is_string($iso)) {
                continue;
            }
            try {
                if (Carbon::parse($iso)->isAfter($agora)) {
                    $vivo[$chave] = $iso;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $vivo;
    }

    /**
     * O instante mais distante presente no saco — o TTL com que ele é regravado.
     * Saco já filtrado por `sacoVivo()`, então todas as entradas são futuras.
     *
     * @param  array<string, string>  $saco
     */
    private function maiorExpiracao(array $saco): Carbon
    {
        $maior = $this->expiraEm();

        foreach ($saco as $iso) {
            try {
                $instante = Carbon::parse($iso);
            } catch (\Throwable) {
                continue;
            }
            if ($instante->isAfter($maior)) {
                $maior = $instante;
            }
        }

        return $maior;
    }
}
