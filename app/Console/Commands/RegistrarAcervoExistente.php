<?php

namespace App\Console\Commands;

use App\Models\AcervoImagem;
use App\Models\MlAnuncioCriativo;
use App\Models\PubImagem;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Backfill SÓ DE BANCO do acervo de imagens (v28.0, ATV-04): registra como ativos as fotos que já
 * existiam antes da Fase 197. Não move, não copia e não apaga arquivo nenhum — o ativo aponta para o
 * caminho atual (`publicador/{rascunho}/{sha}` ou `creative-geradas/{token}/…`) no disco onde ele está.
 *
 * - `pub_imagens` com arquivo e sem ativo → ativo da conta do produto (mesmo sha na mesma conta =
 *   um ativo só; as cópias físicas redundantes ficam contadas para a migração, Fase 200).
 *   Origem `ia` quando a foto veio de um criativo aprovado (`ml_anuncio_criativos.pub_imagem_id`).
 * - Criativos APROVADOS do assistente antigo (sem `pub_imagem_id`) → ativo `ia` (D3: só aprovada entra).
 *
 * Idempotente: rodar de novo só pega o que ainda não tem ativo. `--dry-run` não grava nada.
 */
class RegistrarAcervoExistente extends Command
{
    protected $signature = 'acervo:registrar-existentes
        {--dry-run : Só conta o que seria registrado, sem gravar}';

    protected $description = 'Backfill só de banco: registra no acervo de imagens as fotos e criativos aprovados já existentes (ATV-04)';

    /** @var array<string, int> */
    private array $placar = [
        'fotos_ligadas_a_ativo_existente' => 0,
        'ativos_criados_de_fotos' => 0,
        'ativos_criados_de_criativos_antigos' => 0,
        'copias_fisicas_redundantes' => 0,
        'sem_arquivo' => 0,
        'sem_dono' => 0,
    ];

    /** Ativos "criados" no dry-run, para contar a dedup sem gravar. @var array<string, true> */
    private array $simulados = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        // O Artisan reaproveita a instância do comando entre chamadas no mesmo processo: o estado
        // de uma rodada (sobretudo os "simulados" do dry-run) não pode vazar para a seguinte.
        $this->placar = array_fill_keys(array_keys($this->placar), 0);
        $this->simulados = [];

        PubImagem::query()
            ->whereNull('acervo_imagem_id')->whereNotNull('caminho')->whereNotNull('sha256')
            ->with('rascunho.produto')
            ->chunkById(200, function ($fotos) use ($dryRun) {
                foreach ($fotos as $foto) {
                    $this->registrarFoto($foto, $dryRun);
                }
            });

        MlAnuncioCriativo::query()
            ->where('status', MlAnuncioCriativo::STATUS_APROVADO)
            ->whereNull('pub_imagem_id')->whereNotNull('imagem_path')
            ->chunkById(200, function ($criativos) use ($dryRun) {
                foreach ($criativos as $criativo) {
                    $this->registrarCriativoAntigo($criativo, $dryRun);
                }
            });

        $this->info($dryRun ? 'DRY-RUN — nada foi gravado.' : 'Backfill aplicado.');
        $this->table(['item', 'quantidade'], collect($this->placar)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return self::SUCCESS;
    }

    private function registrarFoto(PubImagem $foto, bool $dryRun): void
    {
        $produto = $foto->rascunho?->produto;
        $dono = $produto === null ? null : AcervoImagem::donoDe($produto->mlb_empresa_id, $produto->company_id);
        if ($dono === null) {
            $this->placar['sem_dono']++;

            return;
        }

        $chaveSimulada = $dono['chave'].'|'.$foto->sha256;
        $existente = AcervoImagem::query()->where('dono_chave', $dono['chave'])->where('sha256', $foto->sha256)->first();
        if ($existente !== null || isset($this->simulados[$chaveSimulada])) {
            $this->placar['fotos_ligadas_a_ativo_existente']++;
            if ($existente === null || $existente->caminho !== $foto->caminho) {
                $this->placar['copias_fisicas_redundantes']++;
            }
            if (! $dryRun && $existente !== null) {
                $foto->update(['acervo_imagem_id' => $existente->id]);
            }

            return;
        }

        $disco = ArquivosDeImagem::discoDe($foto->caminho);
        if ($disco === null) {
            $this->placar['sem_arquivo']++;

            return;
        }

        $criativo = MlAnuncioCriativo::query()->where('pub_imagem_id', $foto->id)->latest('id')->first();
        $this->placar['ativos_criados_de_fotos']++;
        if ($dryRun) {
            $this->simulados[$chaveSimulada] = true;

            return;
        }

        DB::transaction(function () use ($foto, $produto, $dono, $disco, $criativo) {
            $ativo = $this->criarOuAchar([
                'company_id' => $dono['company_id'],
                'mlb_empresa_id' => $dono['mlb_empresa_id'],
                'dono_chave' => $dono['chave'],
                'ml_user_id' => $produto->contaOuNula()?->mlToken?->ml_user_id,
                'disco' => $disco,
                'caminho' => $foto->caminho,
                'sha256' => $foto->sha256,
                'mime' => $foto->mime ?: 'image/jpeg',
                'bytes' => (int) $foto->bytes,
                'largura' => $foto->largura ?: null,
                'altura' => $foto->altura ?: null,
                'origem' => $criativo !== null ? AcervoImagem::ORIGEM_IA : AcervoImagem::ORIGEM_UPLOAD,
                'modelo_ia' => $criativo?->modelo,
                'criativo_id' => $criativo?->id,
                'produto_id' => $produto->id,
                'sku' => $produto->sku,
                'produto_nome' => $produto->nome !== null ? mb_substr((string) $produto->nome, 0, 255) : null,
                'status' => AcervoImagem::DISPONIVEL,
                'created_at' => $foto->created_at,
                'updated_at' => now(),
            ]);
            $foto->update(['acervo_imagem_id' => $ativo->id]);
        });
    }

    private function registrarCriativoAntigo(MlAnuncioCriativo $criativo, bool $dryRun): void
    {
        $dono = AcervoImagem::donoDe($criativo->mlb_empresa_id, $criativo->company_id);
        if ($dono === null) {
            $this->placar['sem_dono']++;

            return;
        }

        $conteudo = ArquivosDeImagem::ler($criativo->imagem_path);
        if ($conteudo === null) {
            $this->placar['sem_arquivo']++;

            return;
        }

        $sha = hash('sha256', $conteudo);
        $chaveSimulada = $dono['chave'].'|'.$sha;
        if (isset($this->simulados[$chaveSimulada]) || AcervoImagem::query()->where('dono_chave', $dono['chave'])->where('sha256', $sha)->exists()) {
            return;
        }

        $this->placar['ativos_criados_de_criativos_antigos']++;
        if ($dryRun) {
            $this->simulados[$chaveSimulada] = true;

            return;
        }

        $info = @getimagesizefromstring($conteudo) ?: [];
        $this->criarOuAchar([
            'company_id' => $dono['company_id'],
            'mlb_empresa_id' => $dono['mlb_empresa_id'],
            'dono_chave' => $dono['chave'],
            'disco' => (string) ArquivosDeImagem::discoDe($criativo->imagem_path),
            'caminho' => $criativo->imagem_path,
            'sha256' => $sha,
            'mime' => $criativo->imagem_mime ?: (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($conteudo),
            'bytes' => strlen($conteudo),
            'largura' => $info[0] ?? null,
            'altura' => $info[1] ?? null,
            'origem' => AcervoImagem::ORIGEM_IA,
            'modelo_ia' => $criativo->modelo,
            'criativo_id' => $criativo->id,
            'status' => AcervoImagem::DISPONIVEL,
            'created_at' => $criativo->aprovado_em ?? $criativo->created_at,
            'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $dados */
    private function criarOuAchar(array $dados): AcervoImagem
    {
        try {
            return AcervoImagem::create($dados);
        } catch (QueryException $e) {
            // Outro processo (upload ao vivo) criou o mesmo (dono, sha) no meio do backfill.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return AcervoImagem::query()->where('dono_chave', $dados['dono_chave'])->where('sha256', $dados['sha256'])->firstOrFail();
        }
    }
}
