import { useEffect, useId, useRef, useState } from 'react';
import { nomeComparavel, nomeNovo, opcoesQueCasam } from '@/lib/trilhaCadastro';
import { cn } from '@/lib/utils';
import { Botao, CAMPO, Etiqueta } from '@/Components/Trilha/Pecas';

// ─── Trilha de produtos — as peças do cadastro (3ª entrega) ─────────────────
//
// O que a gaveta de cadastro repete: o campo com rótulo, dica, erro e contador;
// a lista com busca (família) e os chips com busca (ambientes); a confirmação
// que aparece no rodapé da gaveta. Tudo nos tokens `trilha-*` (claro e escuro),
// sem nenhum componente do Portal.
//
// As listas abrem DENTRO da gaveta, no fluxo da página (sem portal do Radix e
// sem posição absoluta, que a rolagem do corpo cortaria). O Esc com uma lista
// aberta fecha só a lista: o `preventDefault` avisa a gaveta, que respeita
// (`deveFecharNoEsc`).
//
// Nada aqui usa `required`: o cadastro é feito aos poucos e a validação do
// navegador barraria o "Salvar" de um produto ainda incompleto. O "*" é visual
// e o `aria-required` diz o mesmo ao leitor de tela.

const CAMPO_COM_ERRO = 'border-trilha-bad focus:border-trilha-bad focus:ring-trilha-bad/30';

/** O "N de 5.000" ao lado do rótulo (âmbar quando passa do limite). */
export function Contador({ atual, limite }) {
    const passou = limite > 0 && atual > limite;

    return (
        <span className={cn('font-pub-mono text-[11.5px] tabular-nums', passou ? 'font-semibold text-trilha-warn' : 'text-trilha-muted')} data-contador="">
            {atual.toLocaleString('pt-BR')} de {limite.toLocaleString('pt-BR')}
        </span>
    );
}

/**
 * Um campo do cadastro: rótulo (com "*" no obrigatório), o controle, a dica e o erro do servidor. O controle
 * vem de uma função que recebe o `id` e os `aria-*` já ligados ao rótulo, à dica e ao erro.
 *
 * @param {{rotulo: string, campo: string, obrigatorio?: boolean, dica?: React.ReactNode, erro?: ?string,
 *   etiqueta?: React.ReactNode, contador?: React.ReactNode, children: (props: object) => React.ReactNode}} props
 */
export function Campo({ rotulo, campo, obrigatorio = false, dica = null, erro = null, etiqueta = null, contador = null, children }) {
    const id = useId();
    const idDaDica = `${id}-dica`;
    const idDoErro = `${id}-erro`;
    const descritoPor = [dica && idDaDica, erro && idDoErro].filter(Boolean).join(' ') || undefined;

    return (
        <div className="flex min-w-0 flex-col gap-1" data-campo={campo}>
            <div className="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-0.5">
                <label htmlFor={id} className="flex flex-wrap items-center gap-1.5 text-[12.5px] font-semibold text-trilha-fg2">
                    <span>
                        {rotulo}
                        {obrigatorio && (
                            <span aria-hidden="true" className="text-trilha-bad">
                                {' '}*
                            </span>
                        )}
                    </span>
                    {etiqueta}
                </label>
                {contador}
            </div>
            {children({
                id,
                'aria-describedby': descritoPor,
                'aria-invalid': erro ? true : undefined,
                'aria-required': obrigatorio || undefined,
                className: cn(CAMPO, erro && CAMPO_COM_ERRO),
            })}
            {dica && (
                <span id={idDaDica} className="text-[12px] text-trilha-muted">
                    {dica}
                </span>
            )}
            {erro && (
                <span id={idDoErro} className="text-[12px] font-semibold text-trilha-bad" data-erro-do-campo={campo}>
                    {erro}
                </span>
            )}
        </div>
    );
}

/**
 * O campo de texto com a lista de sugestões embaixo (combobox): ↓/↑ andam, Enter escolhe, Esc fecha só a lista.
 * O que foi digitado e não existe aparece no fim como "(nova)"/"(novo)" — o servidor cria pelo nome no "Salvar".
 *
 * @param {{valor: string, onValor: (v: string) => void, opcoes: Array, onEscolher: (nome: string) => void,
 *   fora?: string[], rotuloDoNovo?: string, aoEnterSemEscolha?: ?(() => void), controle?: object,
 *   placeholder?: string, disabled?: boolean}} props  `controle`: o `id` e os `aria-*` do `Campo`.
 */
export function CampoComSugestoes({ valor, onValor, opcoes, onEscolher, fora = [], rotuloDoNovo = 'nova', aoEnterSemEscolha = null, controle = {}, placeholder, disabled }) {
    const [aberta, setAberta] = useState(false);
    const [ativa, setAtiva] = useState(-1);
    const idDaLista = useId();
    const { opcoes: casam, novo } = opcoesQueCasam(opcoes, valor, { fora });
    const itens = [...casam.map((nome) => ({ nome, novo: false })), ...(novo ? [{ nome: novo, novo: true }] : [])];
    const mostra = aberta && itens.length > 0;

    const escolher = (item) => {
        onEscolher(item.nome);
        setAberta(false);
        setAtiva(-1);
    };

    const aoTeclar = (evento) => {
        if (evento.key === 'ArrowDown') {
            evento.preventDefault();
            setAberta(true);
            setAtiva((i) => Math.min(itens.length - 1, i + 1));
        } else if (evento.key === 'ArrowUp') {
            evento.preventDefault();
            setAtiva((i) => Math.max(-1, i - 1));
        } else if (evento.key === 'Enter') {
            if (mostra && ativa >= 0 && itens[ativa]) {
                evento.preventDefault();
                escolher(itens[ativa]);
            } else if (aoEnterSemEscolha) {
                evento.preventDefault();
                aoEnterSemEscolha();
                setAberta(false);
            }
        } else if (evento.key === 'Escape' && mostra) {
            // Fecha só a lista: a gaveta lê o `defaultPrevented` e fica aberta.
            evento.preventDefault();
            setAberta(false);
            setAtiva(-1);
        }
    };

    return (
        <div className="flex min-w-0 flex-col gap-1">
            <input
                {...controle}
                type="text"
                role="combobox"
                autoComplete="off"
                aria-autocomplete="list"
                aria-expanded={mostra}
                aria-controls={idDaLista}
                aria-activedescendant={mostra && ativa >= 0 ? `${idDaLista}-${ativa}` : undefined}
                value={valor}
                placeholder={placeholder}
                disabled={disabled}
                onChange={(e) => {
                    onValor(e.target.value);
                    setAberta(true);
                    setAtiva(-1);
                }}
                onFocus={() => setAberta(true)}
                onBlur={() => setAberta(false)}
                onKeyDown={aoTeclar}
            />
            {mostra && (
                <ul id={idDaLista} role="listbox" className="m-0 flex max-h-56 list-none flex-col overflow-auto rounded-lg border border-trilha-line bg-trilha-surface p-1" data-lista-de-sugestoes="">
                    {itens.map((item, i) => (
                        <li
                            key={`${item.novo ? 'novo' : 'opcao'}-${item.nome}`}
                            id={`${idDaLista}-${i}`}
                            role="option"
                            aria-selected={i === ativa}
                            // O clique não pode tirar o foco do campo antes de escolher (o blur fecharia a lista).
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => escolher(item)}
                            className={cn('cursor-pointer rounded-md px-2 py-1.5 text-[13px] text-trilha-fg hover:bg-trilha-surface2', i === ativa && 'bg-trilha-run-bg')}
                        >
                            {item.nome}
                            {item.novo && <span className="text-trilha-muted"> ({rotuloDoNovo})</span>}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** Os ambientes: os escolhidos como chips (com "×") e o campo com busca para juntar mais. */
export function ChipsComBusca({ valores, onMudar, opcoes, controle = {}, rotuloDoNovo = 'novo', placeholder = 'Buscar ou criar…' }) {
    const [digitado, setDigitado] = useState('');
    const juntar = (nome) => {
        const limpo = String(nome ?? '').trim();
        if (!limpo) return;
        if (!valores.some((v) => nomeComparavel(v) === nomeComparavel(limpo))) onMudar([...valores, limpo]);
        setDigitado('');
    };

    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            {valores.length > 0 && (
                <ul className="m-0 flex list-none flex-wrap gap-1.5 p-0" data-chips="">
                    {valores.map((v) => (
                        <li key={v}>
                            <span className="inline-flex items-center gap-1 rounded-full bg-trilha-surface2 py-0.5 pl-2.5 pr-1 text-[12.5px] font-semibold text-trilha-fg">
                                {v}
                                {nomeNovo(opcoes, v) && <span className="font-normal text-trilha-muted">({rotuloDoNovo})</span>}
                                <button
                                    type="button"
                                    aria-label={`Tirar ${v}`}
                                    onClick={() => onMudar(valores.filter((x) => x !== v))}
                                    className="inline-flex h-5 w-5 items-center justify-center rounded-full text-trilha-muted hover:bg-trilha-line2 hover:text-trilha-fg focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-trilha-run disabled:cursor-not-allowed"
                                >
                                    <span aria-hidden="true">×</span>
                                </button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            <CampoComSugestoes
                valor={digitado}
                onValor={setDigitado}
                opcoes={opcoes}
                fora={valores}
                onEscolher={juntar}
                rotuloDoNovo={rotuloDoNovo}
                aoEnterSemEscolha={() => juntar(digitado)}
                controle={controle}
                placeholder={placeholder}
            />
        </div>
    );
}

/** A etiqueta "(nova)" ao lado do rótulo, quando o nome digitado ainda não existe na lista da empresa. */
export function EtiquetaDeNovo({ opcoes, nome, rotulo = 'nova' }) {
    if (!nomeNovo(opcoes, nome)) return null;

    return (
        <Etiqueta tom="roxo" data-nome-novo="">
            {rotulo} · criada ao salvar
        </Etiqueta>
    );
}

/**
 * Um aviso dentro da gaveta (`tom`: 'warn' âmbar, 'run' azul, 'bad' vermelho, 'ok' verde).
 */
export function AvisoNaGaveta({ tom = 'warn', titulo = null, children, ...resto }) {
    const TONS = {
        warn: 'border-trilha-warn/40 bg-trilha-warn-bg text-trilha-warn',
        run: 'border-trilha-run/30 bg-trilha-run-bg text-trilha-run',
        bad: 'border-trilha-bad/40 bg-trilha-bad-bg text-trilha-bad',
        ok: 'border-trilha-ok/40 bg-trilha-ok-bg text-trilha-ok',
    };

    return (
        <div className={cn('flex flex-col gap-1 rounded-xl border px-[14px] py-3 text-[12.5px]', TONS[tom] ?? TONS.warn)} {...resto}>
            {titulo && <b className="text-[13px]">{titulo}</b>}
            <span className="[overflow-wrap:anywhere]">{children}</span>
        </div>
    );
}

/**
 * A confirmação no RODAPÉ da gaveta (a trilha não usa `window.confirm`): o texto e as ações, com o foco na de
 * `foco` (a que não perde nada). Esc aqui é a mesma coisa que ela.
 *
 * @param {{texto: string, acoes: Array<{rotulo: string, onClick: () => void, acao: string, primario?: boolean,
 *   perigo?: boolean, foco?: boolean, ocupado?: boolean}>}} props
 */
export function ConfirmacaoNaGaveta({ texto, acoes }) {
    const focado = useRef(null);
    const doFoco = acoes.find((a) => a.foco) ?? acoes[acoes.length - 1];

    useEffect(() => {
        focado.current?.focus();
    }, []);

    return (
        <div
            className="flex w-full flex-wrap items-center justify-end gap-2"
            data-confirmacao=""
            onKeyDown={(evento) => {
                if (evento.key !== 'Escape') return;
                evento.preventDefault();
                doFoco?.onClick();
            }}
        >
            <span role="alert" className="mr-auto min-w-0 self-center text-[12.5px] font-semibold text-trilha-warn">
                {texto}
            </span>
            {acoes.map((a) => (
                <Botao
                    key={a.acao}
                    ref={a === doFoco ? focado : undefined}
                    primario={!!a.primario}
                    perigo={!!a.perigo}
                    ocupado={!!a.ocupado}
                    data-acao={a.acao}
                    onClick={a.onClick}
                >
                    {a.rotulo}
                </Botao>
            ))}
        </div>
    );
}
