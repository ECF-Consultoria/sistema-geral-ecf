<?php

namespace App\Services\ChecklistAdministrativo;

use App\Contracts\ChecklistResolver;
use App\Models\ChecklistAdministrativoItem;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\User;
use App\Services\ChecklistAdministrativo\Resolvers\ConexaoEcfResolver;
use App\Services\ChecklistAdministrativo\Resolvers\ContratoAssinadoResolver;
use App\Services\ChecklistAdministrativo\Resolvers\ContratoEnviadoResolver;
use App\Services\ChecklistAdministrativo\Resolvers\MlOAuthConectadoResolver;

/**
 * ChecklistAdministrativoService — Fase 152 Plano 05. Coração de leitura e
 * escrita do checklist administrativo: monta o checklist de uma empresa
 * (D-07), roda os 4 resolvers automáticos e persiste o resultado, calcula o
 * progresso a partir do catálogo (D-10) e permite marcar/desmarcar
 * manualmente com autoria (D-11).
 *
 * ⚠️ **Item automático TAMBÉM aceita marcação à mão desde 2026-09-18** — a
 * D-13 dizia o contrário, e foi estreitada por decisão do usuário ("tudo tem
 * que dar para dar check manualmente"). O override fica registrado e visível
 * como tal; ver `concluirManualmente()` e o ramo AUTO de `paraEmpresa()`, que
 * é onde ele sobrevive ao resolver.
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
            $confirmadoPeloSistema = null;

            if ($definicao['natureza'] === ChecklistAdministrativoDefinicao::NATUREZA_AUTO) {
                // Rodar os 4 resolvers a cada chamada é aceitável e
                // deliberado: são leituras de coluna local, sem rede —
                // diferente do resolver de grant do motor de Onboarding, que
                // sonda API externa e por isso é assíncrono lá.
                $resultado = $resolvers[$definicao['auto_fonte']]->resolver($company);
                $confirmadoPeloSistema = $resultado->ehConcluido();

                // ⚠️ A MARCAÇÃO À MÃO DE ITEM AUTOMÁTICO DEPENDE DESTA LINHA
                // (2026-09-18, decisão do usuário: "tudo tem que dar para dar
                // check manualmente").
                //
                // O resolver roda a CADA montagem da ficha, e antes disto o
                // ramo "não concluído" gravava `status = aberto` por cima de
                // tudo — uma marcação manual sobrevivia ao clique e morria no
                // próximo F5, sem erro nenhum na tela para denunciar.
                //
                // O sinal de que houve override é `feito_por` preenchido numa
                // linha de item AUTOMÁTICO: só `concluirManualmente()` escreve
                // essa coluna, e nenhum resolver a toca. Isso evita coluna
                // nova numa tabela que já tem dado em produção — migration
                // assim exige fase GSD (CLAUDE.md) — e ganha de brinde o
                // desfazer: `reabrirItem()` já limpa `feito_por`, então
                // "Desmarcar" devolve o item ao controle do resolver.
                $forcado = $linhasManuais->get($definicao['chave'])?->feito_por !== null;

                $linha = $this->persistirResultadoAutomatico($company, $definicao, $resultado, $forcado);

                // Item forçado não mostra o motivo do resolver: ele está
                // concluído por decisão humana, e o "o que falta" seria
                // contradição na mesma linha. O que a tela diz nesse caso vem
                // de `auto_confirmado = false` (ver `achatarItem()`).
                $motivo = ($resultado->ehConcluido() || $forcado) ? null : $resultado->motivo;
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

            $grupos[$grupoChave]['itens'][] = $this->achatarItem($definicao, $linha, $motivo, $confirmadoPeloSistema);
        }

        // Segunda passada — a TRAVA de ordem (2026-09-18). Só pode ser
        // calculada aqui, depois que todos os itens existem: `depende_de`
        // olha para o status de OUTROS itens, e na primeira passada os
        // posteriores ainda não foram montados.
        $statusPorChave = [];
        foreach ($grupos as $grupo) {
            foreach ($grupo['itens'] as $item) {
                $statusPorChave[$item['chave']] = $item['status'];
            }
        }

        foreach ($grupos as $grupoChave => $grupo) {
            foreach ($grupo['itens'] as $indice => $item) {
                $bloqueio = $item['status'] === ChecklistAdministrativoItem::STATUS_CONCLUIDO
                    ? null
                    : $this->bloqueio($company, ChecklistAdministrativoDefinicao::item($item['chave']), $statusPorChave);

                $grupos[$grupoChave]['itens'][$indice]['bloqueio']      = $bloqueio['motivo'] ?? null;
                $grupos[$grupoChave]['itens'][$indice]['bloqueio_tipo'] = $bloqueio['tipo'] ?? null;
            }
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
     * Recusa (`\DomainException`) em quatro situações, nesta ordem: (1) chave
     * fora do catálogo fechado — defesa contra chave livre vinda de
     * requisição; (2) item do grupo Contrato numa empresa isenta (D-07); (3)
     * item que tem `auto_fonte` declarado no catálogo, salvo `$forcar`; (4) a
     * trava de ordem/evidência de 2026-09-18, que **`$forcar` não dispensa**.
     *
     * ### `$forcar` passou a ser exposto por HTTP em 2026-09-18
     * Até aqui ele existia só por paridade com o motor de Onboarding e o
     * controller nunca o encaminhava: a D-13 dizia que item automático não se
     * marca à mão, porque seria marcar concluído sem evidência.
     *
     * O usuário decidiu o contrário — "tudo tem que dar para dar check
     * manualmente" — e a razão é operacional: o resolver pode demorar a
     * enxergar um fato que já aconteceu (contrato assinado fora da Clicksign,
     * grant concedido por outro caminho), e a entrada inteira ficava travada
     * esperando um sinal que nunca vinha.
     *
     * A D-13 não foi jogada fora, foi ESTREITADA: a marcação à mão de item
     * automático fica REGISTRADA e VISÍVEL como tal — `feito_por` preenchido
     * numa linha automática é o override, `paraEmpresa()` devolve
     * `forcado: true` com `auto_confirmado: false`, e a tela diz "marcado à
     * mão, o sistema ainda não confirmou" em vez de fingir confirmação.
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

        // Trava de ORDEM e de EVIDÊNCIA (2026-09-18). Mesma disciplina do
        // ADMIN-05: a régua que desabilita o botão na tela é ESTA, lida do
        // payload — nunca uma segunda implementação no JSX. O servidor a
        // reavalia aqui, no instante do clique.
        //
        // ⚠️ Roda mesmo com `$forcar`. `$forcar` libera marcar à mão um item
        // que o SISTEMA fecharia sozinho; ele nunca libera furar a ordem que o
        // usuário pediu. São duas regras diferentes, e confundi-las deixaria
        // as boas-vindas marcáveis antes do e-mail colaborador pela porta do
        // override.
        $statusPorChave = [];
        foreach ($this->paraEmpresa($company)['grupos'] as $grupo) {
            foreach ($grupo['itens'] as $item) {
                $statusPorChave[$item['chave']] = $item['status'];
            }
        }

        $bloqueio = $this->bloqueio($company, $definicao, $statusPorChave);

        if ($bloqueio !== null) {
            throw new \DomainException($bloqueio['motivo']);
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
    private function persistirResultadoAutomatico(Company $company, array $definicao, ChecklistResolverResultado $resultado, bool $forcado = false): ChecklistAdministrativoItem
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

        // Override humano vivo: o resolver ainda não vê o fato, mas alguém
        // afirmou que ele aconteceu. Não reabrir — era exatamente isto que
        // apagava a marcação manual no carregamento seguinte. `feito_por` e
        // `feito_em` ficam onde estão; só `reabrirItem()` os limpa.
        if ($forcado) {
            return ChecklistAdministrativoItem::updateOrCreate(
                ['company_id' => $company->id, 'chave' => $definicao['chave']],
                ['status' => ChecklistAdministrativoItem::STATUS_CONCLUIDO]
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
     * @return array{chave:string, titulo:string, grupo:string, natureza:string, status:string, ajuda:string, motivo:?string, feito_por_nome:?string, feito_em:?string, auto_em:?string, depende_de:array<int,string>, exige_valor:?string, bloqueio:?string, bloqueio_tipo:?string, forcado:bool, auto_confirmado:?bool}
     */
    private function achatarItem(array $definicao, ?ChecklistAdministrativoItem $linha, ?string $motivo, ?bool $confirmadoPeloSistema = null): array
    {
        // `forcado` só faz sentido em item AUTOMÁTICO: é a marcação humana de
        // algo que o sistema observaria sozinho. Em item manual, `feito_por`
        // preenchido é o funcionamento normal, não override.
        $forcado = $definicao['natureza'] === ChecklistAdministrativoDefinicao::NATUREZA_AUTO
            && $linha?->feito_por !== null;

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
            // Chaves do catálogo que a TELA precisa para desenhar a trava e o
            // campo de evidência. `bloqueio` NÃO é preenchido aqui — depende
            // do status dos outros itens e só existe depois da segunda passada
            // de `paraEmpresa()`.
            'depende_de'     => $definicao['depende_de'] ?? [],
            'exige_valor'    => $definicao['exige_valor'] ?? null,
            'bloqueio'       => null,
            'bloqueio_tipo'  => null,
            // Item automático fechado À MÃO (2026-09-18). A tela precisa das
            // duas informações separadas para não mentir: `forcado` diz que
            // alguém afirmou o fato, `auto_confirmado` diz se o sistema já o
            // viu. Fechado à mão e ainda não observado é um estado legítimo —
            // e a tela avisa, em vez de exibir um "confirmado" que ninguém
            // confirmou.
            'forcado'          => $forcado,
            'auto_confirmado'  => $confirmadoPeloSistema,
        ];
    }

    /**
     * A trava de ORDEM e de EVIDÊNCIA de um item ainda aberto (2026-09-18).
     * Devolve a frase que explica o que falta, ou `null` quando nada trava.
     *
     * Duas regras, nesta ordem — a evidência primeiro porque é a que o
     * operador resolve sem sair da linha:
     *
     * 1. `exige_valor` — a coluna de `companies` nomeada pelo catálogo precisa
     *    estar preenchida. Hoje só `email_colaborador_criado` declara isso: o
     *    endereço é a única evidência possível do item, e é ele que entra na
     *    mensagem de boas-vindas.
     * 2. `depende_de` — os itens listados precisam estar concluídos. Chave
     *    ausente de `$statusPorChave` NÃO trava: é o caso da empresa isenta de
     *    contrato (D-07), cujos itens do grupo Contrato nem são instanciados —
     *    exigir um item que não existe travaria a ficha para sempre.
     *
     * Régua PURA: não escreve nada, não consulta o banco além do que já está
     * carregado em `$company`.
     *
     * @param array{chave:string, titulo:string, depende_de?:array<int,string>, exige_valor?:string} $definicao
     * @param array<string, string> $statusPorChave
     * @return array{tipo:string, motivo:string}|null
     */
    private function bloqueio(Company $company, array $definicao, array $statusPorChave): ?array
    {
        $coluna = $definicao['exige_valor'] ?? null;

        if ($coluna !== null && blank($company->{$coluna})) {
            return [
                'tipo'   => 'valor',
                'motivo' => 'Preencha o campo abaixo para concluir este item.',
            ];
        }

        $faltando = [];

        foreach ($definicao['depende_de'] ?? [] as $chaveDependencia) {
            if (! array_key_exists($chaveDependencia, $statusPorChave)) {
                continue;
            }

            if ($statusPorChave[$chaveDependencia] !== ChecklistAdministrativoItem::STATUS_CONCLUIDO) {
                $faltando[] = ChecklistAdministrativoDefinicao::item($chaveDependencia)['titulo'] ?? $chaveDependencia;
            }
        }

        if ($faltando !== []) {
            return [
                'tipo'   => 'dependencia',
                'motivo' => count($faltando) === 1
                    ? "Conclua \"{$faltando[0]}\" antes deste item."
                    : 'Conclua antes: '.implode(', ', $faltando).'.',
            ];
        }

        return null;
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
