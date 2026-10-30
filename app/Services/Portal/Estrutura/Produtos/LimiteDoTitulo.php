<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Services\Publicador\CategorySchemaRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * O limite de caracteres do título de venda (19/10/2026): o `max_title_length` da categoria, lido do
 * mesmo schema que o editor do Publicador usa (guardado no banco; o catálogo só é consultado quando
 * a categoria ainda não foi lida).
 *
 * Quem grava confere com {@see conferir()}. Título de até {@see PADRAO} caracteres passa sem consultar
 * nada: é o limite da imensa maioria das categorias e nenhuma das lidas até hoje pede menos — assim o
 * Salvar e a planilha não esperam o catálogo à toa. Acima disso, vale o da categoria; sem categoria
 * escolhida, ou com o catálogo fora do ar e nada guardado, não há como saber, e o título passa (a tela
 * já segurou a digitação no limite que conhecia).
 */
class LimiteDoTitulo
{
    /** O limite de quando a categoria não diz o dela. */
    public const PADRAO = 60;

    public function __construct(private CategorySchemaRepository $schemas) {}

    /** O limite da categoria; nulo quando não dá para saber agora. */
    public function daCategoria(?string $categoria): ?int
    {
        $categoria = trim((string) $categoria);
        if ($categoria === '') {
            return null;
        }

        try {
            $maximo = (int) ($this->schemas->obter($categoria)->settings()['max_title_length'] ?? 0);
        } catch (\Throwable $e) {
            Log::warning('[Estrutura Produtos] limite do título da categoria indisponível', ['categoria' => $categoria, 'erro' => $e->getMessage()]);

            return null;
        }

        return $maximo > 0 ? $maximo : self::PADRAO;
    }

    /** Recusa o título acima do limite da categoria (ValidationException no campo `titulo`). */
    public function conferir(?string $titulo, ?string $categoria): void
    {
        $tamanho = mb_strlen((string) $titulo);
        if ($tamanho <= self::PADRAO) {
            return;
        }
        $limite = $this->daCategoria($categoria);
        if ($limite !== null && $tamanho > $limite) {
            throw ValidationException::withMessages([
                'titulo' => "O título tem {$tamanho} caracteres, e o título para anunciar vai até {$limite}. Encurte o título.",
            ]);
        }
    }
}
