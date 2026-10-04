import { useEffect, useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import CampoAtributo, { RotuloAtributo } from '../CampoAtributo';
import { valorVazio } from '../apoio';
import { MEDIDAS_DO_PRODUTO, UNIDADES_MEDIDA, UNIDADES_PESO, conferirPacote, daUnidadeMl, numeroDoAtributo, paraUnidadeMl, unidadeInicial } from '../ferramentas';
import { CAMPO, Campo, INVALIDO, SELECT, useErroDoCampo } from './comum';
import { cn } from '@/lib/utils';

// ─── Medidas do pacote fechado (análise do Publicador, 04/10/2026) ──────────
//
// O frete do Mercado Livre sai das medidas do produto EMBALADO (SELLER_PACKAGE_*),
// nunca das do produto fora da caixa. Os mesmos campos aparecem em dois lugares,
// com o mesmo valor (é o mesmo atributo do rascunho): em Detalhes › Mais
// características, ao lado das medidas do produto, e em Condições de venda ›
// Envio, que as herda. Mudar num muda no outro.
//
// Cada medida tem a unidade escolhida na tela (kg/g, cm/mm/m) e grava no que o
// ML aceita (g e cm). Categoria cuja unidade não seja g/cm cai no campo do schema.

export const DIMENSOES = [['SELLER_PACKAGE_HEIGHT', 'Altura do pacote'], ['SELLER_PACKAGE_WIDTH', 'Largura do pacote'], ['SELLER_PACKAGE_LENGTH', 'Comprimento do pacote']];
export const PESO = 'SELLER_PACKAGE_WEIGHT';

/** Os atributos do pacote que a categoria tem: as 3 medidas, o peso e os outros da seção. */
export function atributosDoPacote(schema) {
    const embalagem = Object.values(schema?.atributos ?? {}).filter((a) => a.secao === 'EMBALAGEM');
    const ids = DIMENSOES.map(([id]) => id);

    return {
        dimensoes: DIMENSOES.map(([id, nome]) => [embalagem.find((a) => a.id === id), nome]).filter(([a]) => a),
        peso: embalagem.find((a) => a.id === PESO) ?? null,
        outras: embalagem.filter((a) => ! ids.includes(a.id) && a.id !== PESO),
    };
}

/** As medidas do produto fora da caixa que a categoria tem (vão para a ficha, nunca para o frete). */
export const medidasDoProduto = (schema) => Object.keys(MEDIDAS_DO_PRODUTO).map((id) => schema?.atributos?.[id]).filter((a) => a && ['PRINCIPAIS', 'FICHA', 'AVANCADO'].includes(a.secao));

/** A unidade do ML é uma das que a tela sabe converter? Senão o campo fica o do schema. */
const converte = (a, tipo) => (tipo === 'peso' ? UNIDADES_PESO : UNIDADES_MEDIDA)[a.unidade_padrao ?? a.unidades?.[0] ?? (tipo === 'peso' ? 'g' : 'cm')] === 1;

/** Uma medida do pacote com a unidade da tela; grava na unidade do ML. `comErro` = só na etapa que a confere. */
function MedidaPacote({ m, a, rotulo, tipo, prefixo, comErro }) {
    const valor = m.rasc.atributos?.[a.id];
    const tabela = tipo === 'peso' ? UNIDADES_PESO : UNIDADES_MEDIDA;
    const unidadeMl = a.unidade_padrao ?? a.unidades?.[0] ?? (tipo === 'peso' ? 'g' : 'cm');
    const gravado = numeroDoAtributo(valor);
    const [unidade, setUnidade] = useState(() => unidadeInicial(gravado, tipo));
    const [texto, setTexto] = useState(() => daUnidadeMl(gravado, tabela[unidade] ?? 1));
    useEffect(() => setTexto(daUnidadeMl(numeroDoAtributo(valor), tabela[unidade] ?? 1)), [valor?.value_name]); // eslint-disable-line react-hooks/exhaustive-deps

    const preenchido = ! valorVazio(valor);
    const id = `${prefixo}-${a.id}`;
    const erroDaEtapa = useErroDoCampo((x) => x.atributo === a.id, { vazio: a.obrigatoriedade === 'REQUIRED' && ! preenchido, preenchido });
    const erro = comErro ? erroDaEtapa : null;
    const gravar = (t, u) => {
        // g sem casas; cm com uma (o ML aceita 12.5 cm).
        const n = paraUnidadeMl(t, tabela[u] ?? 1, tipo === 'peso' ? 0 : 1);
        m.mudarAtributo(a.id, n === null ? null : { value_id: null, value_name: `${n} ${unidadeMl}`, origem: 'user', revisar: false });
    };
    const trocarUnidade = (u) => {
        // O número na tela continua sendo o mesmo pacote: só muda a unidade em que é mostrado.
        setUnidade(u);
        setTexto(daUnidadeMl(numeroDoAtributo(valor), tabela[u] ?? 1));
    };

    return (
        <Campo rotulo={rotulo} htmlFor={id} erro={erro} dica={unidade !== unidadeMl && preenchido ? `Vai ao Mercado Livre como ${valor?.value_name}.` : null}>
            <div className="flex gap-2" data-campo-atributo={a.id}>
                <input id={id} inputMode="decimal" value={texto} disabled={m.disabled} onChange={(e) => setTexto(e.target.value)} onBlur={() => gravar(texto, unidade)}
                    placeholder="0" aria-invalid={!! erro || undefined} className={cn(CAMPO, 'min-w-0 tabular-nums', erro && INVALIDO)} data-atributo={a.id} />
                <select value={unidade} disabled={m.disabled} onChange={(e) => trocarUnidade(e.target.value)} aria-label={`Unidade de ${rotulo}`} data-unidade={a.id}
                    className={cn(SELECT, 'w-20 shrink-0')}>
                    {Object.keys(tabela).map((u) => <option key={u} value={u}>{u}</option>)}
                </select>
            </div>
        </Campo>
    );
}

/** Atributo da embalagem que a tela não converte: o campo do schema. */
function CampoEmbalagem({ m, a, prefixo, comErro }) {
    const valor = m.rasc.atributos?.[a.id];
    const id = `${prefixo}-${a.id}`;
    const erroDaEtapa = useErroDoCampo((x) => x.atributo === a.id, { vazio: a.obrigatoriedade === 'REQUIRED' && valorVazio(valor), preenchido: ! valorVazio(valor) });
    const erro = comErro ? erroDaEtapa : null;

    return (
        <Campo rotulo={<RotuloAtributo atributo={a} valor={valor} />} htmlFor={id} erro={erro}>
            <CampoAtributo variante="campo" id={id} atributo={a} valor={valor} disabled={m.disabled} invalido={!! erro} onChange={(v) => m.mudarAtributo(a.id, v)} />
        </Campo>
    );
}

/**
 * Os campos do pacote fechado. `prefixo` separa os ids das duas telas que os mostram;
 * `comErro` = vermelho depois do "Continuar" (só em Condições de venda, a etapa que confere o envio).
 */
export function CamposDoPacote({ m, prefixo = 'pacote', comErro = true }) {
    const { dimensoes, peso, outras } = atributosDoPacote(m.schema);
    if (dimensoes.length === 0 && ! peso && outras.length === 0) return null;

    return (
        <div className="space-y-5">
            {(dimensoes.length > 0 || peso) && (
                <div className="grid gap-x-6 gap-y-5 sm:grid-cols-2 xl:grid-cols-4" data-medidas-pacote={prefixo}>
                    {dimensoes.map(([a, nome]) => (converte(a, 'medida')
                        ? <MedidaPacote key={a.id} m={m} a={a} rotulo={nome} tipo="medida" prefixo={prefixo} comErro={comErro} />
                        : <CampoEmbalagem key={a.id} m={m} a={a} prefixo={prefixo} comErro={comErro} />))}
                    {peso && (converte(peso, 'peso')
                        ? <MedidaPacote m={m} a={peso} rotulo="Peso do pacote" tipo="peso" prefixo={prefixo} comErro={comErro} />
                        : <CampoEmbalagem m={m} a={peso} prefixo={prefixo} comErro={comErro} />)}
                </div>
            )}
            {outras.length > 0 && (
                <div className="grid gap-x-6 gap-y-5 sm:grid-cols-2 xl:grid-cols-4">{outras.map((a) => <CampoEmbalagem key={a.id} m={m} a={a} prefixo={prefixo} comErro={comErro} />)}</div>
            )}
        </div>
    );
}

const AVISO_DO_PACOTE = {
    menor: 'O pacote ficou menor ou mais leve que o produto. Confira se não foram usadas as medidas do produto fora da caixa: o pacote fechado inclui a embalagem.',
    igual: 'As medidas do pacote estão iguais às do produto. O pacote fechado costuma ser maior e mais pesado por causa da embalagem; confira.',
};

/** Aviso (não bloqueia) quando o pacote não parece conter o produto. */
export function AvisoDoPacote({ m }) {
    const aviso = conferirPacote(m.rasc?.atributos);
    if (! aviso) return null;

    return (
        <p className="flex items-start gap-1.5 text-[13px] text-amber-300" data-aviso-pacote={aviso}>
            <AlertTriangle size={14} className="mt-0.5 shrink-0" aria-hidden="true" /> {AVISO_DO_PACOTE[aviso]}
        </p>
    );
}
