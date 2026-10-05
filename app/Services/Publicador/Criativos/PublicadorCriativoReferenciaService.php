<?php

namespace App\Services\Publicador\Criativos;

use App\Models\MlAnuncioCriativo;
use App\Models\PubImagem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Creative\ReferenciaEfemeraService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\OpcoesImagem;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\Variacao\Eixo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fase 165 (critério de Claude fechado no 165-CONTEXT.md) — as fotos do
 * PRÓPRIO rascunho do Publicador (e/ou um upload novo) viram a referência
 * efêmera que o Creative Engine usa para gerar o kit de imagens por IA.
 *
 * As fotos do rascunho são do cliente e continuam do rascunho — nada aqui
 * move nem apaga `pub_imagens`. A CÓPIA em `creative-referencias/{token}/`
 * é efêmera: é ela que a D-02 da v24 protege (apagada ao fechar o kit ou em
 * 48h pela varredura `creative:limpar-referencias` — nenhuma mudança lá).
 *
 * O `ReferenciaEfemeraService` do Creative Engine é usado SEM ALTERAÇÃO, por
 * um adaptador `UploadedFile` em modo teste (`A2` do RESEARCH): se um dia
 * isso quebrar, o conserto é AQUI, nunca naquele serviço sem combinar com o
 * outro dev.
 */
class PublicadorCriativoReferenciaService
{
    /** Teto de referências por criativo — mesmo limite do `ReferenciaEfemeraService`. */
    public const MAX = ReferenciaEfemeraService::MAX_REFERENCIAS;

    public function __construct(
        private ReferenciaEfemeraService $efemera,
        private RascunhoRepository $repo,
    ) {}

    /**
     * Os grupos válidos deste rascunho AGORA — a galeria geral mais os
     * grupos de variação (T-165-10: o controller do 165-04 usa isto para
     * recusar um grupo forjado na criação do kit).
     *
     * @return list<string>
     */
    public function gruposValidos(PubRascunho $r): array
    {
        $s = $this->repo->snapshot($r);
        $grupos = ResolvedorGruposImagem::resolver(
            $s->variantes,
            Eixo::ordenar($s->eixos),
            $s->imagens,
            new OpcoesImagem(OpcoesImagem::UP, null, null, $s->fotosPorVariante, $s->incluirGeral),
        )->grupos;

        return array_values(array_unique([ResolvedorGruposImagem::GERAL, ...array_column($grupos, 'chave')]));
    }

    /**
     * As `pub_imagens` pedidas, na ordem dos ids — só as que têm arquivo em
     * disco (T-165-07: busca só em `$r->imagens()`, id de outro rascunho
     * nunca é achado e vira 422 explícito).
     *
     * @param  list<int|string>  $ids
     * @return list<PubImagem>
     */
    public function selecionarFotos(PubRascunho $r, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $doRascunho = $r->imagens()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            if (! $doRascunho->has($id)) {
                throw ValidationException::withMessages(['imagens' => 'Uma das fotos escolhidas não é deste anúncio.']);
            }
        }

        return array_values(array_filter(
            array_map(fn ($id) => $doRascunho->get($id), $ids),
            fn (PubImagem $img) => $img->caminho !== null && Storage::disk('local')->exists($img->caminho),
        ));
    }

    /**
     * O criativo PORTADOR da referência (165-01/165-02): `rascunho_id` NULL,
     * `pub_rascunho_id`/`pub_grupo` preenchidos (D-02). O token nasce aqui e
     * NUNCA vai à tela (165-04) — os ids que trafegam com o navegador são
     * sempre do PRODUTO/rascunho (T-160-01).
     */
    public function criarPortador(PubRascunho $r, string $grupo, User $u): MlAnuncioCriativo
    {
        return MlAnuncioCriativo::create([
            'token' => Str::random(32),
            'company_id' => $r->produto->company_id,
            'mlb_empresa_id' => $r->produto->mlb_empresa_id,
            'rascunho_id' => null,
            'pub_rascunho_id' => $r->id,
            'pub_grupo' => $grupo,
            'user_id' => $u->id,
            'slot' => 'hero',
            'status' => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);
    }

    /**
     * Guarda as fotos do rascunho e/ou uploads novos como referência
     * efêmera do portador — UMA chamada ao `ReferenciaEfemeraService`
     * (duas chamadas colidiriam no índice 0, já que os índices são por
     * chamada). O controller valida o total ANTES de criar qualquer linha;
     * aqui só a lista vazia é recusada.
     *
     * @param  list<PubImagem>  $fotos
     * @param  list<UploadedFile>  $uploads
     * @return array<int, array{indice:int, path:string, mime:string, bytes:int, nome:string, hash:string}>
     */
    public function guardar(MlAnuncioCriativo $portador, array $fotos, array $uploads): array
    {
        $arquivos = [
            ...array_map(fn (PubImagem $img) => new UploadedFile(
                Storage::disk('local')->path($img->caminho),
                'foto-'.$img->id.'.'.($img->mime === 'image/png' ? 'png' : 'jpg'),
                $img->mime,
                null,
                true,
            ), $fotos),
            ...$uploads,
        ];
        $arquivos = array_slice($arquivos, 0, self::MAX);

        if ($arquivos === []) {
            throw new \InvalidArgumentException('Nenhuma foto de referência para guardar.');
        }

        $refs = $this->efemera->guardar($portador, $arquivos);
        $portador->update(['referencias' => $refs]);

        return $refs;
    }
}
