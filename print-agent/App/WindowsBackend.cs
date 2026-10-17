using System.Drawing.Printing;

namespace CentreCivic.PrintAgent.App;

/// <summary>Prints on this Windows computer: USB/installed printers and network printers.</summary>
internal sealed class WindowsBackend : IPrintBackend
{
    public IReadOnlyList<string> InstalledPrinters()
    {
        try
        {
            return PrinterSettings.InstalledPrinters.Cast<string>().OrderBy(n => n).ToList();
        }
        catch (Exception e)
        {
            Log.Write("No s'ha pogut llegir la llista d'impressores de Windows: " + e.Message);
            return [];
        }
    }

    public void Print(PrintJob job)
    {
        var printer = job.Printer;

        if (printer.Target == "network")
        {
            if (string.IsNullOrWhiteSpace(printer.Ip) || job.Escpos is null)
            {
                throw new InvalidOperationException($"A «{printer.Name}» li falta la IP a Gestió → Impressió.");
            }

            NetworkPrinter.Send(printer.Ip, printer.Port, Convert.FromBase64String(job.Escpos));
            return;
        }

        var name = string.IsNullOrWhiteSpace(printer.SystemName) ? printer.Name : printer.SystemName;
        var installed = InstalledPrinters();

        if (!installed.Contains(name, StringComparer.OrdinalIgnoreCase))
        {
            throw new InvalidOperationException($"No hi ha cap impressora «{name}» instal·lada en aquest ordinador. Tria-la bé a Gestió → Impressió (n'hi ha: {string.Join(", ", installed)}).");
        }

        if (job.Escpos is not null)
        {
            RawPrinter.Send(name, Convert.FromBase64String(job.Escpos), job.Title);
        }
        else if (job.Document is not null)
        {
            DriverPrinter.Print(name, job.Document, job.Title);
        }
        else
        {
            throw new InvalidOperationException("El servidor ha enviat un document buit.");
        }
    }
}
