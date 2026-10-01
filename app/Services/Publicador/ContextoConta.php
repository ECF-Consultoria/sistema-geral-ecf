<?php

namespace App\Services\Publicador;

/**
 * O que a conta do ML é AGORA (`02` E0): modelo de publicação, tags, modos de
 * envio e, nas contas multidepósito, os depósitos (D11). Lido de novo no
 * começo do rascunho e logo antes de publicar (RN-02) — e gravado como
 * snapshot na publicação.
 */
final class ContextoConta
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>|null  $modosEnvio  nulo = não deu para ler (não se cobra)
     * @param  list<array{store_id: string, nome: string, network_node_id: ?string}>|null  $depositos  nulo = conta sem depósitos, ou leitura falhou
     */
    public function __construct(
        public readonly string $sellerId,
        public readonly string $modelo,
        public readonly array $tags,
        public readonly ?array $modosEnvio,
        public readonly ?array $depositos,
        public readonly string $lidaEm,
    ) {}

    public function multiDeposito(): bool
    {
        return in_array('warehouse_management', $this->tags, true);
    }

    public function paraSnapshot(): array
    {
        return get_object_vars($this);
    }
}
