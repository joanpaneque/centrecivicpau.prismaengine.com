using System.Text.Json;
using System.Text.Json.Serialization;

namespace CentreCivic.PrintAgent;

public sealed class HelloResponse
{
    public AgentInfo Agent { get; set; } = new();
    public string? LatestVersion { get; set; }
    public RealtimeInfo? Realtime { get; set; }
    public List<PrinterConfig> Printers { get; set; } = [];
}

public sealed class AgentInfo
{
    public int Id { get; set; }
    public string Name { get; set; } = "";
}

/// <summary>The public Reverb (Pusher protocol) address and this program's channel.</summary>
public sealed record RealtimeInfo
{
    public string Key { get; init; } = "";
    public string Host { get; init; } = "";
    public int Port { get; init; }
    public string Scheme { get; init; } = "https";
    public string Channel { get; init; } = "";
    public string Event { get; init; } = "jobs";
}

/// <summary>A printer as set up in this program (sent to the server in every hello).</summary>
public sealed class LocalPrinter
{
    public string Key { get; set; } = Guid.NewGuid().ToString();
    public string Name { get; set; } = "";

    /// <summary>windows (a printer installed in Windows) or network (IP:port on the bar's LAN).</summary>
    public string Target { get; set; } = "windows";

    public string? SystemName { get; set; }
    public string? Ip { get; set; }
    public int Port { get; set; } = 9100;

    /// <summary>escpos (raw bytes, thermal printers) or driver (drawn through the Windows driver).</summary>
    public string Output { get; set; } = "escpos";

    public string Codepage { get; set; } = "cp858";
    public int PaperWidth { get; set; } = 48;

    public string Where => Target == "network" ? $"{Ip}:{Port}" : SystemName ?? "";

    public static LocalPrinter From(PrinterConfig server) => new()
    {
        Key = server.Key ?? Guid.NewGuid().ToString(),
        Name = server.Name,
        Target = server.Target,
        SystemName = server.SystemName,
        Ip = server.Ip,
        Port = server.Port,
        Output = server.Output,
        Codepage = server.Codepage ?? "cp858",
        PaperWidth = server.PaperWidth,
    };
}

public sealed class PrinterConfig
{
    public int Id { get; set; }

    /// <summary>Its <see cref="LocalPrinter.Key"/> (null on old servers).</summary>
    public string? Key { get; set; }

    public string Name { get; set; } = "";
    public bool Active { get; set; } = true;

    /// <summary>windows (a printer installed in Windows) or network (IP:port on the bar's LAN).</summary>
    public string Target { get; set; } = "windows";

    public string? SystemName { get; set; }
    public string? Ip { get; set; }
    public int Port { get; set; } = 9100;

    /// <summary>escpos (raw bytes, thermal printers) or driver (drawn through the Windows driver).</summary>
    public string Output { get; set; } = "escpos";

    public string? Codepage { get; set; }
    public int PaperWidth { get; set; } = 48;

    /// <summary>Chosen on the web: prints the cashier tickets.</summary>
    public bool IsTicketPrinter { get; set; }

    public string Where => Target == "network" ? $"{Ip}:{Port}" : SystemName ?? Name;
}

public sealed class JobsResponse
{
    public List<PrintJob> Jobs { get; set; } = [];
}

public sealed class PrintJob
{
    public string Uuid { get; set; } = "";
    public string Kind { get; set; } = "";
    public string Title { get; set; } = "";
    public PrinterConfig Printer { get; set; } = new();

    /// <summary>Base64 ESC/POS bytes, already encoded by the server (output = escpos).</summary>
    public string? Escpos { get; set; }

    /// <summary>Lines to draw (output = driver).</summary>
    public DriverDocument? Document { get; set; }
}

public sealed class DriverDocument
{
    public int Width { get; set; } = 48;
    public List<DriverLine> Lines { get; set; } = [];
}

public sealed class DriverLine
{
    public string Type { get; set; } = "text";
    public string? Text { get; set; }
    public string? Align { get; set; }
    public bool Bold { get; set; }
    public int Size { get; set; } = 1;
    public bool Invert { get; set; }
    public string? Left { get; set; }
    public string? Right { get; set; }
    public string? Char { get; set; }
    public int Lines { get; set; } = 1;
    public string? Caption { get; set; }
    public List<string>? Matrix { get; set; }
    public string? Png { get; set; }
}

public static class JsonDefaults
{
    public static readonly JsonSerializerOptions Options = new(JsonSerializerDefaults.Web)
    {
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
    };
}
