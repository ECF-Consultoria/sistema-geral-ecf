import { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { Check, Plus } from 'lucide-react';
import { Checkbox } from '@/Components/ui/checkbox';
import { cn } from '@/lib/utils';

// ─── Família (escolha única) e Ambiente (múltipla) na própria célula ─────────
//
// D-05: o nome é criado UMA vez na lista da empresa e depois só se escolhe, para
// acabar com "Sala estar" × "Sala Estar". Quem decide igualdade de verdade é o
// servidor (ListasDaEmpresaService::chave); `chaveDeLista` aqui é só busca e
// exibição, para oferecer "Usar" em vez de duplicar.
//
// Família grava no clique. Ambiente marca caixas e grava AO FECHAR (a grade chama
// a função registrada em registrarFechar, como o TextareaPopup).

const ORIENTACAO = 'Não use / , | no nome. Escolha um nome simples.';
const DICA_FAMILIA = 'Família é a linha de design (ex.: Farmhouse), não a cor do produto.';

/** Sem caixa, sem acento, espaços colapsados: só para buscar e sugerir "Usar". */
export const chaveDeLista = (nome) => String(nome ?? '')
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().replace(/\s+/g, ' ').trim();

const temSeparador = (t) => /[/,|]/.test(t);

const partesDe = (v) => String(v ?? '').split(',').map((s) => s.trim()).filter(Boolean);
const contem = (lista, nome) => lista.some((x) => chaveDeLista(x) === chaveDeLista(nome));

export default function PickerLista({ tipo, multiplo = false, opcoes = [], valor, textoInicial, onCommit, onClose, registrarFechar, onListas }) {
    const rotulo = tipo === 'familia' ? 'família' : 'ambiente';
    const [busca, setBusca] = useState(textoInicial ?? '');
    const [marcados, setMarcados] = useState(() => (multiplo ? partesDe(valor) : []));

    // Ambiente tirado (ou posto) por fora com o picker aberto — o X do chip: as caixas acompanham, sem
    // perder o que a pessoa já marcou aqui, e o fechar não traz o ambiente de volta (revisão FE-IN-08).
    const valorVisto = useRef(valor);
    useEffect(() => {
        if (! multiplo) return;
        const antes = partesDe(valorVisto.current);
        const agora = partesDe(valor);
        valorVisto.current = valor;
        const sairam = antes.filter((n) => ! contem(agora, n));
        const entraram = agora.filter((n) => ! contem(antes, n));
        if (sairam.length === 0 && entraram.length === 0) return;
        setMarcados((atual) => [...atual.filter((m) => ! contem(sairam, m)), ...entraram.filter((n) => ! contem(atual, n))]);
    }, [valor, multiplo]);
    const [ativo, setAtivo] = useState(0);
    const [erro, setErro] = useState(null);
    const [criando, setCriando] = useState(false);
    const campo = useRef(null);
    const marcadosRef = useRef(marcados);
    marcadosRef.current = marcados;

    useEffect(() => { campo.current?.focus(); }, []);

    // Ambiente: fechar (clique fora ou Esc) grava as caixas marcadas.
    useEffect(() => {
        if (! multiplo) return;
        registrarFechar?.(() => {
            onCommit({ ambientes_texto: marcadosRef.current.join(', ') });
            onClose();
        });
    }, [multiplo]); // eslint-disable-line react-hooks/exhaustive-deps

    const texto = busca.trim();
    const chave = chaveDeLista(texto);
    const filtradas = useMemo(
        () => opcoes.filter((o) => chave === '' || chaveDeLista(o.nome).includes(chave)),
        [opcoes, chave],
    );
    const parecida = chave === '' ? null : opcoes.find((o) => chaveDeLista(o.nome) === chave) ?? null;
    const exata = parecida && parecida.nome === texto;
    const invalido = texto !== '' && temSeparador(texto);

    // Lista navegável: itens existentes + (usar | criar). Grafia quase igual a um nome
    // existente oferece "Usar" em vez de duplicar; nome inédito oferece "Criar".
    const lista = filtradas.map((o) => ({ tipo: 'item', nome: o.nome }));
    if (texto !== '' && ! invalido && ! exata) {
        lista.push(parecida ? { tipo: 'usar', nome: parecida.nome } : { tipo: 'criar', nome: texto });
    }
    // Família é opcional (D-28): escolhida por engano, dá para tirar. Fica no fim, para o Enter
    // da 1ª opção nunca apagar a família sem querer (revisão FE-IN-12).
    if (! multiplo && texto === '' && String(valor ?? '') !== '') {
        lista.push({ tipo: 'nenhuma', nome: `Sem ${rotulo}` });
    }

    const escolher = (nome) => {
        if (multiplo) {
            setMarcados((atual) => (atual.some((m) => chaveDeLista(m) === chaveDeLista(nome))
                ? atual.filter((m) => chaveDeLista(m) !== chaveDeLista(nome))
                : [...atual, nome]));
            setBusca('');
            setErro(null);
            campo.current?.focus();

            return;
        }
        onCommit({ familia: nome });
        onClose();
    };

    const criar = async (nome) => {
        if (criando) return;
        setCriando(true);
        setErro(null);
        try {
            const { data } = await axios.post(route(tipo === 'familia'
                ? 'portal.auth.estrutura.produtos.familias.criar'
                : 'portal.auth.estrutura.produtos.ambientes.criar'), { nome });
            if (data.listas) onListas?.(data.listas);
            escolher(data.item?.nome ?? nome);
        } catch (e) {
            setErro(e.response?.data?.errors?.nome?.[0] ?? e.response?.data?.message ?? 'Não foi possível criar agora. Tente de novo.');
        } finally {
            setCriando(false);
        }
    };

    const acionar = (i) => {
        if (! i) return;
        if (i.tipo === 'nenhuma') { onCommit({ familia: '' }); onClose(); return; }
        if (i.tipo === 'criar') criar(i.nome);
        else escolher(i.nome);
    };

    const aoTecla = (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); setAtivo((a) => Math.min(a + 1, Math.max(lista.length - 1, 0))); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setAtivo((a) => Math.max(a - 1, 0)); }
        else if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); acionar(lista[Math.min(ativo, lista.length - 1)]); }
    };

    const marcado = (nome) => marcados.some((m) => chaveDeLista(m) === chaveDeLista(nome));

    return (
        <div className="w-64 rounded-xl border border-white/[0.08] bg-ecf-card p-2 shadow-xl" onMouseDown={(e) => e.stopPropagation()}>
            <input
                ref={campo}
                value={busca}
                onChange={(e) => { setBusca(e.target.value); setAtivo(0); setErro(null); }}
                onKeyDown={aoTecla}
                placeholder={`Buscar ${rotulo}`}
                aria-label={`Buscar ${rotulo}`}
                className="h-10 w-full rounded-lg border border-white/[0.10] bg-white/[0.04] px-3 text-[13.5px] text-white placeholder:text-white/25 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0"
            />
            {tipo === 'familia' && <p className="px-1 pt-2 text-[12px] text-white/45">{DICA_FAMILIA}</p>}
            {(invalido || erro) && <p className="px-1 pt-2 text-[12px] text-amber-300">{erro ?? ORIENTACAO}</p>}

            <ul role="listbox" aria-multiselectable={multiplo} className="mt-2 max-h-56 overflow-y-auto">
                {lista.map((i, n) => (
                    <li key={`${i.tipo}-${i.nome}`} role="option" aria-selected={i.tipo === 'item' && multiplo ? marcado(i.nome) : n === ativo}
                        onMouseEnter={() => setAtivo(n)}
                        onClick={() => acionar(i)}
                        className={cn('flex min-h-9 cursor-pointer items-center gap-2 rounded-lg px-2 text-[13px] text-white/85',
                            i.tipo === 'nenhuma' && 'text-white/60', n === ativo && 'bg-white/[0.06]')}>
                        {i.tipo === 'item' && multiplo && <Checkbox checked={marcado(i.nome)} tabIndex={-1} aria-hidden="true" className="pointer-events-none" />}
                        {i.tipo === 'criar' && <Plus className="h-3.5 w-3.5 text-ecf-yellow" aria-hidden="true" />}
                        <span className="truncate">
                            {i.tipo === 'criar' ? `Criar “${i.nome}”` : i.tipo === 'usar' ? `Usar “${i.nome}”` : i.nome}
                        </span>
                        {i.tipo === 'item' && ! multiplo && valor === i.nome && <Check className="ml-auto h-3.5 w-3.5 text-ecf-yellow" aria-hidden="true" />}
                    </li>
                ))}
                {lista.length === 0 && texto === '' && (
                    <li className="px-2 py-2 text-[12px] text-white/45">{`Nenhum ${rotulo} ainda. Digite um nome para criar.`}</li>
                )}
            </ul>
        </div>
    );
}
