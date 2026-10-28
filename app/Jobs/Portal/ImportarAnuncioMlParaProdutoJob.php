<?php

namespace App\Jobs\Portal;

use App\Models\Company;
use App\Models\PortalUsuario;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\ImportadorDeAnuncioMl;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\ProdutosDoMercadoLivreService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Importa UM anúncio do Mercado Livre como Produto do Portal (28/10/2026, "Importar do
 * Mercado Livre"). Fila `high`: quem clicou está olhando a tela (o mesmo motivo do
 * `ImportarAnunciosMlEstruturaJob` — a `default` de produção carrega o sync do acervo).
 *
 * Sem nova tentativa: um anúncio pela metade (produto criado, fotos não) não pode ser refeito por
 * cima — a pessoa vê o erro e decide. A trava por anúncio impede dois Jobs do mesmo MLB juntos.
 */
class ImportarAnuncioMlParaProdutoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** 12 fotos por variação × algumas variações, baixadas uma a uma (20 s de teto cada). */
    public int $timeout = 600;

    /** @param  array{equipe: bool, id: int|string|null, nome: string}  $quem */
    public function __construct(public int $companyId, public string $mlb, public array $quem)
    {
        $this->onQueue('high');
    }

    public function handle(ImportadorDeAnuncioMl $importador, ProdutosDoMercadoLivreService $estado): void
    {
        $empresa = Company::find($this->companyId);
        if (! $empresa) {
            return;
        }

        $trava = Cache::lock("estrutura:produtos-ml:importando:{$empresa->id}:{$this->mlb}", $this->timeout);
        if (! $trava->get()) {
            return;
        }

        try {
            $estado->marcar($empresa, $this->mlb, ['situacao' => ProdutosDoMercadoLivreService::IMPORTANDO, 'mensagem' => 'Lendo o anúncio']);

            $r = $importador->importar($empresa, $this->mlb, $this->ator(),
                fn (string $passo) => $estado->marcar($empresa, $this->mlb, ['mensagem' => $passo]));

            $estado->marcar($empresa, $this->mlb, $r);
            Log::info("[Estrutura Produtos ML] anúncio {$this->mlb} → {$r['situacao']} (produto ".($r['produto_id'] ?? '—').") — empresa {$empresa->id} ({$empresa->name})");
        } catch (\Throwable $e) {
            $estado->marcar($empresa, $this->mlb, ['situacao' => ProdutosDoMercadoLivreService::ERRO, 'mensagem' => 'Não foi possível importar este anúncio agora.']);

            throw $e;
        } finally {
            $trava->release();
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Estrutura Produtos ML] importação do anúncio {$this->mlb} falhou — empresa {$this->companyId}: {$e->getMessage()}");
    }

    /** Quem clicou, de volta ao `AtorDoPortal` (o histórico registra a pessoa, não "o sistema"). */
    private function ator(): AtorDoPortal
    {
        $id = $this->quem['id'] ?? null;
        if ($this->quem['equipe'] ?? false) {
            $membro = $id ? User::find($id) : null;
            if ($membro) {
                return AtorDoPortal::daEquipe($membro);
            }
        } else {
            $usuario = $id ? PortalUsuario::find($id) : null;
            if ($usuario) {
                return AtorDoPortal::cliente($usuario);
            }
        }

        return AtorDoPortal::sistema('portal', (string) ($this->quem['nome'] ?? 'Portal'));
    }
}
