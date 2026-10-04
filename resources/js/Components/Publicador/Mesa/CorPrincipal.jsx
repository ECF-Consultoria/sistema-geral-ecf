import { tomDaCor } from '../ferramentas';
import { Campo, INVALIDO, SELECT, useErroDoCampo } from './comum';
import { cn } from '@/lib/utils';

// ─── Cor e cor principal, como no Mercado Livre (análise do Publicador, 04/10/2026) ───
//
// Pedido: escolher a cor da lista OU digitar um nome próprio. No ML são dois
// atributos (o componente COLOR_INPUT junta os dois):
//   - "Cor" (COLOR) aceita nome próprio ("Azul-petróleo"): é o nome que o
//     comprador vê. Mora no nome da variação (eixo Cor) ou na ficha técnica.
//   - "Cor principal" (MAIN_COLOR) é lista FECHADA (`allow_custom_value: false`;
//     valor digitado = erro 3510): é o tom dos filtros de busca.
// Por isso este campo continua sendo uma lista, mas fica junto do nome e se
// preenche sozinho pelo nome (`tomDaCor`, efeito em `useEfeitosDasVariacoes`,
// com `origem: 'auto'`). Escolha da pessoa (`origem: 'user'`) nunca é trocada.

/**
 * Onde a "Cor principal" aparece: 'variacao' (as variações são por Cor: um tom por
 * variação), 'ficha' (a Cor é do produto: um tom só, ao lado dela) ou nulo (campo comum
 * da variação, como antes).
 */
export function ondeFicaOTom(schema, eixos) {
    if (schema?.atributos?.MAIN_COLOR?.secao !== 'VARIANTE') return null;
    if ((eixos ?? []).some((e) => e.chave === 'COLOR' && (e.valores ?? []).length > 0)) return 'variacao';
    if (['PRINCIPAIS', 'FICHA', 'AVANCADO'].includes(schema?.atributos?.COLOR?.secao)) return 'ficha';

    return null;
}

const dicaDoTom = (nome, valor) => {
    if (! nome) return 'O tom básico que o Mercado Livre usa nos filtros, só da lista. O nome da cor que o comprador vê (pode ser um nome próprio) vai em "Cor".';
    if (! valor?.value_id) return `Escolha o tom mais próximo de "${nome}": nos filtros, o Mercado Livre só aceita a cor principal da lista.`;

    return valor.origem === 'auto'
        ? `Tom de "${nome}" nos filtros do Mercado Livre, escolhido pelo nome da cor. Pode trocar.`
        : `Tom de "${nome}" nos filtros do Mercado Livre.`;
};

/** O seletor do tom. `nome` = o nome da cor que o comprador vê (do eixo ou da ficha). */
export function CampoCorPrincipal({ id, atributo, valor, nome, disabled, erro, onMudar }) {
    const tons = atributo?.valores ?? [];
    const sugerido = ! valor?.value_id ? tomDaCor(nome, tons) : null;

    return (
        <Campo rotulo="Cor principal" htmlFor={id} erro={erro} dica={dicaDoTom(nome, valor)}>
            <select id={id} value={valor?.value_id ?? ''} disabled={disabled} aria-invalid={!! erro || undefined} data-atributo="MAIN_COLOR" data-tom-origem={valor?.origem ?? 'nenhum'}
                className={cn(SELECT, erro && INVALIDO)}
                onChange={(e) => {
                    const t = tons.find((x) => String(x.id) === e.target.value);
                    onMudar(t ? { value_id: String(t.id), value_name: t.name, origem: 'user', revisar: false } : null);
                }}>
                <option value="">{sugerido ? `Escolha… (sugestão: ${sugerido.name})` : 'Escolha…'}</option>
                {tons.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
        </Campo>
    );
}

/**
 * A "Cor principal" ao lado da Cor do produto (na ficha): um tom só, gravado em todas as
 * variações que ainda não foram publicadas (no ML o atributo é por variação).
 */
export function TomDoProduto({ m }) {
    const atributo = m.schema?.atributos?.MAIN_COLOR;
    const editaveis = m.variantes.filter((v) => ! v.orfa && ! v.publicada);
    const base = editaveis.find((v) => v.ativa) ?? editaveis[0] ?? null;
    const valor = base?.atributos?.MAIN_COLOR ?? null;
    const erro = useErroDoCampo((x) => x.atributo === 'MAIN_COLOR', { vazio: atributo?.obrigatoriedade === 'REQUIRED' && ! valor?.value_id });
    if (! atributo || ! base) return null;

    const mudar = (novo) => {
        for (const v of editaveis) {
            m.mudarVar(v.chave, (atual) => {
                const atributos = { ...(atual.atributos ?? {}) };
                if (novo === null) delete atributos.MAIN_COLOR; else atributos.MAIN_COLOR = novo;

                return { atributos };
            });
        }
    };

    return (
        <div data-tom-do-produto>
            <CampoCorPrincipal id="atributo-MAIN_COLOR" atributo={atributo} valor={valor} nome={String(m.rasc.atributos?.COLOR?.value_name ?? '').trim()}
                disabled={m.disabled} erro={erro} onMudar={mudar} />
        </div>
    );
}
