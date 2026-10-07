<?php

namespace App\Mcp\Tools;

use App\Mcp\ErroDaFerramenta;
use App\Models\Chamado;
use App\Models\ChamadoAnexo;
use App\Models\User;
use App\Services\DevDemandas\ChamadoService;
use App\Services\ModuleRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Throwable;

/**
 * `ler_ticket` — tickets pelo MCP: a lista que a pessoa vê e o detalhe de um
 * ticket com descrição, mensagens e os PRINTS anexados (como imagem).
 *
 * Por que existe (06/10/2026), em vez de `ler_tela`:
 *  - `/tickets` (`chamados.index`) é a tela de QUEM PEDIU: lista só os tickets
 *    que a pessoa abriu, inclusive para o dev. A caixa da equipe é a aba
 *    Tickets de `/dev/demandas`. Pelo `ler_tela` o dev via "só o dele" e
 *    achava que era recorte errado.
 *  - O detalhe `/tickets/{id}` está bloqueado no `ler_tela`: abrir marca os
 *    avisos do ticket como lidos. Aqui o detalhe é lido sem esse efeito.
 *
 * Mesmas regras das telas, pelo mesmo serviço: {@see ChamadoService::podeVer}
 * (ticket alheio = "não encontrado", como o 404 da tela),
 * {@see ChamadoService::daEquipe} (caixa da equipe) e
 * {@see ChamadoService::detalhe} (nota interna, anexo de nota interna e motivo
 * de transferência só para quem atua como equipe).
 */
#[Name('ler_ticket')]
#[Title('Ler tickets do time de desenvolvimento')]
#[Description(<<<'TXT'
Lê os tickets (TKT-xxxx) da Central de Tickets do ECF Admin, com o recorte do seu perfil.
- Sem "ticket": a lista. Quem é da equipe dev (ou admin) recebe a caixa da equipe — os tickets que atende, os da fila sem responsável e os que abriu; os demais recebem só os tickets que abriram. Filtre por "status" ("abertos" = tudo que não foi resolvido nem cancelado) e "busca" (título ou código).
- Com "ticket" (código, ex.: "TKT-0007", ou id): o ticket inteiro — descrição, contexto, resolução, a linha do tempo de mensagens e eventos, e os prints/imagens anexados, que vêm junto como imagem. Notas internas só aparecem para a equipe dev.
Para responder, mudar status, transferir ou resolver, use atuar_no_ticket.
TXT)]
class LerTicketTool extends FerramentaEcf
{
    /** Quantas imagens vão no detalhe (as primeiras, na ordem da linha do tempo). */
    private const MAX_IMAGENS = 6;

    /** Lado maior da imagem enviada ao Claude — acima disso ele reduz de qualquer forma. */
    private const LADO_MAX = 1568;

    /** Imagem até este tamanho e dentro do LADO_MAX vai como está. */
    private const BYTES_SEM_REDUZIR = 1_000_000;

    private const MIMES_DE_IMAGEM = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** @var array<int, array{0:string, 1:string}> imagens do detalhe — [bytes, mime] */
    private array $imagens = [];

    protected function podeUsar(User $usuario): bool
    {
        // As duas telas de ticket: /tickets (`modulo:chamados`) e a caixa da
        // equipe em /dev/demandas (`modulo:dev.demandas`).
        $modulos = app(ModuleRegistry::class);

        return $modulos->liberadoPara($usuario, 'chamados')
            || (Chamado::ehEquipe($usuario) && $modulos->liberadoPara($usuario, 'dev.demandas'));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket' => $schema->string()
                ->description('Código (ex.: "TKT-0007") ou id. Sem ele, vem a lista.'),
            'status' => $schema->string()
                ->enum(['abertos', ...array_keys(Chamado::STATUS_LABELS)])
                ->description('Só na lista: "abertos" (não resolvidos nem cancelados) ou um status: '.implode(', ', array_keys(Chamado::STATUS_LABELS)).'.'),
            'busca' => $schema->string()
                ->description('Só na lista: parte do título ou do código.'),
            'imagens' => $schema->boolean()
                ->description('Só no detalhe: false para não trazer os prints como imagem (padrão: traz até '.self::MAX_IMAGENS.').'),
            ...$this->schemaPaginacao($schema),
        ];
    }

    protected function consultar(Request $request, User $usuario): array
    {
        $this->imagens = [];
        $servico       = app(ChamadoService::class);
        $referencia    = $this->texto($request, 'ticket');

        return $referencia === null
            ? $this->lista($request, $usuario, $servico)
            : $this->detalhe($request, $usuario, $servico, $referencia);
    }

    /** Texto (JSON) + os prints como imagem; resposta grande não vai duplicada. */
    protected function responder(array $dados): Response|ResponseFactory
    {
        $respostas = [Response::json($dados)];
        foreach ($this->imagens as [$bytes, $mime]) {
            $respostas[] = Response::image($bytes, $mime);
        }
        $this->imagens = [];

        return Response::make($respostas);
    }

    // ═══ Lista ═══

    private function lista(Request $request, User $usuario, ChamadoService $servico): array
    {
        $equipe = Chamado::ehEquipe($usuario);

        // Equipe: a caixa de /dev/demandas + os que a pessoa abriu (um dev que
        // pediu algo a outro dev não atua nele, mas é dele). Demais: só os que
        // abriram, como /tickets.
        // As duas condições AGRUPADAS: para admin o daEquipe() não tem filtro
        // nenhum, e um `->orWhere()` encadeado nele vira o ÚNICO filtro (o
        // Laravel descarta o "or" da primeira condição) — o admin via só os
        // que abriu (06/10/2026, em produção).
        $visiveis = $equipe
            ? Chamado::query()->where(fn ($q) => $q
                ->whereIn('id', $servico->daEquipe($usuario)->select('id'))
                ->orWhere('solicitante_id', $usuario->id))
            : Chamado::query()->where('solicitante_id', $usuario->id);

        // Visibilidade num subselect: o OR acima não pode engolir os filtros.
        $consulta = Chamado::query()
            ->whereIn('id', $visiveis->select('id'))
            ->with(['responsavel:id,name', 'demanda:id,codigo', 'ultimaMensagemPublica'])
            ->orderByDesc('ultima_interacao_em')
            ->orderByDesc('id');

        $status = $this->texto($request, 'status');
        if ($status === 'abertos') {
            $consulta->whereNotIn('status', Chamado::STATUS_ENCERRADOS);
        } elseif ($status !== null) {
            if (! array_key_exists($status, Chamado::STATUS_LABELS)) {
                throw new ErroDaFerramenta('Status inválido. Use "abertos" ou: '.implode(', ', array_keys(Chamado::STATUS_LABELS)).'.');
            }
            $consulta->where('status', $status);
        }

        if (($busca = $this->texto($request, 'busca')) !== null) {
            $consulta->where(fn ($q) => $q->where('titulo', 'like', "%{$busca}%")->orWhere('codigo', 'like', "%{$busca}%"));
        }

        return [
            'visao' => $equipe ? 'equipe dev (caixa de /dev/demandas + os que você abriu)' : 'os tickets que você abriu',
            ...$this->paginarConsulta($consulta, $request, fn (Chamado $c) => $servico->resumo($c, $servico->podeAtuar($usuario, $c))
                + ['status_nome' => Chamado::STATUS_LABELS[$c->status] ?? $c->status]),
            'como_continuar' => 'Abra um ticket com ler_ticket {"ticket": "<código>"} para ver descrição, mensagens e prints.',
        ];
    }

    // ═══ Detalhe ═══

    private function detalhe(Request $request, User $usuario, ChamadoService $servico, string $referencia): array
    {
        $chamado = preg_match('/(\d+)\s*$/', $referencia, $m) ? Chamado::find((int) $m[1]) : null;

        // Ticket alheio responde igual ao inexistente — como o 404 da tela.
        if (! $chamado || ! $servico->podeVer($usuario, $chamado)) {
            throw new ErroDaFerramenta("Ticket \"{$referencia}\" não encontrado.");
        }

        // Quem atua como equipe recebe a visão da equipe (notas internas
        // incluídas); quem só abriu recebe a de solicitante.
        $dados = $servico->detalhe($chamado, $usuario);
        $dados['status_nome'] = Chamado::STATUS_LABELS[$chamado->status] ?? $chamado->status;

        $imagens = [];
        if ($request->get('imagens') === null || $this->booleano($request, 'imagens')) {
            $imagens = $this->carregarImagens($usuario, $chamado, $dados, $servico);
        }

        return $dados + array_filter([
            'imagens_no_retorno' => $imagens['enviadas'] ?? null,
            'imagens_fora'       => $imagens['fora'] ?? null,
        ]);
    }

    /**
     * Os prints que esta pessoa pode ver (anexos do ticket e das mensagens que
     * vieram no detalhe), na ordem da linha do tempo, prontos para ir como
     * imagem. Arquivo que não é imagem fica só na lista de anexos.
     *
     * @return array{enviadas: list<array<string, mixed>>, fora: list<array<string, mixed>>}
     */
    private function carregarImagens(User $usuario, Chamado $chamado, array $dados, ChamadoService $servico): array
    {
        $candidatos = collect($dados['anexos'] ?? [])->map(fn ($a) => $a + ['onde' => 'abertura do ticket']);
        foreach ($dados['linha_do_tempo'] ?? [] as $item) {
            if (($item['item'] ?? null) === 'mensagem') {
                foreach ($item['anexos'] ?? [] as $a) {
                    $candidatos->push($a + ['onde' => 'mensagem de '.$item['autor'].' em '.$item['em']]);
                }
            }
        }

        $enviadas = [];
        $fora     = [];
        foreach ($candidatos->where('imagem', true) as $a) {
            $anexo = ChamadoAnexo::find($a['id']);
            if (! $anexo || ! $servico->podeBaixar($usuario, $chamado, $anexo)) {
                continue;
            }
            if (count($enviadas) >= self::MAX_IMAGENS) {
                $fora[] = ['anexo_id' => $a['id'], 'nome' => $a['nome'], 'onde' => $a['onde'], 'motivo' => 'limite de '.self::MAX_IMAGENS.' imagens por resposta'];
                continue;
            }

            $imagem = $this->imagemParaEnviar($anexo);
            if ($imagem === null) {
                $fora[] = ['anexo_id' => $a['id'], 'nome' => $a['nome'], 'onde' => $a['onde'], 'motivo' => 'arquivo não encontrado ou imagem ilegível'];
                continue;
            }

            $this->imagens[] = $imagem;
            $enviadas[]      = ['ordem' => count($enviadas) + 1, 'anexo_id' => $a['id'], 'nome' => $a['nome'], 'onde' => $a['onde']];
        }

        return ['enviadas' => $enviadas, 'fora' => $fora];
    }

    /**
     * Bytes + mime da imagem, reduzida para caber numa resposta: lado maior até
     * LADO_MAX e JPEG quando precisa reduzir (print de tela inteira chega a
     * vários MB).
     *
     * @return array{0:string, 1:string}|null
     */
    private function imagemParaEnviar(ChamadoAnexo $anexo): ?array
    {
        try {
            $disco = Storage::disk('local');
            if (! in_array($anexo->mime, self::MIMES_DE_IMAGEM, true) || ! $disco->exists($anexo->caminho)) {
                return null;
            }
            $bytes = (string) $disco->get($anexo->caminho);

            if (! function_exists('imagecreatefromstring')) {
                return strlen($bytes) <= 3_500_000 ? [$bytes, $anexo->mime] : null;
            }

            $original = @imagecreatefromstring($bytes);
            if ($original === false) {
                return null;
            }
            $largura = imagesx($original);
            $altura  = imagesy($original);
            $lado    = max($largura, $altura);

            if ($lado <= self::LADO_MAX && strlen($bytes) <= self::BYTES_SEM_REDUZIR) {
                imagedestroy($original);

                return [$bytes, $anexo->mime];
            }

            $escala = min(1, self::LADO_MAX / $lado);
            $nova   = imagecreatetruecolor(max(1, (int) round($largura * $escala)), max(1, (int) round($altura * $escala)));
            // Fundo branco: PNG transparente vira JPEG sem fundo preto.
            imagefill($nova, 0, 0, imagecolorallocate($nova, 255, 255, 255));
            imagecopyresampled($nova, $original, 0, 0, 0, 0, imagesx($nova), imagesy($nova), $largura, $altura);

            ob_start();
            imagejpeg($nova, null, 82);
            $saida = (string) ob_get_clean();
            imagedestroy($original);
            imagedestroy($nova);

            return [$saida, 'image/jpeg'];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
