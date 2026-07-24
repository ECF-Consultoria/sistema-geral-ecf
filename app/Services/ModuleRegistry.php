<?php

namespace App\Services;

use App\Models\Module;
use Illuminate\Support\Facades\Cache;

/**
 * Resolve o mapa `key => stage` da tabela `modules` (Module Registry —
 * Fase 97), cacheado (store default `database`) para consumo O(1) pelo
 * middleware `EnsureModuleStage` (Fase 99) e pelos shared props Inertia
 * (Fase 98). NÃO registra middleware, NÃO toca rotas, NÃO toca shared
 * props — fora do escopo desta fase.
 *
 * A fonte é a tabela `modules` (estado MUTÁVEL em runtime, hidratada a
 * partir do catálogo estático `App\Support\Modules` pelo comando
 * `modules:sync`, Plano 04) — não o catálogo em si.
 *
 * Default `producao` para `moduleKey` ausente do mapa é uma decisão de
 * segurança CONSCIENTE (fail-open, T-97-06 aceito no threat model): um
 * módulo NÃO catalogado fica visível, o que é aceitável porque o gate real
 * de acesso (middleware `EnsureModuleStage`, Fase 99) é aplicado
 * explicitamente por grupo de rota — não depende deste fallback para
 * segurança. Garante zero regressão para qualquer item de menu sem
 * `moduleKey`.
 *
 * Invalidação: `Module::booted()` chama `flush()` em `saved`/`deleted`/
 * `restored`, então o TTL abaixo é só rede de segurança (não a fonte
 * primária de frescor do cache).
 *
 * Tag de log, se necessário: `[Modules]`.
 */
class ModuleRegistry
{
    /** Chave de cache do mapa key=>stage — a mesma invalidada por `Module::booted()`. */
    public const CACHE_KEY = 'modules.stage_map';

    /** Chave de cache das rotas ocultas (MVP Cargo Dev) — invalidada junto do mapa. */
    public const CACHE_KEY_HIDDEN = 'modules.hidden_routes';

    /** TTL de rede de segurança (1h) — invalidação real é por evento via `flush()`. */
    private const CACHE_TTL = 3600;

    /**
     * Mapa completo `key => stage` de todos os módulos cadastrados.
     *
     * @return array<string, string>
     */
    public function map(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => Module::query()->pluck('stage', 'key')->all()
        );
    }

    /**
     * Estágio atual da moduleKey. Default seguro `producao` quando a key
     * não está na tabela (fail-open — ver docblock da classe).
     */
    public function stageFor(string $key): string
    {
        return $this->map()[$key] ?? 'producao';
    }

    /**
     * Alias de `map()` — mapa completo key=>stage para consumo dos shared
     * props Inertia (Fase 98).
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->map();
    }

    /**
     * Rotas (route_prefix) dos módulos marcados como OCULTOS (`visivel_para_todos = false`).
     * Consumido pelos shared props Inertia (`auth.modulos_ocultos`) para o gate de
     * menu do MVP Cargo Dev: o Admin Dev (`isAdminDev()`) ignora esta lista e vê
     * tudo; os demais papéis têm os itens de menu cujo `routeName` casa com um
     * destes prefixos escondidos.
     *
     * Só entram módulos com `route_prefix` não-nulo (os que mapeiam para um item
     * de menu real). Um `route_prefix` terminado em ponto (ex.: `admin.`) é tratado
     * como PREFIXO no frontend — esconde todos os itens sob aquele namespace.
     *
     * @return array<int, string>
     */
    public function hiddenRoutes(): array
    {
        return Cache::remember(
            self::CACHE_KEY_HIDDEN,
            self::CACHE_TTL,
            fn () => Module::query()
                ->where('visivel_para_todos', false)
                ->whereNotNull('route_prefix')
                ->pluck('route_prefix')
                ->values()
                ->all()
        );
    }

    /** Invalida os mapas cacheados — chamado por `Module::booted()` em toda mutação. */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY_HIDDEN);
    }
}
