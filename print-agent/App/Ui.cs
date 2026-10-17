using System.Reflection;

namespace CentreCivic.PrintAgent.App;

/// <summary>Colours, fonts and the header shared by the windows.</summary>
internal static class Ui
{
    public static readonly Color Brand = Color.FromArgb(0, 5, 106);
    public static readonly Color Ok = Color.FromArgb(22, 163, 74);
    public static readonly Color Warn = Color.FromArgb(217, 119, 6);
    public static readonly Color Bad = Color.FromArgb(220, 38, 38);

    public static readonly Font BodyFont = new("Segoe UI", 10f);
    public static readonly Font BoldFont = new("Segoe UI Semibold", 10.5f);
    public static readonly Font SectionFont = new("Segoe UI Semibold", 11f);

    public static Icon AppIcon { get; } = LoadIcon();

    private static Icon LoadIcon()
    {
        using var stream = Assembly.GetExecutingAssembly().GetManifestResourceStream("app.ico");

        return stream is null ? SystemIcons.Application : new Icon(stream);
    }

    public static Panel Header(string title, string subtitle)
    {
        var panel = new Panel { Dock = DockStyle.Top, Height = 76, BackColor = Brand };
        var icon = new PictureBox { Image = new Icon(AppIcon, 48, 48).ToBitmap(), SizeMode = PictureBoxSizeMode.Zoom, Size = new Size(48, 48), Location = new Point(18, 14), BackColor = Color.White };
        var name = new Label { Text = title, ForeColor = Color.White, Font = new Font("Segoe UI Semibold", 15f), AutoSize = true, Location = new Point(78, 12) };
        var sub = new Label { Text = subtitle, ForeColor = Color.FromArgb(200, 210, 255), AutoSize = true, Location = new Point(80, 46) };
        panel.Controls.AddRange([icon, name, sub]);

        return panel;
    }

    public static Label Section(string text) => new() { Text = text, Font = SectionFont, AutoSize = true, Margin = new Padding(0, 14, 0, 4) };
}
