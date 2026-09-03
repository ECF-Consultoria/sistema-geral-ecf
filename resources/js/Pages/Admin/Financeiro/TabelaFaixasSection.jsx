import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Plus, Trash2, Lock, Table2 } from 'lucide-react';

/**
 * TabelaFaixasSection — bloco de cadastro manual da tabela de faixas
 * dentro do accordion da empresa (Fase 137 Plano 09, D-04/D-13).
 *
 * Extraído de `Financeiro.jsx` (arquivo já com ~1300 linhas antes desta
 * seção) por tamanho, conforme decisão deixada em aberto pelo UI-SPEC.
 *
 * Três estados possíveis por empresa (`empresa.tabela_origem`):
 *  - 'servico': herda a tabela do serviço — lista somente leitura + CTA
 *    para criar exceção própria ou editar a tabela do serviço.
 *  - 'propria': tem exceção própria (D-13) — vence sobre a do serviço.
 *  - null: nem exceção própria, nem serviço candidato com tabela — estado
 *    "A DEFINIR" (nunca R$ 0, nunca faixa aproximada).
 *
 * Fase 138 (D-01) acrescenta um quarto bloco, exclusivo das linhas de
 * GRUPO (`empresa.tipo === 'grupo'`), renderizado ANTES dos três estados
 * acima — que continuam servindo, sem alteração, para editar a tabela da
 * empresa/serviço da empresa do grupo que mais faturou no mês (termo
 * interno do backend, nunca escrito na tela):
 *  - grupo COM tabela própria (`tabela_origem === 'grupo'`): selo "Tabela
 *    deste grupo" + lista somente leitura vinda de `faixasPorGrupo`.
 *  - grupo SEM tabela própria: frase nomeando de qual empresa a tabela foi
 *    herdada (`tabela_herdada_de_nome`) — herança que era invisível antes
 *    desta fase.
 *
 * ⚠️ Limitação conhecida documentada em 137-09-SUMMARY.md: o backend
 * (`AdminController::fechamento()`) não expõe hoje as LINHAS da tabela
 * própria de uma empresa (só a origem/nome do serviço substituído) — este
 * componente não toca em `AdminController.php` (arquivo do plano 137-08,
 * em execução paralela). Por isso "editar" uma tabela própria já existente
 * abre um formulário em branco (nunca finge mostrar valores que não temos),
 * com aviso explícito de que a tabela inteira precisa ser preenchida de
 * novo (D-13 é all-or-nothing de qualquer forma).
 */

const fmtBRL = (n) => n == null ? '—'
    : Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL',
        minimumFractionDigits: 0, maximumFractionDigits: 0 });

// Prefixa "a partir de" quando a faixa é piso — mesma disciplina de
// Financeiro.jsx (137-09 Tarefa 1): nunca mostrar o valor seco de uma
// faixa aberta, isso faria o Administrativo cobrar a menos.
const fmtValorFaixa = (valor, isPiso) => valor == null ? null
    : (isPiso ? `a partir de ${fmtBRL(valor)}` : fmtBRL(valor));

function linhaVaziaFaixa(ordem) {
    return { ordem, limite_superior: '', valor: '', valor_e_piso: false };
}

// ─── Form de faixa (add/edit linha), Dialog reutilizável ─────────────────
// Sempre envia a TABELA INTEIRA (D-13, all-or-nothing) — nunca uma linha
// isolada. Validação de sobreposição é autoritativa no backend
// (SalvarFaixasFaturamentoRequest); o front só exibe a mensagem que veio
// de lá, sem reimplementar a regra.
function FaixaFormDialog({ open, title, aviso, faixasIniciais, onClose, onSalvar, salvando, erro }) {
    const [linhas, setLinhas] = useState([]);

    // Reabastece o form sempre que o dialog abre — evita herdar estado de
    // uma empresa/serviço diferente do último dialog aberto.
    useEffect(() => {
        if (!open) return;
        setLinhas(
            faixasIniciais && faixasIniciais.length > 0
                ? faixasIniciais.map(f => ({
                    ordem: f.ordem,
                    limite_superior: f.limite_superior ?? '',
                    valor: f.valor ?? '',
                    valor_e_piso: !!f.valor_e_piso,
                }))
                : [linhaVaziaFaixa(1)]
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    function atualizarLinha(idx, campo, valor) {
        setLinhas(prev => prev.map((l, i) => {
            if (i !== idx) return l;
            const nova = { ...l, [campo]: valor };
            // Backend recusa "valor é piso" numa faixa com teto — some o
            // checkbox assim que o campo de teto deixa de estar vazio.
            if (campo === 'limite_superior' && valor !== '') nova.valor_e_piso = false;
            return nova;
        }));
    }

    function adicionarLinha() {
        const proximaOrdem = linhas.length > 0
            ? Math.max(...linhas.map(l => Number(l.ordem) || 0)) + 1
            : 1;
        setLinhas(prev => [...prev, linhaVaziaFaixa(proximaOrdem)]);
    }

    function removerLinha(idx) {
        const linha = linhas[idx];
        if (!confirm(`Remover a faixa "${linha.ordem}ª faixa" desta tabela?`)) return;
        setLinhas(prev => prev.filter((_, i) => i !== idx));
    }

    function salvar() {
        const payload = linhas.map(l => ({
            ordem: Number(l.ordem),
            limite_superior: l.limite_superior === '' ? null : Number(l.limite_superior),
            valor: Number(l.valor),
            valor_e_piso: !!l.valor_e_piso,
        }));
        onSalvar({ faixas: payload });
    }

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                </DialogHeader>
                {aviso && <p className="text-white/40 text-[11px] -mt-2">{aviso}</p>}
                {erro && <p className="text-red-400 text-[11px]">{erro}</p>}

                <div className="space-y-3 max-h-[50vh] overflow-y-auto pr-1">
                    {linhas.map((linha, idx) => (
                        <div key={idx} className="border-b border-white/[0.06] pb-3 space-y-2 last:border-0">
                            <div className="grid grid-cols-[64px_1fr_1fr_auto] gap-2 items-end">
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Ordem</Label>
                                    <Input
                                        type="number"
                                        min="1"
                                        value={linha.ordem}
                                        onChange={e => atualizarLinha(idx, 'ordem', e.target.value)}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Faturamento até</Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={linha.limite_superior}
                                        placeholder="Sem limite superior"
                                        onChange={e => atualizarLinha(idx, 'limite_superior', e.target.value)}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Valor da mensalidade</Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={linha.valor}
                                        onChange={e => atualizarLinha(idx, 'valor', e.target.value)}
                                    />
                                </div>
                                <button
                                    type="button"
                                    onClick={() => removerLinha(idx)}
                                    title="Remover faixa"
                                    className="text-white/40 hover:text-red-400 p-2 rounded transition-colors"
                                >
                                    <Trash2 size={14} />
                                </button>
                            </div>
                            {linha.limite_superior === '' && (
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={!!linha.valor_e_piso}
                                        onChange={e => atualizarLinha(idx, 'valor_e_piso', e.target.checked)}
                                        className="h-4 w-4 rounded border-white/20 bg-white/5 accent-ecf-yellow"
                                    />
                                    <span className="text-[11px] text-white/60">Valor é um piso (&quot;a partir de&quot;)</span>
                                </label>
                            )}
                        </div>
                    ))}
                </div>

                <button
                    type="button"
                    onClick={adicionarLinha}
                    className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-ecf-yellow bg-ecf-yellow/10 hover:bg-ecf-yellow/20 border border-ecf-yellow/20 px-3 h-7 rounded-lg transition-colors w-fit"
                >
                    <Plus size={12} /> Adicionar faixa
                </button>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
                    <Button type="button" onClick={salvar} disabled={salvando || linhas.length === 0}>
                        {salvando ? 'Salvando...' : 'Salvar'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function TabelaFaixasSection({ empresa, faixasPorServico = [], faixasPorGrupo = [], competenciaFechada = false }) {
    // 'criar-propria' | 'editar-propria' | 'editar-servico' | 'criar-grupo' | 'editar-grupo' | null
    const [dialog, setDialog] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState(null);

    const servicoAplicado = empresa.tabela_origem === 'servico'
        ? faixasPorServico.find(s => s.nome === empresa.tabela_servico_nome)
        : null;

    // Fase 138 (D-01) — tabela própria do grupo, só existe quando a linha é
    // de grupo e a origem já resolveu para 'grupo'.
    const grupoAplicado = (empresa.tipo === 'grupo' && empresa.tabela_origem === 'grupo')
        ? faixasPorGrupo.find(g => g.id === empresa.company_group_id)
        : null;

    // Melhor esforço para nomear o serviço substituído quando a empresa já
    // tem tabela própria — o resolver (FechamentoFaixaResolver::paraEmpresa)
    // corta a resolução assim que encontra a exceção (D-13) e não devolve
    // "qual serviço seria o dono"; então inferimos pelo cruzamento entre os
    // serviços contratados desta empresa e o catálogo de serviços com
    // tabela cadastrada. Só para exibição — nunca usado no payload salvo.
    const nomesComTabela = new Set(faixasPorServico.map(s => s.nome));
    const servicoInferido = empresa.tabela_origem === 'propria'
        ? (empresa.servicos_contratados || [])
            .map(c => c.servico_nome)
            .find(nome => nomesComTabela.has(nome))
        : null;

    function fecharDialog() {
        setDialog(null);
        setErro(null);
    }

    function extrairErro(errors) {
        const primeiro = Object.values(errors ?? {})[0];
        if (Array.isArray(primeiro)) return primeiro[0];
        return primeiro ?? 'Não foi possível salvar a tabela.';
    }

    function salvarEmpresa(payload) {
        setSalvando(true);
        setErro(null);
        router.post(route('admin.financeiro.faixas.empresa', empresa.id), payload, {
            preserveScroll: true,
            onSuccess: () => fecharDialog(),
            onError: (errors) => setErro(extrairErro(errors)),
            onFinish: () => setSalvando(false),
        });
    }

    function salvarServico(payload) {
        if (!servicoAplicado) return;
        if (!confirm(`Editar a tabela do serviço "${servicoAplicado.nome}" afeta todas as empresas que a usam. Confirmar?`)) return;

        setSalvando(true);
        setErro(null);
        router.post(route('admin.financeiro.faixas.servico', servicoAplicado.id), payload, {
            preserveScroll: true,
            onSuccess: () => fecharDialog(),
            onError: (errors) => setErro(extrairErro(errors)),
            onFinish: () => setSalvando(false),
        });
    }

    function voltarParaServico() {
        if (!confirm('Voltar a usar a tabela do serviço? A tabela própria desta empresa será removida.')) return;
        router.delete(route('admin.financeiro.faixas.empresa.remover', empresa.id), { preserveScroll: true });
    }

    // Fase 138 (D-01) — tabela própria do grupo.
    function salvarGrupo(payload) {
        setSalvando(true);
        setErro(null);
        router.post(route('admin.financeiro.faixas.grupo', empresa.company_group_id), payload, {
            preserveScroll: true,
            onSuccess: () => fecharDialog(),
            onError: (errors) => setErro(extrairErro(errors)),
            onFinish: () => setSalvando(false),
        });
    }

    function voltarParaEmpresaDoGrupo() {
        if (!confirm('Voltar a usar a tabela da empresa? A tabela própria deste grupo será removida.')) return;
        router.delete(route('admin.financeiro.faixas.grupo.remover', empresa.company_group_id), { preserveScroll: true });
    }

    const bloqueado = !!competenciaFechada;
    const btnNeutro = 'inline-flex items-center gap-1.5 text-[11px] font-semibold text-white/70 bg-white/[0.05] hover:bg-white/[0.09] border border-white/15 px-3 h-7 rounded-lg transition-colors disabled:opacity-40 disabled:cursor-not-allowed';
    // Accent reservado ao botão de cadastrar/editar tabela — item 5 da
    // lista fechada do Color Contract (UI-SPEC).
    const btnAccent  = 'inline-flex items-center gap-1.5 text-[11px] font-semibold text-ecf-yellow bg-ecf-yellow/10 hover:bg-ecf-yellow/20 border border-ecf-yellow/20 px-3 h-7 rounded-lg transition-colors disabled:opacity-40 disabled:cursor-not-allowed';

    return (
        <div id={`tabela-faixas-${empresa.id}`} className="rounded-lg border border-white/[0.06] overflow-hidden">
            <div className="px-3 py-1.5 bg-white/[0.02] border-b border-white/[0.04] flex items-center gap-1.5">
                <Table2 size={12} className="text-white/40 shrink-0" />
                <span className="text-[11px] uppercase tracking-wider text-white/40">Tabela de faixas aplicada</span>
            </div>

            <div className="p-3 space-y-3">
                {bloqueado && (
                    <p className="text-white/30 text-[11px] flex items-center gap-1.5">
                        <Lock size={11} className="shrink-0" />
                        Competência fechada — a tabela não pode ser alterada para este mês.
                    </p>
                )}

                {/* Fase 138 (D-01) — bloco exclusivo de linha de grupo, sempre
                    ANTES dos três estados abaixo. Não substitui os botões de
                    empresa/serviço que seguem — só acrescenta a camada de
                    grupo por cima. */}
                {empresa.tipo === 'grupo' && (
                    <div className="space-y-2 pb-3 border-b border-white/[0.06]">
                        {grupoAplicado ? (
                            <>
                                <span className="inline-block text-[11px] font-semibold px-2 py-0.5 rounded-full bg-ecf-yellow/10 text-ecf-yellow border border-ecf-yellow/20">
                                    Tabela deste grupo
                                </span>
                                <div className="overflow-x-auto rounded-lg border border-white/[0.06]">
                                    <table className="w-full text-[11px]">
                                        <thead>
                                            <tr className="text-white/30 border-b border-white/[0.06] bg-white/[0.02]">
                                                <th className="text-left py-1.5 px-2.5 font-semibold">Ordem</th>
                                                <th className="text-right py-1.5 px-2.5 font-semibold">Faturamento até</th>
                                                <th className="text-right py-1.5 px-2.5 font-semibold">Mensalidade</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {grupoAplicado.faixas.map(f => (
                                                <tr key={f.ordem} className="text-white/70 border-b border-white/[0.03] last:border-0">
                                                    <td className="py-1.5 px-2.5">{f.ordem}ª</td>
                                                    <td className="py-1.5 px-2.5 text-right font-mono">
                                                        {f.limite_superior != null ? fmtBRL(f.limite_superior) : 'Sem limite superior'}
                                                    </td>
                                                    <td className="py-1.5 px-2.5 text-right font-mono text-emerald-400/80">
                                                        {fmtValorFaixa(f.valor, f.valor_e_piso)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <button type="button" disabled={bloqueado} onClick={() => setDialog('editar-grupo')} className={btnAccent}>
                                        Substituir tabela do grupo
                                    </button>
                                    <button type="button" disabled={bloqueado} onClick={voltarParaEmpresaDoGrupo} className={btnNeutro}>
                                        Voltar a usar a tabela da empresa
                                    </button>
                                </div>
                            </>
                        ) : (
                            <>
                                <p className="text-white/60 text-[12px]">
                                    {empresa.tabela_herdada_de_nome
                                        ? <>Este grupo está usando a tabela da empresa <span className="font-semibold text-white/80">{empresa.tabela_herdada_de_nome}</span>.</>
                                        : 'Este grupo está usando a tabela de uma das empresas dele.'}
                                </p>
                                <p className="text-white/30 text-[11px]">
                                    Quem manda é a empresa do grupo que mais faturou no mês — se outra empresa passar
                                    na frente, a tabela muda junto.
                                </p>
                                <button type="button" disabled={bloqueado} onClick={() => setDialog('criar-grupo')} className={btnAccent}>
                                    Criar tabela do grupo
                                </button>
                            </>
                        )}
                    </div>
                )}

                {/* Estado 1 — herda a tabela do serviço */}
                {empresa.tabela_origem === 'servico' && (
                    <div className="space-y-2">
                        <p className="text-white/70 text-[13px] font-semibold">
                            Tabela do serviço {empresa.tabela_servico_nome}
                        </p>

                        {servicoAplicado && (
                            <div className="overflow-x-auto rounded-lg border border-white/[0.06]">
                                <table className="w-full text-[11px]">
                                    <thead>
                                        <tr className="text-white/30 border-b border-white/[0.06] bg-white/[0.02]">
                                            <th className="text-left py-1.5 px-2.5 font-semibold">Ordem</th>
                                            <th className="text-right py-1.5 px-2.5 font-semibold">Faturamento até</th>
                                            <th className="text-right py-1.5 px-2.5 font-semibold">Mensalidade</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {servicoAplicado.faixas.map(f => (
                                            <tr key={f.ordem} className="text-white/70 border-b border-white/[0.03] last:border-0">
                                                <td className="py-1.5 px-2.5">{f.ordem}ª</td>
                                                <td className="py-1.5 px-2.5 text-right font-mono">
                                                    {f.limite_superior != null ? fmtBRL(f.limite_superior) : 'Sem limite superior'}
                                                </td>
                                                <td className="py-1.5 px-2.5 text-right font-mono text-emerald-400/80">
                                                    {fmtValorFaixa(f.valor, f.valor_e_piso)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        <div className="flex flex-wrap gap-2">
                            <button type="button" disabled={bloqueado} onClick={() => setDialog('criar-propria')} className={btnNeutro}>
                                Criar tabela própria
                            </button>
                            {servicoAplicado && (
                                <button type="button" disabled={bloqueado} onClick={() => setDialog('editar-servico')} className={btnAccent}>
                                    Editar tabela do serviço
                                </button>
                            )}
                        </div>
                        {servicoAplicado && (
                            <p className="text-white/30 text-[11px]">
                                Editar aqui afeta todas as empresas que usam a tabela de {servicoAplicado.nome}.
                            </p>
                        )}
                    </div>
                )}

                {/* Estado 2 — exceção própria já cadastrada (D-13) */}
                {empresa.tabela_origem === 'propria' && (
                    <div className="space-y-2">
                        <span className="inline-block text-[11px] font-semibold px-2 py-0.5 rounded-full bg-white/[0.06] text-white/60 border border-white/10">
                            Tabela própria desta empresa
                        </span>
                        <p className="text-white/40 text-[11px]">
                            Substitui completamente a tabela do serviço {servicoInferido ? `"${servicoInferido}"` : 'vinculado a este contrato'}.
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <button type="button" disabled={bloqueado} onClick={() => setDialog('editar-propria')} className={btnAccent}>
                                Substituir tabela própria
                            </button>
                            <button type="button" disabled={bloqueado} onClick={voltarParaServico} className={btnNeutro}>
                                Voltar a usar a tabela do serviço
                            </button>
                        </div>
                    </div>
                )}

                {/* Estado 3 — nem exceção própria, nem serviço candidato com tabela */}
                {!empresa.tabela_origem && (
                    <div className="space-y-2">
                        <p className="text-amber-400 text-[13px] font-semibold">Tabela de faixas: A DEFINIR</p>
                        <p className="text-white/40 text-[11px]">
                            Cadastre a tabela de faturamento desta empresa para ela entrar no fechamento.
                        </p>
                        <button type="button" disabled={bloqueado} onClick={() => setDialog('criar-propria')} className={btnAccent}>
                            Cadastrar tabela de faixas
                        </button>
                    </div>
                )}
            </div>

            <FaixaFormDialog
                open={dialog === 'criar-propria'}
                title={`Tabela própria — ${empresa.name}`}
                aviso="Substitui completamente a tabela do serviço para esta empresa."
                faixasIniciais={servicoAplicado?.faixas ?? []}
                onClose={fecharDialog}
                onSalvar={salvarEmpresa}
                salvando={salvando}
                erro={erro}
            />

            <FaixaFormDialog
                open={dialog === 'editar-propria'}
                title={`Substituir tabela própria — ${empresa.name}`}
                aviso="Os valores atuais não são carregados aqui — preencha a tabela completa antes de salvar (substitui tudo, D-13)."
                faixasIniciais={[]}
                onClose={fecharDialog}
                onSalvar={salvarEmpresa}
                salvando={salvando}
                erro={erro}
            />

            {servicoAplicado && (
                <FaixaFormDialog
                    open={dialog === 'editar-servico'}
                    title={`Editar tabela do serviço ${servicoAplicado.nome}`}
                    aviso={`Afeta todas as empresas que usam a tabela de ${servicoAplicado.nome}.`}
                    faixasIniciais={servicoAplicado.faixas}
                    onClose={fecharDialog}
                    onSalvar={salvarServico}
                    salvando={salvando}
                    erro={erro}
                />
            )}

            {/* Fase 138 (D-01) — tabela própria do grupo. "Criar" parte da
                tabela aplicada hoje (a do serviço, quando é o caso — mesma
                limitação de dado do "criar-propria": quando a tabela herdada
                é própria da empresa que mais faturou no mês, o backend não
                expõe as linhas dela, então o form abre em branco). */}
            <FaixaFormDialog
                open={dialog === 'criar-grupo'}
                title={`Tabela do grupo — ${empresa.name}`}
                aviso="Substitui completamente a tabela da empresa para todo o grupo."
                faixasIniciais={servicoAplicado?.faixas ?? []}
                onClose={fecharDialog}
                onSalvar={salvarGrupo}
                salvando={salvando}
                erro={erro}
            />

            <FaixaFormDialog
                open={dialog === 'editar-grupo'}
                title={`Substituir tabela do grupo — ${empresa.name}`}
                aviso="Os valores atuais não são carregados aqui — preencha a tabela completa antes de salvar (substitui tudo)."
                faixasIniciais={[]}
                onClose={fecharDialog}
                onSalvar={salvarGrupo}
                salvando={salvando}
                erro={erro}
            />
        </div>
    );
}
