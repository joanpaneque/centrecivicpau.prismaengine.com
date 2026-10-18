using System.Globalization;
using System.Text;

namespace CentreCivic.PrintAgent.App;

/// <summary>
/// A test ticket printed from this computer alone (without the server), to check that a
/// printer works and which format suits it.
/// </summary>
internal static class TestTicket
{
    private const string Accents = "àèéíïòóúüç ÀÈÉÍÏÒÓÚÜÇ ñÑ €";

    /// <summary>Print the test on a printer as set up in the program.</summary>
    public static void Print(LocalPrinter printer)
    {
        const string title = "Prova d'impressió";

        if (printer.Target == "network")
        {
            NetworkPrinter.Send(printer.Ip!, printer.Port, Escpos(printer.Codepage));
        }
        else if (printer.Output == "driver")
        {
            DriverPrinter.Print(printer.SystemName!, Driver(), title);
        }
        else
        {
            RawPrinter.Send(printer.SystemName!, Escpos(printer.Codepage), title);
        }
    }

    public static byte[] Escpos(string codepage = "cp858")
    {
        // The same code pages as the server (PrintDocumentEncoder).
        var (table, encoding) = codepage switch
        {
            "cp1252" => ((byte)16, Encoding.GetEncoding(1252)),
            "ascii" => ((byte)0, Encoding.ASCII),
            _ => ((byte)19, Encoding.GetEncoding(858)),
        };
        var bytes = new List<byte>();
        void Raw(params byte[] b) => bytes.AddRange(b);
        void Text(string s) => bytes.AddRange(encoding.GetBytes((codepage == "ascii" ? Plain(s) : s) + "\n"));

        Raw(0x1B, 0x40, 0x1B, 0x74, table);              // init, code page
        Raw(0x1B, 0x61, 1, 0x1B, 0x45, 1, 0x1D, 0x21, 0x11); // centre, bold, double size
        Text("PROVA");
        Raw(0x1D, 0x21, 0x00, 0x1B, 0x45, 0);
        Text("Centre Cívic Pau");
        Text(DateTime.Now.ToString("dd/MM/yyyy HH:mm:ss"));
        Raw(0x1B, 0x61, 0);
        Text(new string('-', 42));
        Text("Si llegeixes bé aquests accents,");
        Text("el format ESC/POS és el bo:");
        Text(Accents);
        Raw(0x1B, 0x64, 4, 0x1D, 0x56, 0x42, 3);         // feed, cut

        return [.. bytes];
    }

    private static string Plain(string text)
    {
        var letters = text.Replace("€", "EUR").Normalize(NormalizationForm.FormD).Where(c => CharUnicodeInfo.GetUnicodeCategory(c) != UnicodeCategory.NonSpacingMark);

        return new string([.. letters]);
    }

    public static DriverDocument Driver() => new()
    {
        Width = 42,
        Lines =
        [
            new() { Type = "text", Text = "PROVA", Align = "center", Bold = true, Size = 2 },
            new() { Type = "text", Text = "Centre Cívic Pau", Align = "center" },
            new() { Type = "text", Text = DateTime.Now.ToString("dd/MM/yyyy HH:mm:ss"), Align = "center" },
            new() { Type = "divider" },
            new() { Type = "text", Text = "Impressió a través del driver de Windows:" },
            new() { Type = "text", Text = Accents },
            new() { Type = "row", Left = "Esquerra", Right = "Dreta" },
            new() { Type = "feed", Lines = 2 },
        ],
    };
}
