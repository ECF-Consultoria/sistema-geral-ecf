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
 * o picture id + a URL pública (secure_url) da imagem. O picture id deve ser
 * reutilizado nas variações (não subir a mesma imagem várias vezes); a URL serve
 * para a grade em massa preencher as colunas de Foto (que publicam por `source`).
 */
class MlImagemService
{
    private const API_BASE = 'https://api.mercadolibre.com';

    public function __construct(private MercadoLivreService $ml) {}

    /**
     * Envia o binário de uma imagem e retorna ['id' => picture_id, 'url' => secure_url]
     * (ou null em falha). A URL é a maior variação disponível (`variations[0]`), com
     * fallback para qualquer secure_url/url do payload. Upload é multipart, então não
     * passa pelo post() JSON do MercadoLivreService.
     *
     * @return array{id: string, url: ?string}|null
     */
    public function enviar(Company $company, string $conteudo, string $nome = 'imagem.jpg'): ?array
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

        $id = $resp->json('id');
        if ($id === null) {
            return null;
        }

        // A resposta traz `variations[]` (mesma imagem em vários tamanhos). A 1ª é a
        // maior; usa o secure_url dela. Fallbacks defensivos: 1º secure_url que houver,
        // depois url http. `null` é aceitável — o wizard só usa o picture_id.
        $variations = $resp->json('variations') ?? [];
        $url = $variations[0]['secure_url']
            ?? collect($variations)->pluck('secure_url')->filter()->first()
            ?? $variations[0]['url']
            ?? null;

        return ['id' => $id, 'url' => $url];
    }
}
