<?php

namespace App\Services\Publicador;

use App\Contracts\ContaMercadoLivre;
use App\Models\PubImagem;
use App\Models\PubRascunho;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\Validacao\ContextoValidacao;
use App\Support\Publicador\Validacao\Problema;
use App\Support\Publicador\Validacao\ValidadorImagem;
use Illuminate\Support\Facades\Storage;

/**
 * As fotos do rascunho (`06` §7): conferir (L1), guardar, deduplicar e subir
 * para o ML.
 *
 * 1. L1 antes de guardar: JPG/PNG, até 10 MB, lados ≥ 500 px (o ML recusa
 *    abaixo disso). Bloqueio = nada é guardado.
 * 2. O mesmo arquivo (sha256) no mesmo rascunho é a mesma foto (TC-58).
 * 3. Guarda no disco PRIVADO (`local`), não no público: a foto do cliente só
 *    sai daqui para o ML.
 * 4. Sobe na hora (`POST /pictures/items/upload`) e guarda o `ml_picture_id`
 *    — o payload usa ids (RN-65). Falhou: `failed` com o motivo, e a
 *    publicação não segue sem ela (V-IMG-08, TC-59).
 */
class ImagemAssetService
{
    private const DISCO = 'local';

    public function __construct(private ClienteMlPublicador $cliente) {}

    /** @return array{imagem: ?PubImagem, problemas: list<Problema>, nova: bool} */
    public function receber(PubRascunho $r, string $conteudo, string $nomeOriginal): array
    {
        $meta = self::metadados($conteudo);
        $problemas = ValidadorImagem::problemas('nova', $meta, new ContextoValidacao());
        if (array_filter($problemas, fn (Problema $p) => $p->bloqueia())) {
            return ['imagem' => null, 'problemas' => $problemas, 'nova' => false];
        }

        $sha = hash('sha256', $conteudo);
        if ($existente = $r->imagens()->where('sha256', $sha)->first()) {
            return ['imagem' => $existente, 'problemas' => $problemas, 'nova' => false];
        }

        $caminho = "publicador/{$r->id}/{$sha}.".($meta['mime'] === 'image/png' ? 'png' : 'jpg');
        Storage::disk(self::DISCO)->put($caminho, $conteudo);

        $imagem = $r->imagens()->create([
            'caminho' => $caminho, 'sha256' => $sha, 'mime' => $meta['mime'], 'bytes' => $meta['bytes'],
            'largura' => $meta['largura'], 'altura' => $meta['altura'], 'upload_status' => PubImagem::PENDENTE,
        ]);

        return ['imagem' => $this->enviarAoMl($imagem, $conteudo), 'problemas' => $problemas, 'nova' => true];
    }

    /**
     * @param  ?ContaMercadoLivre  $conta  a conta já conferida por quem chama (a publicação passa a
     *                                     fixada no clique — CR-B01); nula = a âncora do rascunho agora
     */
    public function enviarAoMl(PubImagem $imagem, ?string $conteudo = null, ?ContaMercadoLivre $conta = null): PubImagem
    {
        if ($imagem->upload_status === PubImagem::ENVIADA && $imagem->ml_picture_id) {
            return $imagem;
        }
        if ($imagem->caminho === null) {
            // Veio do Anunciar antigo só com o id do ML: não há arquivo para reenviar (H-22).
            $imagem->update(['upload_status' => PubImagem::FALHOU, 'upload_erro' => ['mensagem' => 'Esta foto veio do Anunciar antigo sem o arquivo. Envie a foto de novo.']]);

            return $imagem->fresh();
        }

        $conta ??= $imagem->rascunho->conta();
        // D26: conta não liberada não recebe foto; ela fica guardada aqui e sobe pelo `enviarPendentes`
        // da conferência L3/publicação depois da liberação.
        if (! ContasLiberadas::libera($conta)) {
            return $imagem;
        }

        $conteudo ??= Storage::disk(self::DISCO)->get($imagem->caminho);
        $resposta = $this->cliente->enviarFoto($conta, $conteudo, basename($imagem->caminho));

        if ($resposta->ok() && is_array($resposta->corpo) && isset($resposta->corpo['id'])) {
            $variacoes = (array) ($resposta->corpo['variations'] ?? []);
            $imagem->update([
                'upload_status' => PubImagem::ENVIADA,
                'ml_picture_id' => (string) $resposta->corpo['id'],
                'ml_url' => $variacoes[0]['secure_url'] ?? collect($variacoes)->pluck('secure_url')->filter()->first(),
                'upload_erro' => null,
            ]);
        } else {
            $imagem->update(['upload_status' => PubImagem::FALHOU, 'upload_erro' => [
                'status' => $resposta->status,
                'classe' => $resposta->classe,
                'causas' => $resposta->causas,
                'mensagem' => self::mensagemDeFalha($resposta),
            ]]);
        }

        return $imagem->fresh();
    }

    /**
     * Sobe o que faltou antes de conferir/publicar (`08` §4 passo 4).
     *
     * @param  ?ContaMercadoLivre  $conta  ver `enviarAoMl()`
     * @return list<PubImagem> as que continuam sem subir
     */
    public function enviarPendentes(PubRascunho $r, ?ContaMercadoLivre $conta = null): array
    {
        $falhas = [];
        foreach ($r->imagens()->where('upload_status', '!=', PubImagem::ENVIADA)->get() as $imagem) {
            if ($this->enviarAoMl($imagem, null, $conta)->upload_status !== PubImagem::ENVIADA) {
                $falhas[] = $imagem->fresh();
            }
        }

        return $falhas;
    }

    public function remover(PubImagem $imagem): void
    {
        if ($imagem->caminho) {
            Storage::disk(self::DISCO)->delete($imagem->caminho);
        }
        $imagem->delete();
    }

    /** @return array{mime: string, bytes: int, largura: int, altura: int, cmyk: bool} */
    public static function metadados(string $conteudo): array
    {
        $info = @getimagesizefromstring($conteudo) ?: [];

        return [
            'mime' => (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($conteudo),
            'bytes' => strlen($conteudo),
            'largura' => (int) ($info[0] ?? 0),
            'altura' => (int) ($info[1] ?? 0),
            'cmyk' => ($info['channels'] ?? null) === 4,
        ];
    }

    private static function mensagemDeFalha(RespostaMl $r): string
    {
        $codigos = implode(' ', array_column($r->causas, 'code')).' '.implode(' ', array_map('strval', array_column($r->causas, 'cause_id')));

        return match (true) {
            str_contains($codigos, '3703') => 'O Mercado Livre achou a foto pequena demais (mínimo 500 px).',
            $r->classe === RespostaMl::RATE_LIMIT || $r->status === 400 && str_contains(strtolower(json_encode($r->corpo)), 'bad_request') => 'O Mercado Livre limitou o envio de fotos por minuto. Aguarde um pouco e envie de novo.',
            $r->classe === RespostaMl::NETWORK, $r->classe === RespostaMl::SERVER => 'O Mercado Livre não respondeu. A foto está guardada e será enviada de novo antes de publicar.',
            default => 'O Mercado Livre não aceitou esta foto. Tente de novo ou use outra imagem.',
        };
    }
}
