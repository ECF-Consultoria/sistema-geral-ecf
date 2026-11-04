<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Trilha\CadastroNaTrilha;
use App\Support\Portal\AtorDoPortal;
use App\Support\Portal\Estrutura\RegrasDoCadastro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * As fotos da variação na porta da EQUIPE da trilha: ver (o painel "Mapear", 02/11/2026, e a galeria do cadastro), e,
 * desde a F3 do cadastro (03/11/2026), enviar e excluir — pelo serviço do Portal (`VariacaoImagensService`), sem o
 * Portal.
 *
 * A rota de imagem do Portal não serve aqui: gerada fora de uma visita ao Portal ela sai `/portal/0/…`, e no domínio
 * do admin o `LevaPortalParaODominioDoCliente` a manda para o domínio do cliente, onde a equipe não tem sessão — a foto
 * aparecia quebrada.
 *
 * A guarda é a do cadastro ({@see CadastroNaTrilha::empresa()}, desenho §6.1): as duas chaves (404, antes de ler a
 * empresa) e a régua de escrita do Portal da empresa (403); o produto, a variação e a imagem do ENDEREÇO presos à
 * empresa e uns aos outros (404, antes de validar o corpo — ninguém lê nem mexe na foto de outra loja trocando o
 * número); a forma do envio (422); e, nas escritas, a trava do produto que já andou (409).
 */
class FotosDaTrilhaController extends Controller
{
    public function __construct(
        private VariacaoImagensService $imagens,
        private CadastroNaTrilha $cadastro,
    ) {}

    /**
     * Rota 12 — o arquivo da foto. Sem parâmetro, a miniatura (a galeria e o "Mapear"; uma semana no cache do
     * navegador: o id da imagem nunca troca de conteúdo). `?original=1` (F3, o "Ver maior"): o arquivo enviado, uma
     * hora de cache. Sempre `private` (nunca em cache compartilhado) e `nosniff`.
     */
    public function ver(Request $request, int $company, int $variacao, int $imagem): Response
    {
        $empresa = $this->cadastro->empresa($request->user(), $company);

        $img = EstruturaProdutoVariacaoImagem::query()
            ->where('company_id', $empresa->id)
            ->where('variacao_id', $variacao)
            ->whereKey($imagem)
            ->firstOrFail();
        abort_unless($this->imagens->existe($img), 404);

        if (! $request->boolean('original') && ($miniatura = $this->imagens->miniatura($img)) !== null) {
            return Storage::disk(VariacaoImagensService::DISCO)->response($miniatura, null, [
                'Content-Type'           => 'image/jpeg',
                'Cache-Control'          => 'private, max-age=604800',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // A original: pedida, ou sem miniatura (sem GD, formato que o GD não lê), como no Portal.
        return Storage::disk(VariacaoImagensService::DISCO)->response($img->caminho, null, [
            'Content-Type'           => $img->mime,
            'Cache-Control'          => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Rota 10 — envia `imagens[]` (multipart) para o fim da galeria da variação. As regras são as do Portal
     * (`RegrasDoCadastro::imagens`: 1 ao teto por variação, JPG/PNG/WebP conferidos pelo CONTEÚDO, até 10 MB cada,
     * mensagens com a posição da imagem), e o envio que o PHP descartou vira a mensagem certa. Tudo ou nada: a remessa
     * que não cabe inteira no teto é 422 em `errors.imagens` e nada fica. Responde o retrato novo da gaveta.
     */
    public function enviar(Request $request, int $company, int $produto, int $variacao): JsonResponse
    {
        [$empresa, $alvo] = $this->cadastro->alvo($request->user(), $company, $produto);
        $v = $this->cadastro->variacao($empresa, $alvo, $variacao);

        RegrasDoCadastro::recusarEnvioQueNaoChegou($request);
        $request->validate(RegrasDoCadastro::imagens(), RegrasDoCadastro::mensagensDasImagens());
        $this->cadastro->exigirLivre($empresa, $alvo);

        $arquivos = array_values((array) $request->file('imagens'));
        $this->cadastro->enviarFotos($empresa, $alvo, $v, $arquivos, AtorDoPortal::daEquipe($request->user()));
        $retrato = $this->cadastro->retrato($empresa, $alvo);

        return response()->json([
            ...$retrato,
            'mensagem' => self::mensagem(count($arquivos) === 1 ? 'Foto enviada.' : 'Fotos enviadas.', $retrato['situacao']),
        ]);
    }

    /**
     * Rota 11 — exclui a foto: as que sobram são renumeradas (a 1ª vira a capa) e o arquivo sai do disco depois do
     * commit. Responde o retrato novo da gaveta.
     */
    public function excluir(Request $request, int $company, int $produto, int $variacao, int $imagem): JsonResponse
    {
        [$empresa, $alvo] = $this->cadastro->alvo($request->user(), $company, $produto);
        $v = $this->cadastro->variacao($empresa, $alvo, $variacao);
        $img = $this->cadastro->imagem($empresa, $v, $imagem);
        $this->cadastro->exigirLivre($empresa, $alvo);

        $this->cadastro->excluirFoto($empresa, $alvo, $v, $img, AtorDoPortal::daEquipe($request->user()));
        $retrato = $this->cadastro->retrato($empresa, $alvo);

        return response()->json([...$retrato, 'mensagem' => self::mensagem('Foto excluída.', $retrato['situacao'])]);
    }

    /**
     * Rota 11b (R6, 04/11/2026) — a ordem das fotos da cor: `{ordem: [id, id, …]}` com TODAS as fotos da variação, cada
     * uma uma vez; a 1ª vira a capa. A ordem da guarda é a do envio: o endereço (404), a forma do corpo (422), a trava do
     * produto que andou (409) e, sob a variação presa, a lista conferida contra a galeria de AGORA (422 em `ordem` — a
     * foto que outra aba enviou ou excluiu no meio do caminho não some nem fica de fora em silêncio). Não confere a versão
     * (F6): as fotos ficam fora dela. Responde o retrato novo da gaveta.
     */
    public function ordenar(Request $request, int $company, int $produto, int $variacao): JsonResponse
    {
        [$empresa, $alvo] = $this->cadastro->alvo($request->user(), $company, $produto);
        $v = $this->cadastro->variacao($empresa, $alvo, $variacao);

        $dados = $request->validate([
            'ordem'   => ['required', 'array', 'min:1', 'max:'.max(1, VariacaoImagensService::maxPorVariacao())],
            'ordem.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], [
            'ordem.required'   => 'Informe a ordem das fotos.',
            'ordem.array'      => 'Ordem das fotos inválida.',
            'ordem.min'        => 'Informe a ordem das fotos.',
            'ordem.max'        => 'Ordem das fotos inválida.',
            'ordem.*.required' => 'Ordem das fotos inválida.',
            'ordem.*.integer'  => 'Ordem das fotos inválida.',
            'ordem.*.min'      => 'Ordem das fotos inválida.',
            'ordem.*.distinct' => 'Ordem das fotos inválida: a mesma foto apareceu duas vezes.',
        ]);
        $this->cadastro->exigirLivre($empresa, $alvo);

        $mudou = $this->cadastro->ordenarFotos($empresa, $alvo, $v, array_map('intval', $dados['ordem']), AtorDoPortal::daEquipe($request->user()));
        $retrato = $this->cadastro->retrato($empresa, $alvo);

        return response()->json([...$retrato, 'mensagem' => $mudou ? 'Ordem das fotos salva.' : 'A ordem já era esta.']);
    }

    /** O resultado, no padrão das outras escritas do cadastro: completo, ainda na Lista ou só o que foi feito. */
    private static function mensagem(string $feito, array $situacao): string
    {
        return match ($situacao['etapa']) {
            'lista' => $feito.' Ainda faltam: '.implode(', ', $situacao['faltas']).'.',
            'mapa'  => 'Lista completa: o produto seguiu para o mapeamento.',
            default => $feito,
        };
    }
}
