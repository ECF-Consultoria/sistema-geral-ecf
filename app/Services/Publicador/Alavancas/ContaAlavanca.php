<?php

namespace App\Services\Publicador\Alavancas;

use App\Contracts\ContaMercadoLivre;
use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Support\Publicador\AlavancasLiberadas;

/**
 * A conta do ML em que as Alavancas leem e escrevem — a âncora COM TOKEN
 * (D-01: não depende de Company). `chaveTela` é a chave da URL da tela
 * (`company-N` ou `empresa-N`); `chaveConta()` é a da conta do token.
 */
final readonly class ContaAlavanca
{
    public function __construct(
        public ContaMercadoLivre $conta,
        public string $sellerId,
        public ?MlbEmpresa $mlbEmpresa,
        public ?Company $company,
        public string $chaveTela,
        public string $nome,
    ) {}

    public function chaveConta(): string
    {
        return $this->conta->chaveContaMl();
    }

    public function liberada(): bool
    {
        return AlavancasLiberadas::libera($this->conta);
    }
}
