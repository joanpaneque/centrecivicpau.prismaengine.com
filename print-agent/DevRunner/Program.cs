using System.Text;
using CentreCivic.PrintAgent;

// Development harness: the real connection, realtime and job loop, with a printer that writes
// what it would print to the console (network printers are really sent to IP:port).
if (args.Length < 1 || !AgentConfig.TryParseKey(args[0], out var server, out var token, out var error))
{
    Console.Error.WriteLine("Ús: DevRunner <clau de connexió>");
    return 1;
}

AgentConfig.Folder = Path.Combine(Path.GetTempPath(), "centre-civic-impressio-dev");
Log.Written += Console.WriteLine;

using var runner = new AgentRunner(new AgentConfig { ServerUrl = server, Token = token }, new ConsoleBackend());
runner.StatusChanged += () => Console.WriteLine($"[estat] {runner.State} · temps real: {runner.Realtime} · {runner.AgentName} · impressores: {string.Join(", ", runner.Printers.Select(p => p.Name))}");

using var cts = new CancellationTokenSource();
Console.CancelKeyPress += (_, e) => { e.Cancel = true; cts.Cancel(); };

if (args.Length > 1 && int.TryParse(args[1], out var seconds))
{
    cts.CancelAfter(TimeSpan.FromSeconds(seconds));
}

await runner.RunAsync(cts.Token);
return 0;

sealed class ConsoleBackend : IPrintBackend
{
    public IReadOnlyList<string> InstalledPrinters() => ["POS-80C", "Microsoft Print to PDF"];

    public void Print(PrintJob job)
    {
        Encoding.RegisterProvider(CodePagesEncodingProvider.Instance);

        if (job.Escpos is not null)
        {
            var bytes = Convert.FromBase64String(job.Escpos);

            if (job.Printer.Target == "network")
            {
                NetworkPrinter.Send(job.Printer.Ip!, job.Printer.Port, bytes);
                return;
            }

            if (job.Printer.SystemName == "FALLA")
            {
                throw new IOException("La impressora està apagada (simulat).");
            }

            var text = Encoding.GetEncoding(858).GetString(bytes.Select(b => b < 0x20 && b != 0x0A ? (byte)'·' : b).ToArray());
            Console.WriteLine($"----- ESC/POS a «{job.Printer.SystemName}» ({bytes.Length} bytes) -----\n{text}\n-----");
        }
        else
        {
            Console.WriteLine($"----- Driver a «{job.Printer.SystemName}» -----");

            foreach (var line in job.Document!.Lines)
            {
                Console.WriteLine(line.Type switch
                {
                    "row" => $"{line.Left} … {line.Right}",
                    "qr" => $"[QR {line.Matrix?.Count}x{line.Matrix?.Count}]",
                    "image" => $"[imatge {line.Png?.Length} b64]",
                    _ => line.Text ?? $"[{line.Type}]",
                });
            }
        }
    }
}
