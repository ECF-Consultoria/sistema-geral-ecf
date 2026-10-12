import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import * as Popover from '@radix-ui/react-popover';
import axios from 'axios';
import { Check, ChevronsUpDown, Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import { textoSeguro, textoUtil } from '../BarraDaConta';
import { destinoParaItem } from '../SeletorEmpresaBusca';
import { iniciais, normalizar, tomDoProduto } from '../identidade/identidade';
import { lerRecentes } from '../recentesDoPublicador';
import { estadoDaConexao } from './ConexaoDoHeader';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 06 — o SELETOR DE EMPRESA da linha 1: o botão de identidade
// da conta (38px) e o dropdown de 360px (HEAD-04/HEAD-05).
//
// Protótipo: `new_layout_publicador/PublicadorHeader.dc.html`
//   · botão       → :34-41 (38px, raio 10px, tile 28px com bolinha de 10px)
//   · dropdown    → :43-69 (360px, raio 14px, busca, grupos, rodapé)
//   · agrupamento → :209-211 (`mk`, `companyGroups`), busca → :193 (`norm`)
//
// ─── O QUE VEIO DE `BarraDaConta.jsx` (REG-01: nada pode se perder) ───
//   · o nome e a chave da conta (nome por `textoSeguro`, chave por
//     `textoUtil` — chave vazia não pode virar parâmetro de URL);
//   · o estado da conta ML, que era o `SeloConta` — agora é a bolinha de 10px
//     no tile e o dot de cada item da lista;
//   · "Trocar empresa", que era um link com popover de busca — agora é o
//     próprio nome da empresa que abre o dropdown;
//   · a navegação que PRESERVA A ABA ATUAL, por `destinoParaItem` importado de
//     `SeletorEmpresaBusca.jsx`. ⚠️ NÃO reimplementar: aquela função lê
//     `route().current()` e já é testada desde a Fase 173.
//
// ⚠️ DIVERGÊNCIA DECLARADA (ERP → Portal): no protótipo a bolinha âmbar é
// "ERP desconectado". Aqui o ERP é **só declarado** (não existe integração para
// medir), então o âmbar é o **Portal do Cliente com pendência** — estado REAL,
// que o `SeloPortal` já mostra hoje e que HEAD-11 manda preservar. A decisão
// mora em `estadoDaConexao()`, em `ConexaoDoHeader.jsx`: UMA casa para a
// pergunta "esta conta está conectada?", lida pela pill e por esta bolinha.
//
// ⚠️ DIVERGÊNCIA DECLARADA (implementação do dropdown): o protótipo usa
// `position:absolute` + backdrop transparente; aqui é `@radix-ui/react-popover`
// com `Portal`. O motivo concreto está escrito no topo de `ConexaoDoHeader.jsx`
// (o header vive dentro do `<main>` com `overflow-y-auto` e D2 proíbe tocar no
// `AppLayout`). Esc e clique fora continuam funcionando — são do Radix, e por
// isso `onEscapeKeyDown`/`onPointerDownOutside` não são desabilitados.
//
// ⚠️ O ENDPOINT DO DROPDOWN NÃO RECEBE PARÂMETRO (T-177-08): o escopo é o do
// usuário autenticado, decidido no servidor. Não acrescentar `?programa=`,
// `?user=` nem nada — a ausência de entrada é a mitigação estrutural de IDOR.
//
// ⚠️ `agrupar()` e `PainelDeEmpresas` são EXPORTS NOMEADOS pelo mesmo motivo do
// `PainelDeConexoes`: o renderizador de servidor do React não suporta portal,
// então o corpo do dropdown só pode ser conferido por render REAL se existir
// fora do `Popover.Portal`. Gate por leitura de fonte não vale — foi o que o
// plano 177-04 teve de reescrever depois de 4 mutações passarem.
// ═══════════════════════════════════════════════════════════════════════════

/** Mesmo debounce de `SeletorEmpresaBusca.jsx` — a busca no servidor é a MESMA rota. */
const ESPERA_MS = 300;

/**
 * Sem chave de conta usável não há identidade para mostrar nem destino para
 * montar. Em vez de um gatilho que abre um dropdown sobre o nada, fica um
 * `<span>` dizendo a verdade (`cabecalho` chega nulo em 3 páginas).
 */
export const TITULO_SEM_CONTA = 'Abra uma empresa do Publicador para trocar de conta — esta tela está fora do fluxo por empresa.';

/** Rótulos de grupo. São LITERAIS em caixa alta, como no protótipo (:51/:211). */
export const GRUPO_RECENTES = 'RECENTES';
export const GRUPO_TODAS = 'TODAS';
export const GRUPO_RESULTADOS = 'RESULTADOS';

/** O payload de `GET mlb.anuncios.publicador.empresas-header`, normalizado antes de qualquer leitura. */
export function normalizarPayload(dados) {
    return {
        total: Number.isFinite(dados?.total) ? dados.total : null,
        limite: Number.isFinite(dados?.limite) ? dados.limite : null,
        empresas: Array.isArray(dados?.empresas) ? dados.empresas : [],
    };
}

/** Filtro local: a `norm()` do protótipo (:193) — ignora acento e caixa, sobre nome + código. */
function casa(item, consulta) {
    if (consulta === '') return true;
    return normalizar(`${textoSeguro(item?.nome, '')} ${textoSeguro(item?.chave, '')}`).includes(consulta);
}

/**
 * Os grupos da lista, como DADO — a `companyGroups` do protótipo (:211).
 *
 * Com busca: um grupo só, RESULTADOS (o que o filtro local achou; se não achou
 * nada e o servidor respondeu, o que veio do servidor). Sem busca: RECENTES (na
 * ordem do `localStorage`, só as contas que existem na lista carregada) e TODAS
 * (as demais). Grupo vazio é devolvido vazio — quem renderiza não desenha
 * título sozinho.
 *
 * Função PURA: o gate exercita a regra de agrupamento sem renderizar nada.
 */
export function agrupar({ lista = [], recentes = [], consulta = '', doServidor = null } = {}) {
    const segura = Array.isArray(lista) ? lista : [];
    const q = normalizar(String(consulta ?? '').trim());

    if (q !== '') {
        const locais = segura.filter((item) => casa(item, q));
        const itens = locais.length > 0 ? locais : (Array.isArray(doServidor) ? doServidor : []);
        return [{ rotulo: GRUPO_RESULTADOS, itens }];
    }

    const chaves = (Array.isArray(recentes) ? recentes : [])
        .map((r) => textoUtil(r?.chave))
        .filter((c) => c !== null);
    const porChave = new Map(segura.map((item) => [textoUtil(item?.chave), item]));

    return [
        { rotulo: GRUPO_RECENTES, itens: chaves.map((c) => porChave.get(c)).filter((item) => item !== undefined) },
        { rotulo: GRUPO_TODAS, itens: segura.filter((item) => !chaves.includes(textoUtil(item?.chave))) },
    ];
}

/**
 * O corpo do dropdown de 360px: busca, grupos e rodapé.
 *
 * Componente puro (nenhum estado, nenhum efeito) para o gate poder renderizar
 * de verdade — ver o aviso sobre portais no topo do arquivo.
 *
 * @param {object} props
 * @param {string} [props.consulta]
 * @param {Function} [props.aoConsultar]
 * @param {boolean} [props.carregando]
 * @param {boolean} [props.erro]
 * @param {Array<{rotulo: string, itens: Array}>} [props.grupos]
 * @param {string|null} [props.chaveAtual] a conta aberta — ganha fundo, tile
 *   amarelo e o check
 * @param {number|null} [props.total] o total REAL do servidor; `null` não
 *   desenha contagem nenhuma (HEAD-10: nunca o 48 do protótipo)
 * @param {Function} [props.aoEscolher]
 */
export function PainelDeEmpresas({
    consulta = '',
    aoConsultar = () => {},
    carregando = false,
    erro = false,
    grupos = [],
    chaveAtual = null,
    total = null,
    aoEscolher = () => {},
}) {
    const buscando = String(consulta ?? '').trim() !== '';
    const cheios = (Array.isArray(grupos) ? grupos : []).filter((g) => Array.isArray(g?.itens) && g.itens.length > 0);

    /**
     * Uma linha da lista (46px). ⚠️ REND-04: TODA flag nasce aqui dentro, e
     * esta função é chamada dentro do `.map()` — variável de escopo do
     * componente lida só dentro de um `.map()` já foi eliminada pelo Rollup no
     * bundle de produção deste projeto (mesma forma de `AbasDoHeader.pilula`).
     */
    function linha(item) {
        const itemChave = textoUtil(item?.chave);
        const itemNome = textoSeguro(item?.nome);
        const atual = itemChave !== null && itemChave === chaveAtual;
        const conectada = textoSeguro(item?.token, null) === 'ativo';
        const tom = tomDoProduto(itemChave ?? itemNome);

        return (
            <button
                key={itemChave ?? itemNome}
                type="button"
                onClick={() => aoEscolher(item)}
                aria-current={atual ? 'true' : undefined}
                className={cn(
                    'flex h-[46px] w-full items-center gap-[10px] whitespace-nowrap rounded-pub-control px-[10px] text-left hover:bg-pub-hover-3',
                    atual && 'bg-pub-hover-3',
                )}
            >
                <span
                    className={cn(
                        'grid h-[30px] w-[30px] flex-none place-items-center rounded-[8px] text-[11px] font-semibold',
                        atual && 'bg-pub-accent text-pub-page',
                    )}
                    style={atual ? undefined : { background: tom.fundo, color: tom.texto }}
                >
                    {iniciais(itemNome)}
                </span>
                <span className="flex min-w-0 flex-1 flex-col gap-[2px]">
                    <span className="truncate text-[13.5px] font-medium">{itemNome}</span>
                    <span className="flex items-center gap-[6px] text-[11.5px] text-pub-txt-3">
                        <span aria-hidden="true" className={cn('h-[6px] w-[6px] flex-none rounded-full', conectada ? 'bg-pub-ok' : 'bg-pub-danger')} />
                        {conectada ? 'Conectada' : 'Desconectada'}
                        <span aria-hidden="true" className="text-pub-line-off">·</span>
                        <span className="font-pub-mono text-[11px]">{textoSeguro(item?.chave)}</span>
                    </span>
                </span>
                {atual && <Check size={14} strokeWidth={3} aria-hidden="true" className="flex-none text-pub-accent" />}
            </button>
        );
    }

    return (
        <>
            {/* ─── Busca (:44-47) ─── */}
            <div className="relative border-b border-pub-line-subtle p-[10px]">
                <Search
                    size={15}
                    strokeWidth={2.4}
                    aria-hidden="true"
                    className="pointer-events-none absolute left-[22px] top-[21px] text-pub-txt-3"
                />
                <input
                    type="search"
                    autoFocus
                    value={consulta}
                    onChange={aoConsultar}
                    placeholder="Buscar empresa ou código…"
                    aria-label="Buscar empresa ou código"
                    className="h-[38px] w-full rounded-pub-control border border-pub-line-strong bg-pub-sunken pl-[36px] pr-[12px] text-[13.5px] text-pub-txt outline-none placeholder:text-pub-txt-4 focus:border-pub-accent"
                />
            </div>

            {/* ─── Grupos (:48-64) ─── */}
            <div className="max-h-[340px] overflow-y-auto p-[6px]">
                {/* ⚠️ Nunca uma lista vazia em silêncio: cada estado tem a sua frase. */}
                {carregando && <p className="px-[10px] py-[12px] text-[13px] text-pub-txt-3">Carregando…</p>}
                {!carregando && erro && (
                    <p className="px-[10px] py-[12px] text-[13px] text-pub-txt-3">
                        Não foi possível carregar as empresas agora. Tente de novo em instantes.
                    </p>
                )}
                {!carregando && !erro && cheios.length === 0 && (
                    <p className="px-[10px] py-[12px] text-[13px] text-pub-txt-3">
                        {buscando ? 'Nenhuma empresa encontrada.' : 'Nenhuma empresa disponível.'}
                    </p>
                )}
                {!carregando && !erro && cheios.map((g) => (
                    <div key={g.rotulo} className="flex flex-col">
                        <span className="px-[10px] pb-[6px] pt-[10px] text-[11px] font-semibold tracking-[0.08em] text-pub-txt-4">{g.rotulo}</span>
                        {g.itens.map((item) => linha(item))}
                    </div>
                ))}
            </div>

            {/* ─── Rodapé (:65-68) ─── */}
            {/* ⚠️ HEAD-10: o total é o REAL do servidor. O "48 empresas sob
                gestão" do protótipo é número de mock; sem total carregado, a
                contagem simplesmente não aparece. */}
            <div className="flex items-center justify-between border-t border-pub-line-subtle bg-pub-sunken px-[16px] py-[10px] text-[12px] text-pub-txt-3">
                <span className="tabular-nums">
                    {Number.isFinite(total)
                        ? `${total} ${total === 1 ? 'empresa sob gestão' : 'empresas sob gestão'}`
                        : ''}
                </span>
                <Link href={route('mlb.anuncios.index')} className="whitespace-nowrap font-medium text-pub-txt">
                    Ver todas →
                </Link>
            </div>
        </>
    );
}

/**
 * @param {object} props
 * @param {object|null} [props.empresa] `cabecalho.empresa` do servidor. Pode
 *   ser nula, pode vir com campo objeto — tudo passa pelo normalizador
 *   de `BarraDaConta` antes do JSX e antes do `route()`.
 * @param {object|null} [props.integracoes] `cabecalho.integracoes` — só para a
 *   cor da bolinha de status do tile.
 * @param {number|string|null} [props.userId] o usuário logado, para ler as
 *   contas recentes do `localStorage` (a chave é por usuário).
 */
export default function SeletorDeEmpresaDoHeader({ empresa = null, integracoes = null, userId = null }) {
    const [aberto, setAberto] = useState(false);
    const [consulta, setConsulta] = useState('');
    const [dados, setDados] = useState(null);
    const [carregando, setCarregando] = useState(false);
    const [erro, setErro] = useState(false);
    const [recentes, setRecentes] = useState([]);
    const [doServidor, setDoServidor] = useState(null);
    const jaPediu = useRef(false);
    const espera = useRef(null);

    const nome = textoSeguro(empresa?.nome);
    const chaveAtual = textoUtil(empresa?.chave);
    const estado = estadoDaConexao(integracoes);

    const lista = dados?.empresas ?? [];
    const q = normalizar(consulta.trim());
    const achouLocal = q === '' || lista.some((item) => casa(item, q));

    // O servidor só entra quando a lista do cliente está INCOMPLETA (`total >
    // limite`) e o filtro local não achou nada. Com a lista inteira em mãos a
    // busca é instantânea, como no protótipo — nenhuma ida à rede por tecla.
    const listaIncompleta = dados !== null
        && dados.total !== null
        && dados.limite !== null
        && dados.total > dados.limite;
    const precisaDoServidor = q !== '' && listaIncompleta && !achouLocal;

    useEffect(() => {
        clearTimeout(espera.current);
        if (!precisaDoServidor) {
            setDoServidor(null);
            return undefined;
        }
        const termo = consulta.trim();
        espera.current = setTimeout(() => {
            axios
                .get(route('mlb.anuncios.publicador.empresas-busca'), { params: { q: termo } })
                .then((resposta) => setDoServidor(Array.isArray(resposta.data) ? resposta.data : []))
                .catch(() => setErro(true));
        }, ESPERA_MS);
        return () => clearTimeout(espera.current);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [precisaDoServidor, consulta]);

    function aoAbrir(valor) {
        setAberto(valor);
        if (!valor) {
            setConsulta('');
            setDoServidor(null);
            return;
        }
        // Lido a cada abertura: o usuário pode ter aberto outra conta em outra
        // aba do navegador. `lerRecentes` já é blindado por `try/catch` —
        // `localStorage` pode LANÇAR em janela privada, e aqui isso não pode
        // impedir o dropdown de abrir.
        setRecentes(lerRecentes(userId));
        if (jaPediu.current) return;
        // UMA chamada por montagem (T-177-25): o resultado fica em estado e as
        // aberturas seguintes não vão à rede.
        jaPediu.current = true;
        setCarregando(true);
        setErro(false);
        axios
            .get(route('mlb.anuncios.publicador.empresas-header'))
            .then((resposta) => setDados(normalizarPayload(resposta.data)))
            .catch(() => setErro(true))
            .finally(() => setCarregando(false));
    }

    function escolher(item) {
        // `destinoParaItem` é o "trocar empresa mantém a aba atual" da Fase 173
        // — importado, nunca reimplementado.
        const { rota, parametros } = destinoParaItem(item);
        setAberto(false);
        router.get(route(rota, parametros));
    }

    // ─── Sem conta usável: não há identidade nem destino (EXMP-03) ───
    if (chaveAtual === null) {
        return (
            <span
                aria-disabled="true"
                title={TITULO_SEM_CONTA}
                aria-label={TITULO_SEM_CONTA}
                className="flex h-[38px] flex-none items-center whitespace-nowrap pl-[5px] pr-[10px] text-[14.5px] font-semibold tracking-[-0.01em] text-pub-txt-4"
            >
                Nenhuma empresa aberta
            </span>
        );
    }

    return (
        <Popover.Root open={aberto} onOpenChange={aoAbrir}>
            <Popover.Trigger asChild>
                <button
                    type="button"
                    title={`${nome} — trocar de empresa`}
                    aria-label={`${nome} — trocar de empresa`}
                    className={cn(
                        'flex h-[38px] flex-none items-center gap-[10px] whitespace-nowrap rounded-pub-sub border pl-[5px] pr-[10px] text-left',
                        aberto
                            ? 'border-pub-line-strong bg-pub-hover'
                            : 'border-transparent bg-transparent hover:border-pub-line hover:bg-pub-hover',
                    )}
                >
                    <span className="relative grid h-[28px] w-[28px] flex-none place-items-center rounded-[8px] border border-pub-line-strong bg-pub-line-subtle text-[10.5px] font-bold text-pub-accent">
                        {iniciais(nome)}
                        {/* ⚠️ Estado não medido (`integracoes` ausente) NÃO desenha
                            bolinha nenhuma: um ponto colorido é afirmação sobre a
                            conexão, e "não sabemos" não é "está tudo bem". */}
                        {estado !== null && (
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'absolute bottom-[-3px] right-[-3px] h-[10px] w-[10px] rounded-full border-2 border-pub-page',
                                    estado === 'desconectado' && 'bg-pub-danger',
                                    estado === 'atencao' && 'bg-pub-warn',
                                    estado === 'ok' && 'bg-pub-ok',
                                )}
                            />
                        )}
                    </span>
                    <span className="text-[14.5px] font-semibold tracking-[-0.01em]">{nome}</span>
                    <span className="font-pub-mono text-[11px] text-pub-txt-4">{textoSeguro(empresa?.chave)}</span>
                    <ChevronsUpDown size={13} strokeWidth={2.55} aria-hidden="true" className="flex-none text-pub-txt-3" />
                </button>
            </Popover.Trigger>

            <Popover.Portal>
                <Popover.Content
                    align="start"
                    sideOffset={8}
                    aria-label="Trocar de empresa"
                    className="z-[61] w-[360px] overflow-hidden rounded-[14px] border border-pub-line-strong bg-pub-surface font-pub text-pub-txt shadow-pub-popover"
                >
                    <PainelDeEmpresas
                        consulta={consulta}
                        aoConsultar={(ev) => setConsulta(ev.target.value)}
                        carregando={carregando}
                        erro={erro}
                        grupos={agrupar({ lista, recentes, consulta, doServidor })}
                        chaveAtual={chaveAtual}
                        total={dados?.total ?? null}
                        aoEscolher={escolher}
                    />
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}
