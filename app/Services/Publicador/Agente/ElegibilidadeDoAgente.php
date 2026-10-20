<?php

namespace App\Services\Publicador\Agente;

use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\PubAgenteConfiguracao;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Services\Portal\Estrutura\Produtos\PendenciasDoProduto;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\IaParaRascunhoService;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use App\Services\Publicador\RascunhoRepository;

/**
 * O produto pode ser preparado pelo agente? (AG-02, 20/10/2026) — responde ANTES de qualquer IA, com os
 * motivos objetivos de bloqueio. Não chama o ML nem a IA e não grava nada.
 *
 * Recorte do piloto (`PLANO_AGENTE_ECF_MVP_CLAUDE_CODE.md` §5.1 e §7.5), imposto aqui no servidor:
 * produto unitário do Portal, UMA modalidade ativa (a da configuração da loja) e UMA variante ativa.
 * Fora disso bloqueia e explica — nunca desativa alvo ou variante para caber.
 *
 * Do "produto completo" do Portal (`PendenciasDoProduto`) entram só as pendências que impedem publicar:
 * medidas, peso, custo, categoria e frete do ME1. Família e ambiente organizam o catálogo do cliente e
 * não vão ao anúncio; o título de venda é a própria IA quem gera.
 *
 * `fichaCompleta()` do preparo continua sendo a régua da ficha técnica — e só ela: não prova foto,
 * estoque, custo, preço nem frete, que são conferidos aqui à parte.
 */
class ElegibilidadeDoAgente
{
    /** As pendências do Portal que bloqueiam o agente. */
    public const PENDENCIAS_QUE_BLOQUEIAM = [
        PendenciasDoProduto::MEDIDAS, PendenciasDoProduto::PESO, PendenciasDoProduto::CUSTO,
        PendenciasDoProduto::CATEGORIA, PendenciasDoProduto::FRETE_ME1,
    ];

    private const MENSAGENS_DO_PORTAL = [
        PendenciasDoProduto::MEDIDAS => 'Informe as medidas da embalagem no cadastro do produto.',
        PendenciasDoProduto::PESO => 'Informe o peso da embalagem no cadastro do produto.',
        PendenciasDoProduto::CUSTO => 'Informe o custo do produto no cadastro.',
        PendenciasDoProduto::CATEGORIA => 'Escolha a categoria do Mercado Livre no cadastro do produto.',
        PendenciasDoProduto::FRETE_ME1 => 'Produto em ME1: informe o frete do vendedor no cadastro.',
    ];

    public function __construct(
        private RascunhoRepository $repo,
        private DadosEfetivosService $efetivos,
    ) {}

    /**
     * @return array{produto_id: int, elegivel: bool, versao_dos_fatos: ?string,
     *               pendencias: list<array{codigo: string, mensagem: string, origem: string, url_correcao: ?string}>,
     *               avaliado_em: string}
     */
    public function avaliar(PubProduto $pub, ?PubAgenteConfiguracao $cfg): array
    {
        $pendencias = [];
        $versaoDosFatos = null;
        $editor = route('mlb.anuncios.publicador.editor', ['produto' => $pub->id]);
        $add = function (string $codigo, string $mensagem, string $origem, ?string $url = null) use (&$pendencias) {
            $pendencias[] = ['codigo' => $codigo, 'mensagem' => $mensagem, 'origem' => $origem, 'url_correcao' => $url];
        };

        $modalidade = $cfg?->modalidade;
        if ($modalidade === null) {
            $add('modalidade_nao_configurada', 'Escolha na configuração do agente a modalidade que ele prepara (Clássico ou Premium).', 'agente');
        }

        $estrutura = $this->produtoDoPortal($pub);
        if ($estrutura === null) {
            $add('formato_fora_do_piloto', 'Por enquanto o agente só prepara produto unitário vindo do Portal (sem kit, combo ou fase).', 'agente');
        }

        $r = PubRascunho::where('produto_id', $pub->id)->first();
        if ($r === null) {
            $add('sem_rascunho', 'O produto ainda não chegou ao Publicador. Sincronize o Portal.', 'publicador');

            return $this->resultado($pub, $pendencias, null);
        }
        if (IaParaRascunhoService::intocavel($r)) {
            $add('ja_publicado', 'Este produto já está publicado ou em publicação.', 'publicador', $editor);

            return $this->resultado($pub, $pendencias, null);
        }

        $snap = $this->repo->snapshot($r);
        $alvos = $snap->alvosAtivos();
        $variantes = $snap->variantesAtivas();
        $tipos = array_map(fn ($a) => $a->listingTypeId, $alvos);
        if ($modalidade !== null && $tipos !== [$modalidade]) {
            $add('alvos_fora_do_piloto', 'Deixe ativa no editor só a modalidade configurada no agente.', 'publicador', $editor);
        }
        if (count($variantes) !== 1) {
            $add('variantes_fora_do_piloto', 'Por enquanto o agente só prepara produto com uma variante ativa.', 'publicador', $editor);
        }

        if ($estrutura !== null) {
            foreach ($this->pendenciasDoPortal($estrutura) as $codigo) {
                $add($codigo, self::MENSAGENS_DO_PORTAL[$codigo], 'cadastro');
            }
        }
        if (! $this->preparo()->fichaCompleta($pub)) {
            $add('ficha_incompleta', 'Preencha os campos obrigatórios da ficha técnica no cadastro do produto.', 'cadastro');
        }

        // Foto que conta é a que vai ao anúncio: atribuída a um grupo (é de lá que nascem os conjuntos da revisão).
        $fotos = $r->imagens()->where('upload_status', '!=', PubImagem::FALHOU)->whereHas('atribuicoes')->count();
        if ($fotos === 0) {
            $add('sem_fotos', 'O produto não tem foto utilizável.', 'publicador', $editor);
        }

        if (count($variantes) === 1 && $modalidade !== null) {
            $dados = $variantes[0]->dados;
            $estoque = (int) ($dados['estoque'] ?? 0);
            if ($estoque < max(1, (int) $cfg->estoque_minimo)) {
                $add('estoque_insuficiente', "Estoque {$estoque}, abaixo do mínimo configurado no agente ({$cfg->estoque_minimo}).", 'cadastro');
            }
            $preco = $dados['precos'][$modalidade] ?? ($this->efetivos->daProduto($pub)['precos'][$modalidade] ?? null);
            if ($preco === null || (float) $preco <= 0) {
                $add('preco_ausente', 'O produto não tem preço para a modalidade configurada (Precificação do Portal ou editor).', 'cadastro', $editor);
            }
        }

        if ($r->categoria_id) {
            $versaoDosFatos = $this->preparo()->hashDosFatos($r);
        }

        return $this->resultado($pub, $pendencias, $versaoDosFatos);
    }

    private function resultado(PubProduto $pub, array $pendencias, ?string $versaoDosFatos): array
    {
        return [
            'produto_id' => (int) $pub->id,
            'elegivel' => $pendencias === [],
            'versao_dos_fatos' => $versaoDosFatos,
            'pendencias' => $pendencias,
            'avaliado_em' => now()->toIso8601String(),
        ];
    }

    /** O produto do Portal por trás do produto do Publicador — só o unitário (agrupado ou oferta Simples). */
    private function produtoDoPortal(PubProduto $pub): ?EstruturaProduto
    {
        if ($pub->produto_base_id !== null || $pub->company_id === null) {
            return null;
        }
        $id = $pub->estrutura_produto_id;
        if ($id === null && $pub->oferta_id !== null) {
            $oferta = EstruturaOferta::query()->where('company_id', $pub->company_id)
                ->where('fase', EstruturaOferta::FASE_SIMPLES)->with('variacao')->find($pub->oferta_id);
            $id = $oferta?->variacao?->produto_id;
        }

        return $id === null ? null : EstruturaProduto::query()->where('company_id', $pub->company_id)
            ->with(['familia', 'ambientes', 'variacoes.volumes', 'company'])->find($id);
    }

    /**
     * As pendências que bloqueiam, de cada variação, pela MESMA régua da lista de produtos do Portal
     * (`ProdutoLinhas`) — sem a estimativa de frete, que consulta o ML. A modalidade de envio vem do cache.
     *
     * @return list<string>
     */
    private function pendenciasDoPortal(EstruturaProduto $produto): array
    {
        $modalidade = $produto->company !== null ? ModalidadeDeEnvio::emCache($produto->company) : null;
        $achadas = [];
        foreach ($produto->variacoes as $variacao) {
            $volumes = $variacao->volumes
                ->map(fn ($v) => ['c' => (float) $v->comprimento, 'l' => (float) $v->largura, 'a' => (float) $v->altura, 'kg' => (float) $v->peso])
                ->values()->all();
            $log = LogisticaProduto::daVolumes($volumes, $modalidade);
            $linha = PendenciasDoProduto::daLinha([
                'volumes' => $volumes,
                'custo' => $variacao->custo,
                'categoria_ml_id' => $produto->categoria_ml_id,
                'familia' => $produto->familia?->nome,
                'ambientes' => $produto->ambientes->pluck('nome')->all(),
                'logistica' => $log['logistica'],
            ]);
            foreach (array_intersect($linha, self::PENDENCIAS_QUE_BLOQUEIAM) as $codigo) {
                $achadas[$codigo] = true;
            }
        }

        return array_values(array_intersect(PendenciasDoProduto::ORDEM, array_keys($achadas)));
    }

    /** Resolvido só quando precisa (o preparo é grande e não depende do agente). */
    private function preparo(): PreparoIaDoRascunhoService
    {
        return app(PreparoIaDoRascunhoService::class);
    }
}
