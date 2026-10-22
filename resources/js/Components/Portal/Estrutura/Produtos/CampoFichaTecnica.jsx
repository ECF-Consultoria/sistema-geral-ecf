import { useState } from 'react';
import { X } from 'lucide-react';
import { Obrigatorio, RotuloComExplicacao } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import {
    OPCAO_OUTRO, aceitaNaoSeAplica, aceitaOutro, ehMultivalor, idDeLista, idDoElemento, numeroParaTela, podeSomarProprio, valorProprio, valoresMultivalor,
} from '@/lib/fichaTecnica';
import { cn } from '@/lib/utils';

// ─── Um campo da ficha técnica ──────────────────────────────────────────────
//
// O controle certo para cada tipo que o servidor manda, no mesmo visual dos
// campos da ficha. O rótulo é o `nome` do servidor; o `id` do campo só serve de
// chave e de âncora interna, nunca é mostrado.
//
// `explicacao` (opcional, texto): o "o que é isto?" do campo — o ícone de
// informação ao lado do rótulo (componente compartilhado `Explicacao`, balão no
// hover e no foco do teclado). Sem `title` no rótulo: seriam dois balões. Este
// componente não busca nada: o texto vem com a definição do campo.
//
// "Não se aplica": no campo que o servidor marca, uma caixa abaixo do controle.
// Marcada, o controle fica travado e o que estava digitado não vai no salvar.
//
// "Outro" (11/10/2026): a lista que o servidor marca com `aceita_outro` ganha a opção de
// escrever um valor próprio, além das opções. As opções continuam vindo primeiro; quem
// precisa de um valor que não está nelas escolhe "Outro" e escreve. Lista sem a marca
// continua fechada.

const CAMPO = 'h-11 lg:h-9 w-full min-w-0 rounded-lg border border-white/20 bg-black/40 px-3 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0';
const ROTULO = 'block text-[13px] font-medium text-white/80';
const BOTAO_OPCAO = 'h-11 min-w-[64px] flex-1 px-3 text-[14px] lg:h-9 lg:flex-none';

/**
 * Lista que aceita mais de uma opção: cada escolha vira um chip com X, e o que ainda
 * não foi escolhido continua à mão para somar. Mesmo visual dos chips de Ambientes.
 *
 * As opções são as da categoria. Onde o campo aceita (`aceita_outro`), "Outro" abre uma
 * caixa para escrever um valor próprio, que vira um chip como os demais; onde não
 * aceita, a lista continua fechada.
 */
function ListaMultipla({ id, campo, escolhidos, onChange, descricao, invalido }) {
    const opcoes = Array.isArray(campo.valores) ? campo.valores : [];
    const nomePorId = new Map(opcoes.map((o) => [String(o.id), o.nome]));
    const restantes = opcoes.filter((o) => ! escolhidos.includes(String(o.id)));
    const comOutro = aceitaOutro(campo);
    const [escrevendo, setEscrevendo] = useState(false);
    const [texto, setTexto] = useState('');

    const somar = (valor) => {
        if (valor === OPCAO_OUTRO) { setEscrevendo(true); return; }
        if (valor) onChange([...escolhidos, valor]);
    };
    const tirar = (valor) => onChange(escolhidos.filter((x) => x !== valor));
    const somarProprio = () => {
        if (! podeSomarProprio(campo, escolhidos, texto)) return;
        // Passa pela mesma regra dos demais: o texto igual a uma opção vira a opção.
        onChange(valoresMultivalor(campo, [...escolhidos, texto]));
        setTexto('');
        setEscrevendo(false);
    };

    // Demo 23/10 (N22): depois de escolher, o select volta a "Adicionar outra…" e a escolha só aparecia
    // num chip cinza quase da cor do fundo — parecia que nada tinha sido escolhido. Os escolhidos viram
    // selos em destaque e o select diz quantos já estão escolhidos.
    const rotuloDoSelect = restantes.length === 0 && ! comOutro
        ? 'Todas as opções já escolhidas'
        : (escolhidos.length ? `Adicionar outra… (${escolhidos.length} ${escolhidos.length === 1 ? 'escolhida' : 'escolhidas'})` : 'Selecione');

    return (
        <div data-multivalor className={cn('min-w-0 rounded-lg border bg-black/40 p-1.5', invalido ? 'border-red-400/50' : 'border-white/20')}>
            {escolhidos.length > 0 && (
                <ul className="mb-1.5 flex flex-wrap gap-1.5" aria-label={`Escolhidos em ${campo.nome}`} data-escolhidos>
                    {escolhidos.map((v) => (
                        <li key={v} data-chip={v}
                            className="inline-flex h-7 max-w-full items-center gap-1.5 rounded-md border border-ecf-yellow/40 bg-ecf-yellow/15 pl-2.5 pr-1 text-[14px] font-medium text-white">
                            <span className="truncate">{nomePorId.get(v) ?? v}</span>
                            <button type="button" onClick={() => tirar(v)} aria-label={`Tirar ${nomePorId.get(v) ?? v} de ${campo.nome}`}
                                className="grid h-5 w-5 shrink-0 place-items-center rounded text-white/70 hover:bg-white/10 hover:text-white">
                                <X size={13} />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {/* `value` fixo em "": o select é só o gesto de somar, quem guarda a escolha são os chips. */}
            <select id={id} value="" disabled={restantes.length === 0 && ! comOutro}
                aria-invalid={invalido || undefined} aria-describedby={descricao}
                onChange={(e) => somar(e.target.value)}
                className="h-9 w-full min-w-0 rounded-md border-0 bg-transparent px-1.5 text-[14px] text-white focus:outline-none focus:ring-0 disabled:text-white/30 lg:h-7">
                <option value="">{rotuloDoSelect}</option>
                {restantes.map((o) => <option key={o.id} value={o.id}>{o.nome}</option>)}
                {comOutro && <option value={OPCAO_OUTRO}>Outro (escrever)</option>}
            </select>
            {comOutro && escrevendo && (
                <div className="mt-1.5 flex gap-1.5" data-valor-proprio>
                    <input autoFocus value={texto} maxLength={campo.max || undefined} autoComplete="off" placeholder="Escreva o valor"
                        aria-label={`Outro valor de ${campo.nome}`}
                        onChange={(e) => setTexto(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') { e.preventDefault(); somarProprio(); }
                            if (e.key === 'Escape') { setTexto(''); setEscrevendo(false); }
                        }}
                        className="h-9 min-w-0 flex-1 rounded-md border border-white/20 bg-black/40 px-2.5 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 lg:h-8" />
                    <button type="button" onClick={somarProprio} disabled={! podeSomarProprio(campo, escolhidos, texto)} data-acao="somar-valor-proprio"
                        className="h-9 shrink-0 rounded-md border border-white/20 px-3 text-[13px] font-medium text-white hover:bg-white/[0.07] disabled:opacity-40 lg:h-8">
                        Adicionar
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * Lista de escolha única. Onde o campo aceita (`aceita_outro`), a última opção é "Outro":
 * escolhida, abre a caixa para escrever o valor próprio logo abaixo.
 */
function ListaUnica({ id, campo, atual, classe, descricao, invalido, onMudar }) {
    const valor = atual?.valor ?? '';
    const proprio = valorProprio(campo, atual);
    const comOutro = aceitaOutro(campo);

    return (
        <>
            {/* `idDeLista` casa por nome também: o nome pode estar gravado como texto, sem o id da opção. */}
            <select id={id} className={classe} value={proprio !== null ? OPCAO_OUTRO : idDeLista(campo, valor)}
                aria-invalid={invalido || undefined} aria-describedby={descricao}
                onChange={(e) => onMudar(campo.id, e.target.value === OPCAO_OUTRO ? { valor: '', outro: true } : { valor: e.target.value, outro: false })}>
                <option value="">Selecione</option>
                {(campo.valores ?? []).map((o) => <option key={o.id} value={o.id}>{o.nome}</option>)}
                {comOutro && <option value={OPCAO_OUTRO}>Outro (escrever)</option>}
            </select>
            {proprio !== null && (
                <input className={cn(classe, 'mt-1.5')} value={proprio} maxLength={campo.max || undefined} autoComplete="off"
                    autoFocus={!! atual?.outro && proprio === ''} placeholder="Escreva o valor" data-valor-proprio
                    aria-label={`Outro valor de ${campo.nome}`} aria-invalid={invalido || undefined}
                    onChange={(e) => onMudar(campo.id, { valor: e.target.value, outro: true })} />
            )}
        </>
    );
}

/** "—", "Sim" e "Não": um par de opções, com a terceira para "não informado". */
function SimNao({ id, valor, onChange, descricao, invalido }) {
    const opcoes = [['', '—', 'Não informado'], ['Sim', 'Sim', 'Sim'], ['Não', 'Não', 'Não']];

    return (
        <div id={id} tabIndex={-1} role="group" aria-labelledby={descricao} aria-invalid={invalido || undefined}
            className={cn('inline-flex w-full overflow-hidden rounded-lg border bg-black/40 sm:w-auto', invalido ? 'border-red-400/50' : 'border-white/20')}>
            {opcoes.map(([chave, texto, dica]) => (
                <button key={chave || 'vazio'} type="button" aria-pressed={valor === chave} title={dica} aria-label={dica}
                    onClick={() => onChange(chave)} data-opcao={chave || 'vazio'}
                    className={cn(BOTAO_OPCAO, 'border-r border-white/10 last:border-r-0', valor === chave ? 'bg-ecf-yellow font-semibold text-black' : 'text-white/80 hover:bg-white/[0.07]')}>
                    {texto}
                </button>
            ))}
        </div>
    );
}

export default function CampoFichaTecnica({ campo, atual, erro, onMudar, explicacao }) {
    const id = idDoElemento(campo.id);
    const podeNa = aceitaNaoSeAplica(campo);
    const naoSeAplica = podeNa && !! atual?.naoSeAplica;
    const ajuda = typeof explicacao === 'string' && explicacao.trim() !== '' ? explicacao.trim() : undefined;
    const rotuloId = `${id}-rotulo`;
    const valor = atual?.valor ?? '';
    const unidade = atual?.unidade || campo.unidade_padrao || '';
    const invalido = !! erro;
    const classe = cn(CAMPO, invalido && 'border-red-400/50');
    const descricao = invalido ? `${id}-erro` : undefined;

    let controle;
    switch (campo.tipo) {
        case 'numero':
            controle = (
                <input id={id} className={classe} inputMode="decimal" autoComplete="off" value={numeroParaTela(valor)}
                    aria-invalid={invalido || undefined} aria-describedby={descricao}
                    onChange={(e) => onMudar(campo.id, { valor: e.target.value })} />
            );
            break;
        case 'numero_unidade':
            controle = (
                <div className="flex gap-2">
                    <input id={id} className={classe} inputMode="decimal" autoComplete="off" value={numeroParaTela(valor)}
                        aria-invalid={invalido || undefined} aria-describedby={descricao}
                        onChange={(e) => onMudar(campo.id, { valor: e.target.value, unidade })} />
                    {(campo.unidades ?? []).length > 0 && (
                        <select className={cn(CAMPO, 'w-28 shrink-0 lg:w-28')} value={unidade} aria-label={`Unidade de ${campo.nome}`}
                            onChange={(e) => onMudar(campo.id, { unidade: e.target.value })}>
                            {campo.unidades.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
                        </select>
                    )}
                </div>
            );
            break;
        case 'sim_nao':
            controle = <SimNao id={id} valor={valor} descricao={rotuloId} invalido={invalido} onChange={(v) => onMudar(campo.id, { valor: v })} />;
            break;
        case 'lista':
            controle = ehMultivalor(campo) ? (
                <ListaMultipla id={id} campo={campo} escolhidos={valoresMultivalor(campo, valor)} descricao={descricao} invalido={invalido}
                    onChange={(escolhidos) => onMudar(campo.id, { valor: escolhidos })} />
            ) : (
                <ListaUnica id={id} campo={campo} atual={atual} classe={classe} descricao={descricao} invalido={invalido} onMudar={onMudar} />
            );
            break;
        default: {
            // Texto livre. Na Marca (10/10/2026) o servidor manda algumas marcas como SUGESTÃO: o cliente
            // escolhe uma ou digita a dele.
            const sugestoes = Array.isArray(campo.sugestoes) ? campo.sugestoes.filter((s) => typeof s === 'string' && s !== '') : [];
            const listaId = sugestoes.length > 0 ? `${id}-sugestoes` : undefined;
            controle = (
                <>
                    <input id={id} className={classe} value={valor} maxLength={campo.max || undefined} autoComplete="off" list={listaId}
                        aria-invalid={invalido || undefined} aria-describedby={descricao}
                        onChange={(e) => onMudar(campo.id, { valor: e.target.value })} />
                    {listaId && (
                        <datalist id={listaId} data-sugestoes>
                            {sugestoes.map((s) => <option key={s} value={s} />)}
                        </datalist>
                    )}
                </>
            );
        }
    }

    return (
        <div data-campo-tecnico data-tipo={campo.tipo} data-obrigatorio={campo.obrigatorio ? 'sim' : 'nao'}
            data-nao-se-aplica={naoSeAplica ? 'sim' : undefined}>
            <RotuloComExplicacao id={rotuloId} htmlFor={campo.tipo === 'sim_nao' ? undefined : id} className={ROTULO}
                explicacao={ajuda} nome={campo.nome}>
                {campo.nome}{campo.obrigatorio && <> <Obrigatorio /></>}
            </RotuloComExplicacao>
            {/* Com "Não se aplica" marcado, o controle inteiro (chips, unidade, Sim/Não) fica travado. */}
            <fieldset disabled={naoSeAplica} className={cn('min-w-0 border-0 p-0', naoSeAplica && 'opacity-40')}>
                {controle}
            </fieldset>
            {podeNa && (
                <label className="mt-1.5 inline-flex cursor-pointer items-center gap-2 text-[13px] text-white/70">
                    <input type="checkbox" checked={naoSeAplica} data-acao="nao-se-aplica"
                        onChange={(e) => onMudar(campo.id, { naoSeAplica: e.target.checked })}
                        className="h-4 w-4 rounded border-white/30 bg-black/40 text-ecf-yellow focus:ring-0 focus:ring-offset-0" />
                    Não se aplica
                    {/* Leitor de tela: com dezenas de caixas, cada uma diz de qual campo é. */}
                    <span className="sr-only"> ({campo.nome})</span>
                </label>
            )}
            {invalido && <p id={`${id}-erro`} role="alert" className="mt-1 text-[12px] text-red-300">{erro}</p>}
        </div>
    );
}
