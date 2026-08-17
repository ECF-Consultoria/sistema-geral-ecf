<?php

namespace App\Support;

use App\Models\Setor;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Phase 130 Plano 02 (D-02) — Audiência da rede de segurança de contratos
 * (alerta de contrato preso + checagem de ausência de varredura).
 *
 * União de 2 fontes distintas (id-distinct):
 *   (a) Todo admin ATIVO (`role = 'admin'`).
 *   (b) Todo membro ATIVO do setor `comercial` — via `Setor::membros()`
 *       (pivot `user_setores`), TODOS os membros, não só líderes.
 *
 * Três pontos que já custaram caro descobrir e não são dedutíveis do código:
 *
 * 1. Por que esta classe existe em vez de reusar
 *    `AudienciaComercial::lideresEPermissionados()` (Fase 35): aquele helper
 *    usa `Setor::lideres()` (pivot `setor_lideres`, só líderes) + a
 *    permission `comercial.cadastrar_empresa` — e por isso DEIXA DE FORA um
 *    analista comercial comum, membro do setor mas sem liderança nem aquela
 *    permission específica. A D-02 desta fase exige `Setor::membros()`: todo
 *    membro ativo do setor, sem exceção. Reusar o helper antigo estreitaria
 *    a audiência silenciosamente.
 *
 * 2. O filtro `active = true` também se aplica aos ADMINS (divergência
 *    mínima do texto literal da D-02, deliberada): evita notificar uma
 *    conta administrativa desligada. Decisão consciente, não descuido.
 *
 * 3. `role = 'admin'` é a trava de hoje. Ela sai de cena quando a Fase 131
 *    criar a permission `admin.contratos` — nesse momento esta classe deve
 *    trocar o filtro por permission, não por role.
 */
class AudienciaRedeSeguranca
{
    /**
     * Retorna users ativos que devem receber a rede de segurança de
     * contratos: admins + membros do setor Comercial (D-02).
     *
     * @return Collection<int, User>
     */
    public static function adminsEComercial(): Collection
    {
        $admins = User::where('active', true)
            ->where('role', 'admin')
            ->get();

        $setorComercial = Setor::where('slug', 'comercial')->first();
        $comerciais = $setorComercial
            ? $setorComercial->membros()->where('active', true)->get()
            : collect();

        // União distinct por id, preserva ordem (admins primeiro).
        return $admins->concat($comerciais)->unique('id')->values();
    }
}
