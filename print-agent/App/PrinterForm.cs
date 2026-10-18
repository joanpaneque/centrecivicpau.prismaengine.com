using System.Net;

namespace CentreCivic.PrintAgent.App;

/// <summary>
/// Add or change one printer of this computer: the name it gets on the web, where it is
/// (a printer installed in Windows or one on the network) and how it prints.
/// </summary>
internal sealed class PrinterForm : Form
{
    private static readonly (string Value, string Label)[] Outputs = [("escpos", "ESC/POS directe (impressores de tiquets)"), ("driver", "Driver de Windows (qualsevol impressora)")];
    private static readonly (int Value, string Label)[] Widths = [(48, "80 mm (48 caràcters)"), (42, "80 mm (42 caràcters)"), (32, "58 mm (32 caràcters)")];
    private static readonly (string Value, string Label)[] Codepages = [("cp858", "Europeu CP858 (recomanat)"), ("cp1252", "Windows CP1252"), ("ascii", "Sense accents")];

    private readonly LocalPrinter original;
    private readonly IReadOnlyList<LocalPrinter> others;

    private readonly TextBox name = new() { Width = 360, MaxLength = 60, PlaceholderText = "Cuina, Barra, Tiquets…" };
    private readonly RadioButton windows = new() { Text = "Instal·lada a Windows (USB, o de xarxa amb driver)", AutoSize = true, Checked = true };
    private readonly RadioButton network = new() { Text = "De xarxa, per IP (ESC/POS, port 9100)", AutoSize = true };
    private readonly ComboBox systemName = new() { DropDownStyle = ComboBoxStyle.DropDownList, Width = 360 };
    private readonly TextBox ip = new() { Width = 200, PlaceholderText = "192.168.1.50" };
    private readonly NumericUpDown port = new() { Minimum = 1, Maximum = 65535, Value = 9100, Width = 90 };
    private readonly ComboBox output = Combo(Outputs.Select(o => o.Label));
    private readonly ComboBox width = Combo(Widths.Select(w => w.Label));
    private readonly ComboBox codepage = Combo(Codepages.Select(c => c.Label));
    private readonly Control windowsRow;
    private readonly Control networkRow;
    private readonly Control outputRow;
    private readonly Control codepageRow;

    public PrinterForm(LocalPrinter? printer, IReadOnlyList<LocalPrinter> others)
    {
        original = printer ?? new LocalPrinter();
        this.others = others;

        Text = printer is null ? "Afegeix una impressora" : "Impressora «" + printer.Name + "»";
        Icon = Ui.AppIcon;
        Font = Ui.BodyFont;
        AutoScaleMode = AutoScaleMode.Dpi;
        BackColor = Color.White;
        StartPosition = FormStartPosition.CenterParent;
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false;
        MinimizeBox = false;
        ShowInTaskbar = false;
        AutoSize = true;
        AutoSizeMode = AutoSizeMode.GrowAndShrink;

        var body = new FlowLayoutPanel { AutoSize = true, FlowDirection = FlowDirection.TopDown, WrapContents = false, Padding = new Padding(20, 14, 20, 16) };

        body.Controls.Add(Ui.Section("Nom"));
        body.Controls.Add(Hint("Així sortirà a la web (Gestió → Impressió), on tries què imprimeix."));
        body.Controls.Add(name);

        body.Controls.Add(Ui.Section("On és"));
        body.Controls.Add(windows);
        windowsRow = Row(systemName);
        body.Controls.Add(windowsRow);
        body.Controls.Add(network);
        networkRow = Row(ip, new Label { Text = "Port", AutoSize = true, Margin = new Padding(12, 6, 4, 0) }, port);
        body.Controls.Add(networkRow);

        outputRow = Group("Format", "ESC/POS és el més ràpid i talla el paper. Si surt en blanc o amb símbols estranys, tria «Driver de Windows».", output);
        body.Controls.Add(outputRow);
        body.Controls.Add(Group("Paper", null, width));
        codepageRow = Group("Accents", "Si els accents o el € surten malament, prova l'altre.", codepage);
        body.Controls.Add(codepageRow);

        var test = new Button { Text = "Imprimeix una prova", AutoSize = true, Height = 34 };
        test.Click += (_, _) => PrintTest();
        var save = new Button { Text = "Desa", AutoSize = true, Height = 34, MinimumSize = new Size(110, 34), BackColor = Ui.Brand, ForeColor = Color.White, FlatStyle = FlatStyle.Flat, Font = Ui.BoldFont, Margin = new Padding(24, 3, 3, 3) };
        save.FlatAppearance.BorderSize = 0;
        save.Click += (_, _) => Save();
        var cancel = new Button { Text = "Cancel·la", AutoSize = true, Height = 34, DialogResult = DialogResult.Cancel };
        body.Controls.Add(new FlowLayoutPanel { AutoSize = true, WrapContents = false, Margin = new Padding(0, 18, 0, 0), Controls = { test, save, cancel } });

        Controls.Add(body);
        AcceptButton = save;
        CancelButton = cancel;

        LoadPrinters();
        name.Text = original.Name;
        network.Checked = original.Target == "network";
        ip.Text = original.Ip ?? "";
        port.Value = Math.Clamp(original.Port, 1, 65535);
        output.SelectedIndex = Math.Max(0, Array.FindIndex(Outputs, o => o.Value == original.Output));
        width.SelectedIndex = Math.Max(0, Array.FindIndex(Widths, w => w.Value == original.PaperWidth));
        codepage.SelectedIndex = Math.Max(0, Array.FindIndex(Codepages, c => c.Value == original.Codepage));

        windows.CheckedChanged += (_, _) => UpdateVisibility();
        output.SelectedIndexChanged += (_, _) => UpdateVisibility();
        UpdateVisibility();
    }

    /// <summary>The printer as it is in the form (after Desa: as saved).</summary>
    public LocalPrinter Result => new()
    {
        Key = original.Key,
        Name = name.Text.Trim(),
        Target = network.Checked ? "network" : "windows",
        SystemName = network.Checked ? null : systemName.SelectedItem as string,
        Ip = network.Checked ? ip.Text.Trim() : null,
        Port = (int)port.Value,
        Output = network.Checked ? "escpos" : Outputs[Math.Max(0, output.SelectedIndex)].Value,
        PaperWidth = Widths[Math.Max(0, width.SelectedIndex)].Value,
        Codepage = Codepages[Math.Max(0, codepage.SelectedIndex)].Value,
    };

    private void LoadPrinters()
    {
        var installed = new WindowsBackend().InstalledPrinters();
        systemName.Items.AddRange([.. installed]);

        // Keep the chosen one even if it is not installed right now (so it is not lost).
        if (original.SystemName is { Length: > 0 } current && !installed.Contains(current))
        {
            systemName.Items.Insert(0, current);
        }

        if (systemName.Items.Count > 0)
        {
            systemName.SelectedItem = original.SystemName is not null && systemName.Items.Contains(original.SystemName) ? original.SystemName : systemName.Items[0];
        }
    }

    private void UpdateVisibility()
    {
        windowsRow.Enabled = windows.Checked;
        networkRow.Enabled = network.Checked;
        outputRow.Visible = windows.Checked;
        codepageRow.Visible = network.Checked || output.SelectedIndex == 0;
    }

    private string? Problem(LocalPrinter printer)
    {
        if (printer.Name == "")
        {
            return "Posa-li un nom (per exemple «Cuina»).";
        }

        if (others.Any(o => o.Key != printer.Key && string.Equals(o.Name, printer.Name, StringComparison.CurrentCultureIgnoreCase)))
        {
            return $"Ja hi ha una impressora que es diu «{printer.Name}». Posa-li un altre nom.";
        }

        if (printer.Target == "windows" && string.IsNullOrEmpty(printer.SystemName))
        {
            return "No hi ha cap impressora instal·lada a Windows. Instal·la-la primer (amb el seu driver) o tria «De xarxa».";
        }

        if (printer.Target == "network" && !IPAddress.TryParse(printer.Ip, out _))
        {
            return "Escriu la IP de la impressora (per exemple 192.168.1.50).";
        }

        return null;
    }

    private void Save()
    {
        var printer = Result;

        if (Problem(printer) is { } error)
        {
            MessageBox.Show(this, error, Installer.AppName, MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return;
        }

        DialogResult = DialogResult.OK;
        Close();
    }

    private void PrintTest()
    {
        var printer = Result;

        if (printer.Name == "")
        {
            printer.Name = "Prova";
        }

        if (Problem(printer) is { } error)
        {
            MessageBox.Show(this, error, Installer.AppName, MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return;
        }

        Cursor = Cursors.WaitCursor;

        try
        {
            TestTicket.Print(printer);
            Log.Write($"Prova local enviada a «{printer.Name}» ({printer.Where}).");
            MessageBox.Show(this, "Prova enviada. Si els accents i el tall surten bé, desa-la així.", Installer.AppName, MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
        catch (Exception e)
        {
            Log.Write($"Prova local a «{printer.Name}» ({printer.Where}) fallida: {e.Message}");
            MessageBox.Show(this, e.Message, Installer.AppName, MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
        finally
        {
            Cursor = Cursors.Default;
        }
    }

    private static ComboBox Combo(IEnumerable<string> items)
    {
        var combo = new ComboBox { DropDownStyle = ComboBoxStyle.DropDownList, Width = 360 };
        combo.Items.AddRange([.. items]);

        return combo;
    }

    private static FlowLayoutPanel Row(params Control[] controls)
    {
        var row = new FlowLayoutPanel { AutoSize = true, WrapContents = false, Margin = new Padding(22, 0, 0, 6) };
        row.Controls.AddRange(controls);

        return row;
    }

    private static FlowLayoutPanel Group(string title, string? hint, Control control)
    {
        var group = new FlowLayoutPanel { AutoSize = true, FlowDirection = FlowDirection.TopDown, WrapContents = false, Margin = Padding.Empty };
        group.Controls.Add(Ui.Section(title));

        if (hint is not null)
        {
            group.Controls.Add(Hint(hint));
        }

        group.Controls.Add(control);

        return group;
    }

    private static Label Hint(string text) => new() { Text = text, AutoSize = true, ForeColor = Color.DimGray, MaximumSize = new Size(440, 0), Margin = new Padding(0, 0, 0, 6) };
}
