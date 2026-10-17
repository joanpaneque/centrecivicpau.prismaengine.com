namespace CentreCivic.PrintAgent;

/// <summary>
/// A plain text log next to the configuration (registre.log), kept under 2 MB.
/// </summary>
public static class Log
{
    private static readonly object Gate = new();

    public static string FilePath => Path.Combine(AgentConfig.Folder, "registre.log");

    public static event Action<string>? Written;

    public static void Write(string message)
    {
        var line = $"{DateTime.Now:yyyy-MM-dd HH:mm:ss}  {message}";

        lock (Gate)
        {
            try
            {
                Directory.CreateDirectory(AgentConfig.Folder);
                var file = new FileInfo(FilePath);

                if (file.Exists && file.Length > 2_000_000)
                {
                    File.Move(FilePath, FilePath + ".old", true);
                }

                File.AppendAllText(FilePath, line + Environment.NewLine);
            }
            catch
            {
                // Logging must never stop printing.
            }
        }

        Written?.Invoke(line);
    }
}
