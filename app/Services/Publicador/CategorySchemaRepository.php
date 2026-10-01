<?php

namespace App\Services\Publicador;

use App\Models\MlCategoriaSchema;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Schema\CategorySchema;
use Illuminate\Support\Facades\Log;

/**
 * O schema de uma categoria (`03` §2): as 4 respostas do ML, com app token
 * (dado público), guardadas em `ml_categoria_schemas` por 24h (RN-22).
 *
 * Só grava quando as 4 vieram certas: o cache antigo (`MlCatalogoMetaService`)
 * guardava `[]` de uma falha por 7 dias (`16` §3, V13). Se o ML falhar e
 * houver um schema guardado, usa o guardado (vencido) e registra; sem nenhum,
 * diz que a categoria não pôde ser lida.
 */
class CategorySchemaRepository
{
    public function __construct(private ClienteMlPublicador $cliente) {}

    public function obter(string $categoriaId, bool $forcar = false): CategorySchema
    {
        $guardado = MlCategoriaSchema::find($categoriaId);
        if ($guardado && ! $forcar && $guardado->fetched_at->gt(now()->subHours($this->ttl()))) {
            return $guardado->paraSchema();
        }

        $fontes = [
            'categoria' => $this->cliente->publico("/categories/{$categoriaId}"),
            'atributos' => $this->cliente->publico("/categories/{$categoriaId}/attributes"),
            'technical_specs' => $this->cliente->publico("/categories/{$categoriaId}/technical_specs/input"),
            'sale_terms' => $this->cliente->publico("/categories/{$categoriaId}/sale_terms"),
        ];

        $falhou = array_keys(array_filter($fontes, fn ($r) => ! $r->ok() || ! is_array($r->corpo)));
        if ($falhou) {
            Log::warning("[Publicador] schema de {$categoriaId} não lido (".implode(', ', $falhou).')'.($guardado ? ' — usando o guardado' : ''));
            if ($guardado) {
                return $guardado->paraSchema();
            }
            throw new RegraViolada('V-CAT-03', 'Não foi possível ler esta categoria no Mercado Livre agora. Tente de novo em instantes.');
        }

        $schema = CategorySchema::dasFontes($categoriaId, $fontes['categoria']->corpo, $fontes['atributos']->corpo, $fontes['technical_specs']->corpo, $fontes['sale_terms']->corpo);
        MlCategoriaSchema::updateOrCreate(['category_id' => $categoriaId], [
            'domain_id' => $schema->dominio(),
            'categoria' => $schema->categoria,
            'atributos' => $schema->atributos,
            'technical_specs' => $schema->technicalSpecs,
            'sale_terms' => $schema->saleTerms,
            'schema_hash' => $schema->hash(),
            'fetched_at' => now(),
        ]);

        return $schema;
    }

    /**
     * Antes de publicar (`02` E13 passo 3, TC-75): schema vencido é rebuscado;
     * se o hash mudou desde o rascunho, a validação tem de rodar de novo.
     *
     * @return array{schema: CategorySchema, mudou: bool}
     */
    public function revalidar(string $categoriaId, ?string $hashDoRascunho): array
    {
        $schema = $this->obter($categoriaId);

        return ['schema' => $schema, 'mudou' => $hashDoRascunho !== null && $hashDoRascunho !== $schema->hash()];
    }

    private function ttl(): int
    {
        return (int) config('publicador.schema_ttl_horas', 24);
    }
}
