<?php

namespace App\Support\Publicador;

use App\Models\Configuracao;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\IaParaRascunhoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A garantia padrão de uma conta do Publicador (decisão do usuário, 10/10/2026).
 *
 * O Portal não pergunta garantia e o Mercado Livre não publica sem ela (V-SAL-05): sem um padrão, todo produto que
 * chega do Portal travava na conferência e a publicação em massa não saía "de primeira" (achado do teste de ponta a
 * ponta na #459). A equipe define UMA vez por conta, na tela de Publicação em lote ("uma empresa tem 7 dias de
 * garantia, outra 90"); ela entra sozinha em todo rascunho SEM garantia e ACOMPANHA o padrão — trocar de 90 para
 * 7 dias troca os rascunhos que ainda estão com o padrão antigo (pedido do usuário, 10/10/2026). Nunca troca a que
 * alguém escolheu no editor. Quem diz "veio do padrão" é a marca `step_state.garantia_padrao` (a mesma régua do
 * "campo com origem=portal acompanha o Portal"); rascunho de antes da marca vale como do padrão se tiver EXATAMENTE
 * o padrão anterior. Aplica:
 * - no Sincronizar/preparo do Portal (`PortalParaRascunhoService`), a cada produto que chega;
 * - ao salvar o padrão, nos rascunhos da conta que já existem (`aplicarNaConta`).
 *
 * Guardada em `configuracoes` (sem migration), uma linha por ÂNCORA da conta (`empresa-N` e/ou `company-N`): o
 * produto pode estar ancorado só numa delas, e o padrão tem de achá-lo pelas duas.
 */
final class GarantiaPadrao
{
    public const PREFIXO = 'publicador_garantia_padrao:';

    /** Os tipos do `sale_terms` WARRANTY_TYPE do ML (os mesmos em todas as categorias sondadas). */
    public const TIPOS = [
        '2230280' => 'Garantia do vendedor',
        '2230279' => 'Garantia de fábrica',
        '6150835' => 'Sem garantia',
    ];

    public const SEM_GARANTIA = '6150835';

    /** As unidades do WARRANTY_TIME (`allowed_units`). */
    public const UNIDADES = ['dias', 'meses', 'anos'];

    public const TEMPO_MAXIMO = 999;

    /** A marca, em `step_state`, da garantia que o PADRÃO gravou no rascunho (o que a equipe escolheu não tem). */
    public const MARCA = 'garantia_padrao';

    /** A garantia no formato do rascunho (`tipo`, `tempo`, `unidade`), ou null se não for válida. */
    public static function normalizar(mixed $g): ?array
    {
        if (! is_array($g)) {
            return null;
        }
        $tipo = (string) ($g['tipo'] ?? '');
        if (! isset(self::TIPOS[$tipo])) {
            return null;
        }
        if ($tipo === self::SEM_GARANTIA) {
            return ['tipo' => $tipo, 'tempo' => null, 'unidade' => null];
        }
        $tempo = is_numeric($g['tempo'] ?? null) ? (int) $g['tempo'] : 0;
        $unidade = (string) ($g['unidade'] ?? '');
        if ($tempo < 1 || $tempo > self::TEMPO_MAXIMO || ! in_array($unidade, self::UNIDADES, true)) {
            return null;
        }

        return ['tipo' => $tipo, 'tempo' => $tempo, 'unidade' => $unidade];
    }

    /** "Garantia do vendedor, 90 dias" / "Sem garantia". */
    public static function texto(?array $g): ?string
    {
        $g = self::normalizar($g);
        if ($g === null) {
            return null;
        }

        return $g['tipo'] === self::SEM_GARANTIA ? self::TIPOS[$g['tipo']] : self::TIPOS[$g['tipo']].", {$g['tempo']} {$g['unidade']}";
    }

    /** O rascunho já tem garantia (qualquer tipo escolhido)? */
    public static function temGarantia(PubRascunho $r): bool
    {
        return trim((string) ($r->garantia['tipo'] ?? '')) !== '';
    }

    // ═══ Onde mora ═══════════════════════════════════════════════════════════

    /** @param  array{mlb_empresa: ?\App\Models\MlbEmpresa, company: ?\App\Models\Company}  $alvo */
    public static function daConta(array $alvo): ?array
    {
        return self::primeira(self::chavesDaConta($alvo));
    }

    public static function doProduto(PubProduto $p): ?array
    {
        $chaves = [];
        if ($p->mlb_empresa_id !== null) {
            $chaves[] = self::PREFIXO.'empresa-'.$p->mlb_empresa_id;
        }
        if ($p->company_id !== null) {
            $chaves[] = self::PREFIXO.'company-'.$p->company_id;
        }

        return self::primeira($chaves);
    }

    /**
     * Grava (null = apaga) a garantia padrão nas âncoras da conta.
     *
     * @param  array{mlb_empresa: ?\App\Models\MlbEmpresa, company: ?\App\Models\Company, chave: string}  $alvo
     */
    public static function salvar(array $alvo, ?array $garantia, User $quem): ?array
    {
        $g = $garantia === null ? null : self::normalizar($garantia);
        foreach (self::chavesDaConta($alvo) as $chave) {
            if ($g === null) {
                Configuracao::query()->where('chave', $chave)->delete();
            } else {
                Configuracao::set($chave, json_encode([...$g, 'por' => $quem->id, 'em' => now()->toIso8601String()]));
            }
        }
        Log::info("[Publicador] Garantia padrão da conta {$alvo['chave']}: ".(self::texto($g) ?? 'removida')." (por {$quem->name}).");

        return $g;
    }

    // ═══ Aplicar ═════════════════════════════════════════════════════════════

    /**
     * O padrão pode escrever neste rascunho? Sim quando ele não tem garantia, ou quando a que tem ainda é a que o
     * padrão gravou (a marca) — ou, sem marca, quando é exatamente o padrão `$anterior`. A escolhida pela equipe, não.
     */
    public static function podeAplicar(PubRascunho $r, ?array $anterior = null): bool
    {
        if (IaParaRascunhoService::intocavel($r)) {
            return false;
        }
        if (! self::temGarantia($r)) {
            return true;
        }
        $atual = self::normalizar($r->garantia);
        $marca = self::normalizar($r->step_state[self::MARCA] ?? null);
        if ($atual === null) {
            return false;
        }

        return $marca !== null ? $atual == $marca : ($anterior !== null && $atual == self::normalizar($anterior));
    }

    /**
     * Põe o padrão no rascunho quando `podeAplicar` (sobe a revisão: uma conferência de antes não vale mais) e marca
     * que veio do padrão. Quem chama segura a trava do rascunho. true = a garantia mudou.
     */
    public static function aplicar(PubRascunho $r, array $garantia, ?array $anterior = null): bool
    {
        $g = self::normalizar($garantia);
        if ($g === null || ! self::podeAplicar($r, $anterior)) {
            return false;
        }
        $estado = (array) ($r->step_state ?? []);
        $mudou = self::normalizar($r->garantia) != $g;
        if (! $mudou && self::normalizar($estado[self::MARCA] ?? null) == $g) {
            return false;
        }
        $estado[self::MARCA] = $g;
        $r->forceFill(['garantia' => $g, 'step_state' => $estado])->save();
        if ($mudou) {
            $r->increment('revisao');
        }

        return $mudou;
    }

    /**
     * A garantia padrão nos rascunhos da conta que ainda não têm garantia ou que estão com o padrão anterior
     * (`$anterior`, o que valia antes deste save). Pula quem não pode ser mexido agora: publicado/publicando, editor
     * aberto (o save da tela gravaria por cima) e produto na fila de publicação (foi conferido como está) — esses
     * recebem no próximo Sincronizar.
     *
     * @param  iterable<int>  $produtoIds  os produtos da conta
     * @return int quantos rascunhos tiveram a garantia trocada
     */
    public static function aplicarNaConta(iterable $produtoIds, array $garantia, ?array $anterior = null): int
    {
        $n = 0;
        foreach ($produtoIds as $produtoId) {
            $produtoId = (int) $produtoId;
            if (EditorEmUso::emUso($produtoId) || NaFilaDePublicacao::emUso($produtoId)) {
                continue;
            }
            $n += (int) DB::transaction(function () use ($produtoId, $garantia, $anterior) {
                $r = PubRascunho::query()->where('produto_id', $produtoId)->lockForUpdate()->first();

                return $r !== null && self::aplicar($r, $garantia, $anterior);
            });
        }

        return $n;
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /** @return list<string> */
    private static function chavesDaConta(array $alvo): array
    {
        $chaves = [];
        if (($alvo['mlb_empresa'] ?? null) !== null) {
            $chaves[] = self::PREFIXO.'empresa-'.$alvo['mlb_empresa']->id;
        }
        if (($alvo['company'] ?? null) !== null) {
            $chaves[] = self::PREFIXO.'company-'.$alvo['company']->id;
        }

        return $chaves;
    }

    /** @param  list<string>  $chaves */
    private static function primeira(array $chaves): ?array
    {
        foreach ($chaves as $chave) {
            $valor = Configuracao::get($chave);
            $g = self::normalizar(is_string($valor) ? json_decode($valor, true) : $valor);
            if ($g !== null) {
                return $g;
            }
        }

        return null;
    }
}
