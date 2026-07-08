import { useState, useEffect, useCallback } from 'react';
import { Sun, Moon } from 'lucide-react';

/**
 * Alterna entre modo escuro (padrão) e claro.
 * A preferência é gravada em localStorage ('ecf-theme') e aplicada no <html>
 * pela classe `.light` — o script anti-flash em app.blade.php replica isso no
 * carregamento inicial para evitar piscada de tema.
 */
export default function ThemeToggle({ className = '' }) {
    // Estado inicial reflete a classe já aplicada pelo script inline do <head>.
    const [isLight, setIsLight] = useState(
        () => typeof document !== 'undefined' && document.documentElement.classList.contains('light'),
    );

    // Mantém o <html> e o localStorage em sincronia com o estado.
    useEffect(() => {
        const root = document.documentElement;
        root.classList.toggle('light', isLight);
        try {
            localStorage.setItem('ecf-theme', isLight ? 'light' : 'dark');
        } catch (e) {
            // localStorage indisponível (modo privado) — ignora, tema fica só na sessão.
        }
    }, [isLight]);

    const toggle = useCallback(() => setIsLight((v) => !v), []);

    return (
        <button
            type="button"
            onClick={toggle}
            aria-label={isLight ? 'Ativar modo escuro' : 'Ativar modo claro'}
            title={isLight ? 'Modo escuro' : 'Modo claro'}
            className={
                'text-white/30 hover:text-white/60 p-1.5 rounded-lg hover:bg-white/[0.05] transition-colors ' +
                className
            }
        >
            {isLight ? <Moon size={16} /> : <Sun size={16} />}
        </button>
    );
}
