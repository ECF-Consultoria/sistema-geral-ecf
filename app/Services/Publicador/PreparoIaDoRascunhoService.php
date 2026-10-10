<?php

namespace App\Services\Publicador;

use App\Jobs\Publicador\AvaliarCriativosAutomaticosJob;
use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Jobs\Publicador\PreencherRascunhoDoPortalJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaOfertaComponente;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\MlAnuncioIaAnalise;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use App\Support\Publicador\EditorEmUso;
use App\Support\Publicador\MemoriaDoPreparoIa as Memoria;
use App\Support\Publicador\NaFilaDePublicacao;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\RegrasDoTitulo;
use App\Support\Publicador\RegraViolada;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A IA prepara o rascunho ANTES de a equipe precisar dele (pedido do usuário, 09/10/2026): o
 * cliente salva o produto no Portal → segundos depois o produto já está no Publicador
 * (`sincronizarAgora`, 10/10/2026, sem IA) → depois da espera (`PreparoIaAgenda`) este serviço
 * sincroniza SÓ aquele produto de novo (as regras do "Sincronizar do Portal", D-05 refinado) e,
 * com a ficha completa, gera título → Modelo → descrição, cada um no seu Job encadeado.
 *
 * É a EXCEÇÃO consciente do learnings §10 ("a IA deixa no cache e a tela aplica"): aqui não há
 * tela aberta, então a automação grava no rascunho. Por isso as travas:
 * - grava sob a trava do rascunho (`lockForUpdate`), relendo tudo lá dentro;
 * - só num campo VAZIO ou que ainda tem EXATAMENTE o último valor que ela escreveu
 *   (`step_state.ia_escrito`, `MemoriaDoPreparoIa::podeEscrever`) — o que a equipe editou fica;
 * - nunca em rascunho intocável (publicado/publicando), com "Anunciar por IA" rodando, com o
 *   editor do produto em uso (`EditorEmUso`) nem com o produto agendado na fila de publicação
 *   (`NaFilaDePublicacao`, 10/10/2026): aí espera e tenta de novo, com o valor já gerado;
 * - kit/fase criado pelo Publicador (`produto_base_id`) não é preparado (é o fluxo da Fase 175);
 * - nunca escreve estoque (o kit com `estoque_calculado` fica como está).
 *
 * Custo: a IA só roda com categoria e os obrigatórios da ficha da categoria preenchidos no Portal;
 * fatos iguais aos da última geração (hash em `step_state.ia_preparo`) não chamam a IA de novo; e
 * há um teto diário por empresa. `publicador.preparo_ia.ativo = false` desliga tudo.
 */
class PreparoIaDoRascunhoService
{
    public const TITULO = 'titulo';

    public const MODELO = 'modelo';

    public const DESCRICAO = 'descricao';

    public const ETAPAS = [self::TITULO, self::MODELO, self::DESCRICAO];

    // Situação de cada etapa em `ia_preparo.etapas`.
    public const RODANDO = 'rodando';

    public const ADIADO = 'adiado';

    public const OK = 'ok';

    public const PULADO = 'pulado';

    public const ERRO = 'erro';

    public const DESISTIU = 'desistiu';

    public const INTOCAVEL = 'intocavel';

    /** Tipos de anúncio que recebem o título (Clássico e Premium), como no "Anunciar por IA". */
    private const TIPOS_TITULO = ['gold_special', 'gold_pro'];

    /** Prazo da IA dentro do Job (abaixo do `timeout` de 300 s): termina em erro antes de o worker matar. */
    private const PRAZO_S = 240;

    /** Atributos que a própria automação escreve ou que não descrevem o produto: fora do hash. */
    private const FORA_DO_HASH = ['MODEL', 'GTIN', 'SELLER_SKU', 'EMPTY_GTIN_REASON'];

    /** Etapa "rodando"/"adiada" mais velha que isto é Job que morreu sem avisar: pode rodar de novo. */
    private const EM_ANDAMENTO_VALE_MIN = 180;

    public function __construct(
        private PublicadorSincronizaPortalService $sincroniza,
        private PortalParaRascunhoService $portal,
        private ProgramasPublicadorService $programas,
        private PalavrasChaveService $palavras,
        private DescricaoIaService $descricao,
        private CategorySchemaRepository $schemas,
        private RascunhoRepository $repo,
        private PortalProdutoLeitor $leitor,
        private DadosEfetivosService $efetivos,
    ) {}

    // ═══ 1. O Job acordou: sincroniza o produto e decide a IA ════════════════

    /**
     * @return string o que aconteceu (para o log e os testes): desligado, superado, sem_produto,
     *                adiado, desistiu, pronto
     */
    public function preparar(int $companyId, int $estruturaProdutoId, string $marca, int $adiamentos = 0): string
    {
        if (! $this->ativo()) {
            return 'desligado';
        }
        // Debounce: um save mais novo agendou outro Job; este sai sem fazer nada.
        if (PreparoIaAgenda::marcaAtual($estruturaProdutoId) !== $marca) {
            return 'superado';
        }

        $company = Company::find($companyId);
        $produto = $company === null ? null : EstruturaProduto::query()->where('company_id', $company->id)->find($estruturaProdutoId);
        if ($produto === null) {
            return 'sem_produto';
        }

        // Antes de QUALQUER escrita (o Sincronizar também escreve): editor aberto, "Anunciar por IA" rodando
        // ou produto agendado/publicando na fila de publicação (10/10/2026: foi conferido e vai ao ML como
        // está) num rascunho deste produto → tudo espera.
        foreach ($this->pubProdutosDoPortal($company, $produto) as $pub) {
            $r = PubRascunho::where('produto_id', $pub->id)->first();
            if (EditorEmUso::emUso((int) $pub->id) || NaFilaDePublicacao::emUso((int) $pub->id) || ($r !== null && $this->anunciarPorIaRodando($r))) {
                return $this->adiarPreparo($companyId, $estruturaProdutoId, $marca, $adiamentos, (int) $pub->id);
            }
        }

        $alvo = $this->programas->resolver('company-'.$company->id);
        $r = $this->sincroniza->sincronizar($alvo['mlb_empresa'] ?? null, $company, (int) $produto->id);

        foreach ($r['para_preencher'] as $id) {
            $pub = PubProduto::find($id);
            if ($pub !== null) {
                $this->preencher($pub);
            }
        }
        foreach ($r['para_preencher'] as $id) {
            $pub = PubProduto::find($id);
            if ($pub !== null) {
                $situacao = $this->avaliarIa($pub);
                Log::info("[Publicador] Preparo pela IA: produto {$pub->id} ({$pub->nome}) da empresa {$company->id} — {$situacao}.");
                $this->criativosSemCadeia($pub, $situacao);
            }
        }

        return 'pronto';
    }

    /**
     * O produto salvo no Portal chega ao Publicador LOGO (10/10/2026, `SincronizarProdutoDoPortalJob`): as MESMAS
     * travas e o MESMO Sincronizar do `preparar`, sem o debounce e sem a IA (que segue esperando o cliente parar de
     * mexer). Produto com o editor aberto, agendado na fila de publicação ou com "Anunciar por IA" rodando NÃO é
     * tocado agora: o preparo (2 minutos depois do último save) espera e tenta de novo.
     *
     * Um destes por empresa de cada vez (`block`): a planilha que salva 70 produtos agenda 70 deles, e dois
     * Sincronizar do mesmo Combo/Kit ao mesmo tempo esbarrariam nos uniques.
     *
     * @return string desligado, sem_produto, ocupado, em_andamento, sincronizado
     */
    public function sincronizarAgora(int $companyId, int $estruturaProdutoId): string
    {
        if (! $this->ativo()) {
            return 'desligado';
        }
        $company = Company::find($companyId);
        $produto = $company === null ? null : EstruturaProduto::query()->where('company_id', $company->id)->find($estruturaProdutoId);
        if ($produto === null) {
            return 'sem_produto';
        }
        foreach ($this->pubProdutosDoPortal($company, $produto) as $pub) {
            $r = PubRascunho::where('produto_id', $pub->id)->first();
            if (EditorEmUso::emUso((int) $pub->id) || NaFilaDePublicacao::emUso((int) $pub->id) || ($r !== null && $this->anunciarPorIaRodando($r))) {
                Log::info("[Publicador] Produto {$produto->id} do Portal não foi sincronizado logo após o save: o produto {$pub->id} está em uso no Publicador (o preparo tenta de novo).");

                return 'ocupado';
            }
        }

        $trava = Cache::lock("publicador:sincronizar-agora:company:{$company->id}", 300);
        try {
            $trava->block(120);
        } catch (LockTimeoutException) {
            Log::info("[Publicador] Produto {$produto->id} do Portal: outro Sincronizar da empresa {$company->id} seguiu ocupado; o preparo sincroniza depois.");

            return 'em_andamento';
        }
        try {
            $alvo = $this->programas->resolver('company-'.$company->id);
            $r = $this->sincroniza->sincronizar($alvo['mlb_empresa'] ?? null, $company, (int) $produto->id);
            foreach ($r['para_preencher'] as $id) {
                $pub = PubProduto::find($id);
                if ($pub !== null) {
                    $this->preencher($pub);
                }
            }
        } finally {
            $trava->release();
        }
        Log::info("[Publicador] Produto {$produto->id} ({$produto->nome}) do Portal sincronizado com o Publicador logo após o save (empresa {$company->id}).");

        return 'sincronizado';
    }

    /**
     * 10/10/2026 — imagens por IA automáticas sem cadeia de texto: o texto já estava em dia (ou a cota de texto
     * acabou), mas as fotos podem ter chegado agora. Só com o gatilho ligado para a empresa; o Job confere TUDO de
     * novo (`CriativosAutomaticosService::avaliar`). Incompleto, intocável e kit da Fase N nem entram.
     */
    private function criativosSemCadeia(PubProduto $pub, string $situacao): void
    {
        if (! in_array($situacao, ['em_dia', 'limite'], true) || ! $this->criativos()->ligadoPara($pub->company_id !== null ? (int) $pub->company_id : null)) {
            return;
        }
        $rascunhoId = PubRascunho::where('produto_id', $pub->id)->value('id');
        if ($rascunhoId !== null) {
            AvaliarCriativosAutomaticosJob::dispatch((int) $rascunhoId);
        }
    }

    /** Resolvido só quando precisa: o serviço de imagens lê a `fichaCompleta` DAQUI (sem ciclo no construtor). */
    private function criativos(): CriativosAutomaticosService
    {
        return app(CriativosAutomaticosService::class);
    }

    /**
     * Decide se a IA prepara o rascunho deste produto e, se sim, encadeia as etapas.
     *
     * @return string kit_da_fase, sem_rascunho, intocavel, incompleto, em_dia, limite, desligado, gerando
     */
    public function avaliarIa(PubProduto $pub): string
    {
        if (! $this->ativo()) {
            return 'desligado';
        }
        // Kit/fase N criado pelo Publicador (Fase 175): o texto dele tem fluxo próprio.
        if ($pub->produto_base_id !== null) {
            return 'kit_da_fase';
        }
        $r = PubRascunho::where('produto_id', $pub->id)->first();
        if ($r === null) {
            return 'sem_rascunho';
        }
        if (IaParaRascunhoService::intocavel($r)) {
            return 'intocavel';
        }
        if (! $r->categoria_id || ! $this->fichaCompleta($pub)) {
            return 'incompleto';
        }

        $hash = $this->hashDosFatos($r);
        $etapas = $this->etapasAFazer((array) ($this->lerEstado($r->id)[Memoria::PREPARO] ?? []), $hash);
        if ($etapas === []) {
            return 'em_dia';
        }
        if (! $this->consumirCota((int) $pub->company_id)) {
            Log::warning("[Publicador] Preparo pela IA: limite diário de gerações da empresa {$pub->company_id} atingido; o produto {$pub->id} foi só sincronizado.");

            return 'limite';
        }

        $this->comEstado($r->id, function (array $estado) use ($hash, $etapas) {
            $antes = (array) ($estado[Memoria::PREPARO] ?? []);
            $mesmo = ($antes['hash'] ?? null) === $hash;
            $situacao = $mesmo ? (array) ($antes['etapas'] ?? []) : [];
            foreach ($etapas as $e) {
                $situacao[$e] = ['status' => self::RODANDO, 'em' => now()->toIso8601String()];
            }
            $estado[Memoria::PREPARO] = [
                'hash' => $hash,
                'em' => now()->toIso8601String(),
                // O título gerado vale para o Modelo enquanto os fatos forem os mesmos.
                'titulo_gerado' => $mesmo && ! in_array(self::TITULO, $etapas, true) ? ($antes['titulo_gerado'] ?? null) : null,
                'etapas' => $situacao,
            ];

            return $estado;
        });

        $jobs = [];
        foreach ($etapas as $i => $e) {
            $jobs[] = new GerarPreparoIaJob((int) $r->id, $e, $hash, restantes: array_values(array_slice($etapas, $i + 1)));
        }
        // 10/10/2026 — imagens por IA automáticas (DESLIGADAS por padrão): com o texto pronto, o último elo avalia.
        if ($this->criativos()->ligadoPara($pub->company_id !== null ? (int) $pub->company_id : null)) {
            $jobs[] = new AvaliarCriativosAutomaticosJob((int) $r->id);
        }
        Bus::chain($jobs)->onQueue('default')->dispatch();

        return 'gerando ('.implode(', ', $etapas).')';
    }

    // ═══ 2. Uma etapa da IA ══════════════════════════════════════════════════

    /**
     * Gera (ou usa o valor já gerado de uma escrita adiada) e grava sob as travas. A falha da IA
     * NÃO lança: fica registrada na etapa e a cadeia segue.
     *
     * @return string desligado, sem_rascunho, superado, intocavel, pulado, erro, adiado, desistiu,
     *                escrito, mantido, igual, sem_campo
     */
    public function executarEtapa(int $rascunhoId, string $etapa, string $hash, ?string $valorPronto = null, int $adiamentos = 0): string
    {
        if (! $this->ativo()) {
            return 'desligado';
        }
        $r = PubRascunho::find($rascunhoId);
        if ($r === null) {
            return 'sem_rascunho';
        }
        $preparo = (array) ($this->lerEstado($r->id)[Memoria::PREPARO] ?? []);
        // Outro preparo, com fatos mais novos, já começou: esta cadeia ficou velha.
        if (($preparo['hash'] ?? null) !== $hash) {
            return 'superado';
        }
        if (IaParaRascunhoService::intocavel($r)) {
            $this->marcarEtapa($r->id, $hash, $etapa, self::INTOCAVEL);

            return self::INTOCAVEL;
        }

        $valor = $valorPronto;
        if ($valor === null) {
            try {
                $valor = $this->gerar($r, $etapa, $preparo);
            } catch (\Throwable $e) {
                Log::warning("[Publicador] Preparo pela IA ({$etapa}) falhou no rascunho {$r->id}: ".$e->getMessage());
                $this->marcarEtapa($r->id, $hash, $etapa, self::ERRO);
                if ($etapa === self::DESCRICAO) {
                    // Sem descrição gerada, o automático do editor (D-11) volta a ter a sua chance.
                    Cache::forget(DescricaoIaService::chaveAuto($r->id));
                }

                return self::ERRO;
            }
            if ($valor === null) {
                $this->marcarEtapa($r->id, $hash, $etapa, self::PULADO);

                return self::PULADO;
            }
            if ($etapa === self::TITULO) {
                // O Modelo usa os títulos GERADOS, mesmo que a escrita deles espere o editor fechar.
                $gerados = self::titulosDoValor($valor);
                $this->comEstado($r->id, function (array $estado) use ($hash, $gerados) {
                    if (($estado[Memoria::PREPARO]['hash'] ?? null) === $hash) {
                        $estado[Memoria::PREPARO]['titulo_gerado'] = $gerados;
                    }

                    return $estado;
                });
            }
        }

        // Nunca por trás de gente: editor do produto em uso, "Anunciar por IA" gravando ou o produto na fila de
        // publicação (conferido; uma escrita agora o tiraria da fila como `precisa_revisar`) → espera.
        if (EditorEmUso::emUso((int) $r->produto_id) || NaFilaDePublicacao::emUso((int) $r->produto_id) || $this->anunciarPorIaRodando($r)) {
            if ($adiamentos >= $this->maxAdiamentos()) {
                Log::info("[Publicador] Preparo pela IA ({$etapa}): o rascunho {$r->id} seguiu em uso; a escrita desistiu depois de {$adiamentos} espera(s).");
                $this->marcarEtapa($r->id, $hash, $etapa, self::DESISTIU);

                return self::DESISTIU;
            }
            $this->marcarEtapa($r->id, $hash, $etapa, self::ADIADO);
            GerarPreparoIaJob::dispatch($r->id, $etapa, $hash, $valor, $adiamentos + 1)
                ->delay(now()->addMinutes($this->adiarMin()));

            return self::ADIADO;
        }

        $resultado = $this->escrever($r->id, $etapa, $valor);
        $this->marcarEtapa($r->id, $hash, $etapa, $resultado === self::INTOCAVEL ? self::INTOCAVEL : self::OK);
        Log::info("[Publicador] Preparo pela IA ({$etapa}) no rascunho {$r->id}: {$resultado}.");

        return $resultado;
    }

    /** Registra como a etapa terminou — só se o preparo ainda for o destes fatos. */
    public function marcarEtapa(int $rascunhoId, string $hash, string $etapa, string $status): void
    {
        $this->comEstado($rascunhoId, function (array $estado) use ($hash, $etapa, $status) {
            if (($estado[Memoria::PREPARO]['hash'] ?? null) !== $hash) {
                return $estado;
            }
            $estado[Memoria::PREPARO]['etapas'][$etapa] = ['status' => $status, 'em' => now()->toIso8601String()];

            return $estado;
        });
    }

    /** @return ?string o valor gerado; null = nada a gerar (nenhum campo livre, ou Modelo sem título nenhum) */
    private function gerar(PubRascunho $r, string $etapa, array $preparo): ?string
    {
        // Custo: campo que a equipe já preencheu não recebe nada — então nem se chama a IA. A decisão
        // que vale é a da escrita, sob a trava; esta é só a economia.
        $planejados = $this->planejados($r, $etapa);
        $livres = $this->livres($r, $etapa, $this->lerEstado($r->id), $planejados);
        if ($livres === []) {
            return null;
        }
        $prazo = microtime(true) + self::PRAZO_S;

        switch ($etapa) {
            case self::TITULO:
                // Dois títulos DIFERENTES (09/10/2026: o ML barra dois anúncios com o mesmo nome), e
                // nenhum igual ao título que a equipe já deu ao outro tipo.
                $titulos = $this->palavras->gerarTitulos($r, $prazo, $this->titulosFixos($r, $livres, $planejados));
                if (array_filter($titulos) === []) {
                    throw new \RuntimeException('A IA não devolveu um título aproveitável.');
                }

                return json_encode($titulos, JSON_UNESCAPED_UNICODE);

            case self::MODELO:
                // O Modelo depende do título: os gerados agora, ou o que o rascunho já tem.
                $gerado = $preparo['titulo_gerado'] ?? null;
                $gerado = is_array($gerado) ? array_values(array_unique(array_filter(array_map('strval', $gerado)))) : (is_string($gerado) ? $gerado : null);
                $gerado = $gerado === [] ? null : $gerado;
                $temTitulo = $gerado !== null || $r->alvos()->where('ativo', true)->whereNotNull('titulo')->where('titulo', '<>', '')->exists();
                if (! $temTitulo) {
                    return null;
                }

                return $this->palavras->gerarModelo($r, $prazo, $gerado)['valor'];

            case self::DESCRICAO:
                // O disparo automático do editor (D-11) não gera de novo: a chance dele fica gasta.
                Cache::put(DescricaoIaService::chaveAuto($r->id), true, DescricaoIaService::TTL_AUTOMATICO);

                return $this->descricao->gerar($r, $prazo);
        }

        throw new \InvalidArgumentException("Etapa desconhecida: {$etapa}");
    }

    // ═══ 3. A escrita (a única) ══════════════════════════════════════════════

    /**
     * Grava UM campo sob a trava do rascunho. Escreve só no vazio ou por cima do último valor que a
     * automação escreveu ali (`livres`, com `MemoriaDoPreparoIa::podeEscrever`); o resto é "mantido".
     *
     * @return string escrito, mantido, igual, intocavel, sem_campo
     */
    private function escrever(int $rascunhoId, string $etapa, string $valor): string
    {
        // Fora da trava: o schema pode ir ao ML; o título planejado na aba Anúncios é leitura de banco.
        $r = PubRascunho::find($rascunhoId);
        if ($etapa === self::MODELO && ! $this->categoriaTemModelo($r)) {
            return 'sem_campo';
        }
        $planejados = $r !== null ? $this->planejados($r, $etapa) : [];

        return DB::transaction(function () use ($rascunhoId, $etapa, $valor, $planejados) {
            $r = PubRascunho::whereKey($rascunhoId)->lockForUpdate()->first();
            if (! $r || IaParaRascunhoService::intocavel($r)) {
                return self::INTOCAVEL;
            }
            $estado = $this->lerEstado($r->id);
            $escrito = (array) ($estado[Memoria::ESCRITO] ?? []);
            $snap = $this->repo->snapshot($r);
            $livres = $this->livres($r, $etapa, $estado, $planejados, $snap);
            $gravou = false;

            if ($etapa === self::TITULO) {
                $titulos = [];
                $atuais = collect($snap->alvos)->mapWithKeys(fn ($a) => [$a->listingTypeId => (string) ($a->titulo ?? '')]);
                // Última guarda antes de gravar (a equipe pode ter escrito o outro tipo depois da IA):
                // nunca dois iguais — o que não dá para diferenciar fica sem escrever.
                $gerados = self::titulosDoValor($valor);
                $finais = RegrasDoTitulo::semRepetir(
                    array_intersect_key(array_merge(array_fill_keys(self::TIPOS_TITULO, ''), $gerados), array_flip($livres)),
                    $this->titulosFixos($r, $livres, $planejados, $snap), [], 255,
                );
                foreach ($livres as $tipo) {
                    $novo = $finais[$tipo] ?? '';
                    if ($novo === '') {
                        continue;
                    }
                    if ($atuais[$tipo] !== $novo) {
                        $titulos[$tipo] = $novo;
                    }
                    $escrito[Memoria::chaveDoTitulo($tipo)] = $novo;
                }
                if ($titulos !== []) {
                    $this->repo->gravarTitulos($r, $titulos);
                    $gravou = true;
                }
            } elseif ($etapa === self::MODELO && $livres !== []) {
                if ((string) ($snap->atributos['MODEL']['value_name'] ?? '') !== $valor) {
                    // Formato do editor (`aplicarPalavras` em usePublicador): texto em value_name, origem 'ia'.
                    $this->repo->mesclarAtributos($r, ['MODEL' => ['value_id' => null, 'value_name' => $valor, 'origem' => 'ia', 'revisar' => false]]);
                    $gravou = true;
                }
                $escrito[Memoria::MODELO] = $valor;
            } elseif ($etapa === self::DESCRICAO && $livres !== []) {
                if ((string) ($r->descricao ?? '') !== $valor) {
                    $r->update(['descricao' => $valor]);
                    $gravou = true;
                }
                $escrito[Memoria::DESCRICAO] = $valor;
            }

            if ($gravou) {
                // Conteúdo mudou: a revisão sobe e a conferência anterior deixa de valer.
                $this->repo->tocar($r);
            }
            // A memória vai direto na linha travada (como `portal_escrito`), relida aqui dentro.
            $estado = $this->lerEstado($r->id);
            $estado[Memoria::ESCRITO] = $escrito;
            $this->gravarEstado($r->id, $estado);

            return $gravou ? 'escrito' : ($livres === [] ? 'mantido' : 'igual');
        });
    }

    /**
     * Os campos desta etapa que a automação PODE escrever agora: vazios, ou ainda com o último valor
     * que ela escreveu. Título: os tipos ativos (Clássico/Premium) livres — o título planejado na aba
     * Anúncios do Portal conta como preenchido. Modelo: só sem opção/"Não se aplica" (`value_id`).
     *
     * @param  array<string, mixed>  $estado  o `step_state` lido agora
     * @param  array<string, ?string>  $planejados  listing_type_id → título planejado (efetivo)
     * @return list<string> tipos de anúncio (título), ['MODEL'] ou ['descricao']; vazio = nada livre
     */
    private function livres(PubRascunho $r, string $etapa, array $estado, array $planejados, ?RascunhoSnapshot $snap = null): array
    {
        $escrito = (array) ($estado[Memoria::ESCRITO] ?? []);
        $ultimo = fn (string $chave) => is_string($escrito[$chave] ?? null) ? $escrito[$chave] : null;

        if ($etapa === self::DESCRICAO) {
            return Memoria::podeEscrever((string) ($r->descricao ?? ''), $ultimo(Memoria::DESCRICAO)) ? ['descricao'] : [];
        }

        $snap ??= $this->repo->snapshot($r);
        if ($etapa === self::MODELO) {
            $atual = $snap->atributos['MODEL'] ?? null;
            // Opção escolhida ou "Não se aplica" (`value_id`) é preenchimento de gente.
            if ($atual !== null && trim((string) ($atual['value_id'] ?? '')) !== '') {
                return [];
            }

            return Memoria::podeEscrever((string) ($atual['value_name'] ?? ''), $ultimo(Memoria::MODELO)) ? ['MODEL'] : [];
        }

        $tipos = [];
        foreach ($snap->alvos as $alvo) {
            if (! in_array($alvo->listingTypeId, self::TIPOS_TITULO, true) || ! $alvo->ativo) {
                continue;
            }
            $atual = (string) ($alvo->titulo ?? '');
            if (trim($atual) === '' && trim((string) ($planejados[$alvo->listingTypeId] ?? '')) !== '') {
                continue;
            }
            if (Memoria::podeEscrever($atual, $ultimo(Memoria::chaveDoTitulo($alvo->listingTypeId)))) {
                $tipos[] = $alvo->listingTypeId;
            }
        }

        return $tipos;
    }

    /**
     * Os títulos dos tipos ativos que a automação NÃO vai escrever (da equipe, ou o planejado na aba
     * Anúncios): o título gerado não pode repetir nenhum deles.
     *
     * @param  list<string>  $livres
     * @param  array<string, ?string>  $planejados
     * @return array<string, string> listing_type_id → título
     */
    private function titulosFixos(PubRascunho $r, array $livres, array $planejados, ?RascunhoSnapshot $snap = null): array
    {
        $snap ??= $this->repo->snapshot($r);
        $fixos = [];
        foreach ($snap->alvos as $alvo) {
            if (! $alvo->ativo || ! in_array($alvo->listingTypeId, self::TIPOS_TITULO, true) || in_array($alvo->listingTypeId, $livres, true)) {
                continue;
            }
            $titulo = trim((string) ($alvo->titulo ?? '')) ?: trim((string) ($planejados[$alvo->listingTypeId] ?? ''));
            if ($titulo !== '') {
                $fixos[$alvo->listingTypeId] = $titulo;
            }
        }

        return $fixos;
    }

    /**
     * O valor da etapa do título → listing_type_id → título. É JSON (`gerarTitulos`); texto puro é
     * de um Job adiado antes de 09/10/2026, quando havia um título só para os dois tipos.
     *
     * @return array<string, string>
     */
    private static function titulosDoValor(string $valor): array
    {
        $mapa = json_decode($valor, true);
        if (is_array($mapa)) {
            return array_map(fn ($t) => trim((string) $t), array_intersect_key($mapa, array_flip(self::TIPOS_TITULO)));
        }

        return array_fill_keys(self::TIPOS_TITULO, trim($valor));
    }

    /** @return array<string, ?string> os títulos planejados na aba Anúncios (só para o título) */
    private function planejados(PubRascunho $r, string $etapa): array
    {
        return $etapa === self::TITULO && $r->produto !== null ? (array) ($this->efetivos->daProduto($r->produto)['titulos'] ?? []) : [];
    }

    // ═══ Regras de entrada ═══════════════════════════════════════════════════

    /**
     * A ficha do Portal tem categoria e todos os OBRIGATÓRIOS da categoria preenchidos? Grupo: o
     * próprio produto. Combo/Kit/Combit: todos os componentes. A definição é a mesma da ficha que o
     * cliente vê (`FichaTecnicaDaCategoria`, com o eixo do produto fora), montada do schema que o
     * Publicador já guarda. O Modelo não conta: é ele que a IA vai gerar.
     */
    public function fichaCompleta(PubProduto $pub): bool
    {
        $ids = $this->produtosDoPortal($pub);
        if ($ids === []) {
            return false;
        }

        foreach ($ids as $id) {
            $produto = EstruturaProduto::query()->where('company_id', $pub->company_id)->find($id);
            $categoria = trim((string) $produto?->categoria_ml_id);
            if ($produto === null || $categoria === '') {
                return false;
            }
            try {
                $schema = $this->schemas->obter($categoria);
            } catch (RegraViolada) {
                return false;
            }
            $grupos = FichaTecnicaDaCategoria::doProduto(FichaTecnicaDaCategoria::daAtributos($schema->atributos), FichaTecnicaDoProduto::eixosDoProduto($produto));
            $salvos = EstruturaProdutoAtributo::query()->where('company_id', $produto->company_id)->where('produto_id', $produto->id)
                ->get()->keyBy('atributo_id');
            foreach (FichaTecnicaDaCategoria::camposPorId($grupos) as $campoId => $campo) {
                if (empty($campo['obrigatorio']) || $campoId === 'MODEL') {
                    continue;
                }
                $s = $salvos->get($campoId);
                $valorId = trim((string) $s?->valor_id);
                $preenchido = $s !== null && (trim((string) $s->valor) !== '' || ($valorId !== '' && $valorId !== FichaTecnicaDoProduto::NAO_SE_APLICA));
                if (! $preenchido) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * O hash dos FATOS que a IA usa: nome, categoria, ficha e medidas do rascunho (fora o que a
     * própria automação escreve), as cores das variantes ativas e a descrição do cliente.
     */
    public function hashDosFatos(PubRascunho $r): string
    {
        $atributos = $r->atributos()->orderBy('attribute_id')->get()
            ->reject(fn ($a) => in_array($a->attribute_id, self::FORA_DO_HASH, true))
            ->map(fn ($a) => [$a->attribute_id, $a->value_id, $a->value_name, $a->value_number === null ? null : (float) $a->value_number, $a->value_unit, $a->values_multi])
            ->values()->all();
        $cores = $this->palavras->coresDoRascunho($r);
        sort($cores);

        return sha1((string) json_encode([
            'nome' => $r->produto?->nomeExibido(),
            'categoria' => $r->categoria_id,
            'atributos' => $atributos,
            'cores' => $cores,
            'cliente' => $r->produto !== null ? $this->leitor->descricaoDoCliente($r->produto) : null,
            // O Modelo que o cliente gravou no Portal antes de o campo sair da ficha: é fato para a IA.
            'modelo_do_cliente' => $r->produto !== null ? $this->leitor->modeloDoCliente($r->produto) : null,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * As etapas que faltam para estes fatos. Fatos novos = as três. Os mesmos: só as que erraram
     * ou desistiram (e o Modelo junto com um título refeito); em andamento recente não repete.
     *
     * @return list<string>
     */
    private function etapasAFazer(array $preparo, string $hash): array
    {
        if (($preparo['hash'] ?? null) !== $hash) {
            return self::ETAPAS;
        }

        $fazer = [];
        foreach (self::ETAPAS as $e) {
            $s = (array) ($preparo['etapas'][$e] ?? []);
            $status = $s['status'] ?? null;
            $recente = isset($s['em']) && now()->diffInMinutes(Carbon::parse($s['em']), true) < self::EM_ANDAMENTO_VALE_MIN;
            if (in_array($status, [self::OK, self::PULADO, self::INTOCAVEL], true) || (in_array($status, [self::RODANDO, self::ADIADO], true) && $recente)) {
                continue;
            }
            $fazer[] = $e;
        }
        if (in_array(self::TITULO, $fazer, true) && ! in_array(self::MODELO, $fazer, true)) {
            $s = (array) ($preparo['etapas'][self::MODELO] ?? []);
            if (! in_array($s['status'] ?? null, [self::RODANDO, self::ADIADO], true)) {
                $fazer[] = self::MODELO;
            }
        }

        return array_values(array_filter(self::ETAPAS, fn ($e) => in_array($e, $fazer, true)));
    }

    /** Os ids dos produtos do Portal por trás do pub_produto: o do grupo, ou os componentes da composta. @return list<int> */
    private function produtosDoPortal(PubProduto $pub): array
    {
        if ($pub->estrutura_produto_id !== null) {
            return [(int) $pub->estrutura_produto_id];
        }
        if ($pub->oferta_id === null) {
            return [];
        }
        $oferta = EstruturaOferta::query()->where('company_id', $pub->company_id)
            ->whereIn('fase', [EstruturaOferta::FASE_COMBO, EstruturaOferta::FASE_KIT, EstruturaOferta::FASE_COMBIT])
            ->with('componentes.componente.variacao')->find($pub->oferta_id);
        if ($oferta === null) {
            return [];
        }
        $ids = [];
        foreach ($oferta->componentes as $c) {
            $variacao = $c->componente?->variacao;
            if ($variacao === null || (int) $variacao->company_id !== (int) $pub->company_id) {
                return [];
            }
            $ids[(int) $variacao->produto_id] = true;
        }

        return array_keys($ids);
    }

    /**
     * Os pub_produtos que já existem para um produto do Portal: o grupo e as compostas que o têm
     * como componente.
     *
     * @return list<PubProduto>
     */
    private function pubProdutosDoPortal(Company $company, EstruturaProduto $produto): array
    {
        $grupo = PubProduto::query()->where('company_id', $company->id)->where('estrutura_produto_id', $produto->id)->get()->all();
        $simples = EstruturaOferta::query()->where('company_id', $company->id)
            ->whereIn('variacao_id', $produto->variacoes()->select('id'))->pluck('id');
        $compostas = $simples->isEmpty() ? collect() : EstruturaOfertaComponente::query()
            ->whereIn('componente_id', $simples)->pluck('oferta_id');
        $dasCompostas = $compostas->isEmpty() ? [] : PubProduto::query()->where('company_id', $company->id)
            ->whereIn('oferta_id', $compostas)->get()->all();

        return [...$grupo, ...$dasCompostas];
    }

    /** "Anunciar por IA" em andamento para este rascunho? Ele grava o rascunho inteiro. */
    private function anunciarPorIaRodando(PubRascunho $r): bool
    {
        return MlAnuncioIaAnalise::query()
            ->whereIn('status', MlAnuncioIaAnalise::STATUS_EM_ANDAMENTO)
            ->where('created_at', '>=', now()->subMinutes(MlAnuncioIaAnalise::LIMITE_MINUTOS))
            ->get()
            ->contains(function (MlAnuncioIaAnalise $a) use ($r) {
                $d = $a->destinoPublicador();

                return $d !== null && ((int) ($d['rascunho_id'] ?? 0) === (int) $r->id || (int) ($d['produto_id'] ?? 0) === (int) $r->produto_id);
            });
    }

    private function categoriaTemModelo(?PubRascunho $r): bool
    {
        if ($r === null || ! $r->categoria_id) {
            return false;
        }
        try {
            $schema = $this->schemas->obter((string) $r->categoria_id);
        } catch (RegraViolada) {
            return false;
        }

        return collect($schema->atributos)->contains(fn ($a) => is_array($a) && ($a['id'] ?? null) === 'MODEL');
    }

    /** Preenche o rascunho pelo Portal com a MESMA trava do Job do botão (nunca dois ao mesmo tempo). */
    private function preencher(PubProduto $pub): void
    {
        $trava = Cache::lock(PreencherRascunhoDoPortalJob::chaveDaTrava((int) $pub->id), 330);
        if (! $trava->get()) {
            Log::info("[Publicador] Preparo pela IA: o produto {$pub->id} já estava sendo preenchido por um Sincronizar; segue com o que houver.");

            return;
        }
        try {
            $this->portal->preencher($pub);
        } finally {
            $trava->release();
        }
    }

    private function adiarPreparo(int $companyId, int $estruturaProdutoId, string $marca, int $adiamentos, int $pubId): string
    {
        if ($adiamentos >= $this->maxAdiamentos()) {
            Log::info("[Publicador] Preparo pela IA: o editor do produto {$pubId} seguiu em uso; o preparo do produto {$estruturaProdutoId} do Portal desistiu (o próximo save recomeça).");

            return self::DESISTIU;
        }
        PrepararProdutoNoPublicadorJob::dispatch($companyId, $estruturaProdutoId, $marca, $adiamentos + 1)
            ->delay(now()->addMinutes($this->adiarMin()));

        return self::ADIADO;
    }

    /** Uma preparação a mais no dia da empresa; false = passou do teto. */
    private function consumirCota(int $companyId): bool
    {
        $limite = (int) config('publicador.preparo_ia.limite_diario_por_empresa', 60);
        if ($limite <= 0) {
            return false;
        }
        $chave = "publicador:preparo:cota:{$companyId}:".now()->format('Y-m-d');
        Cache::add($chave, 0, now()->addDays(2));

        return (int) Cache::increment($chave) <= $limite;
    }

    // ═══ step_state (sempre relido do banco; gravado sem subir a revisão) ════

    private function lerEstado(int $rascunhoId): array
    {
        return json_decode((string) DB::table('pub_rascunhos')->where('id', $rascunhoId)->value('step_state'), true) ?: [];
    }

    private function gravarEstado(int $rascunhoId, array $estado): void
    {
        DB::table('pub_rascunhos')->where('id', $rascunhoId)->update(['step_state' => json_encode($estado, JSON_UNESCAPED_UNICODE)]);
    }

    /** Lê-muda-grava o `step_state` sob a trava da linha: duas etapas não se apagam. */
    private function comEstado(int $rascunhoId, callable $mudar): void
    {
        DB::transaction(function () use ($rascunhoId, $mudar) {
            PubRascunho::whereKey($rascunhoId)->lockForUpdate()->value('id');
            $this->gravarEstado($rascunhoId, $mudar($this->lerEstado($rascunhoId)));
        });
    }

    private function ativo(): bool
    {
        return (bool) config('publicador.preparo_ia.ativo', true);
    }

    private function maxAdiamentos(): int
    {
        return max(0, (int) config('publicador.preparo_ia.max_adiamentos', 24));
    }

    private function adiarMin(): int
    {
        return max(1, (int) config('publicador.preparo_ia.adiar_min', 5));
    }
}
