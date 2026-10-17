using System.Text.Json;

namespace CentreCivic.PrintAgent;

/// <summary>
/// Where the program connects and with which key. Saved in the user's AppData; the key
/// comes from Gestió → Impressió as one line: https://server/api/print-agent/KEY.
/// </summary>
public sealed class AgentConfig
{
    public string ServerUrl { get; set; } = "";
    public string Token { get; set; } = "";
    public bool StartWithWindows { get; set; } = true;

    public bool IsComplete => ServerUrl != "" && Token != "";

    public static string Folder { get; set; } = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "CentreCivicImpressio");

    private static string FilePath => Path.Combine(Folder, "config.json");

    private static readonly JsonSerializerOptions Json = new() { WriteIndented = true };

    public static AgentConfig Load()
    {
        try
        {
            if (File.Exists(FilePath))
            {
                return JsonSerializer.Deserialize<AgentConfig>(File.ReadAllText(FilePath)) ?? new AgentConfig();
            }
        }
        catch (Exception e)
        {
            Log.Write("No s'ha pogut llegir la configuració: " + e.Message);
        }

        return new AgentConfig();
    }

    public void Save()
    {
        Directory.CreateDirectory(Folder);
        File.WriteAllText(FilePath, JsonSerializer.Serialize(this, Json));
    }

    /// <summary>
    /// Reads a connection key. Returns false (and why) when it is not one.
    /// </summary>
    public static bool TryParseKey(string text, out string serverUrl, out string token, out string error)
    {
        serverUrl = "";
        token = "";
        error = "";
        text = text.Trim();
        const string marker = "/api/print-agent/";
        var index = text.IndexOf(marker, StringComparison.OrdinalIgnoreCase);

        if (index <= 0 || !Uri.TryCreate(text[..index], UriKind.Absolute, out var server) || (server.Scheme != "https" && server.Scheme != "http"))
        {
            error = "Això no és una clau de connexió. Copia-la sencera de Gestió → Impressió (comença per https://).";
            return false;
        }

        token = text[(index + marker.Length)..].Trim().TrimEnd('/');

        if (token.Length < 20 || token.Any(char.IsWhiteSpace))
        {
            error = "La clau sembla incompleta. Torna-la a copiar sencera.";
            return false;
        }

        serverUrl = server.ToString().TrimEnd('/');
        return true;
    }
}
