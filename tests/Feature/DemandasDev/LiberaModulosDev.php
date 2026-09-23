<?php

namespace Tests\Feature\DemandasDev;

use App\Models\Module;

/**
 * Tickets e Demandas Dev nascem ocultos (só o Dev entra). Os testes de regra de
 * negócio rodam com os dois LIBERADOS, como ficarão depois do teste com o time
 * dev; a trava em si é coberta por ModulosOcultosTest.
 */
trait LiberaModulosDev
{
    protected function liberarModulosDev(): void
    {
        Module::factory()->create(['key' => 'chamados', 'route_prefix' => 'chamados.', 'visivel_para_todos' => true]);
        Module::factory()->create(['key' => 'dev.demandas', 'route_prefix' => 'dev.demandas.', 'visivel_para_todos' => true]);
    }
}
