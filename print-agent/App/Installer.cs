using System.Diagnostics;
using Microsoft.Win32;

namespace CentreCivic.PrintAgent.App;

/// <summary>
/// Installs for the current Windows user only (no administrator needed): the program in
/// %LOCALAPPDATA%\Programs, a Start menu and desktop shortcut, start with Windows, and an
/// entry in Settings → Apps to uninstall it.
/// </summary>
internal static class Installer
{
    public const string AppName = "Impressió Centre Cívic";
    private const string RunKey = @"Software\Microsoft\Windows\CurrentVersion\Run";
    private const string RunValue = "CentreCivicImpressio";
    private const string UninstallKey = @"Software\Microsoft\Windows\CurrentVersion\Uninstall\CentreCivicImpressio";

    public static string InstallDir => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Programs", "CentreCivicImpressio");

    public static string ExePath => Path.Combine(InstallDir, "CentreCivicImpressio.exe");

    private static string StartMenuShortcut => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.Programs), AppName + ".lnk");

    private static string DesktopShortcut => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory), AppName + ".lnk");

    public static bool IsRunningInstalled => string.Equals(Path.GetFullPath(Environment.ProcessPath ?? ""), Path.GetFullPath(ExePath), StringComparison.OrdinalIgnoreCase);

    public static bool IsInstalled => File.Exists(ExePath);

    public static string? InstalledVersion => IsInstalled ? FileVersionInfo.GetVersionInfo(ExePath).ProductVersion?.Split('+')[0] : null;

    /// <summary>Copy this program into place (closing the running copy first) and start it.</summary>
    public static void Install()
    {
        Directory.CreateDirectory(InstallDir);

        if (!SingleInstance.CloseRunning(TimeSpan.FromSeconds(15)))
        {
            throw new IOException("El programa que ja està instal·lat no s'ha pogut tancar. Tanca'l des de la icona del costat del rellotge i torna-ho a provar.");
        }

        CopyWithRetry(Environment.ProcessPath!, ExePath);
        CreateShortcut(StartMenuShortcut);
        CreateShortcut(DesktopShortcut);

        var config = AgentConfig.Load();
        SetAutostart(config.StartWithWindows);
        Register();
        Log.Write($"Instal·lat a {InstallDir} (versió {AgentRunner.Version}).");

        Process.Start(new ProcessStartInfo(ExePath) { UseShellExecute = true, WorkingDirectory = InstallDir });
    }

    public static void Uninstall()
    {
        if (MessageBox.Show(
                "Vols desinstal·lar el programa d'impressió?\n\nEls tiquets i les comandes deixaran de sortir per les impressores d'aquest ordinador.",
                AppName, MessageBoxButtons.YesNo, MessageBoxIcon.Question) != DialogResult.Yes)
        {
            return;
        }

        SingleInstance.CloseRunning(TimeSpan.FromSeconds(15));
        SetAutostart(false);
        Registry.CurrentUser.DeleteSubKeyTree(UninstallKey, false);

        foreach (var shortcut in new[] { StartMenuShortcut, DesktopShortcut })
        {
            try
            {
                File.Delete(shortcut);
            }
            catch
            {
                // Already gone.
            }
        }

        try
        {
            Directory.Delete(AgentConfig.Folder, true);
        }
        catch
        {
            // Keep going: the important part is that it no longer runs.
        }

        // This very program may be running from the folder: delete it a moment after closing.
        Process.Start(new ProcessStartInfo("cmd.exe", $"/c timeout /t 3 /nobreak > nul & rmdir /s /q \"{InstallDir}\"")
        {
            CreateNoWindow = true,
            UseShellExecute = false,
            WindowStyle = ProcessWindowStyle.Hidden,
        });

        MessageBox.Show("S'ha desinstal·lat el programa d'impressió.", AppName, MessageBoxButtons.OK, MessageBoxIcon.Information);
    }

    public static void SetAutostart(bool enabled)
    {
        using var key = Registry.CurrentUser.CreateSubKey(RunKey);

        if (enabled)
        {
            key.SetValue(RunValue, $"\"{ExePath}\" --background");
        }
        else
        {
            key.DeleteValue(RunValue, false);
        }
    }

    public static bool Autostart
    {
        get
        {
            using var key = Registry.CurrentUser.OpenSubKey(RunKey);
            return key?.GetValue(RunValue) is not null;
        }
    }

    /// <summary>The entry in Settings → Apps (also updated after an automatic update).</summary>
    public static void Register()
    {
        using var key = Registry.CurrentUser.CreateSubKey(UninstallKey);
        key.SetValue("DisplayName", AppName);
        key.SetValue("DisplayVersion", AgentRunner.Version);
        key.SetValue("Publisher", "Centre Cívic Pau");
        key.SetValue("DisplayIcon", ExePath);
        key.SetValue("InstallLocation", InstallDir);
        key.SetValue("UninstallString", $"\"{ExePath}\" --uninstall");
        key.SetValue("NoModify", 1, RegistryValueKind.DWord);
        key.SetValue("NoRepair", 1, RegistryValueKind.DWord);

        if (File.Exists(ExePath))
        {
            key.SetValue("EstimatedSize", (int)(new FileInfo(ExePath).Length / 1024), RegistryValueKind.DWord);
        }
    }

    private static void CreateShortcut(string path)
    {
        try
        {
            var type = Type.GetTypeFromProgID("WScript.Shell") ?? throw new InvalidOperationException("WScript.Shell");
            dynamic shell = Activator.CreateInstance(type)!;
            var link = shell.CreateShortcut(path);
            link.TargetPath = ExePath;
            link.WorkingDirectory = InstallDir;
            link.IconLocation = ExePath + ",0";
            link.Description = "Imprimeix directament els tiquets i les comandes del TPV";
            link.Save();
        }
        catch (Exception e)
        {
            Log.Write($"No s'ha pogut crear l'accés directe {path}: {e.Message}");
        }
    }

    private static void CopyWithRetry(string from, string to)
    {
        for (var attempt = 1; ; attempt++)
        {
            try
            {
                File.Copy(from, to, true);
                return;
            }
            catch (IOException) when (attempt < 10)
            {
                Thread.Sleep(500);
            }
        }
    }
}
