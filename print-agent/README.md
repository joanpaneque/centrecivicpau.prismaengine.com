# Programa d'impressió per a Windows

Imprimeix directament (sense diàlogs ni PDF) els tiquets i les comandes del TPV a les impressores del bar. El servidor és a Internet i no pot arribar a la xarxa del bar; aquest programa, instal·lat en un PC del bar, **es connecta ell** al servidor:

1. `POST /api/print-agent/hello` en arrencar, cada 5 minuts i en canviar res: informa de les impressores de Windows del PC i envia les impressores configurades **al programa** (`definitions`: nom, impressora de Windows o IP, format, paper). El servidor les crea, actualitza o esborra (`printers.agent_key`) i retorna què n'ha decidit la web (activa, tiquets de caixa), la darrera versió i on escoltar. A la web només es tria què imprimeix cada una (destins, tiquets, activa).

   La primera vegada, el programa no envia la llista: adopta les impressores que ja hi havia al servidor per a aquell ordinador i després l'envia (així una llista buida mai esborra res).
2. Escolta el seu canal públic (nom aleatori) al Reverb (`wss://…/app/{key}`, protocol Pusher). L'esdeveniment `jobs` és només un avís.
3. `POST /api/print-agent/jobs` (en rebre l'avís, i cada 3 s si el websocket ha caigut o 20 s si no): reclama els treballs pendents. Els d'ESC/POS ja venen codificats pel servidor (`PrintDocumentEncoder`); els de «Driver de Windows» venen com a línies per dibuixar.
4. Imprimeix (RAW per la cua de Windows, TCP a IP:9100, o GDI pel driver) i confirma amb `POST /api/print-agent/jobs/{uuid}/ack`.

S'autentica amb la clau que es genera a Gestió → Impressió (a la base de dades només n'hi ha el hash).

## Projectes

- `Core/` (net8.0): configuració, API, websocket, bucle de treballs, impressores de xarxa. Multiplataforma.
- `App/` (net8.0-windows, WinForms): finestra, icona de la safata, instal·lador (per a l'usuari, sense administrador), impressió RAW i per driver, actualització automàtica.
- `DevRunner/` (net8.0): executa el `Core` en qualsevol sistema contra un servidor, «imprimint» a la consola. Per provar sense Windows:

```bash
cd print-agent/DevRunner
dotnet run "http://localhost/api/print-agent/CLAU" 60
```

## Compilar

Funciona també des de Linux (cal el SDK de .NET 8):

```bash
dotnet publish print-agent/App -c Release -o print-agent/dist -p:Version=1.0.5
```

Surt un únic `CentreCivicImpressio.exe` autocontingut (~70 MB, no cal instal·lar .NET).

El desplegament (`.github/workflows/build.yml`) el compila a cada push amb la versió `1.0.<commits que toquen print-agent/>`, el puja al servidor i el deixa a `storage/app/private/print-agent/` (l'`.exe` i `version.txt`). D'allà el descarrega Gestió → Impressió. El programa mostra a «Actualitzacions» si n'hi ha una de nova i s'actualitza amb «Actualitza ara» (o sol, amb l'opció marcada, que és la de per defecte). Tornar a descarregar-lo i obrir-lo també l'actualitza (o el reinstal·la) conservant la configuració i les impressores.
