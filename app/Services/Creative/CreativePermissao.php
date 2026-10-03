<?php

namespace App\Services\Creative;

use App\Models\Configuracao;
use App\Models\User;
use App\Support\Permissions;

/**
 * Permissão explícita para planejar/gerar/regenerar/aprovar criativos de
 * imagem por IA (OPS-04) — consultada por TODOS os endpoints que gastam
 * cota ou aprovam.
 *
 * DUAS camadas obrigatórias, porque `User::hasPermission()` CURTO-CIRCUITA
 * `true` para qualquer admin (ver `User::isAdmin()`/`hasPermission()`) e o
 * grupo de rotas (`routes/mlb_anuncios.php`) é `role:admin` — uma chave de
 * permissão sozinha não provaria OPS-04 hoje, porque todo admin já passaria
 * por ela de qualquer forma:
 *
 *   (a) `hasPermission(Permissions::MLB_CRIATIVOS_IA)` — a camada que passa
 *       a valer SOZINHA no dia em que o grupo de rotas trocar `role:admin`
 *       por `permission:mlb.anunciar` (hipótese já escrita no cabeçalho de
 *       `routes/mlb_anuncios.php`);
 *   (b) a lista `creative_engine_usuarios` em `configuracoes` (ids
 *       separados por vírgula). AUSENTE ou VAZIA = sem restrição extra
 *       (comportamento de hoje — produção não quebra); PREENCHIDA = só os
 *       ids da lista gastam cota, admin incluído. É esta camada que torna
 *       OPS-04 provável por teste (um admin FORA da lista é barrado) e
 *       operável sem deploy — mesmo tipo de chave de `CreativeEngineAtivo`
 *       (OPS-03).
 *
 * Os quatro métodos (`podePlanejar`/`podeGerar`/`podeRegenerar`/`podeAprovar`)
 * aplicam hoje a MESMA regra, separados porque é a fronteira que o REQ
 * (OPS-04) nomeia por ação — amanhã podem divergir (ex.: aprovar exigir uma
 * permissão mais restrita) sem mexer em nenhum call site.
 */
class CreativePermissao
{
    /** Chave em `configuracoes` — lista de ids de usuário (CSV) autorizados a gastar cota. */
    public const CHAVE_LISTA = 'creative_engine_usuarios';

    public function podePlanejar(User $user): bool
    {
        return $this->autorizado($user);
    }

    public function podeGerar(User $user): bool
    {
        return $this->autorizado($user);
    }

    public function podeRegenerar(User $user): bool
    {
        return $this->autorizado($user);
    }

    public function podeAprovar(User $user): bool
    {
        return $this->autorizado($user);
    }

    /** `abort(403, ...)` em pt-BR quando a ação não é permitida. */
    public function exigir(User $user, string $acao): void
    {
        $metodo = 'pode' . ucfirst($acao);

        $permitido = method_exists($this, $metodo)
            ? $this->{$metodo}($user)
            : $this->autorizado($user);

        abort_unless($permitido, 403, 'Você não tem permissão para ' . $this->rotuloAcao($acao) . ' criativos por IA.');
    }

    private function rotuloAcao(string $acao): string
    {
        return match ($acao) {
            'planejar'  => 'planejar',
            'gerar'     => 'gerar',
            'regenerar' => 'regenerar',
            'aprovar'   => 'aprovar',
            default     => $acao,
        };
    }

    /**
     * Camada (a) E camada (b) — ver docblock da classe. Ausência de registro
     * na lista é "sem restrição", NUNCA "ninguém pode" (inverter isso
     * travaria produção no dia em que a chave de permissão for concedida a
     * alguém sem a lista ter sido tocada).
     */
    private function autorizado(User $user): bool
    {
        if (! $user->hasPermission(Permissions::MLB_CRIATIVOS_IA)) {
            return false;
        }

        $idsPermitidos = $this->listaDeIdsPermitidos();

        if ($idsPermitidos === []) {
            return true;
        }

        return in_array($user->id, $idsPermitidos, true);
    }

    /**
     * @return array<int, int> ids numéricos; entradas não-numéricas são descartadas.
     */
    private function listaDeIdsPermitidos(): array
    {
        $csv = (string) Configuracao::get(self::CHAVE_LISTA, '');

        if (trim($csv) === '') {
            return [];
        }

        return collect(explode(',', $csv))
            ->map(fn ($id) => trim($id))
            ->filter(fn ($id) => $id !== '' && ctype_digit($id))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
