namespace CentreCivic.PrintAgent.App;

/// <summary>
/// What shows when the downloaded file is opened: install (or update) and start.
/// </summary>
internal sealed class InstallForm : Form
{
    private readonly Button install;
    private readonly Label status;

    public InstallForm()
    {
        Text = Installer.AppName;
        Icon = Ui.AppIcon;
        StartPosition = FormStartPosition.CenterScreen;
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false;
        AutoScaleMode = AutoScaleMode.Dpi;
        ClientSize = new Size(560, 330);
        BackColor = Color.White;
        Font = Ui.BodyFont;

        var installed = Installer.InstalledVersion;
        var updating = installed is not null;

        var header = Ui.Header("Programa d'impressió", "Centre Cívic Pau");
        var text = new Label
        {
            Text = updating
                ? $"Ja hi ha la versió {installed} instal·lada en aquest ordinador. S'actualitzarà a la {AgentRunner.Version} i es tornarà a obrir sola, amb la mateixa configuració."
                : "Imprimeix directament els tiquets i les comandes del TPV, sense diàlogs ni PDF.\n\n"
                  + "• S'instal·la només per a aquest usuari de Windows (no cal ser administrador).\n"
                  + "• S'obre sol cada cop que s'encén l'ordinador i es queda al costat del rellotge.\n"
                  + "• No cal obrir cap port del router: es connecta ell al servidor.",
            Location = new Point(24, 96),
            Size = new Size(512, 150),
        };

        install = new Button
        {
            Text = updating ? "Actualitza" : "Instal·la",
            Size = new Size(170, 44),
            Location = new Point(366, 262),
            BackColor = Ui.Brand,
            ForeColor = Color.White,
            FlatStyle = FlatStyle.Flat,
            Font = Ui.BoldFont,
        };
        install.FlatAppearance.BorderSize = 0;
        install.Click += (_, _) => Run();

        status = new Label { Location = new Point(24, 272), Size = new Size(330, 40), ForeColor = Color.DimGray };

        Controls.AddRange([header, text, status, install]);
        AcceptButton = install;
    }

    private void Run()
    {
        install.Enabled = false;
        status.Text = "Instal·lant…";
        Cursor = Cursors.WaitCursor;
        Refresh();

        try
        {
            Installer.Install();
            Close();
        }
        catch (Exception e)
        {
            Cursor = Cursors.Default;
            install.Enabled = true;
            status.Text = "";
            MessageBox.Show(this, "No s'ha pogut instal·lar: " + e.Message, Installer.AppName, MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
    }
}
