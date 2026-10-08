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
use Illuminate\Database\QueryException;
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

    /**
     * @param  bool  $enviar  false = só guarda (pendente), sem chamar o ML: o Sincronizar do Portal
     *                        grava rascunho e nunca escreve no ML; a foto sobe em `enviarPendentes`
     *                        antes da conferência/publicação (D26 intacto).
     * @return array{imagem: ?PubImagem, problemas: list<Problema>, nova: bool}
     */
    public function receber(PubRascunho $r, string $conteudo, string $nomeOriginal, bool $enviar = true): array
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

        try {
            $imagem = $r->imagens()->create([
                'caminho' => $caminho, 'sha256' => $sha, 'mime' => $meta['mime'], 'bytes' => $meta['bytes'],
                'largura' => $meta['largura'], 'altura' => $meta['altura'], 'upload_status' => PubImagem::PENDENTE,
            ]);
        } catch (QueryException $e) {
            // Corrida no `pubim_sha_uq`: outro processo guardou a mesma foto entre a checagem e o create.
            $existente = (string) $e->getCode() === '23000' ? $r->imagens()->where('sha256', $sha)->first() : null;
            if ($existente === null) {
                throw $e;
            }

            return ['imagem' => $existente, 'problemas' => $problemas, 'nova' => false];
        }

        return ['imagem' => $enviar ? $this->enviarAoMl($imagem, $conteudo) : $imagem, 'problemas' => $problemas, 'nova' => true];
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

        // D26: conta não liberada não recebe foto; ela fica guardada aqui (pendente) e sobe pelo
        // `enviarPendentes` da conferência L3/publicação depois da liberação. WR-B04: sem token ativo
        // é o mesmo caso — a foto entra no grupo e o V-ACC-01 (reconectar) aparece ao publicar.
        $conta ??= $imagem->rascunho->produto->contaOuNula();
        if ($conta === null || ! ContasLiberadas::libera($conta)) {
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
