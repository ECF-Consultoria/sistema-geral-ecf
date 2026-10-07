import { useState } from 'react';
import axios from 'axios';
import { Loader2 } from 'lucide-react';
import Janela from '@/Components/Portal/Estrutura/Janela';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import { MSG_FALHA_REDE, corpoDaGeracao, opcoesDeTipo, textoTipoDefinido } from '@/lib/sugestoesEstrutura';
import { cn } from '@/lib/utils';

// ─── Janela de tipo e quantidades do produto (UI-SPEC "JanelaTipo", D-07, D-12) ──
//
// Aberta pelo painel Sem tipo e pela pílula de tipo do cartão. Vazio herda o padrão do tipo;
// 0 não gera. A regra de quantidade mora no servidor: o erro dele aparece sob o campo, só
// depois de tentar salvar (nunca ao digitar). Não mexe na ficha do produto da 167.

const CAMPO = 'h-11 w-full rounded-[10px] border border-white/[0.10] bg-white/[0.04] px-3 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0';
const DICA_COMBO = 'Deixe vazio para usar o padrão do tipo. Digite 0 para não gerar Combo.';
const DICA_COMBIT = 'Quantas unidades deste item entram na mesa + cadeiras. Deixe vazio para o padrão. Digite 0 para não gerar.';

const primeiroErro = (erros, campo) => (Array.isArray(erros?.[campo]) ? erros[campo][0] : (erros?.[campo] ?? null));

function Corpo({ produto, tipos, onFechar, onSalvo }) {
    const [tipo, setTipo] = useState(produto.tipo_escolhido ?? 'sem');
    const [qtdCombo, setQtdCombo] = useState(produto.qtd_combo ?? '');
    const [qtdCombit, setQtdCombit] = useState(produto.qtd_combit ?? '');
    const [salvando, setSalvando] = useState(false);
    const [erros, setErros] = useState({});     // só preenchido depois de tentar salvar
    const [falha, setFalha] = useState(null);

    const opcoes = opcoesDeTipo(tipos, produto.candidatos);
    const padrao = tipos.find((t) => t.slug === tipo);

    const salvar = async () => {
        if (salvando) return;
        setSalvando(true);
        setErros({});
        setFalha(null);
        try {
            await axios.put(route('portal.auth.estrutura.sugestoes.geracao', produto.id), corpoDaGeracao({ tipo, qtdCombo, qtdCombit }, tipos));
            const nome = padrao?.nome;
            onSalvo(nome ? textoTipoDefinido(nome) : 'Quantidades salvas. As sugestões foram atualizadas.');
        } catch (e) {
            const doServidor = e?.response?.data?.errors;
            if (e?.response?.status === 422 && doServidor) setErros(doServidor);
            else setFalha(MSG_FALHA_REDE);
        } finally {
            setSalvando(false);
        }
    };

    return (
        <form onSubmit={(e) => { e.preventDefault(); salvar(); }} className="space-y-4" data-janela-tipo>
            <div>
                <label htmlFor="jt-tipo" className="mb-1 block text-[12px] font-semibold text-white/70">Tipo</label>
                <select id="jt-tipo" value={tipo} onChange={(e) => setTipo(e.target.value)} className={cn(CAMPO, '[&>option]:bg-ecf-card')}>
                    <option value="sem">Sem tipo</option>
                    {opcoes.map((o) => <option key={o.valor} value={o.valor}>{o.rotulo}</option>)}
                </select>
                {primeiroErro(erros, 'tipo_id') && <p role="alert" className="mt-1 text-[12px] text-red-300">{primeiroErro(erros, 'tipo_id')}</p>}
            </div>

            <div>
                <label htmlFor="jt-combo" className="mb-1 block text-[12px] font-semibold text-white/70">Quantidades de Combo</label>
                <input id="jt-combo" value={qtdCombo} onChange={(e) => setQtdCombo(e.target.value)} placeholder={padrao?.qtd_combo ?? '2, 4, 6'}
                    aria-describedby="jt-combo-dica" className={CAMPO} autoComplete="off" />
                <p id="jt-combo-dica" className="mt-1 text-[12px] text-white/55">{DICA_COMBO}</p>
                {primeiroErro(erros, 'qtd_combo') && <p role="alert" className="mt-1 text-[12px] text-red-300">{primeiroErro(erros, 'qtd_combo')}</p>}
            </div>

            <div>
                <label htmlFor="jt-combit" className="mb-1 block text-[12px] font-semibold text-white/70">Quantidades de Combit</label>
                <input id="jt-combit" value={qtdCombit} onChange={(e) => setQtdCombit(e.target.value)} placeholder={padrao?.qtd_combit ?? '2, 4, 6'}
                    aria-describedby="jt-combit-dica" className={CAMPO} autoComplete="off" />
                <p id="jt-combit-dica" className="mt-1 text-[12px] text-white/55">{DICA_COMBIT}</p>
                {primeiroErro(erros, 'qtd_combit') && <p role="alert" className="mt-1 text-[12px] text-red-300">{primeiroErro(erros, 'qtd_combit')}</p>}
            </div>

            {falha && <p role="alert" className="text-[12px] text-red-300">{falha}</p>}

            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Botao variante="secundario" onClick={onFechar} className="h-11">Cancelar</Botao>
                <Botao variante="primario" type="submit" disabled={salvando} className="h-11" data-acao="salvar-tipo">
                    {salvando && <Loader2 size={14} className="animate-spin" aria-hidden="true" />}
                    Salvar tipo
                </Botao>
            </div>
        </form>
    );
}

/**
 * `produto` null = fechada. `onSalvo(texto)` recebe o aviso pronto; a página fecha e recarrega.
 * O corpo remonta a cada produto (key), então campos e erros começam limpos.
 */
export default function JanelaTipo({ produto, tipos = [], onFechar, onSalvo }) {
    return (
        <Janela aberta={Boolean(produto)} onFechar={onFechar} titulo={`Tipo de ${produto?.nome ?? ''}`} largura="max-w-md">
            {produto && <Corpo key={produto.id} produto={produto} tipos={tipos} onFechar={onFechar} onSalvo={onSalvo} />}
        </Janela>
    );
}
