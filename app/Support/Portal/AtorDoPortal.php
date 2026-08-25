<?php

namespace App\Support\Portal;

use App\Models\PortalUsuario;
use App\Models\User;

/**
 * Quem está usando o portal agora — o cliente, ou alguém da ECF.
 *
 * Existe porque o portal passou a ter dois tipos de gente dentro dele, e quase
 * tudo o que a tela faz precisa da MESMA informação dos dois (um nome para
 * mostrar, um registro para gravar). Sem esta abstração, cada controller
 * repetiria o mesmo `if` — e o dia em que um deles esquecesse, a ação de um
 * analista entraria no histórico como ação do cliente.
 *
 * O campo `equipe` não é decoração: é ele que a tela usa para desenhar a faixa
 * de aviso, e é ele que separa "o cliente confirmou" de "nós confirmamos por
 * ele" no histórico.
 */
final class AtorDoPortal
{
    private function __construct(
        public readonly string $nome,
        public readonly ?string $email,
        public readonly bool $equipe,
        /** O modelo por trás — PortalUsuario ou User. Para `causedBy()`. */
        public readonly object $modelo,
    ) {
    }

    public static function cliente(PortalUsuario $usuario): self
    {
        return new self($usuario->nome, $usuario->email, false, $usuario);
    }

    public static function daEquipe(User $membro): self
    {
        return new self($membro->name, $membro->email, true, $membro);
    }

    /** Como a ação aparece no histórico. */
    public function descricao(): string
    {
        return $this->equipe
            ? "{$this->nome} (equipe ECF)"
            : "{$this->nome} ({$this->email})";
    }
}
