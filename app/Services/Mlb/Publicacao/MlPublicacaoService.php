<?php

namespace App\Services\Mlb\Publicacao;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Services\Mlb\Publicacao\Builders\ClassicItemBuilder;
use App\Services\Mlb\Publicacao\Builders\ItemBuilder;
use App\Services\Mlb\Publicacao\Builders\UserProductItemBuilder;
use App\Services\MercadoLivreService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Orquestra a publicação de anúncios no Mercado Livre a partir de um rascunho.
 *
 * Responsabilidades:
 *   - detectar o modelo da conta (Clássico vs User Products) e escolher o builder;
 *   - validar o rascunho no ML (/items/validate, dry-run) traduzindo erros p/ pt-BR;
 *   - publicar de fato (POST /items + descrição), atualizando o ciclo de status.
 *
 * A API do ML só é tocada aqui — o resto do sistema trabalha sobre o rascunho.
 */
class MlPublicacaoService
{
    private const API_BASE = 'https://api.mercadolibre.com';

    public function __construct(
        private MercadoLivreService $ml,
        private MlItemPayloadValidator $validator,
        private MlCompatibilidadeService $compat,
    ) {}

    /**
     * Detecta o modelo de publicação da conta ('classic' | 'user_product') pela
     * tag `user_product_seller` em GET /users/{id}. Cacheado 24h; fallback seguro.
     */
    public function detectarModelo(Company $company): string
    {
        return Cache::remember("ml_modelo_conta_{$company->id}", 86400, function () use ($company) {
            try {
                $u    = $this->ml->fetchUserInfo($company);
                $tags = $u['tags'] ?? [];

                return in_array('user_product_seller', $tags, true) ? 'user_product' : 'classic';
            } catch (\Throwable $e) {
                Log::warning("[MLB Publicacao] Falha ao detectar modelo da empresa {$company->id}: {$e->getMessage()}");

                return 'classic'; // fallback seguro (modelo mais comum)
            }
        });
    }

    /** Escolhe o builder correto para a conta. */
    public function builderPara(Company $company): ItemBuilder
    {
        return $this->detectarModelo($company) === 'user_product'
            ? new UserProductItemBuilder()
            : new ClassicItemBuilder();
    }

    /**
     * Valida o rascunho no ML sem criar nada (/items/validate). Atualiza o
     * rascunho com os erros traduzidos e o status (validado | rascunho).
     *
     * @return array{valido: bool, erros: array}
     */
    public function validar(MlAnuncioRascunho $rascunho): array
    {
        $company = $rascunho->company;
        $payload = $this->builderPara($company)->montar($rascunho->payload ?? []);

        $token = $this->ml->ensureValidToken($company);
        if (! $token) {
            throw new \RuntimeException("[MLB Publicacao] Empresa {$company->id} sem token válido.");
        }

        // /items/validate: 204 = válido; 400 = erros (que queremos ver, não lançar).
        $resp   = Http::withToken($token->access_token)->post(self::API_BASE . '/items/validate', $payload);
        $valido = $resp->status() === 204;
        $causas = $valido ? [] : (array) ($resp->json('cause') ?? $resp->json() ?? []);
        $erros  = $this->validator->traduzir($causas);

        $rascunho->update([
            'validation_errors' => $erros ?: null,
            'status'            => $valido
                ? MlAnuncioRascunho::STATUS_VALIDADO
                : MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        return ['valido' => $valido, 'erros' => $erros];
    }

    /**
     * Publica o rascunho de fato: POST /items + descrição. Idempotente (não
     * republica um rascunho já publicado). Em falha, marca status=erro e relança.
     */
    public function publicar(MlAnuncioRascunho $rascunho): MlAnuncioRascunho
    {
        // Já publicado → não recria (proteção anti-duplicidade).
        if ($rascunho->status === MlAnuncioRascunho::STATUS_PUBLICADO && $rascunho->ml_item_id) {
            return $rascunho;
        }

        $company = $rascunho->company;
        $rascunho->update(['status' => MlAnuncioRascunho::STATUS_PUBLICANDO]);

        try {
            $payload = $this->builderPara($company)->montar($rascunho->payload ?? []);

            $item   = $this->ml->post($company, '/items', $payload);
            $itemId = $item['id'] ?? null;
            if (! $itemId) {
                throw new \RuntimeException('[MLB Publicacao] POST /items não retornou id.');
            }

            // Descrição (texto plano), quando houver.
            $descricao = $rascunho->payload['description'] ?? null;
            if (is_string($descricao) && trim($descricao) !== '') {
                $this->ml->post($company, "/items/{$itemId}/description", ['plain_text' => $descricao]);
            }

            // Compatibilidades de autopeças — recurso separado, aplicado APÓS criar o
            // item (POST /items/{id}/compatibilities). Best-effort: aplicar() engole a
            // falha (o item já existe; a ausência vira tag incomplete_compatibilities no
            // ML). NÃO aborta a publicação. Categorias que não são autopeças não têm
            // 'compatibilidades' no payload, então isto é um no-op para elas.
            $veiculos = $rascunho->payload['compatibilidades'] ?? null;
            if (is_array($veiculos) && ! empty($veiculos)) {
                $this->compat->aplicar($company, $itemId, $veiculos);
            }

            // DUP-04: gravar ml_item_id no campo do tier correto.
            // ml_item_id legado também é preenchido para manter retrocompatibilidade
            // com código que ainda lê $rascunho->ml_item_id (controller e wizard).
            // A falha de um tier NÃO chega aqui — o catch abaixo não toca ml_item_id_*,
            // preservando o campo do tier que publicou com sucesso (Phase 79).
            $campoTier = $rascunho->listing_tier === 'premium'
                ? 'ml_item_id_premium'
                : 'ml_item_id_classico'; // fallback para 'classico' quando listing_tier é null (rascunho legado)

            $rascunho->update([
                'status'            => MlAnuncioRascunho::STATUS_PUBLICADO,
                'ml_item_id'        => $itemId,  // retrocompatibilidade (legado)
                $campoTier          => $itemId,  // DUP-04: campo específico do tier
                'published_at'      => now(),
                'validation_errors' => null,
            ]);

            Log::info("[MLB Publicacao] Anúncio {$itemId} publicado (empresa {$company->id}, rascunho {$rascunho->id}, tier {$campoTier})");
        } catch (\Throwable $e) {
            $rascunho->update([
                'status'            => MlAnuncioRascunho::STATUS_ERRO,
                'validation_errors' => [['code' => 'publish_failed', 'campo' => null, 'mensagem' => $e->getMessage()]],
            ]);

            Log::error("[MLB Publicacao] Falha ao publicar rascunho {$rascunho->id}: {$e->getMessage()}");
            throw $e;
        }

        return $rascunho->fresh();
    }
}
