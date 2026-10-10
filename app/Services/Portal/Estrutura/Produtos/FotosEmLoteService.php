<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProdutoVariacao;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Fotos em lote pelo NOME do arquivo (09/10/2026): `<Ref>_<n>.jpg` é a foto `n` da variação de
 * Ref `<Ref>` — o cliente escolhe a pasta inteira de fotos de uma vez, em vez de abrir produto
 * por produto.
 *
 * ### A regra do nome (a MESMA na prévia e no envio; quem decide é o servidor)
 * - A Ref é casada como em todo o cadastro: sem caixa, acento nem espaço nas pontas
 *   (`ProdutoCadastroService::chaveCodigo`), só dentro da empresa recebida.
 * - O nome inteiro (sem a extensão) que já é uma Ref vence: "MESA_2.jpg" é a 1ª foto da Ref
 *   "MESA_2" quando ela existe. Senão vale a Ref antes do ÚLTIMO "_" e o número depois dele
 *   (a Ref pode ter "_" e "-": "CAD_01_3.jpg" é a 3ª da "CAD_01").
 * - Nome sem número ("MESA.jpg") é a foto 1.
 *
 * ### Ordem e teto
 * As fotos de uma variação entram na ordem do número, DEPOIS das que ela já tem (a `_1` só vira a
 * capa da variação sem foto). O teto por variação (12) é conferido aqui: entra o que cabe, pela
 * ordem; o resto volta como "não coube", com o motivo. Cada variação é gravada pelo MESMO
 * `VariacaoImagensService::enviar` da ficha (disco privado, conteúdo conferido, tudo ou nada POR
 * VARIAÇÃO) — a falha de uma não derruba as outras.
 *
 * ### Sigilo
 * Nenhuma mensagem daqui cita para onde vão as fotos.
 */
class FotosEmLoteService
{
    /** Arquivos por envio (a tela manda em remessas menores, por causa do `post_max_size`). */
    public const MAX_POR_ENVIO = 20;

    /** Nomes por prévia. */
    public const MAX_NOMES = 1000;

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private VariacaoImagensService $imagens) {}

    /**
     * Lê o nome do arquivo: o nome limpo, a extensão e os candidatos [Ref, posição], na ordem de preferência.
     *
     * @return array{nome: string, extensao: string, formato_aceito: bool, candidatos: list<array{0: string, 1: int}>}
     */
    public static function lerNome(string $nome): array
    {
        $limpo = basename(str_replace('\\', '/', $nome));
        $limpo = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $limpo));

        $extensao = '';
        $base = $limpo;
        if (preg_match('/^(.+)\.([A-Za-z0-9]{1,5})$/u', $limpo, $m)) {
            [$base, $extensao] = [trim($m[1]), strtolower($m[2])];
        }

        $candidatos = $base === '' ? [] : [[$base, 1]];
        if (preg_match('/^(.+)_(\d{1,3})$/u', $base, $p) && trim($p[1]) !== '') {
            $candidatos[] = [trim($p[1]), max(1, (int) $p[2])];
        }

        $aceitas = (array) config('estrutura_produtos.imagens.extensoes', ['jpg', 'jpeg', 'png', 'webp']);

        return ['nome' => $limpo, 'extensao' => $extensao, 'formato_aceito' => in_array($extensao, $aceitas, true), 'candidatos' => $candidatos];
    }

    /**
     * A prévia: o que entra em cada variação (na ordem), o que não coube e o que ficou de fora.
     *
     * @param  list<string>  $nomes
     * @return array{variacoes: list<array>, fora: list<array{nome: string, motivo: string}>, entram: int, max_por_variacao: int}
     */
    public function previa(Company $empresa, array $nomes): array
    {
        $variacoes = $this->variacoesDaEmpresa($empresa);
        $max = VariacaoImagensService::maxPorVariacao();

        $grupos = [];
        $fora = [];
        $vistos = [];
        foreach (array_slice($nomes, 0, self::MAX_NOMES) as $nome) {
            $lido = self::lerNome((string) $nome);
            if (isset($vistos[mb_strtolower($lido['nome'])])) {
                $fora[] = ['nome' => $lido['nome'], 'motivo' => 'Nome repetido na escolha: vale o primeiro.'];
                continue;
            }
            $vistos[mb_strtolower($lido['nome'])] = true;

            [$variacao, $posicao, $motivo] = $this->resolver($lido, $variacoes);
            if ($variacao === null) {
                $fora[] = ['nome' => $lido['nome'], 'motivo' => $motivo];
                continue;
            }
            $grupos[$variacao['id']] ??= $variacao + ['arquivos' => []];
            $grupos[$variacao['id']]['arquivos'][] = ['nome' => $lido['nome'], 'posicao' => $posicao];
        }

        $entram = 0;
        $saida = [];
        foreach ($grupos as $g) {
            $arquivos = self::naOrdem($g['arquivos']);
            $cabem = max(0, $max - $g['imagens']);
            foreach ($arquivos as $i => &$a) {
                $a['entra'] = $i < $cabem;
                $a['motivo'] = $a['entra'] ? null : self::motivoNaoCabe($max);
                $entram += $a['entra'] ? 1 : 0;
            }
            unset($a);
            $saida[] = [
                'variacao_id' => $g['id'], 'ref' => $g['codigo'], 'produto' => $g['produto'], 'valor' => $g['valor'],
                'imagens' => $g['imagens'], 'cabem' => $cabem, 'arquivos' => $arquivos,
            ];
        }
        usort($saida, fn ($a, $b) => strnatcasecmp($a['ref'], $b['ref']));

        return ['variacoes' => $saida, 'fora' => $fora, 'entram' => $entram, 'max_por_variacao' => $max];
    }

    /**
     * Grava as fotos recebidas, cada uma na variação que o NOME dela indica, na ordem do número e
     * até o teto. Uma remessa pode trazer fotos de várias variações; cada variação é uma gravação.
     *
     * @param  list<UploadedFile>  $arquivos
     * @return array{resultados: list<array{nome: string, situacao: string, motivo: ?string, ref: ?string}>, enviadas: int, produtos: list<int>}
     */
    public function enviar(Company $empresa, array $arquivos, AtorDoPortal $ator): array
    {
        $variacoes = $this->variacoesDaEmpresa($empresa);
        $max = VariacaoImagensService::maxPorVariacao();
        $maxBytes = (int) config('estrutura_produtos.imagens.max_kb', 10240) * 1024;

        $resultados = [];
        $fora = function (string $nome, string $motivo, ?string $ref = null) use (&$resultados): void {
            $resultados[] = ['nome' => $nome, 'situacao' => 'fora', 'motivo' => $motivo, 'ref' => $ref];
        };

        $grupos = [];
        $vistos = [];
        foreach (array_slice($arquivos, 0, self::MAX_POR_ENVIO) as $arquivo) {
            if (! $arquivo instanceof UploadedFile) {
                continue;
            }
            $lido = self::lerNome((string) $arquivo->getClientOriginalName());
            $nome = $lido['nome'] !== '' ? $lido['nome'] : 'imagem';

            if (! $arquivo->isValid()) {
                $fora($nome, in_array($arquivo->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? VariacaoImagensService::MENSAGEM_GRANDE_DEMAIS
                    : 'Não foi possível receber esta foto. Tente de novo.');
                continue;
            }
            if (isset($vistos[mb_strtolower($nome)])) {
                $fora($nome, 'Nome repetido na escolha: vale o primeiro.');
                continue;
            }
            $vistos[mb_strtolower($nome)] = true;

            [$variacao, $posicao, $motivo] = $this->resolver($lido, $variacoes);
            if ($variacao === null) {
                $fora($nome, $motivo);
                continue;
            }
            if ((int) $arquivo->getSize() > $maxBytes) {
                $fora($nome, VariacaoImagensService::MENSAGEM_GRANDE_DEMAIS, $variacao['codigo']);
                continue;
            }
            // O formato é conferido no CONTEÚDO (o nome pode mentir), arquivo por arquivo: um
            // arquivo ruim não derruba as outras fotos da mesma variação.
            if (! in_array((string) $arquivo->getMimeType(), self::MIMES, true)) {
                $fora($nome, 'Formato de imagem não aceito. Envie JPG, PNG ou WebP.', $variacao['codigo']);
                continue;
            }

            $grupos[$variacao['id']] ??= $variacao + ['arquivos' => []];
            $grupos[$variacao['id']]['arquivos'][] = ['nome' => $nome, 'posicao' => $posicao, 'arquivo' => $arquivo];
        }

        $enviadas = 0;
        $produtos = [];
        foreach ($grupos as $id => $g) {
            $modelo = EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->whereKey($id)->first();
            if (! $modelo) {
                foreach ($g['arquivos'] as $a) {
                    $fora($a['nome'], 'Não achamos mais esta variação. Atualize a página.', $g['codigo']);
                }
                continue;
            }

            // Recontado na hora: outra remessa (ou a ficha) pode ter mandado fotos depois da prévia.
            $cabem = max(0, $max - $modelo->imagens()->count());
            $naOrdem = self::naOrdem($g['arquivos']);
            $entram = array_slice($naOrdem, 0, $cabem);
            foreach (array_slice($naOrdem, $cabem) as $a) {
                $fora($a['nome'], self::motivoNaoCabe($max), $g['codigo']);
            }
            if ($entram === []) {
                continue;
            }

            try {
                $this->imagens->enviar($empresa, $modelo, array_column($entram, 'arquivo'), $ator);
            } catch (ValidationException $e) {
                $motivo = (string) (collect($e->errors())->flatten()->first() ?? 'Não foi possível guardar estas fotos agora.');
                foreach ($entram as $a) {
                    $fora($a['nome'], $motivo, $g['codigo']);
                }
                continue;
            } catch (\Throwable $e) {
                Log::warning("[Estrutura Imagens] fotos em lote: falha ao guardar na variação {$id} da empresa {$empresa->id} ({$empresa->name})", ['erro' => $e->getMessage()]);
                foreach ($entram as $a) {
                    $fora($a['nome'], 'Não foi possível guardar esta foto agora. Tente de novo.', $g['codigo']);
                }
                continue;
            }

            foreach ($entram as $a) {
                $resultados[] = ['nome' => $a['nome'], 'situacao' => 'enviada', 'motivo' => null, 'ref' => $g['codigo']];
            }
            $enviadas += count($entram);
            $produtos[(int) $modelo->produto_id] = true;
        }

        return ['resultados' => $resultados, 'enviadas' => $enviadas, 'produtos' => array_keys($produtos)];
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /**
     * As variações da empresa pela chave da Ref, com o que a prévia mostra. Uma leitura só.
     *
     * @return array<string, array{id: int, codigo: string, produto: string, valor: ?string, imagens: int, produto_id: int}>
     */
    private function variacoesDaEmpresa(Company $empresa): array
    {
        $saida = [];
        EstruturaProdutoVariacao::query()
            ->where('company_id', $empresa->id)
            ->with('produto:id,nome')
            ->withCount('imagens')
            ->orderBy('id')
            ->get(['id', 'produto_id', 'codigo', 'valor'])
            ->each(function (EstruturaProdutoVariacao $v) use (&$saida) {
                $saida[ProdutoCadastroService::chaveCodigo($v->codigo)] ??= [
                    'id'         => (int) $v->id,
                    'codigo'     => (string) $v->codigo,
                    'produto'    => (string) $v->produto?->nome,
                    'valor'      => $v->valor,
                    'imagens'    => (int) $v->imagens_count,
                    'produto_id' => (int) $v->produto_id,
                ];
            });

        return $saida;
    }

    /**
     * A variação e a posição de um nome lido, ou o motivo de ficar de fora.
     *
     * @return array{0: ?array, 1: ?int, 2: ?string}
     */
    private function resolver(array $lido, array $variacoes): array
    {
        if ($lido['nome'] === '' || $lido['candidatos'] === []) {
            return [null, null, 'Arquivo sem nome. Use o nome Ref_número, como MESA-01_1.jpg.'];
        }
        if (! $lido['formato_aceito']) {
            return [null, null, 'Formato não aceito. Envie JPG, PNG ou WebP.'];
        }
        foreach ($lido['candidatos'] as [$ref, $posicao]) {
            $v = $variacoes[ProdutoCadastroService::chaveCodigo($ref)] ?? null;
            if ($v !== null) {
                return [$v, $posicao, null];
            }
        }

        $ref = end($lido['candidatos'])[0];

        return [null, null, "Não achamos a Ref {$ref} nos seus produtos. Confira o nome do arquivo (Ref_número, como MESA-01_1.jpg)."];
    }

    /** Pela posição (o número do nome) e, no empate, pelo nome. */
    private static function naOrdem(array $arquivos): array
    {
        usort($arquivos, fn ($a, $b) => [$a['posicao'], mb_strtolower($a['nome'])] <=> [$b['posicao'], mb_strtolower($b['nome'])]);

        return array_values($arquivos);
    }

    private static function motivoNaoCabe(int $max): string
    {
        return "Não coube: cada variação aceita até {$max} fotos.";
    }
}
