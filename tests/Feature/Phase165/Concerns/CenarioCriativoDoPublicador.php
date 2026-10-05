<?php

namespace Tests\Feature\Phase165\Concerns;

use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubImagem;
use App\Models\User;
use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\Dto\CreativeGenerationRequest;
use App\Services\Creative\Dto\CreativeGenerationResult;
use App\Services\Creative\ReferenciaEfemeraService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\GeradorCombinacoes;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;

/**
 * Cenário compartilhado da Fase 165 (Creative Engine dentro do Publicador):
 * a cadeira de `CenarioCadeira` + a chave do Creative Engine ligada + o
 * dublê de `ImageGenerationProvider`. Base de todos os testes de servidor
 * da fase (planos 165-02 a 165-05).
 */
trait CenarioCriativoDoPublicador
{
    use CenarioCadeira;

    /**
     * @param  string  $ancora  'mlb_empresa' (padrão, loja da Incubadora sem
     *                          Company — T-165-05b) ou 'company'.
     */
    protected function montarCenarioCriativo(string $ancora = 'mlb_empresa'): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->montarCenario($ancora);

        Configuracao::set('creative_engine_ativo', '1');
        // D-26: padrão é NINGUÉM receber foto; quem quiser envio sobrescreve.
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);

        $teste = $this;
        $this->app->instance(ImageGenerationProvider::class, new class($teste) implements ImageGenerationProvider
        {
            private int $n = 0;

            public function __construct(private $teste) {}

            public function gerarImagem(CreativeGenerationRequest $request): CreativeGenerationResult
            {
                $this->n++;

                return new CreativeGenerationResult(
                    bytes: $this->teste::jpeg(1200 + $this->n),
                    mime: 'image/jpeg',
                    modelo: 'dublê',
                    latenciaMs: 1,
                    status: 'sucesso',
                );
            }

            public function gerarTexto(string $prompt): string
            {
                return json_encode([
                    'estrategia' => ['publico' => 'quem compra o produto', 'proposta_de_valor' => 'fiel ao cadastro', 'direcao_visual' => 'fundo neutro'],
                    'slots' => [
                        ['tipo' => 'hero', 'objetivo' => 'capa', 'cena' => 'fundo branco'],
                        ['tipo' => 'white_background', 'objetivo' => 'ângulo 2', 'cena' => 'fundo branco'],
                    ],
                ]);
            }
        });
    }

    /**
     * Bytes de uma imagem fake — guarda o `UploadedFile` numa variável antes
     * de ler o conteúdo (learnings publicador-ml.md §9: ler na mesma linha
     * falha porque o arquivo temporário já foi liberado).
     */
    public static function jpeg(int $lado = 1200): string
    {
        $arquivo = UploadedFile::fake()->image('ref.jpg', $lado, $lado);

        return file_get_contents($arquivo->getPathname());
    }

    /**
     * Uma foto COM arquivo em disco no grupo pedido (galeria geral por
     * padrão) — serve de referência candidata para o Creative Engine.
     */
    protected function fotoComArquivo(string $grupo = 'GENERAL'): PubImagem
    {
        $bytes = self::jpeg();
        $sha = hash('sha256', $bytes);
        $caminho = "publicador/{$this->r->id}/{$sha}.jpg";
        Storage::disk('local')->put($caminho, $bytes);

        $foto = $this->r->imagens()->create([
            'caminho'       => $caminho,
            'sha256'        => $sha,
            'mime'          => 'image/jpeg',
            'bytes'         => strlen($bytes),
            'largura'       => 1200,
            'altura'        => 1200,
            'upload_status' => PubImagem::PENDENTE,
        ]);

        $atuais = $this->repo->snapshot($this->r->fresh())->imagens;
        $doGrupo = array_values(array_filter($atuais, fn ($a) => $a['grupo'] === $grupo));
        $this->repo->gravarAtribuicoes($this->r->fresh(), [
            ...$atuais,
            ['imagem' => $foto->id, 'grupo' => $grupo, 'posicao' => count($doGrupo)],
        ]);

        return $foto->fresh();
    }

    /**
     * Liga a variação de cor (Azul/Preto, `COLOR`, define a foto) no
     * rascunho do cenário e devolve a chave do grupo "Azul" (D-14).
     */
    protected function comVariacaoDeCor(): string
    {
        $eixos = [new Eixo('COLOR', 'Cor', 0, definesPicture: true, valores: [
            new ValorEixo('52028', 'Azul'), new ValorEixo('52049', 'Preto'),
        ])];
        $variantes = array_map(fn ($c) => Variante::daCombinacao($c), GeradorCombinacoes::gerar($eixos));
        $this->repo->gravarVariacao($this->r->fresh(), $eixos, $variantes);

        return ChaveCanonica::combinacao(['COLOR' => 'id:52028']);
    }

    /**
     * O criativo PORTADOR de referência do Publicador — `rascunho_id` NULL,
     * `pub_rascunho_id`/`pub_grupo` preenchidos (D-02).
     */
    protected function portadorDoPublicador(string $grupo, int $fotos = 1): MlAnuncioCriativo
    {
        $criativo = MlAnuncioCriativo::create([
            'token'          => Str::random(32),
            'company_id'     => $this->produto->company_id,
            'mlb_empresa_id' => $this->produto->mlb_empresa_id,
            'rascunho_id'    => null,
            'pub_rascunho_id' => $this->r->id,
            'pub_grupo'      => $grupo,
            'slot'           => 'hero',
            'status'         => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);

        $arquivos = [];
        for ($i = 0; $i < $fotos; $i++) {
            $arquivos[] = UploadedFile::fake()->image("ref-{$i}.jpg");
        }
        $refs = app(ReferenciaEfemeraService::class)->guardar($criativo, $arquivos);
        $criativo->update(['referencias' => $refs]);

        return $criativo->fresh();
    }

    /**
     * Um kit `pronto` do Publicador, com portador + N slots `pronto`
     * (imagem já gerada no disco fake). Os slots NÃO recebem
     * `pub_rascunho_id` próprio — igual ao `PlanejarKitCriativosJob`, que
     * copia só as colunas antigas; eles resolvem pelo kit
     * (`pubRascunhoIdEfetivo()`).
     */
    protected function kitProntoDoPublicador(string $grupo, int $slots = 3): MlAnuncioCriativoKit
    {
        $portador = $this->portadorDoPublicador($grupo);

        $kit = MlAnuncioCriativoKit::create([
            'token'                  => Str::random(32),
            'company_id'             => $this->produto->company_id,
            'mlb_empresa_id'         => $this->produto->mlb_empresa_id,
            'rascunho_id'            => null,
            'pub_rascunho_id'        => $this->r->id,
            'pub_grupo'              => $grupo,
            'criativo_referencia_id' => $portador->id,
            'status'                 => MlAnuncioCriativoKit::STATUS_PRONTO,
            'total_slots'            => $slots,
            'minimo_aprovadas'       => 3,
        ]);

        $portador->update(['kit_id' => $kit->id, 'slot' => 'referencia']);

        $disco = Storage::disk('local');
        for ($i = 1; $i <= $slots; $i++) {
            $token = Str::random(32);
            $caminho = "creative-geradas/{$token}/{$i}.jpg";
            $disco->put($caminho, self::jpeg(1200 + $i));

            MlAnuncioCriativo::create([
                'token'          => $token,
                'company_id'     => $this->produto->company_id,
                'mlb_empresa_id' => $this->produto->mlb_empresa_id,
                'rascunho_id'    => null,
                'kit_id'         => $kit->id,
                'slot_indice'    => $i,
                'slot'           => $i === 1 ? 'hero' : 'white_background',
                'status'         => MlAnuncioCriativo::STATUS_PRONTO,
                'imagem_path'    => $caminho,
                'imagem_mime'    => 'image/jpeg',
            ]);
        }

        return $kit->fresh();
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Token do kit, do portador e de todos os slots — usado pelos testes de
     * "nenhum token de 32 caracteres do Publicador chega ao navegador"
     * (planos 165-04/05, T-165-20).
     *
     * @return list<string>
     */
    protected function tokensDoKit(MlAnuncioCriativoKit $kit): array
    {
        return [
            $kit->token,
            $kit->criativoReferencia?->token,
            ...$kit->slots()->pluck('token')->all(),
        ];
    }
}
