<?php

return [
    /*
     * Caminho do executável do Chrome/Chromium para geração de PDF via Browsershot.
     * Configurar no .env de cada ambiente:
     *   Windows (local): C:/Program Files/Google/Chrome/Application/chrome.exe
     *   Linux (VPS):     /usr/bin/google-chrome
     */
    'chrome_binary' => env('CHROME_BINARY_PATH', '/usr/bin/google-chrome'),
];
