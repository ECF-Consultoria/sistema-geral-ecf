<?php

namespace App\Services\Trilha\Ondas;

use App\Models\Company;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Models\User;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * O Planejamento dentro da trilha (2ª entrega, 02/11/2026): criar, editar e apagar onda; pôr, mover e tirar oferta.
 *
 * Regras (o porquê de cada uma no desenho da 2ª entrega):
 * - A data é o dia em que o robô COMEÇA (São Paulo), entre hoje e hoje + 365. Hoje = "começar agora": a soltura
 *   roda no próprio pedido. Data passada é recusada.
 * - Até o dia chegar dá para mudar a data, mover, tirar e apagar. Depois, só renomear e pôr oferta nova (que começa
 *   na hora): o robô já foi chamado.
 * - Só entra em onda a oferta que ainda não andou e tem produto — a da etapa 3. Um item fica numa onda só.
 * - O Combo nasce da Simples do produto: `c-{p}-{N}` só fica numa onda com data igual ou posterior à da `s-{p}`
 *   (salvo se a Simples já andou). Simples sem onda entra JUNTO, na mesma onda, com aviso. Tirar a Simples, movê-la
 *   para depois de um combo dela, mudar a data da onda dela para depois do combo ou apagar a onda dela com o combo
 *   noutra é recusado com a lista dos combos — mexer sozinho mudaria uma data que alguém escolheu. A conferência
 *   olha só os pares que o pedido toca: um combo de OUTRO produto nunca trava o planejamento deste.
 *
 * - Em LOTE (R12, 04/11/2026, {@see planejarEmLote()}): as mesmas regras, item a item ({@see porNoPlano()}); o que não
 *   passa é recusado com o motivo e o resto entra.
 *
 * Tudo sob a trava da empresa (`Company::lockForUpdate`, a mesma do aceite e do "Montar kit" do Portal), com o
 * rastro no `RegistroEstrutura`. A soltura (quando a onda já começou) roda DEPOIS do commit.
 */
class OndasDaTrilha
{
    public const DIAS_A_FRENTE = 365;

    /** Quantos itens o "Pôr na onda" em lote aceita por pedido (R12, 04/11/2026). */
    public const MAXIMO_DO_LOTE = 100;

    public function __construct(private SoltarOndas $soltura) {}

    public function criar(Company $empresa, User $quem, string $nome, string $data): TrilhaOnda
    {
        $nome = self::nome($nome);
        $data = self::data($data);

        $onda = DB::transaction(function () use ($empresa, $quem, $nome, $data) {
            Company::query()->whereKey($empresa->id)->lockForUpdate()->first();
            self::conferirNomeLivre((int) $empresa->id, $nome);
            $onda = TrilhaOnda::create(['company_id' => $empresa->id, 'nome' => $nome, 'data' => $data, 'criada_por' => $quem->id]);
            RegistroEstrutura::registrar(AtorDoPortal::daEquipe($quem), $empresa, $onda, 'trilha_onda_criada',
                "Onda {$nome} criada: o robô começa em ".self::diaCurto($data), ['via' => 'trilha', 'onda_id' => (int) $onda->id, 'data' => $data]);

            return $onda;
        });

        if ($onda->comecou()) {
            $this->soltarDepoisDoCommit($empresa);
        }

        return $onda->fresh();
    }

    public function editar(TrilhaOnda $onda, User $quem, ?string $nome, ?string $data): TrilhaOnda
    {
        $empresa = Company::query()->findOrFail($onda->company_id);
        $nome = $nome !== null ? self::nome($nome) : null;
        $data = $data !== null ? self::data($data) : null;

        $onda = DB::transaction(function () use ($empresa, $onda, $quem, $nome, $data) {
            Company::query()->whereKey($empresa->id)->lockForUpdate()->first();
            $onda = TrilhaOnda::query()->whereKey($onda->id)->lockForUpdate()->firstOrFail();
            $antes = ['nome' => $onda->nome, 'data' => $onda->dia()];
            if ($nome !== null) {
                self::conferirNomeLivre((int) $empresa->id, $nome, (int) $onda->id);
            }

            if ($data !== null && $data !== $onda->dia()) {
                if ($onda->comecou()) {
                    throw ValidationException::withMessages(['data' => "A {$onda->nome} já começou: a data não muda mais."]);
                }
                // A data nova não pode pôr a Simples depois de um combo dela (nem o combo antes da Simples dele). A
                // linha de combo órfã ou recriada sai antes: ela não é o combo de hoje.
                $itens = TrilhaDaEmpresa::itens($empresa);
                self::limparCombosOrfaos($empresa, $itens);
                $ondas = OndasDaEmpresa::ler((int) $empresa->id);
                $plano = self::planoAtual($itens, $ondas);
                $tocadas = [];
                foreach ($plano as $chave => $p) {
                    if ($p !== null && $p['id'] === (int) $onda->id) {
                        $plano[$chave]['dia'] = $data;
                        $tocadas[] = $chave;
                    }
                }
                self::conferirCombos($plano, $itens, $tocadas);
            }
            $onda->update(array_filter(['nome' => $nome, 'data' => $data], fn ($v) => $v !== null));
            // Data nova (inclusive o "começar agora"): a contagem dos itens recomeça daqui — senão o alarme "ainda não
            // começou" contaria do começo do dia de hoje, horas antes de o robô ser chamado.
            if ($data !== null && $antes['data'] !== $onda->dia()) {
                $onda->itens()->update(['na_onda_desde' => now(), 'pedido_em' => null, 'repescado_em' => null]);
            }

            if ($antes !== ['nome' => $onda->nome, 'data' => $onda->dia()]) {
                RegistroEstrutura::registrar(AtorDoPortal::daEquipe($quem), $empresa, $onda, 'trilha_onda_editada',
                    "Onda {$onda->nome} alterada", ['via' => 'trilha', 'onda_id' => (int) $onda->id, 'antes' => $antes,
                        'depois' => ['nome' => $onda->nome, 'data' => $onda->dia()]]);
            }

            return $onda;
        });

        if ($onda->comecou()) {
            $this->soltarDepoisDoCommit($empresa);
        }

        return $onda->fresh();
    }

    /** @return int quantas ofertas saíram da onda */
    public function excluir(TrilhaOnda $onda, User $quem): int
    {
        $empresa = Company::query()->findOrFail($onda->company_id);

        return DB::transaction(function () use ($empresa, $onda, $quem) {
            Company::query()->whereKey($empresa->id)->lockForUpdate()->first();
            $onda = TrilhaOnda::query()->whereKey($onda->id)->lockForUpdate()->firstOrFail();
            if ($onda->comecou()) {
                throw ValidationException::withMessages(['onda' => "A {$onda->nome} já começou: o robô já foi chamado, ela não pode ser apagada."]);
            }

            // Os itens saem da onda: a Simples não pode sair deixando um combo dela noutra onda. Antes, a linha de
            // combo órfã ou recriada sai (ela não é o combo de hoje e travaria a conferência à toa).
            $itens = TrilhaDaEmpresa::itens($empresa);
            self::limparCombosOrfaos($empresa, $itens);
            $plano = self::planoAtual($itens, OndasDaEmpresa::ler((int) $empresa->id));
            $tocadas = [];
            foreach ($plano as $chave => $p) {
                if ($p !== null && $p['id'] === (int) $onda->id) {
                    $plano[$chave] = null;
                    $tocadas[] = $chave;
                }
            }
            self::conferirCombos($plano, $itens, $tocadas);

            $chaves = $onda->itens()->get()->map(fn (TrilhaOndaItem $l) => $l->chave())->all();
            RegistroEstrutura::registrar(AtorDoPortal::daEquipe($quem), $empresa, null, 'trilha_onda_apagada',
                "Onda {$onda->nome} apagada: ".count($chaves).' oferta(s) saíram da onda',
                ['via' => 'trilha', 'onda_id' => (int) $onda->id, 'nome' => $onda->nome, 'data' => $onda->dia(), 'itens' => $chaves]);
            $onda->delete();

            return count($chaves);
        });
    }

    /**
     * Põe os itens na onda (ou move de uma onda que ainda não começou), ou tira (`$ondaId` null).
     *
     * @param  list<string>  $chaves  `s-12`, `c-12-2`, `o-55`
     * @return array{mudados: list<string>, avisos: list<string>, onda: ?TrilhaOnda, sem_onda: bool}
     */
    public function planejar(Company $empresa, User $quem, array $chaves, ?int $ondaId): array
    {
        $resultado = DB::transaction(function () use ($empresa, $quem, $chaves, $ondaId) {
            Company::query()->whereKey($empresa->id)->lockForUpdate()->first();
            $itens = TrilhaDaEmpresa::itens($empresa);
            self::limparCombosOrfaos($empresa, $itens);
            $ondas = OndasDaEmpresa::ler((int) $empresa->id);
            $destino = null;
            if ($ondaId !== null) {
                $destino = $ondas->onda($ondaId) ?? throw ValidationException::withMessages(['onda_id' => 'Essa onda não existe nesta loja.']);
            }

            // O plano: chave → onda de destino (null = sem onda). Começa com o que já está gravado.
            $plano = self::planoAtual($itens, $ondas);

            $mudados = [];
            $avisos = [];
            foreach (array_values(array_unique($chaves)) as $chave) {
                $passo = self::porNoPlano($chave, $itens, $ondas, $destino, $plano);
                $mudados = [...$mudados, ...$passo['mudados']];
                $avisos = [...$avisos, ...$passo['avisos']];
            }

            $mudados = array_values(array_unique($mudados));
            self::conferirCombos($plano, $itens, $mudados);
            if ($mudados === []) {
                return ['mudados' => [], 'avisos' => $avisos, 'onda' => $destino, 'sem_onda' => false];
            }

            self::gravarPlano($empresa, $quem, $mudados, $plano, $ondas, $destino);

            // Tirar: "volta para Sem onda" só é verdade para quem ainda espera onda (tem produto e não andou); a que
            // andou e a sem produto saem com o status delas (revisão de 02/11/2026).
            $semOnda = $destino === null
                && array_filter($mudados, fn (string $c) => ($itens[$c]['andou'] ?? true) || ($itens[$c]['sem_produto'] ?? true)) === [];

            return ['mudados' => $mudados, 'avisos' => $avisos, 'onda' => $destino, 'sem_onda' => $semOnda];
        });

        if ($resultado['mudados'] !== [] && $resultado['onda'] !== null && $resultado['onda']->comecou()) {
            $this->soltarDepoisDoCommit($empresa);
        }

        return $resultado;
    }

    /**
     * R12 do 1º teste do usuário (04/11/2026): pôr VÁRIOS itens numa onda de uma vez, com as MESMAS regras de pôr um
     * ({@see porNoPlano()} e {@see conferirCombos()}, as do {@see planejar()}), mas com a resposta POR ITEM: o que não
     * passa é recusado com o motivo e o resto entra — ao contrário do `planejar`, em que um item recusado barra o pedido.
     *
     * Nada parcial DENTRO de um item: o Combo e a Simples que ele puxa entram juntos ou nenhum dos dois (o plano volta ao
     * que era antes do item). As Simples vêm primeiro, depois os Combos e o resto: o Combo marcado junto com a Simples
     * dele não é recusado só porque chegou antes dela. Tudo numa transação, sob a trava da empresa, com UM registro no
     * histórico e UMA soltura (onda que já começou) depois do commit.
     *
     * @param  list<string>  $chaves  `s-12`, `c-12-2`, `o-55`
     * @return array{onda: TrilhaOnda, resultados: list<array{item: string, nome: ?string, situacao: 'entrou'|'ja_estava'|'recusado', motivo: ?string, junto: list<string>}>, mudados: list<string>, avisos: list<string>}
     */
    public function planejarEmLote(Company $empresa, User $quem, array $chaves, int $ondaId): array
    {
        $resultado = DB::transaction(function () use ($empresa, $quem, $chaves, $ondaId) {
            Company::query()->whereKey($empresa->id)->lockForUpdate()->first();
            $itens = TrilhaDaEmpresa::itens($empresa);
            self::limparCombosOrfaos($empresa, $itens);
            $ondas = OndasDaEmpresa::ler((int) $empresa->id);
            $destino = $ondas->onda($ondaId) ?? throw ValidationException::withMessages(['onda_id' => 'Essa onda não existe nesta loja.']);
            $plano = self::planoAtual($itens, $ondas);

            $pedidas = array_values(array_unique($chaves));
            $porItem = [];
            $mudados = [];
            $avisos = [];
            foreach (self::naOrdemDoLote($pedidas) as $chave) {
                $antes = $plano;
                try {
                    $passo = self::porNoPlano($chave, $itens, $ondas, $destino, $plano);
                    self::conferirCombos($plano, $itens, $passo['mudados']);
                } catch (ValidationException $e) {
                    // O item inteiro fica de fora — inclusive a Simples que ele puxaria.
                    $plano = $antes;
                    $porItem[$chave] = ['situacao' => 'recusado', 'motivo' => self::primeiraMensagem($e), 'junto' => []];

                    continue;
                }
                $porItem[$chave] = $passo['mudados'] === []
                    ? ['situacao' => 'ja_estava', 'motivo' => null, 'junto' => []]
                    : ['situacao' => 'entrou', 'motivo' => null, 'junto' => array_values(array_diff($passo['mudados'], [$chave]))];
                $mudados = [...$mudados, ...$passo['mudados']];
                $avisos = [...$avisos, ...$passo['avisos']];
            }
            $mudados = array_values(array_unique($mudados));

            if ($mudados !== []) {
                $recusados = array_filter($porItem, fn (array $r) => $r['situacao'] === 'recusado');
                self::gravarPlano($empresa, $quem, $mudados, $plano, $ondas, $destino,
                    ['lote' => true, 'recusados' => array_map(fn (array $r) => $r['motivo'], $recusados)]);
            }

            // Na ordem do pedido (a da tela), com o nome do item: a resposta se lê sem a trilha recarregada.
            $resultados = array_map(fn (string $chave) => [
                'item' => $chave,
                'nome' => isset($itens[$chave]) ? (string) $itens[$chave]['nome'] : null,
                ...$porItem[$chave],
            ], $pedidas);

            return ['onda' => $destino, 'resultados' => $resultados, 'mudados' => $mudados, 'avisos' => array_values(array_unique($avisos))];
        });

        if ($resultado['mudados'] !== [] && $resultado['onda']->comecou()) {
            $this->soltarDepoisDoCommit($empresa);
        }

        return $resultado;
    }

    /**
     * UM item no plano, com as regras de pôr/mover/tirar — o passo que o {@see planejar()} e o {@see planejarEmLote()}
     * repetem: o item existe, tem produto e não andou (tirar vale também para quem andou); a onda em que ele está não
     * começou (ou é a mesma); o Combo sem a Simples planejada a puxa para a mesma onda. Mexe só no `$plano` (nada é
     * gravado aqui); a regra Combo × Simples é conferida por quem chama ({@see conferirCombos()}), com o que mudou.
     * Lança `ValidationException` (`itens`) quando o item não pode.
     *
     * @param  array<string, array>  $itens  {@see TrilhaDaEmpresa::itens()}
     * @param  array<string, ?array{id: int, nome: string, dia: string}>  $plano
     * @return array{mudados: list<string>, avisos: list<string>}  vazio = o item já estava ali
     */
    private static function porNoPlano(string $chave, array $itens, OndasDaEmpresa $ondas, ?TrilhaOnda $destino, array &$plano): array
    {
        // Tirar vale também para quem já andou (alguém preparou ou publicou à mão antes da data): ele só sai.
        $item = self::itemPlanejavel($chave, $itens, tirando: $destino === null);
        $atual = $ondas->ondaDoItem($chave);
        if ($atual !== null && $atual->comecou() && $atual->id !== $destino?->id) {
            throw ValidationException::withMessages(['itens' => "A {$atual->nome} já começou: \"{$item['nome']}\" não muda mais de onda."]);
        }
        if ($atual?->id === $destino?->id) {
            return ['mudados' => [], 'avisos' => []];
        }
        $paraOnde = $destino !== null ? self::resumo($destino) : null;
        $plano[$chave] = $paraOnde;
        $mudados = [$chave];
        $avisos = [];

        // Combo sem a Simples planejada: a Simples entra junto, na mesma onda.
        if ($destino !== null && str_starts_with($chave, 'c-')) {
            $simples = 's-'.$item['produto_id'];
            if (isset($itens[$simples]) && ! $itens[$simples]['andou'] && $plano[$simples] === null) {
                $plano[$simples] = $paraOnde;
                $mudados[] = $simples;
                $avisos[] = "A simples de {$itens[$simples]['nome']} entrou junto, na {$destino->nome}: o combo nasce dela.";
            }
        }

        return ['mudados' => $mudados, 'avisos' => $avisos];
    }

    /**
     * Grava o que mudou no plano (entrar, mudar e sair de onda) e o rastro no histórico — UM registro por pedido.
     *
     * @param  list<string>  $mudados
     * @param  array<string, ?array{id: int, nome: string, dia: string}>  $plano
     * @param  array<string, mixed>  $extras  o que o histórico leva a mais (o lote: `lote`, `recusados`)
     */
    private static function gravarPlano(Company $empresa, User $quem, array $mudados, array $plano, OndasDaEmpresa $ondas, ?TrilhaOnda $destino, array $extras = []): void
    {
        $de = [];
        foreach ($mudados as $chave) {
            $de[$chave] = $ondas->ondaDoItem($chave)?->id;
            $linha = $ondas->linhaDoItem($chave);
            if ($plano[$chave] === null) {
                $linha?->delete();

                continue;
            }
            // Entrou (ou mudou de) onda agora: o pedido e a repescagem recomeçam da onda nova.
            $campos = ['onda_id' => $plano[$chave]['id'], 'na_onda_desde' => now(), 'pedido_em' => null, 'repescado_em' => null, 'incluido_por' => $quem->id];
            if ($linha !== null) {
                $linha->update($campos);
            } else {
                TrilhaOndaItem::create([...TrilhaOndaItem::colunasDaChave($chave), 'company_id' => $empresa->id, ...$campos]);
            }
        }

        RegistroEstrutura::registrar(AtorDoPortal::daEquipe($quem), $empresa, $destino, 'trilha_itens_na_onda',
            $destino !== null
                ? count($mudados).' oferta(s) na '.$destino->nome.' (começa em '.self::diaCurto($destino->dia()).')'
                : count($mudados).' oferta(s) tiradas da onda',
            ['via' => 'trilha', 'onda_id' => $destino?->id, 'itens' => $mudados, 'de' => $de, ...$extras]);
    }

    /**
     * A ordem em que o lote confere os itens: as Simples, os Combos, o resto — cada grupo na ordem do pedido. Assim o
     * Combo marcado com a Simples dele a encontra já no plano novo (e não é recusado por "a simples começaria depois").
     *
     * @param  list<string>  $chaves
     * @return list<string>
     */
    private static function naOrdemDoLote(array $chaves): array
    {
        $grupo = fn (string $c) => match (true) {
            str_starts_with($c, 's-') => 0,
            str_starts_with($c, 'c-') => 1,
            default                   => 2,
        };
        $posicao = array_flip($chaves);
        usort($chaves, fn (string $a, string $b) => [$grupo($a), $posicao[$a]] <=> [$grupo($b), $posicao[$b]]);

        return $chaves;
    }

    /** A 1ª mensagem da recusa — o texto da regra, em português, como o `planejar` devolve no 422. */
    private static function primeiraMensagem(ValidationException $e): string
    {
        foreach ($e->errors() as $mensagens) {
            foreach ((array) $mensagens as $m) {
                if (is_string($m) && trim($m) !== '') {
                    return $m;
                }
            }
        }

        return 'Esta oferta não pode entrar nesta onda.';
    }

    /**
     * A soltura no próprio pedido (onda de hoje, item posto numa onda que já começou), DEPOIS do commit. Uma falha
     * aqui não pode virar 500: o que foi pedido já está gravado, e uma nova tentativa da pessoa criaria uma onda
     * duplicada. Registra e segue — a passada agendada (15 minutos) solta de novo.
     */
    private function soltarDepoisDoCommit(Company $empresa): void
    {
        try {
            $this->soltura->daEmpresa($empresa);
        } catch (\Throwable $e) {
            Log::error("[Trilha] Soltura no pedido da empresa {$empresa->id} falhou; a passada agendada tenta de novo: ".$e->getMessage());
        }
    }

    /**
     * Apaga as linhas de Combo cujo combo deixou de existir (todas as cores apagadas), em qualquer onda — senão um
     * Combo N aceito de novo herdaria a onda antiga. Quem apaga a última cor já chama isto na hora
     * (`TrilhaComboExcluidoObserver`); a soltura, o planejar, editar/apagar onda e o "Concluir mapeamento" chamam de
     * novo, como rede de segurança. Só a órfã de verdade: o combo que ganhou uma cor e perdeu outra nunca deixou de
     * existir e fica na onda dele.
     *
     * @param  array<string, array>  $itens  {@see TrilhaDaEmpresa::itens()}
     */
    public static function limparCombosOrfaos(Company $empresa, array $itens): int
    {
        $orfas = TrilhaOndaItem::query()
            ->where('company_id', $empresa->id)
            ->whereNotNull('produto_id')
            ->where('quantidade', '>=', 2)
            ->get()
            ->reject(fn (TrilhaOndaItem $l) => isset($itens[$l->chave()]));
        foreach ($orfas as $l) {
            $l->delete();
        }

        return $orfas->count();
    }

    /**
     * O plano gravado: cada item da trilha → a onda dele (id, nome e dia), ou null.
     *
     * @param  array<string, array>  $itens
     * @return array<string, ?array{id: int, nome: string, dia: string}>
     */
    private static function planoAtual(array $itens, OndasDaEmpresa $ondas): array
    {
        $plano = [];
        foreach (array_keys($itens) as $chave) {
            $onda = $ondas->ondaDoItem($chave);
            $plano[$chave] = $onda !== null ? self::resumo($onda) : null;
        }

        return $plano;
    }

    /** @return array{id: int, nome: string, dia: string} */
    private static function resumo(TrilhaOnda $onda): array
    {
        return ['id' => (int) $onda->id, 'nome' => (string) $onda->nome, 'dia' => $onda->dia()];
    }

    /**
     * O item existe agora, tem produto e ainda não andou — a oferta da etapa 3 (ou de uma onda que não começou).
     *
     * @param  array<string, array>  $itens
     */
    private static function itemPlanejavel(string $chave, array $itens, bool $tirando = false): array
    {
        if (TrilhaOndaItem::colunasDaChave($chave) === null) {
            throw ValidationException::withMessages(['itens' => 'Oferta inválida.']);
        }
        $item = $itens[$chave] ?? throw ValidationException::withMessages(['itens' => 'Essa oferta não está mais na trilha (foi apagada ou mudou). Recarregue a página.']);
        // Tirar vale também para a que ficou sem produto já dentro de uma onda (um kit cujo produto saiu da Lista):
        // sem isso ela ficaria presa na onda, com o status "Fora das ondas" (revisão de 02/11/2026).
        if ($item['sem_produto'] && ! $tirando) {
            throw ValidationException::withMessages(['itens' => "\"{$item['nome']}\" não tem produto na Lista: por enquanto fica fora das ondas."]);
        }
        if ($item['andou'] && ! $tirando) {
            throw ValidationException::withMessages(['itens' => "\"{$item['nome']}\" já começou (preparada, em revisão ou no ar): não entra em onda."]);
        }

        return $item;
    }

    /**
     * Cada Combo que o pedido TOCA (ele mesmo ou a Simples dele) fica com a Simples numa onda que começa no mesmo
     * dia ou antes — ou com a Simples já andando. Pares que o pedido não toca não são conferidos: um combo de outro
     * produto nunca trava este pedido.
     *
     * @param  array<string, ?array{id: int, nome: string, dia: string}>  $plano
     * @param  array<string, array>  $itens
     * @param  list<string>  $tocadas
     */
    private static function conferirCombos(array $plano, array $itens, array $tocadas): void
    {
        $tocadas = array_flip($tocadas);
        $hoje = TrilhaOnda::hoje();
        $problemas = [];
        foreach ($plano as $chave => $onda) {
            if ($onda === null || ! str_starts_with($chave, 'c-')) {
                continue;
            }
            $simples = 's-'.$itens[$chave]['produto_id'];
            if (! isset($tocadas[$chave]) && ! isset($tocadas[$simples])) {
                continue;
            }
            if (($itens[$simples]['andou'] ?? true)) {
                continue;
            }
            $daSimples = $plano[$simples] ?? null;
            // O combo numa onda que JÁ COMEÇOU não sai nem muda mais de onda: a saída é só pela simples.
            $comboPreso = $onda['dia'] <= $hoje;
            if ($daSimples === null) {
                $problemas[] = $comboPreso
                    ? "\"{$itens[$chave]['nome']}\" está na {$onda['nome']}, que já começou: a simples do produto não pode ficar sem onda. Ponha a simples na {$onda['nome']} (ou numa onda que começou até ".self::diaCurto($onda['dia']).').'
                    : "\"{$itens[$chave]['nome']}\" está na {$onda['nome']}, mas a simples do produto ficaria sem onda. Tire o combo antes ou ponha a simples numa onda.";
            } elseif ($daSimples['dia'] > $onda['dia']) {
                $problemas[] = "A simples de {$itens[$simples]['nome']} começaria em ".self::diaCurto($daSimples['dia'])
                    .", depois de \"{$itens[$chave]['nome']}\" ({$onda['nome']}, ".self::diaCurto($onda['dia']).'): o combo nasce da simples. '
                    .($comboPreso
                        ? "Ponha a simples na {$onda['nome']} (ou numa onda que começou até ".self::diaCurto($onda['dia']).').'
                        : 'Ponha o combo numa onda a partir dessa data ou adiante a simples.');
            }
        }
        if ($problemas !== []) {
            throw ValidationException::withMessages(['itens' => implode(' ', array_unique($problemas))]);
        }
    }

    /**
     * Duas ondas com o mesmo nome na loja confundem a tela, o Mapa e quem lê o aviso (sem caixa e sem acento). O
     * duplo clique em "Criar onda" também cai aqui em vez de nascer uma segunda onda igual.
     */
    private static function conferirNomeLivre(int $empresaId, string $nome, ?int $exceto = null): void
    {
        $chave = mb_strtolower(\Illuminate\Support\Str::ascii($nome));
        $repetida = TrilhaOnda::query()->where('company_id', $empresaId)
            ->when($exceto !== null, fn ($q) => $q->whereKeyNot($exceto))
            ->pluck('nome')
            ->contains(fn ($n) => mb_strtolower(\Illuminate\Support\Str::ascii((string) $n)) === $chave);
        if ($repetida) {
            throw ValidationException::withMessages(['nome' => 'Já existe uma onda com esse nome nesta loja.']);
        }
    }

    private static function nome(string $nome): string
    {
        $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? '');
        if ($nome === '' || mb_strlen($nome) > 80) {
            throw ValidationException::withMessages(['nome' => 'Dê um nome de 1 a 80 caracteres à onda.']);
        }

        return $nome;
    }

    /** A data `Y-m-d` entre hoje e hoje + 365 (São Paulo). */
    private static function data(string $data): string
    {
        $dia = Carbon::createFromFormat('!Y-m-d', $data, TrilhaOnda::FUSO);
        if ($dia === false || $dia->format('Y-m-d') !== $data) {
            throw ValidationException::withMessages(['data' => 'Data inválida.']);
        }
        $hoje = TrilhaOnda::hoje();
        if ($data < $hoje) {
            throw ValidationException::withMessages(['data' => 'Escolha hoje ou um dia depois: hoje faz o robô começar agora.']);
        }
        if ($data > Carbon::parse($hoje, TrilhaOnda::FUSO)->addDays(self::DIAS_A_FRENTE)->toDateString()) {
            throw ValidationException::withMessages(['data' => 'Escolha uma data até um ano para a frente.']);
        }

        return $data;
    }

    /** `2026-11-05` → `05/11`. */
    public static function diaCurto(string $data): string
    {
        return strlen($data) >= 10 ? substr($data, 8, 2).'/'.substr($data, 5, 2) : $data;
    }
}
