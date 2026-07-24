<?php

namespace App\Console\Commands;

use App\Support\Modules;
use Illuminate\Console\Command;

/**
 * Guard anti-drift entre o catálogo estático `App\Support\Modules` e as
 * `moduleKey` referenciadas no `NAV_TREE` do frontend (Module Registry —
 * Fase 97, Plano 04).
 *
 * Nesta fase o NAV_TREE (resources/js/Layouts/AppLayout.jsx) ainda não decora
 * nenhum item com `moduleKey` — o comando passa trivialmente. A partir da
 * Fase 98, quando o NAV_TREE ganhar `moduleKey`, este comando impede que o
 * projeto repita o drift silencioso já existente entre `Permissions.php` e
 * `resources/js/lib/permissions.js`.
 *
 * Rodável em CI: sai com código ≠0 quando o NAV referencia uma `moduleKey`
 * fantasma (não catalogada) — isso quebraria a resolução de estágio.
 * `moduleKey` do catálogo sem item de menu no NAV é apenas um AVISO (não
 * bloqueia): nem todo módulo do catálogo precisa aparecer no menu (ex.:
 * `dev.metas`, arquivado).
 */
class ModulesCheck extends Command
{
    protected $signature = 'modules:check
        {--nav-path= : Caminho alternativo do arquivo NAV (para teste/fixture)}';

    protected $description = 'Verifica se toda moduleKey usada no NAV_TREE existe no catálogo App\\Support\\Modules (guard anti-drift, rodável em CI)';

    public function handle(): int
    {
        $navPath = $this->option('nav-path') ?: resource_path('js/Layouts/AppLayout.jsx');

        if (! is_readable($navPath)) {
            $this->error("[Modules] arquivo NAV não encontrado ou ilegível: {$navPath}");
            return self::FAILURE;
        }

        $conteudo = (string) file_get_contents($navPath);
        preg_match_all('/moduleKey:\s*[\'"]([^\'"]+)[\'"]/', $conteudo, $matches);
        $navKeys = array_values(array_unique($matches[1] ?? []));

        $catalogKeys = Modules::all();

        // ERRO DURO: moduleKey usada no NAV que não existe no catálogo — quebraria
        // a resolução de estágio (ModuleRegistry::stageFor faz fallback silencioso).
        $faltantes = array_values(array_diff($navKeys, $catalogKeys));

        // AVISO BRANDO: key do catálogo sem item de menu correspondente no NAV.
        $semItemDeMenu = array_values(array_diff($catalogKeys, $navKeys));

        if (! empty($faltantes)) {
            $this->error('[Modules] moduleKey(s) usada(s) no NAV_TREE sem entrada no catálogo App\\Support\\Modules:');
            foreach ($faltantes as $key) {
                $this->error("  - {$key}");
            }
            return self::FAILURE;
        }

        if (! empty($semItemDeMenu)) {
            $this->warn('[Modules] moduleKey(s) do catálogo sem item de menu no NAV_TREE (aviso, não bloqueia):');
            foreach ($semItemDeMenu as $key) {
                $this->warn("  - {$key}");
            }
        }

        $this->info('[Modules] catálogo e NAV_TREE em sincronia');
        return self::SUCCESS;
    }
}
