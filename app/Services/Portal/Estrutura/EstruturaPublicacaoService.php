<?php

namespace App\Services\Portal\Estrutura;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPublicacao;
use App\Services\MercadoLivreService;
use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use App\Services\Mlb\Publicacao\MlImagemService;
use App\Services\Mlb\Publicacao\MlItemPayloadValidator;
use App\Services\Mlb\Publicacao\MlPublicacaoService;
use App\Services\MlColetaService;
use App\Services\Publicador\CategoriaBuscaService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Anunciar do Mapeamento Estrutural: publicar o par Clássico + Premium de
 * uma oferta no Mercado Livre, pelo Portal do Cliente. ADR PORTAL-03.
 *
 * Compõe as peças do motor do módulo admin sem tocá-las: o builder do modelo
 * da conta (`MlPublicacaoService::builderPara`), o `post()` autenticado do
 * `MercadoLivreService`, o upload do `MlImagemService`, os metadados do
 * `MlCatalogoMetaService` e a tradução do `MlItemPayloadValidator`. Não usa
 * `MlPublicacaoService::validar()`/`publicar()`: eles recebem
 * `MlAnuncioRascunho`, que exige `user_id` de `users`.
 *
 * ### Dados efetivos
 * O rascunho guarda o que a pessoa digitou. Título vazio de um tipo vale o
 * título planejado daquele tipo na aba Anúncios; preço vazio vale o
 * `anunciado` da Precificação (`PrecificacaoEstrutura`, no PHP). Conferência
 * e publicação SEMPRE trabalham sobre os dados efetivos — e o hash gravado
 * na conferência é deles, então mudar o preço na Precificação exige conferir
 * de novo.
 *
 * ### Nunca duas vezes
 * A transição para `publicando` é um UPDATE condicional que precisa afetar
 * exatamente 1 linha; cada MLB é gravado no instante em que o ML devolve o
 * id; tipo com código nunca é reenviado. Ver o ADR, §Nunca publicar duas vezes.
 */
class EstruturaPublicacaoService
{
    private const API_BASE = 'https://api.mercadolibre.com';

    public const POR_PAGINA = 25;

    public const FILTROS = ['a_anunciar', 'publicados'];

    /** Depois disso uma trava `publicando` órfã (request que morreu) pode ser retomada. */
    public const PUBLICANDO_VENCE_MINUTOS = 15;

    /** Quantas fotos o formulário aceita (o ML aceita mais; 12 já é "ideal 6+" com folga). */
    public const MAX_FOTOS = 12;

    public const GARANTIAS = ['30 dias', '90 dias', '6 meses', '1 ano'];

    public const CONDICOES = ['new' => 'Novo', 'used' => 'Usado'];

    public const ENVIOS = ['me2' => 'Mercado Envios', 'not_specified' => 'A combinar com o comprador'];

    /** As pendências locais, como o card da esquerda as diz. */
    private const ROTULO_CURTO = [
        'categoria'       => 'falta categoria',
        'titulo_classico' => 'falta título do Clássico',
        'titulo_premium'  => 'falta título do Premium',
        'preco_classico'  => 'falta preço',
        'preco_premium'   => 'falta preço',
        'fotos'           => 'falta foto',
        'estoque'         => 'falta estoque',
        'ficha'           => 'falta ficha técnica',
    ];

    public function __construct(
        private MlPublicacaoService $motor,
        private MercadoLivreService $ml,
        private MlImagemService $imagem,
        private MlCatalogoMetaService $meta,
        private MlColetaService $coleta,
        private MlItemPayloadValidator $tradutor,
        private EstruturaAnuncioService $anuncios,
        private EstruturaPrecificacaoService $precificacao,
        private CategoriaBuscaService $buscaCategoria,
    ) {
    }

    // ═══ Leitura ════════════════════════════════════════════════════════════

    /**
     * A lista da esquerda. "A anunciar" = situação diferente de OK na régua;
     * "Publicados" = OK (publicado aqui ou importado). Contagens do conjunto
     * inteiro; a lista pagina no servidor.
     *
     * @return array{filtro: string, contagens: array{a_anunciar: int, publicados: int}, ofertas: array<int, array>, paginacao: array}
     */
    public function pagina(Company $empresa, string $filtro, string $busca, int $pagina): array
    {
        $filtro = in_array($filtro, self::FILTROS, true) ? $filtro : 'a_anunciar';
        $busca = mb_strtolower(trim($busca));

        $conjunto = EstruturaConjunto::daEmpresa($empresa);
        $publicacoes = $this->publicacoesDa($empresa);

        $grupos = ['a_anunciar' => [], 'publicados' => []];
        foreach ($conjunto->ofertas() as $o) {
            $grupos[$o['situacao'] === ReguaEstrutura::SITUACAO_OK ? 'publicados' : 'a_anunciar'][] = $o;
        }
        $contagens = array_map('count', $grupos);

        $lista = array_values(array_filter($grupos[$filtro], fn ($o) => $busca === ''
            || str_contains(mb_strtolower($o['sku']), $busca)
            || str_contains(mb_strtolower((string) $o['nome']), $busca)));

        $total = count($lista);
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);
        $daPagina = array_slice($lista, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA);

        $precos = $this->precificacao->pagina($empresa, array_column($daPagina, 'id'))['por_oferta'];

        return [
            'filtro'    => $filtro,
            'contagens' => $contagens,
            'ofertas'   => array_map(function (array $o) use ($publicacoes, $precos) {
                $pub = $publicacoes[$o['id']] ?? null;
                $ef = $this->efetivo($o, $pub, $precos[$o['id']] ?? null);

                return [
                    'id'        => $o['id'],
                    'sku'       => $o['sku'],
                    'nome'      => $o['nome'],
                    'fase'      => $o['fase'],
                    'situacao'  => $o['situacao'],
                    'preco_classico' => $ef['dados']['tipos']['classico']['preco'],
                    'anuncios'  => $this->mlbsDa($o),
                    'publicacao' => $pub ? $this->serializar($pub, $ef['dados']) : null,
                    'prontidao' => $this->prontidao($o, $pub, $ef['dados']),
                ];
            }, $daPagina),
            'paginacao' => ['pagina' => $pagina, 'paginas' => $paginas, 'total' => $total, 'por_pagina' => self::POR_PAGINA],
        ];
    }

    /**
     * O formulário de uma oferta: dados efetivos, referências (título
     * planejado, preço da Precificação), categoria sugerida pelo título
     * quando ainda não há uma, a meta da categoria (atributos obrigatórios,
     * limite do título) e as pendências. GET: nada é gravado.
     */
    public function abrir(EstruturaOferta $oferta): array
    {
        [$o, $pub, $ef] = $this->carregar($oferta);
        $dados = $ef['dados'];
        $tipos = $this->tiposPendentes($o, $pub);

        // Sem nada a publicar (os dois já estão no ar), o preditor não é chamado.
        $sugestoes = [];
        if ($dados['categoria_id'] === null && $tipos) {
            $sugestoes = $this->categorias($dados['tipos']['classico']['titulo'] ?: ($o['nome'] ?: $o['sku']));
            if ($sugestoes) {
                // O nome gravado é o CAMINHO inteiro, como o card mostra e como
                // o "trocar" grava — a folha sozinha ("Cadeiras") não diz nada.
                $dados['categoria_id'] = $sugestoes[0]['id'];
                $dados['categoria_nome'] = $sugestoes[0]['caminho'] ? implode(' › ', $sugestoes[0]['caminho']) : $sugestoes[0]['nome'];
                $dados['categoria_origem'] = 'sugerida';
            }
        }

        $meta = $dados['categoria_id'] !== null ? $this->categoria($dados['categoria_id']) : null;
        if ($meta && $dados['categoria_nome'] === null) {
            $dados['categoria_nome'] = $meta['caminho'] ? implode(' › ', $meta['caminho']) : $meta['nome'];
        }

        return [
            'oferta'     => [
                'id' => $o['id'], 'sku' => $o['sku'], 'nome' => $o['nome'], 'fase' => $o['fase'],
                'situacao' => $o['situacao'], 'anuncios' => $this->mlbsDa($o),
            ],
            'publicacao' => $pub ? $this->serializar($pub, $ef['dados']) : $this->serializar(null, $ef['dados']),
            'dados'      => $dados,
            'referencia' => $ef['referencia'],
            // O que esta publicação ainda envia. Vazio = os dois já estão no ar.
            'tipos_pendentes' => $tipos,
            'sugestoes'  => $sugestoes,
            'categoria'  => $meta,
            'pendencias' => $this->pendencias($dados, $tipos, $meta),
            'vocabulario' => [
                'condicoes' => self::CONDICOES,
                'envios'    => self::ENVIOS,
                'garantias' => self::GARANTIAS,
                'max_fotos' => self::MAX_FOTOS,
            ],
        ];
    }

    /**
     * Categorias para um texto (o preditor do ML, `domain_discovery`), cada
     * uma com o CAMINHO inteiro da árvore (`path_from_root`). Serve a
     * sugestão pelo título e a busca do "trocar".
     *
     * O caminho vem ANTES de escolher (pedido do usuário, 29/09): "Caixa de
     * Direção" e "Caixas de Direção Hidráulica" só se distinguem pela
     * árvore, e o leigo precisa vê-la na lista. Uma categoria cujo caminho
     * falhou aparece com o nome do preditor e `caminho` vazio — a lista não
     * cai por causa de uma.
     *
     * @return array<int, array{id: string, nome: string, dominio: ?string, caminho: array<int, string>}>
     */
    public function categorias(string $q): array
    {
        // D18: a busca mora em CategoriaBuscaService; o Portal só delega até sair (160-15).
        return $this->buscaCategoria->categorias($q);
    }

    /**
     * A meta de uma categoria, pronta para o formulário: caminho, limite do
     * título e SÓ os atributos obrigatórios (mesmo filtro do wizard admin:
     * `required` e não `allow_variations`, sem grade de moda).
     *
     * @return array{id: string, nome: string, caminho: array<int, string>, folha: bool, max_titulo: int, atributos: array<int, array>}|null
     */
    public function categoria(string $id): ?array
    {
        $cat = $this->meta->categoria($id);
        if (! $cat) {
            return null;
        }

        $obrigatorios = array_values(array_filter($this->meta->atributos($id), fn ($a) => data_get($a, 'tags.required') === true
            && data_get($a, 'tags.allow_variations') !== true
            && ! str_contains((string) ($a['id'] ?? ''), 'GRID')));

        return [
            'id'         => (string) ($cat['id'] ?? $id),
            'nome'       => (string) ($cat['name'] ?? $id),
            'caminho'    => array_values(array_filter(array_column((array) data_get($cat, 'path_from_root', []), 'name'))),
            'folha'      => empty($cat['children_categories']),
            'max_titulo' => (int) (data_get($cat, 'settings.max_title_length') ?: 60),
            'atributos'  => array_map(fn ($a) => [
                'id'      => (string) $a['id'],
                'nome'    => (string) ($a['name'] ?? $a['id']),
                'tipo'    => (string) ($a['value_type'] ?? 'string'),
                'valores' => array_map(fn ($v) => ['id' => (string) $v['id'], 'nome' => (string) ($v['name'] ?? $v['id'])], (array) ($a['values'] ?? [])),
                'unidade' => data_get($a, 'default_unit'),
                'dica'    => data_get($a, 'hint'),
            ], $obrigatorios),
        ];
    }

    // ═══ Escrita ════════════════════════════════════════════════════════════

    /**
     * O rascunho salva sozinho. Cria a linha no primeiro salvamento; depois só
     * atualiza. Um par `validado` cujos dados mudaram volta a `rascunho`
     * (o hash deixa de bater de qualquer modo — o status é só a leitura).
     * Publicado ou publicando não se edita.
     */
    public function salvarRascunho(EstruturaOferta $oferta, array $dados, AtorDoPortal $ator): EstruturaPublicacao
    {
        $pub = $oferta->publicacao;
        $this->garantirEditavel($pub);

        $novos = $this->normalizar($dados);

        if (! $pub) {
            $pub = $oferta->publicacao()->create(['status' => EstruturaPublicacao::STATUS_RASCUNHO, 'dados' => $novos]);

            RegistroEstrutura::registrar($ator, $oferta->company, $pub, 'publicacao_rascunho', "Rascunho do par de {$oferta->sku} criado");

            return $pub;
        }

        $mudou = $pub->dados !== $novos;
        $pub->update([
            'dados'  => $novos,
            'status' => $mudou && $pub->status === EstruturaPublicacao::STATUS_VALIDADO ? EstruturaPublicacao::STATUS_RASCUNHO : $pub->status,
        ]);

        return $pub;
    }

    /** Sobe UMA foto para o ML e a pendura no rascunho (`{id, url}`). */
    public function enviarFoto(EstruturaOferta $oferta, string $conteudo, string $nome, AtorDoPortal $ator): EstruturaPublicacao
    {
        $empresa = $oferta->company;
        $pub = $oferta->publicacao;
        $this->garantirEditavel($pub);
        $this->garantirConectada($empresa);

        $dados = $pub?->dados ?? $this->normalizar([]);
        if (count($dados['fotos']) >= self::MAX_FOTOS) {
            throw ValidationException::withMessages(['imagem' => 'O formulário aceita até '.self::MAX_FOTOS.' fotos.']);
        }
        if ($conteudo === '') {
            throw ValidationException::withMessages(['imagem' => 'O arquivo está vazio.']);
        }

        try {
            $foto = $this->imagem->enviar($empresa, $conteudo, $nome);
        } catch (\Throwable $e) {
            Log::error("[Estrutura Anunciar] upload de foto falhou — empresa {$empresa->id}, oferta {$oferta->id}: {$e->getMessage()}");
            $foto = null;
        }

        if (! $foto) {
            throw ValidationException::withMessages(['imagem' => 'O Mercado Livre não aceitou esta imagem. Use JPG ou PNG com pelo menos 500 px no menor lado.']);
        }

        $dados['fotos'][] = ['id' => (string) $foto['id'], 'url' => $foto['url'] ? str_replace('http://', 'https://', $foto['url']) : null];

        if ($pub) {
            $pub->update([
                'dados'  => $dados,
                'status' => $pub->status === EstruturaPublicacao::STATUS_VALIDADO ? EstruturaPublicacao::STATUS_RASCUNHO : $pub->status,
            ]);
        } else {
            $pub = $oferta->publicacao()->create(['status' => EstruturaPublicacao::STATUS_RASCUNHO, 'dados' => $dados]);
        }

        RegistroEstrutura::registrar($ator, $empresa, $pub, 'publicacao_foto', "Foto {$foto['id']} enviada ao ML para {$oferta->sku}");

        return $pub;
    }

    /**
     * Conferir com o Mercado Livre: primeiro as pendências locais (sem tocar
     * o ML); sem nenhuma, `POST /items/validate` com o payload de cada tipo
     * que ainda não foi publicado. Aprovado → `validado` + hash dos dados
     * efetivos. Aviso do ML não bloqueia; erro bloqueia.
     *
     * @return array{valido: bool, erros: array<string, array<int, array>>, publicacao: array}
     */
    public function validar(EstruturaOferta $oferta, AtorDoPortal $ator): array
    {
        $empresa = $oferta->company;
        [$o, $pub, $ef] = $this->carregar($oferta);
        $this->garantirEditavel($pub);
        $this->garantirConectada($empresa);

        $tipos = $this->tiposPendentes($o, $pub);
        if (! $tipos) {
            throw ValidationException::withMessages(['publicacao' => 'Esta oferta já tem Clássico e Premium no ar — não há o que publicar.']);
        }

        $pub ??= $oferta->publicacao()->create(['status' => EstruturaPublicacao::STATUS_RASCUNHO, 'dados' => $this->normalizar([])]);
        $dados = $ef['dados'];
        $meta = $dados['categoria_id'] !== null ? $this->categoria($dados['categoria_id']) : null;

        $erros = [];
        foreach ($this->pendencias($dados, $tipos, $meta) as $p) {
            $erros['local'][] = [...$p, 'tipo' => 'error'];
        }

        if (! $erros) {
            $builder = $this->motor->builderPara($empresa);
            $token = $this->ml->ensureValidToken($empresa);

            foreach ($tipos as $tipo) {
                $payload = $builder->montar($this->montarItem($dados, $tipo, $o['sku']));
                $resp = Http::withToken($token->access_token)->post(self::API_BASE.'/items/validate', $payload);

                if ($resp->status() === 204) {
                    continue;
                }

                $causas = $resp->status() === 400
                    ? (array) ($resp->json('cause') ?? $resp->json() ?? [])
                    : [['code' => 'http_'.$resp->status(), 'message' => 'O Mercado Livre não respondeu à conferência (HTTP '.$resp->status().'). Tente de novo em instantes.']];

                $traduzidos = $this->tradutor->traduzir($causas) ?: [['code' => 'http_'.$resp->status(), 'campo' => null, 'mensagem' => 'O Mercado Livre recusou o anúncio sem dizer o motivo.', 'tipo' => 'error']];
                $erros[$tipo] = $traduzidos;
            }
        }

        $bloqueia = collect($erros)->flatten(1)->contains(fn ($e) => ($e['tipo'] ?? 'error') === 'error');

        $pub->update([
            'status'        => $bloqueia ? EstruturaPublicacao::STATUS_RASCUNHO : ($pub->status === EstruturaPublicacao::STATUS_RASCUNHO ? EstruturaPublicacao::STATUS_VALIDADO : $pub->status),
            'validado_hash' => $bloqueia ? null : EstruturaPublicacao::hashDe($dados),
            'erros'         => $erros ?: null,
        ]);

        if (! $bloqueia) {
            RegistroEstrutura::registrar($ator, $empresa, $pub, 'publicacao_conferida', "Par de {$oferta->sku} conferido com o Mercado Livre");
        }

        return ['valido' => ! $bloqueia, 'erros' => $erros, 'publicacao' => $this->serializar($pub->fresh(), $dados)];
    }

    /**
     * Publica o que falta do par: `POST /items` + descrição por tipo sem
     * código, gravando cada MLB no instante em que o ML o devolve, e
     * registrando-o na aba Anúncios (completa o planejado). Um só tipo
     * publicado → `parcial`; nenhum → `erro`; os dois → `publicado`.
     *
     * @return array{publicacao: array, erros: array}
     */
    public function publicar(EstruturaOferta $oferta, AtorDoPortal $ator): array
    {
        $empresa = $oferta->company;
        [$o, $pub, $ef] = $this->carregar($oferta);
        $dados = $ef['dados'];

        // Só o que a régua diz que falta — uma oferta "Falta Premium" com o
        // Clássico importado publica SÓ o Premium; nunca um segundo Clássico.
        $tentar = $this->tiposPendentes($o, $pub);
        if (! $tentar || ($pub && $pub->status === EstruturaPublicacao::STATUS_PUBLICADO)) {
            throw ValidationException::withMessages(['publicacao' => 'Esta oferta já foi publicada.']);
        }
        if (! $pub || $pub->status === EstruturaPublicacao::STATUS_RASCUNHO) {
            throw ValidationException::withMessages(['publicacao' => 'Confira o anúncio no Mercado Livre antes de publicar.']);
        }
        if ($pub->validado_hash === null || $pub->validado_hash !== EstruturaPublicacao::hashDe($dados)) {
            throw ValidationException::withMessages(['publicacao' => 'O anúncio mudou depois da última conferência. Confira de novo no Mercado Livre antes de publicar.']);
        }
        $this->garantirConectada($empresa);

        // A trava: só quem afetar exatamente 1 linha publica. `rascunho`
        // nunca entra; `publicando` só se a trava venceu (request que morreu).
        $vencida = now()->subMinutes(self::PUBLICANDO_VENCE_MINUTOS);
        $afetadas = EstruturaPublicacao::where('id', $pub->id)
            ->where(fn ($q) => $q
                ->whereIn('status', [EstruturaPublicacao::STATUS_VALIDADO, EstruturaPublicacao::STATUS_PARCIAL, EstruturaPublicacao::STATUS_ERRO])
                ->orWhere(fn ($q2) => $q2->where('status', EstruturaPublicacao::STATUS_PUBLICANDO)->where('publicando_em', '<', $vencida)))
            ->update(['status' => EstruturaPublicacao::STATUS_PUBLICANDO, 'publicando_em' => now()]);

        if ($afetadas !== 1) {
            $atual = $pub->fresh()->status;

            throw ValidationException::withMessages(['publicacao' => $atual === EstruturaPublicacao::STATUS_PUBLICADO
                ? 'Esta oferta já foi publicada.'
                : 'Esta oferta já está sendo publicada — aguarde e recarregue a página.']);
        }

        $pub->refresh();
        $erros = [];

        try {
            $builder = $this->motor->builderPara($empresa);
        } catch (\Throwable $e) {
            $builder = null;
            $erros['local'][] = ['code' => 'modelo', 'campo' => null, 'mensagem' => 'Não foi possível ler a conta do Mercado Livre: '.$e->getMessage(), 'tipo' => 'error'];
        }

        foreach ($builder ? $tentar : [] as $tipo) {
            $titulo = $dados['tipos'][$tipo]['titulo'];

            try {
                $item = $this->ml->post($empresa, '/items', $builder->montar($this->montarItem($dados, $tipo, $o['sku'])));
                $id = (string) ($item['id'] ?? '');
                if ($id === '') {
                    throw new \RuntimeException('O Mercado Livre não devolveu o código do anúncio.');
                }

                // ANTES de qualquer outra coisa: o item existe no ML, e o sistema precisa saber.
                $pub->update(['ml_item_'.$tipo => $id]);
                Log::info("[Estrutura Anunciar] {$id} publicado ({$tipo}) — empresa {$empresa->id}, oferta {$oferta->id} ({$o['sku']})");
            } catch (\Throwable $e) {
                $erros[$tipo] = $this->traduzirFalha($e);
                Log::error("[Estrutura Anunciar] falha ao publicar {$tipo} — empresa {$empresa->id}, oferta {$oferta->id} ({$o['sku']}): {$e->getMessage()}");
                continue;
            }

            // Descrição: best-effort — o item já existe; a falta vira aviso.
            if ($dados['descricao'] !== null) {
                try {
                    $this->ml->post($empresa, "/items/{$id}/description", ['plain_text' => $dados['descricao']]);
                } catch (\Throwable $e) {
                    $erros[$tipo][] = ['code' => 'descricao', 'campo' => 'descricao', 'mensagem' => "Publicado como {$id}, mas a descrição não foi enviada — edite-a no Mercado Livre.", 'tipo' => 'warning'];
                    Log::warning("[Estrutura Anunciar] descrição de {$id} falhou — empresa {$empresa->id}: {$e->getMessage()}");
                }
            }

            // O MLB volta para a linha dele na aba Anúncios: completa o
            // planejado do mesmo tipo, e a oferta passa a contar na régua.
            try {
                $this->anuncios->cadastrar($oferta, ['tipo' => $tipo, 'codigo_mlb' => $id, 'titulo' => $titulo, 'status' => EstruturaAnuncio::STATUS_ATIVO], $ator);
            } catch (\Throwable $e) {
                $erros[$tipo][] = ['code' => 'aba_anuncios', 'campo' => null, 'mensagem' => "Publicado como {$id}, mas não entrou na aba Anúncios: ".($e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage()), 'tipo' => 'warning'];
                Log::error("[Estrutura Anunciar] {$id} publicado mas não cadastrado em Anúncios — oferta {$oferta->id}: {$e->getMessage()}");
            }
        }

        $pub->refresh();
        // O que sobrou é lido de novo da régua: o `cadastrar()` acima já pôs
        // cada MLB publicado na aba Anúncios.
        $restantes = $this->tiposPendentes(EstruturaConjunto::daEmpresa($empresa)->oferta($oferta->id), $pub);
        $status = match (true) {
            $restantes === [] => EstruturaPublicacao::STATUS_PUBLICADO,
            count($restantes) < count($tentar) => EstruturaPublicacao::STATUS_PARCIAL,
            default => EstruturaPublicacao::STATUS_ERRO,
        };

        $pub->update([
            'status'        => $status,
            'erros'         => $erros ?: null,
            'publicando_em' => null,
            'publicado_em'  => $status === EstruturaPublicacao::STATUS_PUBLICADO ? now() : null,
        ]);

        $evento = ['publicado' => 'par_publicado', 'parcial' => 'par_parcial', 'erro' => 'par_erro'][$status];
        RegistroEstrutura::registrar($ator, $empresa, $pub, $evento,
            "Par de {$o['sku']}: ".EstruturaPublicacao::STATUS[$status],
            ['classico' => $pub->ml_item_classico, 'premium' => $pub->ml_item_premium, 'erros' => $erros ?: null]);

        return ['publicacao' => $this->serializar($pub->fresh(), $dados), 'erros' => $erros];
    }

    // ═══ Dados efetivos e pendências ════════════════════════════════════════

    /**
     * @return array{0: array, 1: ?EstruturaPublicacao, 2: array{dados: array, referencia: array}}
     */
    private function carregar(EstruturaOferta $oferta): array
    {
        $empresa = $oferta->company;
        $o = EstruturaConjunto::daEmpresa($empresa)->oferta($oferta->id);
        $pub = $oferta->publicacao()->first();
        $preco = $this->precificacao->pagina($empresa, [$oferta->id])['por_oferta'][$oferta->id] ?? null;

        return [$o, $pub, $this->efetivo($o, $pub, $preco)];
    }

    /**
     * Rascunho + padrões do resto do módulo. Título vazio → o planejado da aba
     * Anúncios (o anúncio sem MLB daquele tipo; senão o primeiro); preço
     * vazio → o anunciado da Precificação.
     *
     * @param  array  $o  a oferta medida pelo `EstruturaConjunto`
     * @return array{dados: array, referencia: array<string, array>}
     */
    private function efetivo(array $o, ?EstruturaPublicacao $pub, ?array $preco): array
    {
        $dados = $this->normalizar($pub?->dados ?? []);
        $referencia = [];

        foreach (array_keys(EstruturaAnuncio::TIPOS) as $tipo) {
            $doTipo = array_values(array_filter($o['anuncios'], fn ($a) => $a['tipo'] === $tipo));
            usort($doTipo, fn ($a, $b) => ($a['codigo_mlb'] === null ? 0 : 1) <=> ($b['codigo_mlb'] === null ? 0 : 1));
            $planejado = $doTipo[0]['titulo'] ?? null;
            $calculo = $preco[$tipo] ?? null;

            $referencia[$tipo] = [
                'titulo_planejado' => $planejado,
                'preco_anunciado'  => $calculo['anunciado'] ?? null,
                'preco_minimo'     => $calculo['minimo'] ?? null,
                'pendencia_preco'  => $preco['pendencia'] ?? PrecificacaoEstrutura::PENDENCIA_SEM_CUSTO,
                // O MLB deste tipo, publicado aqui ou já existente na aba Anúncios (importado, colado).
                'publicado'        => $pub?->mlItem($tipo) ?? $this->mlbsDa($o)[$tipo],
            ];

            $dados['tipos'][$tipo]['titulo'] ??= $planejado !== null ? mb_substr(trim($planejado), 0, 255) : null;
            $dados['tipos'][$tipo]['preco'] ??= $calculo['anunciado'] ?? null;
        }

        return ['dados' => $dados, 'referencia' => $referencia];
    }

    /**
     * A forma canônica de `dados` — a mesma ordem de chaves sempre, porque o
     * hash da conferência é calculado sobre ela.
     */
    public function normalizar(array $d): array
    {
        $texto = fn ($v, int $max) => ($v = trim((string) ($v ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $numero = fn ($v) => ($v === null || $v === '' || ! is_numeric($v)) ? null : round((float) $v, 2);
        $inteiro = fn ($v) => ($v === null || $v === '' || ! is_numeric($v)) ? null : (int) $v;

        $atributos = [];
        foreach ((array) ($d['atributos'] ?? []) as $id => $v) {
            $id = strtoupper(trim((string) $id));
            if ($id === '' || ! is_array($v)) {
                continue;
            }
            $vid = $texto($v['value_id'] ?? null, 100);
            $vnome = $texto($v['value_name'] ?? null, 255);
            if ($vid === null && $vnome === null) {
                continue;
            }
            $atributos[$id] = ['value_id' => $vid, 'value_name' => $vnome];
        }
        ksort($atributos);

        $fotos = [];
        foreach ((array) ($d['fotos'] ?? []) as $f) {
            $id = $texto(is_array($f) ? ($f['id'] ?? null) : null, 100);
            if ($id === null) {
                continue;
            }
            $fotos[] = ['id' => $id, 'url' => $texto($f['url'] ?? null, 500)];
        }

        $tipos = [];
        foreach (array_keys(EstruturaAnuncio::TIPOS) as $tipo) {
            $t = (array) ($d['tipos'][$tipo] ?? []);
            $tipos[$tipo] = ['titulo' => $texto($t['titulo'] ?? null, 255), 'preco' => $numero($t['preco'] ?? null)];
        }

        $condicao = (string) ($d['condicao'] ?? 'new');
        $envio = (array) ($d['envio'] ?? []);
        $modo = (string) ($envio['modo'] ?? 'me2');
        $emb = (array) ($d['embalagem'] ?? []);
        $garantia = $texto($d['garantia'] ?? null, 30);

        return [
            'categoria_id'     => $texto($d['categoria_id'] ?? null, 20),
            'categoria_nome'   => $texto($d['categoria_nome'] ?? null, 255),
            'categoria_origem' => in_array($d['categoria_origem'] ?? null, ['sugerida', 'escolhida'], true) ? $d['categoria_origem'] : null,
            'atributos'        => $atributos,
            'fotos'            => array_slice($fotos, 0, self::MAX_FOTOS),
            'estoque'          => $inteiro($d['estoque'] ?? null),
            'condicao'         => array_key_exists($condicao, self::CONDICOES) ? $condicao : 'new',
            'envio'            => ['modo' => array_key_exists($modo, self::ENVIOS) ? $modo : 'me2', 'frete_gratis' => (bool) ($envio['frete_gratis'] ?? false)],
            'embalagem'        => [
                'peso_g'         => $inteiro($emb['peso_g'] ?? null),
                'altura_cm'      => $numero($emb['altura_cm'] ?? null),
                'largura_cm'     => $numero($emb['largura_cm'] ?? null),
                'comprimento_cm' => $numero($emb['comprimento_cm'] ?? null),
            ],
            'garantia'         => in_array($garantia, self::GARANTIAS, true) ? $garantia : null,
            'descricao'        => $texto($d['descricao'] ?? null, 50000),
            'tipos'            => $tipos,
        ];
    }

    /**
     * Os tipos que ESTA publicação ainda precisa enviar: sem MLB que conte na
     * régua (importado, colado ou publicado aqui e já registrado) e sem código
     * gravado nesta linha. É a mesma régua da coluna H da planilha: "Falta
     * Premium" publica só o Premium.
     *
     * @return array<int, string>
     */
    private function tiposPendentes(array $o, ?EstruturaPublicacao $pub): array
    {
        $mlbs = $this->mlbsDa($o);

        return array_values(array_filter(array_keys(EstruturaAnuncio::TIPOS), fn ($t) => $mlbs[$t] === null && $pub?->mlItem($t) === null));
    }

    /**
     * O que falta ANTES de perguntar ao ML — só dos tipos que ainda vão ser
     * publicados. `$meta` nula = sem a ficha técnica (a lista da esquerda não
     * a confere; o formulário sim).
     *
     * @param  array<int, string>  $tipos
     * @return array<int, array{campo: string, mensagem: string}>
     */
    private function pendencias(array $dados, array $tipos, ?array $meta): array
    {
        $p = [];

        if ($dados['categoria_id'] === null) {
            $p[] = ['campo' => 'categoria', 'mensagem' => 'Falta a categoria no Mercado Livre.'];
        } elseif ($meta && ! $meta['folha']) {
            $p[] = ['campo' => 'categoria', 'mensagem' => 'Esta categoria tem subcategorias — escolha a mais específica.'];
        }

        foreach ($tipos as $tipo) {
            $rotulo = EstruturaAnuncio::TIPOS[$tipo];
            $t = $dados['tipos'][$tipo];
            if ($t['titulo'] === null) {
                $p[] = ['campo' => "titulo_{$tipo}", 'mensagem' => "Falta o título do {$rotulo} (a aba Anúncios também o aceita)."];
            } elseif ($meta && mb_strlen($t['titulo']) > $meta['max_titulo']) {
                $p[] = ['campo' => "titulo_{$tipo}", 'mensagem' => "O título do {$rotulo} passa de {$meta['max_titulo']} caracteres."];
            }
            if ($t['preco'] === null || $t['preco'] <= 0) {
                $p[] = ['campo' => "preco_{$tipo}", 'mensagem' => "Falta o preço do {$rotulo} — informe custo e frete na Precificação, ou digite aqui."];
            }
        }

        $c = $dados['tipos']['classico']['titulo'];
        $pr = $dados['tipos']['premium']['titulo'];
        if (count($tipos) === 2 && $c !== null && $pr !== null && mb_strtolower($c) === mb_strtolower($pr)) {
            $p[] = ['campo' => 'titulo_premium', 'mensagem' => 'Clássico e Premium precisam de títulos diferentes (mesmo SKU, títulos diferentes).'];
        }

        if (! $dados['fotos']) {
            $p[] = ['campo' => 'fotos', 'mensagem' => 'Falta ao menos 1 foto (o Premium sempre exige).'];
        }
        if ($dados['estoque'] === null || $dados['estoque'] < 1) {
            $p[] = ['campo' => 'estoque', 'mensagem' => 'Informe o estoque disponível (ao menos 1).'];
        }

        foreach ($meta['atributos'] ?? [] as $a) {
            $v = $dados['atributos'][$a['id']] ?? null;
            if (! $v || ($v['value_id'] === null && $v['value_name'] === null)) {
                $p[] = ['campo' => 'ficha', 'atributo' => $a['id'], 'mensagem' => "Falta \"{$a['nome']}\" na ficha técnica."];
            }
        }

        return $p;
    }

    /** O card da esquerda, em português: a primeira pendência, ou o estado do par. */
    private function prontidao(array $o, ?EstruturaPublicacao $pub, array $dados): array
    {
        if ($pub && $pub->status === EstruturaPublicacao::STATUS_PUBLICANDO) {
            return ['chave' => 'publicando', 'rotulo' => 'publicando…'];
        }
        if ($o['situacao'] === ReguaEstrutura::SITUACAO_OK) {
            return ['chave' => 'publicado', 'rotulo' => 'publicado'];
        }
        $tipos = $this->tiposPendentes($o, $pub);
        if ($pub && $pub->status === EstruturaPublicacao::STATUS_PARCIAL) {
            return ['chave' => 'parcial', 'rotulo' => 'falta publicar o '.EstruturaAnuncio::TIPOS[$tipos[0] ?? EstruturaAnuncio::TIPO_PREMIUM]];
        }
        if ($pub && $pub->status === EstruturaPublicacao::STATUS_ERRO) {
            return ['chave' => 'erro', 'rotulo' => 'erro ao publicar'];
        }

        $pendencias = $this->pendencias($dados, $tipos, null);
        if ($pendencias) {
            $campo = $pendencias[0]['campo'];

            return ['chave' => $campo, 'rotulo' => self::ROTULO_CURTO[$campo] ?? 'falta '.$campo];
        }

        $conferida = $pub && $pub->validado_hash !== null && $pub->validado_hash === EstruturaPublicacao::hashDe($dados);

        return $conferida
            ? ['chave' => 'pronto', 'rotulo' => 'pronto para publicar']
            : ['chave' => 'conferir', 'rotulo' => 'pronto para conferir'];
    }

    // ═══ Payload ════════════════════════════════════════════════════════════

    /**
     * Os dados de UM tipo no shape que o `ItemBuilder` espera. O SKU da
     * oferta vai em `SELLER_SKU` nos dois — é o que liga o par no ML (§27).
     */
    public function montarItem(array $dados, string $tipo, string $sku): array
    {
        $t = $dados['tipos'][$tipo];

        $attrs = [];
        foreach ($dados['atributos'] as $id => $v) {
            $attrs[] = $v['value_id'] !== null ? ['id' => $id, 'value_id' => $v['value_id']] : ['id' => $id, 'value_name' => $v['value_name']];
        }
        $attrs[] = ['id' => 'SELLER_SKU', 'value_name' => $sku];

        $e = $dados['embalagem'];
        foreach (['peso_g' => ['SELLER_PACKAGE_WEIGHT', 'g'], 'altura_cm' => ['SELLER_PACKAGE_HEIGHT', 'cm'], 'largura_cm' => ['SELLER_PACKAGE_WIDTH', 'cm'], 'comprimento_cm' => ['SELLER_PACKAGE_LENGTH', 'cm']] as $campo => [$id, $unidade]) {
            if ($e[$campo] !== null && $e[$campo] > 0) {
                $attrs[] = ['id' => $id, 'value_name' => rtrim(rtrim(number_format($e[$campo], 2, '.', ''), '0'), '.')." {$unidade}"];
            }
        }

        $shipping = ['mode' => $dados['envio']['modo'], 'local_pick_up' => false, 'free_shipping' => $dados['envio']['frete_gratis']];
        if ($dados['envio']['frete_gratis'] && $dados['envio']['modo'] === 'me2') {
            $shipping['free_methods'] = [];
        }

        return [
            'title'              => $t['titulo'],
            'category_id'        => $dados['categoria_id'],
            'price'              => $t['preco'],
            'currency_id'        => 'BRL',
            'available_quantity' => $dados['estoque'],
            'buying_mode'        => 'buy_it_now',
            'condition'          => $dados['condicao'],
            'listing_type_id'    => EstruturaPublicacao::LISTING_TYPES[$tipo],
            'attributes'         => $attrs,
            'pictures'           => array_map(fn ($f) => ['id' => $f['id']], $dados['fotos']),
            'sale_terms'         => $dados['garantia'] !== null
                ? [['id' => 'WARRANTY_TYPE', 'value_name' => 'Garantia do vendedor'], ['id' => 'WARRANTY_TIME', 'value_name' => $dados['garantia']]]
                : [],
            'shipping'           => $shipping,
        ];
    }

    /**
     * A falha do `POST /items` em pt-BR: o `MercadoLivreService` embute o
     * corpo da resposta na mensagem; quando há `cause`, passa pela mesma
     * tradução da conferência.
     *
     * @return array<int, array{code: string, campo: ?string, mensagem: string, tipo: string}>
     */
    private function traduzirFalha(\Throwable $e): array
    {
        $msg = $e->getMessage();
        $inicio = strpos($msg, '{');
        $corpo = $inicio !== false ? json_decode(substr($msg, $inicio), true) : null;

        if (is_array($corpo)) {
            $causas = (array) ($corpo['cause'] ?? []);
            if ($causas && ($traduzidos = $this->tradutor->traduzir($causas))) {
                return $traduzidos;
            }
            if (! empty($corpo['message'])) {
                return $this->tradutor->traduzir([['code' => (string) ($corpo['error'] ?? 'publish_failed'), 'message' => (string) $corpo['message']]]);
            }
        }

        return [['code' => 'publish_failed', 'campo' => null, 'mensagem' => 'O Mercado Livre não aceitou a publicação: '.mb_substr($msg, 0, 300), 'tipo' => 'error']];
    }

    // ═══ Apoio ══════════════════════════════════════════════════════════════

    /** @return array<int, EstruturaPublicacao> por oferta_id */
    private function publicacoesDa(Company $empresa): array
    {
        return EstruturaPublicacao::query()
            ->join('estrutura_ofertas as o', 'o.id', '=', 'estrutura_publicacoes.oferta_id')
            ->where('o.company_id', $empresa->id)
            ->get(['estrutura_publicacoes.*'])
            ->keyBy('oferta_id')
            ->all();
    }

    /** O MLB que CONTA de cada tipo (da régua), para a lista e o cabeçalho. */
    private function mlbsDa(array $o): array
    {
        $r = ['classico' => null, 'premium' => null];
        foreach ($o['anuncios'] as $a) {
            if ($r[$a['tipo']] === null && EstruturaAnuncio::conta($a['status'], $a['codigo_mlb'])) {
                $r[$a['tipo']] = $a['codigo_mlb'];
            }
        }

        return $r;
    }

    private function serializar(?EstruturaPublicacao $pub, array $dadosEfetivos): array
    {
        return [
            'status'           => $pub?->status ?? EstruturaPublicacao::STATUS_RASCUNHO,
            'status_rotulo'    => EstruturaPublicacao::STATUS[$pub?->status ?? EstruturaPublicacao::STATUS_RASCUNHO],
            'ml_item_classico' => $pub?->ml_item_classico,
            'ml_item_premium'  => $pub?->ml_item_premium,
            'erros'            => $pub?->erros,
            'conferida'        => $pub !== null && $pub->validado_hash !== null && $pub->validado_hash === EstruturaPublicacao::hashDe($dadosEfetivos),
            'publicado_em'     => $pub?->publicado_em?->toIso8601String(),
        ];
    }

    private function garantirEditavel(?EstruturaPublicacao $pub): void
    {
        if (! $pub) {
            return;
        }
        if ($pub->status === EstruturaPublicacao::STATUS_PUBLICADO) {
            throw ValidationException::withMessages(['publicacao' => 'Esta oferta já foi publicada — edite os anúncios no Mercado Livre.']);
        }
        if ($pub->status === EstruturaPublicacao::STATUS_PUBLICANDO && $pub->publicando_em?->gt(now()->subMinutes(self::PUBLICANDO_VENCE_MINUTOS))) {
            throw ValidationException::withMessages(['publicacao' => 'Esta oferta está sendo publicada — aguarde.']);
        }
    }

    private function garantirConectada(Company $empresa): void
    {
        if (! AnunciosMercadoLivreService::conectado($empresa) || ! $this->ml->ensureValidToken($empresa)) {
            throw ValidationException::withMessages(['publicacao' => 'A conta do Mercado Livre desta empresa não está conectada. Conecte pelo Onboarding e volte aqui.']);
        }
    }
}
