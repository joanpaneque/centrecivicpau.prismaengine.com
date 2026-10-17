using System.Text;

namespace CentreCivic.PrintAgent.App;

/// <summary>
/// A test ticket printed from this computer alone (without the server), to check that a
/// printer works and which format suits it.
/// </summary>
internal static class TestTicket
{
    private const string Accents = "àèéíïòóúüç ÀÈÉÍÏÒÓÚÜÇ ñÑ €";

    public static byte[] Escpos()
    {
        var cp858 = Encoding.GetEncoding(858);
        var bytes = new List<byte>();
        void Raw(params byte[] b) => bytes.AddRange(b);
        void Text(string s) => bytes.AddRange(cp858.GetBytes(s + "\n"));

        Raw(0x1B, 0x40, 0x1B, 0x74, 19);                 // init, code page 858
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
