import { STATUS_META } from './statusMeta';

/**
 * StatusBadge — selo de status com a paleta compartilhada (No alvo / Em progresso
 * / Não / Problema). Usado no ranking do Cockpit e no drawer de empresas.
 */
export default function StatusBadge({ status }) {
    const meta = STATUS_META[status] ?? { cor: '#94a3b8', label: status };
    return (
        <span
            className="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold"
            style={{ background: `${meta.cor}22`, color: meta.cor }}
        >
            <span className="h-1.5 w-1.5 rounded-full" style={{ background: meta.cor }} />
            {meta.label}
        </span>
    );
}
