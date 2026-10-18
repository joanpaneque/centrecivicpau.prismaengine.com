using System.Diagnostics;

namespace CentreCivic.PrintAgent.App;

/// <summary>
/// The program's window: paste the key, see whether it is connected, set up the printers of
/// this computer (they show up on the web with their names), the last prints, and updates.
/// </summary>
internal sealed class MainForm : Form
{
    private readonly AgentRunner runner;
    private readonly Action updateNow;
    private bool allowClose;
    private bool hintShown;
    private bool updating;

    private readonly Label statusDot = new() { Text = "●", AutoSize = true, Font = new Font("Segoe UI", 16f) };
    private readonly Label statusText = new() { AutoSize = true, Font = Ui.BoldFont, MaximumSize = new Size(640, 0) };
    private readonly Label statusDetail = new() { AutoSize = true, ForeColor = Color.DimGray, MaximumSize = new Size(640, 0) };
    private readonly TextBox keyBox = new() { Width = 520, PlaceholderText = "https://…/api/print-agent/…" };
    private readonly ListView printers = List(("Nom", 150), ("On és", 210), ("Format", 120), ("A la web", 180));
    private readonly Button editPrinter = new() { Text = "Edita…", AutoSize = true, Height = 32, Enabled = false };
    private readonly Button removePrinter = new() { Text = "Elimina", AutoSize = true, Height = 32, Enabled = false };
    private readonly Button testPrinter = new() { Text = "Imprimeix una prova", AutoSize = true, Height = 32, Enabled = false };
    private readonly ListView history = List(("Hora", 70), ("Impressora", 150), ("Document", 260), ("Resultat", 140));
    private readonly Label updateDot = new() { Text = "●", AutoSize = true, Font = new Font("Segoe UI", 12f), Margin = new Padding(0, 4, 4, 0) };
    private readonly Label updateText = new() { AutoSize = true, MaximumSize = new Size(600, 0), Margin = new Padding(0, 8, 0, 0) };
    private readonly Button updateButton = Primary("Actualitza ara");
    private readonly Button checkButton = new() { Text = "Comprova si n'hi ha", AutoSize = true, Height = 32 };

    public MainForm(AgentRunner runner, Action updateNow)
    {
        this.runner = runner;
        this.updateNow = updateNow;
        var config = runner.Config;

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

        // Printers of this computer
        body.Controls.Add(Ui.Section("Impressores d'aquest ordinador"));
        body.Controls.Add(Hint("Afegeix-hi cada impressora amb un nom (Cuina, Barra, Tiquets…). Surten soles a la web, a Gestió → Impressió, on tries què imprimeix cadascuna."));
        printers.Height = 150;
        printers.MultiSelect = false;
        printers.SelectedIndexChanged += (_, _) => UpdatePrinterButtons();
        printers.DoubleClick += (_, _) => EditPrinter();
        body.Controls.Add(printers);
        var addPrinter = Primary("Afegeix…");
        addPrinter.Margin = new Padding(0, 3, 6, 3);
        addPrinter.Click += (_, _) => AddPrinter();
        editPrinter.Click += (_, _) => EditPrinter();
        removePrinter.Click += (_, _) => RemovePrinter();
        testPrinter.Click += (_, _) => TestPrinter();
        body.Controls.Add(new FlowLayoutPanel { AutoSize = true, WrapContents = false, Margin = new Padding(0, 6, 0, 0), Controls = { addPrinter, editPrinter, removePrinter, testPrinter } });

        // History
        body.Controls.Add(Ui.Section("Darreres impressions"));
        history.Height = 170;
        body.Controls.Add(history);

        // Updates
        body.Controls.Add(Ui.Section("Actualitzacions"));
        updateButton.Margin = new Padding(12, 3, 6, 3);
        updateButton.Click += (_, _) => updateNow();
        checkButton.Click += (_, _) =>
        {
            runner.CheckNow();
            updateText.Text = "Comprovant…";
        };
        body.Controls.Add(new FlowLayoutPanel { AutoSize = true, WrapContents = false, Controls = { updateDot, updateText, updateButton, checkButton } });
        var autoUpdate = new CheckBox { Text = "Instal·la les versions noves automàticament", AutoSize = true, Checked = config.AutoUpdate, Margin = new Padding(0, 6, 0, 0) };
        autoUpdate.CheckedChanged += (_, _) => runner.SetAutoUpdate(autoUpdate.Checked);
        body.Controls.Add(autoUpdate);

        // Footer
        var autostart = new CheckBox { Text = "Obre'l automàticament en encendre l'ordinador", AutoSize = true, Checked = Installer.Autostart, Margin = new Padding(0, 18, 0, 0) };
        autostart.CheckedChanged += (_, _) =>
        {
            Installer.SetAutostart(autostart.Checked);
            var current = runner.Config;
            current.StartWithWindows = autostart.Checked;
            current.Save();
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
        Shown += (_, _) => RefreshStatus();
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
            ? $"Servidor: {runner.Config.ServerUrl}"
            : runner.Message ?? "";

        RefreshPrinters();
        RefreshUpdate();
    }

    /// <summary>The printers set up here, and what the web says about each one.</summary>
    public void RefreshPrinters()
    {
        var selected = (printers.SelectedItems.Count > 0 ? printers.SelectedItems[0].Tag as LocalPrinter : null)?.Key;
        var config = runner.Config;
        printers.BeginUpdate();
        printers.Items.Clear();

        foreach (var printer in config.Printers)
        {
            var server = runner.Printers.FirstOrDefault(p => p.Key == printer.Key);
            var web = server is null
                ? (runner.State == AgentState.Online ? "Enviant…" : "Pendent d'enviar")
                : !server.Active ? "Desactivada"
                : server.IsTicketPrinter ? "Tiquets de caixa"
                : "A punt";
            var item = new ListViewItem([
                printer.Name,
                printer.Target == "network" ? $"Xarxa {printer.Ip}:{printer.Port}" : printer.SystemName ?? "",
                printer.Target == "network" || printer.Output == "escpos" ? "ESC/POS directe" : "Driver de Windows",
                web,
            ])
            {
                Tag = printer,
                ForeColor = server is { Active: false } ? Color.Gray : Color.Black,
            };

            printers.Items.Add(item);
            item.Selected = printer.Key == selected;
        }

        if (config.Printers.Count == 0)
        {
            printers.Items.Add(new ListViewItem(["Cap encara", "Clica «Afegeix…» per posar-ne una", "", ""]) { ForeColor = Color.Gray });
        }

        printers.EndUpdate();
        UpdatePrinterButtons();
    }

    public void SetUpdating(bool value)
    {
        updating = value;
        RefreshUpdate();
    }

    private void RefreshUpdate()
    {
        var latest = runner.LatestVersion;
        var newer = Updater.IsNewer(latest);

        updateButton.Visible = newer;
        updateButton.Enabled = !updating;
        checkButton.Enabled = !updating;
        checkButton.Visible = !newer;

        (updateDot.ForeColor, updateText.Text) = (updating, newer, runner.CheckedAt) switch
        {
            (true, _, _) => (Ui.Warn, $"Actualitzant a la versió {latest}… El programa es tornarà a obrir sol."),
            (_, true, _) => (Ui.Warn, $"Hi ha una versió nova: {latest} (tens la {AgentRunner.Version})."),
            (_, _, null) => (Color.Gray, $"Tens la versió {AgentRunner.Version}. Es comprovarà quan es connecti al servidor."),
            (_, _, { } at) => (Ui.Ok, $"Tens la darrera versió ({AgentRunner.Version}). Comprovat a les {at:HH:mm}."),
        };
    }

    private void UpdatePrinterButtons()
    {
        var any = printers.SelectedItems.Count > 0 && printers.SelectedItems[0].Tag is LocalPrinter;
        editPrinter.Enabled = any;
        removePrinter.Enabled = any;
        testPrinter.Enabled = any;
    }

    private LocalPrinter? SelectedPrinter => printers.SelectedItems.Count > 0 ? printers.SelectedItems[0].Tag as LocalPrinter : null;

    private void AddPrinter()
    {
        var list = runner.Config.Printers;
        using var form = new PrinterForm(null, list);

        if (form.ShowDialog(this) == DialogResult.OK)
        {
            runner.SetPrinters([.. list, form.Result]);
            RefreshPrinters();
        }
    }

    private void EditPrinter()
    {
        if (SelectedPrinter is not { } printer)
        {
            return;
        }

        var list = runner.Config.Printers;
        using var form = new PrinterForm(printer, list);

        if (form.ShowDialog(this) == DialogResult.OK)
        {
            var result = form.Result;
            runner.SetPrinters([.. list.Select(p => p.Key == result.Key ? result : p)]);
            RefreshPrinters();
        }
    }

    private void RemovePrinter()
    {
        if (SelectedPrinter is not { } printer)
        {
            return;
        }

        if (MessageBox.Show(this, $"Vols eliminar la impressora «{printer.Name}»?\n\nTambé desapareixerà de la web, amb els destins que tenia assignats.", Installer.AppName, MessageBoxButtons.YesNo, MessageBoxIcon.Question) != DialogResult.Yes)
        {
            return;
        }

        runner.SetPrinters([.. runner.Config.Printers.Where(p => p.Key != printer.Key)]);
        RefreshPrinters();
    }

    private void TestPrinter()
    {
        if (SelectedPrinter is not { } printer)
        {
            return;
        }

        try
        {
            TestTicket.Print(printer);
            Log.Write($"Prova local enviada a «{printer.Name}» ({printer.Where}).");
            AddResult(new JobResult(DateTime.Now, printer.Name, "Prova local", true, null));
        }
        catch (Exception e)
        {
            Log.Write($"Prova local a «{printer.Name}» ({printer.Where}) fallida: {e.Message}");
            AddResult(new JobResult(DateTime.Now, printer.Name, "Prova local", false, e.Message));
            MessageBox.Show(this, e.Message, Installer.AppName, MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
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

        var config = runner.Config.WithKey(server, token);
        config.Save();
        keyBox.Text = $"{server}/api/print-agent/••••••••";
        Log.Write($"Nova clau de connexió per a {server}.");
        runner.Reconfigure(config);
        RefreshStatus();
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
