<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Entidade que pode ter uma conta do Mercado Livre conectada por OAuth.
 *
 * Existe porque a conexão com o ML tem DUAS âncoras no sistema, e nenhuma
 * delas pode virar a outra:
 *
 *  - `Company`    — cliente de consultoria; pivô de Desempenho/carteira/NPS.
 *  - `MlbEmpresa` — empresa de Polos/Onboarding. 535 de 539 não têm `Company`
 *                   (medido em 27/08/2026). Criar uma `Company` só para
 *                   guardar o token mexeria no pivô de bonificação — porta
 *                   fechada de propósito em
 *                   `.planning/learnings/painel-polos-status-e-meta.md` §3.
 *
 * Quem só precisa do token (o módulo de anúncios e os 4 métodos do
 * `MercadoLivreService` que ele usa) tipa por esta interface e passa a servir
 * as duas âncoras sem saber qual está na mão.
 */
interface ContaMercadoLivre
{
    /** Token OAuth desta conta, se conectada. */
    public function mlToken(): HasOne;

    /**
     * Chave única e estável da conta, já distinguindo a âncora.
     *
     * NUNCA usar `$model->id` cru em cache ou lock: `Company 5` e
     * `MlbEmpresa 5` colidiriam, e uma venceria o lock da outra — o refresh
     * token do ML é de uso único, então essa colisão mata uma das conexões.
     */
    public function chaveContaMl(): string;

    /** Nome legível, para tela e log. */
    public function nomeContaMl(): string;

    /** Coluna de `ml_tokens` que ancora esta entidade. */
    public function colunaAncoraMl(): string;
}
