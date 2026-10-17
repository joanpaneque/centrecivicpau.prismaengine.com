using System.Diagnostics;

namespace CentreCivic.PrintAgent.App;

/// <summary>
/// Keeps the installed program up to date with the one published on the server: downloads
/// it, swaps the file (a running .exe can be renamed, not overwritten) and restarts.
/// </summary>
internal static class Updater
{
    private static bool busy;

    private static string NewPath => Installer.ExePath + ".new";

    private static string OldPath => Installer.ExePath + ".old";

    public static bool IsNewer(string? latest) =>
        Version.TryParse(latest, out var available) && Version.TryParse(AgentRunner.Version, out var current) && available > current;

    /// <summary>Remove what the previous version left behind.</summary>
    public static void CleanUp()
    {
        foreach (var path in new[] { OldPath, NewPath })
        {
            for (var attempt = 0; attempt < 10 && File.Exists(path); attempt++)
            {
                try
                {
                    File.Delete(path);
                }
                catch (IOException)
                {
                    Thread.Sleep(500);
                }
                catch (UnauthorizedAccessException)
                {
                    Thread.Sleep(500);
                }
            }
        }

        if (Installer.IsRunningInstalled)
        {
            try
            {
                Installer.Register();
            }
            catch
            {
                // Only the version shown in Settings → Apps.
            }
        }
    }

    /// <summary>Returns true when the new version has been started and this one must exit.</summary>
    public static async Task<bool> TryUpdateAsync(AgentRunner runner, string version, CancellationToken ct)
    {
        if (busy || !Installer.IsRunningInstalled)
        {
            return false;
        }

        busy = true;

        try
        {
            Log.Write($"Hi ha una versió nova ({version}). Descarregant…");
            await runner.DownloadLatestAsync(NewPath, ct);

            if (new FileInfo(NewPath).Length < 5_000_000)
            {
                throw new InvalidDataException("El fitxer descarregat és massa petit.");
            }

            File.Move(Installer.ExePath, OldPath, true);
            File.Move(NewPath, Installer.ExePath);
            Log.Write($"Actualitzat a la versió {version}. Reiniciant…");

            Program.ReleaseInstance();
            Process.Start(new ProcessStartInfo(Installer.ExePath, "--background --updated") { UseShellExecute = false, WorkingDirectory = Installer.InstallDir });

            return true;
        }
        catch (Exception e) when (e is not OperationCanceledException)
        {
            Log.Write("No s'ha pogut actualitzar: " + e.Message);

            // Put the working program back if the swap was half done.
            if (!File.Exists(Installer.ExePath) && File.Exists(OldPath))
            {
                File.Move(OldPath, Installer.ExePath);
            }

            return false;
        }
        finally
        {
            busy = false;
        }
    }
}
