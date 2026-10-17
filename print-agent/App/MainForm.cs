using System.Diagnostics;

namespace CentreCivic.PrintAgent.App;

/// <summary>
/// The program's window: paste the key, see whether it is connected, which printers it
/// serves, the last prints, and print a test on any printer of this computer.
/// </summary>
internal sealed class MainForm : Form
{
    private readonly AgentRunner runner;
    private readonly Action quit;
    private AgentConfig config;
    private bool allowClose;
    private bool hintShown;

    private readonly Label statusDot = new() { Text = "●", AutoSize = true, Font = new Font("Segoe UI", 16f) };
    private readonly Label statusText = new() { AutoSize = true, Font = Ui.BoldFont, MaximumSize = new Size(640, 0) };
    private readonly Label statusDetail = new() { AutoSize = true, ForeColor = Color.DimGray, MaximumSize = new Size(640, 0) };
    private readonly TextBox keyBox = new() { Width = 520, PlaceholderText = "https://…/api/print-agent/…" };
    private readonly ListView assigned = List(("Al TPV", 170), ("On imprimeix", 250), ("Format", 190));
    private readonly ListView history = List(("Hora", 70), ("Impressora", 150), ("Document", 260), ("Resultat", 140));
    private readonly ComboBox localPrinters = new() { DropDownStyle = ComboBoxStyle.DropDownList, Width = 300 };
    private readonly ComboBox localFormat = new() { DropDownStyle = ComboBoxStyle.DropDownList, Width = 170 };

    public MainForm(AgentRunner runner, AgentConfig config, Action quit)
    {
        this.runner = runner;
        this.config = config;
        this.quit = quit;

        Text = Installer.AppName;
        Icon = Ui.AppIcon;
        Font = Ui.BodyFont;
        AutoScaleMode = AutoScaleMode.Dpi;
        BackColor = Color.White;
        StartPosition = FormStartPosition.CenterScreen;
        ClientSize = new Size(720, 760);
        MinimumSize = new Size(640, 560);

        var body = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            FlowDirection = FlowDirection.TopDown,
            WrapContents = false,
            AutoScroll = true,
            Padding = new Padding(22, 8, 22, 16),
        };

        // Status
        var statusRow = new FlowLayoutPanel { AutoSize = true, WrapContents = false, Margin = new Padding(0, 10, 0, 0) };
        statusRow.Controls.AddRange([statusDot, new FlowLayoutPanel { AutoSize = true, FlowDirection = FlowDirection.TopDown, Controls = { statusText, statusDetail } }]);
        body.Controls.Add(statusRow);

        // Key
        body.Controls.Add(Ui.Section("Clau de connexió"));
        body.Controls.Add(Hint("A la web: Gestió → Impressió → Programa d'impressió → «Clau». Copia-la sencera i enganxa-la aquí."));
        var connect = Primary("Connecta");
        connect.Click += (_, _) => SaveKey();
        var keyRow = new FlowLayoutPanel { AutoSize = true, WrapContents = false };
        keyRow.Controls.AddRange([keyBox, connect]);
        body.Controls.Add(keyRow);

        // Printers it serves
        body.Controls.Add(Ui.Section("Impressores que imprimeix aquest ordinador"));
        body.Controls.Add(Hint("Es trien a la web, a cada impressora: «Imprimeix a través de» aquest ordinador."));
        assigned.Height = 120;
        body.Controls.Add(assigned);

        // History
        body.Controls.Add(Ui.Section("Darreres impressions"));
        history.Height = 170;
        body.Controls.Add(history);

        // Local test
        body.Controls.Add(Ui.Section("Prova una impressora d'aquest ordinador"));
        body.Controls.Add(Hint("Imprimeix un tiquet de prova sense passar pel servidor. Si amb «ESC/POS directe» els accents i el tall surten bé, és el format a triar a la web."));
        localFormat.Items.AddRange(["ESC/POS directe", "Driver de Windows"]);
        localFormat.SelectedIndex = 0;
        var test = new Button { Text = "Imprimeix prova", AutoSize = true, Height = 32 };
        test.Click += (_, _) => PrintLocalTest();
        var testRow = new FlowLayoutPanel { AutoSize = true, WrapContents = false };
        testRow.Controls.AddRange([localPrinters, localFormat, test]);
        body.Controls.Add(testRow);

        // Footer
        var autostart = new CheckBox { Text = "Obre'l automàticament en encendre l'ordinador", AutoSize = true, Checked = Installer.Autostart, Margin = new Padding(0, 18, 0, 0) };
        autostart.CheckedChanged += (_, _) =>
        {
            Installer.SetAutostart(autostart.Checked);
            this.config.StartWithWindows = autostart.Checked;
            this.config.Save();
        };
        body.Controls.Add(autostart);

        var footer = new FlowLayoutPanel { AutoSize = true, WrapContents = false, Margin = new Padding(0, 8, 0, 0) };
        var logLink = new LinkLabel { Text = "Obre el registre", AutoSize = true, Margin = new Padding(0, 6, 18, 0) };
        logLink.LinkClicked += (_, _) => OpenLog();
        var uninstall = new LinkLabel { Text = "Desinstal·la…", AutoSize = true, Margin = new Padding(0, 6, 18, 0) };
        uninstall.LinkClicked += (_, _) => Process.Start(new ProcessStartInfo(Installer.ExePath, "--uninstall") { UseShellExecute = false });
        var version = new Label { Text = $"Versió {AgentRunner.Version}", AutoSize = true, ForeColor = Color.Gray, Margin = new Padding(0, 6, 0, 0) };
        footer.Controls.AddRange([logLink, uninstall, version]);
        body.Controls.Add(footer);

        Controls.Add(body);
        Controls.Add(Ui.Header("Programa d'impressió", "Centre Cívic Pau · imprimeix els tiquets i les comandes del TPV"));

        if (config.IsComplete)
        {
            keyBox.Text = $"{config.ServerUrl}/api/print-agent/••••••••";
        }

        keyBox.Enter += (_, _) =>
        {
            if (keyBox.Text.Contains('•'))
            {
                keyBox.SelectAll();
            }
        };

        AcceptButton = connect;
        Shown += (_, _) => { RefreshStatus(); LoadLocalPrinters(); };
        RefreshStatus();
    }

    public void AllowClose() => allowClose = true;

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        // Closing the window keeps the program printing in the background.
        if (!allowClose && e.CloseReason == CloseReason.UserClosing)
        {
            e.Cancel = true;
            Hide();

            if (!hintShown)
            {
                hintShown = true;
                MessageBox.Show(this, "El programa segueix funcionant i imprimint. El trobaràs a la icona del costat del rellotge.", Installer.AppName, MessageBoxButtons.OK, MessageBoxIcon.Information);
            }

            return;
        }

        base.OnFormClosing(e);
    }

    public void RefreshStatus()
    {
        var (color, title) = runner.State switch
        {
            AgentState.Online when runner.Realtime => (Ui.Ok, $"Connectat i a punt d'imprimir{(runner.AgentName is null ? "" : $" · «{runner.AgentName}»")}"),
            AgentState.Online => (Ui.Warn, "Connectat (sense temps real: pot trigar uns segons)"),
            AgentState.Offline => (Ui.Bad, "Sense connexió amb el servidor"),
            AgentState.BadKey => (Ui.Bad, "La clau no és vàlida"),
            AgentState.NotConfigured => (Ui.Warn, "Falta enganxar la clau de connexió"),
            _ => (Ui.Warn, "Connectant…"),
        };

        statusDot.ForeColor = color;
        statusText.Text = title;
        statusDetail.Text = runner.State == AgentState.Online
            ? $"Servidor: {config.ServerUrl}"
            : runner.Message ?? "";

        assigned.BeginUpdate();
        assigned.Items.Clear();

        foreach (var printer in runner.Printers)
        {
            var item = new ListViewItem([
                printer.Name,
                printer.Target == "network" ? $"Xarxa {printer.Ip}:{printer.Port}" : printer.SystemName ?? printer.Name,
                printer.Output == "driver" ? "Driver de Windows" : "ESC/POS directe",
            ]);

            if (!printer.Active)
            {
                item.ForeColor = Color.Gray;
                item.SubItems[0].Text += " (desactivada)";
            }

            assigned.Items.Add(item);
        }

        if (runner.Printers.Count == 0 && runner.State == AgentState.Online)
        {
            assigned.Items.Add(new ListViewItem(["Cap encara", "Assigna-les a la web, a Gestió → Impressió", ""]) { ForeColor = Color.Gray });
        }

        assigned.EndUpdate();
    }

    public void AddResult(JobResult result)
    {
        var item = new ListViewItem([result.At.ToString("HH:mm:ss"), result.Printer, result.Title, result.Printed ? "Imprès" : "Error: " + result.Error])
        {
            ForeColor = result.Printed ? Color.Black : Ui.Bad,
            ToolTipText = result.Error ?? "",
        };
        history.Items.Insert(0, item);

        while (history.Items.Count > 50)
        {
            history.Items.RemoveAt(history.Items.Count - 1);
        }
    }

    private void SaveKey()
    {
        var text = keyBox.Text.Trim();

        if (text.Contains('•'))
        {
            return; // unchanged
        }

        if (!AgentConfig.TryParseKey(text, out var server, out var token, out var error))
        {
            MessageBox.Show(this, error, Installer.AppName, MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return;
        }

        config = new AgentConfig { ServerUrl = server, Token = token, StartWithWindows = config.StartWithWindows };
        config.Save();
        keyBox.Text = $"{server}/api/print-agent/••••••••";
        Log.Write($"Nova clau de connexió per a {server}.");
        runner.Reconfigure(config);
        RefreshStatus();
    }

    private void LoadLocalPrinters()
    {
        var selected = localPrinters.SelectedItem as string;
        localPrinters.Items.Clear();
        localPrinters.Items.AddRange([.. new WindowsBackend().InstalledPrinters()]);

        if (localPrinters.Items.Count > 0)
        {
            localPrinters.SelectedItem = selected is not null && localPrinters.Items.Contains(selected) ? selected : localPrinters.Items[0];
        }
    }

    private void PrintLocalTest()
    {
        if (localPrinters.SelectedItem is not string name)
        {
            MessageBox.Show(this, "No hi ha cap impressora instal·lada a Windows. Instal·la-la primer (amb el seu driver) i torna a obrir aquesta finestra.", Installer.AppName);
            return;
        }

        try
        {
            if (localFormat.SelectedIndex == 0)
            {
                RawPrinter.Send(name, TestTicket.Escpos(), "Prova d'impressió");
            }
            else
            {
                DriverPrinter.Print(name, TestTicket.Driver(), "Prova d'impressió");
            }

            Log.Write($"Prova local enviada a «{name}» ({localFormat.Text}).");
            AddResult(new JobResult(DateTime.Now, name, "Prova local · " + localFormat.Text, true, null));
        }
        catch (Exception e)
        {
            Log.Write($"Prova local a «{name}» fallida: {e.Message}");
            AddResult(new JobResult(DateTime.Now, name, "Prova local · " + localFormat.Text, false, e.Message));
            MessageBox.Show(this, e.Message, Installer.AppName, MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
    }

    private static void OpenLog()
    {
        if (File.Exists(Log.FilePath))
        {
            Process.Start(new ProcessStartInfo("notepad.exe", $"\"{Log.FilePath}\"") { UseShellExecute = true });
        }
    }

    private static Label Hint(string text) => new() { Text = text, AutoSize = true, ForeColor = Color.DimGray, MaximumSize = new Size(660, 0), Margin = new Padding(0, 0, 0, 6) };

    private static Button Primary(string text)
    {
        var button = new Button { Text = text, Height = 32, AutoSize = true, BackColor = Ui.Brand, ForeColor = Color.White, FlatStyle = FlatStyle.Flat, Font = Ui.BoldFont, Margin = new Padding(8, 0, 0, 0) };
        button.FlatAppearance.BorderSize = 0;

        return button;
    }

    private static ListView List(params (string Title, int Width)[] columns)
    {
        var list = new ListView { View = View.Details, FullRowSelect = true, HeaderStyle = ColumnHeaderStyle.Nonclickable, Width = 660, ShowItemToolTips = true };

        foreach (var (title, width) in columns)
        {
            list.Columns.Add(title, width);
        }

        return list;
    }
}
