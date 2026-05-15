// Injeta elemento ponte para que o React detecte que a extensão está instalada
const bridge = document.createElement('div');
bridge.id = '__ecf_gemini_bridge';
bridge.style.display = 'none';
document.body.appendChild(bridge);

// Escuta o evento disparado pelo botão "Abrir Gemini" no React
window.addEventListener('ecf:open-gemini', () => {
  chrome.runtime.sendMessage({ action: 'openPanel' });
});
