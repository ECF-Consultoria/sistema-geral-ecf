<?php

namespace App\Services\Creative;

use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use Illuminate\Support\Facades\Log;

/**
 * Detecta se a categoria do anúncio é de MÓVEIS (quick 261007-amb) — decide
 * se o primeiro slot do kit é a AMBIENTAÇÃO em vez do `hero` de fundo
 * branco. Pedido do usuário: *"Para anúncios que a categoria forem
 * relacionados a móveis (mesa, cadeira, cômoda, sofá, poltrona e etc) usa
 * foto ambientada."*
 *
 * `GET /categories/{id}` NÃO expõe nenhuma flag "é móvel" — `settings` só
 * traz `max_pictures_per_item`/`max_pictures_per_item_var`. A única pista
 * disponível é o CAMINHO da categoria (`path_from_root`): medido contra a
 * API real em 2026-10-07, toda categoria de móvel (mesa de centro, sofá,
 * cadeira de escritório, cômoda, poltrona) passa por um nó com a palavra
 * "Móveis" (ex.: "Móveis para Casa") ABAIXO da raiz.
 *
 * ⚠️ A RAIZ "Casa, Móveis e Decoração" TAMBÉM contém a palavra "Móveis" e
 * cobre cozinha e decoração — por isso `path_from_root[0]` (a raiz) é
 * SEMPRE descartado da busca. Sem isso, panela de pressão (`…> Cozinha
 * >…`) e quadro decorativo (`…> Enfeites e Decoração da Casa >…`) virariam
 * "móvel" incorretamente — os dois casos foram medidos e confirmados como
 * NÃO-móveis contra a API real antes de escrever esta classe.
 *
 * Reaproveita o MESMO cache de `MlCatalogoMetaService::categoria()`
 * (`ml_meta_categoria_{id}`, 7 dias, já aquecido pelo wizard/incubadora) —
 * nunca dispara uma chamada nova à API por geração de criativo.
 *
 * Degrada graciosamente: categoria ausente/inválida, API fora do ar ou
 * app token indisponível resolvem para `false` — nunca lança, nunca
 * derruba o planejamento do kit. O resultado é a capa de sempre: `hero`
 * de fundo branco.
 */
class CreativeCategoriaMobiliarioService
{
    /**
     * Padrão fechado: a palavra "moveis" (já sem acento), isolada por
     * limite de palavra — nunca substring livre (evitaria falso-positivo
     * em nome composto que só contenha o radical por coincidência).
     */
    private const PADRAO_MOVEIS = '/\bmoveis\b/u';

    public function __construct(private MlCatalogoMetaService $meta) {}

    public function ehMoveis(?string $categoriaId): bool
    {
        $categoriaId = trim((string) $categoriaId);
        if ($categoriaId === '') {
            return false;
        }

        try {
            $categoria = $this->meta->categoria($categoriaId);
        } catch (\Throwable $e) {
            // Mesma disciplina de MlCatalogoMetaService: nunca derruba o
            // chamador por uma falha de rede/token — a capa volta a ser o
            // hero de fundo branco, o comportamento de sempre.
            Log::warning("[Creative] Falha ao consultar categoria {$categoriaId} para detectar móveis, assumindo não-móvel: " . $e->getMessage());

            return false;
        }

        $caminho = (array) ($categoria['path_from_root'] ?? []);

        // Descarta a RAIZ (índice 0) — ver docblock da classe.
        foreach (array_slice($caminho, 1) as $nivel) {
            $nome = $this->normalizar((string) ($nivel['name'] ?? ''));

            if (preg_match(self::PADRAO_MOVEIS, $nome) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Minúsculas e sem acento — só o suficiente para casar "móveis"/"Móveis" com o padrão. */
    private function normalizar(string $texto): string
    {
        $minusculo = mb_strtolower($texto, 'UTF-8');

        return strtr($minusculo, [
            'á' => 'a', 'â' => 'a', 'ã' => 'a', 'à' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e',
            'í' => 'i', 'î' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o',
            'ú' => 'u', 'û' => 'u',
            'ç' => 'c',
        ]);
    }
}
