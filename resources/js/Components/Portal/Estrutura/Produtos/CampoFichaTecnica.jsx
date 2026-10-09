import { useState } from 'react';
import { X } from 'lucide-react';
import { Obrigatorio, RotuloComExplicacao } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { aceitaNaoSeAplica, aceitaTextoLivre, ehMultivalor, idDeLista, idDoElemento, idsMultivalor, numeroParaTela } from '@/lib/fichaTecnica';
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

const CAMPO = 'h-11 lg:h-9 w-full min-w-0 rounded-lg border border-white/20 bg-black/40 px-3 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0';
const ROTULO = 'block text-[13px] font-medium text-white/80';
const BOTAO_OPCAO = 'h-11 min-w-[64px] flex-1 px-3 text-[14px] lg:h-9 lg:flex-none';

/** Valor do `<option>` que abre a digitação na lista de escolha única. Nunca vai ao servidor. */
const OUTRO = '__digitar__';

/**
 * Lista que aceita mais de uma opção: cada escolha vira um chip com X, e o que ainda
 * não foi escolhido continua à mão para somar. Mesmo visual dos chips de Ambientes.
 *
 * Escolher é por lista fechada, a não ser que o servidor marque `texto_livre` (a mesma régua
 * do editor interno): aí, abaixo das opções, um campo deixa somar um valor digitado, que vira
 * chip igual aos outros. Sem a marca, valor fora das opções não serve e não é oferecido.
 */
function ListaMultipla({ id, campo, escolhidos, onChange, descricao, invalido }) {
    const opcoes = Array.isArray(campo.valores) ? campo.valores : [];
    const nomePorId = new Map(opcoes.map((o) => [String(o.id), o.nome]));
    const restantes = opcoes.filter((o) => ! escolhidos.includes(String(o.id)));
    const livre = aceitaTextoLivre(campo);
    const [digitado, setDigitado] = useState('');

    const somar = (valor) => { if (valor && ! escolhidos.includes(valor)) onChange([...escolhidos, valor]); };
    const tirar = (valor) => onChange(escolhidos.filter((x) => x !== valor));
    const somarDigitado = () => {
        // O nome de uma opção vira a opção; o resto entra como texto. "|" separa os chips gravados.
        const t = digitado.replace(/\|/g, ' ').trim();
        if (t === '') return;
        const opcao = opcoes.find((o) => String(o.nome).toLowerCase() === t.toLowerCase());
        somar(opcao ? String(opcao.id) : t);
        setDigitado('');
    };

    return (
        <div data-multivalor className={cn('min-w-0 rounded-lg border bg-black/40 p-1.5', invalido ? 'border-red-400/50' : 'border-white/20')}>
            {escolhidos.length > 0 && (
                <ul className="mb-1.5 flex flex-wrap gap-1.5">
                    {escolhidos.map((v) => (
                        <li key={v} data-chip={v}
                            className="inline-flex h-7 max-w-full items-center gap-1.5 rounded-md border border-white/[0.08] bg-white/[0.06] pl-2.5 pr-1 text-[14px] text-white">
                            <span className="truncate">{nomePorId.get(v) ?? v}</span>
                            <button type="button" onClick={() => tirar(v)} aria-label={`Tirar ${nomePorId.get(v) ?? v} de ${campo.nome}`}
                                className="grid h-5 w-5 shrink-0 place-items-center rounded text-white/60 hover:bg-white/10 hover:text-white">
                                <X size={13} />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {/* `value` fixo em "": o select é só o gesto de somar, quem guarda a escolha são os chips. */}
            <select id={id} value="" disabled={restantes.length === 0}
                aria-invalid={invalido || undefined} aria-describedby={descricao}
                onChange={(e) => somar(e.target.value)}
                className="h-9 w-full min-w-0 rounded-md border-0 bg-transparent px-1.5 text-[14px] text-white focus:outline-none focus:ring-0 disabled:text-white/30 lg:h-7">
                <option value="">
                    {restantes.length === 0 ? 'Todas as opções já escolhidas' : (escolhidos.length ? 'Adicionar outra…' : 'Selecione')}
                </option>
                {restantes.map((o) => <option key={o.id} value={o.id}>{o.nome}</option>)}
            </select>
            {livre && (
                <div className="mt-1.5 flex gap-1.5" data-digitar>
                    <input value={digitado} maxLength={campo.max || undefined} autoComplete="off"
                        placeholder="Ou digite outro valor" aria-label={`Digitar outro valor para ${campo.nome}`}
                        onChange={(e) => setDigitado(e.target.value)}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); somarDigitado(); } }}
                        className="h-9 w-full min-w-0 rounded-md border border-white/10 bg-transparent px-2 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 lg:h-7" />
                    <button type="button" onClick={somarDigitado} disabled={digitado.trim() === ''}
                        className="h-9 shrink-0 rounded-md border border-white/10 px-3 text-[13px] text-white/80 hover:bg-white/[0.07] disabled:text-white/30 lg:h-7">
                        Adicionar
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * Lista de escolha única que também aceita digitar (`texto_livre`): as opções primeiro e, no fim,
 * "Outro (digitar)", que abre um campo de texto. Valor guardado que não é opção (texto de antes)
 * abre já digitado — sem isso ele sumiria da tela e o salvar o apagaria.
 */
function ListaComDigitacao({ id, campo, valor, onChange, descricao, invalido, classe }) {
    const escolhido = idDeLista(campo, valor);
    const bruto = String(valor ?? '');
    const [digitando, setDigitando] = useState(escolhido === '' && bruto.trim() !== '');
    // Digitando, o campo mostra o que a pessoa escreveu, mesmo que bata com uma opção (no salvar vira a opção).
    const mostraTexto = digitando || (escolhido === '' && bruto.trim() !== '');
    const texto = mostraTexto ? bruto : '';

    return (
        <div className="flex flex-col gap-2">
            <select id={id} className={classe} value={mostraTexto ? OUTRO : escolhido} aria-invalid={invalido || undefined} aria-describedby={descricao}
                onChange={(e) => {
                    const v = e.target.value;
                    setDigitando(v === OUTRO);
                    onChange(v === OUTRO ? texto : v);
                }}>
                <option value="">Selecione</option>
                {(campo.valores ?? []).map((o) => <option key={o.id} value={o.id}>{o.nome}</option>)}
                <option value={OUTRO}>Outro (digitar)</option>
            </select>
            {mostraTexto && (
                <input className={classe} value={texto} maxLength={campo.max || undefined} autoComplete="off" data-digitar
                    placeholder="Digite o valor" aria-label={`Valor digitado para ${campo.nome}`}
                    onChange={(e) => onChange(e.target.value)} />
            )}
        </div>
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
                <ListaMultipla id={id} campo={campo} escolhidos={idsMultivalor(campo, valor)} descricao={descricao} invalido={invalido}
                    onChange={(ids) => onMudar(campo.id, { valor: ids })} />
            ) : aceitaTextoLivre(campo) ? (
                <ListaComDigitacao id={id} campo={campo} valor={valor} descricao={descricao} invalido={invalido} classe={classe}
                    onChange={(v) => onMudar(campo.id, { valor: v })} />
            ) : (
                // `idDeLista` casa por nome também: campo que hoje é lista pode ter o nome
                // gravado como texto livre de antes — sem isso ele apareceria vazio.
                <select id={id} className={classe} value={idDeLista(campo, valor)} aria-invalid={invalido || undefined} aria-describedby={descricao}
                    onChange={(e) => onMudar(campo.id, { valor: e.target.value })}>
                    <option value="">Selecione</option>
                    {(campo.valores ?? []).map((o) => <option key={o.id} value={o.id}>{o.nome}</option>)}
                </select>
            );
            break;
        default:
            controle = (
                <input id={id} className={classe} value={valor} maxLength={campo.max || undefined} autoComplete="off"
                    aria-invalid={invalido || undefined} aria-describedby={descricao}
                    onChange={(e) => onMudar(campo.id, { valor: e.target.value })} />
            );
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
