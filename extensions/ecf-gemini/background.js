let geminiWindowId = null;
let sistemaWindowId = null;
let larguraGemini = 480;

chrome.action.onClicked.addListener((tab) => alternarGemini(tab));

chrome.runtime.onMessage.addListener((message, sender) => {
  if (message.action === 'openPanel') alternarGemini(sender.tab);
});

function alternarGemini(tab) {
  if (geminiWindowId !== null) {
    chrome.windows.get(geminiWindowId, {}, (win) => {
      if (chrome.runtime.lastError || !win) {
        resetarEstado();
        abrirGemini(tab);
      } else {
        chrome.windows.update(geminiWindowId, { focused: true });
      }
    });
    return;
  }
  abrirGemini(tab);
}

function abrirGemini(tab) {
  chrome.windows.getCurrent({}, (win) => {
    sistemaWindowId = win.id;
    larguraGemini = Math.round(win.width * 0.40);
    const larguraSistema = win.width - larguraGemini;

    // Encolhe o sistema exatamente para a esquerda
    chrome.windows.update(win.id, { width: larguraSistema }, () => {
      // Abre o Gemini colado pixel a pixel na borda direita
      chrome.windows.create({
        url:     'https://gemini.google.com',
        type:    'popup',
        width:   larguraGemini,
        height:  win.height,
        left:    win.left + larguraSistema,
        top:     win.top,
        focused: false, // mantém foco no sistema
      }, (geminiWin) => {
        geminiWindowId = geminiWin.id;
      });
    });
  });
}

// Gemini segue o sistema quando a janela é movida ou redimensionada
chrome.windows.onBoundsChanged.addListener((win) => {
  if (win.id !== sistemaWindowId || geminiWindowId === null) return;

  chrome.windows.update(geminiWindowId, {
    left:   win.left + win.width,
    top:    win.top,
    height: win.height,
  });
});

// Ao fechar o Gemini, restaura o sistema ao tamanho completo
chrome.windows.onRemoved.addListener((windowId) => {
  if (windowId !== geminiWindowId) return;

  if (sistemaWindowId !== null) {
    chrome.windows.get(sistemaWindowId, {}, (win) => {
      if (!chrome.runtime.lastError && win) {
        chrome.windows.update(sistemaWindowId, {
          width: win.width + larguraGemini,
        });
      }
    });
  }
  resetarEstado();
});

function resetarEstado() {
  geminiWindowId = null;
  sistemaWindowId = null;
}
