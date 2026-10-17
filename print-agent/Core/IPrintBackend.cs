namespace CentreCivic.PrintAgent;

/// <summary>How this computer prints: the Windows one in the app, a fake one in DevRunner.</summary>
public interface IPrintBackend
{
    /// <summary>Printers installed on this computer (Windows printer names).</summary>
    IReadOnlyList<string> InstalledPrinters();

    /// <summary>Print the job or throw with a message a person understands.</summary>
    void Print(PrintJob job);
}
