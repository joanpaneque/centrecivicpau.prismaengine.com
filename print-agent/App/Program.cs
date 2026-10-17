using System.Text;

namespace CentreCivic.PrintAgent.App;

internal static class Program
{
    private static Mutex? instance;

    [STAThread]
    private static int Main(string[] args)
    {
        Encoding.RegisterProvider(CodePagesEncodingProvider.Instance);
        ApplicationConfiguration.Initialize();

        if (args.Contains("--uninstall"))
        {
            Installer.Uninstall();
            return 0;
        }

        // Downloaded copy (or a newer one): offer to install or update, then hand over.
        if (!Installer.IsRunningInstalled)
        {
            Application.Run(new InstallForm());
            return 0;
        }

        Updater.CleanUp();
        instance = new Mutex(true, SingleInstance.MutexName, out var first);

        // After an update the old copy may still be closing: give it a moment.
        if (!first && args.Contains("--updated"))
        {
            try
            {
                first = instance.WaitOne(TimeSpan.FromSeconds(15));
            }
            catch (AbandonedMutexException)
            {
                first = true;
            }
        }

        if (!first)
        {
            SingleInstance.Signal(SingleInstance.ShowEvent);
            return 0;
        }

        Application.Run(new TrayApp(showWindow: !args.Contains("--background")));
        ReleaseInstance();
        return 0;
    }

    /// <summary>Let another copy start (used right before restarting after an update).</summary>
    public static void ReleaseInstance()
    {
        try
        {
            instance?.ReleaseMutex();
        }
        catch (ApplicationException)
        {
            // Not owned (already released).
        }

        instance?.Dispose();
        instance = null;
    }
}

/// <summary>Only one copy runs; opening it again shows the window of the running one.</summary>
internal static class SingleInstance
{
    public const string MutexName = @"Local\CentreCivicImpressio";
    public const string ShowEvent = @"Local\CentreCivicImpressio.Show";
    public const string QuitEvent = @"Local\CentreCivicImpressio.Quit";

    public static void Signal(string name)
    {
        try
        {
            using var handle = EventWaitHandle.OpenExisting(name);
            handle.Set();
        }
        catch (WaitHandleCannotBeOpenedException)
        {
            // Not running.
        }
    }

    /// <summary>Calls <paramref name="action"/> every time the event is signalled.</summary>
    public static void Listen(string name, Action action)
    {
        var handle = new EventWaitHandle(false, EventResetMode.AutoReset, name);
        ThreadPool.RegisterWaitForSingleObject(handle, (_, _) => action(), null, Timeout.Infinite, false);
    }

    /// <summary>Ask the running copy to close and wait until it has.</summary>
    public static bool CloseRunning(TimeSpan timeout)
    {
        Signal(QuitEvent);

        try
        {
            using var mutex = Mutex.OpenExisting(MutexName);

            try
            {
                if (!mutex.WaitOne(timeout))
                {
                    return false;
                }
            }
            catch (AbandonedMutexException)
            {
                // It closed without releasing: fine.
            }

            mutex.ReleaseMutex();
            return true;
        }
        catch (WaitHandleCannotBeOpenedException)
        {
            return true;
        }
    }
}
