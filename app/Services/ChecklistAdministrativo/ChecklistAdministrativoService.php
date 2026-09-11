<?php

namespace App\Services\ChecklistAdministrativo;

use App\Contracts\ChecklistResolver;
use App\Models\ChecklistAdministrativoItem;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\OnboardingLink;
use App\Models\User;
use App\Services\ChecklistAdministrativo\Resolvers\ConexaoEcfResolver;
use App\Services\ChecklistAdministrativo\Resolvers\ContratoAssinadoResolver;
use App\Services\ChecklistAdministrativo\Resolvers\ContratoEnviadoResolver;
use App\Services\ChecklistAdministrativo\Resolvers\MlOAuthConectadoResolver;
use App\Services\Onboarding\OnboardingLinkService;

/**
 * ChecklistAdministrativoService — Fase 152 Plano 05. Coração de leitura e
 * escrita do checklist administrativo: monta o checklist de uma empresa
 * (D-07), roda os 4 resolvers automáticos e persiste o resultado, calcula o
 * progresso a partir do catálogo (D-10) e permite marcar/desmarcar
 * manualmente com autoria (D-11), recusando marcação manual em item
 * automático (D-13).
 *
 * ⚠️ Este service NÃO transiciona `companies.etapa` e nunca vai transicionar.
 * A régua que dirige a etapa pelo progresso do checklist mora na camada
 * dedicada de sincronização (plano 152-07) e é disparada pela camada HTTP
 * (plano 152-08) — nunca por dentro deste arquivo. O motivo é estrutural: o
 * sincronizador precisa LER este service, então se este service recebesse o
 * sincronizador no construtor o grafo de injeção fecharia ciclo. Por isso
 * `paraEmpresa()` recebe só `Company $company` — nenhum ator, nenhuma
 * tentação de sincronizar por dentro.
 */
class ChecklistAdministrativoService
{
    /**
     * Quatro resolvers fixos, injetados diretamente — nenhuma factory nova.
     * Uma `ChecklistResolverFactory` só se justificaria se o conjunto de
     * resolvers fosse aberto (o motor de Onboarding tem uma porque o
     * catálogo dele cresce por template); aqui o catálogo é fechado pela
     * D-01/D-03, então o container do Laravel já resolve as quatro
     * dependências sem nenhuma indireção extra.
     */
    public function __construct(
        private ContratoEnviadoResolver $contratoEnviadoResolver,
        private ContratoAssinadoResolver $contratoAssinadoResolver,
        private MlOAuthConectadoResolver $mlOAuthConectadoResolver,
        private ConexaoEcfResolver $conexaoEcfResolver,
        private OnboardingLinkService $onboardingLinkService,
    ) {
    }

    /**
     * Monta o checklist completo da empresa: 9 itens quando a empresa exige
     * contrato (D-07), 6 quando é isenta — o grupo `contrato` simplesmente
     * NÃO APARECE na chave `grupos` para empresa isenta, nunca vem vazio nem
     * marcado como "não aplicável" (D-02).
     *
     * @return array{
     *   exige_contrato: bool,
     *   grupos: array<string, array{chave:string, titulo:string, itens:array<int, array<string, mixed>>}>,
     *   progresso: array{feitos:int, total:int, percentual:int},
     * }
     */
    public function paraEmpresa(Company $company): array
    {
        $exigeContrato = $this->exigeContrato($company);
        $itens = ChecklistAdministrativoDefinicao::itens($exigeContrato);

        // Linhas MANUAIS já gravadas da empresa, indexadas por chave, com
        // feitoPor eager-loaded (evita N+1 ao ler feito_por_nome item a
        // item). Persistência é LAZY (D-11): uma linha só existe depois que
        // alguém marca. Ausência de linha nesta consulta é lida como
        // "aberto" — não é criada aqui só para representar esse estado.
        $linhasManuais = ChecklistAdministrativoItem::where('company_id', $company->id)
            ->with('feitoPor')
            ->get()
            ->keyBy('chave');

        $resolvers = $this->mapaDeResolvers();

        $titulos = [
            ChecklistAdministrativoDefinicao::GRUPO_CONTRATO => 'Contrato',
            ChecklistAdministrativoDefinicao::GRUPO_ENTRADA  => 'Estrutura e Comunicação',
        ];

        $grupos = [];

        foreach ($itens as $definicao) {
            if ($definicao['natureza'] === ChecklistAdministrativoDefinicao::NATUREZA_AUTO) {
                // Rodar os 4 resolvers a cada chamada é aceitável e
                // deliberado: são leituras de coluna local, sem rede —
                // diferente do resolver de grant do motor de Onboarding, que
                // sonda API externa e por isso é assíncrono lá.
                $resultado = $resolvers[$definicao['auto_fonte']]->resolver($company);
                $linha = $this->persistirResultadoAutomatico($company, $definicao, $resultado);
                $motivo = $resultado->ehConcluido() ? null : $resultado->motivo;
            } else {
                // Item manual: só lê a linha existente. Nunca cria linha
                // aqui — só concluirManualmente() escreve.
                $linha = $linhasManuais->get($definicao['chave']);
                $motivo = null;
            }

            $grupoChave = $definicao['grupo'];

            if (! isset($grupos[$grupoChave])) {
                $grupos[$grupoChave] = [
                    'chave'  => $grupoChave,
                    'titulo' => $titulos[$grupoChave],
                    'itens'  => [],
                ];
            }

            $grupos[$grupoChave]['itens'][] = $this->achatarItem($definicao, $linha, $motivo);
        }

        return [
            'exige_contrato' => $exigeContrato,
            'grupos'         => $grupos,
            'progresso'      => $this->calcularProgresso($grupos, count($itens)),
        ];
    }

    /**
     * Progresso do checklist — mesmo contrato de props `{feitos, total,
     * percentual}` de `ProgressoBarra.jsx`, para o componente ser reusado
     * por import direto (plano 152-09) em vez de duplicado.
     *
     * **O denominador vem do catálogo, nunca da tabela** (D-10). A fórmula
     * aqui é mais simples que a do motor de Onboarding: lá o denominador é
     * `count($passos) - $naoAplicaveis`, porque a régua nasce copiada para
     * linhas e a isenção é marcada numa delas. Aqui a D-02 proíbe o estado
     * "não aplicável" e a D-07 resolve a isenção NO NASCIMENTO — o item nem
     * é instanciado para empresa isenta — então não há o que subtrair.
     *
     * Consequência prática: uma linha gravada em
     * `checklist_administrativo_itens` com uma `chave` que saiu do catálogo
     * (renomeação em deploy) é lixo órfão — não aparece na tela (porque a
     * tela itera pelo catálogo, nunca pela tabela) e NÃO entra no
     * denominador; a ficha continua podendo fechar 100%. Mesma família de
     * bug já registrada para o checklist de Onboarding: renomear o RÓTULO
     * de um item nunca pode trocar a `chave`.
     *
     * Reaproveita `paraEmpresa()` para não montar o checklist duas vezes
     * quando o chamador precisar dos dois — a duplicação que se evita é de
     * TRABALHO, não de API pública.
     *
     * @return array{feitos:int, total:int, percentual:int}
     */
    public function progresso(Company $company): array
    {
        return $this->paraEmpresa($company)['progresso'];
    }

    /**
     * Marca um item manualmente, gravando autoria (D-11): `feito_por` e
     * `feito_em` sempre juntos, no mesmo `updateOrCreate`.
     *
     * Recusa (`\DomainException`) em três situações, nesta ordem: (1) chave
     * fora do catálogo fechado — defesa contra chave livre vinda de
     * requisição; (2) item do grupo Contrato numa empresa isenta (D-07); (3)
     * item que tem `auto_fonte` declarado no catálogo, salvo `$forcar`
     * (D-13) — os 4 itens automáticos não podem ser marcados à mão pela
     * tela.
     *
     * `$forcar` existe por paridade com o molde do motor de Onboarding
     * (`OnboardingEngineService::concluirManualmente()`), mesma disciplina
     * T-150-02 de nunca aceitar ator cru — mas **não é exposto por HTTP
     * nesta fase**: o controller do plano 152-08 nunca encaminha este
     * parâmetro.
     */
    public function concluirManualmente(Company $company, string $chave, User $usuario, bool $forcar = false): ChecklistAdministrativoItem
    {
        $definicao = ChecklistAdministrativoDefinicao::item($chave);

        if ($definicao === null) {
            throw new \DomainException(
                "Item \"{$chave}\" não existe no catálogo do checklist administrativo."
            );
        }

        if ($definicao['grupo'] === ChecklistAdministrativoDefinicao::GRUPO_CONTRATO && ! $this->exigeContrato($company)) {
            throw new \DomainException(
                "O item \"{$definicao['titulo']}\" não existe para esta empresa — o serviço contratado é isento de contrato."
            );
        }

        if ($definicao['auto_fonte'] !== null && ! $forcar) {
            throw new \DomainException(
                "O item \"{$definicao['titulo']}\" tem verificação automática — conclusão manual não é permitida."
            );
        }

        $item = ChecklistAdministrativoItem::updateOrCreate(
            ['company_id' => $company->id, 'chave' => $chave],
            [
                'status'    => ChecklistAdministrativoItem::STATUS_CONCLUIDO,
                'feito_por' => $usuario->id,
                'feito_em'  => now(),
            ]
        );

        // Trilha SECUNDÁRIA (D-11, ponto 3) — a leitura da tela vem sempre
        // das colunas feito_por/feito_em, nunca do activity log.
        activity('checklist-administrativo')
            ->performedOn($company)
            ->withProperties(['chave' => $chave, 'por' => $usuario->id])
            ->log("Item \"{$definicao['titulo']}\" marcado como concluído");

        return $item;
    }

    /**
     * Desmarca um item concluído manualmente. Recusa (`\DomainException`) se
     * o item não está concluído — nada a desmarcar. `status`, `feito_por` e
     * `feito_em` são limpos NO MESMO `save()` (D-11, ponto 2) — nunca um sem
     * o outro.
     */
    public function reabrirItem(Company $company, string $chave, User $usuario): ChecklistAdministrativoItem
    {
        $item = ChecklistAdministrativoItem::where('company_id', $company->id)
            ->where('chave', $chave)
            ->first();

        if ($item === null || $item->status !== ChecklistAdministrativoItem::STATUS_CONCLUIDO) {
            throw new \DomainException(
                "O item \"{$chave}\" não está concluído — não há o que desmarcar."
            );
        }

        $item->status = ChecklistAdministrativoItem::STATUS_ABERTO;
        $item->feito_por = null;
        $item->feito_em = null;
        $item->save();

        activity('checklist-administrativo')
            ->performedOn($company)
            ->withProperties(['chave' => $chave, 'por' => $usuario->id])
            ->log("Item \"{$chave}\" desmarcado");

        return $item;
    }

    /**
     * Gera a conexão com o sistema ECF da empresa (item 8) — chama o
     * serviço idempotente de link (`firstOrCreate`, D-14) e devolve o link.
     *
     * **Não** marca o item 8 à mão: o resolver correspondente já lê a
     * existência desta linha na próxima montagem de `paraEmpresa()`. Marcar
     * aqui criaria uma segunda fonte de verdade para o mesmo fato — a
     * mesma disciplina que o resolver do item 8 já documenta por que NÃO
     * chama este mesmo método de fábrica sozinho a cada carregamento da
     * ficha.
     */
    public function gerarConexaoEcf(Company $company, User $usuario): OnboardingLink
    {
        $link = $this->onboardingLinkService->paraEmpresa($company);

        activity('checklist-administrativo')
            ->performedOn($company)
            ->withProperties(['chave' => ChecklistAdministrativoDefinicao::AUTO_FONTE_CONEXAO_ECF, 'por' => $usuario->id])
            ->log('Link do Portal do Cliente gerado');

        return $link;
    }

    /**
     * Mapa `chave() => resolver` dos 4 resolvers automáticos, para lookup
     * por `auto_fonte` do catálogo — montado a partir das quatro instâncias
     * já injetadas no construtor.
     *
     * @return array<string, ChecklistResolver>
     */
    private function mapaDeResolvers(): array
    {
        return [
            $this->contratoEnviadoResolver->chave()    => $this->contratoEnviadoResolver,
            $this->contratoAssinadoResolver->chave()   => $this->contratoAssinadoResolver,
            $this->mlOAuthConectadoResolver->chave()   => $this->mlOAuthConectadoResolver,
            $this->conexaoEcfResolver->chave()         => $this->conexaoEcfResolver,
        ];
    }

    /**
     * `true` quando a empresa tem ao menos um `contratosServico` ATIVO cujo
     * `servico->exigeContrato()` é `true` (D-07) — mesma isenção que
     * `ContratoAdminController`/`ComercialController`/`ComercialEntradaController`
     * já respeitam, nada novo a inventar.
     */
    private function exigeContrato(Company $company): bool
    {
        $company->loadMissing('contratosServico.servico');

        return $company->contratosServico
            ->where('ativo', true)
            ->contains(static fn (ContratoServico $cs): bool => $cs->servico?->exigeContrato() === true);
    }

    /**
     * Aplica e PERSISTE o resultado de um resolver automático. `concluido`
     * grava `status`/`valor`/`auto_em`; qualquer outro estado grava só
     * `status = aberto` e NÃO mexe em `auto_em` já gravado — um item que já
     * fechou uma vez e temporariamente regrediu (ex.: token ML revogado
     * depois de ter conectado) não perde o carimbo de quando fechou a
     * primeira vez.
     */
    private function persistirResultadoAutomatico(Company $company, array $definicao, ChecklistResolverResultado $resultado): ChecklistAdministrativoItem
    {
        if ($resultado->ehConcluido()) {
            return ChecklistAdministrativoItem::updateOrCreate(
                ['company_id' => $company->id, 'chave' => $definicao['chave']],
                [
                    'status'  => ChecklistAdministrativoItem::STATUS_CONCLUIDO,
                    'valor'   => $resultado->valor,
                    'auto_em' => now(),
                ]
            );
        }

        return ChecklistAdministrativoItem::updateOrCreate(
            ['company_id' => $company->id, 'chave' => $definicao['chave']],
            ['status' => ChecklistAdministrativoItem::STATUS_ABERTO]
        );
    }

    /**
     * Achata a definição do catálogo + a linha persistida (quando existe)
     * no shape de payload Inertia — nunca o model inteiro.
     *
     * Regra explícita: um item `auto` nunca carrega `feito_por_nome`
     * (ninguém o marcou); um item `manual` nunca carrega `auto_em`. Se as
     * duas colunas aparecerem preenchidas na mesma linha (override forçado
     * via `$forcar`), é sintoma — a tela deve poder mostrar as duas, então
     * este método NÃO normaliza apagando uma: só espelha o que está gravado.
     *
     * @return array{chave:string, titulo:string, grupo:string, natureza:string, status:string, ajuda:string, motivo:?string, feito_por_nome:?string, feito_em:?string, auto_em:?string}
     */
    private function achatarItem(array $definicao, ?ChecklistAdministrativoItem $linha, ?string $motivo): array
    {
        return [
            'chave'          => $definicao['chave'],
            'titulo'         => $definicao['titulo'],
            'grupo'          => $definicao['grupo'],
            'natureza'       => $definicao['natureza'],
            'status'         => $linha?->status ?? ChecklistAdministrativoItem::STATUS_ABERTO,
            'ajuda'          => $definicao['ajuda'],
            'motivo'         => $motivo,
            'feito_por_nome' => $linha?->feitoPor?->name,
            'feito_em'       => $linha?->feito_em?->toIso8601String(),
            'auto_em'        => $linha?->auto_em?->toIso8601String(),
        ];
    }

    /**
     * `total` vem da CONTAGEM DO CATÁLOGO recebida por parâmetro (D-10) —
     * nunca de `ChecklistAdministrativoItem::where(...)->count()`. `feitos`
     * é contado sobre os itens JÁ ACHATADOS de `$grupos` (que só existem
     * para chaves do catálogo), então uma linha órfã na tabela nunca entra
     * em nenhum dos dois números.
     *
     * @param array<string, array{chave:string, titulo:string, itens:array<int, array<string, mixed>>}> $grupos
     * @return array{feitos:int, total:int, percentual:int}
     */
    private function calcularProgresso(array $grupos, int $total): array
    {
        $feitos = 0;

        foreach ($grupos as $grupo) {
            foreach ($grupo['itens'] as $item) {
                if ($item['status'] === ChecklistAdministrativoItem::STATUS_CONCLUIDO) {
                    $feitos++;
                }
            }
        }

        return [
            'feitos'     => $feitos,
            'total'      => $total,
            'percentual' => $total > 0 ? (int) round($feitos / $total * 100) : 0,
        ];
    }
}
