namespace CentreCivic.PrintAgent.App;

/// <summary>
/// The program while it runs: an icon next to the clock, the printing loop in the
/// background and the window, which is only hidden when closed.
/// </summary>
internal sealed class TrayApp : ApplicationContext
{
    private readonly NotifyIcon tray;
    private readonly MainForm window;
    private readonly AgentRunner runner;
    private readonly CancellationTokenSource stop = new();
    private readonly SynchronizationContext ui;
    private AgentState? announced;
    private string? announcedVersion;
    private DateTime nextAutoUpdate = DateTime.MinValue;

    public TrayApp(bool showWindow)
    {
        ui = SynchronizationContext.Current ?? new WindowsFormsSynchronizationContext();
        var config = AgentConfig.Load();
        runner = new AgentRunner(config, new WindowsBackend());
        window = new MainForm(runner, () => _ = UpdateAsync(manual: true));

        var menu = new ContextMenuStrip();
        menu.Items.Add("Obre", null, (_, _) => ShowWindow());
        menu.Items.Add(new ToolStripSeparator());
        menu.Items.Add("Tanca el programa", null, (_, _) => AskQuit());

        tray = new NotifyIcon { Icon = Ui.AppIcon, Text = Installer.AppName, ContextMenuStrip = menu, Visible = true };
        tray.DoubleClick += (_, _) => ShowWindow();

        runner.StatusChanged += () => ui.Post(_ => OnStatus(), null);
        runner.JobFinished += result => ui.Post(_ => OnJob(result), null);
        runner.PrintersChanged += () => ui.Post(_ => window.RefreshPrinters(), null);
        SingleInstance.Listen(SingleInstance.ShowEvent, () => ui.Post(_ => ShowWindow(), null));
        SingleInstance.Listen(SingleInstance.QuitEvent, () => ui.Post(_ => Quit(), null));

        _ = Task.Run(() => runner.RunAsync(stop.Token));

        if (showWindow || !config.IsComplete)
        {
            ShowWindow();
        }
    }

    private void ShowWindow()
    {
        window.Show();
        window.WindowState = FormWindowState.Normal;
        window.Activate();
    }

    private void OnStatus()
    {
        window.RefreshStatus();
        var text = runner.State switch
        {
            AgentState.Online => runner.Realtime ? "connectat" : "connectat (sense temps real)",
            AgentState.Offline => "sense connexió",
            AgentState.BadKey => "clau no vàlida",
            AgentState.NotConfigured => "falta la clau",
            _ => "connectant…",
        };
        var tip = $"{Installer.AppName}: {text}";
        tray.Text = tip.Length > 63 ? tip[..63] : tip;

        // Tell once when it stops working, not on every retry.
        if (runner.State is AgentState.Offline or AgentState.BadKey && announced != runner.State)
        {
            tray.ShowBalloonTip(8000, "Impressió: no s'imprimirà res", runner.Message ?? "", ToolTipIcon.Warning);
        }

        if (runner.State is AgentState.Online or AgentState.Offline or AgentState.BadKey)
        {
            announced = runner.State;
        }

        if (runner.State == AgentState.Online && Updater.IsNewer(runner.LatestVersion))
        {
            if (runner.Config.AutoUpdate && DateTime.UtcNow >= nextAutoUpdate)
            {
                _ = UpdateAsync(manual: false);
            }
            else if (announcedVersion != runner.LatestVersion)
            {
                announcedVersion = runner.LatestVersion;
                tray.ShowBalloonTip(10000, "Hi ha una versió nova del programa", $"Versió {runner.LatestVersion}. Obre el programa i clica «Actualitza ara».", ToolTipIcon.Info);
            }
        }
    }

    private async Task UpdateAsync(bool manual)
    {
        var version = runner.LatestVersion;

        if (!Updater.IsNewer(version) || Updater.Busy)
        {
            return;
        }

        window.SetUpdating(true);
        var error = await Updater.TryUpdateAsync(runner, version!, stop.Token);

        if (error is null)
        {
            Quit();
            return;
        }

        window.SetUpdating(false);
        nextAutoUpdate = DateTime.UtcNow.AddMinutes(30); // do not retry on every status change

        if (manual)
        {
            MessageBox.Show(window, "No s'ha pogut actualitzar: " + error + "\n\nTambé pots descarregar el programa de nou a Gestió → Impressió i obrir-lo: s'actualitza amb la mateixa configuració.", Installer.AppName, MessageBoxButtons.OK, MessageBoxIcon.Warning);
        }
    }

    private void OnJob(JobResult result)
    {
        window.AddResult(result);

        if (!result.Printed)
        {
            tray.ShowBalloonTip(10000, $"No s'ha pogut imprimir a «{result.Printer}»", result.Error ?? "", ToolTipIcon.Error);
        }
    }

    private void AskQuit()
    {
        if (MessageBox.Show(
                "Si tanques el programa, els tiquets i les comandes no s'imprimiran per les impressores d'aquest ordinador fins que el tornis a obrir.\n\nVols tancar-lo?",
                Installer.AppName, MessageBoxButtons.YesNo, MessageBoxIcon.Warning) == DialogResult.Yes)
        {
            Quit();
        }
    }

    private void Quit()
    {
        stop.Cancel();
        tray.Visible = false;
        window.AllowClose();
        window.Close();
        runner.Dispose();
        ExitThread();
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            tray.Dispose();
            window.Dispose();
            stop.Dispose();
        }

        base.Dispose(disposing);
    }
}
