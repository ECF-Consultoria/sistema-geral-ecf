import { useId, useState } from 'react';
import {
    OPCAO_OUTRO,
    aceitaNaoSeAplica,
    aceitaOutro,
    ehMultivalor,
    idDeLista,
    idDoElemento,
    numeroParaTela,
    podeSomarProprio,
    propriosDoMultivalor,
    valorProprio,
    valoresMultivalor,
} from '@/lib/fichaTecnica';
import { cn } from '@/lib/utils';
import { Botao, CAMPO, CaixaDeMarcar, Etiqueta, Opcao, Seletor } from '@/Components/Trilha/Pecas';

// ─── Trilha de produtos — um campo da ficha técnica do Mercado Livre ────────
//
// O controle certo para cada tipo que o servidor manda, nos tokens da trilha
// (claro e escuro), com os mesmos `data-*` do Portal (`data-campo-tecnico`,
// `data-tipo`, `data-obrigatorio`, `data-acao="nao-se-aplica"`) — sem nenhum
// componente do Portal (lá o tema é escuro e as listas abrem num portal).
//
// - texto (a Marca com as sugestões num `<datalist>`) e número;
// - número com unidade: a unidade única fica dentro do campo; várias, um seletor;
// - Sim/Não: clicar de novo na marcada limpa;
// - lista: seletor; lista de várias opções: caixinhas; "Outro (escrever)" onde
//   o servidor aceita um valor próprio;
// - "Não se aplica" onde o servidor oferece: trava o controle (o que estava
//   digitado fica guardado e volta ao desmarcar).
//
// O `id` do campo é interno: só o `nome` aparece. Nada usa `required`: o "*" é
// visual (o cadastro é aos poucos; o obrigatório vazio grava e vira falta).

const CAMPO_COM_ERRO = 'border-trilha-bad focus:border-trilha-bad focus:ring-trilha-bad/30';

/** As opções de uma lista de várias: caixinhas (com rolagem quando são muitas) e, onde aceita, o valor próprio. */
function CaixinhasDaLista({ campo, escolhidos, onMudar, idDoRotulo }) {
    const [texto, setTexto] = useState('');
    const opcoes = Array.isArray(campo.valores) ? campo.valores : [];
    const proprios = propriosDoMultivalor(campo, escolhidos);
    const marcar = (valor, marcado) => onMudar(campo.id, { valor: marcado ? [...escolhidos, valor] : escolhidos.filter((x) => x !== valor) });
    const somarProprio = () => {
        if (!podeSomarProprio(campo, escolhidos, texto)) return;
        // O texto igual a uma opção vira a opção (a mesma régua do servidor).
        onMudar(campo.id, { valor: valoresMultivalor(campo, [...escolhidos, texto]) });
        setTexto('');
    };

    return (
        <div className="flex min-w-0 flex-col gap-1.5" data-multivalor="">
            <ul
                role="group"
                aria-labelledby={idDoRotulo}
                className={cn('m-0 flex list-none flex-col gap-1 rounded-lg border border-trilha-line bg-trilha-surface p-2', opcoes.length + proprios.length > 8 && 'max-h-52 overflow-auto')}
            >
                {opcoes.map((o) => (
                    <li key={o.id}>
                        <label className="flex cursor-pointer items-center gap-2 text-[13px] text-trilha-fg">
                            <CaixaDeMarcar checked={escolhidos.includes(String(o.id))} onChange={(e) => marcar(String(o.id), e.target.checked)} data-opcao={o.id} />
                            <span className="min-w-0 [overflow-wrap:anywhere]">{o.nome}</span>
                        </label>
                    </li>
                ))}
                {proprios.map((p) => (
                    <li key={`proprio-${p}`}>
                        <label className="flex cursor-pointer items-center gap-2 text-[13px] text-trilha-fg">
                            <CaixaDeMarcar checked onChange={() => marcar(p, false)} data-valor-proprio={p} />
                            <span className="min-w-0 [overflow-wrap:anywhere]">{p}</span>
                            <span className="text-[12px] text-trilha-muted">(escrito)</span>
                        </label>
                    </li>
                ))}
            </ul>
            {aceitaOutro(campo) && (
                <div className="flex min-w-0 gap-1.5" data-valor-proprio="">
                    <input
                        className={cn(CAMPO, 'flex-1')}
                        value={texto}
                        maxLength={campo.max || undefined}
                        autoComplete="off"
                        placeholder="Outro (escrever)"
                        aria-label={`Outro valor de ${campo.nome}`}
                        onChange={(e) => setTexto(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key !== 'Enter') return;
                            e.preventDefault();
                            somarProprio();
                        }}
                    />
                    <Botao data-acao="somar-valor-proprio" disabled={!podeSomarProprio(campo, escolhidos, texto)} onClick={somarProprio}>
                        Adicionar
                    </Botao>
                </div>
            )}
        </div>
    );
}

/** Sim e Não: clicar de novo na marcada limpa. */
function SimNao({ id, valor, onMudar, idDoRotulo, invalido }) {
    return (
        <div id={id} tabIndex={-1} role="group" aria-labelledby={idDoRotulo} aria-invalid={invalido || undefined} className="inline-flex gap-1.5">
            {['Sim', 'Não'].map((opcao) => {
                const marcada = valor === opcao;

                return (
                    <button
                        key={opcao}
                        type="button"
                        aria-pressed={marcada}
                        data-opcao={opcao}
                        title={marcada ? `${opcao} · clique de novo para limpar` : opcao}
                        onClick={() => onMudar(marcada ? '' : opcao)}
                        className={cn(
                            'min-w-[64px] rounded-lg border px-3 py-[7px] text-[13px] font-semibold focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run disabled:cursor-not-allowed disabled:opacity-60',
                            marcada ? 'border-trilha-navy bg-trilha-navy text-trilha-on-navy' : 'border-trilha-line bg-trilha-surface text-trilha-fg hover:border-trilha-muted',
                            invalido && !marcada && 'border-trilha-bad',
                        )}
                    >
                        {opcao}
                    </button>
                );
            })}
        </div>
    );
}

/**
 * Um campo da ficha técnica.
 *
 * @param {{campo: object, atual?: {valor: any, unidade?: string, naoSeAplica?: boolean, outro?: boolean},
 *   erro?: ?string, onMudar: (id: string, parte: object) => void, sugeridoIa?: boolean}} props
 *   `onMudar(id, parte)`: a parte junta ao que o campo tinha (`{valor}`, `{unidade}`, `{naoSeAplica}`…).
 */
export default function CampoDaFicha({ campo, atual = null, erro = null, onMudar, sugeridoIa = false }) {
    const id = idDoElemento(campo.id);
    const idDoRotulo = `${id}-rotulo`;
    const idDoErro = `${id}-erro`;
    const idDaLista = useId();
    const podeNa = aceitaNaoSeAplica(campo);
    const naoSeAplica = podeNa && !!atual?.naoSeAplica;
    const valor = atual?.valor ?? '';
    const unidade = atual?.unidade || campo.unidade_padrao || '';
    const invalido = !!erro;
    const classe = cn(CAMPO, invalido && CAMPO_COM_ERRO);
    const aria = {
        'aria-invalid': invalido || undefined,
        'aria-describedby': invalido ? idDoErro : undefined,
        'aria-required': campo.obrigatorio || undefined,
    };
    const mudar = (parte) => onMudar(campo.id, parte);
    // O rótulo aponta para o controle (o Sim/Não e as caixinhas são grupos: o rótulo os nomeia pelo `aria-labelledby`).
    const comRotuloNoControle = campo.tipo !== 'sim_nao' && !ehMultivalor(campo);

    let controle;
    switch (campo.tipo) {
        case 'numero':
            controle = <input id={id} className={cn(classe, 'tabular-nums')} inputMode="decimal" autoComplete="off" value={numeroParaTela(valor)} onChange={(e) => mudar({ valor: e.target.value })} {...aria} />;
            break;
        case 'numero_unidade': {
            const unidades = Array.isArray(campo.unidades) ? campo.unidades : [];
            controle = (
                <div className="flex min-w-0 items-stretch gap-1.5">
                    <input
                        id={id}
                        className={cn(classe, 'flex-1 tabular-nums')}
                        inputMode="decimal"
                        autoComplete="off"
                        value={numeroParaTela(valor)}
                        onChange={(e) => mudar({ valor: e.target.value, unidade })}
                        {...aria}
                    />
                    {unidades.length > 1 ? (
                        <Seletor envolveClassName="w-[92px] shrink-0" value={unidade} aria-label={`Unidade de ${campo.nome}`} onChange={(e) => mudar({ unidade: e.target.value })}>
                            {unidades.map((u) => (
                                <Opcao key={u.id} value={u.id}>
                                    {u.nome}
                                </Opcao>
                            ))}
                        </Seletor>
                    ) : (
                        <span className="self-center text-[12.5px] text-trilha-muted" data-unidade-sufixo="">
                            {unidades[0]?.nome ?? unidade}
                        </span>
                    )}
                </div>
            );
            break;
        }
        case 'sim_nao':
            controle = <SimNao id={id} valor={valor} idDoRotulo={idDoRotulo} invalido={invalido} onMudar={(v) => mudar({ valor: v })} />;
            break;
        case 'lista':
            if (ehMultivalor(campo)) {
                controle = <CaixinhasDaLista campo={campo} escolhidos={valoresMultivalor(campo, valor)} onMudar={onMudar} idDoRotulo={idDoRotulo} />;
            } else {
                const proprio = valorProprio(campo, atual);
                controle = (
                    <div className="flex min-w-0 flex-col gap-1.5">
                        {/* `idDeLista` casa pelo nome também: o nome pode estar gravado como texto, sem o id da opção. */}
                        <Seletor
                            id={id}
                            className={cn(invalido && CAMPO_COM_ERRO)}
                            value={proprio !== null ? OPCAO_OUTRO : idDeLista(campo, valor)}
                            onChange={(e) => mudar(e.target.value === OPCAO_OUTRO ? { valor: '', outro: true } : { valor: e.target.value, outro: false })}
                            {...aria}
                        >
                            <Opcao value="">Selecione</Opcao>
                            {(campo.valores ?? []).map((o) => (
                                <Opcao key={o.id} value={o.id}>
                                    {o.nome}
                                </Opcao>
                            ))}
                            {aceitaOutro(campo) && <Opcao value={OPCAO_OUTRO}>Outro (escrever)</Opcao>}
                        </Seletor>
                        {proprio !== null && (
                            <input
                                className={classe}
                                value={proprio}
                                maxLength={campo.max || undefined}
                                autoComplete="off"
                                placeholder="Escreva o valor"
                                aria-label={`Outro valor de ${campo.nome}`}
                                data-valor-proprio=""
                                onChange={(e) => mudar({ valor: e.target.value, outro: true })}
                            />
                        )}
                    </div>
                );
            }
            break;
        default: {
            // Texto livre. Na Marca o servidor manda algumas como SUGESTÃO: escolhe-se uma ou digita-se a da loja.
            const sugestoes = Array.isArray(campo.sugestoes) ? campo.sugestoes.filter((s) => typeof s === 'string' && s !== '') : [];
            controle = (
                <>
                    <input
                        id={id}
                        className={classe}
                        value={valor}
                        maxLength={campo.max || undefined}
                        autoComplete="off"
                        list={sugestoes.length ? idDaLista : undefined}
                        onChange={(e) => mudar({ valor: e.target.value })}
                        {...aria}
                    />
                    {sugestoes.length > 0 && (
                        <datalist id={idDaLista} data-sugestoes="">
                            {sugestoes.map((s) => (
                                <option key={s} value={s} />
                            ))}
                        </datalist>
                    )}
                </>
            );
        }
    }

    return (
        <div
            className="flex min-w-0 flex-col gap-1"
            data-campo-tecnico=""
            data-tipo={campo.tipo}
            data-obrigatorio={campo.obrigatorio ? 'sim' : 'nao'}
            data-nao-se-aplica={naoSeAplica ? 'sim' : undefined}
        >
            <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <label id={idDoRotulo} htmlFor={comRotuloNoControle ? id : undefined} className="text-[12.5px] font-semibold text-trilha-fg2 [overflow-wrap:anywhere]">
                    {campo.nome}
                    {campo.obrigatorio && (
                        <span aria-hidden="true" className="text-trilha-bad">
                            {' '}*
                        </span>
                    )}
                </label>
                {/* Preenchido pelo "Preencher com IA" e ainda não mexido: a pessoa confere antes de salvar. */}
                {sugeridoIa && (
                    <Etiqueta tom="run" data-sugestao-ia="">
                        sugestão da IA
                    </Etiqueta>
                )}
            </div>
            {/* Com "Não se aplica" marcado, o controle inteiro fica travado — e no lugar. */}
            <fieldset disabled={naoSeAplica} className={cn('m-0 min-w-0 border-0 p-0', naoSeAplica && 'opacity-50')}>
                {controle}
            </fieldset>
            {podeNa && (
                <label className="inline-flex cursor-pointer items-center gap-2 self-start text-[12.5px] text-trilha-fg2">
                    <CaixaDeMarcar
                        checked={naoSeAplica}
                        data-acao="nao-se-aplica"
                        aria-label={`Não se aplica (${campo.nome})`}
                        onChange={(e) => mudar({ naoSeAplica: e.target.checked })}
                    />
                    Não se aplica
                </label>
            )}
            {invalido && (
                <span id={idDoErro} role="alert" className="text-[12px] font-semibold text-trilha-bad" data-erro-do-campo-tecnico="">
                    {erro}
                </span>
            )}
        </div>
    );
}
