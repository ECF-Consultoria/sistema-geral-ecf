<?php

namespace App\Services\Publicador\Aprovacao\Fila;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaPublicacao;
use App\Models\PubAprovacaoOferta;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * "PRÓXIMO A REVISAR" — A ÚNICA PEÇA DA FILA QUE DECIDE POR QUEM ESTÁ OLHANDO.
 *
 * Fase 187, plano 05. A faixa amarelada acima da tabela escolhe qual oferta o operador abre
 * primeiro, e a escolha é a **regra literal de TRÊS DEGRAUS** do protótipo
 * (`design_handoff_publicador/Aprovacao.dc.html:1220`, medido com `grep -n` em 16/10/2026):
 *
 *     nextO = live.filter(status === 'awaiting' && !touched).sort(b.waitMin - a.waitMin)[0]
 *          || live.filter(!touched)[0]
 *          || live[0]
 *
 * ═══ ⚠️⚠️ A DIVERGÊNCIA QUE JUSTIFICA ESTE ARQUIVO EXISTIR ════════════════════════════════════
 *
 * O `design_handoff_publicador/README-v2.md:170` escreveu **só o primeiro degrau** — *"a oferta
 * `awaiting` não tocada há mais tempo na fila"*. Os **dois fallbacks** existem apenas na classe
 * `Component`, que é a **especificação de comportamento** (é o que o próprio README manda usar).
 *
 * ⚠️ **Sem eles a faixa DESAPARECERIA exatamente quando toda a fila já foi tocada** — que é o
 * estado NORMAL a partir do segundo dia de uso. A pessoa perderia a faixa justamente quando há
 * mais trabalho em aberto. ➡️ Os três degraus estão implementados, e há um teste por degrau com
 * fixture que **força** a queda para ele.
 *
 * ═══ ⚠️⚠️ OS TRÊS DEGRAUS DIFEREM SÓ NO FILTRO. A ORDEM É SEMPRE A DA FILA ════════════════════
 *
 * O protótipo reordena por `waitMin` no 1º degrau e usa a ordem de declaração do mock nos degraus
 * 2 e 3. ⚠️ **Aqui a ordem dos TRÊS é a de `AndamentoDaOfertaService::ordenarFila()`, delegada
 * por `FilaDeAprovacaoService::ordenar()`** — e isso é DECISÃO MEDIDA, não descuido:
 *
 *   · `ordenarFila()` já ordena **por degrau de `ORDEM` e, dentro do degrau, maior espera
 *     primeiro** (com `entrou_na_fila_em` nulo no FIM do degrau). Como os três estados de `LIVE`
 *     caem em `ORDEM` 1, 1 e 2, filtrar as `aguardando_aprovacao` não tocadas e tomar a
 *     **primeira** devolve, por construção, a de **maior espera** — que é o 1º degrau literal.
 *   · No protótipo `live` é `all.filter(o => o.inf.live)` sobre um ARRAY DE MOCK, então "a
 *     primeira" é a ordem de declaração do dado de exemplo. Aqui a consulta ao banco **não tem
 *     ordem definida**, então copiar "a ordem da fonte" seria copiar o indefinido. A única ordem
 *     NÃO-ARBITRÁRIA desta fila é a dela, e é a que a tabela mostra.
 *   · Reordenar aqui criaria **duas ordens da mesma fila**, livres para divergir na primeira
 *     correção feita de um lado só — e a faixa passaria a apontar para uma oferta que a tabela
 *     mostra noutro lugar, sem nenhum teste de render acusar.
 *
 * ⚠️ Há gate lendo este arquivo **sem comentário** e proibindo `->sort(`, `->sortBy(`, `orderBy(`
 * e o literal dos estados. Ler o código sem comentário não é zelo: palavra no docblock que
 * explica *"não uso isso"* casa com o gate que conta aquela palavra, e o executor seguinte
 * "conserta" o código para o gate passar.
 *
 * ═══ ⚠️ `LIVE` É LIDO DO MODELO, NUNCA ESCRITO À MÃO ═══════════════════════════════════════════
 *
 * `PubAprovacaoOferta::LIVE` são hoje três — e esta frase é a única razão pela qual os nomes
 * `aguardando_aprovacao`, `em_revisao_ecf` e `ajustes_solicitados` aparecem neste arquivo: ela
 * está num COMENTÁRIO, e o gate lê o código sem comentário. Escrever os três no CÓDIGO criaria
 * uma cópia da mesma verdade, livre para divergir no dia em que um **4º** estado entrar em `LIVE`.
 *
 * ⚠️ O **único** estado que o degrau 1 nomeia é `AGUARDANDO_APROVACAO`, pela **constante** — ele
 * é parte da regra (o `'awaiting'` do protótipo), não uma cópia da lista.
 *
 * ⚠️ **NUNCA um `||` aceitando as chaves do protótipo** (`awaiting`/`ecf_review`/`changes`): as
 * chaves de dado são pt-BR e aceitar as duas formas foi achado como **defeito grave** na
 * verificação da Fase 186.
 *
 * ═══ ⚠️⚠️ CUSTO: `BloqueiosDaOfertaService::para()` É CHAMADO **UMA** VEZ ══════════════════════
 *
 * `para()` lê a composição, que consulta `/sites/MLB/listing_prices` (cache de 1 h por
 * categoria+tipo+preço). Chamá-lo por linha da fila seria **uma requisição ao Mercado Livre por
 * linha** — a classe do incidente de 30/07/2026, quando requisições lentas ocuparam os workers do
 * php-fpm e até o login parou. ➡️ Aqui ele é chamado **só para a oferta escolhida**, e há teste
 * com espião contando as chamadas numa fila de 6 ofertas ao vivo (T-187-51).
 *
 * ═══ ⚠️ A ESPERA É RÓTULO PRONTO, MONTADO AQUI ════════════════════════════════════════════════
 *
 * `rotuloDaEspera()` é a fórmula literal de `Aprovacao.dc.html:1054`. Ela existe no SERVIDOR de
 * propósito: `new Date()` no JSX já mordeu este projeto duas vezes (`Invalid Date` e o
 * deslocamento de fuso de data-only em BRT), e o componente de cabeçalho da Fase 180 **recusa**
 * rótulo com cara de data crua.
 *
 * ⚠️⚠️ **DIVERGÊNCIA DO PLANO, REGISTRADA:** o `<action>` do 187-05 escreveu *"3 dias"*; o
 * protótipo escreve **`' d'`** (`:1054`). **O protótipo ganha nos RÓTULOS** — e a razão prática é
 * mais forte que a regra: o MESMO `wait()` é consumido pela coluna de espera da linha da tabela
 * (`:1247`), que é do plano **187-08**. Dois rótulos para a mesma medida na mesma tela seriam
 * duas verdades, e é por isso que a fórmula mora aqui, pública, para o 187-08 CHAMAR.
 *
 * ⚠️ `espera_minutos` viaja junto (int), porque o 187-08 usa o NÚMERO para a regra do âmbar acima
 * de 24 h. O rótulo não serve para isso: `'25 h'` e `'1 d'` são o mesmo lado da régua, e comparar
 * texto para decidir cor é como se inventa um bug de cor.
 *
 * ═══ ⚠️ O QUE ESTE SERVIÇO NÃO FAZ ═══════════════════════════════════════════════════════════
 *
 * ⚠️ Ele **não aprova, não move andamento e não publica**. A escrita de `andamento` tem UMA porta
 * (`AndamentoDaOfertaService`), a aprovação é do 187-09 e a escrita no Mercado Livre é da
 * **Fase 193**. Aqui só se LÊ.
 *
 * ⚠️ Ele **não manda miniatura**. A imagem de capa vem do acervo, e `ImagemAssetService`/
 * `Acervo*` são território PROIBIDO nesta fase (a v28 acabou de virar o acervo para o R2 em
 * produção). O componente desenha o **lugar vazio que o próprio protótipo desenha** (`:55`:
 * `background:#1A1A1A;color:#52525B`), e a wave que montar as miniaturas da tabela preenche a
 * prop. ➡️ **Nada de URL inventada e nada de `<img src="undefined">`.**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class ProximoARevisarService
{
    /** `:1222` — o estado quando não há pendência nenhuma. */
    public const ESTADO_PRONTO = 'Pronto para aprovar';

    /** `:1222` — o SINGULAR, que o critério 6 da fase não escreveu. */
    public const SUFIXO_PENDENCIA = ' pendência';

    /** `:1222` — o plural. */
    public const SUFIXO_PENDENCIAS = ' pendências';

    /** `:1221` — o separador da frase: U+00B7, **com** espaço nos dois lados. */
    public const SEPARADOR = ' · ';

    /** `:1222` — o travessão do nome: U+2014, **nunca** hífen. */
    public const TRAVESSAO = ' — ';

    /** `:1221` — a oferta de uma unidade. */
    public const UNIDADE = 'Unidade';

    /** `:1221` — o prefixo do combo. O `×` é U+00D7, **nunca** a letra `x`. */
    public const PREFIXO_COMBO = 'Combo ×';

    /** `:1221` — o prefixo da espera. */
    public const PREFIXO_ESPERA = 'aguardando há ';

    /** `:1221` — o prefixo do responsável. */
    public const PREFIXO_RESPONSAVEL = 'responsável ';

    public function __construct(
        private FilaDeAprovacaoService $fila,
        private BloqueiosDaOfertaService $bloqueios,
    ) {}

    /**
     * As ofertas **ao vivo** da conta, na ordem da fila.
     *
     * ⚠️ O recorte de conta vem do `Builder` que já chega recortado por
     * `FilaDeAprovacaoService::paraAConta()` — que PROPAGA a `RegraViolada` das duas âncoras
     * nulas (T-186-14). Quem não sabe de que conta fala não recebe fila nenhuma, e por isso este
     * método não aceita id de empresa: ele não tem como recortar sozinho.
     *
     * ⚠️ `with()` nas três relações que a frase usa: sem o eager loading, uma fila de 30 ofertas
     * faria 90 consultas para montar UMA frase.
     *
     * @param  Builder  $base  já recortada por conta, e com todos os filtros MENOS a etapa
     * @return Collection<int, PubAprovacaoOferta>
     */
    public function aoVivo(Builder $base): Collection
    {
        return $this->fila->ordenar(
            (clone $base)
                ->whereIn('andamento', PubAprovacaoOferta::LIVE)
                ->with(['alvo', 'produto', 'responsavel'])
                ->get()
        );
    }

    /**
     * A ESCOLHA LITERAL DE TRÊS DEGRAUS (`:1220`), ou `null` quando não há oferta ao vivo.
     *
     * ⚠️ Os três degraus diferem **só no filtro**; a ordem é a que `$aoVivo` já traz. Ver o
     * docblock da classe.
     *
     * @param  Collection<int, PubAprovacaoOferta>  $aoVivo  o resultado de `aoVivo()`
     */
    public function escolher(Collection $aoVivo): ?PubAprovacaoOferta
    {
        // ⚠️ `ehLive()` lê `LIVE` do modelo: mesmo recebendo uma coleção montada à mão, nada que
        // não esteja ao vivo é candidato. `falha` é o degrau **0** de `ORDEM` — o mais urgente da
        // fila — e seria a PRIMEIRA escolhida por quem lesse a ordem sem este recorte.
        $live = $aoVivo->filter(fn (PubAprovacaoOferta $oferta) => $oferta->ehLive())->values();

        // ─── Degrau 1 — a `aguardando_aprovacao` NÃO TOCADA de maior espera ──────────────────
        $degrau1 = $live->first(fn (PubAprovacaoOferta $oferta) => $oferta->andamento === PubAprovacaoOferta::AGUARDANDO_APROVACAO
            && ! $oferta->tocado);

        if ($degrau1 !== null) {
            return $degrau1;
        }

        // ─── Degrau 2 — a PRIMEIRA ao vivo NÃO TOCADA ────────────────────────────────────────
        // ⚠️ Sem este degrau, uma fila sem `aguardando_aprovacao` nova (mas com revisão ou
        // ajustes novos) não teria próxima NENHUMA.
        $degrau2 = $live->first(fn (PubAprovacaoOferta $oferta) => ! $oferta->tocado);

        if ($degrau2 !== null) {
            return $degrau2;
        }

        // ─── Degrau 3 — a PRIMEIRA ao vivo ───────────────────────────────────────────────────
        // ⚠️ É o estado NORMAL a partir do segundo dia de uso: toda a fila já foi tocada.
        return $live->first();
    }

    /**
     * O payload da faixa, ou `null` quando não há oferta ao vivo.
     *
     * ⚠️ **`null` significa "a faixa INTEIRA não aparece"** — nunca uma faixa vazia, nunca uma
     * casca com borda amarela e nada dentro. Nada nasce decorativo (D4).
     *
     * ⚠️ `BloqueiosDaOfertaService::para()` é chamado **UMA** vez aqui, para a oferta escolhida.
     * Ver o docblock da classe (T-187-51).
     *
     * @param  Builder  $base  já recortada por conta, e com todos os filtros MENOS a etapa
     * @return array{id: int, nome: ?string, sub: string, estado: string, aprovavel: bool, pendencias: int, espera_minutos: ?int}|null
     */
    public function para(Builder $base): ?array
    {
        $escolhida = $this->escolher($this->aoVivo($base));

        if ($escolhida === null) {
            return null;
        }

        $leitura = $this->bloqueios->para($escolhida);

        $aprovavel = ($leitura['aprovavel'] ?? false) === true;
        $pendencias = is_array($leitura['bloqueios'] ?? null) ? count($leitura['bloqueios']) : 0;
        $minutos = $this->esperaEmMinutos($escolhida);

        return [
            'id' => (int) $escolhida->id,
            'nome' => $this->nomeDe($escolhida),
            'sub' => $this->frase($escolhida, $minutos),
            'estado' => $this->estado($aprovavel, $pendencias),
            'aprovavel' => $aprovavel,
            'pendencias' => $pendencias,
            'espera_minutos' => $minutos,
        ];
    }

    /**
     * O rótulo humano da espera — a fórmula literal de `:1054`.
     *
     * ⚠️ Ela é **pública** porque o plano 187-08 (a coluna de espera da linha) lê a MESMA
     * medida. Copiar a fórmula lá criaria duas verdades para o mesmo número na mesma tela.
     *
     * ⚠️ `' d'` e não `' dias'`: ver a divergência registrada no docblock da classe.
     */
    public function rotuloDaEspera(int $minutos): string
    {
        if ($minutos < 60) {
            return $minutos.' min';
        }

        if ($minutos < 1440) {
            return ((int) round($minutos / 60)).' h';
        }

        return ((int) round($minutos / 1440)).' d';
    }

    /**
     * O estado (`:1222`): "Pronto para aprovar", ou a contagem com **singular e plural**.
     *
     * ⚠️ O protótipo tem as DUAS formas (`B.length > 1 ? ' pendências' : ' pendência'`) e o
     * critério 6 da fase escreveu só "N pendências". O protótipo ganha.
     *
     * ⚠️ **E o ZERO nunca vira "0 pendência".** Para oferta ao vivo,
     * `aprovavel = ao_vivo && bloqueios === []`, então zero pendências ⟺ aprovável; a trava
     * explícita de `$pendencias === 0` está aqui para que uma divergência futura naquele
     * contrato apareça como "Pronto para aprovar", nunca como uma contagem que mente.
     */
    private function estado(bool $aprovavel, int $pendencias): string
    {
        if ($aprovavel || $pendencias === 0) {
            return self::ESTADO_PRONTO;
        }

        return $pendencias.($pendencias > 1 ? self::SUFIXO_PENDENCIAS : self::SUFIXO_PENDENCIA);
    }

    /**
     * O nome (`:1222`): `produto.nome` + ` — ` + `alvo.titulo`.
     *
     * ⚠️ Faltando um dos dois, sai só o que existe; faltando os dois, devolve `null` e o
     * componente **não desenha a linha do nome**. Nada é inventado — e a faixa continua
     * aparecendo, porque a oferta EXISTE e precisa ser revisada: esconder a faixa esconderia
     * trabalho.
     */
    private function nomeDe(PubAprovacaoOferta $oferta): ?string
    {
        $partes = array_values(array_filter([
            $this->texto($oferta->produto?->nome),
            $this->texto($oferta->alvo?->titulo),
        ], fn (?string $parte) => $parte !== null));

        return $partes === [] ? null : implode(self::TRAVESSAO, $partes);
    }

    /**
     * A frase descritiva (`:1221`), na ordem literal e com os separadores ` · `:
     *
     *     <Modalidade> · <Unidade|Combo ×N> · aguardando há <espera> · responsável <nome>
     *
     * ⚠️ Cada trecho que não tem dado **sai fora** — a frase nunca preenche buraco com rótulo
     * genérico, e nunca inventa responsável.
     */
    private function frase(PubAprovacaoOferta $oferta, ?int $minutos): string
    {
        $partes = [];

        $modalidade = $this->modalidade($oferta->alvo?->listing_type_id);
        if ($modalidade !== null) {
            $partes[] = $modalidade;
        }

        $formato = $this->formato($oferta->produto?->quantidade_kit);
        if ($formato !== null) {
            $partes[] = $formato;
        }

        if ($minutos !== null) {
            $partes[] = self::PREFIXO_ESPERA.$this->rotuloDaEspera($minutos);
        }

        $responsavel = $this->texto($oferta->responsavel?->name);
        if ($responsavel !== null) {
            $partes[] = self::PREFIXO_RESPONSAVEL.$responsavel;
        }

        return implode(self::SEPARADOR, $partes);
    }

    /**
     * "Clássico" ou "Premium", ou `null` para `listing_type_id` que não é nenhum dos dois.
     *
     * ⚠️ O mapa é **LIDO**: `EstruturaPublicacao::LISTING_TYPES` invertido +
     * `EstruturaAnuncio::TIPOS`, que é o par que `FamiliaDeFasesService` já usa. Cunhar um mapa
     * novo criaria uma terceira cópia da mesma verdade.
     *
     * ⚠️ `free`/`silver`/`bronze` devolvem `null` e o trecho sai da frase — **nunca** viram
     * "Clássico" porque é o mais comum. É o precedente medido do módulo
     * (`CockpitDaContaService`: *"tipo nulo é listing desconhecido: não vira 'Clássico' por…"*).
     */
    private function modalidade(mixed $listingTypeId): ?string
    {
        if (! is_string($listingTypeId) || $listingTypeId === '') {
            return null;
        }

        $tipo = array_search($listingTypeId, EstruturaPublicacao::LISTING_TYPES, true);

        if ($tipo === false) {
            return null;
        }

        return EstruturaAnuncio::TIPOS[$tipo] ?? null;
    }

    /**
     * "Unidade" ou "Combo ×N", de `produto.quantidade_kit`.
     *
     * ⚠️⚠️ **DIVERGÊNCIA DECLARADA:** `PubProduto::ehKit()` exige **também** `produto_base_id`
     * não-nulo, e aqui ele **não** é consultado — de propósito. O que esta frase descreve é
     * *"quantas unidades vão nesta oferta"*, que é `quantidade_kit` e só. Um kit cadastrado sem
     * base ainda vende 2 unidades por venda, e dizer "Unidade" ali seria uma afirmação FALSA
     * sobre o que o comprador recebe. É também o que o protótipo mede (`set.units > 1`, `:1221`).
     *
     * ⚠️ Valor que não é inteiro positivo devolve `null` e o trecho sai: `quantidade_kit` zero ou
     * negativo é dado que não deveria existir, e "Combo ×0" seria pior que o silêncio.
     */
    private function formato(mixed $quantidade): ?string
    {
        if (! is_int($quantidade) || $quantidade < 1) {
            return null;
        }

        return $quantidade >= 2 ? self::PREFIXO_COMBO.$quantidade : self::UNIDADE;
    }

    /**
     * Os minutos de espera desde `entrou_na_fila_em`, ou `null` quando ela não existe.
     *
     * ⚠️ **Espera desconhecida não é espera zero nem espera infinita** — é a mesma decisão que
     * `AndamentoDaOfertaService::ordenarFila()` já tomou ao mandar o nulo para o fim do degrau.
     *
     * ⚠️ Carimbo no FUTURO é clampado em 0: espera negativa não existe, e um `-180 min` na frase
     * seria defeito do dado aparecendo como número inventado.
     *
     * ⚠️ A conta é por TIMESTAMP, não por `diffInMinutes()`: entre o Carbon 2 e o 3 aquele método
     * mudou de inteiro para float e o sinal passou a depender do 2º argumento. Timestamp é a
     * mesma medida que `AndamentoDaOfertaService::ordenarFila()` usa para desempatar a espera —
     * uma só aritmética para a mesma grandeza.
     */
    private function esperaEmMinutos(PubAprovacaoOferta $oferta): ?int
    {
        $entrou = $oferta->entrou_na_fila_em;

        if ($entrou === null) {
            return null;
        }

        return intdiv(max(0, now()->getTimestamp() - $entrou->getTimestamp()), 60);
    }

    /** Texto não-vazio, ou `null`. Nunca `(string) $valor`, que lança com array. */
    private function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpo = trim($valor);

        return $limpo === '' ? null : $limpo;
    }
}
