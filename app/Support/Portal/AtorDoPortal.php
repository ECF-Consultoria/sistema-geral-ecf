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
 *
 * ### O terceiro nascimento (Fase 184, BLING-21)
 * Uma varredura agendada (Bling, e no futuro outras integrações) não é nem
 * cliente nem equipe: não existe humano por trás do clique. Reaproveitar
 * `::daEquipe()` com um usuário qualquer falsificaria o histórico — exatamente
 * o que esta classe existe para evitar. `::sistema()` é esse terceiro
 * nascimento: nasce com `modelo = null` (não há model para `causedBy()`) e
 * `origem` própria (`ORIGEM_SISTEMA_BLING`). `modelo` é nulo **só** neste
 * nascimento — código que faz `$ator->modelo->getKey()` sem checar nulo
 * continua seguro enquanto não receber um ator de sistema; `RegistroEstrutura`
 * já guarda essa checagem.
 */
final class AtorDoPortal
{
    public const ORIGEM_CLIENTE       = 'cliente';
    public const ORIGEM_INTERNO       = 'interno';
    public const ORIGEM_SISTEMA_BLING = 'bling';

    private function __construct(
        public readonly string $nome,
        public readonly ?string $email,
        public readonly bool $equipe,
        public readonly string $origem,
        /** O modelo por trás — PortalUsuario ou User. Nulo só no nascimento `::sistema()`. */
        public readonly ?object $modelo = null,
    ) {
    }

    public static function cliente(PortalUsuario $usuario): self
    {
        return new self($usuario->nome, $usuario->email, false, self::ORIGEM_CLIENTE, $usuario);
    }

    public static function daEquipe(User $membro): self
    {
        return new self($membro->name, $membro->email, true, self::ORIGEM_INTERNO, $membro);
    }

    /** Varredura agendada (Bling): sem causer, sem humano por trás. */
    public static function sistema(string $origem = self::ORIGEM_SISTEMA_BLING, string $nome = 'Bling (ERP)'): self
    {
        return new self($nome, null, false, $origem, null);
    }

    /** Como a ação aparece no histórico. */
    public function descricao(): string
    {
        if ($this->modelo === null) {
            return "{$this->nome} (automático)";
        }

        return $this->equipe
            ? "{$this->nome} (equipe ECF)"
            : "{$this->nome} ({$this->email})";
    }
}
