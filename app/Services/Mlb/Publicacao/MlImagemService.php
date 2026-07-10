<?php

namespace App\Services\Mlb\Publicacao;

use App\Models\Company;
use App\Services\MercadoLivreService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Upload de imagens de anúncio para o Mercado Livre.
 *
 * Sobe o binário para /pictures/items/upload (com o token do vendedor) e devolve
 * o picture id retornado — que deve ser reutilizado nas variações (não subir a
 * mesma imagem várias vezes).
 */
class MlImagemService
{
    private const API_BASE = 'https://api.mercadolibre.com';

    public function __construct(private MercadoLivreService $ml) {}

    /**
     * Envia o binário de uma imagem e retorna o picture id do ML (ou null em falha).
     * Upload é multipart, então não passa pelo post() JSON do MercadoLivreService.
     */
    public function enviar(Company $company, string $conteudo, string $nome = 'imagem.jpg'): ?string
    {
        $token = $this->ml->ensureValidToken($company);

        if (! $token) {
            throw new \RuntimeException("[MLB Publicacao] Empresa {$company->id} sem token válido.");
        }

        $resp = Http::withToken($token->access_token)
            ->attach('file', $conteudo, $nome)
            ->post(self::API_BASE . '/pictures/items/upload');

        if (! $resp->successful()) {
            Log::warning("[MLB Publicacao] Falha no upload de imagem empresa {$company->id}: HTTP {$resp->status()}", [
                'body' => $resp->body(),
            ]);

            return null;
        }

        return $resp->json('id');
    }
}
