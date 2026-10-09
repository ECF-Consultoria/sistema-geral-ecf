<?php

namespace App\Jobs\Publicador;

use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Publicador\ExplicacaoDeAtributos;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A IA escreve, UMA vez por atributo, a explicação do ícone de informação dos campos que não têm
 * texto no glossário nem no ML (`ExplicacaoDeAtributos`). Uma chamada para até 40 atributos, JSON
 * `{ "ATTR_ID": "explicação" }`; o que não passa na regra (tamanho, HTML, citar plataforma) é
 * descartado e o atributo segue com o texto montado até a trava vencer.
 *
 * Fila `default`: ninguém espera por isto na tela (a explicação aparece na próxima abertura).
 * Sem nova tentativa, como os irmãos: o serviço da IA já troca de modelo quando o principal falha;
 * prazo abaixo do `timeout` para terminar com log antes de o worker matar o processo.
 */
class GerarExplicacoesDeAtributosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    private const PRAZO_S = 240;

    /**
     * @param  list<array{id: string, nome: string, tipo?: string, unidades?: list<string>, valores?: list<string>}>  $atributos
     */
    public function __construct(
        public array $atributos,
        public ?string $contexto = null,
    ) {
        // Não é clique de pessoa: fila `default`. No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('default');
    }

    public function handle(AnaliseAnuncioService $ia, ExplicacaoDeAtributos $explicacoes): void
    {
        if ($this->atributos === []) {
            return;
        }

        try {
            $r = $ia->comPrazo(microtime(true) + self::PRAZO_S)->explicacoesDeAtributos($this->atributos, (string) $this->contexto);
            $aceitos = $explicacoes->guardarDaIa((array) $r['dados'], $this->atributos, $r['meta']['modelo'] ?? null);
            Log::info('[Publicador] IA de explicações: '.$aceitos.' de '.count($this->atributos).' atributos guardados.');
        } catch (\Throwable $e) {
            // A trava por atributo segura uma nova tentativa até vencer; a tela segue com o texto montado.
            Log::warning('[Publicador] IA de explicações falhou ('.count($this->atributos).' atributos): '.$e->getMessage());
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[Publicador] IA de explicações quebrou ('.count($this->atributos).' atributos): '.$e->getMessage());
    }
}
