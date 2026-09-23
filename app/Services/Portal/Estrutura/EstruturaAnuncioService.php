<?php

namespace App\Services\Portal\Estrutura;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro de anúncio e a área de espera — o único lugar que grava em
 * `estrutura_anuncios` e `estrutura_anuncios_espera`, fora a colagem (que
 * passa pelas mesmas duas invariantes, ver {@see self::localizar()}).
 *
 * ### As duas invariantes da identidade (ADR §Identidade do anúncio)
 * 1. Com MLB: único na EMPRESA, olhando anúncios E espera juntos.
 * 2. Sem MLB: no máximo um anúncio por (oferta, tipo) — e, na espera, um por
 *    (SKU normalizado, tipo).
 *
 * A segunda é o que deixa a colagem sem MLB determinística: colar de novo
 * "CAD-01 · Clássico" atualiza o mesmo registro em vez de empilhar cópias.
 *
 * ### MLB obrigatório só ao concluir pela agenda
 * A aula documenta o mínimo como SKU + tipo; exigir MLB rejeitaria a colagem
 * que segue a aula ao pé da letra. Mas quem conclui uma publicação acabou de
 * publicar e tem o código na tela — e é ali que um registro sem nome nasceria.
 */
class EstruturaAnuncioService
{
    /**
     * @param  array{tipo: string, status?: string, catalogo?: bool, kit_virtual?: bool, codigo_mlb?: ?string, titulo?: ?string}  $dados
     */
    public function cadastrar(EstruturaOferta $oferta, array $dados, AtorDoPortal $ator, bool $exigirMlb = false): EstruturaAnuncio
    {
        $dados = $this->normalizar($dados, $exigirMlb);
        $empresa = $oferta->company;

        return DB::transaction(function () use ($oferta, $dados, $ator, $empresa) {
            $this->garantirMlbLivre($empresa, $dados['codigo_mlb']);
            $this->garantirUnicoSemMlb($oferta, $dados);

            $anuncio = $oferta->anuncios()->create($dados);

            RegistroEstrutura::registrar($ator, $empresa, $anuncio, 'anuncio_cadastrado',
                "Anúncio {$this->rotulo($anuncio)} cadastrado em {$oferta->sku}");

            return $anuncio;
        });
    }

    public function atualizar(EstruturaAnuncio $anuncio, array $dados, AtorDoPortal $ator): EstruturaAnuncio
    {
        $dados = $this->normalizar($dados, false);
        $oferta = $anuncio->oferta;
        $empresa = $oferta->company;

        return DB::transaction(function () use ($anuncio, $oferta, $dados, $ator, $empresa) {
            $this->garantirMlbLivre($empresa, $dados['codigo_mlb'], ignorarAnuncio: $anuncio->id);
            $this->garantirUnicoSemMlb($oferta, $dados, ignorarAnuncio: $anuncio->id);

            $anuncio->update($dados);

            RegistroEstrutura::registrar($ator, $empresa, $anuncio, 'anuncio_editado',
                "Anúncio {$this->rotulo($anuncio)} editado em {$oferta->sku}");

            return $anuncio;
        });
    }

    public function excluir(EstruturaAnuncio $anuncio, AtorDoPortal $ator): void
    {
        $oferta = $anuncio->oferta;

        RegistroEstrutura::registrar($ator, $oferta->company, $oferta, 'anuncio_excluido',
            "Anúncio {$this->rotulo($anuncio)} excluído de {$oferta->sku}", ['anuncio' => $anuncio->toArray()]);

        $anuncio->delete();
    }

    // ═══ Espera ═════════════════════════════════════════════════════════════

    /** Tira da espera para uma oferta escolhida pela pessoa. */
    public function vincularEspera(EstruturaAnuncioEspera $linha, EstruturaOferta $oferta, AtorDoPortal $ator): EstruturaAnuncio
    {
        return DB::transaction(function () use ($linha, $oferta, $ator) {
            $anuncio = $this->promover($linha, $oferta);

            RegistroEstrutura::registrar($ator, $oferta->company, $anuncio, 'espera_vinculada',
                "Anúncio colado {$this->rotulo($anuncio)} vinculado a {$oferta->sku}");

            return $anuncio;
        });
    }

    public function descartarEspera(EstruturaAnuncioEspera $linha, AtorDoPortal $ator): void
    {
        RegistroEstrutura::registrar($ator, $linha->company, null, 'espera_descartada',
            'Anúncio colado descartado da espera', ['linha' => $linha->toArray()]);

        $linha->delete();
    }

    /**
     * Espera → anúncio da oferta, respeitando a invariante sem MLB: se a oferta
     * já tem um anúncio sem MLB do mesmo tipo, a linha ATUALIZA esse anúncio
     * (é o mesmo registro colado de novo) em vez de criar um segundo.
     *
     * O MLB não precisa ser conferido: ele já era único na empresa enquanto
     * estava na espera, e sair dela não muda isso.
     */
    public function promover(EstruturaAnuncioEspera $linha, EstruturaOferta $oferta): EstruturaAnuncio
    {
        $dados = $linha->dadosDoAnuncio();

        $existente = $dados['codigo_mlb'] === null
            ? $oferta->anuncios()->where('tipo', $dados['tipo'])->whereNull('codigo_mlb')->first()
            : null;

        if ($existente) {
            $existente->update($dados);
            $anuncio = $existente;
        } else {
            $anuncio = $oferta->anuncios()->create($dados);
        }

        $linha->delete();

        return $anuncio;
    }

    /**
     * Guarda na espera respeitando a invariante sem MLB por (SKU, tipo). Usado
     * pela colagem e pela exclusão de oferta (os anúncios dela voltam para cá,
     * como na planilha, onde apagar a linha da Lista deixa a aba Anúncios
     * intacta).
     */
    public function guardarNaEspera(Company $empresa, ?string $sku, string $motivo, array $dados): EstruturaAnuncioEspera
    {
        $dados = [...$dados, 'motivo' => $motivo, 'sku_colado' => $sku === null ? null : trim($sku)];

        $existente = null;

        if ($dados['codigo_mlb'] !== null) {
            $existente = EstruturaAnuncioEspera::where('company_id', $empresa->id)
                ->where('codigo_mlb', $dados['codigo_mlb'])->first();
        } elseif ($sku !== null) {
            $alvo = EstruturaOferta::normalizarSku($sku);
            $existente = EstruturaAnuncioEspera::where('company_id', $empresa->id)
                ->whereNull('codigo_mlb')
                ->where('tipo', $dados['tipo'])
                ->get()
                ->first(fn ($l) => EstruturaOferta::normalizarSku($l->sku_colado) === $alvo);
        }

        if ($existente) {
            $existente->update($dados);

            return $existente;
        }

        return EstruturaAnuncioEspera::create([...$dados, 'company_id' => $empresa->id]);
    }

    // ═══ Identidade ═════════════════════════════════════════════════════════

    /**
     * Onde este MLB já mora na empresa — anúncio ou espera —, ou `null`.
     *
     * @return EstruturaAnuncio|EstruturaAnuncioEspera|null
     */
    public function localizarMlb(Company $empresa, string $mlb): ?object
    {
        $anuncio = EstruturaAnuncio::query()
            ->whereHas('oferta', fn ($q) => $q->where('company_id', $empresa->id))
            ->where('codigo_mlb', $mlb)
            ->first();

        return $anuncio ?? EstruturaAnuncioEspera::where('company_id', $empresa->id)
            ->where('codigo_mlb', $mlb)->first();
    }

    private function garantirMlbLivre(Company $empresa, ?string $mlb, ?int $ignorarAnuncio = null): void
    {
        if ($mlb === null) {
            return;
        }

        $dono = $this->localizarMlb($empresa, $mlb);

        if ($dono === null || ($dono instanceof EstruturaAnuncio && $dono->id === $ignorarAnuncio)) {
            return;
        }

        $onde = $dono instanceof EstruturaAnuncio
            ? 'na oferta '.$dono->oferta->sku
            : 'nos anúncios colados que aguardam oferta';

        throw ValidationException::withMessages([
            'codigo_mlb' => "O anúncio {$mlb} já está cadastrado {$onde}.",
        ]);
    }

    private function garantirUnicoSemMlb(EstruturaOferta $oferta, array $dados, ?int $ignorarAnuncio = null): void
    {
        if ($dados['codigo_mlb'] !== null) {
            return;
        }

        $outro = $oferta->anuncios()
            ->where('tipo', $dados['tipo'])
            ->whereNull('codigo_mlb')
            ->when($ignorarAnuncio, fn ($q) => $q->where('id', '!=', $ignorarAnuncio))
            ->exists();

        if ($outro) {
            $tipo = EstruturaAnuncio::TIPOS[$dados['tipo']];

            throw ValidationException::withMessages([
                'codigo_mlb' => "Esta oferta já tem um anúncio {$tipo} sem código MLB. Informe o MLB para cadastrar outro.",
            ]);
        }
    }

    /**
     * Valida o vocabulário e normaliza. O front manda os valores do model;
     * aqui é a última palavra (na planilha o dropdown aceitava qualquer texto).
     */
    private function normalizar(array $dados, bool $exigirMlb): array
    {
        $erros = [];

        $tipo = $dados['tipo'] ?? null;
        if (! array_key_exists((string) $tipo, EstruturaAnuncio::TIPOS)) {
            $erros['tipo'] = 'Escolha Clássico ou Premium.';
        }

        $status = $dados['status'] ?? EstruturaAnuncio::STATUS_ATIVO;
        if (! array_key_exists((string) $status, EstruturaAnuncio::STATUS)) {
            $erros['status'] = 'Status inválido.';
        }

        $mlbDigitado = trim((string) ($dados['codigo_mlb'] ?? ''));
        $mlb = EstruturaAnuncio::normalizarMlb($mlbDigitado);

        if ($mlbDigitado !== '' && $mlb === null) {
            $erros['codigo_mlb'] = 'O código MLB tem a forma MLB seguido de números (ex.: MLB1234567890).';
        } elseif ($mlb === null && $exigirMlb) {
            $erros['codigo_mlb'] = 'Informe o código MLB do anúncio que você acabou de publicar.';
        }

        if ($erros) {
            throw ValidationException::withMessages($erros);
        }

        $titulo = trim((string) ($dados['titulo'] ?? ''));

        return [
            'tipo'        => $tipo,
            'status'      => $status,
            'catalogo'    => (bool) ($dados['catalogo'] ?? false),
            'kit_virtual' => (bool) ($dados['kit_virtual'] ?? false),
            'codigo_mlb'  => $mlb,
            'titulo'      => $titulo === '' ? null : mb_substr($titulo, 0, 255),
        ];
    }

    private function rotulo(EstruturaAnuncio $anuncio): string
    {
        return EstruturaAnuncio::TIPOS[$anuncio->tipo].($anuncio->codigo_mlb ? " {$anuncio->codigo_mlb}" : ' (sem MLB)');
    }
}
